# Avvance for WooCommerce — Claude Reference

This file captures the full context of the plugin, its architecture, and all significant work done during AI-assisted development sessions. Use it as a starting point for any new Claude conversation.

---

## Plugin Overview

**Plugin Name:** Avvance for WooCommerce
**Version:** 1.2.0
**Text Domain:** `avvance-for-woocommerce`
**Description:** U.S. Bank point-of-sale installment financing integrated as a WooCommerce payment gateway. Customers apply for financing at checkout; the merchant gets paid while the customer repays U.S. Bank Avvance in installments.

---

## Repository & Branch Strategy

- **Repo location:** `c:/Users/vaibh/Downloads/From demo store/avvance-woocommerce/`
- **`main`** — production-ready branch
- **`dev`** — active development branch (always work here first)
- Workflow: commit to `dev` → merge to `main` via `git merge dev --no-ff`
- PHPCS must pass before merging. Run: `/c/xampp/php/php.exe /c/Users/vaibh/AppData/Roaming/Composer/vendor/bin/phpcs <file> --standard=WordPress`
- Auto-fix line endings: `/c/xampp/php/php.exe /c/Users/vaibh/AppData/Roaming/Composer/vendor/bin/phpcbf <file> --standard=WordPress`

---

## File Structure

```
avvance-for-woocommerce.php          Main plugin file, constants, init
includes/
  avvance-functions.php              Helper functions (avvance_log, avvance_get_gateway, etc.)
  class-avvance-gateway.php          WC_Payment_Gateway subclass — core payment logic
  class-avvance-api-base.php         Abstract base for all API classes (OAuth, token cache)
  class-avvance-api-client.php       Main API client (financing requests, refunds, voids)
  class-avvance-price-breakdown-api.php  Price breakdown / loan options API
  class-avvance-preapproval-api.php  Pre-approval onboarding API
  class-avvance-preapproval-handler.php  Browser fingerprint & pre-approval DB logic
  class-avvance-widget-handler.php   Widget rendering across product/cart/checkout pages
  class-avvance-webhooks.php         Incoming webhook processing (loan status, pre-approval)
  class-avvance-order-handler.php    Order status management, manual status check
  class-avvance-blocks.php           WooCommerce Blocks checkout integration
assets/
  css/avvance-widget.css             Widget styles
  css/avvance-checkout.css           Checkout / order-received page styles
  js/avvance-widget.js               Widget JS (modals, price breakdown, pre-approval flow)
  images/avvance-logo.svg            Full horizontal logo (used in widgets/modals)
  images/avvance-icon.svg            Small icon (used as WC gateway icon on checkout)
```

---

## Plugin Constants

```php
AVVANCE_VERSION      // '1.2.0'
AVVANCE_PLUGIN_FILE  // Full path to main plugin file
AVVANCE_PLUGIN_PATH  // Directory path (trailing slash)
AVVANCE_PLUGIN_URL   // URL (trailing slash)
```

---

## Gateway Settings (WooCommerce → Payments → U.S. Bank Avvance)

| Setting | Key | Notes |
|---|---|---|
| Enable/Disable | `enabled` | |
| Environment | `environment` | `sandbox` or `production` |
| Client Key | `client_key` | OAuth client key |
| Client Secret | `client_secret` | OAuth client secret |
| Merchant ID | `merchant_id` | Elavon MID |
| Partner ID | `partner_id` | e.g. `CONVERGE` — was hardcoded, now configurable |
| Hashed Merchant ID | `hashed_merchant_id` | Used for pre-approval widget onboarding |
| Category Widget | `category_widget_enabled` | |
| Product Widget | `product_widget_enabled` | |
| Product Widget Position | `product_widget_position` | `after_price`, `after_add_cart`, `both` |
| Cart Widget | `cart_widget_enabled` | |
| Checkout Widget | `checkout_widget_enabled` | |
| Widget Theme | `widget_theme` | `light` or `dark` |
| Show Logo | `widget_show_logo` | |
| Min Order Amount | `min_order_amount` | Default $300 |
| Max Order Amount | `max_order_amount` | Default $25,000 |
| Webhook URL | `webhook_url` | Read-only, give to Avvance Support |
| Webhook Username | `webhook_username` | Basic Auth for webhooks |
| Webhook Password | `webhook_password` | Basic Auth for webhooks |
| Debug Mode | `debug_mode` | Logs to WooCommerce logs |

---

## API Architecture

### Base Class (`Avvance_API_Base`)
All API classes extend this. It handles:
- OAuth token fetch + caching (WordPress transients, cache key = `avvance_token_{md5(client_key)}`)
- Environment-based URLs: sandbox = `https://alpha-api.usbank.com`, production = `https://alpha-api2.usbank.com`
- Properties: `$client_key`, `$client_secret`, `$merchant_id`, `$partner_id`, `$environment`, `$base_url`

