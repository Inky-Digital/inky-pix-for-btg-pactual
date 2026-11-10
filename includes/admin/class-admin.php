<?php
/**
 * Interface administrativa do plugin.
 *
 * @package Inky_BTG
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra o menu e a tela de configurações.
 */
class Inky_BTG_Admin {

	/**
	 * Slug da página de configurações.
	 */
	const PAGE_SLUG = 'inky-btg-settings';

	/**
	 * Nome da ação usada no formulário de gravação.
	 */
	const ACTION_SAVE = 'inky_btg_save_settings';

	/**
	 * Nome do nonce do formulário.
	 */
	const NONCE_SAVE = 'inky_btg_save_settings_nonce';

	/**
	 * Ação de conectar a conta.
	 */
	const ACTION_CONNECT = 'inky_btg_connect';

	/**
	 * Ação de desconectar a conta.
	 */
	const ACTION_DISCONNECT = 'inky_btg_disconnect';

	/**
	 * Ação AJAX que consulta o status de um pedido.
	 */
	const ACTION_CHECK_ORDER = 'inky_btg_admin_check_order';

	/**
	 * Ação AJAX do checklist de conexão.
	 */
	const ACTION_TEST_CONNECTION = 'inky_btg_test_connection';

	/**
	 * Registra os hooks da área administrativa.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_pending_notice' ) );
		add_action( 'wp_ajax_' . self::ACTION_CHECK_ORDER, array( $this, 'handle_check_order' ) );
		add_action( 'wp_ajax_' . self::ACTION_TEST_CONNECTION, array( $this, 'handle_test_connection' ) );
	}

	/**
	 * Registra a página no menu do WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'PIX BTG Pactual', 'inky-pix-for-btg-pactual' ),
			__( 'PIX BTG Pactual', 'inky-pix-for-btg-pactual' ),
			INKY_BTG_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Carrega os recursos da tela.
	 *
	 * @param string $hook Identificador da página atual.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'inky-btg-admin',
			INKY_BTG_URL . 'assets/css/admin.css',
			array(),
			INKY_BTG_VERSION
		);

		wp_enqueue_script(
			'inky-btg-admin',
			INKY_BTG_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			INKY_BTG_VERSION,
			true
		);

		wp_localize_script(
			'inky-btg-admin',
			'inkyBtgAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'inky_btg_admin' ),
				'strings' => array(
					'testing'      => __( 'Testando conexão…', 'inky-pix-for-btg-pactual' ),
					'copied'       => __( 'Log copiado.', 'inky-pix-for-btg-pactual' ),
					'copyError'    => __( 'Não foi possível copiar o log.', 'inky-pix-for-btg-pactual' ),
					'checking'     => __( 'Consultando o BTG…', 'inky-pix-for-btg-pactual' ),
					'checkError'   => __( 'Não foi possível consultar o pagamento.', 'inky-pix-for-btg-pactual' ),
					'missingOrder' => __( 'Informe o número do pedido.', 'inky-pix-for-btg-pactual' ),
					'stepOk'       => __( 'OK', 'inky-pix-for-btg-pactual' ),
					'stepFail'     => __( 'Falhou', 'inky-pix-for-btg-pactual' ),
					'stepSkip'     => __( 'Ignorado', 'inky-pix-for-btg-pactual' ),
					'stepRunning'  => __( 'Verificando…', 'inky-pix-for-btg-pactual' ),
					'testDone'     => __( 'Checklist concluído.', 'inky-pix-for-btg-pactual' ),
					'testFailed'   => __( 'O checklist parou na etapa que falhou.', 'inky-pix-for-btg-pactual' ),
				),
			)
		);
	}

	/**
	 * Exibe o aviso de pendências no painel.
	 *
	 * @return void
	 */
	public function render_pending_notice() {
		if ( ! current_user_can( INKY_BTG_CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || false === strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$count = Inky_BTG_API_Contract::count_open_questions();

		if ( $count < 1 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'PIX BTG Pactual:', 'inky-pix-for-btg-pactual' ),
			esc_html(
				sprintf(
					/* translators: %d: número de pendências. */
					_n(
						'Há %d item aguardando confirmação na documentação do BTG. O plugin funciona em modo presumido até que seja confirmado.',
						'Há %d itens aguardando confirmação na documentação do BTG. O plugin funciona em modo presumido até que sejam confirmados.',
						$count,
						'inky-pix-for-btg-pactual'
					),
					$count
				)
			)
		);
	}

	/**
	 * Renderiza a página de configurações.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( INKY_BTG_CAPABILITY ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'inky-pix-for-btg-pactual' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection for display only; no state change.
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';
		$settings = Inky_BTG_Settings::all();
		$env      = Inky_BTG_Settings::get_environment();

		$tabs = array(
			'connection' => __( 'Conexão', 'inky-pix-for-btg-pactual' ),
			'checkout'   => __( 'Checkout', 'inky-pix-for-btg-pactual' ),
			'webhook'    => __( 'Webhook', 'inky-pix-for-btg-pactual' ),
			'diagnostics' => __( 'Diagnóstico', 'inky-pix-for-btg-pactual' ),
			'pending'    => __( 'Pendências', 'inky-pix-for-btg-pactual' ),
		);

		?>
		<div class="wrap inky-btg-wrap">
			<h1><?php esc_html_e( 'PIX BTG Pactual para WooCommerce', 'inky-pix-for-btg-pactual' ); ?></h1>

			<?php $this->render_saved_notice(); ?>

			<nav class="nav-tab-wrapper inky-btg-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
						class="nav-tab <?php echo esc_attr( $slug === $tab ? 'nav-tab-active' : '' ); ?>"
					>
						<?php echo esc_html( $label ); ?>
						<?php if ( 'pending' === $slug && Inky_BTG_API_Contract::count_open_questions() > 0 ) : ?>
							<span class="inky-btg-badge"><?php echo esc_html( (string) Inky_BTG_API_Contract::count_open_questions() ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="inky-btg-tab-content">
				<?php
				switch ( $tab ) {
					case 'checkout':
						$this->render_checkout_tab( $settings );
						break;
					case 'webhook':
						$this->render_webhook_tab( $env );
						break;
					case 'diagnostics':
						$this->render_diagnostics_tab();
						break;
					case 'pending':
						$this->render_pending_tab();
						break;
					case 'connection':
					default:
						$this->render_connection_tab( $env );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Exibe o aviso de configurações salvas.
	 *
	 * @return void
	 */
	private function render_saved_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; no state change.
		if ( ! isset( $_GET['inky_btg_saved'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Configurações salvas.', 'inky-pix-for-btg-pactual' )
		);
	}

	/**
	 * Renderiza a aba de conexão.
	 *
	 * @param string $env Ambiente ativo.
	 * @return void
	 */
	private function render_connection_tab( $env ) {
		$credentials = Inky_BTG_Settings::get_credentials( $env );
		$has_creds   = Inky_BTG_Settings::has_credentials( $env );
		$redirect    = Inky_BTG_Settings::get_redirect_uri();

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
			<input type="hidden" name="tab" value="connection" />
			<?php wp_nonce_field( self::NONCE_SAVE, self::NONCE_SAVE ); ?>

			<h2><?php esc_html_e( 'Ambiente', 'inky-pix-for-btg-pactual' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Sandbox e produção usam credenciais e tokens separados. Trocar o ambiente não apaga os dados do outro.', 'inky-pix-for-btg-pactual' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Ambiente ativo', 'inky-pix-for-btg-pactual' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="environment" value="sandbox" <?php checked( $env, 'sandbox' ); ?> />
								<?php esc_html_e( 'Sandbox (testes)', 'inky-pix-for-btg-pactual' ); ?>
							</label>
							<br />
							<label>
								<input type="radio" name="environment" value="production" <?php checked( $env, 'production' ); ?> />
								<?php esc_html_e( 'Produção', 'inky-pix-for-btg-pactual' ); ?>
							</label>
						</fieldset>
						<?php if ( 'sandbox' === $env ) : ?>
						<p class="description">
							<?php esc_html_e( 'Atenção: o sandbox usa respostas simuladas. Ele permite testar a integração, mas não comprova pagamento e não marca pedidos como pagos.', 'inky-pix-for-btg-pactual' ); ?>
						</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Credenciais do aplicativo', 'inky-pix-for-btg-pactual' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Informe o client_id e o client_secret do aplicativo criado na Área do Desenvolvedor do BTG. Os valores são cifrados antes de serem gravados.', 'inky-pix-for-btg-pactual' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="inky-btg-client-id"><?php esc_html_e( 'Client ID', 'inky-pix-for-btg-pactual' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="inky-btg-client-id"
							name="client_id"
							class="regular-text code"
							autocomplete="off"
							placeholder="<?php echo esc_attr( $has_creds ? Inky_BTG_Encryption::mask( $credentials['client_id'] ) : '' ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Deixe em branco para manter o valor atual.', 'inky-pix-for-btg-pactual' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="inky-btg-client-secret"><?php esc_html_e( 'Client Secret', 'inky-pix-for-btg-pactual' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="inky-btg-client-secret"
							name="client_secret"
							class="regular-text code"
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( $has_creds ? Inky_BTG_Encryption::mask( $credentials['client_secret'] ) : '' ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Deixe em branco para manter o valor atual.', 'inky-pix-for-btg-pactual' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="inky-btg-pix-key"><?php esc_html_e( 'Chave Pix da conta recebedora', 'inky-pix-for-btg-pactual' ); ?></label></th>
					<td>
						<input type="password" id="inky-btg-pix-key" name="pix_key" class="regular-text code" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $credentials['pix_key'] ) ? Inky_BTG_Encryption::mask( $credentials['pix_key'] ) : '' ); ?>" />
						<p class="description"><?php esc_html_e( 'Obrigatória para criar cobranças. Deixe em branco para manter a chave atual.', 'inky-pix-for-btg-pactual' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'URL de redirecionamento', 'inky-pix-for-btg-pactual' ); ?></th>
					<td>
						<code class="inky-btg-copyable"><?php echo esc_html( $redirect ); ?></code>
						<p class="description">
							<?php esc_html_e( 'Cadastre exatamente esta URL no aplicativo do BTG. Ela não é configurável.', 'inky-pix-for-btg-pactual' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Salvar credenciais', 'inky-pix-for-btg-pactual' ) ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Autorização', 'inky-pix-for-btg-pactual' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'O BTG usa o fluxo Authorization Code. É preciso autorizar uma vez por ambiente, entrando com um usuário operador ou superior.', 'inky-pix-for-btg-pactual' ); ?>
		</p>

		<?php $this->render_oauth_status( $env, $has_creds ); ?>

		<p>
			<button type="button" class="button button-secondary" id="inky-btg-test-connection" <?php disabled( ! $has_creds ); ?>>
				<?php esc_html_e( 'Testar conexão', 'inky-pix-for-btg-pactual' ); ?>
			</button>
			<span class="inky-btg-test-result" aria-live="polite"></span>
		</p>

		<div class="inky-btg-checklist" id="inky-btg-checklist" hidden>
			<h3><?php esc_html_e( 'Checklist da conexão', 'inky-pix-for-btg-pactual' ); ?></h3>
			<ol class="inky-btg-checklist-steps"></ol>
		</div>

		<?php if ( ! $has_creds ) : ?>
			<p class="inky-btg-hint">
				<?php esc_html_e( 'Salve o client_id e o client_secret para habilitar o teste de conexão.', 'inky-pix-for-btg-pactual' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
		* Renderiza o estado da autorização e os botões de conectar e desconectar.
		*
		* @param string $env       Ambiente ativo.
		* @param bool   $has_creds Se as credenciais estão salvas.
		* @return void
		*/
	private function render_oauth_status( $env, $has_creds ) {
		$summary = Inky_BTG_Token_Store::get_summary( $env );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; no state change.
		if ( ! empty( $_GET['oauth_status'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; no state change.
			$status  = sanitize_key( wp_unslash( $_GET['oauth_status'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only notice text for display (escaped on output); rawurldecode must run before sanitize_text_field, which strips percent-encoding.
			$notice  = isset( $_GET['oauth_notice'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['oauth_notice'] ) ) ) : '';
			$class   = 'success' === $status ? 'notice-success' : 'notice-error';

			if ( '' !== $notice ) {
				printf(
					'<div class="notice %1$s inline"><p>%2$s</p></div>',
					esc_attr( $class ),
					esc_html( $notice )
				);
			}
		}

		if ( $summary['connected'] ) {
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Situação', 'inky-pix-for-btg-pactual' ); ?></th>
					<td>
						<span class="inky-btg-status inky-btg-status--ok"><?php esc_html_e( 'Conectado', 'inky-pix-for-btg-pactual' ); ?></span>
					</td>
				</tr>
				<?php if ( '' !== $summary['company_id'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'CNPJ autorizado', 'inky-pix-for-btg-pactual' ); ?></th>
						<td><code><?php echo esc_html( $summary['company_id'] ); ?></code></td>
					</tr>
				<?php endif; ?>
				<?php if ( $summary['expires_at'] > 0 ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Token válido até', 'inky-pix-for-btg-pactual' ); ?></th>
						<td>
							<?php
							echo esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									$summary['expires_at']
								)
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISCONNECT ); ?>" />
				<?php wp_nonce_field( self::ACTION_DISCONNECT, self::NONCE_SAVE ); ?>
				<?php submit_button( __( 'Desconectar conta', 'inky-pix-for-btg-pactual' ), 'delete', 'submit', false, array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Desconectar apaga o token. Para voltar a funcionar, a titular da conta terá de autorizar de novo. Continuar?', 'inky-pix-for-btg-pactual' ) ) . ');' ) ); ?>
			</form>
			<?php
			return;
		}

		?>
		<p>
			<span class="inky-btg-status inky-btg-status--off"><?php esc_html_e( 'Não conectado', 'inky-pix-for-btg-pactual' ); ?></span>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONNECT ); ?>" />
			<?php wp_nonce_field( self::ACTION_CONNECT, self::NONCE_SAVE ); ?>
			<?php submit_button( __( 'Conectar conta do BTG', 'inky-pix-for-btg-pactual' ), 'primary', 'submit', false, $has_creds ? array() : array( 'disabled' => 'disabled' ) ); ?>
			<?php submit_button( __( 'Gerar link para a cliente', 'inky-pix-for-btg-pactual' ), 'secondary', 'inky_btg_share', false, $has_creds ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>

		<?php $share_link = get_transient( 'inky_btg_share_link' ); ?>
		<?php if ( $share_link ) : ?>
			<p>
				<label for="inky-btg-share-link"><?php esc_html_e( 'Envie este link à cliente. Ela entra com o usuário dela (perfil Operador ou superior), escolhe a empresa e autoriza. Vale por 7 dias e só pode ser usado uma vez.', 'inky-pix-for-btg-pactual' ); ?></label><br />
				<input type="text" id="inky-btg-share-link" class="large-text code" readonly value="<?php echo esc_attr( $share_link ); ?>" onclick="this.select();" />
			</p>
		<?php endif; ?>

		<?php if ( ! $has_creds ) : ?>
			<p class="inky-btg-hint">
				<?php esc_html_e( 'Salve as credenciais antes de conectar.', 'inky-pix-for-btg-pactual' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
		* Redireciona o lojista para o BTG Id para autorizar a conta.
		*
		* @return void
		*/
	public function handle_connect() {
		if ( ! current_user_can( INKY_BTG_CAPABILITY ) ) {
			wp_die( esc_html__( 'Você não tem permissão para conectar a conta.', 'inky-pix-for-btg-pactual' ) );
		}

		check_admin_referer( self::ACTION_CONNECT, self::NONCE_SAVE );

		$environment = Inky_BTG_Settings::get_environment();
		$oauth       = new Inky_BTG_OAuth();
		$url         = $oauth->get_authorize_url( $environment );

		if ( is_wp_error( $url ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::PAGE_SLUG,
						'tab'          => 'connection',
						'oauth_status' => 'error',
						'oauth_notice' => rawurlencode( $url->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( isset( $_POST['inky_btg_share'] ) ) {
			// Link para enviar à cliente: ela faz o login no BTG sem precisar de acesso ao WordPress.
			set_transient( 'inky_btg_share_link', $url, Inky_BTG_OAuth::STATE_TTL );
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'connection' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- destino externo é o servidor de autorização do BTG.
		exit;
	}

	/**
		* Desconecta a conta do ambiente ativo.
		*
		* @return void
		*/
	public function handle_disconnect() {
		if ( ! current_user_can( INKY_BTG_CAPABILITY ) ) {
			wp_die( esc_html__( 'Você não tem permissão para desconectar a conta.', 'inky-pix-for-btg-pactual' ) );
		}

		check_admin_referer( self::ACTION_DISCONNECT, self::NONCE_SAVE );

		$environment = Inky_BTG_Settings::get_environment();
		$oauth       = new Inky_BTG_OAuth();
		$oauth->disconnect( $environment );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'tab'          => 'connection',
					'oauth_status' => 'success',
					'oauth_notice' => rawurlencode( __( 'Conta desconectada.', 'inky-pix-for-btg-pactual' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renderiza a aba de checkout.
	 *
	 * @param array<string, mixed> $settings Configurações atuais.
	 * @return void
	 */
	private function render_checkout_tab( $settings ) {
		$unit = 'reais';

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
			<input type="hidden" name="tab" value="checkout" />
			<?php wp_nonce_field( self::NONCE_SAVE, self::NONCE_SAVE ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Habilitar no checkout', 'inky-pix-for-btg-pactual' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="yes" <?php checked( 'yes', isset( $settings['enabled'] ) ? $settings['enabled'] : 'no' ); ?> />
							<?php esc_html_e( 'Exibir o PIX como forma de pagamento', 'inky-pix-for-btg-pactual' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="inky-btg-title"><?php esc_html_e( 'Título', 'inky-pix-for-btg-pactual' ); ?></label>
					</th>
					<td>
						<input type="text" id="inky-btg-title" name="title" class="regular-text" value="<?php echo esc_attr( isset( $settings['title'] ) ? $settings['title'] : '' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="inky-btg-description"><?php esc_html_e( 'Descrição', 'inky-pix-for-btg-pactual' ); ?></label>
					</th>
					<td>
						<textarea id="inky-btg-description" name="description" class="large-text" rows="2"><?php echo esc_textarea( isset( $settings['description'] ) ? $settings['description'] : '' ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="inky-btg-instructions"><?php esc_html_e( 'Instruções na tela de obrigado', 'inky-pix-for-btg-pactual' ); ?></label>
					</th>
					<td>
						<textarea id="inky-btg-instructions" name="instructions" class="large-text" rows="3"><?php echo esc_textarea( isset( $settings['instructions'] ) ? $settings['instructions'] : '' ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="inky-btg-expiration"><?php esc_html_e( 'Validade da cobrança', 'inky-pix-for-btg-pactual' ); ?></label>
					</th>
					<td>
						<input type="number" id="inky-btg-expiration" name="expiration_minutes" min="5" max="1440" step="5" value="<?php echo esc_attr( (string) Inky_BTG_Settings::get_expiration_minutes() ); ?>" />
						<?php esc_html_e( 'minutos', 'inky-pix-for-btg-pactual' ); ?>
					</td>
				</tr>
				<tr><th scope="row"><?php esc_html_e( 'Unidade do valor', 'inky-pix-for-btg-pactual' ); ?></th><td><?php esc_html_e( 'Reais, com duas casas decimais. Confira o valor exibido no teste de sandbox.', 'inky-pix-for-btg-pactual' ); ?></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Verificação automática', 'inky-pix-for-btg-pactual' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="polling_enabled" value="yes" <?php checked( 'yes', isset( $settings['polling_enabled'] ) ? $settings['polling_enabled'] : 'yes' ); ?> />
							<?php esc_html_e( 'Consultar o status do pagamento periodicamente', 'inky-pix-for-btg-pactual' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Recomendado manter ativo. Em produção, consulta o status caso o webhook não chegue. No sandbox, as respostas são simuladas e não confirmam pagamento real.', 'inky-pix-for-btg-pactual' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Salvar configurações de checkout', 'inky-pix-for-btg-pactual' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Renderiza a aba de webhook.
	 *
	 * @param string $env Ambiente ativo.
	 * @return void
	 */
	private function render_webhook_tab( $env ) {
		$secret       = Inky_BTG_Settings::get_webhook_secret( $env );
		$webhook_url  = Inky_BTG_Settings::get_webhook_url();
		$is_sandbox   = 'sandbox' === $env;

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
			<input type="hidden" name="tab" value="webhook" />
			<?php wp_nonce_field( self::NONCE_SAVE, self::NONCE_SAVE ); ?>

			<?php if ( $is_sandbox ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'O BTG informa que webhooks do sandbox não estão disponíveis para todos. O cadastro de webhook mostrado no portal é de produção; configure o segredo de produção ao mudar o ambiente do plugin.', 'inky-pix-for-btg-pactual' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'URL do webhook', 'inky-pix-for-btg-pactual' ); ?></th>
					<td>
						<code class="inky-btg-copyable"><?php echo esc_html( $webhook_url ); ?></code>
						<p class="description">
							<?php esc_html_e( 'Precisa ser HTTPS na porta 443. O BTG não aceita portas personalizadas.', 'inky-pix-for-btg-pactual' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="inky-btg-webhook-secret"><?php esc_html_e( 'Segredo do webhook', 'inky-pix-for-btg-pactual' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="inky-btg-webhook-secret"
							name="webhook_secret"
							class="regular-text code"
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( '' !== $secret ? Inky_BTG_Encryption::mask( $secret ) : '' ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Informe somente o segredo real do webhook BTG, nunca um valor aleatório nem o Client Secret. Deixe em branco para manter o valor salvo.', 'inky-pix-for-btg-pactual' ); ?>
						</p>
						<?php if ( '' !== $secret ) : ?>
							<label>
								<input type="checkbox" name="clear_webhook_secret" value="yes" />
								<?php esc_html_e( 'Limpar o segredo salvo neste ambiente', 'inky-pix-for-btg-pactual' ); ?>
							</label>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Salvar webhook', 'inky-pix-for-btg-pactual' ) ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Como o plugin trata os eventos', 'inky-pix-for-btg-pactual' ); ?></h2>
		<ul class="inky-btg-list">
			<li><?php esc_html_e( 'Eventos aceitos: instant-collection.paid e instant-collection.unlinked.', 'inky-pix-for-btg-pactual' ); ?></li>
			<li><?php esc_html_e( 'A resposta é enviada em menos de 10 segundos, como o BTG exige.', 'inky-pix-for-btg-pactual' ); ?></li>
			<li><?php esc_html_e( 'Entregas repetidas são identificadas pelo cabeçalho x-correlation-id e ignoradas.', 'inky-pix-for-btg-pactual' ); ?></li>
			<li><?php esc_html_e( 'No cadastro do endpoint BTG, deixe Tags em branco. O plugin envia a tag order em cada cobrança e também concilia pelo txId.', 'inky-pix-for-btg-pactual' ); ?></li>
			<li><?php esc_html_e( 'O pedido é localizado pelas tags enviadas na cobrança. Se elas não vierem, o plugin usa o txId.', 'inky-pix-for-btg-pactual' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Renderiza a aba de diagnóstico.
	 *
	 * @return void
	 */
	private function render_diagnostics_tab() {
		$settings = Inky_BTG_Settings::all();

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
			<input type="hidden" name="tab" value="diagnostics" />
			<?php wp_nonce_field( self::NONCE_SAVE, self::NONCE_SAVE ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Log detalhado', 'inky-pix-for-btg-pactual' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="debug_logging" value="yes" <?php checked( 'yes', isset( $settings['debug_logging'] ) ? $settings['debug_logging'] : 'no' ); ?> />
							<?php esc_html_e( 'Registrar chamadas e respostas da API', 'inky-pix-for-btg-pactual' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Tokens, segredos e chaves PIX são sempre mascarados, mesmo com o log detalhado ligado.', 'inky-pix-for-btg-pactual' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Remover dados ao desinstalar', 'inky-pix-for-btg-pactual' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="delete_data_on_uninstall" value="yes" <?php checked( 'yes', isset( $settings['delete_data_on_uninstall'] ) ? $settings['delete_data_on_uninstall'] : 'no' ); ?> />
							<?php esc_html_e( 'Apagar configurações, credenciais e logs ao desinstalar o plugin', 'inky-pix-for-btg-pactual' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Salvar diagnóstico', 'inky-pix-for-btg-pactual' ) ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Verificar pagamento agora', 'inky-pix-for-btg-pactual' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Consulta o BTG na hora, sem esperar o próximo ciclo do polling. Use quando o cliente avisar que pagou e o pedido ainda estiver pendente.', 'inky-pix-for-btg-pactual' ); ?>
		</p>
		<p>
			<label for="inky-btg-check-order-id"><?php esc_html_e( 'Número do pedido', 'inky-pix-for-btg-pactual' ); ?></label>
			<input type="number" min="1" step="1" id="inky-btg-check-order-id" class="small-text" />
			<button type="button" class="button button-secondary" id="inky-btg-check-order"><?php esc_html_e( 'Verificar pagamento agora', 'inky-pix-for-btg-pactual' ); ?></button>
			<span class="inky-btg-check-result" aria-live="polite"></span>
		</p>

		<hr />

		<h2><?php esc_html_e( 'Últimos registros', 'inky-pix-for-btg-pactual' ); ?></h2>
		<p>
			<button type="button" class="button" id="inky-btg-copy-log"><?php esc_html_e( 'Copiar log', 'inky-pix-for-btg-pactual' ); ?></button>
			<span class="inky-btg-copy-result" aria-live="polite"></span>
		</p>
		<textarea id="inky-btg-log-output" class="large-text code" rows="16" readonly><?php echo esc_textarea( $this->get_recent_log_lines() ); ?></textarea>
		<?php
	}

	/**
	 * Renderiza a aba de pendências.
	 *
	 * @return void
	 */
	private function render_pending_tab() {
		$questions = Inky_BTG_API_Contract::get_open_questions();

		?>
		<h2><?php esc_html_e( 'Itens aguardando confirmação', 'inky-pix-for-btg-pactual' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Estes pontos não estão descritos na documentação pública do BTG. O plugin não inventou nenhum valor: onde não há confirmação, ele adota o comportamento mais seguro e avisa aqui.', 'inky-pix-for-btg-pactual' ); ?>
		</p>

		<table class="widefat striped inky-btg-pending-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Item', 'inky-pix-for-btg-pactual' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Situação', 'inky-pix-for-btg-pactual' ); ?></th>
					<th scope="col"><?php esc_html_e( 'O que fazer', 'inky-pix-for-btg-pactual' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $questions as $question ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $question['title'] ); ?></strong>
							<p class="description"><?php echo esc_html( $question['detail'] ); ?></p>
						</td>
						<td>
							<?php if ( ! empty( $question['resolved'] ) ) : ?>
								<span class="inky-btg-status inky-btg-status-ok"><?php esc_html_e( 'Confirmado', 'inky-pix-for-btg-pactual' ); ?></span>
							<?php else : ?>
								<span class="inky-btg-status inky-btg-status-pending"><?php esc_html_e( 'Presumido', 'inky-pix-for-btg-pactual' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $question['action'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Executa o checklist de conexão com o BTG.
	 *
	 * São seis etapas independentes. A primeira que falhar interrompe o
	 * restante, porque as seguintes dependem dela. Nenhuma etapa cria
	 * cobrança real: a última apenas confirma que a listagem responde.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		check_ajax_referer( 'inky_btg_admin', 'nonce' );

		if ( ! current_user_can( INKY_BTG_CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Você não tem permissão para testar a conexão.', 'inky-pix-for-btg-pactual' ) ),
				403
			);
		}

		$env   = Inky_BTG_Settings::get_environment();
		$steps = array();

		// Etapa 1: credenciais salvas.
		$has_creds = Inky_BTG_Settings::has_credentials( $env );
		$steps[]   = $this->build_step(
			__( 'Credenciais do aplicativo', 'inky-pix-for-btg-pactual' ),
			$has_creds,
			$has_creds
				? __( 'client_id e client_secret encontrados.', 'inky-pix-for-btg-pactual' )
				: __( 'Salve o client_id e o client_secret na aba Conexão.', 'inky-pix-for-btg-pactual' )
		);

		if ( ! $has_creds ) {
			wp_send_json_error(
				array(
					'message' => __( 'O checklist parou na primeira etapa.', 'inky-pix-for-btg-pactual' ),
					'steps'   => $steps,
				)
			);
		}

		// Etapa 2: token de acesso válido.
		$has_token = Inky_BTG_Token_Store::has_token( $env );
		$steps[]   = $this->build_step(
			__( 'Autorização OAuth', 'inky-pix-for-btg-pactual' ),
			$has_token,
			$has_token
				? __( 'Token de acesso presente para este ambiente.', 'inky-pix-for-btg-pactual' )
				: __( 'Clique em Conectar e autorize o aplicativo no BTG.', 'inky-pix-for-btg-pactual' )
		);

		if ( ! $has_token ) {
			wp_send_json_error(
				array(
					'message' => __( 'O checklist parou na etapa de autorização.', 'inky-pix-for-btg-pactual' ),
					'steps'   => $steps,
				)
			);
		}

		// Etapa 3: token ainda dentro da validade.
		$expired = Inky_BTG_Token_Store::is_expired( $env );
		$steps[] = $this->build_step(
			__( 'Validade do token', 'inky-pix-for-btg-pactual' ),
			! $expired,
			$expired
				? __( 'O token expirou. A renovação automática deve assumir na próxima chamada.', 'inky-pix-for-btg-pactual' )
				: __( 'Token dentro da validade.', 'inky-pix-for-btg-pactual' )
		);

		// Etapa 4: companyId extraído da claim do token.
		$company_id = Inky_BTG_Token_Store::get_company_id( $env );
		$steps[]    = $this->build_step(
			__( 'Identificador da empresa', 'inky-pix-for-btg-pactual' ),
			! empty( $company_id ),
			! empty( $company_id )
				? sprintf(
					/* translators: 1: CNPJ, 2: ambiente */
					__( 'companyId %1$s lido da claim pix-cash-in do token (ambiente: %2$s).', 'inky-pix-for-btg-pactual' ),
					$company_id,
					'production' === $env ? __( 'Produção', 'inky-pix-for-btg-pactual' ) : __( 'Sandbox', 'inky-pix-for-btg-pactual' )
				)
				: __( 'A claim pix-cash-in não trouxe o companyId. Verifique se o aplicativo tem o escopo de PIX habilitado.', 'inky-pix-for-btg-pactual' )
		);

		if ( empty( $company_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'O checklist parou na leitura do companyId.', 'inky-pix-for-btg-pactual' ),
					'steps'   => $steps,
				)
			);
		}

		// Etapa 5: chamada autenticada de leitura na API.
		$oauth  = new Inky_BTG_OAuth();
		$client = $oauth->get_authenticated_client( $env );

		if ( is_wp_error( $client ) ) {
			$steps[] = $this->build_step(
				__( 'Chamada autenticada à API', 'inky-pix-for-btg-pactual' ),
				false,
				$client->get_error_message()
			);

			wp_send_json_error(
				array(
					'message' => __( 'O checklist parou na chamada à API.', 'inky-pix-for-btg-pactual' ),
					'steps'   => $steps,
				)
			);
		}

		$locations = new Inky_BTG_Locations( $client );
		$response  = $locations->list_all( $company_id );

		if ( is_wp_error( $response ) ) {
			$steps[] = $this->build_step(
				__( 'Chamada autenticada à API', 'inky-pix-for-btg-pactual' ),
				false,
				$response->get_error_message()
			);

			wp_send_json_error(
				array(
					'message' => __( 'O checklist parou na chamada à API.', 'inky-pix-for-btg-pactual' ),
					'steps'   => $steps,
				)
			);
		}

		$steps[] = $this->build_step(
			__( 'Chamada autenticada à API', 'inky-pix-for-btg-pactual' ),
			true,
			__( 'A API respondeu à listagem de locations.', 'inky-pix-for-btg-pactual' )
		);

		// Etapa 6: webhook configurado (informativo no sandbox).
		$webhook_secret = Inky_BTG_Settings::get_webhook_secret( $env );
		$webhook_ready  = ! empty( $webhook_secret );

		if ( 'sandbox' === $env ) {
			$steps[] = $this->build_step(
				__( 'Webhook', 'inky-pix-for-btg-pactual' ),
				true,
				$webhook_ready
					? __( 'Segredo salvo. O sandbox não comprova liquidação; confirme pagamentos reais apenas em produção.', 'inky-pix-for-btg-pactual' )
					: __( 'Webhook de sandbox indisponível para muitos usuários. O teste de pagamento real exige produção.', 'inky-pix-for-btg-pactual' ),
				true
			);
		} else {
			$steps[] = $this->build_step(
				__( 'Webhook', 'inky-pix-for-btg-pactual' ),
			$webhook_ready,
			$webhook_ready
				? __( 'Segredo informado. A autenticação só é validada quando o BTG entrega um evento real.', 'inky-pix-for-btg-pactual' )
					: __( 'Cadastre o segredo do webhook na aba Webhook para validar as notificações.', 'inky-pix-for-btg-pactual' )
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Todas as etapas foram concluídas.', 'inky-pix-for-btg-pactual' ),
				'steps'   => $steps,
			)
		);
	}

	/**
	 * Monta a estrutura de uma etapa do checklist.
	 *
	 * @param string $label   Nome da etapa.
	 * @param bool   $ok      Se a etapa passou.
	 * @param string $detail  Explicação exibida ao lojista.
	 * @param bool   $skipped Se a etapa é apenas informativa.
	 * @return array
	 */
	private function build_step( $label, $ok, $detail, $skipped = false ) {
		return array(
			'label'   => $label,
			'ok'      => (bool) $ok,
			'skipped' => (bool) $skipped,
			'detail'  => $detail,
		);
	}

	/**
	 * Consulta o status de um pedido a pedido do lojista.
	 *
	 * É o mesmo caminho usado pelo polling e pelo webhook: chama o método
	 * unificado do serviço de cobranças, para que não exista uma segunda
	 * regra de confirmação.
	 *
	 * @return void
	 */
	public function handle_check_order() {
		check_ajax_referer( 'inky_btg_admin', 'nonce' );

		if ( ! current_user_can( INKY_BTG_CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Você não tem permissão para consultar pedidos.', 'inky-pix-for-btg-pactual' ) ),
				403
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_send_json_error(
				array( 'message' => __( 'Pedido não encontrado.', 'inky-pix-for-btg-pactual' ) ),
				404
			);
		}

		$charges = new Inky_BTG_Charge_Service( new Inky_BTG_OAuth() );
		$result  = $charges->refresh_status( $order );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'paid'    => ! empty( $result['paid'] ),
				'status'  => isset( $result['status'] ) ? (string) $result['status'] : '',
				'message' => ! empty( $result['paid'] )
					? __( 'Pagamento confirmado. O pedido foi marcado como pago.', 'inky-pix-for-btg-pactual' )
					: ( ! empty( $result['simulated'] )
						? __( 'Resposta de sandbox simulada; nenhum pagamento real foi confirmado e o pedido não foi marcado como pago.', 'inky-pix-for-btg-pactual' )
						: __( 'O BTG ainda não registrou o pagamento desta cobrança.', 'inky-pix-for-btg-pactual' ) ),
			)
		);
	}

	/**
	 * Monta as últimas linhas do log para exibição.
	 *
	 * O texto é montado para ser colado em um chamado de suporte: traz o
	 * ambiente, a versão do plugin e as versões relevantes antes das linhas.
	 * O conteúdo passa por uma segunda camada de mascaramento, porque o log
	 * pode ter sido gravado antes de uma chave entrar na lista de sensíveis.
	 *
	 * @return string
	 */
	private function get_recent_log_lines() {
		global $wpdb;

		$table = Inky_BTG_Install::get_logs_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT created_at, level, source, message, correlation_id FROM %i ORDER BY id DESC LIMIT %d',
				$table,
				200
			),
			ARRAY_A
		);

		$header = array(
			sprintf( 'Plugin: inky-btg %s', INKY_BTG_VERSION ),
			sprintf( 'Ambiente: %s', Inky_BTG_Settings::get_environment() ),
			sprintf( 'WordPress: %s', get_bloginfo( 'version' ) ),
			sprintf( 'WooCommerce: %s', defined( 'WC_VERSION' ) ? WC_VERSION : 'n/d' ),
			sprintf( 'PHP: %s', PHP_VERSION ),
			sprintf( 'Gerado em: %s', gmdate( 'Y-m-d H:i:s' ) . ' UTC' ),
			str_repeat( '-', 60 ),
		);

		if ( empty( $rows ) ) {
			$header[] = __( 'Nenhum registro encontrado.', 'inky-pix-for-btg-pactual' );

			return implode( "\n", $header );
		}

		$lines = array();

		foreach ( $rows as $row ) {
			$lines[] = sprintf(
				'[%s] %s %s %s%s',
				$row['created_at'],
				strtoupper( (string) $row['level'] ),
				$row['source'],
				$row['message'],
				'' !== (string) $row['correlation_id'] ? ' (x-correlation-id: ' . $row['correlation_id'] . ')' : ''
			);
		}

		$body = Inky_BTG_Logger::sanitize( implode( "\n", $lines ) );

		return implode( "\n", $header ) . "\n" . ( is_string( $body ) ? $body : implode( "\n", $lines ) );
	}

	/**
	 * Processa o formulário de gravação.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( INKY_BTG_CAPABILITY ) ) {
			wp_die( esc_html__( 'Você não tem permissão para salvar estas configurações.', 'inky-pix-for-btg-pactual' ) );
		}

		check_admin_referer( self::NONCE_SAVE, self::NONCE_SAVE );

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'connection';

		switch ( $tab ) {
			case 'connection':
				$this->save_connection_tab();
				break;
			case 'checkout':
				$this->save_checkout_tab();
				break;
			case 'webhook':
				$this->save_webhook_tab();
				break;
			case 'diagnostics':
				$this->save_diagnostics_tab();
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'tab'           => $tab,
					'inky_btg_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- These run only from handle_save(), after check_admin_referer().
	/**
	 * Salva a aba de conexão.
	 *
	 * @return void
	 */
	private function save_connection_tab() {
		$environment = isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : 'sandbox';

		if ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) ) {
			$environment = 'sandbox';
		}

		Inky_BTG_Settings::set( 'environment', $environment );

		$credentials = array(
			'client_id'     => isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '',
			'client_secret' => isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '',
			'pix_key'       => isset( $_POST['pix_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pix_key'] ) ) : '',
		);

		Inky_BTG_Settings::save_credentials( $environment, $credentials );

		Inky_BTG_Logger::info(
			__( 'Configurações de conexão atualizadas.', 'inky-pix-for-btg-pactual' ),
			array( 'source' => 'admin' )
		);
	}

	/**
	 * Salva a aba de checkout.
	 *
	 * @return void
	 */
	private function save_checkout_tab() {
		$unit = 'reais';

		Inky_BTG_Settings::set_many(
			array(
				'enabled'            => isset( $_POST['enabled'] ) ? 'yes' : 'no',
				'title'              => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
				'description'        => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
				'instructions'       => isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '',
				'expiration_minutes' => isset( $_POST['expiration_minutes'] ) ? absint( wp_unslash( $_POST['expiration_minutes'] ) ) : 60,
				'amount_unit'        => $unit,
				'polling_enabled'    => isset( $_POST['polling_enabled'] ) ? 'yes' : 'no',
			)
		);
		$gateway_options = get_option( 'woocommerce_' . Inky_BTG_Gateway::ID . '_settings', array() );
		if ( ! is_array( $gateway_options ) ) {
			$gateway_options = array();
		}
		$gateway_options['enabled'] = isset( $_POST['enabled'] ) ? 'yes' : 'no';
		$gateway_options['title'] = Inky_BTG_Settings::get( 'title', 'PIX' );
		$gateway_options['description'] = Inky_BTG_Settings::get( 'description', '' );
		update_option( 'woocommerce_' . Inky_BTG_Gateway::ID . '_settings', $gateway_options, false );

		Inky_BTG_Logger::info(
			__( 'Configurações de checkout atualizadas.', 'inky-pix-for-btg-pactual' ),
			array(
				'source'      => 'admin',
				'amount_unit' => $unit,
			)
		);
	}

	/**
	 * Salva a aba de webhook.
	 *
	 * @return void
	 */
	private function save_webhook_tab() {
		$environment = Inky_BTG_Settings::get_environment();
		$secret      = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';

		if ( isset( $_POST['clear_webhook_secret'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['clear_webhook_secret'] ) ) ) {
			Inky_BTG_Settings::clear_webhook_secret( $environment );
		} elseif ( '' !== $secret ) {
			Inky_BTG_Settings::save_webhook_secret( $environment, $secret );
		}

		Inky_BTG_Logger::info(
			__( 'Configurações de webhook atualizadas.', 'inky-pix-for-btg-pactual' ),
			array( 'source' => 'admin' )
		);
	}

	/**
	 * Salva a aba de diagnóstico.
	 *
	 * @return void
	 */
	private function save_diagnostics_tab() {
		Inky_BTG_Settings::set_many(
			array(
				'debug_logging'            => isset( $_POST['debug_logging'] ) ? 'yes' : 'no',
				'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ) ? 'yes' : 'no',
			)
		);

		Inky_BTG_Logger::info(
			__( 'Configurações de diagnóstico atualizadas.', 'inky-pix-for-btg-pactual' ),
			array( 'source' => 'admin' )
		);
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
