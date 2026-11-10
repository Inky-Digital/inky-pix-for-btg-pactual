<?php
/**
 * Recurso de cobranças instantâneas do Pix Cobrança.
 *
 * Cria e consulta cobranças. O corpo da criação é montado pelo contrato, que
 * é o único lugar onde os campos ainda não confirmados ficam isolados.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Operações sobre cobranças instantâneas.
 */
class Inky_BTG_Instant_Collections {

	/**
	 * Cliente HTTP.
	 *
	 * @var Inky_BTG_API_Client
	 */
	private $client;

	/**
	 * Recurso de locations, usado no fluxo alternativo.
	 *
	 * @var Inky_BTG_Locations
	 */
	private $locations;

	/**
	 * Construtor.
	 *
	 * @param Inky_BTG_API_Client $client    Cliente HTTP já autenticado.
	 * @param Inky_BTG_Locations  $locations Recurso de locations.
	 */
	public function __construct( $client, $locations ) {
		$this->client    = $client;
		$this->locations = $locations;
	}

	/**
	 * Cria uma cobrança.
	 *
	 * Se o contrato indicar que a location deve existir antes, ela é criada
	 * primeiro. Caso contrário, tentamos criar a cobrança direto e, se a API
	 * recusar por falta de location, criamos a location e tentamos de novo.
	 *
	 * @param string               $company_id CNPJ da empresa.
	 * @param array<string, mixed> $args       Dados da cobrança.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( $company_id, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'amount'       => 0,
				'display_text' => '',
				'location_id'  => '',
				'tags'         => array(),
				'description'  => '',
			)
		);

		if ( Inky_BTG_API_Contract::should_create_location_first() && '' === (string) $args['location_id'] ) {
			$location = $this->create_location( $company_id, $args );

			if ( is_wp_error( $location ) ) {
				return $location;
			}

			$args['location_id'] = $location;
		}

		$response = $this->send_create( $company_id, $args );

		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $this->looks_like_missing_location( $response ) || '' !== (string) $args['location_id'] ) {
			return $response;
		}

		/*
		 * A API recusou por falta de location. Criamos uma e repetimos uma
		 * única vez, para não entrar em laço.
		 */
		Inky_BTG_Logger::info(
			__( 'A API pediu uma location antes da cobrança. Criando o QR Code e repetindo a operação.', 'inky-pix-for-btg-pactual' ),
			array( 'source' => 'instant-collections' )
		);

		$location = $this->create_location( $company_id, $args );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$args['location_id'] = $location;

