<?php
/**
 * Recebimento de webhooks do BTG Pactual Empresas.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rota REST que recebe os eventos de cobrança PIX.
 *
 * Responsabilidades:
 * - validar o cabeçalho `Authorization: Bearer {webhook secret}` em tempo
 *   constante, com `hash_equals`;
 * - deduplicar entregas repetidas pelo `x-correlation-id`, porque o BTG
 *   reenvia o mesmo evento até três vezes dentro de dez minutos;
 * - responder em menos de 10 segundos, devolvendo 200 e deixando o trabalho
 *   pesado para uma ação assíncrona do Action Scheduler;
 * - localizar o pedido pelas tags enviadas na cobrança, com o `txId` como
 *   alternativa quando as tags não vierem no evento.
 */
class Inky_BTG_Webhook {

	/**
	 * Identificador da rota REST.
	 */
	const ROUTE = '/webhook';

	/**
	 * Ação assíncrona que processa o evento fora do ciclo da requisição.
	 */
	const ASYNC_HOOK = 'inky_btg_process_webhook';

	/**
	 * Serviço de cobranças, usado para confirmar o pagamento.
	 *
	 * @var Inky_BTG_Charge_Service
	 */
	private $charges;

	/**
	 * Construtor.
	 *
	 * @param Inky_BTG_Charge_Service $charges Serviço de cobranças.
	 */
	public function __construct( $charges ) {
		$this->charges = $charges;
	}

	/**
	 * Registra os ganchos do módulo.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::ASYNC_HOOK, array( $this, 'process_event' ), 10, 1 );
	}

	/**
	 * Declara a rota REST.
	 *
	 * A permissão é validada dentro do callback, e não em `permission_callback`,
	 * porque o segredo depende do ambiente ativo e a resposta precisa ser um
	 * 401 com corpo vazio, e não o erro padrão do WordPress.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			INKY_BTG_REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Trata a requisição do webhook.
	 *
	 * @param WP_REST_Request $request Requisição recebida.
	 * @return WP_REST_Response
	 */
	public function handle_request( $request ) {
		$environment = Inky_BTG_Settings::get_environment();
		$secret      = Inky_BTG_Settings::get_webhook_secret( $environment );

		if ( ! $this->is_authorized( $request, $secret ) ) {
			Inky_BTG_Logger::warning(
				'Webhook recusado: segredo inválido ou ausente.',
				array(
					'source'         => 'webhook',
					'correlation_id' => $this->get_correlation_id( $request ),
				)
			);

			return new WP_REST_Response( null, 401 );
		}

		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) || empty( $payload['event'] ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$correlation_id = $this->get_correlation_id( $request );
		$event_type     = sanitize_text_field( (string) $payload['event'] );

		/*
		 * A deduplicação acontece antes de qualquer trabalho, porque o BTG
		 * reenvia o mesmo evento com o mesmo `x-correlation-id`. O índice
		 * único da tabela é a garantia final contra corrida entre requisições.
		 */
		$registered = $this->register_delivery( $correlation_id, $event_type, $payload );
		if ( is_wp_error( $registered ) ) {
			return new WP_REST_Response( null, 503 );
		}
		if ( ! $registered ) {
			Inky_BTG_Logger::info(
				'Webhook repetido ignorado.',
				array(
					'source'         => 'webhook',
					'event'          => $event_type,
					'correlation_id' => $correlation_id,
				)
			);

			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		/*
		 * O BTG exige resposta em até 10 segundos. O processamento é agendado
		 * e a resposta sai imediatamente, sem tocar na API nem no pedido.
		 */
		if ( ! $this->schedule_processing( $correlation_id, $event_type, $payload ) ) {
			if ( '' !== $correlation_id ) {
				global $wpdb;
				$wpdb->delete( Inky_BTG_Install::get_events_table_name(), array( 'correlation_id' => $correlation_id ), array( '%s' ) );
			}
			return new WP_REST_Response( null, 503 );
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Confere o cabeçalho Authorization em tempo constante.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @param string          $secret  Segredo configurado para o ambiente.
	 * @return bool
	 */
	private function is_authorized( $request, $secret ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			return false;
		}

		$header = (string) $request->get_header( 'authorization' );

		if ( '' === $header ) {
			return false;
		}

		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $matches ) ) {
			return false;
		}
		return hash_equals( $secret, $matches[1] );
	}

	/**
	 * Lê o identificador de correlação do cabeçalho.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return string
	 */
	private function get_correlation_id( $request ) {
		$value = (string) $request->get_header( 'x-correlation-id' );

		if ( '' === $value ) {
			$value = (string) $request->get_header( 'correlation-id' );
		}

		return substr( sanitize_text_field( $value ), 0, 100 );
	}

