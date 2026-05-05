# Avvance WooCommerce Plugin — Webhook & Security Overhaul
## Development Brief for Claude Code

---

## HOW TO USE THIS FILE

Read this entire file before touching any code. It contains:
1. **Codebase context** — what exists and why
2. **Architecture decisions** — what we are building and the reasoning
3. **All phase prompts** — exact instructions for each change
4. **Execution rules** — how to work through phases safely

After reading, confirm understanding by outputting:
- The execution order you will follow
- The two new files you will create
- The three files you will make the most significant changes to

Do not write any code until you have confirmed understanding and received approval to begin Phase 4B.

---

## SECTION 1 — CODEBASE CONTEXT

### Plugin overview
Avvance for WooCommerce (v1.2.0) is U.S. Bank's point-of-sale financing plugin. Merchants install it, configure API credentials, and their customers can apply for installment loans at checkout.

### File inventory (confirmed clean state)
```
avvance-for-woocommerce.php          — main plugin, includes() loads all classes
uninstall.php                         — cleanup on uninstall
includes/
  avvance-functions.php               — helper functions
  class-avvance-api-base.php          — abstract base: OAuth token cache, base_url
  class-avvance-api-client.php        — create_financing_request, void, refund, get_notification_status
  class-avvance-blocks.php            — WooCommerce Blocks integration
  class-avvance-gateway.php           — WC_Gateway_Avvance: settings, process_payment, process_refund, thankyou_page
  class-avvance-order-handler.php     — ajax_manual_status_check, cart resume banner, cleanup cron
  class-avvance-preapproval-api.php   — create_preapproval API call
  class-avvance-preapproval-handler.php — browser fingerprint, DB storage, webhook processing
  class-avvance-price-breakdown-api.php — get_price_breakdown for widget display
  class-avvance-webhooks.php          — validate_basic_auth, route_webhook, process_loan_status_webhook
  class-avvance-widget-handler.php    — render_modal, render_product_widget, AJAX endpoints
```

### Two files that DO NOT EXIST yet (will be created in this overhaul)
- `includes/class-avvance-loan-status-api.php` — Phase 1A
- `includes/class-avvance-setup-handler.php`   — Phase 4A

### Key architecture facts
- `partner_id` is a **merchant-configurable settings field** in `init_form_fields()`. It is read via `$this->get_option('partner_id')` and passed through `get_api_settings()`. It is **never hardcoded** anywhere in this codebase.
- `Avvance_API_Base` constructor accepts: `client_key`, `client_secret`, `merchant_id`, `partner_id`, `environment`.
- `get_api_settings()` in `WC_Gateway_Avvance` returns all five of those keys.
- `avvance_get_gateway()` in `avvance-functions.php` returns the gateway instance safely.

---

## SECTION 2 — ARCHITECTURE DECISIONS

### Decision 1 — Webhook auth: replace Basic Auth with bearer token
**Current:** `validate_basic_auth()` with username/password from settings. Complex password-cleaning fallbacks exist due to corruption issues.
**New:** Merchant pastes a dedicated webhook signing token from Avvance Merchant Portal into a new `webhook_auth_token` settings field. Plugin validates via `Authorization: Bearer <token>` or fallback `X-Avvance-Token` header. Clean, no password mangling.

### Decision 2 — New loan-status endpoint replaces get_notification_status
**Current:** `get_notification_status()` on `Avvance_API_Client` is used in `process_refund()` and `ajax_manual_status_check()` to determine loan state.
**New:** New endpoint `GET /poslp/services/avvance-loan/v1/loan-status` with `PartnerSession-ID` header returns a clean string: `AUTHORIZED`, `SETTLED`, `VOIDED`, `REFUNDED`, `REFUND_IN_PROGRESS`. HTTP 400 "Loan yet to be authorized" = polling signal, not an error. This replaces all `get_notification_status()` usage.

### Decision 3 — Dual verification on AUTHORIZED webhook
**Current:** Webhook AUTHORIZED event → immediately calls `payment_complete()`.
**New:** Webhook AUTHORIZED event → confirm with loan-status API → then `payment_complete()`. Anti-spoofing. If loan-status fails transiently, return 200 to Avvance and add an order note for cron reconciliation (Phase 2, deferred).

### Decision 4 — Hashed MID auto-fetch on settings save
**Current:** Merchant manually enters `hashed_merchant_id` in settings form.
**New:** On settings save, plugin calls `POST /poslp/services/avvance-loan/v1/preapproval/link` (empty body), parses `?id=` from the returned URL, stores it as `hashed_merchant_id` option silently. Removes the manual input field. Webhook URL display becomes dynamic.

### Decision 5 — APPLICATION_LINK_EXPIRED cancels the order
**Current:** Adds an order note only.
**New:** Cancels the WooCommerce order.

### Decision 6 — APPLICATION_DENIED / PARTIALLY_APPROVED keeps order pending
Order stays in pending status so the consumer can retry (e.g., co-applicant). No change from current behavior.

