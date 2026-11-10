<?php
/**
 * Plugin Name:       Inky Pix for BTG Pactual
 * Plugin URI:        https://github.com/Inky-Digital/inky-pix-for-btg-pactual
 * Description:       Pix payments through BTG Pactual Empresas for WooCommerce: QR Code, copy and paste code, webhook and automatic order confirmation. Unofficial plugin, not affiliated with BTG Pactual.
 * Version:           0.2.3
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Inky Digital
 * Author URI:        https://inkydigital.com.br
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       inky-pix-for-btg-pactual
 * Domain Path:       /languages
 * WC requires at least: 7.1
 * Requires Plugins:  woocommerce
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Constantes globais do plugin.
 *
 * O prefixo `INKY_BTG_` é usado em todas as constantes para evitar colisão
 * com outros plugins instalados no mesmo WordPress.
 */
define( 'INKY_BTG_VERSION', '0.2.3' );
define( 'INKY_BTG_FILE', __FILE__ );
define( 'INKY_BTG_PATH', plugin_dir_path( __FILE__ ) );
define( 'INKY_BTG_URL', plugin_dir_url( __FILE__ ) );
define( 'INKY_BTG_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Slug usado em rotas REST, opções e identificadores internos.
 *
 * O briefing já define a rota de callback como
 * `wp-json/inky-btg/v1/oauth/callback`, portanto o slug é fixo.
 */
define( 'INKY_BTG_SLUG', 'inky-pix-for-btg-pactual' );

/**
 * Namespace das rotas REST.
 */
define( 'INKY_BTG_REST_NAMESPACE', 'inky-btg/v1' );

/**
 * Nome da opção que guarda as configurações do plugin.
 */
define( 'INKY_BTG_OPTION_SETTINGS', 'inky_btg_settings' );

/**
 * Nome da opção que guarda os tokens OAuth por ambiente.
 */
define( 'INKY_BTG_OPTION_TOKENS', 'inky_btg_tokens' );

/**
 * Nome da opção que guarda as credenciais cifradas por ambiente.
 */
define( 'INKY_BTG_OPTION_CREDENTIALS', 'inky_btg_credentials' );

/**
 * Nome da opção que guarda o segredo do webhook por ambiente.
 */
define( 'INKY_BTG_OPTION_WEBHOOK_SECRET', 'inky_btg_webhook_secret' );

/**
 * Nome da opção que guarda o estado do assistente de configuração.
 */
define( 'INKY_BTG_OPTION_WIZARD_STATE', 'inky_btg_wizard_state' );

/**
 * Versão do esquema do banco de dados.
 *
 * Incrementar sempre que a estrutura das tabelas mudar, para que o
 * `class-install.php` saiba quando rodar o `dbDelta` novamente.
 */
define( 'INKY_BTG_DB_VERSION', '1.0.0' );

/**
 * Nome da opção que guarda a versão atual do esquema do banco.
 */
define( 'INKY_BTG_OPTION_DB_VERSION', 'inky_btg_db_version' );

/**
 * Capacidade exigida para gerenciar o plugin.
 */
define( 'INKY_BTG_CAPABILITY', 'manage_woocommerce' );

/**
 * Autoloader próprio.
 *
 * O briefing proíbe dependências externas instaladas via Composer, pois o
 * plugin precisa ser instalado por upload de ZIP. Por isso o carregamento de
 * classes é feito manualmente, convertendo o nome da classe em caminho de
 * arquivo.
 *
 * Regras de conversão:
 * - `Inky_BTG_Plugin`        -> `includes/class-plugin.php`
 * - `Inky_BTG_API_Client`    -> `includes/api/class-api-client.php`
 * - `Inky_BTG_Gateway`       -> `includes/gateway/class-gateway.php`
 *
 * @param string $class_name Nome completo da classe solicitada.
 * @return void
 */
function inky_btg_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'Inky_BTG_' ) ) {
		return;
	}
	$files = array(
		'Plugin' => 'class-plugin.php', 'Install' => 'class-install.php',
		'Encryption' => 'class-encryption.php', 'Logger' => 'class-logger.php',
		'Settings' => 'class-settings.php', 'API_Contract' => 'api/class-api-contract.php',
		'API_Client' => 'api/class-api-client.php', 'Error_Translator' => 'api/class-error-translator.php',
		'Locations' => 'api/class-locations.php', 'Instant_Collections' => 'api/class-instant-collections.php',
		'OAuth' => 'oauth/class-oauth.php', 'Token_Store' => 'oauth/class-token-store.php',
		'Gateway' => 'gateway/class-gateway.php', 'Charge_Service' => 'gateway/class-charge-service.php',
		'Thank_You' => 'gateway/class-thank-you.php', 'Email' => 'gateway/class-email.php',
		'Blocks' => 'gateway/class-blocks.php', 'Ajax' => 'gateway/class-ajax.php',
		'Webhook' => 'webhook/class-webhook.php', 'Scheduler' => 'polling/class-scheduler.php',
		'Poller' => 'polling/class-poller.php', 'Admin' => 'admin/class-admin.php',
	);
	$key = substr( $class_name, strlen( 'Inky_BTG_' ) );
	if ( ! isset( $files[ $key ] ) ) {
		return;
	}
	$file_path = INKY_BTG_PATH . 'includes/' . $files[ $key ];

	if ( is_readable( $file_path ) ) {
		require_once $file_path;
	}
}
spl_autoload_register( 'inky_btg_autoload' );
add_filter( 'cron_schedules', array( 'Inky_BTG_Install', 'register_cron_schedules' ) );
add_action( Inky_BTG_Install::HOOK_CLEANUP, array( 'Inky_BTG_Install', 'cleanup' ) );