	/**
	 * Registra a entrega e informa se ela é inédita.
	 *
	 * @param string               $correlation_id Identificador de correlação.
	 * @param string               $event_type     Tipo do evento.
	 * @param array<string, mixed> $payload        Corpo recebido.
	 * @return bool Verdadeiro quando a entrega é nova.
	 */
	private function register_delivery( $correlation_id, $event_type, $payload ) {
		global $wpdb;

		/*
		 * Sem `x-correlation-id` não há como deduplicar. Nesse caso o evento é
		 * aceito, porque perder um pagamento é pior do que processá-lo duas
		 * vezes, e a confirmação do pedido já é idempotente.
		 */
		if ( '' === $correlation_id ) {
			return true;
		}

		$table = Inky_BTG_Install::get_events_table_name();

		$inserted = $wpdb->insert(
			$table,
			array(
				'received_at'    => current_time( 'mysql', true ),
				'environment'    => Inky_BTG_Settings::get_environment(),
				'event_type'     => substr( $event_type, 0, 60 ),
				'correlation_id' => $correlation_id,
				'webhook_id'     => isset( $payload['webhookId'] ) ? substr( sanitize_text_field( (string) $payload['webhookId'] ), 0, 100 ) : null,
				'status'         => 'received',
				'attempts'       => 1,
				'payload'        => null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE correlation_id = %s', $table, $correlation_id ) );
			return $existing ? false : new WP_Error( 'inky_btg_webhook_storage_failed' );
		}

		return true;
	}

	/**
	 * Agenda o processamento assíncrono do evento.
	 *
	 * @param string               $correlation_id Identificador de correlação.
	 * @param string               $event_type     Tipo do evento.
	 * @param array<string, mixed> $payload        Corpo recebido.
	 * @return bool
	 */
	private function schedule_processing( $correlation_id, $event_type, $payload ) {
		$input = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$txid = $this->extract_txid( $input );
		$args = array(
			'correlation_id' => $correlation_id,
			'event'          => $event_type,
			'environment'    => Inky_BTG_Settings::get_environment(),
			'payload'        => array( 'data' => array(
				'txId' => $txid,
				'status' => isset( $input['status'] ) ? sanitize_text_field( (string) $input['status'] ) : '',
			) ),
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return (bool) as_enqueue_async_action( self::ASYNC_HOOK, array( $args ), 'inky-pix-for-btg-pactual' );
		}

		/*
		 * Sem o Action Scheduler o evento é processado na hora. O WP-Cron não
		 * serve aqui porque o agendamento de um único evento pode levar mais
		 * que os 10 segundos que o BTG tolera.
		 */
		$this->process_event( $args );
		return true;
	}

	/**
	 * Processa o evento recebido.
	 *
	 * @param array<string, mixed> $args Dados agendados.
	 * @return void
	 */
	public function process_event( $args ) {
		if ( ! is_array( $args ) || empty( $args['event'] ) ) {
			return;
		}

		$event_type     = (string) $args['event'];
		$payload        = isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : array();
		$correlation_id = isset( $args['correlation_id'] ) ? (string) $args['correlation_id'] : '';
		$data           = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		$order = $this->find_order( $data );
		if ( $order && isset( $args['environment'] ) && $order->get_meta( Inky_BTG_Gateway::ORDER_META_ENVIRONMENT, true ) !== $args['environment'] ) {
			$order = false;
		}

		if ( ! $order ) {
			$this->update_delivery_status( $correlation_id, 'order_not_found' );

			Inky_BTG_Logger::warning(
				'Webhook recebido sem pedido correspondente.',
				array(
					'source'         => 'webhook',
					'event'          => $event_type,
					'correlation_id' => $correlation_id,
				)
			);

			return;
		}

		$this->update_delivery_status( $correlation_id, 'processed', $order->get_id() );

		if ( Inky_BTG_API_Contract::EVENT_PAID === $event_type ) {
			$this->handle_paid( $order, $data, $correlation_id );
			return;
		}

		if ( Inky_BTG_API_Contract::EVENT_UNLINKED === $event_type ) {
			$this->handle_unlinked( $order, $data, $correlation_id );
			return;
		}

		Inky_BTG_Logger::info(
			'Evento de webhook sem tratamento específico.',
			array(
				'source'         => 'webhook',
				'event'          => $event_type,
				'correlation_id' => $correlation_id,
			)
		);
	}

	/**
	 * Trata o evento de pagamento confirmado.
	 *
	 * @param WC_Order             $order          Pedido.
	 * @param array<string, mixed> $data           Objeto `data` do evento.
	 * @param string               $correlation_id Identificador de correlação.
	 * @return void
	 */
	private function handle_paid( $order, $data, $correlation_id ) {
		$txid = $this->extract_txid( $data );
		if ( Inky_BTG_API_Contract::STATUS_PAID !== strtoupper( (string) ( $data['status'] ?? '' ) ) ) {
			$this->update_delivery_status( $correlation_id, 'invalid_status', $order->get_id() );
			return;
		}

		$order->update_meta_data( Inky_BTG_Gateway::ORDER_META_STATUS, Inky_BTG_API_Contract::STATUS_PAID );
		$order->save();

		if ( ! $this->charges->mark_as_paid( $order, $txid ) ) {
			return;
		}

		Inky_BTG_Logger::info(
			'Pagamento confirmado por webhook.',
			array(
				'source'         => 'webhook',
				'order_id'       => $order->get_id(),
				'txid'           => $txid,
				'correlation_id' => $correlation_id,
			)
		);
	}

	/**
	 * Trata o evento de cobrança desvinculada.
	 *
	 * O desvínculo significa que o QR Code deixou de valer. O pedido não é
	 * cancelado automaticamente, porque o pagamento pode ter sido confirmado
	 * por outra via; apenas registramos o fato para o lojista decidir.
	 *
	 * @param WC_Order             $order          Pedido.
	 * @param array<string, mixed> $data           Objeto `data` do evento.
	 * @param string               $correlation_id Identificador de correlação.
	 * @return void
	 */
	private function handle_unlinked( $order, $data, $correlation_id ) {
		if ( $order->is_paid() ) {
			return;
		}

		$order->update_meta_data( Inky_BTG_Gateway::ORDER_META_STATUS, Inky_BTG_API_Contract::STATUS_UNLINKED );
		$order->add_order_note(
			__( 'O BTG informou que o QR Code desta cobrança foi desvinculado e não pode mais ser pago.', 'inky-pix-for-btg-pactual' )
		);
		$order->save();

		Inky_BTG_Logger::info(
			'Cobrança desvinculada informada por webhook.',
			array(
				'source'         => 'webhook',
				'order_id'       => $order->get_id(),
				'txid'           => $this->extract_txid( $data ),
				'correlation_id' => $correlation_id,
			)
		);
	}

	/**
	 * Localiza o pedido pelas tags, com o `txId` como alternativa.
	 *
	 * @param array<string, mixed> $data Objeto `data` do evento.
	 * @return WC_Order|false
	 */
	private function find_order( $data ) {
		$txid = $this->extract_txid( $data );
		if ( '' === $txid ) {
			return false;
		}
		$order_id = $this->extract_order_id_from_tags( $data );

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order && Inky_BTG_Gateway::ID === $order->get_payment_method() && hash_equals( (string) $order->get_meta( Inky_BTG_Gateway::ORDER_META_TXID, true ), $txid ) ) {
				return $order;
			}
		}

