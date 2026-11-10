<?php
/**
 * Consulta periódica do status das cobranças.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consulta a API e confirma o pedido quando o PIX é pago.
 *
 * O poller não decide o que é "pago": ele apenas chama o método unificado do
 * serviço de cobranças, o mesmo usado pelo webhook. Assim a regra de
 * confirmação existe em um só lugar.
 */
class Inky_BTG_Poller {

	/**
	 * Prefixo da trava de concorrência por pedido.
	 */
	const LOCK_PREFIX = 'inky_btg_poll_lock_';

	/**
	 * Serviço de cobranças.
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
		add_action( Inky_BTG_Scheduler::HOOK_POLL_ORDER, array( $this, 'poll_order' ), 10, 1 );
	}

	/**
	 * Consulta um pedido e reagenda a próxima tentativa quando necessário.
	 *
	 * @param array<string, mixed> $args Argumentos agendados.
	 * @return void
	 */
	public function poll_order( $args ) {
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		$attempt  = isset( $args['attempt'] ) ? (int) $args['attempt'] : 0;

		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || $order->is_paid() ) {
			Inky_BTG_Scheduler::cancel_order( $order_id );
			return;
		}

		if ( Inky_BTG_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}

		$data = $this->charges->get_from_order( $order );

		if ( empty( $data['txid'] ) ) {
			return;
		}

		/*
		 * Cobrança vencida não é mais consultada: o QR Code expirou e a API
		 * não vai mudar o status. O pedido segue pendente para o lojista
		 * decidir o que fazer.
		 */
		if ( ! empty( $data['expires_at'] ) && time() > (int) $data['expires_at'] + HOUR_IN_SECONDS ) {
			Inky_BTG_Logger::info(
				'Consulta encerrada: a cobrança PIX venceu.',
				array(
					'source'   => 'poller',
					'order_id' => $order_id,
				)
			);

			Inky_BTG_Scheduler::cancel_order( $order_id );
			return;
		}

		if ( ! $this->acquire_lock( $order_id ) ) {
			/*
			 * Outra execução já está consultando este pedido. Reagendar evita
			 * duas chamadas simultâneas à API para a mesma cobrança.
			 */
			Inky_BTG_Scheduler::schedule_next( $order_id, $attempt );
			return;
		}

		$result = $this->charges->refresh_status( $order );

		$this->release_lock( $order_id );

		if ( is_wp_error( $result ) ) {
			Inky_BTG_Logger::warning(
				'Falha ao consultar o status da cobrança PIX.',
				array(
					'source'   => 'poller',
					'order_id' => $order_id,
					'error'    => $result->get_error_message(),
				)
			);

			Inky_BTG_Scheduler::schedule_next( $order_id, $attempt );
			return;
		}

		if ( ! empty( $result['paid'] ) ) {
			Inky_BTG_Scheduler::cancel_order( $order_id );
			return;
		}

		Inky_BTG_Scheduler::schedule_next( $order_id, $attempt );
	}

	/**
	 * Tenta obter a trava de concorrência do pedido.
	 *
	 * `add_option` é usado no lugar de `set_transient` porque ele falha quando
	 * a chave já existe, o que dá a garantia atômica que a trava precisa.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @return bool
	 */
	private function acquire_lock( $order_id ) {
		$key = self::LOCK_PREFIX . (int) $order_id;

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		$started = (int) get_option( $key, 0 );

		/*
		 * Uma trava órfã, deixada por um processo que morreu, é removida
		 * depois de dois minutos para não bloquear o pedido para sempre.
		 */
		if ( $started > 0 && ( time() - $started ) > 2 * MINUTE_IN_SECONDS ) {
			delete_option( $key );

			return (bool) add_option( $key, time(), '', false );
		}

		return false;
	}

	/**
	 * Libera a trava de concorrência do pedido.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @return void
	 */
	private function release_lock( $order_id ) {
		delete_option( self::LOCK_PREFIX . (int) $order_id );
	}
}