### Phase 2 (deferred — NOT in this overhaul)
Stale order cleanup cron with 15-min polling using loan-status. The existing daily cleanup cron is retained. VOIDED detection in the thank-you page (Phase 5B) will not be fully functional until Phase 2 is built.

---

## SECTION 3 — EXECUTION ORDER

**Critical: run phases in this exact order. Each phase depends on what came before.**

| Step | Phase | Why this order |
|------|-------|----------------|
| 1 | 4B | Creates `webhook_auth_token` field — Phase 3A reads it |
| 2 | 4A | Creates setup handler — no dependencies on 3A/3B |
| 3 | 1A | Creates `Avvance_Loan_Status_API` — Phases 3B and 5A depend on it |
| 4 | 1B | Rewrites `process_refund()` — needs 1A done |
| 5 | 3A | Bearer token auth — needs 4B done |
| 6 | 3B | Loan-status confirmation + LINK_EXPIRED — needs 1A done |
| 7 | 5A | Rewrites `ajax_manual_status_check()` — needs 1A done |
| 8 | 5B | Voided status on thank-you page — no hard dependencies |
| 9 | 6  | Final cleanup — run last |

### Review gate protocol
After completing each phase, before starting the next:
1. Output a summary: which files changed, what specifically changed
2. Confirm no syntax errors, no undefined class references
3. Wait for explicit approval before proceeding

---

## SECTION 4 — PHASE PROMPTS

---

### PHASE 4B — Settings fields cleanup
**File:** `includes/class-avvance-gateway.php`

#### Change 1 — Remove auto-generation block from init_form_fields()
At the top of `init_form_fields()`, remove this entire block:
```php
if ( ! $this->get_option( 'webhook_username' ) ) {
    $credentials = avvance_generate_webhook_credentials();
    $this->update_option( 'webhook_username', $credentials['username'] );
    $this->update_option( 'webhook_password', $credentials['password'] );
}
```

#### Change 2 — Remove three field definitions from form_fields array
Remove `webhook_username`, `webhook_password`, and `hashed_merchant_id` from the `$this->form_fields` array.

**IMPORTANT:** Removing `hashed_merchant_id` from the form display only. Do NOT delete the stored option value or call `$this->update_option('hashed_merchant_id', '')` anywhere. The setup handler (Phase 4A) writes to this option and other code reads it.

#### Change 3 — Add new webhook_auth_token field
Add this field to `form_fields`, positioned after the `partner_id` field:
```php
'webhook_auth_token' => array(
    'title'       => __( 'Webhook Authentication Token', 'avvance-for-woocommerce' ),
    'type'        => 'password',
    'description' => __( 'Your webhook signing token from the Avvance Merchant Portal. Separate from your Client Key and Secret.', 'avvance-for-woocommerce' ),
    'default'     => '',
    'desc_tip'    => true,
),
```

#### Change 4 — Update webhook_title description
Update the `webhook_title` field description to:
```
'Provide your webhook URL to Avvance when configuring your store in the Merchant Portal. Your full webhook URL is generated automatically after saving your API credentials.'
```

#### Change 5 — Dynamic webhook URL in __construct()
After `$this->init_settings()` in `__construct()`, add:
```php
$hashed_mid = $this->get_option( 'hashed_merchant_id' );
if ( ! empty( $hashed_mid ) ) {
    $this->form_fields['webhook_url']['default'] =
        WC()->api_request_url( 'avvance_webhook' ) . '/' . $hashed_mid;
} else {
    $this->form_fields['webhook_url']['default'] =
        WC()->api_request_url( 'avvance_webhook' ) .
        ' (save your credentials to generate full URL)';
}
```

#### Change 6 — Remove static default from webhook_url field
In the `webhook_url` field definition, set `'default' => ''` (empty string). The dynamic value set in `__construct__()` above now controls what displays.

---

### PHASE 4A — Create Avvance_Setup_Handler
**New file:** `includes/class-avvance-setup-handler.php`

Create this file with the following class:

```php
<?php
/**
 * Avvance Setup Handler
 *
 * On settings save, auto-fetches the hashed merchant ID from the
 * preapproval link API and stores it silently.
 *
 * @package Avvance_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Avvance_Setup_Handler {

    public static function init() {
        add_action(
            'woocommerce_update_options_payment_gateways_avvance',
            array( __CLASS__, 'fetch_and_store_hashed_mid' ),
            20
        );
        add_action( 'admin_notices', array( __CLASS__, 'show_admin_notices' ) );
    }

    public static function fetch_and_store_hashed_mid() {
        $gateway = avvance_get_gateway();
        if ( ! $gateway ) {
            avvance_log( 'Setup: gateway not available', 'error' );
            return;
        }

        $client_key    = $gateway->get_option( 'client_key' );
        $client_secret = $gateway->get_option( 'client_secret' );
        $merchant_id   = $gateway->get_option( 'merchant_id' );
        $partner_id    = $gateway->get_option( 'partner_id' );
        $environment   = $gateway->get_option( 'environment' );

        if ( empty( $client_key ) || empty( $client_secret ) || empty( $merchant_id ) ) {
            avvance_log( 'Setup: credentials incomplete, skipping hashed MID fetch' );
            return;
        }

        $base_url = ( 'production' === $environment )
            ? 'https://alpha-api2.usbank.com'
            : 'https://alpha-api.usbank.com';

        // Get OAuth token inline (setup handler is not an API class).
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $auth           = base64_encode( $client_key . ':' . $client_secret );
        $token_response = wp_remote_post(
            $base_url . '/auth/oauth2/v1/token',
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . $auth,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body'    => 'grant_type=client_credentials',
                'timeout' => 30,
            )
        );

        $token_code = wp_remote_retrieve_response_code( $token_response );
        $token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );

        if ( is_wp_error( $token_response ) || 200 !== $token_code || empty( $token_body['accessToken'] ) ) {
            avvance_log( 'Setup: failed to obtain access token for hashed MID fetch', 'error' );
            set_transient( 'avvance_setup_notice', 'hashed_mid_failed', 60 );
            return;
        }

        $token = $token_body['accessToken'];

        // Call preapproval link endpoint with empty POST body.
        $response = wp_remote_post(
            $base_url . '/poslp/services/avvance-loan/v1/preapproval/link',
            array(
                'headers' => array(
                    'Authorization'  => 'Bearer ' . $token,
                    'Content-Type'   => 'application/json',
                    'Correlation-ID' => wp_generate_uuid4(),
                    'Partner-ID'     => $partner_id,
                    'Merchant-ID'    => $merchant_id,
                ),
                'body'    => '',
                'timeout' => 30,
            )
        );

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( is_wp_error( $response ) || 200 !== $code ) {
            $error_msg = $body['error']['message'] ?? 'Unknown error';
            avvance_log( 'Setup: preapproval link API failed (HTTP ' . $code . '): ' . $error_msg, 'error' );
            set_transient( 'avvance_setup_notice', 'hashed_mid_failed', 60 );
            return;
        }

        if ( empty( $body['preApprovalLink'] ) ) {
            avvance_log( 'Setup: preApprovalLink missing from response', 'error' );
            set_transient( 'avvance_setup_notice', 'hashed_mid_failed', 60 );
            return;
        }

        // Parse the ?id= parameter from the returned URL.
        $url    = $body['preApprovalLink'];
        $parsed = parse_url( $url );
        parse_str( $parsed['query'] ?? '', $query_params );
        $hashed_mid = $query_params['id'] ?? '';

        if ( empty( $hashed_mid ) ) {
            avvance_log( 'Setup: could not extract id parameter from preApprovalLink: ' . $url, 'error' );
            set_transient( 'avvance_setup_notice', 'hashed_mid_failed', 60 );
            return;
        }

        $gateway->update_option( 'hashed_merchant_id', $hashed_mid );
        avvance_log( 'Setup: hashed MID retrieved and stored successfully' );
        set_transient( 'avvance_setup_notice', 'hashed_mid_success', 60 );
    }

    public static function show_admin_notices() {
        $notice = get_transient( 'avvance_setup_notice' );
        if ( empty( $notice ) ) {
            return;
        }
        delete_transient( 'avvance_setup_notice' );

        if ( 'hashed_mid_success' === $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>' . esc_html__( 'Avvance', 'avvance-for-woocommerce' ) . '</strong>: ';
            echo esc_html__( 'Configuration updated successfully. Merchant verified.', 'avvance-for-woocommerce' );
            echo '</p></div>';
        }

        if ( 'hashed_mid_failed' === $notice ) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo '<strong>' . esc_html__( 'Avvance', 'avvance-for-woocommerce' ) . '</strong>: ';
            echo esc_html__( 'Could not retrieve merchant configuration. Verify your Client Key, Client Secret, Merchant ID, and Partner ID, then save again.', 'avvance-for-woocommerce' );
            echo '</p></div>';
        }
    }
}
```

**Then in `avvance-for-woocommerce.php`:**

In `includes()`, add after the `class-avvance-api-client.php` line:
```php
require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-setup-handler.php';
```

In `init_hooks()`, add:
```php
Avvance_Setup_Handler::init();
```

---

### PHASE 1A — Create Avvance_Loan_Status_API
**New file:** `includes/class-avvance-loan-status-api.php`