### Settings Array Pattern
All API classes receive settings via array:
```php
array(
    'client_key'    => $gateway->get_option('client_key'),
    'client_secret' => $gateway->get_option('client_secret'),
    'merchant_id'   => $gateway->get_option('merchant_id'),
    'partner_id'    => $gateway->get_option('partner_id'),
    'environment'   => $gateway->get_option('environment'),
)
```
`get_api_settings()` in the gateway returns this array. Widget handler builds it manually.

---

## API Response Structures

### Price Breakdown API — NEW format (3 offer types)
```json
{
  "offers": [
    { "apr": 0, "paymentAmount": 61.75, "termInMonths": 36, "offerType": "PROMO",
      "promotionApr": 0, "promotionTermInMonths": 12, "promotionPaymentAmount": 166.67 },
    { "apr": 0, "paymentAmount": 55.56, "termInMonths": 36, "offerType": "ZERO" },
    { "apr": 6.99, "paymentAmount": 61.75, "termInMonths": 36, "offerType": "APR" }
  ]
}
```
- **ZERO**: 0% APR for full term
- **PROMO**: Promotional 0% period, then regular APR
- **APR**: Standard APR offer
- Widget inline preference order: ZERO > PROMO > APR
- Field is `paymentAmount` (not `monthlyPaymentAmount`)
- JS has backward compatibility for old flat array format

### Price Breakdown API — OLD format (still supported)
```json
[{ "apr": 0, "monthlyPaymentAmount": 183.89 }, { "apr": 8.99, "monthlyPaymentAmount": 105.24 }]
```

### Notification Status API
- Header `notificationId` expects the `partnerSessionId` (NOT `applicationGUID`)
- `partnerSessionId` stored in order meta as `_avvance_partner_session_id`
- `applicationGUID` stored as `_avvance_application_guid` (used for order notes, not API lookups)

---

## Order Meta Keys

| Meta Key | Description |
|---|---|
| `_avvance_application_guid` | Application GUID from financing request |
| `_avvance_partner_session_id` | Partner session ID (used for notification-status API) |
| `_avvance_consumer_url` | Avvance onboarding URL for the customer |
| `_avvance_url_created_at` | Unix timestamp when URL was created (expires after 30 days) |
| `_avvance_last_webhook_status` | Last received webhook loan status |

---

## Payment Flow

### Classic Checkout (non-Blocks)
1. Customer selects Avvance → clicks Place Order
2. `process_payment()` calls `create_financing_request()` → stores `_avvance_consumer_url` on order
3. Redirects to **order-received / thank-you page**
4. `thankyou_page()` opens Avvance URL in new popup window
5. JS polls `avvance_check_order_status` AJAX every 5 seconds
6. On approval webhook → order completed → page reloads to show confirmation
7. On decline webhook → order stays `pending` → JS detects `avvance_status` → redirects to order-pay page

### Blocks Checkout
1. `process_payment()` detects blocks via `is_blocks_checkout()`
2. Returns redirect directly to `consumerOnboardingURL` (full-page redirect)

### `is_blocks_checkout()` Logic
- Returns `false` on `order-pay` page (always classic form)
- Returns `true` if `wc-avvance-payment-token` POST param or `has_block('woocommerce/checkout')`

### Order-Pay Page (Retry Flow)
- After decline, consumer is redirected to `$order->get_checkout_payment_url()`
- `is_available()` checks order total (not cart total) on order-pay page
- Avvance shows as available payment method for retry
- The payment title shows "Pay over time with U.S. Bank Avvance Learn more" (same as checkout)
- The `avvance-icon.svg` icon shows in its default WC position

---

## Webhook Handling

File: `class-avvance-webhooks.php`

| Status | Action |
|---|---|
| `APPLICATION_APPROVED` | Adds order note |
| `APPLICATION_STARTED` | Adds order note |
| `APPLICATION_PENDING_REQUIRE_CUSTOMER_ACTION` | Adds order note |
| `APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT` | **Keep order pending** (NOT cancelled) — stores in `_avvance_last_webhook_status` |
| `APPLICATION_PARTIALLY_APPROVED` | **Keep order pending** — stores in `_avvance_last_webhook_status` |
| `SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT` | **Keep order pending** — stores in `_avvance_last_webhook_status` |
| `INVOICE_PAYMENT_TRANSACTION_AUTHORIZED` | Mark order as processing |
| `INVOICE_PAYMENT_TRANSACTION_SETTLED` | Mark order as completed |

**Key decision:** Declined/error orders stay `pending` so the consumer or a spouse can retry with Avvance or use a different payment method. The JS polling detects the declined `avvance_status` and redirects to the order-pay page.

---

## Widget States (Inline CTA)

1. **No 0% APR available:** "As low as $XXX.XX/month with [logo] Check your spending power"
2. **0% APR available:** "0% APR or as low as $XXX.XX/month with [logo] Check your spending power"
3. **Pre-approved:** "You're pre-approved! As low as $XXX.XX/month with [logo] See your details"

---

## Modals

