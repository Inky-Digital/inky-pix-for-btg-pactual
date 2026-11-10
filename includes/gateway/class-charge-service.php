<?php
/**
 * Criação e consulta de cobranças vinculadas a pedidos.
 *
 * Este é o ponto único onde um pedido do WooCommerce vira uma cobrança PIX.
 * Tanto o checkout quanto o polling e o webhook passam por aqui, para que a
 * regra de negócio exista em um só lugar.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serviço de cobranças.
 */
class Inky_BTG_Charge_Service {

	/**
	 * Instância do OAuth, usada para obter um cliente autenticado.
	 *
	 * @var Inky_BTG_OAuth
	 */
	private $oauth;

	/**
	 * Construtor.
	 *
	 * @param Inky_BTG_OAuth $oauth Módulo OAuth.
	 */
	public function __construct( $oauth ) {
		$this->oauth = $oauth;
	}

	/**
	 * Registra os hooks do serviço.
	 *
	 * A cobrança é criada em `woocommerce_checkout_order_processed` porque o
	 * pedido já existe nesse ponto, mas o redirecionamento ainda não ocorreu.
	 *
	 * @return void
	 */
	public function init() {
		// A cobrança é criada por process_payment, também usado no checkout por blocos.
	}

	/**
	 * Cria a cobrança quando o pedido usa o PIX do BTG.
	 *
	 * @param int                  $order_id    Identificador do pedido.
	 * @param array<string, mixed> $posted_data Dados enviados pelo checkout.
	 * @param WC_Order             $order       Pedido criado.
	 * @return void
	 */
	public function maybe_create_charge( $order_id, $posted_data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order || Inky_BTG_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}

		$result = $this->create_for_order( $order );