```php
<?php
/**
 * Avvance Loan Status API
 *
 * Retrieves current loan status via the loan-status endpoint.
 * Returns a clean status string on success.
 * Returns WP_Error('loan_not_authorized') when the loan is pending
 * authorization — callers should treat this as a polling signal, not a failure.
 *
 * @package Avvance_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Avvance_Loan_Status_API extends Avvance_API_Base {

    /**
     * Get current loan status for a partner session.
     *
     * @param string $partner_session_id The partnerSessionId from the financing request.
     * @return string|WP_Error Status string on success, WP_Error on failure.
     *   WP_Error code 'loan_not_authorized' means loan is pending — not an error condition.
     */
    public function get_loan_status( $partner_session_id ) {
        avvance_log( 'Getting loan status for partnerSessionId: ' . $partner_session_id );

        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_get(
            $this->base_url . '/poslp/services/avvance-loan/v1/loan-status',
            array(
                'headers' => array(
                    'Authorization'    => 'Bearer ' . $token,
                    'Content-Type'     => 'application/json',
                    'Correlation-ID'   => $this->generate_correlation_id(),
                    'Partner-ID'       => $this->partner_id,
                    'Merchant-ID'      => $this->merchant_id,
                    'PartnerSession-ID' => $partner_session_id,
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            avvance_log( 'Loan status request failed: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $code ) {
            $loan_status = $body['loanStatus'] ?? '';
            avvance_log( 'Loan status retrieved: ' . $loan_status );
            return $loan_status;
        }

        if ( 400 === $code ) {
            $message = $body['error']['message'] ?? '';
            if ( false !== stripos( $message, 'Loan yet to be authorized' ) ) {
                avvance_log( 'Loan not yet authorized for session: ' . $partner_session_id );
                return new WP_Error(
                    'loan_not_authorized',
                    'Loan pending authorization',
                    array( 'status' => 400 )
                );
            }
            avvance_log( 'Loan status 400 error: ' . $message, 'error' );
            return new WP_Error(
                'loan_status_failed',
                $message,
                array( 'status' => 400 )
            );
        }

        avvance_log( 'Loan status unexpected response code: ' . $code, 'error' );
        return new WP_Error(
            'loan_status_failed',
            'Unexpected response code: ' . $code,
            array( 'status' => $code )
        );
    }
}
```

**Then in `avvance-for-woocommerce.php`:**

In `includes()`, add after `class-avvance-api-client.php` and BEFORE `class-avvance-webhooks.php`:
```php
require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-loan-status-api.php';
```

The final includes() order for these three lines must be:
```php
require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-api-client.php';
require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-setup-handler.php';
require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-loan-status-api.php';
require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-gateway.php';
require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-webhooks.php';
```

---

### PHASE 1B — Rewrite process_refund()
**File:** `includes/class-avvance-gateway.php`

Replace the entire `process_refund()` method body. Keep the method signature unchanged:
```php
public function process_refund( $order_id, $amount = null, $reason = '' )
```

New body:
```php
avvance_log( '=== REFUND PROCESS STARTED ===' );
avvance_log( 'Order ID: ' . $order_id );
avvance_log( 'Refund Amount: ' . ( $amount ? $amount : 'FULL' ) );

$order = wc_get_order( $order_id );
if ( ! $order ) {
    avvance_log( 'ERROR: Order #' . $order_id . ' not found', 'error' );
    return new WP_Error( 'invalid_order', __( 'Invalid order', 'avvance-for-woocommerce' ) );
}

$partner_session_id = $order->get_meta( '_avvance_partner_session_id' );
if ( empty( $partner_session_id ) ) {
    avvance_log( 'ERROR: Missing Avvance partner session ID on order #' . $order_id, 'error' );
    return new WP_Error( 'missing_session', __( 'Avvance session ID not found', 'avvance-for-woocommerce' ) );
}

avvance_log( 'Partner Session ID: ' . $partner_session_id );

// Check current loan status via the loan-status API.
$loan_api = new Avvance_Loan_Status_API( $this->get_api_settings() );
$status   = $loan_api->get_loan_status( $partner_session_id );

if ( is_wp_error( $status ) ) {
    if ( 'loan_not_authorized' === $status->get_error_code() ) {
        avvance_log( 'Refund blocked: loan not yet authorized for order #' . $order_id, 'error' );
        return new WP_Error(
            'loan_not_authorized',
            __( 'Loan has not been authorized yet and cannot be refunded or voided.', 'avvance-for-woocommerce' )
        );
    }
    avvance_log( 'Refund blocked: loan-status API error: ' . $status->get_error_message(), 'error' );
    return $status;
}

avvance_log( 'Loan status for refund decision: ' . $status );

switch ( $status ) {
    case 'AUTHORIZED':
        avvance_log( 'Decision: VOID (transaction is authorized but not settled)' );
        $api_client = new Avvance_API_Client( $this->get_api_settings() );
        $result     = $api_client->void_transaction( $partner_session_id );
        $action     = 'void';
        break;

    case 'SETTLED':
    case 'REFUNDED':
        $refund_amount = $amount ? floatval( $amount ) : floatval( $order->get_total() );
        avvance_log( 'Decision: REFUND, amount: ' . $refund_amount );
        $api_client = new Avvance_API_Client( $this->get_api_settings() );
        $result     = $api_client->refund_transaction( $partner_session_id, $refund_amount );
        $action     = 'refund';
        break;

    case 'REFUND_IN_PROGRESS':
        avvance_log( 'Refund already in progress for order #' . $order_id, 'warning' );
        return new WP_Error(
            'refund_in_progress',
            __( 'A refund is already in progress. Please wait for it to settle before processing another.', 'avvance-for-woocommerce' )
        );

    case 'VOIDED':
        avvance_log( 'Cannot refund voided loan for order #' . $order_id, 'error' );
        return new WP_Error(
            'already_voided',
            __( 'This loan has already been voided. No further action is possible.', 'avvance-for-woocommerce' )
        );

    default:
        avvance_log( 'Cannot process refund for unexpected status: ' . $status, 'error' );
        return new WP_Error(
            'unexpected_status',
            sprintf(
                /* translators: %s: current loan status */
                __( 'Cannot process refund. Current loan status is: %s', 'avvance-for-woocommerce' ),
                $status
            )
        );
}

if ( is_wp_error( $result ) ) {
    avvance_log( 'ERROR: ' . $action . ' API call failed: ' . $result->get_error_message(), 'error' );
    avvance_log( '=== REFUND PROCESS FAILED ===' );
    return $result;
}

$note = sprintf(
    /* translators: %s: action type (refund or void) */
    __( 'Avvance %s processed successfully.', 'avvance-for-woocommerce' ),
    $action
);
if ( 'refund' === $action ) {
    $note .= ' ' . sprintf(
        /* translators: %s: refund amount */
        __( 'Amount: %s', 'avvance-for-woocommerce' ),
        wc_price( $refund_amount )
    );
}
$order->add_order_note( $note );

avvance_log( '=== REFUND PROCESS COMPLETED SUCCESSFULLY ===' );
return true;
```

