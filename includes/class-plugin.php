<?php
/**
 * Orquestrador principal do plugin.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe responsável por inicializar todos os módulos do plugin.
 *
 * Usa o padrão singleton para garantir que os hooks sejam registrados uma
 * única vez, mesmo que a classe seja instanciada em vários pontos.
 */
final class Inky_BTG_Plugin {

	/**
	 * Instância única da classe.
	 *
	 * @var Inky_BTG_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Módulos carregados, indexados pelo identificador curto.
	 *
	 * @var array<string, object>
	 */
	private $modules = array();

	/**
	 * Retorna a instância única, criando-a na primeira chamada.
	 *
	 * @return Inky_BTG_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Construtor privado. Use `instance()`.
	 */
	private function __construct() {
		$this->load_textdomain();
		$this->init_modules();
		$this->register_hooks();
	}

	/**
	 * Impede a clonagem da instância.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Impede a desserialização da instância.
	 *
	 * @throws Exception Sempre, pois a classe é um singleton.
	 * @return void
	 */
	public function __wakeup() {
		throw new Exception( 'Inky_BTG_Plugin não pode ser desserializado.' );
	}

	/**
	 * Carrega as traduções.
	 *
	 * O carregamento é feito em `init` para respeitar o momento correto do
	 * WordPress e permitir traduções carregadas por outros plugins.
	 *
	 * @return void
	 */
	private function load_textdomain() {
		add_action(
			'init',
			function () {
				load_plugin_textdomain(
					'inky-btg',
					false,
					dirname( INKY_BTG_BASENAME ) . '/languages'
				);
			}
		);
	}

	/**
	 * Declara compatibilidade com recursos do WooCommerce.
	 *
	 * O HPOS (armazenamento de pedidos em tabelas próprias) precisa ser
	 * declarado explicitamente, caso contrário o plugin aparece como
	 * incompatível na listagem de plugins do WooCommerce.
	 *
	 * @return void
	 */
	private function declare_compatibility() {
		add_action(
			'before_woocommerce_init',
			function () {
				if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					return;
				}

				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables',
					INKY_BTG_FILE,
					true
				);

				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'cart_checkout_blocks',
					INKY_BTG_FILE,
					true
				);
			}
		);
	}

	/**
	 * Instancia os módulos do plugin.
	 *
	 * Cada módulo é responsável por registrar seus próprios hooks. O
	 * carregamento é feito sob demanda para não penalizar requisições que não
	 * usam o plugin.
	 *
	 * @return void
	 */
	private function init_modules() {
		add_action( 'init', array( $this, 'load_modules' ), 5 );
	}

	/**
	 * Carrega os módulos conforme o contexto da requisição.
	 *
	 * @return void
	 */
	public function load_modules() {
		$this->modules['logger']   = new Inky_BTG_Logger();
		$this->modules['settings'] = new Inky_BTG_Settings();

		$this->modules['oauth'] = new Inky_BTG_OAuth();
		$this->modules['oauth']->init();

		$this->modules['charges'] = new Inky_BTG_Charge_Service( $this->modules['oauth'] );
		$this->modules['charges']->init();

		$this->modules['thankyou'] = new Inky_BTG_Thank_You( $this->modules['charges'] );
		$this->modules['thankyou']->init();

		$this->modules['ajax'] = new Inky_BTG_Ajax( $this->modules['charges'] );
		$this->modules['ajax']->init();

		$this->modules['email'] = new Inky_BTG_Email( $this->modules['charges'] );
		$this->modules['email']->init();

		$this->modules['webhook'] = new Inky_BTG_Webhook( $this->modules['charges'] );
		$this->modules['webhook']->init();

		$this->modules['scheduler'] = new Inky_BTG_Scheduler();
		$this->modules['scheduler']->init();

		$this->modules['poller'] = new Inky_BTG_Poller( $this->modules['charges'] );
		$this->modules['poller']->init();

		add_filter( 'woocommerce_payment_gateways', array( 'Inky_BTG_Gateway', 'register' ) );

		$this->register_blocks_support();

		if ( is_admin() ) {
			$this->modules['admin'] = new Inky_BTG_Admin();
		}

		/**
		 * Permite que módulos adicionais sejam registrados.
		 *
		 * @param Inky_BTG_Plugin $plugin Instância do plugin.
		 */
		do_action( 'inky_btg_loaded', $this );
	}

	/**
	 * Registra o PIX no checkout por blocos.
	 *
	 * A classe base do WooCommerce só existe quando o recurso de blocos está
	 * presente. A verificação evita erro fatal em instalações antigas, onde o
	 * checkout clássico continua sendo usado normalmente.
	 *
	 * @return void
	 */
	private function register_blocks_support() {
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				if ( class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
					$registry->register( new Inky_BTG_Blocks() );
				}
			}
		);

	}

	/**
	 * Publica os dados do meio de pagamento para o script do bloco.
	 *
	 * @return void
	 */
	public function enqueue_blocks_script() {
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return;
		}

		$gateways = WC_Payment_Gateways::instance()->payment_gateways();

		if ( ! isset( $gateways[ Inky_BTG_Gateway::ID ] ) ) {
			return;
		}

		$gateway = $gateways[ Inky_BTG_Gateway::ID ];

		wp_register_script(
			'inky-btg-blocks',
			INKY_BTG_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			INKY_BTG_VERSION,
			true
		);

		wp_localize_script(
			'inky-btg-blocks',
			'inkyBtgBlocks',
			array(
				'title'       => $gateway->title,
				'description' => $gateway->description,
				'icon'        => $gateway->icon,
			)
		);

		wp_enqueue_script( 'inky-btg-blocks' );
	}

	/**
	 * Registra os hooks gerais do plugin.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_filter(
			'plugin_action_links_' . INKY_BTG_BASENAME,
			array( $this, 'add_action_links' )
		);

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Adiciona o atalho de configurações na listagem de plugins.
	 *
	 * @param array<int, string> $links Links existentes.
	 * @return array<int, string> Links com o atalho adicionado.
	 */
	public function add_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=inky-btg-settings' );

		$custom = array(
			'inky_btg_settings' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Configurações', 'inky-btg' )
			),
		);

		return array_merge( $custom, $links );
	}

	/**
	 * Executa migrações quando a versão do banco está desatualizada.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$installed = get_option( INKY_BTG_OPTION_DB_VERSION, '0' );

		if ( version_compare( (string) $installed, INKY_BTG_DB_VERSION, '>=' ) ) {
			return;
		}

		require_once INKY_BTG_PATH . 'includes/class-install.php';
		Inky_BTG_Install::install();
	}

	/**
	 * Recupera um módulo carregado.
	 *
	 * @param string $key Identificador do módulo.
	 * @return object|null Módulo encontrado ou nulo.
	 */
	public function get_module( $key ) {
		return isset( $this->modules[ $key ] ) ? $this->modules[ $key ] : null;
	}
}
