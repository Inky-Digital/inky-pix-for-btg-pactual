<?php
/**
 * Contrato da API do BTG Pactual Empresas.
 *
 * Os detalhes do payload de cobrança devem ser conferidos em sandbox e na
 * Postman Collection do BTG antes da entrada em produção.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ponto único de verdade sobre o formato dos dados trocados com a API.
 */
final class Inky_BTG_API_Contract {

	/**
	 * Ambiente de sandbox.
	 */
	const ENV_SANDBOX = 'sandbox';

	/**
	 * Ambiente de produção.
	 */
	const ENV_PRODUCTION = 'production';

	/**
	 * Escopo obrigatório para todos os serviços de banking.
	 */
	const SCOPE_OPENID = 'openid';

	/**
	 * Escopo do Pix Cobrança, com permissão de escrita.
	 */
	const SCOPE_PIX_CASH_IN = 'empresas.btgpactual.com/pix-cash-in';

	/**
	 * Escopo do Pix Cobrança, somente leitura.
	 */
	const SCOPE_PIX_CASH_IN_READONLY = 'empresas.btgpactual.com/pix-cash-in.readonly';

	/**
	 * CNPJ da empresa usada no sandbox.
	 *
	 * Confirmado na documentação: o sandbox sempre responde por esta empresa.
	 */
	const SANDBOX_COMPANY_TAX_ID = '30306294000145';

	/**
	 * Tempo de vida do token de acesso, em segundos.
	 *
	 * Confirmado: `expires_in: 86400`.
	 */
	const ACCESS_TOKEN_LIFETIME = 86400;

	/**
	 * Tempo de vida do refresh token, em dias.
	 *
	 * Confirmado: 10 dias, com o prazo reiniciado a cada uso.
	 */
	const REFRESH_TOKEN_LIFETIME_DAYS = 10;

	/**
	 * Antecedência da renovação proativa, em segundos.
	 *
	 * O briefing pede renovação a cada 12 horas, o que equivale a metade da
	 * vida do token de 24 horas.
	 */
	const PROACTIVE_REFRESH_INTERVAL = 43200;

	/**
	 * Margem de segurança antes do vencimento, em segundos.
	 *
	 * Se faltar menos que isso para o token expirar, ele é renovado antes de
	 * ser usado.
	 */
	const TOKEN_EXPIRY_MARGIN = 300;

	/**
	 * Prazo máximo de resposta exigido pelo webhook, em segundos.
	 *
	 * Confirmado: 10 segundos.
	 */
	const WEBHOOK_RESPONSE_TIMEOUT = 10;

	/**
	 * Número de retentativas do webhook.
	 *
	 * Confirmado: mais 3 tentativas.
	 */
	const WEBHOOK_MAX_RETRIES = 3;

	/**
	 * Janela total das retentativas do webhook, em segundos.
	 *
	 * Confirmado: 10 minutos.
	 */
	const WEBHOOK_RETRY_WINDOW = 600;

	/**
	 * Evento de pagamento confirmado.
	 */
	const EVENT_PAID = 'instant-collection.paid';

	/**
	 * Evento de cobrança desvinculada.
	 */
	const EVENT_UNLINKED = 'instant-collection.unlinked';

	/**
	 * Status de cobrança paga.
	 */
	const STATUS_PAID = 'PAID';

	/**
	 * Status de cobrança desvinculada.
	 */
	const STATUS_UNLINKED = 'UNLINKED';

	/**
	 * Status de cobrança ativa, aguardando pagamento.
	 */
	const STATUS_ACTIVE = 'ACTIVE';

	/**
	 * Unidade do campo `amount.value`.
	 *
	 * O campo de criação amount.original usa valor decimal em reais.
	 */
	const AMOUNT_UNIT_DEFAULT = 'reais';