---

### PHASE 3A — Replace Basic Auth with bearer token
**File:** `includes/class-avvance-webhooks.php`

**PREREQUISITE:** Phase 4B must be applied — `webhook_auth_token` option must exist in settings.

#### Change 1 — Replace validate_basic_auth() with validate_webhook_token()

Delete the entire `validate_basic_auth()` method and replace with:

```php
/**
 * Validate webhook bearer token authentication.
 *
 * Checks Authorization: Bearer <token> header first,
 * then falls back to X-Avvance-Token custom header.
 *
 * @return bool True if token is valid, false otherwise.
 */
private static function validate_webhook_token() {
    $gateway = avvance_get_gateway();
    if ( ! $gateway ) {
        avvance_log( 'Webhook auth: gateway not available', 'error' );
        return false;
    }

    $expected_token = trim( $gateway->get_option( 'webhook_auth_token' ) );
    if ( empty( $expected_token ) ) {
        avvance_log( 'Webhook auth: webhook_auth_token not configured in plugin settings', 'error' );
        return false;
    }

    $provided_token = '';

    // Attempt A: Authorization: Bearer <token> via $_SERVER.
    $auth_header = '';
    if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
        $auth_header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    } elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
        $auth_header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }

    if ( ! empty( $auth_header ) && str_starts_with( trim( $auth_header ), 'Bearer ' ) ) {
        $provided_token = trim( substr( trim( $auth_header ), 7 ) );
        avvance_log( 'Webhook auth: using Authorization Bearer header' );
    }

    // Attempt B: X-Avvance-Token custom header via $_SERVER.
    if ( empty( $provided_token ) && ! empty( $_SERVER['HTTP_X_AVVANCE_TOKEN'] ) ) {
        $provided_token = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AVVANCE_TOKEN'] ) ) );
        avvance_log( 'Webhook auth: using X-Avvance-Token header' );
    }

    // Attempt C: getallheaders() case-insensitive fallback.
    if ( empty( $provided_token ) && function_exists( 'getallheaders' ) ) {
        foreach ( getallheaders() as $key => $value ) {
            $key_lower = strtolower( $key );
            if ( 'authorization' === $key_lower && str_starts_with( trim( $value ), 'Bearer ' ) ) {
                $provided_token = trim( substr( trim( $value ), 7 ) );
                avvance_log( 'Webhook auth: using Authorization header via getallheaders()' );
                break;
            }
            if ( 'x-avvance-token' === $key_lower && ! empty( $value ) ) {
                $provided_token = trim( $value );
                avvance_log( 'Webhook auth: using X-Avvance-Token via getallheaders()' );
                break;
            }
        }
    }

    if ( empty( $provided_token ) ) {
        avvance_log( 'Webhook auth: no Authorization Bearer or X-Avvance-Token header found', 'error' );
        return false;
    }

    if ( ! hash_equals( $expected_token, $provided_token ) ) {
        avvance_log( 'Webhook auth: token mismatch', 'error' );
        avvance_log( 'Provided token length: ' . strlen( $provided_token ) );
        avvance_log( 'Expected token length: ' . strlen( $expected_token ) );
        return false;
    }

    avvance_log( 'Webhook authentication successful' );
    return true;
}
```

