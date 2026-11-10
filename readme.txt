=== Inky Pix for BTG Pactual ===
Contributors: inkydigital
Tags: pix, btg, btg pactual, woocommerce, payment gateway
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Pix through BTG Pactual Empresas in WooCommerce: QR Code, copy and paste code, and orders confirmed automatically when the payment arrives.

== Description ==

Inky Pix for BTG Pactual is a Pix payment gateway for WooCommerce stores that receive payments in a **BTG Pactual Empresas** business account, using the bank's Pix Collection (Pix Cobrança) API.

The customer places the order, sees the QR Code and the copy and paste code on the store itself, pays in their banking app, and the order is marked as paid without anyone checking the bank statement.

The plugin interface is in Brazilian Portuguese, since Pix is the Brazilian instant payment system.

**What the plugin does**

* Creates the Pix charge in BTG Pactual Empresas when the order is placed.
* Shows the QR Code and the copy and paste code in both the classic checkout and the block checkout.
* Confirms the payment through the BTG webhook and, as a fallback, through periodic status checks.
* Connects the account through BTG OAuth (Authorization Code), with automatic token renewal.
* Generates a link so a person at the company can authorize the BTG account without access to WordPress.
* Stores Client ID, Client Secret and tokens encrypted in the database, using the site's salts.
* Keeps sandbox and production separate, with independent credentials and tokens.
* Never retries charge creation automatically, to avoid duplicate Pix charges when the bank's response is uncertain.

**Important**

This is an unofficial plugin, not affiliated with or endorsed by BTG Pactual. BTG Pactual is a trademark of its owner. Developed and maintained by [Inky Digital](https://inkydigital.com.br), which builds custom integrations between stores, ERPs and payment providers.

Source code and issues: [github.com/Inky-Digital/inky-pix-for-btg-pactual](https://github.com/Inky-Digital/inky-pix-for-btg-pactual).

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Open **WooCommerce > PIX BTG Pactual > Conexão**. The initial environment is sandbox.
3. Copy the redirect URL shown by the plugin and register it in your BTG application.
4. Enter the Client ID, the Client Secret and the Pix key of the receiving account.
5. Click **Conectar** and authorize the account at BTG.
6. In production, register the URL from the **Webhook** tab at BTG, with the events `instant-collection.paid` and `instant-collection.unlinked`, and save the webhook secret in the plugin.

Pix is not offered at checkout while the Pix key, the credentials or the authorization are missing.

== Frequently Asked Questions ==

= Do I need a BTG Pactual Empresas account? =

Yes. The plugin uses the BTG Pactual Empresas Pix Collection API, with an application created in the bank's developer portal and the Pix Cash-in scope.

= The token expires. Do I have to reconnect all the time? =

No. The plugin renews the token automatically. You only need to authorize again if the authorization is revoked at BTG.

= Is the order confirmed even if the webhook fails? =

Yes. Besides the webhook, the plugin periodically checks open charges and lets you check an order immediately with the "Verificar pagamento agora" button.

= Does it work with the block checkout? =

Yes, with both the classic checkout and the WooCommerce block checkout.

= Is this an official BTG Pactual plugin? =

No. It is an independent plugin, not affiliated with the bank.

== External services ==

This plugin connects to the BTG Pactual Empresas API to authorize the account (OAuth), create and query Pix charges, and receive payment notifications.

* What is sent: the order amount and identifier, the store's Pix key, the charge description, and the BTG application credentials configured by the store owner.
* When: when connecting the account, when an order is placed with Pix, during status checks and during token renewal.
* Service: BTG Pactual Empresas. Privacy policy: https://www.btgpactual.com/politica-de-privacidade

== Changelog ==

= 0.2.3 =
* New directory name: Inky Pix for BTG Pactual (text domain inky-pix-for-btg-pactual).
* Readme in English, translators comments and table names through %i placeholders (Plugin Check).

= 0.2.2 =
* First public release: Pix Collection, OAuth with token renewal, webhook, periodic status checks, classic and block checkout.
