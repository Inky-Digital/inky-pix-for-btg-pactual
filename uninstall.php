<?php
/**
 * Rotina de desinstalação.
 *
 * Executada pelo WordPress quando o plugin é apagado pela tela de plugins.
 * Os dados só são removidos se o lojista tiver marcado a opção correspondente
 * em Ajustes → Diagnóstico.
 *
 * @package Inky_BTG
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove os dados do plugin em uma única instalação.
 *
 * @return void
 */
function inky_btg_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'inky_btg_settings', array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$should_delete = isset( $settings['delete_data_on_uninstall'] ) && 'yes' === $settings['delete_data_on_uninstall'];

	if ( ! $should_delete ) {
		/*
		 * O lojista optou por preservar os dados. Removemos apenas os
		 * agendamentos, para não deixar tarefas órfãs rodando.
		 */
		$hooks = array(
			'inky_btg_refresh_token',
			'inky_btg_poll_payments',
			'inky_btg_cleanup',
			'inky_btg_poll_order',
			'inky_btg_process_webhook',
		);

		foreach ( $hooks as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'inky-btg' );
			}

			wp_unschedule_hook( $hook );
		}

		return;
	}

	$options = array(
		'inky_btg_settings',
		'inky_btg_tokens',
		'inky_btg_credentials',
		'inky_btg_webhook_secret',
		'inky_btg_wizard_state',
		'inky_btg_db_version',
		'inky_btg_oauth_state',
		'inky_btg_encryption_key',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$tables = array(
		$wpdb->prefix . 'inky_btg_logs',
		$wpdb->prefix . 'inky_btg_events',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nome de tabela montado a partir do prefixo do próprio WordPress.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	$hooks = array(
		'inky_btg_refresh_token',
		'inky_btg_poll_payments',
		'inky_btg_cleanup',
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

if ( is_multisite() ) {
	$inky_btg_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $inky_btg_sites as $inky_btg_site_id ) {
		switch_to_blog( (int) $inky_btg_site_id );
		inky_btg_uninstall_site();
		restore_current_blog();
	}
} else {
	inky_btg_uninstall_site();
}