		return $this->send_create( $company_id, $args );
	}

	/**
	 * Envia a requisição de criação de cobrança.
	 *
	 * @param string               $company_id CNPJ da empresa.
	 * @param array<string, mixed> $args       Dados da cobrança.
	 * @return array<string, mixed>|WP_Error
	 */
	private function send_create( $company_id, $args ) {
		$path = Inky_BTG_API_Contract::build_path(
			'instant_collections',
			array( 'companyId' => $company_id )
		);

		if ( '' === $path ) {
			return new WP_Error(
				'inky_btg_missing_endpoint',
				__( 'O caminho de criação de cobrança não está definido no contrato.', 'inky-pix-for-btg-pactual' )
			);
		}

		$body = Inky_BTG_API_Contract::build_create_collection_body( $args );

		return $this->client->post(
			$path,
			$body,
			array(
				'context' => 'criar cobrança',
				'idempotency_key' => ! empty( $args['tags']['order'] ) ? hash( 'sha256', $company_id . ':' . $args['tags']['order'] ) : '',
			)
		);
	}

	/**
	 * Cria uma location e devolve o identificador.
	 *
	 * @param string               $company_id CNPJ da empresa.
	 * @param array<string, mixed> $args       Dados usados na descrição.
	 * @return string|WP_Error Identificador da location.
	 */
	private function create_location( $company_id, $args ) {
		$response = $this->locations->create(
			$company_id,
			array( 'description' => isset( $args['description'] ) ? $args['description'] : '' )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$location_id = Inky_BTG_Locations::extract_id( $response );

		if ( '' === $location_id ) {
			return new WP_Error(
				'inky_btg_location_id_missing',
				__( 'A API criou o QR Code, mas não devolveu o identificador.', 'inky-pix-for-btg-pactual' )
			);
		}

		return $location_id;
	}

	/**
	 * Verifica se o erro indica ausência de location.
	 *
	 * Só olhamos o texto devolvido pela API. Não inventamos códigos.
	 *
	 * @param WP_Error $error Erro retornado pelo cliente.
	 * @return bool
	 */
	private function looks_like_missing_location( $error ) {
		$data = $error->get_error_data();
		$body = is_array( $data ) && isset( $data['body'] ) ? $data['body'] : array();

		$haystack = strtolower( $error->get_error_message() );

		if ( is_array( $body ) ) {
			$haystack .= ' ' . strtolower( wp_json_encode( $body ) );
		}

		return false !== strpos( $haystack, 'location' )
			&& ( false !== strpos( $haystack, 'required' )
				|| false !== strpos( $haystack, 'obrigat' )
				|| false !== strpos( $haystack, 'not found' )
				|| false !== strpos( $haystack, 'missing' ) );
	}

	/**
	 * Consulta uma cobrança específica.
	 *
	 * O caminho é o mesmo da listagem, acrescido do identificador. A
	 * documentação confirma que a cobrança é acessível pelo seu `txId`.
	 *
	 * @param string $company_id CNPJ da empresa.
	 * @param string $txid       Identificador da cobrança.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( $company_id, $txid ) {
		$base = Inky_BTG_API_Contract::build_path(
			'instant_collections',
			array( 'companyId' => $company_id )
		);

		if ( '' === $base ) {
			return new WP_Error(
				'inky_btg_missing_endpoint',
				__( 'O caminho de consulta de cobranças não está definido no contrato.', 'inky-pix-for-btg-pactual' )
			);
		}

		$path = $base . '/' . rawurlencode( (string) $txid );

		$result = $this->client->get(
			$path,
			array( 'context' => 'consultar cobrança' )
		);
		if ( ! is_wp_error( $result ) || ! in_array( $result->get_error_code(), array( 'inky_btg_http_404', 'inky_btg_http_405' ), true ) ) {
			return $result;
		}
		$list = $this->list_all( $company_id );
		if ( is_wp_error( $list ) ) {
			return $result;
		}
		$found = self::find_by_txid( isset( $list['body'] ) ? $list['body'] : array(), (string) $txid );
		if ( ! $found ) {
			return $result;
		}
		$list['body'] = $found;
		return $list;
	}

	/** Procura a cobrança em uma resposta de listagem. */
	private static function find_by_txid( $node, $txid, $depth = 0 ) {
		if ( ! is_array( $node ) || $depth > 4 ) {
			return array();
		}
		if ( isset( $node['txId'] ) && (string) $node['txId'] === $txid ) {
			return $node;
		}
		foreach ( $node as $child ) {
			$found = self::find_by_txid( $child, $txid, $depth + 1 );
			if ( $found ) {
				return $found;
			}
		}
		return array();
	}

	/**
	 * Lista as cobranças da empresa.
	 *
	 * @param string               $company_id CNPJ da empresa.
	 * @param array<string, mixed> $query      Filtros de consulta.
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_all( $company_id, $query = array() ) {
		$path = Inky_BTG_API_Contract::build_path(
			'instant_collections',
			array( 'companyId' => $company_id )
		);

		if ( '' === $path ) {
			return new WP_Error(
				'inky_btg_missing_endpoint',
				__( 'O caminho de listagem de cobranças não está definido no contrato.', 'inky-pix-for-btg-pactual' )
			);
		}

		return $this->client->get(
			$path,
			array(
				'query'   => $query,
				'context' => 'listar cobranças',
			)
		);
	}

	/**
	 * Extrai o identificador da cobrança de uma resposta de criação.
	 *
	 * @param array<string, mixed> $response Resposta normalizada do cliente.
	 * @return string Identificador encontrado, ou string vazia.
	 */
	public static function extract_id( $response ) {
		if ( ! is_array( $response ) ) {
			return '';
		}

		$candidates = array( 'txId', 'txid' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $response['body'][ $key ] ) && is_scalar( $response['body'][ $key ] ) ) {
				return (string) $response['body'][ $key ];
			}
		}

		return '';
	}

	/**
	 * Extrai o código copia e cola (EMV) de uma resposta.
	 *
	 * A documentação cita o recurso `emv`. Lemos apenas os nomes de campo
	 * que aparecem na documentação acessível.
	 *
	 * @param array<string, mixed> $response Resposta normalizada do cliente.
	 * @return string Código encontrado, ou string vazia.
	 */
	public static function extract_emv( $response ) {
		if ( ! is_array( $response ) || empty( $response['body'] ) || ! is_array( $response['body'] ) ) {
			return '';
		}

		$body       = $response['body'];
		$candidates = array( 'emv', 'pixCopyPaste', 'copyPaste', 'qrCode', 'payload' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
				return (string) $body[ $key ];
			}
		}

		if ( ! empty( $body['emv'] ) && is_array( $body['emv'] ) ) {
			foreach ( array( 'payload', 'code', 'value' ) as $key ) {
				if ( ! empty( $body['emv'][ $key ] ) && is_scalar( $body['emv'][ $key ] ) ) {
					return (string) $body['emv'][ $key ];
				}
			}
		}
		if ( ! empty( $body['location']['emv'] ) && is_scalar( $body['location']['emv'] ) ) {
			return (string) $body['location']['emv'];
		}

		return '';
	}

	/**
	 * Extrai a imagem do QR Code de uma resposta, quando houver.
	 *
	 * @param array<string, mixed> $response Resposta normalizada do cliente.
	 * @return string Conteúdo da imagem ou URL, ou string vazia.
	 */
	public static function extract_qr_image( $response ) {
		if ( ! is_array( $response ) || empty( $response['body'] ) || ! is_array( $response['body'] ) ) {
			return '';
		}

		$body       = $response['body'];
		$candidates = array( 'qrCodeImage', 'image', 'base64Image' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
				return (string) $body[ $key ];
			}
		}
		if ( ! empty( $body['location']['url'] ) && is_string( $body['location']['url'] ) ) {
			return $body['location']['url'];
		}

		return '';
	}

	/**
	 * Extrai o status de uma cobrança.
	 *
	 * @param array<string, mixed> $response Resposta normalizada do cliente.
	 * @return string Status em maiúsculas, ou string vazia.
	 */
	public static function extract_status( $response ) {
		if ( ! is_array( $response ) || empty( $response['body'] ) || ! is_array( $response['body'] ) ) {
			return '';
		}

		if ( ! empty( $response['body']['status'] ) && is_scalar( $response['body']['status'] ) ) {
			return strtoupper( (string) $response['body']['status'] );
		}

		return '';
	}
}