#### Change 2 — Update handle_webhook() to call the new method

In `handle_webhook()`, replace:
```php
if ( ! self::validate_basic_auth() ) {
```
with:
```php
if ( ! self::validate_webhook_token() ) {
```

Keep the surrounding 405/401 error response logic unchanged.

---

### PHASE 3B — Loan-status confirmation + registration flag + APPLICATION_LINK_EXPIRED
**File:** `includes/class-avvance-webhooks.php`

**PREREQUISITE:** Phase 1A (`Avvance_Loan_Status_API`) must exist.

#### Change 1 — Registration flag in handle_webhook()

In `handle_webhook()`, after the `validate_webhook_token()` check passes and before parsing the raw payload, add:

```php
// Record that the webhook endpoint has been confirmed active.
if ( get_option( 'avvance_webhook_status' ) !== 'active' ) {
    update_option( 'avvance_webhook_status', 'active' );
    avvance_log( 'Webhook endpoint confirmed active - first webhook received' );
}
```

#### Change 2 — Replace AUTHORIZED case in process_loan_status_webhook()

Find the `case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':` block in `process_loan_status_webhook()`.
**Replace its entire contents** (everything from after the `case` line up to and including `break;`) with:

```php
avvance_log( 'Processing AUTHORIZED status for order #' . $order_id );

$partner_session_id = $order->get_meta( '_avvance_partner_session_id' );

if ( empty( $partner_session_id ) ) {
    avvance_log( 'AUTHORIZED webhook: missing partnerSessionId on order #' . $order_id, 'error' );
    $order->add_order_note(
        __( 'Avvance payment authorization received but partnerSessionId missing. Pending reconciliation.', 'avvance-for-woocommerce' )
    );
    $order->save();
    break;
}

$gateway          = avvance_get_gateway();
$loan_status_api  = new Avvance_Loan_Status_API(
    array(
        'client_key'    => $gateway->get_option( 'client_key' ),
        'client_secret' => $gateway->get_option( 'client_secret' ),
        'merchant_id'   => $gateway->get_option( 'merchant_id' ),
        'partner_id'    => $gateway->get_option( 'partner_id' ),
        'environment'   => $gateway->get_option( 'environment' ),
    )
);

$confirmed_status = $loan_status_api->get_loan_status( $partner_session_id );

if ( is_wp_error( $confirmed_status ) ) {
    avvance_log(
        'AUTHORIZED webhook: loan-status confirmation failed: ' . $confirmed_status->get_error_message(),
        'warning'
    );
    $order->add_order_note(
        __( 'Avvance payment authorization received but could not be confirmed via loan-status API. Pending reconciliation.', 'avvance-for-woocommerce' )
    );
    $order->save();
    // Return 200 to Avvance — cron will reconcile.
    break;
}

if ( 'AUTHORIZED' !== $confirmed_status ) {
    avvance_log(
        'AUTHORIZED webhook: loan-status mismatch. Webhook claimed AUTHORIZED but API returned: ' . $confirmed_status,
        'error'
    );
    $order->add_order_note(
        sprintf(
            /* translators: %s: actual loan status from API */
            __( 'Avvance webhook claimed AUTHORIZED but loan-status returned: %s. Order not marked paid.', 'avvance-for-woocommerce' ),
            $confirmed_status
        )
    );
    $order->save();
    break;
}

// Confirmed AUTHORIZED — safe to mark paid.
$payment_transaction_id = $event_details['paymentTransactionId'] ?? '';
$approval_code          = $event_details['approvalCode'] ?? '';

if ( $payment_transaction_id ) {
    $order->update_meta_data( '_avvance_payment_transaction_id', $payment_transaction_id );
}
if ( $approval_code ) {
    $order->update_meta_data( '_avvance_approval_code', $approval_code );
}
if ( isset( $event_details['loanSummary'] ) ) {
    $order->update_meta_data( '_avvance_loan_summary', $event_details['loanSummary'] );
}

$order->payment_complete( $payment_transaction_id );
$order->add_order_note(
    sprintf(
        /* translators: %s: payment transaction ID */
        __( 'Avvance payment authorized and confirmed via loan-status API. Transaction ID: %s', 'avvance-for-woocommerce' ),
        $payment_transaction_id ? $payment_transaction_id : 'N/A'
    )
);

if ( WC()->session ) {
    WC()->session->__unset( 'avvance_pending_order_id' );
}

avvance_log( 'Order #' . $order_id . ' marked as paid - loan-status confirmed AUTHORIZED' );
```

#### Change 3 — Replace APPLICATION_LINK_EXPIRED case

Find the existing `case 'APPLICATION_LINK_EXPIRED':` in `process_loan_status_webhook()`.
**Replace the entire case** with:

