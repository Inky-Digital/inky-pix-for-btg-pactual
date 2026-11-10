<?php
/**
 * Agendamento das verificações de pagamento.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Agenda a consulta de cada cobrança pendente.
 *
 * O sandbox do BTG usa respostas estáticas; o polling ali testa apenas o fluxo
 * de consulta. Em produção ele é mantido como reserva, porque o webhook pode
 * falhar ou chegar depois do prazo.
 *
 * Cada pedido tem sua própria ação agendada, com intervalos crescentes. Isso
 * evita consultar a API a cada minuto para uma cobrança que ninguém vai pagar,
 * e mantém o número de chamadas proporcional à chance de pagamento.
 */
class Inky_BTG_Scheduler {

	/**
	 * Ação executada para consultar um pedido.
	 */
	const HOOK_POLL_ORDER = 'inky_btg_poll_order';

	/**
	 * Ação executada para varrer os pedidos pendentes.
	 */
	const HOOK_SWEEP = 'inky_btg_poll_payments';

	/**
	 * Intervalos entre as consultas, em segundos.
	 *
	 * A sequência cobre os primeiros minutos, quando a maioria dos PIX é paga,
	 * e depois espaça as consultas até o vencimento da cobrança.
	 *
	 * @var array<int, int>
	 */
	private static $backoff = array( 60, 120, 300, 600, 900, 1800 );

	/**
	 * Registra os ganchos do módulo.
	 *
	 * @return void
	 */
	public function init() {
		add_action( self::HOOK_SWEEP, array( $this, 'sweep' ) );
	}

	/**
	 * Retorna a sequência de intervalos, permitindo ajuste pelo lojista.
	 *
	 * @return array<int, int>
	 */
	public static function get_backoff() {
		/**
		 * Permite alterar os intervalos entre as consultas.
		 *
		 * @param array<int, int> $backoff Intervalos em segundos.
		 */
		$backoff = apply_filters( 'inky_btg_poll_backoff', self::$backoff );

		if ( ! is_array( $backoff ) || empty( $backoff ) ) {
			return self::$backoff;
		}

		return array_values( array_filter( array_map( 'absint', $backoff ) ) );
	}

	/**
	 * Agenda a primeira consulta de um pedido.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @return void
	 */
	public static function schedule_order( $order_id ) {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 || ! Inky_BTG_Settings::is_polling_enabled() ) {
			return;
		}

		$backoff = self::get_backoff();

		if ( empty( $backoff ) ) {
			return;
		}

		self::schedule_attempt( $order_id, 0, $backoff[0] );
	}

	/**
	 * Agenda uma tentativa específica.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @param int $attempt  Número da tentativa, começando em zero.
	 * @param int $delay    Atraso em segundos.
	 * @return void
	 */
	private static function schedule_attempt( $order_id, $attempt, $delay ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_inky_btg_next_poll_at', time() + max( 1, (int) $delay ) );
			$order->save();
		}
		$args = array(
			'order_id' => (int) $order_id,
			'attempt'  => (int) $attempt,
		);

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + max( 1, (int) $delay ),
				self::HOOK_POLL_ORDER,
				array( $args ),
				'inky-pix-for-btg-pactual'
			);

			return;
		}

		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HOOK_POLL_ORDER, array( $args ) );
	}

	/**
	 * Agenda a próxima tentativa de um pedido, se ainda houver.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @param int $attempt  Tentativa que acabou de rodar.
	 * @return void
	 */
	public static function schedule_next( $order_id, $attempt ) {
		$backoff = self::get_backoff();
		$next    = min( (int) $attempt + 1, count( $backoff ) - 1 );

		if ( ! isset( $backoff[ $next ] ) ) {
			return;
		}

		self::schedule_attempt( $order_id, $next, $backoff[ $next ] );
	}

	/**
	 * Cancela as consultas agendadas de um pedido.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @return void
	 */
	public static function cancel_order( $order_id ) {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete_meta_data( '_inky_btg_next_poll_at' );
			$order->save();
		}
		foreach ( array_keys( self::get_backoff() ) as $attempt ) {
			$args = array( array( 'order_id' => $order_id, 'attempt' => $attempt ) );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK_POLL_ORDER, $args, 'inky-pix-for-btg-pactual' );
			}
			wp_clear_scheduled_hook( self::HOOK_POLL_ORDER, $args );
		}
	}

	/**
	 * Varre os pedidos pendentes e reagenda quem ficou sem consulta.
	 *
	 * Esta é a rede de segurança: se o agendamento individual for perdido, por
	 * exemplo em uma migração ou em um erro de banco, a varredura encontra o
	 * pedido e agenda a consulta de novo.
	 *
	 * @return void
	 */
	public function sweep() {
		if ( ! Inky_BTG_Settings::is_polling_enabled() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 50,
				'return'     => 'objects',
				'status'     => array( 'pending', 'on-hold' ),
				'meta_query' => array(
					array(
						'key'     => Inky_BTG_Gateway::ORDER_META_TXID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order->is_paid() ) {
				continue;
			}

			if ( Inky_BTG_Gateway::ID !== $order->get_payment_method() ) {
				continue;
			}
			$data = $order->get_meta( Inky_BTG_Gateway::ORDER_META_COLLECTION, true );
			if ( is_array( $data ) && ! empty( $data['expires_at'] ) && time() > (int) $data['expires_at'] + HOUR_IN_SECONDS ) {
				continue;
			}

			if ( $this->has_pending_attempt( $order->get_id() ) ) {
				continue;
			}

			self::schedule_order( $order->get_id() );
		}
	}

	/**
	 * Verifica se o pedido já tem uma consulta agendada.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @return bool
	 */
	private function has_pending_attempt( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && (int) $order->get_meta( '_inky_btg_next_poll_at', true ) > time() - 300 ) {
			return true;
		}
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action(
				self::HOOK_POLL_ORDER,
				array( array( 'order_id' => (int) $order_id ) ),
				'inky-pix-for-btg-pactual'
			);

			if ( false !== $next ) {
				return true;
			}
		}

		return false !== wp_next_scheduled( self::HOOK_POLL_ORDER, array( array( 'order_id' => (int) $order_id ) ) );
	}
}