	/**
	 * Nome do cabeçalho de idempotência.
	 *
	 * PENDENTE 2 — A documentação menciona o erro 409 causado pelo "uso da
	 * mesma `idempotent` key", mas nunca nomeia o cabeçalho.
	 *
	 * Valor padrão adotado: string vazia, ou seja, NENHUM cabeçalho é enviado.
	 * Isto é intencional: enviar um nome inventado poderia causar rejeição da
	 * requisição ou, pior, criar uma cobrança duplicada por falta de proteção
	 * real.
	 *
	 * COMO CORRIGIR: definir o nome confirmado, ou usar o filtro
	 * `inky_btg_idempotency_header`.
	 */
	const IDEMPOTENCY_HEADER_DEFAULT = '';

	/**
	 * Método HTTP do endpoint de desvincular location.
	 *
	 * CONFIRMADO na documentação acessível: a página "Desvincular QR Code da
	 * cobrança" informa `DELETE` e o caminho
	 * `/companies/:companyId/pix-cash-in/locations/:locationId`.
	 *
	 * O briefing marcava este método como indefinido porque a página de
	 * referência era uma casca JavaScript. O texto extraído da documentação,
	 * porém, confirma o verbo DELETE.
	 *
	 * COMO AJUSTAR: usar o filtro `inky_btg_unlink_location_method`.
	 */
	const UNLINK_LOCATION_METHOD_DEFAULT = 'DELETE';

	/**
	 * Retorna a URL base da API para um ambiente.
	 *
	 * Confirmado na documentação de ambientes.
	 *
	 * @param string $environment Ambiente desejado.
	 * @return string URL base sem barra final.
	 */
	public static function get_api_base_url( $environment ) {
		if ( self::ENV_PRODUCTION === $environment ) {
			return 'https://api.empresas.btgpactual.com';
		}

		return 'https://api.sandbox.empresas.btgpactual.com';
	}

	/**
	 * Retorna a URL base do servidor de autorização para um ambiente.
	 *
	 * Confirmado na documentação de ambientes.
	 *
	 * @param string $environment Ambiente desejado.
	 * @return string URL base sem barra final.
	 */
	public static function get_auth_base_url( $environment ) {
		if ( self::ENV_PRODUCTION === $environment ) {
			return 'https://id.btgpactual.com';
		}

		return 'https://id.sandbox.btgpactual.com';
	}

	/**
	 * Retorna os caminhos dos endpoints, relativos à URL base da API.
	 *
	 * CONFIRMADO na documentação: todos os recursos de Pix Cobrança ficam sob
	 * `/companies/:companyId/pix-cash-in`. O `companyId` é o CNPJ da empresa
	 * e vem da claim do token, não de um valor fixo.
	 *
	 * Os caminhos de autorização e token pertencem ao BTG Id, cuja URL base é
	 * diferente da URL base da API.
	 *
	 * @return array<string, string>
	 */
	public static function get_endpoints() {
		return array(
			'authorize'           => '/oauth2/authorize',
			'token'               => '/oauth2/token',
			'locations'           => '/v1/companies/{companyId}/pix-cash-in/locations',
			'instant_collections' => '/v1/companies/{companyId}/pix-cash-in/instant-collections',
			'unlink_location'     => '/v1/companies/{companyId}/pix-cash-in/locations/{locationId}',
		);
	}

	/**
	 * Monta o caminho de um endpoint substituindo os marcadores.
	 *
	 * @param string                $key    Chave do endpoint em `get_endpoints()`.
	 * @param array<string, string> $params Valores dos marcadores.
	 * @return string Caminho pronto, ou string vazia se a chave não existir.
	 */
	public static function build_path( $key, $params = array() ) {
		$endpoints = self::get_endpoints();

		if ( ! isset( $endpoints[ $key ] ) ) {
			return '';
		}

		$path = $endpoints[ $key ];

		foreach ( $params as $name => $value ) {
			$path = str_replace( '{' . $name . '}', rawurlencode( (string) $value ), $path );
		}

		return $path;
	}