/** Declara a compatibilidade antes de o WooCommerce iniciar. */
function inky_btg_declare_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
}
add_action( 'before_woocommerce_init', 'inky_btg_declare_compatibility' );

/**
 * Verifica se o WooCommerce está ativo antes de inicializar o plugin.
 *
 * Sem o WooCommerce não há como registrar um meio de pagamento, então o
 * plugin apenas exibe um aviso administrativo e encerra a execução.
 *
 * @return bool Verdadeiro quando o WooCommerce está disponível.
 */
function inky_btg_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Exibe o aviso de dependência ausente no painel administrativo.
 *
 * @return void
 */
function inky_btg_render_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'O plugin PIX BTG Pactual para WooCommerce precisa do WooCommerce instalado e ativo para funcionar.',
			'inky-pix-for-btg-pactual'
		)
	);
}

/**
 * Inicializa o plugin.
 *
 * O carregamento é adiado até `plugins_loaded` para garantir que o
 * WooCommerce já tenha registrado suas classes e constantes.
 *
 * @return void
 */
function inky_btg_bootstrap() {
	if ( ! inky_btg_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'inky_btg_render_missing_woocommerce_notice' );
		return;
	}

	Inky_BTG_Plugin::instance();
}
add_action( 'plugins_loaded', 'inky_btg_bootstrap', 11 );

/**
 * Rotina de ativação.
 *
 * Registrada aqui, e não dentro da classe, porque o WordPress exige que o
 * hook `register_activation_hook` seja chamado no arquivo principal.
 *
 * @return void
 */
function inky_btg_activate() {
	require_once INKY_BTG_PATH . 'includes/class-install.php';
	Inky_BTG_Install::activate();
}
register_activation_hook( __FILE__, 'inky_btg_activate' );

/**
 * Rotina de desativação.
 *
 * Remove apenas os agendamentos. Os dados são preservados, pois a remoção
 * definitiva é opcional e controlada pelo `uninstall.php`.
 *
 * @return void
 */
function inky_btg_deactivate() {
	require_once INKY_BTG_PATH . 'includes/class-install.php';
	Inky_BTG_Install::deactivate();
}
register_deactivation_hook( __FILE__, 'inky_btg_deactivate' );