```php
case 'APPLICATION_LINK_EXPIRED':
    avvance_log( 'Processing APPLICATION_LINK_EXPIRED for order #' . $order_id );
    if ( ! $order->is_paid() ) {
        $order->update_status(
            'cancelled',
            __( 'Avvance application link expired (webhook notification received).', 'avvance-for-woocommerce' )
        );
    }
    avvance_log( 'Order #' . $order_id . ' cancelled due to expired application link' );
    break;
```

---

### PHASE 5A — Rewrite ajax_manual_status_check()
**File:** `includes/class-avvance-order-handler.php`

**PREREQUISITE:** Phase 1A (`Avvance_Loan_Status_API`) must exist.

Keep the following unchanged at the top of the method:
- The `$order_id` and `$nonce` extraction from `$_POST`
- The `wp_verify_nonce()` check and error response
- The `wc_get_order()` call and not-found error response
- The `$order->is_paid()` check and redirect to order received URL

Replace everything after the "already paid" check with:

```php
$partner_session_id = $order->get_meta( '_avvance_partner_session_id' );

if ( empty( $partner_session_id ) ) {
    avvance_log( 'Manual status check: missing partnerSessionId on order #' . $order_id, 'error' );
    wp_send_json_error( array( 'message' => __( 'Application ID not found.', 'avvance-for-woocommerce' ) ) );
}

$gateway = avvance_get_gateway();
if ( ! $gateway ) {
    wp_send_json_error( array( 'message' => __( 'Payment gateway not available.', 'avvance-for-woocommerce' ) ) );
}

$api = new Avvance_Loan_Status_API(
    array(
        'client_key'    => $gateway->get_option( 'client_key' ),
        'client_secret' => $gateway->get_option( 'client_secret' ),
        'merchant_id'   => $gateway->get_option( 'merchant_id' ),
        'partner_id'    => $gateway->get_option( 'partner_id' ),
        'environment'   => $gateway->get_option( 'environment' ),
    )
);

avvance_log( 'Manual status check: calling loan-status for order #' . $order_id );

$status = $api->get_loan_status( $partner_session_id );

if ( is_wp_error( $status ) ) {
    if ( 'loan_not_authorized' === $status->get_error_code() ) {
        avvance_log( 'Manual status check: loan not yet authorized for order #' . $order_id );
        wp_send_json_success(
            array(
                'pending' => true,
                'status'  => 'not_yet_authorized',
                'message' => __( 'Your application is still being processed.', 'avvance-for-woocommerce' ),
            )
        );
    }
    avvance_log( 'Manual status check API error: ' . $status->get_error_message(), 'error' );
    wp_send_json_error( array( 'message' => __( 'Unable to check status. Please try again.', 'avvance-for-woocommerce' ) ) );
}

avvance_log( 'Manual status check: loan status is ' . $status . ' for order #' . $order_id );

switch ( $status ) {
    case 'AUTHORIZED':
    case 'SETTLED':
        $order->payment_complete();
        $order->add_order_note(
            sprintf(
                /* translators: %s: loan status string */
                __( 'Payment confirmed via manual status check. Loan status: %s', 'avvance-for-woocommerce' ),
                $status
            )
        );
        wp_send_json_success( array( 'redirect' => $order->get_checkout_order_received_url() ) );
        break;

    case 'VOIDED':
        $order->update_status(
            'cancelled',
            __( 'Loan voided - confirmed via manual status check.', 'avvance-for-woocommerce' )
        );
        wp_send_json_error(
            array( 'message' => __( 'Your financing application was voided. Please return to cart and select a different payment method.', 'avvance-for-woocommerce' ) )
        );
        break;

    case 'REFUNDED':
    case 'REFUND_IN_PROGRESS':
        wp_send_json_success( array( 'redirect' => $order->get_checkout_order_received_url() ) );
        break;

    default:
        $order->add_order_note(
            sprintf(
                /* translators: %s: loan status string */
                __( 'Manual status check: loan status is %s - still pending.', 'avvance-for-woocommerce' ),
                $status
            )
        );
        wp_send_json_success(
            array(
                'pending' => true,
                'status'  => $status,
                'message' => __( 'Your application is still being processed.', 'avvance-for-woocommerce' ),
            )
        );
        break;
}
```

Remove all references to `Avvance_API_Client`, `get_notification_status()`, and the old `$response['eventDetails']['loanStatus']['status']` parsing from this method.

---

### PHASE 5B — Voided status on thank-you page
**File:** `includes/class-avvance-gateway.php`

**Note:** Full VOIDED detection requires Phase 2 (deferred reconciliation cron). This phase adds the infrastructure now for future readiness.

#### Change 1 — ajax_check_order_status()

The current method already returns `avvance_status`. No change needed to the PHP. Verify it returns `avvance_status` from `_avvance_last_webhook_status` order meta in the JSON response. If it does not, add:

```php
$avvance_status = $order->get_meta( '_avvance_last_webhook_status' );
```

And include it in `wp_send_json_success()`:
```php
array(
    'status'         => $status,
    'avvance_status' => $avvance_status ? $avvance_status : '',
)
```

#### Change 2 — thankyou_page() JavaScript

In the polling JavaScript inside `thankyou_page()`, add a VOIDED branch. After the existing `cancelled` handler block, add:

```javascript
} else if (response.data.status === 'cancelled' &&
           response.data.avvance_status === 'VOIDED') {
    clearInterval(statusInterval);
    $('#avvance-status').html('<?php echo esc_js( __( 'Your financing application was voided. Redirecting to cart...', 'avvance-for-woocommerce' ) ); ?>');
    setTimeout(function() {
        window.location = '<?php echo esc_js( wc_get_cart_url() ); ?>';
    }, 4000);
}
```

---

### PHASE 6 — Final cleanup
**Files:** `includes/avvance-functions.php`, `includes/class-avvance-widget-handler.php`

#### Change 1 — avvance-functions.php: delete avvance_generate_webhook_credentials()

Delete the entire `avvance_generate_webhook_credentials()` function. It is no longer called anywhere after Phases 4A and 4B are applied.

#### Change 2 — class-avvance-widget-handler.php: remove hashed_mid from render_modal()

In `render_modal()`:

a. Remove this line:
```php
$hashed_mid = $gateway ? $gateway->get_option( 'hashed_merchant_id' ) : '';
```

b. Remove the `data-hashed-mid` attribute from the `avvance-qualify-button` element:
```php
data-hashed-mid="<?php echo esc_attr( $hashed_mid ); ?>"
```

The JS `avvance-qualify-button` handler reads `session_id` from the widget element and posts to `avvance_create_preapproval` AJAX, which reads `hashed_merchant_id` from the database directly. The frontend `data-hashed-mid` attribute is unused and safe to remove.

#### Change 3 — Final verification grep

Search all PHP files for these strings and confirm zero remaining references:
- `webhook_username` — expected: zero (removed in 4B, validate_basic_auth deleted in 3A)
- `webhook_password` — expected: zero (same)
- `avvance_generate_webhook_credentials` — expected: zero (function deleted above)
- `validate_basic_auth` — expected: zero (replaced in 3A)

If any remain, identify the file and line and remove the reference.

#### Change 4 — Confirm option key consistency

Verify these option keys are referenced consistently across all files:

| Option key | Written by | Read by |
|---|---|---|
| `hashed_merchant_id` | setup handler (4A) | preapproval-handler, widget-handler, gateway __construct |
| `webhook_auth_token` | merchant via settings UI | webhooks validate_webhook_token (3A) |
| `avvance_webhook_status` | webhooks handle_webhook (3B) | (future admin UI) |
| `partner_id` | merchant via settings UI | all API classes via get_api_settings() |

---

## SECTION 5 — WHAT IS NOT CHANGING

These areas are explicitly out of scope for this overhaul. Do not modify them:

- `class-avvance-preapproval-handler.php` — no changes needed
- `class-avvance-preapproval-api.php` — no changes needed
- `class-avvance-price-breakdown-api.php` — no changes needed
- `class-avvance-blocks.php` — no changes needed
- `uninstall.php` — no changes needed
- `assets/` directory — no changes needed
- `blocks/` directory — no changes needed
- The daily cleanup cron in `class-avvance-order-handler.php` — retained as-is
- Pre-approval webhook processing in `class-avvance-webhooks.php` — unchanged
- The `is_preapproval_webhook()` routing logic — unchanged

---

## SECTION 6 — REVIEW CHECKLIST (run after all phases complete)

- [ ] `class-avvance-loan-status-api.php` exists and extends `Avvance_API_Base`
- [ ] `class-avvance-setup-handler.php` exists and is registered in `init_hooks()`
- [ ] `includes()` order: api-client → setup-handler → loan-status-api → gateway → webhooks
- [ ] `webhook_auth_token` field exists in settings form
- [ ] `webhook_username`, `webhook_password`, `hashed_merchant_id` removed from form_fields
- [ ] `validate_basic_auth()` deleted, `validate_webhook_token()` exists
- [ ] `process_refund()` uses `Avvance_Loan_Status_API` not `get_notification_status()`
- [ ] `ajax_manual_status_check()` uses `Avvance_Loan_Status_API` not `get_notification_status()`
- [ ] AUTHORIZED webhook case calls loan-status API before `payment_complete()`
- [ ] APPLICATION_LINK_EXPIRED cancels the order
- [ ] `avvance_generate_webhook_credentials()` deleted from avvance-functions.php
- [ ] `data-hashed-mid` removed from qualify button in render_modal()
- [ ] Zero grep results for `webhook_username`, `webhook_password`, `validate_basic_auth`, `avvance_generate_webhook_credentials`
