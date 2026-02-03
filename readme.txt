=== Avvance for WooCommerce ===
Contributors: usbankavvance
Tags: payments, financing, installment, bnpl, avvance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offer customers flexible installment financing through U.S. Bank Avvance at checkout.

== Description ==

U.S. Bank Avvance for WooCommerce enables you to offer point-of-sale financing to your customers. With Avvance, customers can apply for installment loans ranging from $300 to $25,000 and complete purchases with flexible payment options backed by U.S. Bank.

= Key Features =

* **Seamless Integration** - Adds Avvance as a payment method during checkout
* **Real-time Webhooks** - Automatic order status updates via webhooks
* **Application Resume** - Customers can resume incomplete applications
* **Full Refund Support** - Process full and partial refunds directly from WooCommerce
* **Blocks Checkout Compatible** - Works with both classic and block-based checkouts
* **HPOS Compatible** - Full support for High-Performance Order Storage

= How It Works =

1. Customer selects Avvance at checkout
2. Order is created and customer is directed to U.S. Bank's secure application
3. Customer completes loan application in new window
4. Upon approval, order is automatically completed via webhook
5. Customer returns to store to view order confirmation

= Requirements =

* WooCommerce 5.6.0 or higher
* USD currency
* Valid Avvance merchant account
* SSL certificate (HTTPS)

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Go to Plugins > Add New
3. Search for "Avvance for WooCommerce"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress admin panel
3. Go to Plugins > Add New > Upload Plugin
4. Choose the ZIP file and click "Install Now"
5. Activate the plugin

= Configuration =

1. Go to WooCommerce > Settings > Payments
2. Click on "U.S. Bank Avvance"
3. Enter your API credentials from the Avvance Merchant Portal:
   - Client Key
   - Client Secret
   - Merchant ID
4. Copy the Webhook URL, Username, and Password
5. Contact Avvance Support to register your webhook endpoint
6. Enable the payment method and save changes

== Frequently Asked Questions ==

= What is Avvance? =

Avvance is U.S. Bank's point-of-sale financing solution that allows customers to pay for purchases through flexible installment loans.

= What are the financing limits? =

Customers can apply for financing on purchases between $300 and $25,000.

= What currency is supported? =

Currently, only USD is supported.

= Do I need a merchant account? =

Yes, you need an approved Avvance merchant account. Contact U.S. Bank to apply.

= How do refunds work? =

For authorized transactions: Use the void functionality
For settled transactions: Process full or partial refunds
The plugin automatically determines which method to use based on transaction status.

= What happens if a customer closes the application window? =

The order remains in "pending payment" status. Customers can resume their application from the cart page or by returning to the order.

= How long is the application link valid? =

Application links are valid for 30 days. After 30 days, expired orders are automatically cancelled.

== Screenshots ==

1. Payment method selection at checkout
2. Gateway settings page
3. Webhook configuration
4. Order details with Avvance information
5. Cart resume banner

== Changelog ==

= 1.0.0 - 2025-01-XX =
* Initial release
* Financing initiation API integration
* Webhook support for real-time status updates
* Cart resume functionality
* Full and partial refund support
* Classic and Blocks checkout support
* HPOS compatibility
* 30-day URL expiration handling
* Debug logging

== Upgrade Notice ==

= 1.0.0 =
Initial release of Avvance for WooCommerce.

== Support ==

For plugin support, please contact Avvance Support or visit the support forum.

For Avvance merchant account questions, contact U.S. Bank Avvance directly.