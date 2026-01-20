# Avvance WooCommerce Plugin - AI Coding Guidelines

## Architecture Overview

This is a WooCommerce payment gateway plugin for U.S. Bank Avvance point-of-sale financing. The plugin integrates as a payment method and provides promotional widgets showing financing options.

**Core Components:**
- `class-avvance-gateway.php` - Main WooCommerce payment gateway extending `WC_Payment_Gateway`
- `class-avvance-api-client.php` - Handles OAuth authentication and API calls to Avvance
- `class-avvance-widget-handler.php` - Renders promotional widgets on product/cart/checkout pages
- `class-avvance-preapproval-handler.php` - Manages pre-approval requests using browser fingerprinting
- `class-avvance-webhooks.php` - Processes real-time webhook updates from Avvance
- `class-avvance-order-handler.php` - Handles order status updates and application resume
- `class-avvance-blocks.php` - WooCommerce Blocks integration for checkout compatibility

## Key Patterns & Conventions

### Initialization Pattern
All handler classes use static `init()` methods that register hooks during `plugins_loaded`:

```php
class Avvance_Some_Handler {
    public static function init() {
        add_action('some_hook', [__CLASS__, 'some_method']);
        // Register AJAX endpoints
        add_action('wp_ajax_some_action', [__CLASS__, 'ajax_method']);
    }
}
```

### AJAX Endpoints
AJAX handlers follow this pattern with nonce verification:

```php
public static function ajax_some_action() {
    if (!wp_verify_nonce($_POST['nonce'], 'avvance_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    // Process request
    wp_send_json_success($data);
}
```

### Widget Rendering
Widgets use WooCommerce hooks for placement and data attributes for JavaScript interaction:

```php
add_action('woocommerce_single_product_summary', [__CLASS__, 'render_product_widget'], 25);
```

HTML structure with data attributes:
```html
<div class="avvance-promo-message" data-amount="29999" data-page-type="product">
    <!-- Content -->
</div>
```

### Database Integration
Custom table `wp_avvance_preapprovals` stores pre-approval data by browser fingerprint:

```php
global $wpdb;
$table_name = $wpdb->prefix . 'avvance_preapprovals';
$record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE browser_fingerprint = %s", $fingerprint));
```

### API Client Pattern
OAuth tokens are cached using WordPress transients:

```php
$cache_key = 'avvance_token_' . md5($this->client_key);
$token = get_transient($cache_key);
if (!$token) {
    // Fetch new token and cache
    set_transient($cache_key, $token, $ttl);
}
```

### Logging
Use the custom `avvance_log()` function for debug logging:

```php
avvance_log('Message', 'info'); // info, warning, error
```

## WooCommerce Integration Points

- **Payment Gateway**: Registered via `woocommerce_payment_gateways` filter
- **Widget Hooks**: `woocommerce_single_product_summary`, `woocommerce_after_cart_table`, `woocommerce_review_order_before_payment`
- **Blocks Support**: Extends `AbstractPaymentMethodType` for checkout blocks
- **HPOS Compatibility**: Declared in main plugin file
- **Order Status**: Custom statuses like `wc-avvance-pending`, `wc-avvance-approved`

## File Structure

- `includes/class-avvance-*.php` - Core classes
- `assets/css/js/` - Frontend assets
- `blocks/build/` - Compiled blocks integration
- `avvance-for-woocommerce.php` - Main plugin file with initialization

## Development Workflow

1. **Testing**: Use sandbox environment, test with small amounts
2. **Webhooks**: Register webhook endpoint in Avvance Merchant Portal with generated credentials
3. **Assets**: Enqueue CSS/JS only on relevant pages (`is_product()`, `is_cart()`, `is_checkout()`)
4. **Security**: Always verify nonces on AJAX calls, sanitize input data
5. **Browser Fingerprinting**: Used for cross-session pre-approval tracking via cookies and database

## Common Tasks

- **Add new widget location**: Hook into appropriate WooCommerce action with priority
- **Add API endpoint**: Extend `Avvance_API_Client` with new method using cached OAuth token
- **Add admin setting**: Add to `init_form_fields()` array in gateway class
- **Handle webhook**: Add case to `process_webhook()` in webhooks class
- **Update widget content**: Modify render methods in widget handler, consider AJAX updates

## Key Reference Files

- `AVVANCE_WIDGET_IMPLEMENTATION_CONTEXT.md` - Detailed widget implementation guide
- `readme.txt` - Plugin documentation and setup instructions
- `includes/avvance-functions.php` - Utility functions and helpers</content>
<parameter name="filePath">c:\Users\vaibh\Downloads\From demo store\avvance-woocommerce\.github\copilot-instructions.md