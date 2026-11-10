<?php
/**
 * Cliente HTTP da API do BTG Pactual Empresas.
 *
 * Responsabilidade única: montar e executar requisições HTTP, cuidando de
 * URL base por ambiente, cabeçalhos, timeout, repetição em 429 e captura do
 * `x-correlation-id`. Nenhuma regra de negócio mora aqui.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executa chamadas HTTP contra a API e o servidor de autorização.
 */
class Inky_BTG_API_Client {

	/**
	 * Tempo limite padrão de cada requisição, em segundos.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Número máximo de tentativas em caso de 429 ou erro de rede.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Espera base entre tentativas, em segundos.
	 */
	const RETRY_BASE_DELAY = 1;

	/**
	 * Ambiente em uso.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Token de acesso já obtido, se houver.
	 *
	 * @var string
	 */
	private $access_token = '';

	/**
	 * Construtor.
	 *
	 * @param string $environment Ambiente desejado.
	 */
	public function __construct( $environment = '' ) {
		if ( '' === $environment ) {
			$environment = Inky_BTG_Settings::get_environment();
		}

		$this->environment = $environment;
	}

	/**
	 * Define o token de acesso usado nas requisições autenticadas.
	 *
	 * @param string $token Token de acesso.
	 * @return void
	 */
	public function set_access_token( $token ) {
		$this->access_token = (string) $token;
	}

	/**
	 * Retorna o ambiente em uso.
	 *
	 * @return string
	 */
	public function get_environment() {
		return $this->environment;
	}

	/**
	 * Retorna a URL base da API para o ambiente atual.
	 *
	 * @return string
	 */
	public function get_api_base_url() {
		return Inky_BTG_API_Contract::get_api_base_url( $this->environment );
	}

	/**
	 * Retorna a URL base do servidor de autorização para o ambiente atual.
	 *
	 * @return string
	 */
	public function get_auth_base_url() {
		return Inky_BTG_API_Contract::get_auth_base_url( $this->environment );
	}

