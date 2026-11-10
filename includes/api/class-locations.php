<?php
/**
 * Recurso de locations (QR Codes) do Pix Cobrança.
 *
 * Uma location representa um QR Code. Em cobrança dinâmica, a location é
 * criada e depois vinculada a uma cobrança. O caminho e o método de
 * desvínculo vêm do contrato, nunca escritos aqui.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Operações sobre locations.
 */
class Inky_BTG_Locations {

	/**
	 * Cliente HTTP.
	 *
	 * @var Inky_BTG_API_Client
	 */
	private $client;

	/**
	 * Construtor.
	 *
	 * @param Inky_BTG_API_Client $client Cliente HTTP já autenticado.
	 */
	public function __construct( $client ) {
		$this->client = $client;
	}

	/**
	 * Cria uma location (QR Code).
	 *
	 * @param string               $company_id CNPJ da empresa.
	 * @param array<string, mixed> $args       Dados da location.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( $company_id, $args = array() ) {
		$path = Inky_BTG_API_Contract::build_path(
			'locations',
			array( 'companyId' => $company_id )
		);

		if ( '' === $path ) {
			return new WP_Error(
				'inky_btg_missing_endpoint',
				__( 'O caminho de criação de QR Code não está definido no contrato.', 'inky-btg' )
			);
		}

		$body = Inky_BTG_API_Contract::build_create_location_body( $args );

		return $this->client->post(
			$path,
			$body,
			array( 'context' => 'criar location' )
		);
	}

	/**
	 * Lista as locations da empresa.
	 *
	 * @param string               $company_id CNPJ da empresa.
	 * @param array<string, mixed> $query      Filtros de consulta.
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_all( $company_id, $query = array() ) {
		$path = Inky_BTG_API_Contract::build_path(
			'locations',
			array( 'companyId' => $company_id )
		);

		if ( '' === $path ) {
			return new WP_Error(
				'inky_btg_missing_endpoint',
				__( 'O caminho de listagem de QR Codes não está definido no contrato.', 'inky-btg' )
			);
		}

		return $this->client->get(
			$path,
			array(
				'query'   => $query,
				'context' => 'listar locations',
			)
		);
	}

	/**
	 * Desvincula uma location de uma cobrança.
	 *
	 * O método HTTP é lido do contrato. Se ainda não tiver sido confirmado,
	 * a operação é recusada em vez de arriscar um verbo errado.
	 *
	 * @param string $company_id  CNPJ da empresa.
	 * @param string $location_id Identificador da location.
	 * @return array<string, mixed>|WP_Error
	 */
	public function unlink( $company_id, $location_id ) {
		$method = Inky_BTG_API_Contract::get_unlink_location_method();

		if ( '' === $method ) {
			return new WP_Error(
				'inky_btg_unlink_method_unknown',
				__( 'O método de desvincular QR Code ainda não foi confirmado na documentação.', 'inky-btg' )
			);
		}

		$path = Inky_BTG_API_Contract::build_path(
			'unlink_location',
			array(
				'companyId'  => $company_id,
				'locationId' => $location_id,
			)
		);

		if ( '' === $path ) {
			return new WP_Error(
				'inky_btg_missing_endpoint',
				__( 'O caminho de desvincular QR Code não está definido no contrato.', 'inky-btg' )
			);
		}

		return $this->client->request(
			$method,
			$path,
			array(),
			array( 'context' => 'desvincular location' )
		);
	}

	/**
	 * Extrai o identificador da location de uma resposta de criação.
	 *
	 * A API devolve o recurso criado no corpo e, às vezes, também no
	 * cabeçalho `Location`. Tentamos os dois, sem inventar campos.
	 *
	 * @param array<string, mixed> $response Resposta normalizada do cliente.
	 * @return string Identificador encontrado, ou string vazia.
	 */
	public static function extract_id( $response ) {
		if ( ! is_array( $response ) ) {
			return '';
		}

		if ( ! empty( $response['body']['id'] ) && is_scalar( $response['body']['id'] ) ) {
			return (string) $response['body']['id'];
		}

		if ( ! empty( $response['location'] ) && is_string( $response['location'] ) ) {
			$segments = explode( '/', rtrim( $response['location'], '/' ) );

			return (string) end( $segments );
		}

		return '';
	}
}
