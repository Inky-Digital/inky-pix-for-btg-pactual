<?php
/**
 * Meio de pagamento PIX via BTG Pactual Empresas.
 *
 * Estende `WC_Payment_Gateway` para que o PIX apareça no checkout do
 * WooCommerce como qualquer outro meio de pagamento. A criação da cobrança e
 * a confirmação do pagamento ficam em módulos separados, acionados a partir
 * daqui.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gateway de pagamento PIX.
 */
class Inky_BTG_Gateway extends WC_Payment_Gateway {

	/**
	 * Identificador do meio de pagamento.
	 */
	const ID = 'inky_btg_pix';

	/**
	 * Chave do pedido que guarda os dados da cobrança.
	 */
	const ORDER_META_COLLECTION = '_inky_btg_collection';

	/**
	 * Chave do pedido que guarda o identificador da transação.
	 */
	const ORDER_META_TXID = '_inky_btg_txid';

	/**
	 * Chave do pedido que guarda o CNPJ da empresa.
	 */
	const ORDER_META_COMPANY = '_inky_btg_company_id';

	/**
	 * Chave do pedido que guarda o ambiente usado na cobrança.
	 */
	const ORDER_META_ENVIRONMENT = '_inky_btg_environment';

	/**
	 * Chave do pedido que guarda o status bruto devolvido pela API.
	 */
	const ORDER_META_STATUS = '_inky_btg_status';

	/**
	 * Construtor.
	 *
	 * Registra o identificador, os campos exibidos no checkout e os campos do
	 * formulário de configuração do meio de pagamento.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PIX BTG Pactual', 'inky-btg' );
		$this->method_description = __(
			'Receba pagamentos por PIX usando a API Pix Cobrança do BTG Pactual Empresas. O cliente paga lendo o QR Code ou usando o copia e cola.',
			'inky-btg'
		);
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = Inky_BTG_Settings::get( 'enabled', 'no' );
		$this->title       = Inky_BTG_Settings::get( 'title', __( 'PIX', 'inky-btg' ) );
		$this->description = Inky_BTG_Settings::get( 'description', '' );
		$this->icon        = $this->resolve_icon();

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'save_gateway_options' )
		);
	}

	/** Mantém os dois painéis de configuração sincronizados. */
	public function save_gateway_options() {
		$result = $this->process_admin_options();
		if ( $result ) {
			Inky_BTG_Settings::set_many( array(
				'enabled' => $this->get_option( 'enabled', 'no' ),
				'title' => $this->get_option( 'title', __( 'PIX', 'inky-btg' ) ),
				'description' => $this->get_option( 'description', '' ),
			) );
		}
		return $result;
	}

	/**
	 * Define os campos do formulário de configuração do meio de pagamento.
	 *
	 * As credenciais e o ambiente continuam na tela de Ajustes do plugin, para
	 * não duplicar o cadastro. Aqui ficam apenas os textos vistos pelo cliente.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Ativar', 'inky-btg' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ativar o pagamento por PIX', 'inky-btg' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Título no checkout', 'inky-btg' ),
				'type'        => 'text',
				'description' => __(
					'Como o meio de pagamento aparece para o cliente no checkout.',
					'inky-btg'
				),
				'default'     => __( 'PIX', 'inky-btg' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Descrição no checkout', 'inky-btg' ),
				'type'        => 'textarea',
				'description' => __(
					'Texto exibido abaixo do título, explicando como o cliente deve pagar.',
					'inky-btg'
				),
				'default'     => __(
					'Ao finalizar o pedido, mostramos o QR Code e o código copia e cola. O pedido é confirmado automaticamente após o pagamento.',
					'inky-btg'
				),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Verifica se o meio de pagamento está disponível para uso.
	 *
	 * Além de estar ativado, é preciso que o ambiente ativo tenha credenciais
	 * e uma autorização válida. Sem isso o PIX não aparece no checkout, o que
	 * evita pedidos que não podem ser cobrados.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! parent::is_available() ) {
			return false;
		}

		return $this->is_ready();
	}

	/**
	 * Verifica se o ambiente ativo está pronto para cobrar.
	 *
	 * @return bool
	 */
	public function is_ready() {
		$environment = Inky_BTG_Settings::get_environment();

		if ( ! Inky_BTG_Settings::has_credentials( $environment ) || ! Inky_BTG_Settings::has_pix_key( $environment ) ) {
			return false;
		}

		return Inky_BTG_Token_Store::has_token( $environment )
			&& ( ! Inky_BTG_Token_Store::is_expired( $environment ) || Inky_BTG_Token_Store::can_refresh( $environment ) )
			&& '' !== self::get_company_id( $environment );
	}

	/**
	 * Processa o pedido.
	 *
	 * A cobrança é criada no evento `woocommerce_checkout_order_processed`, e
	 * não aqui, porque o pedido precisa existir antes de ser cobrado. Neste
	 * ponto apenas liberamos o pedido para pagamento.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice(
				__( 'Não foi possível localizar o pedido para gerar a cobrança PIX.', 'inky-btg' ),
				'error'
			);

			return array( 'result' => 'failure' );
		}
		$service = Inky_BTG_Plugin::instance()->get_module( 'charges' );
		if ( ! $service ) {
			wc_add_notice( __( 'O serviço Pix não está disponível. Tente novamente.', 'inky-btg' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$charge = $service->create_for_order( $order );
		if ( is_wp_error( $charge ) ) {
			$order->add_order_note( sprintf( __( 'Falha ao criar Pix BTG: %s', 'inky-btg' ), $charge->get_error_message() ) );
			wc_add_notice( esc_html( $charge->get_error_message() ), 'error' );
			return array( 'result' => 'failure' );
		}
		$order = wc_get_order( $order_id );

		/*
		 * O pedido fica pendente até o pagamento ser confirmado pelo webhook ou
		 * pelo polling. Reduzimos o estoque agora, como o WooCommerce faz em
		 * meios de pagamento que não são imediatos.
		 */
		if ( ! $order->is_paid() ) {
			$order->update_status(
				'on-hold',
				__( 'Aguardando o pagamento do PIX.', 'inky-btg' )
			);
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Retorna o ícone do meio de pagamento.
	 *
	 * O ícone é opcional: se o arquivo não existir, o checkout segue sem
	 * imagem, sem quebrar o layout.
	 *
	 * @return string
	 */
	private function resolve_icon() {
		$icon_path = INKY_BTG_PATH . 'assets/img/pix.svg';

		if ( ! is_readable( $icon_path ) ) {
			return '';
		}

		return INKY_BTG_URL . 'assets/img/pix.svg';
	}

	/**
	 * Retorna o CNPJ da empresa autorizada no ambiente ativo.
	 *
	 * O valor vem da claim `pix-cash-in` do token de acesso, que é a fonte
	 * confiável: o lojista não digita o CNPJ em lugar nenhum.
	 *
	 * @param string $environment Ambiente desejado.
	 * @return string CNPJ, ou string vazia quando não houver.
	 */
	public static function get_company_id( $environment = '' ) {
		return Inky_BTG_Token_Store::get_company_id( $environment );
	}

	/**
	 * Registra o meio de pagamento no WooCommerce.
	 *
	 * @param array<int, string> $methods Meios de pagamento registrados.
	 * @return array<int, string>
	 */
	public static function register( $methods ) {
		$methods[] = self::class;

		return $methods;
	}
}
