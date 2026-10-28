=== Urbby for WooCommerce ===
Contributors: urbby
Tags: woocommerce, marketplace, inventory, stock, el salvador
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell your WooCommerce catalog on Urbby with the stock kept in one place, so your store and Urbby never sell the same unit twice.

== Description ==

Urbby is a marketplace for El Salvador and Central America. This plugin connects your WooCommerce store with your Urbby merchant account. **The stock lives in Urbby**: your store and Urbby both deduct from the same place.

* Your catalog (name, description, photos, price and sale price) is sent to Urbby when you save a product. WooCommerce stays in charge of content and price.
* A sale in your store reserves the stock in Urbby at checkout. If there is not enough stock, the purchase does not go through.
* An Urbby order creates an order in your store and its status follows Urbby automatically. The order carries no buyer data (name, phone or address): Urbby handles delivery.
* If you cancel an Urbby order in WooCommerce, it is cancelled in Urbby too, under the same rules as cancelling from the Urbby portal. If Urbby cannot cancel it (for example, it is already picked up), the order goes back to its previous status with a note explaining why.
* A delivered cash-on-delivery order is marked completed and paid.
* The stock shown by your store is a copy of Urbby's stock, refreshed after each sale and every 30 minutes.

The plugin texts are written in Spanish and can be translated with the usual WordPress tools (text domain `urbby-woocommerce`).

== Installation ==

1. Install and activate the plugin in Plugins → Add New (or upload the zip).
2. Open WooCommerce → Urbby and click **Connect with Urbby** («Conectar con Urbby»). You will be sent to the Urbby portal to authorize the connection (only the owner of the merchant account can) and come back already connected. Your site must be served over HTTPS.
3. Choose the Urbby category for each of your store categories (or a default one) and save.
4. The full catalog is queued for sending when the connection finishes. You can send it again at any time with **Send catalog to Urbby**.

Advanced: you can still paste an API credential and a webhook secret by hand (Advanced section) instead of using Connect.

== Frequently Asked Questions ==

= Do I need an Urbby account? =

Yes. You need an approved merchant account on Urbby. The Connect screen asks you to sign in to the Urbby portal.

= What happens if I disconnect, deactivate or uninstall the plugin? =

The plugin revokes its credential in Urbby. If the connection was made with Connect, Urbby then puts the products that came from your store in **draft** (nothing is deleted) and removes the webhook; a credential you pasted by hand is only revoked. When you connect again and send the catalog, they are published again according to WooCommerce. If Urbby does not answer within a few seconds, deactivation or uninstall continues anyway.

= Is buyer personal data stored in my store? =

No. Orders that come from Urbby carry only Urbby's order number, products, amounts and status. Urbby handles the buyer and the delivery.

= Where do I cancel an Urbby order? =

You can cancel it in WooCommerce or in the Urbby portal. Only orders that Urbby still allows to cancel (new or being prepared) are cancelled.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes. The plugin only uses WooCommerce's order CRUD API.

= Can I point it at a test server? =

Define `URBBY_WC_API_BASE` and `URBBY_WC_PORTAL_BASE` in `wp-config.php`. They cannot be changed from the admin screen.

== External services ==

This plugin connects your store to Urbby (https://urbby.app), the marketplace it integrates with. It does nothing until you connect it, and it needs an Urbby merchant account. Service terms: https://urbby.app/terminos — Privacy policy: https://urbby.app/privacidad

= Urbby API (https://api.urbby.app) =

Your server sends requests to the Urbby API, authenticated with the credential created when you connect. Every request also carries the plugin version in the User-Agent, and Urbby sees your server's IP address. What is sent, and when:

* **When you click Connect with Urbby and return from the portal:** the one-time authorization code, the PKCE verifier, your site's admin page address (as the return address) and your site's REST webhook address. Urbby answers with the credential and the webhook secret, which are stored in your database (the `urbby_wc_settings` option).
* **When you save a product, or click "Send catalog to Urbby", or when the plugin retries:** the product data — name, description, photo addresses, price and sale price, the options of variable products and the SKU of each variation, the Urbby category you chose, the stock quantity the first time a product is sent, and later manual stock changes as differences. Product status (published/draft) is included.
* **At checkout and when an order is paid, cancelled or refunded:** the Urbby product variants and quantities in the order, plus a reference made of a hash of your site address and the order number, to reserve, confirm or release stock. No buyer data is sent.
* **Every 30 minutes, and when you click the action buttons:** requests to read Urbby's product list and stock, your merchant's Urbby orders (order number, products, amounts, statuses, shipment status) and the category list.
* **When you cancel in WooCommerce an order that came from Urbby:** a cancel request for that Urbby order.
* **When you click Disconnect, or deactivate or uninstall the plugin:** a request that revokes the credential. Urbby then puts your products in draft and deletes the webhook.

Urbby also calls your site: signed order and shipment events are sent to the REST route `/wp-json/urbby/v1/webhook`.

= Urbby merchant portal (https://comercios.urbby.app) =

When you click **Connect with Urbby**, your browser (not your server) is redirected to this portal to authorize the connection. The address contains the app name (`woocommerce`), your site's admin page address as the return address, a random state value and a PKCE code challenge. Nothing else is sent from your site. The portal shows your site's domain to the merchant owner before authorizing.

== Changelog ==

= 0.2.0 =
* New: **Connect with Urbby** — authorize in the Urbby portal and the plugin gets its credential and webhook by itself (OAuth with PKCE). Pasting the credential by hand stays available under Advanced.
* New: when the connection finishes the full catalog is queued for sending.
* New: Urbby orders no longer copy buyer data into WooCommerce billing/shipping; they carry only Urbby's order reference.
* New: cancelling an Urbby order in WooCommerce cancels it in Urbby (retried in the background; if Urbby cannot, the order returns to its previous status with a note).
* New: a delivered order is completed and marked as paid; payment method title «Contra entrega (Urbby)» for cash on delivery.
* New: Disconnect button; disconnecting, deactivating or uninstalling revokes the credential in Urbby (products go to draft there).
* New: the settings page tells when Urbby has revoked the credential, and the store sells with its own stock meanwhile.
* WordPress.org readiness: English readme with the external services disclosure, translatable strings, `Domain Path`.

= 0.1.0 =
* First release: catalog, stock reservations at checkout, Urbby orders and statuses, 30-minute synchronization.

== Upgrade Notice ==

= 0.2.0 =
Adds Connect with Urbby, cancelling in your store cancels in Urbby, and no buyer data in orders. Existing manual connections keep working; reconnect with Connect to get the new webhook without buyer data.
