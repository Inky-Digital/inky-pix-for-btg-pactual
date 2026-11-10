<?php
/**
 * Rotinas de ativação, desativação e migração do banco de dados.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Responsável por criar e atualizar a estrutura de dados do plugin.
 */
class Inky_BTG_Install {

	/**
	 * Nome do hook usado para renovar o token de acesso.
	 */
	const HOOK_REFRESH_TOKEN = 'inky_btg_refresh_token';

	/**
	 * Nome do hook usado para verificar pagamentos pendentes.
	 */
	const HOOK_POLL_PAYMENTS = 'inky_btg_poll_payments';

	/**
	 * Nome do hook usado para limpar registros antigos.
	 */
	const HOOK_CLEANUP = 'inky_btg_cleanup';

	/**
	 * Executa a rotina de ativação.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
		self::schedule_events();

		/*
		 * Garante que as regras de reescrita das rotas REST sejam
		 * regeneradas na próxima requisição.
		 */
		flush_rewrite_rules();
	}

	/**
	 * Executa a rotina de desativação.
	 *
	 * Os dados são preservados. A remoção definitiva acontece apenas pelo
	 * `uninstall.php`, e somente se o lojista tiver marcado a opção.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::unschedule_events();
		flush_rewrite_rules();
	}

	/**
	 * Cria ou atualiza as tabelas do plugin.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_logs      = self::get_logs_table_name();
		$table_events    = self::get_events_table_name();

		/*
		 * Tabela de log de diagnóstico.
		 *
		 * Guarda as chamadas HTTP e os eventos internos para a tela de
		 * diagnóstico. Nunca deve conter tokens, segredos ou dados de cartão.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nome de tabela montado a partir do prefixo do próprio WordPress.
		$sql_logs = "CREATE TABLE {$table_logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			environment varchar(20) NOT NULL DEFAULT 'sandbox',
			level varchar(20) NOT NULL DEFAULT 'info',
			source varchar(60) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			correlation_id varchar(100) NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level (level),
			KEY environment (environment),
			KEY correlation_id (correlation_id)
		) {$charset_collate};";

		/*
		 * Tabela de eventos de webhook.
		 *
		 * Usada para deduplicar entregas pelo `x-correlation-id` e para
		 * auditar retentativas. O índice único em `correlation_id` é o que
		 * impede o processamento duplicado de um mesmo pagamento.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nome de tabela montado a partir do prefixo do próprio WordPress.
		$sql_events = "CREATE TABLE {$table_events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			received_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			environment varchar(20) NOT NULL DEFAULT 'sandbox',
			event_type varchar(60) NOT NULL DEFAULT '',
			correlation_id varchar(100) NOT NULL,
			webhook_id varchar(100) NULL,
			order_id bigint(20) unsigned NULL,
			tx_id varchar(100) NULL,
			status varchar(30) NOT NULL DEFAULT 'received',
			attempts smallint(5) unsigned NOT NULL DEFAULT 1,
			payload longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY correlation_id (correlation_id),
			KEY event_type (event_type),
			KEY order_id (order_id),
			KEY tx_id (tx_id)
		) {$charset_collate};";

		dbDelta( $sql_logs );
		dbDelta( $sql_events );

		update_option( INKY_BTG_OPTION_DB_VERSION, INKY_BTG_DB_VERSION, false );

		self::set_default_options();
	}

	/**
	 * Define as opções padrão, sem sobrescrever valores já existentes.
	 *
	 * @return void
	 */
	private static function set_default_options() {
		$defaults = array(
			'environment'          => 'sandbox',
			'enabled'              => 'no',
			'title'                => __( 'PIX', 'inky-btg' ),
			'description'          => __( 'Pague com PIX usando o QR Code gerado na finalização do pedido.', 'inky-btg' ),
			'instructions'         => __( 'Escaneie o QR Code ou copie o código PIX para concluir o pagamento.', 'inky-btg' ),
			'expiration_minutes'   => 60,
			'amount_unit'          => 'reais',
			'polling_enabled'      => 'yes',
			'debug_logging'        => 'no',
			'delete_data_on_uninstall' => 'no',
		);

		$current = get_option( INKY_BTG_OPTION_SETTINGS, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		update_option(
			INKY_BTG_OPTION_SETTINGS,
			array_merge( $defaults, $current ),
			false
		);
	}

	/**
	 * Agenda os eventos recorrentes do plugin.
	 *
	 * @return void
	 */
	public static function schedule_events() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			/*
			 * Sem o Action Scheduler o plugin usa o WP-Cron como reserva.
			 * O agendamento é feito mesmo assim, pois o WP-Cron é nativo.
			 */
			if ( ! wp_next_scheduled( self::HOOK_REFRESH_TOKEN ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::HOOK_REFRESH_TOKEN );
			}

			if ( ! wp_next_scheduled( self::HOOK_POLL_PAYMENTS ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'inky_btg_minute', self::HOOK_POLL_PAYMENTS );
			}

			if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK_CLEANUP );
			}

			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK_REFRESH_TOKEN ) ) {
			as_schedule_recurring_action(
				time() + HOUR_IN_SECONDS,
				12 * HOUR_IN_SECONDS,
				self::HOOK_REFRESH_TOKEN,
				array(),
				'inky-btg'
			);
		}

		if ( false === as_next_scheduled_action( self::HOOK_POLL_PAYMENTS ) ) {
			as_schedule_recurring_action(
				time() + MINUTE_IN_SECONDS,
				MINUTE_IN_SECONDS,
				self::HOOK_POLL_PAYMENTS,
				array(),
				'inky-btg'
			);
		}

		if ( false === as_next_scheduled_action( self::HOOK_CLEANUP ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				self::HOOK_CLEANUP,
				array(),
				'inky-btg'
			);
		}
	}

	/**
	 * Remove os eventos agendados.
	 *
	 * @return void
	 */
	public static function unschedule_events() {
		$hooks = array(
			self::HOOK_REFRESH_TOKEN,
			self::HOOK_POLL_PAYMENTS,
			self::HOOK_CLEANUP,
			'inky_btg_poll_order',
			'inky_btg_process_webhook',
		);

		foreach ( $hooks as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'inky-btg' );
			}

			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Registra o intervalo de um minuto usado pelo polling.
	 *
	 * @param array<string, array<string, mixed>> $schedules Intervalos existentes.
	 * @return array<string, array<string, mixed>> Intervalos com o novo item.
	 */
	public static function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['inky_btg_minute'] ) ) {
			$schedules['inky_btg_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'A cada minuto (PIX BTG)', 'inky-btg' ),
			);
		}

		return $schedules;
	}

	/**
	 * Retorna o nome completo da tabela de logs.
	 *
	 * @return string
	 */
	public static function get_logs_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'inky_btg_logs';
	}

	/**
	 * Retorna o nome completo da tabela de eventos de webhook.
	 *
	 * @return string
	 */
	public static function get_events_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'inky_btg_events';
	}

	/** Remove registros de diagnóstico antigos sem apagar pedidos. */
	public static function cleanup() {
		global $wpdb;
		$logs = self::get_logs_table_name();
		$events = self::get_events_table_name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$logs} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE received_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
	}
}