		if ( is_wp_error( $result ) ) {
			/*
			 * A falha é registrada no pedido para que o lojista entenda o que
			 * houve, e o pedido segue pendente para não perder a venda.
			 */
			$order->add_order_note(
				sprintf(
					/* translators: %s: mensagem de erro. */
					__( 'Não foi possível gerar a cobrança PIX: %s', 'inky-btg' ),
					$result->get_error_message()
				)
			);

			Inky_BTG_Logger::error(
				'Falha ao criar cobrança PIX.',
				array(
					'source'   => 'charge-service',
					'order_id' => $order->get_id(),
					'error'    => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Cria a cobrança de um pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array<string, mixed>|WP_Error Dados da cobrança gravados no pedido.
	 */
	public function create_for_order( $order ) {
		$existing = $this->get_from_order( $order );
		if ( ! empty( $existing['txid'] ) ) {
			return empty( $existing['emv'] ) && empty( $existing['qr_image'] )
				? new WP_Error( 'inky_btg_incomplete_charge', __( 'A cobrança anterior precisa de conferência no BTG antes de nova tentativa.', 'inky-btg' ) )
				: $existing;
		}
		$lock = 'inky_btg_create_' . $order->get_id();
		$locked_at = (int) get_option( $lock, 0 );
		if ( $locked_at && $locked_at < time() - 120 ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			return new WP_Error( 'inky_btg_charge_busy', __( 'Esta cobrança Pix já está sendo gerada. Aguarde alguns segundos.', 'inky-btg' ) );
		}
		try {
			return $this->create_for_order_unlocked( $order );
		} finally {
			delete_option( $lock );
		}
	}

	/** Executa a criação após obter a trava exclusiva do pedido. */
	private function create_for_order_unlocked( $order ) {
		$order = wc_get_order( $order->get_id() );
		$existing = $this->get_from_order( $order );
		if ( ! empty( $existing['txid'] ) ) {
			return $existing;
		}
		if ( 'BRL' !== $order->get_currency() || (float) $order->get_total() <= 0 ) {
			return new WP_Error( 'inky_btg_invalid_order', __( 'O Pix BTG exige um pedido em reais com valor maior que zero.', 'inky-btg' ) );
		}
		if ( ! Inky_BTG_Settings::has_pix_key() ) {
			return new WP_Error( 'inky_btg_missing_pix_key', __( 'A chave Pix da loja não foi configurada.', 'inky-btg' ) );
		}
		$environment = Inky_BTG_Settings::get_environment();
		$company_id  = Inky_BTG_Gateway::get_company_id( $environment );

		if ( '' === $company_id ) {
			return new WP_Error(
				'inky_btg_missing_company_id',
				__(
					'A conta conectada não informou o CNPJ da empresa. Reconecte a conta na tela de Ajustes.',
					'inky-btg'
				)
			);
		}

		$client = $this->oauth->get_authenticated_client( $environment );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$collections = new Inky_BTG_Instant_Collections(
			$client,
			new Inky_BTG_Locations( $client )
		);

		$args = array(
			'amount'       => (float) $order->get_total(),
			'pix_key'      => Inky_BTG_Settings::get_pix_key( $environment ),
			'expires_in'   => Inky_BTG_Settings::get_expiration_minutes() * MINUTE_IN_SECONDS,
			'display_text' => $this->build_display_text( $order ),
			'tags'         => Inky_BTG_API_Contract::build_tags( $order->get_id() ),
			'description'  => $this->build_description( $order ),
		);

		$response = $collections->create( $company_id, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$txid = Inky_BTG_Instant_Collections::extract_id( $response );
		$emv  = Inky_BTG_Instant_Collections::extract_emv( $response );

		if ( '' === $txid ) {
			return new WP_Error(
				'inky_btg_incomplete_charge',
				__(
					'A API respondeu sem o identificador da cobrança. Consulte o suporte do BTG antes de repetir esta cobrança.',
					'inky-btg'
				)
			);
		}

		$data = array(
			'txid'         => $txid,
			'emv'          => $emv,
			'qr_image'     => Inky_BTG_Instant_Collections::extract_qr_image( $response ),
			'status'       => Inky_BTG_Instant_Collections::extract_status( $response ),
			'environment'  => $environment,
			'company_id'   => $company_id,
			'created_at'   => time(),
			'expires_at'   => time() + ( Inky_BTG_Settings::get_expiration_minutes() * MINUTE_IN_SECONDS ),
		);

		$this->store_on_order( $order, $data );
		if ( '' === $emv && '' === $data['qr_image'] ) {
			return new WP_Error( 'inky_btg_incomplete_charge', __( 'O BTG criou a cobrança, mas não devolveu QR Code nem código Pix. Verifique a cobrança no BTG.', 'inky-btg' ) );
		}

		/*
		 * O polling começa assim que a cobrança existe. No sandbox ele exercita
		 * a consulta, sem confirmar liquidação. Em produção serve de reserva
		 * caso o webhook não chegue.
		 */
		Inky_BTG_Scheduler::schedule_order( $order->get_id() );

		Inky_BTG_Logger::info(
			'Cobrança PIX criada.',
			array(
				'source'   => 'charge-service',
				'order_id' => $order->get_id(),
				'txid'     => $txid,
			)
		);

		return $data;
	}

	/**
	 * Grava os dados da cobrança no pedido.
	 *
	 * @param WC_Order             $order Pedido.
	 * @param array<string, mixed> $data  Dados da cobrança.
	 * @return void
	 */
	public function store_on_order( $order, $data ) {
		$order->update_meta_data( Inky_BTG_Gateway::ORDER_META_TXID, $data['txid'] );
		$order->update_meta_data( Inky_BTG_Gateway::ORDER_META_COMPANY, $data['company_id'] );
		$order->update_meta_data( Inky_BTG_Gateway::ORDER_META_ENVIRONMENT, $data['environment'] );
		$order->update_meta_data( Inky_BTG_Gateway::ORDER_META_STATUS, $data['status'] );
		$order->update_meta_data( Inky_BTG_Gateway::ORDER_META_COLLECTION, $data );
		$order->save();
	}

	/**
	 * Recupera os dados da cobrança gravados no pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array<string, mixed> Dados da cobrança, ou array vazio.
	 */
	public function get_from_order( $order ) {
		$data = $order->get_meta( Inky_BTG_Gateway::ORDER_META_COLLECTION, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Consulta o status atual da cobrança e confirma pedidos de produção pagos.
	 *
	 * Este é o ponto único de confirmação: o webhook e o polling chamam este
	 * método, para que a regra de "marcar como pago" exista em um só lugar.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array<string, mixed>|WP_Error Resultado com a chave `paid`.
	 */
	public function refresh_status( $order ) {
		$data = $this->get_from_order( $order );

		if ( empty( $data['txid'] ) ) {
			return new WP_Error(
				'inky_btg_missing_txid',
				__( 'Este pedido ainda não tem uma cobrança PIX gerada.', 'inky-btg' )
			);
		}

		$environment = isset( $data['environment'] ) ? (string) $data['environment'] : Inky_BTG_Settings::get_environment();
		$company_id  = isset( $data['company_id'] ) ? (string) $data['company_id'] : '';

		if ( '' === $company_id ) {
			return new WP_Error(
				'inky_btg_missing_company_id',
				__( 'A cobrança não tem o CNPJ da empresa registrado.', 'inky-btg' )
			);
		}

		$client = $this->oauth->get_authenticated_client( $environment );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$collections = new Inky_BTG_Instant_Collections(
			$client,
			new Inky_BTG_Locations( $client )
		);

		$response = $collections->get( $company_id, $data['txid'] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = Inky_BTG_Instant_Collections::extract_status( $response );

		// O sandbox devolve respostas estáticas: PAID ali não prova liquidação.
		$paid = 'production' === $environment && $this->is_paid_status( $status );

		$order->update_meta_data( Inky_BTG_Gateway::ORDER_META_STATUS, $status );
		$order->save();

		if ( $paid ) {
			$this->mark_as_paid( $order, $data['txid'] );
			$updated_order = wc_get_order( $order->get_id() );
			$paid = $updated_order && $updated_order->is_paid();
		}

		return array(
			'paid'      => $paid,
			'status'    => $status,
			'simulated' => 'sandbox' === $environment,
		);
	}

	/**
	 * Marca o pedido como pago, de forma idempotente.
	 *
	 * A trava evita que webhook e polling confirmem o mesmo pedido duas vezes,
	 * o que duplicaria baixa de estoque e e-mails.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $txid  Identificador da transação.
	 * @return bool Verdadeiro quando a confirmação foi aplicada agora.
	 */
	public function mark_as_paid( $order, $txid ) {
		// Impede que um retorno simulado do sandbox conclua uma venda real.
		if ( 'production' !== $order->get_meta( Inky_BTG_Gateway::ORDER_META_ENVIRONMENT, true ) ) {
			return false;
		}

		if ( $order->is_paid() || '' === (string) $txid ) {
			return false;
		}

		$lock_key = 'inky_btg_paid_lock_' . $order->get_id();

		$locked_at = (int) get_option( $lock_key, 0 );
		if ( $locked_at && $locked_at < time() - 120 ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
			return false;
		}
		$order = wc_get_order( $order->get_id() );
		if ( ! $order || $order->is_paid() || ! hash_equals( (string) $order->get_meta( Inky_BTG_Gateway::ORDER_META_TXID, true ), (string) $txid ) ) {
			delete_option( $lock_key );
			return false;
		}

		if ( ! $order->payment_complete( $txid ) ) {
			delete_option( $lock_key );
			return false;
		}

		$order->add_order_note(
			__( 'Pagamento PIX confirmado pelo BTG Pactual.', 'inky-btg' )
		);
		Inky_BTG_Scheduler::cancel_order( $order->get_id() );
		delete_option( $lock_key );

		Inky_BTG_Logger::info(
			'Pedido confirmado como pago.',
			array(
				'source'   => 'charge-service',
				'order_id' => $order->get_id(),
				'txid'     => $txid,
			)
		);

		return true;
	}

	/**
	 * Verifica se um status devolvido pela API significa pagamento concluído.
	 *
	 * A documentação confirma o evento `instant-collection.paid`. Como o
	 * conjunto exato de status não está disponível, aceitamos as variações
	 * mais prováveis e deixamos o filtro para ajuste fino.
	 *
	 * @param string $status Status bruto.
	 * @return bool
	 */
	public function is_paid_status( $status ) {
		$status = strtolower( trim( (string) $status ) );

		$paid_statuses = array( 'paid', 'paga', 'pago', 'concluida', 'concluída', 'settled', 'completed' );

		/**
		 * Permite ajustar a lista de status considerados pagos.
		 *
		 * @param array<int, string> $paid_statuses Status aceitos.
		 * @param string             $status        Status recebido.
		 */
		$paid_statuses = apply_filters( 'inky_btg_paid_statuses', $paid_statuses, $status );

		return in_array( $status, $paid_statuses, true );
	}

	/**
	 * Verifica se a cobrança do pedido já venceu.
	 *
	 * @param array<string, mixed> $data Dados da cobrança.
	 * @return bool
	 */
	public function is_expired( $data ) {
		if ( empty( $data['expires_at'] ) ) {
			return false;
		}

		return time() > (int) $data['expires_at'];
	}

	/**
	 * Monta o texto exibido no aplicativo do pagador.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	private function build_display_text( $order ) {
		$store = get_bloginfo( 'name' );

		return sprintf(
			/* translators: 1: nome da loja, 2: número do pedido. */
			__( '%1$s - Pedido %2$s', 'inky-btg' ),
			$store,
			$order->get_order_number()
		);
	}

	/**
	 * Monta a descrição interna da cobrança.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	private function build_description( $order ) {
		return sprintf(
			/* translators: %s: número do pedido. */
			__( 'Cobrança PIX do pedido %s', 'inky-btg' ),
			$order->get_order_number()
		);
	}
}
