<?php
/**
 * Criptografia simétrica para credenciais e tokens.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cifra e decifra dados sensíveis usando AES-256-CBC.
 *
 * A chave é própria do plugin (veja `get_key()`), para que a troca dos salts
 * do WordPress não torne o token ilegível.
 */
class Inky_BTG_Encryption {

	/**
	 * Cifra usada pelo OpenSSL.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Prefixo que identifica um valor cifrado por esta classe.
	 *
	 * Permite diferenciar um valor cifrado de um valor em texto puro, o que
	 * facilita a migração de dados antigos.
	 */
	const PREFIX = 'inkybtg:v1:';

	/**
	 * Opção que guarda a chave própria do plugin.
	 */
	const KEY_OPTION = 'inky_btg_encryption_key';

	/**
	 * Chave atual: constante INKY_BTG_ENCRYPTION_KEY no wp-config.php ou, sem
	 * ela, uma chave aleatória gerada uma vez. Não depende dos salts do
	 * WordPress, que plugins de segurança e migrações costumam trocar —
	 * o que apagaria o token e exigiria reconectar a conta.
	 *
	 * @return string Chave binária de 32 bytes.
	 */
	private static function get_key() {
		if ( defined( 'INKY_BTG_ENCRYPTION_KEY' ) && '' !== (string) INKY_BTG_ENCRYPTION_KEY ) {
			return hash( 'sha256', (string) INKY_BTG_ENCRYPTION_KEY, true );
		}

		$stored = get_option( self::KEY_OPTION, '' );

		if ( '' === $stored ) {
			global $wpdb;

			// INSERT IGNORE é atômico: se duas requisições criarem a chave juntas, só a primeira grava.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes')",
					self::KEY_OPTION,
					base64_encode( random_bytes( 32 ) )
				)
			);
			wp_cache_delete( self::KEY_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );

			$stored = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::KEY_OPTION )
			);
		}

		return hash( 'sha256', (string) $stored, true );
	}

	/**
	 * Chaves aceitas na leitura: a atual e as anteriores, para não perder
	 * valores gravados antes da troca de chave.
	 *
	 * @return array<int, string>
	 */
	private static function get_decrypt_keys() {
		$keys   = array( self::get_key() );
		$stored = get_option( self::KEY_OPTION, '' );

		if ( '' !== $stored ) {
			$keys[] = hash( 'sha256', (string) $stored, true );
		}

		$keys[] = self::get_legacy_key();

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Chave antiga, derivada dos salts do WordPress. Só usada para ler
	 * valores gravados por versões anteriores.
	 *
	 * @return string Chave binária de 32 bytes.
	 */
	private static function get_legacy_key() {
		$salt = '';

		if ( defined( 'AUTH_KEY' ) ) {
			$salt .= AUTH_KEY;
		}

		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$salt .= SECURE_AUTH_KEY;
		}

		if ( '' === $salt ) {
			return '';
		}

		return hash( 'sha256', $salt, true );
	}

	/**
	 * Cifra um valor.
	 *
	 * @param string $plaintext Valor em texto puro.
	 * @return string Valor cifrado em Base64, com o prefixo de identificação.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;

		if ( '' === $plaintext ) {
			return '';
		}

		$key    = self::get_key();
		if ( '' === $key ) {
			return '';
		}
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = random_bytes( $iv_len );

		if ( false === $iv ) {
			return '';
		}

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $ciphertext ) {
			return '';
		}

		/*
		 * O HMAC garante integridade: se o valor for alterado no banco, a
		 * decifragem falha em vez de devolver lixo.
		 */
		$hmac = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );

		return self::PREFIX . base64_encode( $iv . $hmac . $ciphertext );
	}

	/**
	 * Decifra um valor.
	 *
	 * @param string $payload Valor cifrado, com ou sem o prefixo.
	 * @return string Valor em texto puro, ou string vazia em caso de falha.
	 */
	public static function decrypt( $payload ) {
		$payload = (string) $payload;

		if ( '' === $payload ) {
			return '';
		}

		if ( 0 !== strpos( $payload, self::PREFIX ) ) {
			return '';
		}

		$encoded = substr( $payload, strlen( self::PREFIX ) );
		$decoded = base64_decode( $encoded, true );

		if ( false === $decoded ) {
			return '';
		}

		$iv_len   = openssl_cipher_iv_length( self::CIPHER );
		$hmac_len = 32;

		if ( strlen( $decoded ) <= ( $iv_len + $hmac_len ) ) {
			return '';
		}

		$iv         = substr( $decoded, 0, $iv_len );
		$hmac       = substr( $decoded, $iv_len, $hmac_len );
		$ciphertext = substr( $decoded, $iv_len + $hmac_len );

		foreach ( self::get_decrypt_keys() as $key ) {
			$expected = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );

			if ( ! hash_equals( $expected, $hmac ) ) {
				continue;
			}

			$plaintext = openssl_decrypt(
				$ciphertext,
				self::CIPHER,
				$key,
				OPENSSL_RAW_DATA,
				$iv
			);

			return false === $plaintext ? '' : $plaintext;
		}

		return '';
	}

	/**
	 * Verifica se um valor já está cifrado por esta classe.
	 *
	 * @param string $value Valor a verificar.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Mascara um valor para exibição segura.
	 *
	 * Mostra apenas os quatro últimos caracteres, o suficiente para o lojista
	 * conferir se cadastrou o valor certo, sem expor o segredo.
	 *
	 * @param string $value Valor a mascarar.
	 * @return string Valor mascarado.
	 */
	public static function mask( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		$length = strlen( $value );

		if ( $length <= 4 ) {
			return str_repeat( '•', $length );
		}

		return str_repeat( '•', 8 ) . substr( $value, -4 );
	}
}
