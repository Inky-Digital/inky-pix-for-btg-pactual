<?php
/**
 * Fluxo OAuth 2.0 Authorization Code com o BTG Id.
 *
 * Cuida de montar a URL de autorização, receber o `code` na rota REST de
 * retorno, trocar por tokens, renovar de forma proativa e desconectar.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gerencia o ciclo de vida da autorização.
 */
class Inky_BTG_OAuth {

	/**
	 * Rota REST do retorno da autorização.
	 */
	const CALLBACK_ROUTE = '/oauth/callback';

	/**
	 * Nome da opção que guarda o estado temporário do fluxo.
	 */
	const STATE_OPTION = 'inky_btg_oauth_state';

	/**
	 * Validade do link de autorização, em segundos. Folgada para dar tempo
	 * de enviar o link à cliente e ela fazer o login.
	 */
	const STATE_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Registra as rotas e os agendamentos.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( Inky_BTG_Install::HOOK_REFRESH_TOKEN, array( $this, 'refresh_tokens' ) );
	}

	/**
	 * Registra a rota de retorno do OAuth.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			INKY_BTG_REST_NAMESPACE,
			self::CALLBACK_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Monta a URL de autorização para o ambiente atual.
	 *
	 * Confirmado na documentação: `GET /oauth2/authorize` com `client_id`,
	 * `response_type=code`, `redirect_uri`, `scope` e `prompt=login`.
	 *
	 * @param string $environment Ambiente desejado.
	 * @return string|WP_Error URL de autorização.
	 */
	public function get_authorize_url( $environment = '' ) {
		if ( '' === $environment ) {
			$environment = Inky_BTG_Settings::get_environment();
		}

		$credentials = Inky_BTG_Settings::get_credentials( $environment );

		if ( empty( $credentials['client_id'] ) ) {
			return new WP_Error(
				'inky_btg_missing_client_id',
				__( 'Informe o Client ID antes de conectar a conta.', 'inky-pix-for-btg-pactual' )
			);
		}

		$state = $this->create_state( $environment );

		$query = array(
			'client_id'     => $credentials['client_id'],
			'response_type' => 'code',
			'redirect_uri'  => Inky_BTG_Settings::get_redirect_uri(),
			'scope'         => Inky_BTG_API_Contract::get_scopes(),
			'prompt'        => 'login',
			'state'         => $state,
		);

		return Inky_BTG_API_Contract::get_auth_base_url( $environment )
			. Inky_BTG_API_Contract::build_path( 'authorize' )
			. '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Cria e guarda o valor de `state` do fluxo.
	 *
	 * @param string $environment Ambiente.
	 * @return string Valor de state.
	 */
	private function create_state( $environment ) {
		$state = wp_generate_password( 32, false, false );

		// Vários links podem estar válidos ao mesmo tempo: gerar um novo não invalida o que já foi enviado à cliente.
		$states            = $this->get_valid_states();
		$states[ $state ]  = array(
			'environment' => $environment,
			'created_at'  => time(),
		);

		update_option( self::STATE_OPTION, $states, false );

		return $state;
	}

	/**
	 * Retorna os states ainda dentro da validade.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_valid_states() {
		$states = get_option( self::STATE_OPTION, array() );

		// Formato antigo guardava um único state com a chave `value`.
		if ( ! is_array( $states ) || isset( $states['value'] ) ) {
			return array();
		}

		foreach ( $states as $value => $entry ) {
			if ( empty( $entry['created_at'] ) || (int) $entry['created_at'] < time() - self::STATE_TTL ) {
				unset( $states[ $value ] );
			}
		}

		return $states;
	}

	/**
	 * Valida o `state` recebido no retorno.
	 *
	 * @param string $state Valor recebido.
	 * @return string|WP_Error Ambiente do fluxo, ou erro.
	 */
	private function validate_state( $state ) {
		$states = $this->get_valid_states();
		$state  = (string) $state;

		if ( '' === $state || empty( $states[ $state ]['environment'] ) ) {
			return new WP_Error(
				'inky_btg_invalid_state',
				__( 'O link de autorização expirou ou já foi usado. Gere um novo link e tente novamente.', 'inky-pix-for-btg-pactual' )
			);
		}

		$environment = (string) $states[ $state ]['environment'];

		// O state é de uso único.
		unset( $states[ $state ] );
		update_option( self::STATE_OPTION, $states, false );

		return $environment;
	}

	/**
	 * Trata o retorno da autorização.
	 *
	 * @param WP_REST_Request $request Requisição recebida.
	 * @return void
	 */
	public function handle_callback( $request ) {
		$error = $request->get_param( 'error' );

		if ( ! empty( $error ) ) {
			$this->redirect_back( 'error', $this->describe_callback_error( $request ) );
			return;
		}

		$state = (string) $request->get_param( 'state' );
		$code  = (string) $request->get_param( 'code' );

		if ( '' === $code ) {
			$this->redirect_back( 'error', __( 'O BTG não devolveu o código de autorização.', 'inky-pix-for-btg-pactual' ) );
			return;
		}

		$environment = $this->validate_state( $state );

		if ( is_wp_error( $environment ) ) {
			$this->redirect_back( 'error', $environment->get_error_message() );
			return;
		}

		$result = $this->exchange_code( $environment, $code );

		if ( is_wp_error( $result ) ) {
			$this->redirect_back( 'error', $result->get_error_message() );
			return;
		}

		delete_transient( 'inky_btg_share_link' );
		$this->redirect_back( 'success', __( 'Conta conectada com sucesso.', 'inky-pix-for-btg-pactual' ) );
	}

	/**
	 * Descreve o erro devolvido pelo servidor de autorização.
	 *
	 * @param WP_REST_Request $request Requisição recebida.
	 * @return string Mensagem em português.
	 */
	private function describe_callback_error( $request ) {
		$error             = (string) $request->get_param( 'error' );
		$error_description = (string) $request->get_param( 'error_description' );

		if ( '' !== $error_description ) {
			return $error_description;
		}

		switch ( $error ) {
			case 'access_denied':
				return __( 'A autorização foi recusada pelo usuário.', 'inky-pix-for-btg-pactual' );

			case 'invalid_client':
				return __( 'O Client ID ou o Client Secret estão incorretos.', 'inky-pix-for-btg-pactual' );

			case 'invalid_scope':
				return __( 'Um dos escopos solicitados não está autorizado para este aplicativo.', 'inky-pix-for-btg-pactual' );

			default:
				return sprintf(
					/* translators: %s: código de erro do OAuth */
					__( 'O BTG devolveu o erro %s durante a autorização.', 'inky-pix-for-btg-pactual' ),
					$error
				);
		}
	}

	/**
	 * Redireciona de volta para a tela de Ajustes com o resultado.
	 *
	 * @param string $status  `success` ou `error`.
	 * @param string $message Mensagem a exibir.
	 * @return void
	 */
	private function redirect_back( $status, $message ) {
		/*
		 * Quem autorizou pode ser a cliente, sem login no WordPress (link
		 * enviado pelo desenvolvedor). Mostra o resultado direto em vez de
		 * cair na tela de login do wp-admin.
		 */
		if ( ! wp_validate_auth_cookie( '', 'logged_in' ) ) {
			nocache_headers();
			wp_die(
				'<p style="font-size:16px">' . esc_html( $message ) . '</p>'
				. ( 'success' === $status ? '<p>' . esc_html__( 'Pode fechar esta janela.', 'inky-pix-for-btg-pactual' ) . '</p>' : '' ),
				esc_html__( 'PIX BTG Pactual', 'inky-pix-for-btg-pactual' ),
				array( 'response' => 200 )
			);
		}

		$url = add_query_arg(
			array(
				'page'         => Inky_BTG_Admin::PAGE_SLUG,
				'tab'          => 'connection',
				'oauth_status' => $status,
				'oauth_notice' => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Troca o código de autorização por tokens.
	 *
	 * Confirmado: `POST /oauth2/token` com `application/x-www-form-urlencoded`
	 * e `Authorization: Basic base64(client_id:client_secret)`.
	 *
	 * @param string $environment Ambiente.
	 * @param string $code        Código de autorização.
	 * @return true|WP_Error
	 */
	public function exchange_code( $environment, $code ) {
		$body = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => Inky_BTG_Settings::get_redirect_uri(),
		);

		return $this->request_token( $environment, $body, 'trocar código por token' );
	}

	/**
	 * Renova os tokens usando o refresh token.
	 *
	 * @param string $environment Ambiente.
	 * @return true|WP_Error
	 */
	public function refresh( $environment ) {
		global $wpdb;

		/*
		 * Trava entre processos. Cron, polling e checkout podem pedir renovação
		 * ao mesmo tempo; sem a trava, um deles pode gravar um refresh token
		 * que o BTG já invalidou, e aí só reconectando a conta.
		 * GET_LOCK é liberado sozinho se o processo morrer.
		 */
		$lock   = $wpdb->prefix . 'inky_btg_refresh_' . $environment;
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 15)', $lock ) );

		// Relê do banco: outro processo pode ter renovado enquanto esperávamos.
		wp_cache_delete( INKY_BTG_OPTION_TOKENS, 'options' );
		$data = Inky_BTG_Token_Store::get( $environment );

		if ( ! empty( $data['obtained_at'] ) && (int) $data['obtained_at'] > time() - MINUTE_IN_SECONDS && ! Inky_BTG_Token_Store::is_expired( $environment ) ) {
			$result = true;
		} elseif ( empty( $data['refresh_token'] ) ) {
			$result = new WP_Error(
				'inky_btg_missing_refresh_token',
				__( 'Não há refresh token gravado. Conecte a conta novamente.', 'inky-pix-for-btg-pactual' )
			);
		} else {
			$body = array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $data['refresh_token'],
			);

			$result = $this->request_token( $environment, $body, 'renovar token' );
		}

		if ( $locked ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		return $result;
	}

	/**
	 * Executa a requisição de token e grava o resultado.
	 *
	 * @param string               $environment Ambiente.
	 * @param array<string, mixed> $body        Corpo do formulário.
	 * @param string               $context     Contexto para o log.
	 * @return true|WP_Error
	 */
	private function request_token( $environment, $body, $context ) {
		$credentials = Inky_BTG_Settings::get_credentials( $environment );

		if ( empty( $credentials['client_id'] ) || empty( $credentials['client_secret'] ) ) {
			return new WP_Error(
				'inky_btg_missing_credentials',
				__( 'Client ID e Client Secret são obrigatórios para obter o token.', 'inky-pix-for-btg-pactual' )
			);
		}

		$url = Inky_BTG_API_Contract::get_auth_base_url( $environment )
			. Inky_BTG_API_Contract::build_path( 'token' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => Inky_BTG_API_Client::DEFAULT_TIMEOUT,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $credentials['client_id'] . ':' . $credentials['client_secret'] ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			Inky_BTG_Logger::error(
				sprintf(
					/* translators: 1: contexto, 2: mensagem de erro */
					__( 'Falha de rede ao %1$s: %2$s.', 'inky-pix-for-btg-pactual' ),
					$context,
					$response->get_error_message()
				),
				array( 'source' => 'oauth' )
			);

			return $response;
		}

		$status         = (int) wp_remote_retrieve_response_code( $response );
		$correlation_id = (string) wp_remote_retrieve_header( $response, 'x-correlation-id' );
		$decoded        = json_decode( wp_remote_retrieve_body( $response ), true );
		$decoded        = is_array( $decoded ) ? $decoded : array();

		if ( $status < 200 || $status >= 300 ) {
			$message = Inky_BTG_Error_Translator::translate( $status, $decoded, $correlation_id );

			Inky_BTG_Logger::error(
				sprintf(
					/* translators: 1: contexto, 2: código HTTP */
					__( 'Erro ao %1$s: HTTP %2$d.', 'inky-pix-for-btg-pactual' ),
					$context,
					$status
				),
				array(
					'source'         => 'oauth',
					'correlation_id' => $correlation_id,
					'body'           => $decoded,
				)
			);

			return new WP_Error( 'inky_btg_token_error', $message );
		}

		if ( empty( $decoded['access_token'] ) ) {
			return new WP_Error(
				'inky_btg_token_missing',
				__( 'O BTG respondeu sem o token de acesso.', 'inky-pix-for-btg-pactual' )
			);
		}

		$decoded['company_id'] = $this->extract_company_id_from_token( $decoded );

		// Se a renovação não devolver refresh token ou CNPJ, mantém os atuais para não forçar reconexão.
		if ( 'refresh_token' === $body['grant_type'] ) {
			if ( empty( $decoded['refresh_token'] ) ) {
				$decoded['refresh_token'] = $body['refresh_token'];
			}
			if ( '' === $decoded['company_id'] ) {
				$decoded['company_id'] = Inky_BTG_Token_Store::get_company_id( $environment );
			}
		}

		Inky_BTG_Token_Store::save( $environment, $decoded );

		Inky_BTG_Logger::info(
			sprintf(
				/* translators: %s: contexto */
				__( 'Token obtido com sucesso ao %s.', 'inky-pix-for-btg-pactual' ),
				$context
			),
			array(
				'source'     => 'oauth',
				'company_id' => $decoded['company_id'],
			)
		);

		return true;
	}

	/**
	 * Extrai o CNPJ da empresa a partir das claims do token.
	 *
	 * O `access_token` é um JWT. Lemos apenas o payload, sem validar a
	 * assinatura, porque a validação é responsabilidade do servidor do BTG.
	 *
	 * @param array<string, mixed> $token_data Dados devolvidos pelo BTG.
	 * @return string CNPJ encontrado, ou string vazia.
	 */
	private function extract_company_id_from_token( $token_data ) {
		if ( ! empty( $token_data['id_token'] ) ) {
			$claims = $this->decode_jwt_payload( (string) $token_data['id_token'] );
			$found  = Inky_BTG_API_Contract::extract_company_id( $claims );

			if ( '' !== $found ) {
				return $found;
			}
		}

		if ( ! empty( $token_data['access_token'] ) ) {
			$claims = $this->decode_jwt_payload( (string) $token_data['access_token'] );
			$found  = Inky_BTG_API_Contract::extract_company_id( $claims );

			if ( '' !== $found ) {
				return $found;
			}
		}

		return '';
	}

	/**
	 * Decodifica o payload de um JWT sem validar a assinatura.
	 *
	 * @param string $jwt Token no formato `header.payload.signature`.
	 * @return array<string, mixed> Claims decodificadas.
	 */
	private function decode_jwt_payload( $jwt ) {
		$parts = explode( '.', $jwt );

		if ( count( $parts ) < 2 ) {
			return array();
		}

		$payload = strtr( $parts[1], '-_', '+/' );
		$padding = strlen( $payload ) % 4;

		if ( $padding > 0 ) {
			$payload .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( $payload, true );

		if ( false === $decoded ) {
			return array();
		}

		$claims = json_decode( $decoded, true );

		return is_array( $claims ) ? $claims : array();
	}

	/**
	 * Renova os tokens de todos os ambientes que precisam.
	 *
	 * Executado pelo agendamento a cada 12 horas.
	 *
	 * @return void
	 */
	public function refresh_tokens() {
		foreach ( array( Inky_BTG_API_Contract::ENV_SANDBOX, Inky_BTG_API_Contract::ENV_PRODUCTION ) as $environment ) {
			if ( ! Inky_BTG_Token_Store::has_token( $environment ) ) {
				continue;
			}

			if ( ! Inky_BTG_Token_Store::can_refresh( $environment ) ) {
				Inky_BTG_Logger::warning(
					sprintf(
						/* translators: %s: ambiente */
						__( 'O refresh token do ambiente %s expirou. É preciso reconectar a conta.', 'inky-pix-for-btg-pactual' ),
						$environment
					),
					array( 'source' => 'oauth' )
				);

				continue;
			}

			if ( ! Inky_BTG_Token_Store::needs_proactive_refresh( $environment ) && ! Inky_BTG_Token_Store::is_expired( $environment ) ) {
				continue;
			}

			$this->refresh( $environment );
		}
	}

	/**
	 * Retorna um cliente HTTP já autenticado para o ambiente atual.
	 *
	 * Se o token estiver vencido e houver refresh token válido, renova antes
	 * de devolver o cliente.
	 *
	 * @param string $environment Ambiente desejado.
	 * @return Inky_BTG_API_Client|WP_Error
	 */
	public function get_authenticated_client( $environment = '' ) {
		if ( '' === $environment ) {
			$environment = Inky_BTG_Settings::get_environment();
		}

		if ( Inky_BTG_Token_Store::is_expired( $environment ) ) {
			if ( ! Inky_BTG_Token_Store::can_refresh( $environment ) ) {
				return new WP_Error(
					'inky_btg_not_connected',
					__( 'A conta do BTG Pactual não está conectada ou a autorização expirou. Reconecte em WooCommerce > BTG PIX.', 'inky-pix-for-btg-pactual' )
				);
			}

			$refreshed = $this->refresh( $environment );

			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}

		$data = Inky_BTG_Token_Store::get( $environment );

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error(
				'inky_btg_not_connected',
				__( 'A conta do BTG Pactual não está conectada. Conecte em WooCommerce > BTG PIX.', 'inky-pix-for-btg-pactual' )
			);
		}

		$client = new Inky_BTG_API_Client( $environment );
		$client->set_access_token( $data['access_token'] );

		return $client;
	}

	/**
	 * Desconecta a conta de um ambiente.
	 *
	 * @param string $environment Ambiente.
	 * @return void
	 */
	public function disconnect( $environment ) {
		Inky_BTG_Token_Store::clear( $environment );

		Inky_BTG_Logger::info(
			sprintf(
				/* translators: %s: ambiente */
				__( 'Conta do ambiente %s desconectada.', 'inky-pix-for-btg-pactual' ),
				$environment
			),
			array( 'source' => 'oauth' )
		);
	}
}