	/**
	 * Executa uma requisição contra a API.
	 *
	 * @param string               $method   Método HTTP.
	 * @param string               $path     Caminho relativo à URL base da API.
	 * @param array<string, mixed> $body     Corpo da requisição, se houver.
	 * @param array<string, mixed> $args     Argumentos extras.
	 * @return array<string, mixed>|WP_Error Resposta normalizada ou erro.
	 */
	public function request( $method, $path, $body = array(), $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'base'          => 'api',
				'authenticated' => true,
				'timeout'       => self::DEFAULT_TIMEOUT,
				'headers'       => array(),
				'query'         => array(),
				'context'       => '',
				'idempotency_key' => '',
			)
		);

		$base = 'auth' === $args['base'] ? $this->get_auth_base_url() : $this->get_api_base_url();
		$url  = $base . $path;

		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args['query'] ), $url );
		}

		$headers = $this->build_headers( $args['headers'], $args['authenticated'] );
		if ( '' !== (string) $args['idempotency_key'] && Inky_BTG_API_Contract::has_idempotency_header() ) {
			$headers[ Inky_BTG_API_Contract::get_idempotency_header() ] = (string) $args['idempotency_key'];
		}

		$request_args = array(
			'method'  => strtoupper( $method ),
			'timeout' => (int) $args['timeout'],
			'headers' => $headers,
		);

		if ( ! empty( $body ) ) {
			$request_args['body'] = wp_json_encode( $body );
		}

		return $this->execute_with_retry( $url, $request_args, $args['context'] );
	}

	/**
	 * Monta os cabeçalhos da requisição.
	 *
	 * O cabeçalho de idempotência só é incluído se o nome tiver sido
	 * confirmado no contrato. Enquanto isso, nada é enviado.
	 *
	 * @param array<string, string> $extra         Cabeçalhos extras.
	 * @param bool                  $authenticated Se deve incluir o Bearer.
	 * @return array<string, string>
	 */
	private function build_headers( $extra, $authenticated ) {
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);

		if ( $authenticated && '' !== $this->access_token ) {
			$headers['Authorization'] = 'Bearer ' . $this->access_token;
		}

		if ( is_array( $extra ) ) {
			foreach ( $extra as $name => $value ) {
				$headers[ $name ] = $value;
			}
		}

		return $headers;
	}

	/**
	 * Executa a requisição com repetição em 429 e falhas de rede.
	 *
	 * @param string               $url          URL completa.
	 * @param array<string, mixed> $request_args Argumentos do wp_remote_request.
	 * @param string               $context      Contexto para o log.
	 * @return array<string, mixed>|WP_Error
	 */
	private function execute_with_retry( $url, $request_args, $context ) {
		$attempt = 0;
		$result  = null;
		$max_attempts = 'GET' === strtoupper( (string) $request_args['method'] ) ? self::MAX_ATTEMPTS : 1;

		while ( $attempt < $max_attempts ) {
			$attempt++;

			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				$result = $response;

				Inky_BTG_Logger::warning(
					sprintf(
						/* translators: 1: contexto, 2: mensagem de erro, 3: tentativa */
						__( 'Falha de rede em %1$s: %2$s (tentativa %3$d).', 'inky-pix-for-btg-pactual' ),
						$context ? $context : $url,
						$response->get_error_message(),
						$attempt
					),
					array( 'source' => 'api-client' )
				);

				if ( $attempt < $max_attempts ) {
					$this->sleep_before_retry( $attempt );
				}
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( 429 === $status && $attempt < $max_attempts ) {
				$delay = $this->get_retry_delay( $response, $attempt );

				Inky_BTG_Logger::warning(
					sprintf(
						/* translators: 1: contexto, 2: segundos, 3: tentativa */
						__( 'Limite de requisições atingido em %1$s. Aguardando %2$d segundos (tentativa %3$d).', 'inky-pix-for-btg-pactual' ),
						$context ? $context : $url,
						$delay,
						$attempt
					),
					array( 'source' => 'api-client' )
				);

				sleep( $delay );
				continue;
			}

			return $this->normalize_response( $response, $context );
		}

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_Error(
			'inky_btg_request_failed',
			__( 'Não foi possível concluir a requisição após várias tentativas.', 'inky-pix-for-btg-pactual' )
		);
	}

	/**
	 * Calcula a espera antes da próxima tentativa.
	 *
	 * Respeita o cabeçalho `Retry-After` quando presente.
	 *
	 * @param array<string, mixed> $response Resposta HTTP.
	 * @param int                  $attempt  Número da tentativa.
	 * @return int Segundos de espera.
	 */
	private function get_retry_delay( $response, $attempt ) {
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

		if ( '' !== $retry_after && is_numeric( $retry_after ) ) {
			return min( 30, max( 1, (int) $retry_after ) );
		}

		return min( 30, self::RETRY_BASE_DELAY * (int) pow( 2, $attempt - 1 ) );
	}

	/**
	 * Aguarda antes de repetir a requisição.
	 *
	 * @param int $attempt Número da tentativa.
	 * @return void
	 */
	private function sleep_before_retry( $attempt ) {
		$delay = min( 10, self::RETRY_BASE_DELAY * (int) pow( 2, $attempt - 1 ) );

		if ( $delay > 0 ) {
			sleep( $delay );
		}
	}

	/**
	 * Normaliza a resposta HTTP em um array previsível.
	 *
	 * @param array<string, mixed> $response Resposta HTTP.
	 * @param string               $context  Contexto para o log.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_response( $response, $context ) {
		$status         = (int) wp_remote_retrieve_response_code( $response );
		$raw_body       = wp_remote_retrieve_body( $response );
		$correlation_id = (string) wp_remote_retrieve_header( $response, 'x-correlation-id' );
		$location       = (string) wp_remote_retrieve_header( $response, 'location' );
		$decoded        = json_decode( $raw_body, true );

		$normalized = array(
			'status'         => $status,
			'body'           => is_array( $decoded ) ? $decoded : array(),
			'raw'            => $raw_body,
			'correlation_id' => $correlation_id,
			'location'       => $location,
		);

		if ( $status >= 200 && $status < 300 ) {
			return $normalized;
		}

		Inky_BTG_Logger::error(
			sprintf(
				/* translators: 1: contexto, 2: código HTTP, 3: identificador de correlação */
				__( 'Erro na chamada %1$s: HTTP %2$d. Correlação: %3$s.', 'inky-pix-for-btg-pactual' ),
				$context ? $context : 'API',
				$status,
				'' !== $correlation_id ? $correlation_id : __( 'não informada', 'inky-pix-for-btg-pactual' )
			),
			array(
				'source'         => 'api-client',
				'status'         => $status,
				'correlation_id' => $correlation_id,
				'body'           => $normalized['body'],
			)
		);

		return new WP_Error(
			'inky_btg_http_' . $status,
			Inky_BTG_Error_Translator::translate( $status, $normalized['body'], $correlation_id ),
			array(
				'status'         => $status,
				'correlation_id' => $correlation_id,
				'body'           => $normalized['body'],
			)
		);
	}

	/**
	 * Atalho para requisições GET.
	 *
	 * @param string               $path Caminho relativo.
	 * @param array<string, mixed> $args Argumentos extras.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( $path, $args = array() ) {
		return $this->request( 'GET', $path, array(), $args );
	}

	/**
	 * Atalho para requisições POST.
	 *
	 * @param string               $path Caminho relativo.
	 * @param array<string, mixed> $body Corpo da requisição.
	 * @param array<string, mixed> $args Argumentos extras.
	 * @return array<string, mixed>|WP_Error
	 */
	public function post( $path, $body = array(), $args = array() ) {
		return $this->request( 'POST', $path, $body, $args );
	}

	/**
	 * Atalho para requisições DELETE.
	 *
	 * @param string               $path Caminho relativo.
	 * @param array<string, mixed> $args Argumentos extras.
	 * @return array<string, mixed>|WP_Error
	 */
	public function delete( $path, $args = array() ) {
		return $this->request( 'DELETE', $path, array(), $args );
	}
}
