<?php
/**
 * Registro de eventos com mascaramento de dados sensíveis.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Centraliza o log do plugin.
 *
 * Toda mensagem passa por um processo de mascaramento antes de ser gravada,
 * garantindo que tokens, segredos e credenciais nunca cheguem ao arquivo de
 * log nem à tela de diagnóstico.
 */
class Inky_BTG_Logger {

	/**
	 * Identificador do canal usado no WC_Logger.
	 */
	const CHANNEL = 'inky-btg';

	/**
	 * Níveis de log aceitos, em ordem crescente de severidade.
	 *
	 * @var array<int, string>
	 */
	private static $levels = array( 'debug', 'info', 'notice', 'warning', 'error' );

	/**
	 * Chaves cujo valor deve ser sempre mascarado.
	 *
	 * A comparação é feita em minúsculas e por trecho, então `access_token`,
	 * `refresh_token` e `id_token` são todos cobertos por `token`.
	 *
	 * @var array<int, string>
	 */
	private static $sensitive_keys = array(
		'token',
		'secret',
		'password',
		'senha',
		'client_secret',
		'authorization',
		'code',
		'pixkey',
		'pix_key',
		'card',
		'cvv',
		'cvc',
		'pan',
	);

	/**
	 * Registra uma mensagem.
	 *
	 * @param string               $level   Nível: debug, info, notice, warning ou error.
	 * @param string               $message Mensagem legível.
	 * @param array<string, mixed> $context Dados adicionais.
	 * @return void
	 */
	public static function log( $level, $message, $context = array() ) {
		$level = strtolower( (string) $level );

		if ( ! in_array( $level, self::$levels, true ) ) {
			$level = 'info';
		}

		$environment = self::get_environment();
		$safe_context = self::sanitize( $context );
		$correlation  = self::extract_correlation_id( $context );

		self::write_to_database( $level, $environment, $message, $safe_context, $correlation );

		if ( ! self::is_debug_enabled() && 'error' !== $level ) {
			return;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		$logger->log(
			$level,
			self::format_message( $message, $safe_context ),
			array( 'source' => self::CHANNEL )
		);
	}

	/**
	 * Atalho para nível debug.
	 *
	 * @param string               $message Mensagem.
	 * @param array<string, mixed> $context Dados adicionais.
	 * @return void
	 */
	public static function debug( $message, $context = array() ) {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Atalho para nível info.
	 *
	 * @param string               $message Mensagem.
	 * @param array<string, mixed> $context Dados adicionais.
	 * @return void
	 */
	public static function info( $message, $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Atalho para nível warning.
	 *
	 * @param string               $message Mensagem.
	 * @param array<string, mixed> $context Dados adicionais.
	 * @return void
	 */
	public static function warning( $message, $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Atalho para nível error.
	 *
	 * @param string               $message Mensagem.
	 * @param array<string, mixed> $context Dados adicionais.
	 * @return void
	 */
	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Grava a mensagem na tabela própria do plugin.
	 *
	 * @param string               $level        Nível do log.
	 * @param string               $environment  Ambiente ativo.
	 * @param string               $message      Mensagem.
	 * @param array<string, mixed> $context      Contexto já sanitizado.
	 * @param string               $correlation  Identificador de correlação.
	 * @return void
	 */
	private static function write_to_database( $level, $environment, $message, $context, $correlation ) {
		global $wpdb;

		if ( ! class_exists( 'Inky_BTG_Install' ) ) {
			return;
		}

		$table = Inky_BTG_Install::get_logs_table_name();

		$wpdb->insert(
			$table,
			array(
				'created_at'     => current_time( 'mysql', true ),
				'environment'    => $environment,
				'level'          => $level,
				'source'         => isset( $context['source'] ) ? substr( (string) $context['source'], 0, 60 ) : '',
				'message'        => wp_strip_all_tags( (string) $message ),
				'context'        => empty( $context ) ? null : wp_json_encode( $context ),
				'correlation_id' => '' !== $correlation ? $correlation : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Monta a linha final enviada ao WC_Logger.
	 *
	 * @param string               $message Mensagem.
	 * @param array<string, mixed> $context Contexto sanitizado.
	 * @return string
	 */
	private static function format_message( $message, $context ) {
		if ( empty( $context ) ) {
			return (string) $message;
		}

		return (string) $message . ' ' . wp_json_encode( $context );
	}

	/**
	 * Remove ou mascara dados sensíveis de um contexto.
	 *
	 * @param mixed $data Dado a sanitizar, em qualquer profundidade.
	 * @param int   $depth Profundidade atual, para evitar recursão infinita.
	 * @return mixed Dado sanitizado.
	 */
	public static function sanitize( $data, $depth = 0 ) {
		if ( $depth > 8 ) {
			return '[profundidade máxima atingida]';
		}

		if ( is_array( $data ) ) {
			$clean = array();

			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
					$clean[ $key ] = self::mask_value( $value );
					continue;
				}

				$clean[ $key ] = self::sanitize( $value, $depth + 1 );
			}

			return $clean;
		}

		if ( is_object( $data ) ) {
			return self::sanitize( get_object_vars( $data ), $depth + 1 );
		}

		if ( is_string( $data ) ) {
			return self::scrub_string( $data );
		}

		return $data;
	}

	/**
	 * Verifica se uma chave é considerada sensível.
	 *
	 * @param string $key Nome da chave.
	 * @return bool
	 */
	private static function is_sensitive_key( $key ) {
		$key = strtolower( $key );

		foreach ( self::$sensitive_keys as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Mascara um valor sensível.
	 *
	 * @param mixed $value Valor original.
	 * @return string Valor mascarado.
	 */
	private static function mask_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '[removido]';
		}

		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		return Inky_BTG_Encryption::mask( $value );
	}

	/**
	 * Remove padrões sensíveis que apareçam dentro de textos livres.
	 *
	 * Cobre os casos em que o segredo não está em uma chave conhecida, por
	 * exemplo dentro de uma URL ou de uma mensagem de erro da API.
	 *
	 * @param string $text Texto original.
	 * @return string Texto limpo.
	 */
	private static function scrub_string( $text ) {
		$patterns = array(
			// Parâmetros de query com nomes sensíveis.
			'/([?&](?:access_token|refresh_token|id_token|token|code|client_secret|secret|password)=)[^&\s]+/i',
			// Cabeçalho Authorization em qualquer forma.
			'/(Authorization\s*:\s*)(?:Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i',
			// Basic auth embutido em URL.
			'/(https?:\/\/)[^:@\s\/]+:[^@\s\/]+@/i',
			// Chaves PIX em formato UUID.
			'/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
		);

		$replacements = array(
			'$1[removido]',
			'$1[removido]',
			'$1[removido]@',
			'[removido]',
		);

		$clean = preg_replace( $patterns, $replacements, $text );

		return is_string( $clean ) ? $clean : $text;
	}

	/**
	 * Extrai o identificador de correlação do contexto, se presente.
	 *
	 * @param array<string, mixed> $context Contexto original.
	 * @return string
	 */
	private static function extract_correlation_id( $context ) {
		$keys = array( 'x-correlation-id', 'x_correlation_id', 'correlation_id' );

		foreach ( $keys as $key ) {
			if ( ! empty( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				return substr( (string) $context[ $key ], 0, 100 );
			}
		}

		return '';
	}

	/**
	 * Retorna o ambiente ativo.
	 *
	 * @return string
	 */
	private static function get_environment() {
		$settings = get_option( INKY_BTG_OPTION_SETTINGS, array() );
		$value    = isset( $settings['environment'] ) ? $settings['environment'] : 'sandbox';

		return in_array( $value, array( 'sandbox', 'production' ), true ) ? $value : 'sandbox';
	}

	/**
	 * Verifica se o log detalhado está habilitado.
	 *
	 * @return bool
	 */
	private static function is_debug_enabled() {
		$settings = get_option( INKY_BTG_OPTION_SETTINGS, array() );

		return isset( $settings['debug_logging'] ) && 'yes' === $settings['debug_logging'];
	}

	/**
	 * Remove registros antigos conforme a retenção configurada.
	 *
	 * @param int $days Dias de retenção.
	 * @return int Número de linhas removidas.
	 */
	public static function purge_old_entries( $days = 30 ) {
		global $wpdb;

		$days = max( 1, (int) $days );
		$table = Inky_BTG_Install::get_logs_table_name();

		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )',
				$table,
				$days
			)
		);

		return (int) $deleted;
	}
}
