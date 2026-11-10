<?php
/**
 * Armazenamento de tokens OAuth por ambiente.
 *
 * Tokens de sandbox e produção são independentes e nunca se misturam. Tudo é
 * gravado cifrado, usando a mesma camada de criptografia das credenciais.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guarda e recupera tokens de acesso e atualização.
 */
class Inky_BTG_Token_Store {

	/**
	 * Retorna todos os tokens gravados.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$tokens = get_option( INKY_BTG_OPTION_TOKENS, array() );

		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Retorna os dados de token de um ambiente.
	 *
	 * @param string $environment Ambiente desejado.
	 * @return array<string, mixed> Dados do token, ou array vazio.
	 */
	public static function get( $environment ) {
		$tokens = self::all();

		if ( empty( $tokens[ $environment ] ) || ! is_array( $tokens[ $environment ] ) ) {
			return array();
		}

		$data = $tokens[ $environment ];

		foreach ( array( 'access_token', 'refresh_token', 'id_token' ) as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				$data[ $field ] = Inky_BTG_Encryption::decrypt( $data[ $field ] );
			}
		}

		return $data;
	}

	/**
	 * Grava os dados de token de um ambiente.
	 *
	 * @param string               $environment Ambiente.
	 * @param array<string, mixed> $data        Dados do token.
	 * @return void
	 */
	public static function save( $environment, $data ) {
		$tokens = self::all();

		$stored = array(
			'access_token'  => '',
			'refresh_token' => '',
			'id_token'      => '',
			'scope'         => '',
			'token_type'    => '',
			'expires_in'    => 0,
			'expires_at'    => 0,
			'refresh_expires_at' => 0,
			'obtained_at'   => time(),
			'company_id'    => '',
		);

		foreach ( array( 'access_token', 'refresh_token', 'id_token' ) as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				$stored[ $field ] = Inky_BTG_Encryption::encrypt( (string) $data[ $field ] );
			}
		}

		foreach ( array( 'scope', 'token_type', 'company_id' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_scalar( $data[ $field ] ) ) {
				$stored[ $field ] = (string) $data[ $field ];
			}
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : Inky_BTG_API_Contract::ACCESS_TOKEN_LIFETIME;

		$stored['expires_in'] = $expires_in;
		$stored['expires_at'] = time() + $expires_in;

		/*
		 * O refresh token tem 10 dias de validade e o prazo é reiniciado a
		 * cada uso. Guardamos a data limite para saber quando é preciso
		 * refazer o fluxo de autorização.
		 */
		$stored['refresh_expires_at'] = time() + ( Inky_BTG_API_Contract::REFRESH_TOKEN_LIFETIME_DAYS * DAY_IN_SECONDS );

		$tokens[ $environment ] = $stored;

		update_option( INKY_BTG_OPTION_TOKENS, $tokens, false );
	}

	/**
	 * Remove os tokens de um ambiente.
	 *
	 * @param string $environment Ambiente.
	 * @return void
	 */
	public static function clear( $environment ) {
		$tokens = self::all();

		if ( isset( $tokens[ $environment ] ) ) {
			unset( $tokens[ $environment ] );
		}

		update_option( INKY_BTG_OPTION_TOKENS, $tokens, false );
	}

	/**
	 * Remove os tokens de todos os ambientes.
	 *
	 * @return void
	 */
	public static function clear_all() {
		delete_option( INKY_BTG_OPTION_TOKENS );
	}

	/**
	 * Indica se existe um token de acesso para o ambiente.
	 *
	 * @param string $environment Ambiente.
	 * @return bool
	 */
	public static function has_token( $environment ) {
		$data = self::get( $environment );

		return ! empty( $data['access_token'] );
	}

	/**
	 * Indica se o token de acesso está vencido ou perto de vencer.
	 *
	 * @param string $environment Ambiente.
	 * @return bool
	 */
	public static function is_expired( $environment ) {
		$data = self::get( $environment );

		if ( empty( $data['access_token'] ) || empty( $data['expires_at'] ) ) {
			return true;
		}

		return ( (int) $data['expires_at'] - Inky_BTG_API_Contract::TOKEN_EXPIRY_MARGIN ) <= time();
	}

	/**
	 * Indica se o refresh token ainda é válido.
	 *
	 * @param string $environment Ambiente.
	 * @return bool
	 */
	public static function can_refresh( $environment ) {
		$data = self::get( $environment );

		// ponytail: refresh_expires_at é só estimativa local; quem decide se o refresh token vale é o BTG.
		return ! empty( $data['refresh_token'] );
	}

	/**
	 * Indica se já passou do intervalo de renovação proativa.
	 *
	 * O briefing pede renovação a cada 12 horas, mesmo que o token ainda
	 * esteja válido por 24 horas.
	 *
	 * @param string $environment Ambiente.
	 * @return bool
	 */
	public static function needs_proactive_refresh( $environment ) {
		$data = self::get( $environment );

		if ( empty( $data['obtained_at'] ) ) {
			return true;
		}

		return ( (int) $data['obtained_at'] + Inky_BTG_API_Contract::PROACTIVE_REFRESH_INTERVAL ) <= time();
	}

	/**
	 * Retorna o identificador da empresa gravado junto ao token.
	 *
	 * @param string $environment Ambiente.
	 * @return string CNPJ, ou string vazia.
	 */
	public static function get_company_id( $environment ) {
		$data = self::get( $environment );

		return isset( $data['company_id'] ) ? (string) $data['company_id'] : '';
	}

	/**
	 * Retorna um resumo seguro do estado do token, para exibição.
	 *
	 * Nunca devolve o token em si.
	 *
	 * @param string $environment Ambiente.
	 * @return array<string, mixed>
	 */
	public static function get_summary( $environment ) {
		$data = self::get( $environment );

		if ( empty( $data['access_token'] ) ) {
			return array(
				'connected'  => false,
				'expires_at' => 0,
				'scope'      => '',
				'company_id' => '',
			);
		}

		return array(
			'connected'  => true,
			'expires_at' => isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0,
			'scope'      => isset( $data['scope'] ) ? (string) $data['scope'] : '',
			'company_id' => isset( $data['company_id'] ) ? (string) $data['company_id'] : '',
		);
	}
}
