<?php
/**
 * Leitura e gravação das configurações do plugin.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Centraliza o acesso às opções, aplicando cifragem nas credenciais.
 *
 * As credenciais e os tokens são guardados separadamente por ambiente, pois
 * sandbox e produção têm conjuntos distintos e não devem se misturar.
 */
class Inky_BTG_Settings {

	/**
	 * Ambientes suportados.
	 *
	 * @var array<int, string>
	 */
	private static $environments = array( 'sandbox', 'production' );

	/**
	 * Retorna todas as configurações gerais.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$settings = get_option( INKY_BTG_OPTION_SETTINGS, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return $settings;
	}

	/**
	 * Retorna uma configuração específica.
	 *
	 * @param string $key     Nome da configuração.
	 * @param mixed  $default Valor padrão.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Grava uma configuração específica.
	 *
	 * @param string $key   Nome da configuração.
	 * @param mixed  $value Valor a gravar.
	 * @return void
	 */
	public static function set( $key, $value ) {
		$settings         = self::all();
		$settings[ $key ] = $value;

		update_option( INKY_BTG_OPTION_SETTINGS, $settings, false );
	}

	/**
	 * Grava várias configurações de uma vez.
	 *
	 * @param array<string, mixed> $values Pares chave/valor.
	 * @return void
	 */
	public static function set_many( $values ) {
		$settings = array_merge( self::all(), $values );

		update_option( INKY_BTG_OPTION_SETTINGS, $settings, false );
	}

	/**
	 * Retorna o ambiente ativo.
	 *
	 * @return string `sandbox` ou `production`.
	 */
	public static function get_environment() {
		$environment = self::get( 'environment', 'sandbox' );

		return in_array( $environment, self::$environments, true ) ? $environment : 'sandbox';
	}

	/**
	 * Verifica se o ambiente ativo é o de produção.
	 *
	 * @return bool
	 */
	public static function is_production() {
		return 'production' === self::get_environment();
	}

	/**
	 * Retorna as credenciais de um ambiente, já decifradas.
	 *
	 * @param string|null $environment Ambiente desejado, ou nulo para o ativo.
	 * @return array<string, string> Credenciais com as chaves client_id e client_secret.
	 */
	public static function get_credentials( $environment = null ) {
		$environment = $environment ? $environment : self::get_environment();
		$stored      = get_option( INKY_BTG_OPTION_CREDENTIALS, array() );

		if ( ! is_array( $stored ) || empty( $stored[ $environment ] ) ) {
			return array(
				'client_id'     => '',
				'client_secret' => '',
				'pix_key'       => '',
			);
		}

		$entry = $stored[ $environment ];

		return array(
			'client_id'     => isset( $entry['client_id'] ) ? Inky_BTG_Encryption::decrypt( $entry['client_id'] ) : '',
			'client_secret' => isset( $entry['client_secret'] ) ? Inky_BTG_Encryption::decrypt( $entry['client_secret'] ) : '',
			'pix_key'       => isset( $entry['pix_key'] ) ? Inky_BTG_Encryption::decrypt( $entry['pix_key'] ) : '',
		);
	}

