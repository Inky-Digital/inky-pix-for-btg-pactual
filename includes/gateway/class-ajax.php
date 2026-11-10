<?php
/**
 * Endpoints AJAX do checkout.
 *
 * Hoje há apenas a verificação manual do pagamento, acionada pelo botão
 * "Já paguei, verificar agora". A consulta real à API fica no módulo de
 * polling, para que a regra de confirmação exista em um só lugar.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ações AJAX do checkout.
 */
class Inky_BTG_Ajax {

	/**
	 * Nome da ação de verificação.
	 */
	const ACTION_CHECK_PAYMENT = 'inky_btg_check_payment';

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
	 * Registra os hooks.
	 *
	 * A verificação é aberta a visitantes porque o cliente pode pagar sem
	 * estar logado. A autorização é feita pela posse da chave do pedido.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::ACTION_CHECK_PAYMENT, array( $this, 'check_payment' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_CHECK_PAYMENT, array( $this, 'check_payment' ) );
	}

	/**
	 * Verifica o pagamento de um pedido.
	 *
	 * @return void
	 */
	public function check_payment() {
		check_ajax_referer( self::ACTION_CHECK_PAYMENT, 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Pedido inválido.', 'inky-btg' ) ),
				400
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error(
				array( 'message' => __( 'Pedido não encontrado.', 'inky-btg' ) ),
				404
			);
		}

		if ( ! $this->can_view_order( $order ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Você não tem permissão para consultar este pedido.', 'inky-btg' ) ),
				403
			);
		}

		if ( $order->is_paid() ) {
			wp_send_json_success(
				array(
					'paid'    => true,
					'message' => __( 'Pagamento confirmado. Obrigado!', 'inky-btg' ),
				)
			);
		}

		$result = $this->charges->refresh_status( $order );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				502
			);
		}

		if ( ! empty( $result['paid'] ) ) {
			wp_send_json_success(
				array(
					'paid'    => true,
					'message' => __( 'Pagamento confirmado. Obrigado!', 'inky-btg' ),
				)
			);
		}

		if ( ! empty( $result['simulated'] ) ) {
			wp_send_json_success(
				array(
					'paid'    => false,
					'message' => __( 'Teste em sandbox: a resposta do BTG é simulada e não confirma pagamento real.', 'inky-btg' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'paid'    => false,
				'message' => __(
					'Ainda não identificamos o pagamento. Se você acabou de pagar, aguarde alguns instantes e tente de novo.',
					'inky-btg'
				),
			)
		);
	}

	/**
	 * Verifica se o visitante pode consultar o pedido.
	 *
	 * Aceita quem tem permissão de gestão ou quem apresenta a chave do pedido,
	 * que é o que o cliente recebe no link de retorno do checkout.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private function can_view_order( $order ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$current_user_id = get_current_user_id();

		if ( $current_user_id && (int) $order->get_customer_id() === $current_user_id ) {
			return true;
		}

		$key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

		if ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) {
			return true;
		}

		return false;
	}
}
