=== Avvance for WooCommerce ===
Contributors: usbankavvance
Tags: payments, financing, installment, bnpl, avvance
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offer customers flexible installment financing through U.S. Bank Avvance at checkout.

== Description ==

U.S. Bank Avvance for WooCommerce enables you to offer point-of-sale financing to your customers. With Avvance, customers can apply for installment loans ranging from $300 to $25,000 and complete purchases with flexible payment options backed by U.S. Bank.

= Key Features =

* **Seamless Integration** - Adds Avvance as a payment method during checkout
* **Pre-Approval / "Check Your Spending Power"** - Customers can check their eligibility and see personalized loan offers before completing a purchase, from widgets on product, cart, category, checkout, and empty-cart pages
* **Real-time Webhooks with Automatic Reconciliation** - Order status updates via webhooks, backed by a scheduled background job that resolves orders even if a webhook is missed or delayed
* **Application Resume** - Customers can resume incomplete applications
* **Full Refund Support** - Process full and partial refunds directly from WooCommerce
* **Blocks Checkout & Blocks Cart Compatible** - Works with classic and block-based checkout and cart
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
   - Partner ID
4. Copy the Webhook URL and Authentication Token
5. Contact Avvance Support to register your webhook endpoint with that URL and token
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

The order remains in "pending payment" status. Customers can resume or retry their application by returning to the order (via the "Pay for order" link in their account, an order-status email, or the cart page on classic-cart stores).

= How long is the application link valid? =

Application links are valid for 30 days. After 30 days, expired orders are automatically cancelled.

== Screenshots ==

1. Payment method selection at checkout
2. Gateway settings page
3. Webhook configuration
4. Order details with Avvance information
5. Cart resume banner

== Changelog ==

= 1.4.0 =
* Refactored inline payment messaging widgets across product, category,
  cart, checkout, and empty-cart ("New in store") pages
* Added Pre-Approval / "Check your spending power" flow with dedicated
  modals, live status polling, and personalized offers for pre-approved
  customers
* Added always-visible checkout banner above payment methods
* Unified modal trigger system using data-modal attributes
* Added automatic cart/checkout widget refresh (including WooCommerce
  Cart block support) when quantities or totals change, hiding the
  widget when the order total falls outside the configured range
* Enforced hard minimum/maximum order amount validation ($300-$25,000)
  on the gateway settings page, including a check that the minimum
  stays below the maximum
* Simplified the payment method display everywhere to "Pay over time
  with [Avvance logo]", removing the separate marketing description
  and "Learn more" link
* Removed the "Show Avvance Logo" toggle - the logo is now always shown
* Switched webhook authentication from Basic Auth to HMAC-SHA256, with
  a deprecated Bearer-token fallback for backward compatibility
* Added a scheduled hourly reconciliation job (via Action Scheduler)
  that automatically resolves pending orders if a webhook is missed
  or fails to process
* Added automatic cleanup of expired, unused pre-approval records
* Fixed a bug that could crash the site when another WooCommerce
  payment gateway plugin was also active
* Various security and reliability fixes (webhook payload logging,
  PII handling, credential validation URL consistency)

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