	/**
	 * Grava as credenciais de um ambiente, cifrando os valores.
	 *
	 * Valores vazios não sobrescrevem o que já está gravado, o que permite
	 * salvar o formulário sem redigitar o segredo.
	 *
	 * @param string               $environment Ambiente alvo.
	 * @param array<string, mixed> $credentials Credenciais informadas.
	 * @return void
	 */
	public static function save_credentials( $environment, $credentials ) {
		if ( ! in_array( $environment, self::$environments, true ) ) {
			return;
		}

		$stored = get_option( INKY_BTG_OPTION_CREDENTIALS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		if ( ! isset( $stored[ $environment ] ) || ! is_array( $stored[ $environment ] ) ) {
			$stored[ $environment ] = array();
		}

		foreach ( array( 'client_id', 'client_secret', 'pix_key' ) as $field ) {
			if ( ! isset( $credentials[ $field ] ) ) {
				continue;
			}

			$value = trim( (string) $credentials[ $field ] );

			if ( '' === $value ) {
				continue;
			}

			$encrypted = Inky_BTG_Encryption::encrypt( $value );
			if ( '' !== $encrypted ) {
				$stored[ $environment ][ $field ] = $encrypted;
			}
		}

		update_option( INKY_BTG_OPTION_CREDENTIALS, $stored, false );
	}

	/**
	 * Verifica se as credenciais de um ambiente estão completas.
	 *
	 * @param string|null $environment Ambiente desejado, ou nulo para o ativo.
	 * @return bool
	 */
	public static function has_credentials( $environment = null ) {
		$credentials = self::get_credentials( $environment );

		return '' !== $credentials['client_id'] && '' !== $credentials['client_secret'];
	}

	/** Retorna a chave Pix da conta que receberá os pagamentos. */
	public static function get_pix_key( $environment = null ) {
		$credentials = self::get_credentials( $environment );
		return $credentials['pix_key'];
	}

	/** Informa se a chave Pix está configurada. */
	public static function has_pix_key( $environment = null ) {
		return '' !== self::get_pix_key( $environment );
	}

	/**
	 * Remove as credenciais de um ambiente.
	 *
	 * @param string $environment Ambiente alvo.
	 * @return void
	 */
	public static function clear_credentials( $environment ) {
		$stored = get_option( INKY_BTG_OPTION_CREDENTIALS, array() );

		if ( ! is_array( $stored ) ) {
			return;
		}

		unset( $stored[ $environment ] );

		update_option( INKY_BTG_OPTION_CREDENTIALS, $stored, false );
	}

	/**
	 * Retorna o segredo do webhook de um ambiente.
	 *
	 * @param string|null $environment Ambiente desejado, ou nulo para o ativo.
	 * @return string Segredo em texto puro, ou string vazia.
	 */
	public static function get_webhook_secret( $environment = null ) {
		$environment = $environment ? $environment : self::get_environment();
		$stored      = get_option( INKY_BTG_OPTION_WEBHOOK_SECRET, array() );

		if ( ! is_array( $stored ) || empty( $stored[ $environment ] ) ) {
			return '';
		}

		return Inky_BTG_Encryption::decrypt( $stored[ $environment ] );
	}

	/**
	 * Grava o segredo do webhook de um ambiente.
	 *
	 * @param string $environment Ambiente alvo.
	 * @param string $secret    Segredo informado.
	 * @return void
	 */
	public static function save_webhook_secret( $environment, $secret ) {
		if ( ! in_array( $environment, self::$environments, true ) ) {
			return;
		}

		$secret = trim( (string) $secret );

		if ( '' === $secret ) {
			return;
		}

		$stored = get_option( INKY_BTG_OPTION_WEBHOOK_SECRET, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$encrypted = Inky_BTG_Encryption::encrypt( $secret );
		if ( '' === $encrypted ) {
			return;
		}
		$stored[ $environment ] = $encrypted;

		update_option( INKY_BTG_OPTION_WEBHOOK_SECRET, $stored, false );
	}

	/**
	 * Remove o segredo do webhook de um ambiente.
	 *
	 * @param string $environment Ambiente alvo.
	 * @return void
	 */
	public static function clear_webhook_secret( $environment ) {
		$stored = get_option( INKY_BTG_OPTION_WEBHOOK_SECRET, array() );

		if ( ! is_array( $stored ) ) {
			return;
		}

		unset( $stored[ $environment ] );

		update_option( INKY_BTG_OPTION_WEBHOOK_SECRET, $stored, false );
	}

	/**
	 * Retorna a URL de callback do OAuth.
	 *
	 * O briefing fixa esta rota, portanto ela não é configurável.
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return rest_url( INKY_BTG_REST_NAMESPACE . '/oauth/callback' );
	}

	/**
	 * Retorna a URL pública do webhook.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		return rest_url( INKY_BTG_REST_NAMESPACE . '/webhook' );
	}

	/**
	 * Retorna o tempo de expiração da cobrança, em minutos.
	 *
	 * @return int
	 */
	public static function get_expiration_minutes() {
		$minutes = (int) self::get( 'expiration_minutes', 60 );

		return max( 5, min( 1440, $minutes ) );
	}

	/**
	 * Verifica se o polling de reserva está habilitado.
	 *
	 * @return bool
	 */
	public static function is_polling_enabled() {
		return 'yes' === self::get( 'polling_enabled', 'yes' );
	}

	/**
	 * Verifica se o log detalhado está habilitado.
	 *
	 * @return bool
	 */
	public static function is_debug_enabled() {
		return 'yes' === self::get( 'debug_logging', 'no' );
	}

	/**
	 * Verifica se os dados devem ser removidos na desinstalação.
	 *
	 * @return bool
	 */
	public static function should_delete_data_on_uninstall() {
		return 'yes' === self::get( 'delete_data_on_uninstall', 'no' );
	}

	/**
	 * Retorna o estado do assistente de configuração.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_wizard_state() {
		$state = get_option( INKY_BTG_OPTION_WIZARD_STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Atualiza o estado do assistente de configuração.
	 *
	 * @param array<string, mixed> $values Valores a mesclar.
	 * @return void
	 */
	public static function update_wizard_state( $values ) {
		$state = array_merge( self::get_wizard_state(), $values );

		update_option( INKY_BTG_OPTION_WIZARD_STATE, $state, false );
	}

	/**
	 * Remove todas as opções do plugin.
	 *
	 * Usado apenas pelo `uninstall.php`, e somente quando o lojista autoriza.
	 *
	 * @return void
	 */
	public static function delete_all() {
		$options = array(
			INKY_BTG_OPTION_SETTINGS,
			INKY_BTG_OPTION_TOKENS,
			INKY_BTG_OPTION_CREDENTIALS,
			INKY_BTG_OPTION_WEBHOOK_SECRET,
			INKY_BTG_OPTION_WIZARD_STATE,
			INKY_BTG_OPTION_DB_VERSION,
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}
	}
}
