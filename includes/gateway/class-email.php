<?php
/**
 * QR Code do PIX nos e-mails do pedido.
 *
 * O cliente precisa conseguir pagar mesmo sem voltar ao site, então o QR Code
 * e o copia e cola também vão no e-mail de pedido pendente. O bloco é injetado
 * por hook, sem sobrescrever templates do tema.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bloco PIX nos e-mails transacionais.
 */
class Inky_BTG_Email {

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
	 * O hook `woocommerce_email_after_order_table` é usado porque funciona
	 * tanto no e-mail em HTML quanto no texto simples, e recebe o pedido.
	 *
	 * @return void
	 */
	public function init() {
		add_action(
			'woocommerce_email_after_order_table',
			array( $this, 'render_block' ),
			15,
			4
		);
	}

	/**
	 * Injeta o bloco do PIX no e-mail.
	 *
	 * @param WC_Order $order         Pedido.
	 * @param bool     $sent_to_admin Indica se o e-mail vai para o administrador.
	 * @param bool     $plain_text    Indica se o e-mail é texto simples.
	 * @param WC_Email $email         Objeto do e-mail.
	 * @return void
	 */
	public function render_block( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( Inky_BTG_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() ) {
			return;
		}

		/*
		 * Só enviamos o QR Code nos e-mails de pedido pendente e de
		 * processamento, que são os que o cliente recebe logo após comprar.
		 */
		if ( ! $this->is_payment_email( $email ) ) {
			return;
		}

		$data = $this->charges->get_from_order( $order );

		if ( empty( $data['txid'] ) || $this->charges->is_expired( $data ) ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_plain( $order, $data );
			return;
		}

		$this->render_html( $order, $data );
	}

	/**
	 * Verifica se o e-mail é um dos que devem trazer o QR Code.
	 *
	 * @param WC_Email $email Objeto do e-mail.
	 * @return bool
	 */
	private function is_payment_email( $email ) {
		if ( ! $email instanceof WC_Email ) {
			return false;
		}

		$allowed = array( 'customer_on_hold_order', 'customer_processing_order' );

		/**
		 * Permite ajustar quais e-mails recebem o QR Code.
		 *
		 * @param array<int, string> $allowed Identificadores de e-mail.
		 * @param WC_Email           $email   E-mail atual.
		 */
		$allowed = apply_filters( 'inky_btg_email_ids', $allowed, $email );

		return in_array( $email->id, $allowed, true );
	}

	/**
	 * Renderiza o bloco em HTML.
	 *
	 * @param WC_Order             $order Pedido.
	 * @param array<string, mixed> $data  Dados da cobrança.
	 * @return void
	 */
	private function render_html( $order, $data ) {
		$emv      = isset( $data['emv'] ) ? (string) $data['emv'] : '';
		$qr_image = isset( $data['qr_image'] ) ? (string) $data['qr_image'] : '';

		?>
		<div style="margin: 24px 0; padding: 16px; border: 1px solid #e5e5e5; border-radius: 6px; text-align: center;">
			<h2 style="margin: 0 0 12px; font-size: 18px;">
				<?php esc_html_e( 'Pague com PIX para confirmar o pedido', 'inky-btg' ); ?>
			</h2>

			<p style="margin: 0 0 16px; color: #555;">
				<?php
				esc_html_e(
					'Abra o aplicativo do seu banco, escolha pagar com PIX e aponte a câmera para o QR Code abaixo. Você também pode usar o código copia e cola.',
					'inky-btg'
				);
				?>
			</p>

			<?php if ( '' !== $qr_image ) : ?>
				<p style="margin: 0 0 16px;">
					<img
						src="<?php echo esc_url( $qr_image ); ?>"
						alt="<?php esc_attr_e( 'QR Code para pagamento via PIX', 'inky-btg' ); ?>"
						width="220"
						height="220"
						style="display: inline-block;"
					/>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $emv ) : ?>
				<p style="margin: 0 0 8px; font-weight: 600;">
					<?php esc_html_e( 'Código copia e cola', 'inky-btg' ); ?>
				</p>
				<p style="margin: 0; padding: 10px; background: #f7f7f7; border-radius: 4px; font-family: monospace; font-size: 12px; word-break: break-all;">
					<?php echo esc_html( $emv ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $data['expires_at'] ) ) : ?>
				<p style="margin: 16px 0 0; font-size: 12px; color: #666;">
					<?php
					printf(
						/* translators: %s: data e hora de expiração. */
						esc_html__( 'Este código é válido até %s.', 'inky-btg' ),
						esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $data['expires_at'] ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza o bloco em texto simples.
	 *
	 * @param WC_Order             $order Pedido.
	 * @param array<string, mixed> $data  Dados da cobrança.
	 * @return void
	 */
	private function render_plain( $order, $data ) {
		$emv = isset( $data['emv'] ) ? (string) $data['emv'] : '';

		echo "\n";

		echo esc_html__( 'Pague com PIX para confirmar o pedido', 'inky-btg' ) . "\n";
		echo esc_html__( 'Abra o aplicativo do seu banco, escolha pagar com PIX e use o código abaixo.', 'inky-btg' ) . "\n\n";

		if ( '' !== $emv ) {
			echo esc_html__( 'Código copia e cola:', 'inky-btg' ) . "\n";
			echo esc_html( $emv ) . "\n\n";
		}

		if ( ! empty( $data['expires_at'] ) ) {
			printf(
				/* translators: %s: data e hora de expiração. */
				esc_html__( 'Este código é válido até %s.', 'inky-btg' ) . "\n",
				esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $data['expires_at'] ) )
			);
		}

		echo "\n";
	}
}