	/**
	 * Retorna os escopos solicitados na autorização.
	 *
	 * Confirmado: `openid` é obrigatório e o escopo do Pix Cobrança concede
	 * criação, consulta e webhooks.
	 *
	 * @return string Escopos separados por espaço.
	 */
	public static function get_scopes() {
		$scopes = array(
			self::SCOPE_OPENID,
			self::SCOPE_PIX_CASH_IN,
		);

		/**
		 * Permite ajustar os escopos solicitados.
		 *
		 * @param array<int, string> $scopes Lista de escopos.
		 */
		$scopes = apply_filters( 'inky_btg_scopes', $scopes );

		return implode( ' ', array_unique( array_filter( $scopes ) ) );
	}

	/**
	 * Retorna a unidade de valor em uso.
	 *
	 * @return string `centavos` ou `reais`.
	 */
	public static function get_amount_unit() {
		return 'reais';
	}

	/**
	 * Converte um valor do WooCommerce para o formato da API.
	 *
	 * O WooCommerce sempre trabalha com o valor em reais, como string decimal
	 * com duas casas. A conversão depende da unidade confirmada.
	 *
	 * @param float|string $amount Valor em reais.
	 * @return int|float Valor no formato esperado pela API.
	 */
	public static function to_api_amount( $amount ) {
		$amount = (float) $amount;

		if ( 'reais' === self::get_amount_unit() ) {
			return round( $amount, 2 );
		}

		return (int) round( $amount * 100 );
	}

	/**
	 * Converte um valor recebido da API para reais.
	 *
	 * @param int|float|string $amount Valor recebido da API.
	 * @return float Valor em reais.
	 */
	public static function from_api_amount( $amount ) {
		$amount = (float) $amount;

		if ( 'reais' === self::get_amount_unit() ) {
			return round( $amount, 2 );
		}

		return round( $amount / 100, 2 );
	}

	/**
	 * Retorna o nome do cabeçalho de idempotência.
	 *
	 * @return string Nome do cabeçalho, ou string vazia se ainda não confirmado.
	 */
	public static function get_idempotency_header() {
		$header = self::IDEMPOTENCY_HEADER_DEFAULT;

		/**
		 * Permite definir o cabeçalho de idempotência sem editar o código.
		 *
		 * @param string $header Nome do cabeçalho.
		 */
		return (string) apply_filters( 'inky_btg_idempotency_header', $header );
	}

	/**
	 * Verifica se o cabeçalho de idempotência já foi confirmado.
	 *
	 * @return bool
	 */
	public static function has_idempotency_header() {
		return '' !== self::get_idempotency_header();
	}

	/**
	 * Retorna o método HTTP do endpoint de desvincular location.
	 *
	 * @return string Método HTTP, ou string vazia se ainda não confirmado.
	 */
	public static function get_unlink_location_method() {
		$method = self::UNLINK_LOCATION_METHOD_DEFAULT;

		/**
		 * Permite definir o método HTTP sem editar o código.
		 *
		 * @param string $method Método HTTP.
		 */
		$method = strtoupper( (string) apply_filters( 'inky_btg_unlink_location_method', $method ) );

		return $method;
	}

	/**
	 * Indica se é preciso criar uma location antes de criar a cobrança.
	 *
	 * PENDENTE 4 — A documentação não deixa claro se o `POST
	 * /instant-collections` cria a location implicitamente ou se é preciso
	 * chamar `POST /locations` antes.
	 *
	 * Valor padrão: criar a location primeiro e enviar locationId.
	 *
	 * COMO CORRIGIR: retornar `true` para sempre criar a location antes, ou
	 * usar o filtro `inky_btg_create_location_first`.
	 *
	 * @return bool
	 */
	public static function should_create_location_first() {
		$create_first = true;

		/**
		 * Permite fixar a ordem de criação sem editar o código.
		 *
		 * @param bool $create_first Verdadeiro para criar a location antes.
		 */
		return (bool) apply_filters( 'inky_btg_create_location_first', $create_first );
	}

