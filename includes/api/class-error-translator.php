<?php
/**
 * Tradutor de erros HTTP da API do BTG Pactual Empresas.
 *
 * Converte códigos HTTP e corpos de erro em mensagens em português, sempre
 * anexando o `x-correlation-id` quando disponível, porque é esse valor que o
 * suporte do BTG pede para investigar um chamado.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Traduz respostas de erro da API para mensagens legíveis.
 */
final class Inky_BTG_Error_Translator {

	/**
	 * Traduz um código HTTP em uma mensagem para o lojista.
	 *
	 * @param int                  $status         Código HTTP.
	 * @param array<string, mixed> $body           Corpo da resposta, se houver.
	 * @param string               $correlation_id Identificador de correlação.
	 * @return string Mensagem pronta para exibição.
	 */
	public static function translate( $status, $body = array(), $correlation_id = '' ) {
		$status  = (int) $status;
		$message = self::get_message( $status, $body );

		$detail = self::extract_detail( $body );

		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		if ( '' !== $correlation_id ) {
			$message .= ' ' . sprintf(
				/* translators: %s: identificador de correlação */
				__( 'Código de correlação: %s.', 'inky-pix-for-btg-pactual' ),
				$correlation_id
			);
		}

		return $message;
	}

	/**
	 * Retorna a mensagem base de cada código HTTP.
	 *
	 * @param int                  $status Código HTTP.
	 * @param array<string, mixed> $body   Corpo da resposta.
	 * @return string
	 */
	private static function get_message( $status, $body ) {
		switch ( $status ) {
			case 400:
				return __( 'A requisição foi recusada por dados inválidos. Revise os dados do pedido e tente novamente.', 'inky-pix-for-btg-pactual' );

			case 401:
				return __( 'A autenticação falhou. Reconecte a conta do BTG Pactual em WooCommerce > BTG PIX.', 'inky-pix-for-btg-pactual' );

			case 403:
				return __( 'A conta conectada não tem permissão para esta operação. Em produção, quem clica em Conectar precisa ter perfil Operador ou superior na conta PJ (perfil só de desenvolvedor é recusado). Reconecte com esse usuário.', 'inky-pix-for-btg-pactual' );

			case 404:
				return __( 'O recurso solicitado não existe na API. Verifique se o ambiente selecionado corresponde às credenciais usadas.', 'inky-pix-for-btg-pactual' );

			case 409:
				return __( 'A requisição entrou em conflito com uma anterior. Isso costuma indicar reenvio da mesma cobrança.', 'inky-pix-for-btg-pactual' );

			case 429:
				return __( 'Muitas requisições em pouco tempo. O plugin já tentou novamente; aguarde alguns instantes.', 'inky-pix-for-btg-pactual' );

			case 500:
			case 502:
			case 503:
			case 504:
				return __( 'O BTG Pactual está com instabilidade no momento. O pagamento continua pendente e será verificado automaticamente.', 'inky-pix-for-btg-pactual' );

			default:
				if ( $status >= 500 ) {
					return __( 'Erro no servidor do BTG Pactual. O pagamento continua pendente e será verificado automaticamente.', 'inky-pix-for-btg-pactual' );
				}

				if ( $status >= 400 ) {
					return __( 'A API recusou a requisição. Consulte o log de diagnóstico para mais detalhes.', 'inky-pix-for-btg-pactual' );
				}

				return __( 'Não foi possível concluir a operação com o BTG Pactual.', 'inky-pix-for-btg-pactual' );
		}
	}

	/**
	 * Extrai uma mensagem de detalhe do corpo da resposta.
	 *
	 * A API pode devolver `message`, `error`, `error_description` ou uma
	 * lista de erros de validação. Nenhum campo é inventado: só lemos os que
	 * aparecem de fato.
	 *
	 * @param array<string, mixed> $body Corpo da resposta.
	 * @return string Detalhe encontrado, ou string vazia.
	 */
	private static function extract_detail( $body ) {
		if ( ! is_array( $body ) || empty( $body ) ) {
			return '';
		}

		$candidates = array( 'message', 'error_description', 'error', 'detail', 'title' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
				return self::sanitize( (string) $body[ $key ] );
			}
		}

		if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
			$parts = array();

			foreach ( $body['errors'] as $error ) {
				if ( is_scalar( $error ) ) {
					$parts[] = self::sanitize( (string) $error );
					continue;
				}

				if ( is_array( $error ) && ! empty( $error['message'] ) && is_scalar( $error['message'] ) ) {
					$parts[] = self::sanitize( (string) $error['message'] );
				}
			}

			if ( ! empty( $parts ) ) {
				return implode( ' ', array_unique( $parts ) );
			}
		}

		return '';
	}

	/**
	 * Limpa o texto recebido da API antes de exibir.
	 *
	 * @param string $text Texto bruto.
	 * @return string Texto limpo.
	 */
	private static function sanitize( $text ) {
		$text = wp_strip_all_tags( $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( strlen( $text ) > 300 ) {
			$text = substr( $text, 0, 300 ) . '…';
		}

		return $text;
	}

	/**
	 * Indica se o erro é temporário e vale a pena tentar de novo depois.
	 *
	 * @param int $status Código HTTP.
	 * @return bool
	 */
	public static function is_transient( $status ) {
		$status = (int) $status;

		return 429 === $status || $status >= 500;
	}

	/**
	 * Indica se o erro exige reconectar a conta.
	 *
	 * @param int $status Código HTTP.
	 * @return bool
	 */
	public static function requires_reconnect( $status ) {
		return 401 === (int) $status;
	}
}
