<?php
/**
 * Exibição do QR Code na página de obrigado.
 *
 * Depois de finalizar o pedido, o cliente precisa ver o QR Code e o código
 * copia e cola. Este módulo injeta esse bloco na página de retorno do pedido,
 * sem depender de sobrescrever templates do tema.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bloco de pagamento PIX na página de obrigado.
 */
class Inky_BTG_Thank_You {

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
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_thankyou_' . Inky_BTG_Gateway::ID, array( $this, 'render' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Carrega o CSS e o JS do bloco, apenas na página de obrigado.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}

		wp_enqueue_style(
			'inky-btg-checkout',
			INKY_BTG_URL . 'assets/css/checkout.css',
			array(),
			INKY_BTG_VERSION
		);

		wp_enqueue_script(
			'inky-btg-checkout',
			INKY_BTG_URL . 'assets/js/checkout.js',
			array(),
			INKY_BTG_VERSION,
			true
		);

		wp_localize_script(
			'inky-btg-checkout',
			'inkyBtgCheckout',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'inky_btg_check_payment' ),
				'copied'  => __( 'Código copiado!', 'inky-btg' ),
				'copy'    => __( 'Copiar código', 'inky-btg' ),
				'error'   => __( 'Não foi possível copiar. Selecione o código e copie manualmente.', 'inky-btg' ),
			)
		);
	}

	/**
	 * Renderiza o bloco do PIX.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @return void
	 */
	public function render( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( $order->is_paid() ) {
			$this->render_paid_notice();
			return;
		}

		$data = $this->charges->get_from_order( $order );

		if ( empty( $data['txid'] ) ) {
			$this->render_missing_notice();
			return;
		}

		if ( $this->charges->is_expired( $data ) ) {
			$this->render_expired_notice();
			return;
		}

		$this->render_pix_block( $order, $data );
	}

	/**
	 * Renderiza o QR Code, o copia e cola e o botão de verificação.
	 *
	 * @param WC_Order             $order Pedido.
	 * @param array<string, mixed> $data  Dados da cobrança.
	 * @return void
	 */
	private function render_pix_block( $order, $data ) {
		$emv      = isset( $data['emv'] ) ? (string) $data['emv'] : '';
		$qr_image = isset( $data['qr_image'] ) ? (string) $data['qr_image'] : '';
		$expires  = isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0;

		?>
		<div class="inky-btg-pix" id="inky-btg-pix">
			<h2 class="inky-btg-pix__title">
				<?php esc_html_e( 'Pague com PIX para confirmar o pedido', 'inky-btg' ); ?>
			</h2>

			<p class="inky-btg-pix__intro">
				<?php
				esc_html_e(
					'Abra o aplicativo do seu banco, escolha pagar com PIX e aponte a câmera para o QR Code abaixo. Você também pode usar o código copia e cola.',
					'inky-btg'
				);
				?>
			</p>

			<?php if ( '' !== $qr_image ) : ?>
				<div class="inky-btg-pix__qr">
					<img
						src="<?php echo esc_url( $qr_image ); ?>"
						alt="<?php esc_attr_e( 'QR Code para pagamento via PIX', 'inky-btg' ); ?>"
						width="260"
						height="260"
					/>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $emv ) : ?>
				<div class="inky-btg-pix__code">
					<label for="inky-btg-emv" class="inky-btg-pix__label">
						<?php esc_html_e( 'Código copia e cola', 'inky-btg' ); ?>
					</label>
					<textarea
						id="inky-btg-emv"
						class="inky-btg-pix__textarea"
						readonly
						rows="3"
						onclick="this.select();"
					><?php echo esc_textarea( $emv ); ?></textarea>

					<button
						type="button"
						class="button inky-btg-pix__copy"
						data-inky-btg-copy="#inky-btg-emv"
					>
						<?php esc_html_e( 'Copiar código', 'inky-btg' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<?php if ( $expires > 0 ) : ?>
				<p class="inky-btg-pix__expires">
					<?php
					printf(
						/* translators: %s: data e hora de expiração. */
						esc_html__( 'Este código é válido até %s.', 'inky-btg' ),
						esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires ) )
					);
					?>
				</p>
			<?php endif; ?>

			<p class="inky-btg-pix__status" id="inky-btg-status" aria-live="polite">
				<?php esc_html_e( 'Aguardando a confirmação do pagamento. Esta página é atualizada automaticamente.', 'inky-btg' ); ?>
			</p>

			<p class="inky-btg-pix__actions">
				<button
					type="button"
					class="button alt inky-btg-pix__verify"
					data-inky-btg-verify="<?php echo esc_attr( (string) $order->get_id() ); ?>"
				>
					<?php esc_html_e( 'Já paguei, verificar agora', 'inky-btg' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Aviso exibido quando o pedido já está pago.
	 *
	 * @return void
	 */
	private function render_paid_notice() {
		?>
		<div class="woocommerce-message inky-btg-pix__notice" role="status">
			<?php esc_html_e( 'Pagamento confirmado. Obrigado!', 'inky-btg' ); ?>
		</div>
		<?php
	}

	/**
	 * Aviso exibido quando a cobrança ainda não foi gerada.
	 *
	 * @return void
	 */
	private function render_missing_notice() {
		?>
		<div class="woocommerce-info inky-btg-pix__notice" role="status">
			<?php
			esc_html_e(
				'A cobrança PIX ainda não está disponível. Atualize a página em alguns instantes ou fale com a loja.',
				'inky-btg'
			);
			?>
		</div>
		<?php
	}

	/**
	 * Aviso exibido quando o código expirou.
	 *
	 * @return void
	 */
	private function render_expired_notice() {
		?>
		<div class="woocommerce-error inky-btg-pix__notice" role="alert">
			<?php
			esc_html_e(
				'O código PIX deste pedido expirou. Fale com a loja para gerar um novo código.',
				'inky-btg'
			);
			?>
		</div>
		<?php
	}
}
