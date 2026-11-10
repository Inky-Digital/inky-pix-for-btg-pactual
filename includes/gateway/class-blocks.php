<?php
/**
 * Integração com o checkout por blocos do WooCommerce.
 *
 * O checkout por blocos não usa o formulário clássico nem o método
 * `process_payment` diretamente: ele consulta os meios de pagamento por uma
 * API própria. Esta classe expõe o PIX do BTG nessa API, reaproveitando o
 * gateway já existente para não duplicar título, descrição e ícone.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registra o PIX do BTG no checkout por blocos.
 */
final class Inky_BTG_Blocks extends AbstractPaymentMethodType {

	/**
	 * Nome usado pelo WooCommerce para localizar esta integração.
	 *
	 * Precisa ser igual ao id do gateway.
	 *
	 * @var string
	 */
	protected $name = 'inky_btg_pix';

	/**
	 * Instância do gateway, usada como fonte dos textos exibidos.
	 *
	 * @var Inky_BTG_Gateway|null
	 */
	private $gateway = null;

	/**
	 * Carrega as configurações do gateway.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Informa se o meio de pagamento está ativo.
	 *
	 * O bloco só aparece no checkout quando o gateway está habilitado e as
	 * credenciais do ambiente ativo existem. Sem isso, o cliente veria uma
	 * opção que não consegue gerar cobrança.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->get_gateway();

		if ( ! $gateway || 'yes' !== $gateway->enabled ) {
			return false;
		}

		return $gateway->is_ready();
	}

	/** Registra o script que apresenta o meio de pagamento no checkout por blocos. */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'inky-btg-blocks',
			INKY_BTG_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			INKY_BTG_VERSION,
			true
		);
		return array( 'inky-btg-blocks' );
	}

	/**
	 * Devolve os dados consumidos pelo script do bloco.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->get_gateway();

		return array(
			'title'       => $gateway ? $gateway->title : __( 'PIX', 'inky-btg' ),
			'description' => $gateway ? $gateway->description : '',
			'icon'        => $gateway ? $gateway->icon : '',
			'supports'    => array( 'products' ),
		);
	}

	/**
	 * Devolve a instância do gateway, criando-a se necessário.
	 *
	 * @return Inky_BTG_Gateway|null
	 */
	private function get_gateway() {
		if ( null !== $this->gateway ) {
			return $this->gateway;
		}

		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return null;
		}

		$gateways = WC_Payment_Gateways::instance()->payment_gateways();

		if ( isset( $gateways[ $this->name ] ) ) {
			$this->gateway = $gateways[ $this->name ];
		}

		return $this->gateway;
	}
}