		return $this->find_order_by_txid( $txid );
	}

	/**
	 * Lê o identificador do pedido a partir das tags do evento.
	 *
	 * As tags podem chegar como objeto `{ "order": "123" }` ou como lista de
	 * pares `{ "key": "order", "value": "123" }`, dependendo do evento.
	 *
	 * @param array<string, mixed> $data Objeto `data` do evento.
	 * @return int Identificador do pedido, ou zero.
	 */
	private function extract_order_id_from_tags( $data ) {
		if ( empty( $data['tags'] ) || ! is_array( $data['tags'] ) ) {
			return 0;
		}

		$tags = $data['tags'];

		if ( isset( $tags['order'] ) && is_scalar( $tags['order'] ) ) {
			return (int) $tags['order'];
		}

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}

			$key   = isset( $tag['key'] ) ? (string) $tag['key'] : '';
			$value = isset( $tag['value'] ) ? $tag['value'] : null;

			if ( 'order' === $key && is_scalar( $value ) ) {
				return (int) $value;
			}
		}

		return 0;
	}

	/**
	 * Extrai o identificador da transação do evento.
	 *
	 * @param array<string, mixed> $data Objeto `data` do evento.
	 * @return string
	 */
	private function extract_txid( $data ) {
		$candidates = array( 'txId', 'txid', 'transactionId', 'id' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return (string) $data[ $key ];
			}
		}

		return '';
	}

	/**
	 * Procura o pedido pelo `txId` gravado na cobrança.
	 *
	 * @param string $txid Identificador da transação.
	 * @return WC_Order|false
	 */
	private function find_order_by_txid( $txid ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'objects',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array(
					array(
						'key'   => Inky_BTG_Gateway::ORDER_META_TXID,
						'value' => $txid,
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return false;
		}

		return Inky_BTG_Gateway::ID === $orders[0]->get_payment_method() ? $orders[0] : false;
	}

	/**
	 * Atualiza o desfecho de uma entrega registrada.
	 *
	 * @param string $correlation_id Identificador de correlação.
	 * @param string $status         Novo status.
	 * @param int    $order_id       Pedido localizado, quando houver.
	 * @return void
	 */
	private function update_delivery_status( $correlation_id, $status, $order_id = 0 ) {
		if ( '' === $correlation_id ) {
			return;
		}

		global $wpdb;

		$data = array( 'status' => $status );

		if ( $order_id > 0 ) {
			$data['order_id'] = $order_id;
		}

		$wpdb->update(
			Inky_BTG_Install::get_events_table_name(),
			$data,
			array( 'correlation_id' => $correlation_id ),
			null,
			array( '%s' )
		);
	}
}