	/**
	 * Monta o corpo da requisição de criação de cobrança.
	 *
	 * PENDENTE 3 — O schema exato do `POST /instant-collections` não está
	 * disponível, pois a página de referência é uma casca JavaScript.
	 *
	 * O método envia os campos de criação observados nos exemplos de integração:
	 * pixKey, locationId, expiresIn, amount.original, displayText e tags.
	 *
	 * COMO CORRIGIR: ajustar o array retornado, ou usar o filtro
	 * `inky_btg_create_collection_body`.
	 *
	 * @param array<string, mixed> $args Dados da cobrança.
	 * @return array<string, mixed> Corpo da requisição.
	 */
	public static function build_create_collection_body( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'amount'       => 0,
				'display_text' => '',
				'location_id'  => '',
				'pix_key'      => '',
				'expires_in'   => 3600,
				'tags'         => array(),
			)
		);

		$body = array(
			'pixKey' => (string) $args['pix_key'],
			'expiresIn' => (int) $args['expires_in'],
			'amount' => array(
				'original'                 => self::to_api_amount( $args['amount'] ),
				'allowCustomerChangeValue' => false,
			),
		);

		if ( '' !== (string) $args['display_text'] ) {
			$body['displayText'] = (string) $args['display_text'];
		}

		if ( '' !== (string) $args['location_id'] ) {
			$body['locationId'] = (string) $args['location_id'];
		}

		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$body['tags'] = $args['tags'];
		}

		/**
		 * Permite ajustar o corpo da criação de cobrança.
		 *
		 * @param array<string, mixed> $body Corpo montado.
		 * @param array<string, mixed> $args Dados originais.
		 */
		return apply_filters( 'inky_btg_create_collection_body', $body, $args );
	}

	/**
	 * Monta o corpo da requisição de criação de location.
	 *
	 * A documentação confirma que a location representa um QR Code e que o
	 * tipo usado em cobrança dinâmica é `cob`, conforme o exemplo do webhook.
	 *
	 * @param array<string, mixed> $args Dados da location.
	 * @return array<string, mixed> Corpo da requisição.
	 */
	public static function build_create_location_body( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'        => 'cob',
				'description' => '',
			)
		);

		$body = array(
			'type' => (string) $args['type'],
		);

		if ( '' !== (string) $args['description'] ) {
			$body['description'] = (string) $args['description'];
		}

		/**
		 * Permite ajustar o corpo da criação de location.
		 *
		 * @param array<string, mixed> $body Corpo montado.
		 * @param array<string, mixed> $args Dados originais.
		 */
		return apply_filters( 'inky_btg_create_location_body', $body, $args );
	}

	/**
	 * Monta as tags enviadas na cobrança.
	 *
	 * A documentação confirma que as tags são pares `chave:valor` e que
	 * servem para facilitar a gestão. O briefing pede que elas sejam usadas
	 * para reconciliar o pedido no webhook.
	 *
	 * @param int $order_id Identificador do pedido.
	 * @return array<string, string> Tags no formato chave:valor.
	 */
	public static function build_tags( $order_id ) {
		$order_id = (int) $order_id;

		$tags = array(
			'order' => (string) $order_id,
		);

		/**
		 * Permite adicionar ou remover tags.
		 *
		 * @param array<string, string> $tags     Tags montadas.
		 * @param int                   $order_id Identificador do pedido.
		 */
		return apply_filters( 'inky_btg_order_tags', $tags, $order_id );
	}

	/**
	 * Extrai o identificador da empresa a partir das claims do token.
	 *
	 * Confirmado: a claim tem o formato
	 * `"empresas.btgpactual.com/[recurso]":"CNPJ"`. A ausência da claim ou do
	 * CNPJ é a principal causa do erro 403.
	 *
	 * @param array<string, mixed> $claims Claims decodificadas do token.
	 * @return string CNPJ encontrado, ou string vazia.
	 */
	public static function extract_company_id( $claims ) {
		if ( ! is_array( $claims ) ) {
			return '';
		}

		$candidates = array(
			self::SCOPE_PIX_CASH_IN,
			self::SCOPE_PIX_CASH_IN_READONLY,
		);

		foreach ( $candidates as $claim_name ) {
			if ( ! empty( $claims[ $claim_name ] ) && is_scalar( $claims[ $claim_name ] ) ) {
				return (string) $claims[ $claim_name ];
			}
		}

		/*
		 * Alguns servidores devolvem a claim com o prefixo completo. Fazemos
		 * uma varredura pelo sufixo para cobrir essa variação.
		 */
		foreach ( $claims as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			if ( false !== strpos( $key, 'pix-cash-in' ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Retorna a lista de pendências ainda abertas.
	 *
	 * Usada pela tela de Ajustes e pelo README para avisar o lojista
	 * de que alguns comportamentos estão em modo presumido.
	 *
	 * @return array<int, array<string, mixed>> Pendências com descrição e estado.
	 */
	public static function get_open_questions() {
		return array(
			array(
				'id'      => 'amount_unit',
				'title'   => __( 'Unidade do valor da cobrança', 'inky-pix-for-btg-pactual' ),
				'detail'  => __( 'A criação usa amount.original em reais, com duas casas decimais.', 'inky-pix-for-btg-pactual' ),
				'resolved' => true,
				'action'  => __( 'Confira o valor em uma cobrança de teste no sandbox.', 'inky-pix-for-btg-pactual' ),
			),
			array(
				'id'      => 'idempotency_header',
				'title'   => __( 'Cabeçalho de idempotência', 'inky-pix-for-btg-pactual' ),
				'detail'  => __( 'A documentação cita o erro 409 por reuso da chave de idempotência, mas não informa o nome do cabeçalho. Nenhum cabeçalho está sendo enviado.', 'inky-pix-for-btg-pactual' ),
				'resolved' => self::has_idempotency_header(),
				'action'  => __( 'Informe o nome exato do cabeçalho para evitar cobranças duplicadas.', 'inky-pix-for-btg-pactual' ),
			),
			array(
				'id'      => 'create_collection_body',
				'title'   => __( 'Corpo da criação de cobrança', 'inky-pix-for-btg-pactual' ),
				'detail'  => __( 'O schema completo do POST /instant-collections não está disponível publicamente. O plugin envia pixKey, locationId, expiresIn, amount.original e os dados de identificação.', 'inky-pix-for-btg-pactual' ),
				'resolved' => false,
				'action'  => __( 'Confirme os campos obrigatórios na Postman Collection.', 'inky-pix-for-btg-pactual' ),
			),
			array(
				'id'      => 'location_order',
				'title'   => __( 'Ordem entre location e cobrança', 'inky-pix-for-btg-pactual' ),
				'detail'  => __( 'O plugin cria uma location antes de criar a cobrança e envia seu locationId.', 'inky-pix-for-btg-pactual' ),
				'resolved' => false,
				'action'  => __( 'Confirme a sequência correta na Postman Collection.', 'inky-pix-for-btg-pactual' ),
			),
			array(
				'id'      => 'unlink_method',
				'title'   => __( 'Método HTTP de desvincular location', 'inky-pix-for-btg-pactual' ),
				'detail'  => __( 'Confirmado na documentação: DELETE em /companies/:companyId/pix-cash-in/locations/:locationId.', 'inky-pix-for-btg-pactual' ),
				'resolved' => '' !== self::get_unlink_location_method(),
				'action'  => __( 'Nenhuma ação necessária. Ajustável pelo filtro inky_btg_unlink_location_method.', 'inky-pix-for-btg-pactual' ),
			),
			array(
				'id'      => 'tags_on_paid',
				'title'   => __( 'Tags no evento de pagamento', 'inky-pix-for-btg-pactual' ),
				'detail'  => __( 'O exemplo do evento de pagamento não inclui tags; apenas o de desvínculo inclui. O plugin usa as tags quando presentes e recorre ao txId quando não estão.', 'inky-pix-for-btg-pactual' ),
				'resolved' => false,
				'action'  => __( 'Confirme se o evento instant-collection.paid também envia tags.', 'inky-pix-for-btg-pactual' ),
			),
		);
	}

	/**
	 * Conta quantas pendências ainda estão abertas.
	 *
	 * @return int
	 */
	public static function count_open_questions() {
		$count = 0;

		foreach ( self::get_open_questions() as $question ) {
			if ( empty( $question['resolved'] ) ) {
				$count++;
			}
		}

		return $count;
	}
}