### Pre-approval Modal (`#avvance-preapproval-modal`)
Opened by "Check your spending power" CTA.
- Loan calculator with editable amount + "Calculate monthly payments" button
- Loan cards rendered dynamically by JS from price breakdown API
- 3-step slider: "How to get pre-approved"
- "See if you qualify" button (uses `hashed_merchant_id`) opens Avvance onboarding

### Pre-approved Details Modal (`#avvance-preapproved-details-modal`)
Opened by "See your details" CTA (pre-approved customers only).
- Success banner with spending power amount + expiry date
- Same loan calculator and cards
- 3-step slider: "How to checkout"
- "Continue shopping" button closes modal

---

## CSS Classes Reference

| Class | Purpose |
|---|---|
| `.avvance-modal-dialog` | Modal container (replaces old `.avvance-modal-content`) |
| `.avvance-loan-card` | Individual loan option card |
| `.avvance-card-badge` | Badge on loan card |
| `.avvance-card-row` | Row inside loan card |
| `.avvance-monthly-price` | Monthly price display |
| `.avvance-input-group` | Calculator input wrapper |
| `.avvance-currency-input` | Editable amount input |
| `.avvance-calc-btn` | Calculate button |
| `.avvance-slider-section` | How-to slider section |
| `.avvance-slide` | Individual slide |
| `.avvance-step-number` | Step number circle |
| `.avvance-dot` | Slider navigation dot |
| `.avvance-btn-primary` | Primary CTA button |
| `.avvance-success-banner` | Pre-approved success banner |
| `.avvance-pay-for-order` | Added to `.woocommerce-order` on thank-you page |

**Design tokens:** Blue `#235AE4`, Navy `#001E79`, Gray bg `#F5F5FA`, Badge bg `#EEF6FF`

---

## Order-Received / Thank-You Page Customisations

- Title changed from "Order received" → "Pay for order" for pending Avvance orders (via `woocommerce_endpoint_order-received_title` filter)
- "Thank you. Your order has been received." text removed (via `woocommerce_thankyou_order_received_text` filter)
- `.avvance-pay-for-order` class injected via inline JS for CSS targeting
- Horizontal order summary card layout (Affirm-style) via `avvance-checkout.css`

---

## Known Issues & Fixes Applied

### `esc_js()` corrupts URLs (FIXED)
`esc_js()` converts `&` to `&amp;`, breaking the `?pay_for_order=true&key=...` URL used in JS redirects. **Fix:** Use `wp_json_encode()` for URLs in inline JS:
```php
window.location = <?php echo wp_json_encode( $order->get_checkout_payment_url() ); ?>;
```

### WooCommerce Blocks cart — `woocommerce_before_cart` hook doesn't fire
Cart restoration via hooks doesn't work with the Blocks cart. **Fix:** Abandoned cart restoration entirely; use order-pay page redirect instead (same approach as Affirm plugin).

### `is_blocks_checkout()` returns true on order-pay page
`has_block('woocommerce/checkout')` returns true even on the order-pay endpoint (same checkout post). **Fix:** Return `false` early when `is_wc_endpoint_url('order-pay')`.

### Category widget "Unable to start pre-approval" error
Three jQuery selectors in `avvance-widget.js` were missing `.avvance-category-widget`, so `sessionId` was undefined. **Fix:** Added `.avvance-category-widget` to all selectors.

---

## AJAX Endpoints

| Action | Auth | Description |
|---|---|---|
| `avvance_check_order_status` | None (GET, order_id validated) | Polling from thank-you page |
| `avvance_manual_status_check` | Nonce | Manual status check button |
| `avvance_get_price_breakdown` | None (public) | Widget price breakdown |
| `avvance_check_preapproval` | None (public) | Widget pre-approval check |

---

## Coding Standards

- WordPress coding standards (PHPCS `--standard=WordPress`)
- All files must use Unix `\n` line endings (CRLF causes PHPCS errors on Windows)
- Text domain: `avvance-for-woocommerce`
- Customer-facing brand name: **"U.S. Bank Avvance"** (not just "Avvance")
- No `routingKey` header in any API calls (removed)
- Use `wp_json_encode()` not `json_encode()`
- Nonce verification required on all state-changing AJAX; polling AJAX (read-only) may omit with `phpcs:ignore` comment

---

## Commit History (Key Features)

| Commit | Description |
|---|---|
| `ec5369b` | Merge: Partner ID configurable setting |
| `4a17af8` | feat: Make Partner ID configurable in plugin settings |
| `fcd20cb` | Merge: decline/retry flow and order-pay UX fixes |
| `5fdec79` | feat: Add decline/retry flow and fix order-pay page UX |
| `8156549` | feat: Redesign post-checkout UX and fix category widget selector |
| `38e800a` | style: Fix remaining PHPCS issues |
| `151e6ff` | chore: Bump plugin version to 1.2.0 |
| `d03cfbe` | refactor: Remove UCP handler from main branch |
