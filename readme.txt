=== Inky BTG ===
Contributors: inkydigital
Tags: pix, btg, btg pactual, woocommerce, gateway de pagamento
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receba Pix pelo BTG Pactual Empresas no WooCommerce: QR Code, copia e cola e pedido confirmado sozinho quando o pagamento cai.

== Description ==

O Inky BTG é um gateway de pagamento Pix para lojas WooCommerce que recebem pela conta do **BTG Pactual Empresas**, usando a API de Pix Cobrança do banco.

O cliente finaliza a compra, vê o QR Code e o código copia e cola na própria loja, paga pelo app do banco, e o pedido muda para pago sem ninguém conferir o extrato.

**O que o plugin faz**

* Cria a cobrança Pix no BTG Pactual Empresas no fechamento do pedido.
* Mostra QR Code e código copia e cola no checkout clássico e no checkout por blocos.
* Confirma o pagamento pelo webhook do BTG e, como reserva, por consulta periódica de status.
* Conecta a conta pelo OAuth do BTG (Authorization Code), com renovação automática do token.
* Envia à pessoa da empresa um link para autorizar a conta no BTG sem precisar de acesso ao WordPress.
* Guarda Client ID, Client Secret e tokens cifrados no banco, com os salts da instalação.
* Separa sandbox e produção, com credenciais e tokens independentes.
* Não repete automaticamente a criação de cobrança, para evitar Pix em dobro quando a resposta do banco é incerta.

**Importante**

Este plugin não é oficial e não tem vínculo com o BTG Pactual. BTG Pactual é marca do seu titular. Desenvolvido e mantido pela [Inky Digital](https://inkydigital.com.br), que faz integrações sob medida entre lojas, ERPs e meios de pagamento.

== Installation ==

1. Instale e ative o plugin. O WooCommerce precisa estar ativo.
2. Abra **WooCommerce > PIX BTG Pactual > Conexão**. O ambiente inicial é o sandbox.
3. Copie a URL de redirecionamento mostrada pelo plugin e cadastre-a no aplicativo do BTG.
4. Informe Client ID, Client Secret e a chave Pix da conta recebedora.
5. Clique em **Conectar** e autorize a conta no BTG.
6. Em produção, cadastre no BTG a URL da aba **Webhook**, com os eventos `instant-collection.paid` e `instant-collection.unlinked`, e salve o segredo do webhook no plugin.

Enquanto faltar chave Pix, credencial ou autorização, o Pix não aparece no checkout.

== Frequently Asked Questions ==

= Preciso de uma conta no BTG Pactual Empresas? =

Sim. O plugin usa a API de Pix Cobrança do BTG Pactual Empresas, com um aplicativo criado no portal de desenvolvedores do banco e o escopo de Pix Cash-in.

= O token expira. Preciso reconectar sempre? =

Não. O plugin renova o token sozinho. Só é preciso autorizar de novo se a autorização for revogada no BTG.

= O pedido é confirmado mesmo se o webhook falhar? =

Sim. Além do webhook, o plugin consulta periodicamente as cobranças em aberto e permite verificar um pedido na hora pelo botão "Verificar pagamento agora".

= Funciona com o checkout por blocos? =

Sim, com o checkout clássico e com o de blocos do WooCommerce.

= O plugin é oficial do BTG Pactual? =

Não. É um plugin independente, sem vínculo com o banco.

== External services ==

Este plugin se conecta à API do BTG Pactual Empresas para autorizar a conta (OAuth), criar e consultar cobranças Pix e receber notificações de pagamento.

* O que é enviado: o valor e o identificador do pedido, a chave Pix da loja, o texto da cobrança e as credenciais do aplicativo BTG configuradas pelo lojista.
* Quando: ao conectar a conta, ao finalizar um pedido com Pix, nas consultas de status e na renovação do token.
* Serviço: BTG Pactual Empresas. Termos e privacidade: https://www.btgpactual.com/politica-de-privacidade

== Changelog ==

= 0.2.2 =
* Versão inicial pública: Pix Cobrança, OAuth com renovação de token, webhook, consulta periódica e checkout clássico e por blocos.
