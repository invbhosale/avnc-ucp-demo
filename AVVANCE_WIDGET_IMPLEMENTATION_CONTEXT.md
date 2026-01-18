# Avvance WooCommerce Plugin - Widget Implementation Context Document

This document provides a comprehensive blueprint for implementing promotional/payment messaging widgets in the Avvance WooCommerce plugin, based on analysis of the Affirm Gateway plugin architecture.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture Summary](#architecture-summary)
3. [Widget Placement Locations](#widget-placement-locations)
4. [HTML Structure & Tags](#html-structure--tags)
5. [WooCommerce Hooks Reference](#woocommerce-hooks-reference)
6. [JavaScript Event Handling](#javascript-event-handling)
7. [Admin Settings Structure](#admin-settings-structure)
8. [Implementation Checklist](#implementation-checklist)
9. [Code Templates](#code-templates)

---

## Overview

### Purpose
Avvance needs promotional messaging widgets that display financing/payment options to customers at key touchpoints:
- **Product Pages** - Show "Pay as low as $X/month" under product price
- **Category/Shop Pages** - Show messaging under each product in the grid
- **Cart Page** - Show messaging based on cart total
- **Checkout Page** - Display payment option details when Avvance is selected

### Key Difference from Affirm
- **Affirm**: Uses an external JavaScript SDK (`affirm.js`) that renders widgets based on data attributes
- **Avvance**: Will use server-side API calls to fetch widget content/calculations, then render HTML directly

---

## Architecture Summary

```
┌─────────────────────────────────────────────────────────────────────┐
│                        WIDGET FLOW DIAGRAM                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  [Page Load]                                                        │
│       │                                                             │
│       ▼                                                             │
│  ┌─────────────────┐    ┌──────────────────┐                       │
│  │ PHP Hook Fires  │───▶│ Get Product/Cart │                       │
│  │ (WooCommerce)   │    │     Price        │                       │
│  └─────────────────┘    └────────┬─────────┘                       │
│                                  │                                  │
│                                  ▼                                  │
│                    ┌─────────────────────────┐                     │
│                    │  Call Avvance Widget    │                     │
│                    │  API (if needed) OR     │                     │
│                    │  Calculate Locally      │                     │
│                    └───────────┬─────────────┘                     │
│                                │                                    │
│                                ▼                                    │
│                    ┌─────────────────────────┐                     │
│                    │  Render Widget HTML     │                     │
│                    │  with data attributes   │                     │
│                    └───────────┬─────────────┘                     │
│                                │                                    │
│                                ▼                                    │
│  [User Interaction: Variation Change, Cart Update, etc.]           │
│                                │                                    │
│                                ▼                                    │
│                    ┌─────────────────────────┐                     │
│                    │  JavaScript Listener    │                     │
│                    │  Updates Widget via     │                     │
│                    │  AJAX or DOM Update     │                     │
│                    └─────────────────────────┘                     │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Widget Placement Locations

### 1. Single Product Page

| Location | WooCommerce Hook | Priority | When to Use |
|----------|------------------|----------|-------------|
| After product price | `woocommerce_single_product_summary` | 15 | Default - most common |
| After add to cart button | `woocommerce_after_add_to_cart_form` | 10 | Alternative placement |
| For composite products | `woocommerce_composite_add_to_cart_button` | 1 | WooCommerce Composite Products |

**Visual Placement:**
```
┌─────────────────────────────────────┐
│         [Product Image]             │
├─────────────────────────────────────┤
│  Product Title                      │
│  $299.99                            │
│  ─────────────────────────────      │
│  [AVVANCE WIDGET HERE - Option 1]   │  ◄── After product price (priority 15)
│  ─────────────────────────────      │
│  Short Description                  │
│  [Qty: 1]  [Add to Cart]           │
│  ─────────────────────────────      │
│  [AVVANCE WIDGET HERE - Option 2]   │  ◄── After add to cart
└─────────────────────────────────────┘
```

### 2. Category/Shop Page (Product Grid)

| Location | WooCommerce Hook | Priority |
|----------|------------------|----------|
| After each product in loop | `woocommerce_after_shop_loop_item` | 10 |

**Visual Placement:**
```
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│   [Image]    │  │   [Image]    │  │   [Image]    │
│  Product A   │  │  Product B   │  │  Product C   │
│   $199.99    │  │   $299.99    │  │   $149.99    │
│ [WIDGET]     │  │ [WIDGET]     │  │ [WIDGET]     │  ◄── Under each product
│ [Add to Cart]│  │ [Add to Cart]│  │ [Add to Cart]│
└──────────────┘  └──────────────┘  └──────────────┘
```

### 3. Cart Page

| Location | WooCommerce Hook | Priority |
|----------|------------------|----------|
| After order total row | `woocommerce_cart_totals_after_order_total` | 10 |

**Visual Placement:**
```
┌─────────────────────────────────────┐
│  Cart Totals                        │
├─────────────────────────────────────┤
│  Subtotal           │    $499.99   │
│  Shipping           │     $15.00   │
│  Tax                │     $42.50   │
│  ───────────────────┼──────────────│
│  Total              │    $557.49   │
│                     │ [WIDGET]     │  ◄── Widget appears here
├─────────────────────────────────────┤
│        [Proceed to Checkout]        │
└─────────────────────────────────────┘
```

### 4. Checkout Page

| Location | WooCommerce Hook | Priority |
|----------|------------------|----------|
| After order review | `woocommerce_checkout_after_order_review` | 10 |
| In payment method description | Payment gateway `payment_fields()` method | N/A |

**Visual Placement:**
```
┌─────────────────────────────────────┐
│  Payment Methods                    │
├─────────────────────────────────────┤
│  ○ Credit Card                      │
│  ○ PayPal                           │
│  ● Avvance - Pay Over Time          │
│    ┌─────────────────────────────┐  │
│    │  [INLINE WIDGET/DETAILS]    │  │  ◄── Expanded when selected
│    │  Pay as low as $XX/month    │  │
│    │  [Learn More]               │  │
│    └─────────────────────────────┘  │
├─────────────────────────────────────┤
│        [Place Order]                │
└─────────────────────────────────────┘
```

---

## HTML Structure & Tags

### Standard Widget HTML Structure

```html
<!-- Avvance Promotional Messaging Widget -->
<div id="avvance-widget"
     class="avvance-promo-message"
     data-amount="29999"
     data-page-type="product"
     data-merchant-id="your-merchant-id"
     data-theme="light"
     data-show-logo="true"
     data-show-learn-more="true">
    <!-- Content rendered here by JS or server-side -->
    <span class="avvance-message">
        Or pay as low as <strong>$XX/month</strong> with
        <img src="avvance-logo.svg" alt="Avvance" class="avvance-logo-inline" />
    </span>
    <a href="#" class="avvance-learn-more" data-modal="avvance-info">Learn more</a>
</div>
```

### Data Attributes Reference

| Attribute | Type | Description | Example |
|-----------|------|-------------|---------|
| `data-amount` | Integer | Price in cents | `29999` for $299.99 |
| `data-page-type` | String | Context type | `product`, `category`, `cart`, `checkout` |
| `data-merchant-id` | String | Avvance merchant identifier | `merch_12345` |
| `data-theme` | String | Widget color scheme | `light`, `dark` |
| `data-show-logo` | Boolean | Display Avvance logo | `true`, `false` |
| `data-show-learn-more` | Boolean | Show learn more link | `true`, `false` |
| `data-min-amount` | Integer | Minimum eligible amount (cents) | `5000` ($50) |
| `data-max-amount` | Integer | Maximum eligible amount (cents) | `500000` ($5000) |
| `data-currency` | String | Currency code | `USD`, `CAD` |
| `data-product-id` | Integer | WooCommerce product ID | `123` |
| `data-product-sku` | String | Product SKU | `PROD-001` |

### CSS Classes

```css
/* Container */
.avvance-promo-message { }

/* Message text */
.avvance-message { }
.avvance-message strong { } /* Monthly payment amount */

/* Logo */
.avvance-logo-inline { }

/* Learn more link */
.avvance-learn-more { }

/* Page-specific variants */
.avvance-promo-message--product { }
.avvance-promo-message--category { }
.avvance-promo-message--cart { }
.avvance-promo-message--checkout { }

/* Theme variants */
.avvance-promo-message--light { }
.avvance-promo-message--dark { }

/* States */
.avvance-promo-message--loading { }
.avvance-promo-message--hidden { }
.avvance-promo-message--error { }
```

### Cart Page Specific HTML (Table Row)

```html
<tr class="avvance-cart-promo">
    <th></th>
    <td>
        <div id="avvance-widget-cart"
             class="avvance-promo-message avvance-promo-message--cart"
             data-amount="55749"
             data-page-type="cart">
            <!-- Widget content -->
        </div>
    </td>
</tr>
```

---

## WooCommerce Hooks Reference

### Complete Hook Registration (PHP)

```php
class Avvance_Widget_Manager {

    public function __construct() {
        // Product page widgets
        add_action('woocommerce_single_product_summary',
            array($this, 'render_product_widget_after_price'), 15);
        add_action('woocommerce_after_add_to_cart_form',
            array($this, 'render_product_widget_after_cart'), 10);

        // Category/Shop page widgets
        add_action('woocommerce_after_shop_loop_item',
            array($this, 'render_category_widget'), 10);

        // Cart page widget
        add_action('woocommerce_cart_totals_after_order_total',
            array($this, 'render_cart_widget'), 10);

        // Checkout page
        add_action('woocommerce_checkout_after_order_review',
            array($this, 'render_checkout_widget'), 10);

        // Enqueue scripts/styles
        add_action('wp_enqueue_scripts',
            array($this, 'enqueue_widget_assets'));

        // AJAX handlers for dynamic updates
        add_action('wp_ajax_avvance_calculate_payment',
            array($this, 'ajax_calculate_payment'));
        add_action('wp_ajax_nopriv_avvance_calculate_payment',
            array($this, 'ajax_calculate_payment'));
    }
}
```

### Hook Priority Guide

| Hook | Default Priority | Affirm Uses | Recommended for Avvance |
|------|------------------|-------------|-------------------------|
| `woocommerce_single_product_summary` | 10 | 15 | 15-20 (after price) |
| `woocommerce_after_add_to_cart_form` | 10 | 10 | 10 |
| `woocommerce_after_shop_loop_item` | 10 | 10 | 10 |
| `woocommerce_cart_totals_after_order_total` | 10 | 10 | 10 |
| `woocommerce_checkout_after_order_review` | 10 | 10 | 10 |

---

## JavaScript Event Handling

### Events to Listen For

```javascript
jQuery(document).ready(function($) {

    // ============================================
    // PRODUCT PAGE EVENTS
    // ============================================

    /**
     * Variable product: User selects a variation
     * Fired when variation is found and displayed
     */
    $(document.body).on('found_variation', '.variations_form', function(event, variation) {
        // variation.display_price contains the new price
        updateAvvanceWidget(variation.display_price * 100); // Convert to cents
    });

    /**
     * Variable product: Variation is reset/cleared
     */
    $(document.body).on('reset_data', '.variations_form', function() {
        // Reset to base product price or hide widget
        resetAvvanceWidget();
    });

    /**
     * Quantity change on product page
     */
    $('input.qty').on('change', function() {
        var qty = parseInt($(this).val());
        var price = getBasePrice();
        updateAvvanceWidget(price * qty * 100);
    });

    // ============================================
    // CART PAGE EVENTS
    // ============================================

    /**
     * Cart totals updated (after AJAX cart update)
     */
    $(document.body).on('updated_cart_totals', function() {
        // Re-fetch cart total and update widget
        refreshCartWidget();
    });

    /**
     * Shipping method changed
     */
    $(document.body).on('updated_shipping_method', function() {
        refreshCartWidget();
    });

    /**
     * Cart item quantity changed
     */
    $(document.body).on('updated_wc_div', function() {
        refreshCartWidget();
    });

    // ============================================
    // CHECKOUT PAGE EVENTS
    // ============================================

    /**
     * Payment method selection changed
     */
    $('form.checkout').on('change', 'input[name="payment_method"]', function() {
        if ($(this).val() === 'avvance') {
            showAvvanceCheckoutWidget();
        } else {
            hideAvvanceCheckoutWidget();
        }
    });

    /**
     * Checkout form fields changed (for dynamic total updates)
     */
    $('form.checkout').on('change',
        'input, select',
        debounce(function() {
            if ($('#payment_method_avvance').is(':checked')) {
                updateCheckoutWidget();
            }
        }, 1000)
    );

    /**
     * Order review updated (after coupon, shipping change, etc.)
     */
    $(document.body).on('updated_checkout', function() {
        if ($('#payment_method_avvance').is(':checked')) {
            updateCheckoutWidget();
        }
    });

    // ============================================
    // HELPER FUNCTIONS
    // ============================================

    function updateAvvanceWidget(amountInCents) {
        var $widget = $('#avvance-widget');
        if ($widget.length === 0) return;

        // Update data attribute
        $widget.attr('data-amount', amountInCents);

        // Check min/max thresholds
        var min = parseInt($widget.attr('data-min-amount')) || 5000;
        var max = parseInt($widget.attr('data-max-amount')) || 500000;

        if (amountInCents < min || amountInCents > max) {
            $widget.addClass('avvance-promo-message--hidden');
            return;
        }

        $widget.removeClass('avvance-promo-message--hidden');

        // Option 1: Calculate locally
        var monthlyPayment = calculateMonthlyPayment(amountInCents);
        $widget.find('.avvance-monthly-amount').text('$' + monthlyPayment);

        // Option 2: Fetch from API via AJAX
        // fetchAvvancePaymentInfo(amountInCents);
    }

    function calculateMonthlyPayment(amountInCents) {
        // Simple calculation - replace with actual Avvance formula
        // Example: 12-month term, 0% APR
        var months = 12;
        var monthly = Math.ceil(amountInCents / months) / 100;
        return monthly.toFixed(2);
    }

    function debounce(func, wait) {
        var timeout;
        return function() {
            var context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }
});
```

### AJAX Handler for Dynamic Updates (PHP)

```php
public function ajax_calculate_payment() {
    check_ajax_referer('avvance_widget_nonce', 'nonce');

    $amount = isset($_POST['amount']) ? intval($_POST['amount']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    // Call Avvance API to get payment options
    $response = $this->call_avvance_widget_api($amount);

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }

    wp_send_json_success(array(
        'monthly_payment' => $response['monthly_payment'],
        'term_months' => $response['term_months'],
        'apr' => $response['apr'],
        'html' => $this->render_widget_html($response)
    ));
}
```

---

## Admin Settings Structure

### Settings Fields for Widget Control

```php
$form_fields = array(
    // ==========================================
    // WIDGET DISPLAY SETTINGS SECTION
    // ==========================================
    'widget_settings_title' => array(
        'title' => __('Promotional Messaging Settings', 'avvance'),
        'type'  => 'title',
        'description' => __('Configure where and how Avvance messaging appears on your store.', 'avvance'),
    ),

    // Category/Shop Page Widget
    'category_widget_enabled' => array(
        'title'       => __('Category Page Widget', 'avvance'),
        'label'       => __('Enable promotional messaging on category/shop pages', 'avvance'),
        'type'        => 'checkbox',
        'description' => __('Show "Pay as low as" messaging under each product in shop/category listings.', 'avvance'),
        'default'     => 'yes',
    ),

    // Product Page Widget
    'product_widget_enabled' => array(
        'title'       => __('Product Page Widget', 'avvance'),
        'label'       => __('Enable promotional messaging on product pages', 'avvance'),
        'type'        => 'checkbox',
        'description' => __('Show financing information on individual product pages.', 'avvance'),
        'default'     => 'yes',
    ),

    // Product Widget Position
    'product_widget_position' => array(
        'title'       => __('Product Widget Position', 'avvance'),
        'type'        => 'select',
        'class'       => 'wc-enhanced-select',
        'description' => __('Choose where the widget appears on product pages.', 'avvance'),
        'default'     => 'after_price',
        'options'     => array(
            'after_price'    => __('After product price (Recommended)', 'avvance'),
            'after_add_cart' => __('After Add to Cart button', 'avvance'),
        ),
    ),

    // Cart Page Widget
    'cart_widget_enabled' => array(
        'title'       => __('Cart Page Widget', 'avvance'),
        'label'       => __('Enable promotional messaging on cart page', 'avvance'),
        'type'        => 'checkbox',
        'description' => __('Show financing options based on cart total.', 'avvance'),
        'default'     => 'yes',
    ),

    // Checkout Widget
    'checkout_widget_enabled' => array(
        'title'       => __('Checkout Widget', 'avvance'),
        'label'       => __('Enable detailed messaging on checkout', 'avvance'),
        'type'        => 'checkbox',
        'description' => __('Show payment details when Avvance is selected at checkout.', 'avvance'),
        'default'     => 'yes',
    ),

    // ==========================================
    // WIDGET APPEARANCE SETTINGS
    // ==========================================
    'widget_appearance_title' => array(
        'title' => __('Widget Appearance', 'avvance'),
        'type'  => 'title',
    ),

    // Theme/Color
    'widget_theme' => array(
        'title'       => __('Widget Theme', 'avvance'),
        'type'        => 'select',
        'class'       => 'wc-enhanced-select',
        'description' => __('Color scheme for the promotional messaging.', 'avvance'),
        'default'     => 'light',
        'options'     => array(
            'light' => __('Light (for light backgrounds)', 'avvance'),
            'dark'  => __('Dark (for dark backgrounds)', 'avvance'),
        ),
    ),

    // Show Logo
    'widget_show_logo' => array(
        'title'       => __('Show Avvance Logo', 'avvance'),
        'type'        => 'checkbox',
        'description' => __('Display the Avvance logo in widget messaging.', 'avvance'),
        'default'     => 'yes',
    ),

    // Show Learn More
    'widget_show_learn_more' => array(
        'title'       => __('Show Learn More Link', 'avvance'),
        'type'        => 'checkbox',
        'description' => __('Display a "Learn More" link that opens additional information.', 'avvance'),
        'default'     => 'yes',
    ),

    // ==========================================
    // ELIGIBILITY SETTINGS
    // ==========================================
    'eligibility_title' => array(
        'title' => __('Eligibility Settings', 'avvance'),
        'type'  => 'title',
    ),

    // Minimum Amount
    'min_order_amount' => array(
        'title'       => __('Minimum Order Amount', 'avvance'),
        'type'        => 'number',
        'description' => __('Minimum order amount for Avvance to be available (in dollars).', 'avvance'),
        'default'     => '50',
        'custom_attributes' => array(
            'min'  => '0',
            'step' => '1',
        ),
    ),

    // Maximum Amount
    'max_order_amount' => array(
        'title'       => __('Maximum Order Amount', 'avvance'),
        'type'        => 'number',
        'description' => __('Maximum order amount for Avvance (in dollars).', 'avvance'),
        'default'     => '5000',
        'custom_attributes' => array(
            'min'  => '0',
            'step' => '1',
        ),
    ),
);
```

---

## Implementation Checklist

### Phase 1: Core Widget Infrastructure

- [ ] Create `class-avvance-widget-manager.php`
- [ ] Register all WooCommerce hooks
- [ ] Create base `render_widget()` method
- [ ] Add gateway settings for widget configuration
- [ ] Create widget CSS file (`avvance-widget.css`)
- [ ] Create widget JS file (`avvance-widget.js`)

### Phase 2: Product Page Widget

- [ ] Implement `render_product_widget()` method
- [ ] Handle simple products
- [ ] Handle variable products (price changes on variation select)
- [ ] Handle grouped products (use lowest child price)
- [ ] Add `found_variation` JS event listener
- [ ] Test with various product types

### Phase 3: Category/Shop Page Widget

- [ ] Implement `render_category_widget()` method
- [ ] Ensure unique IDs for multiple widgets on page
- [ ] Handle performance (many products = many widgets)
- [ ] Consider lazy loading for large catalogs

### Phase 4: Cart Page Widget

- [ ] Implement `render_cart_widget()` method
- [ ] Add as table row after totals
- [ ] Handle `updated_cart_totals` event
- [ ] Handle `updated_shipping_method` event
- [ ] Exclude ineligible products (if any)

### Phase 5: Checkout Page Widget

- [ ] Implement `render_checkout_widget()` method
- [ ] Create AJAX endpoint for dynamic updates
- [ ] Handle payment method selection change
- [ ] Handle form field changes (debounced)
- [ ] Handle `updated_checkout` event

### Phase 6: API Integration

- [ ] Create Avvance Widget API client class
- [ ] Implement payment calculation API call
- [ ] Add caching for API responses (transients)
- [ ] Handle API errors gracefully
- [ ] Add fallback for API unavailability

### Phase 7: Testing & Polish

- [ ] Test all widget locations
- [ ] Test dynamic updates (variations, cart changes)
- [ ] Test min/max amount thresholds
- [ ] Test with WooCommerce Blocks (cart/checkout blocks)
- [ ] Cross-browser testing
- [ ] Mobile responsiveness
- [ ] Performance optimization

---

## Code Templates

### Main Widget Manager Class Structure

```php
<?php
/**
 * Avvance Widget Manager
 *
 * Handles rendering and management of promotional messaging widgets
 * across WooCommerce pages.
 *
 * @package Avvance
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Widget_Manager {

    /**
     * Gateway instance
     * @var WC_Gateway_Avvance
     */
    private $gateway;

    /**
     * Constructor
     */
    public function __construct($gateway) {
        $this->gateway = $gateway;
        $this->init_hooks();
    }

    /**
     * Initialize WordPress/WooCommerce hooks
     */
    private function init_hooks() {
        // Only add hooks if gateway is enabled and valid
        if (!$this->gateway->is_enabled() || !$this->gateway->is_valid_for_use()) {
            return;
        }

        // Enqueue assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Product page
        if ($this->gateway->get_option('product_widget_enabled') === 'yes') {
            $position = $this->gateway->get_option('product_widget_position');
            if ($position === 'after_price') {
                add_action('woocommerce_single_product_summary',
                    array($this, 'render_product_widget'), 15);
            } else {
                add_action('woocommerce_after_add_to_cart_form',
                    array($this, 'render_product_widget'), 10);
            }
        }

        // Category page
        if ($this->gateway->get_option('category_widget_enabled') === 'yes') {
            add_action('woocommerce_after_shop_loop_item',
                array($this, 'render_category_widget'), 10);
        }

        // Cart page
        if ($this->gateway->get_option('cart_widget_enabled') === 'yes') {
            add_action('woocommerce_cart_totals_after_order_total',
                array($this, 'render_cart_widget'), 10);
        }

        // Checkout page
        if ($this->gateway->get_option('checkout_widget_enabled') === 'yes') {
            add_action('woocommerce_checkout_after_order_review',
                array($this, 'render_checkout_widget'), 10);
        }

        // AJAX handlers
        add_action('wp_ajax_avvance_get_payment_info', array($this, 'ajax_get_payment_info'));
        add_action('wp_ajax_nopriv_avvance_get_payment_info', array($this, 'ajax_get_payment_info'));
    }

    /**
     * Enqueue widget CSS and JavaScript
     */
    public function enqueue_assets() {
        // Only on relevant pages
        if (!is_product() && !is_cart() && !is_checkout() && !is_shop() && !is_product_category()) {
            return;
        }

        wp_enqueue_style(
            'avvance-widget',
            AVVANCE_PLUGIN_URL . 'assets/css/avvance-widget.css',
            array(),
            AVVANCE_VERSION
        );

        wp_enqueue_script(
            'avvance-widget',
            AVVANCE_PLUGIN_URL . 'assets/js/avvance-widget.js',
            array('jquery'),
            AVVANCE_VERSION,
            true
        );

        // Localize script with settings
        wp_localize_script('avvance-widget', 'avvanceWidgetParams', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('avvance_widget_nonce'),
            'min_amount'  => $this->gateway->get_option('min_order_amount') * 100,
            'max_amount'  => $this->gateway->get_option('max_order_amount') * 100,
            'currency'    => get_woocommerce_currency(),
            'show_logo'   => $this->gateway->get_option('widget_show_logo') === 'yes',
            'show_learn_more' => $this->gateway->get_option('widget_show_learn_more') === 'yes',
            'theme'       => $this->gateway->get_option('widget_theme'),
        ));
    }

    /**
     * Render widget on product page
     */
    public function render_product_widget() {
        global $product;

        if (!$product) {
            return;
        }

        // Check supported product types
        $supported_types = apply_filters('avvance_supported_product_types',
            array('simple', 'variable', 'grouped'));

        if (!$product->is_type($supported_types)) {
            return;
        }

        $price = $product->get_price();

        // For grouped products, get lowest child price
        if ($product->is_type('grouped')) {
            $price = $this->get_grouped_product_lowest_price($product);
        }

        if (!$price) {
            return;
        }

        $this->render_widget($price * 100, 'product', array(
            'product_id'  => $product->get_id(),
            'product_sku' => $product->get_sku(),
        ));
    }

    /**
     * Render widget on category/shop page
     */
    public function render_category_widget() {
        global $product;

        if (!$product) {
            return;
        }

        $price = $product->get_price();
        if (!$price) {
            return;
        }

        $this->render_widget($price * 100, 'category', array(
            'product_id' => $product->get_id(),
        ));
    }

    /**
     * Render widget on cart page
     */
    public function render_cart_widget() {
        // Skip for subscription carts if WooCommerce Subscriptions is active
        if (class_exists('WC_Subscriptions_Cart') && WC_Subscriptions_Cart::cart_contains_subscription()) {
            return;
        }

        $cart_total = WC()->cart->get_total('edit');

        echo '<tr class="avvance-cart-row"><th></th><td>';
        $this->render_widget($cart_total * 100, 'cart');
        echo '</td></tr>';
    }

    /**
     * Render widget on checkout page
     */
    public function render_checkout_widget() {
        // Widget will be populated/shown via JavaScript when Avvance is selected
        echo '<div id="avvance-checkout-widget-container" style="display:none;"></div>';

        // Enqueue checkout-specific JS
        wp_enqueue_script(
            'avvance-checkout-widget',
            AVVANCE_PLUGIN_URL . 'assets/js/avvance-checkout-widget.js',
            array('jquery', 'avvance-widget'),
            AVVANCE_VERSION,
            true
        );
    }

    /**
     * Core widget rendering method
     *
     * @param int    $amount_cents Amount in cents
     * @param string $page_type    Context type (product, category, cart, checkout)
     * @param array  $extra_data   Additional data attributes
     */
    private function render_widget($amount_cents, $page_type, $extra_data = array()) {
        $min = $this->gateway->get_option('min_order_amount') * 100;
        $max = $this->gateway->get_option('max_order_amount') * 100;

        // Only render for cart if within thresholds
        if ($page_type === 'cart' && ($amount_cents < $min || $amount_cents > $max)) {
            return;
        }

        $theme = $this->gateway->get_option('widget_theme');
        $show_logo = $this->gateway->get_option('widget_show_logo') === 'yes';
        $show_learn_more = $this->gateway->get_option('widget_show_learn_more') === 'yes';

        // Calculate monthly payment (replace with API call if needed)
        $monthly_payment = $this->calculate_monthly_payment($amount_cents);

        // Build data attributes
        $data_attrs = array(
            'amount'     => $amount_cents,
            'page-type'  => $page_type,
            'min-amount' => $min,
            'max-amount' => $max,
            'currency'   => get_woocommerce_currency(),
        );

        $data_attrs = array_merge($data_attrs, $extra_data);

        // Generate unique ID for multiple widgets on same page
        $widget_id = 'avvance-widget-' . $page_type;
        if (isset($extra_data['product_id'])) {
            $widget_id .= '-' . $extra_data['product_id'];
        }

        // Build HTML
        $classes = array(
            'avvance-promo-message',
            'avvance-promo-message--' . $page_type,
            'avvance-promo-message--' . $theme,
        );

        ?>
        <div id="<?php echo esc_attr($widget_id); ?>"
             class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             <?php foreach ($data_attrs as $key => $val): ?>
                 data-<?php echo esc_attr($key); ?>="<?php echo esc_attr($val); ?>"
             <?php endforeach; ?>>
            <span class="avvance-message">
                <?php
                printf(
                    /* translators: %s: monthly payment amount */
                    esc_html__('Or pay as low as %s/month with', 'avvance'),
                    '<strong class="avvance-monthly-amount">$' . esc_html($monthly_payment) . '</strong>'
                );
                ?>
                <?php if ($show_logo): ?>
                    <img src="<?php echo esc_url(AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg'); ?>"
                         alt="Avvance"
                         class="avvance-logo-inline" />
                <?php else: ?>
                    <span class="avvance-brand-name">Avvance</span>
                <?php endif; ?>
            </span>
            <?php if ($show_learn_more): ?>
                <a href="#" class="avvance-learn-more" data-amount="<?php echo esc_attr($amount_cents); ?>">
                    <?php esc_html_e('Learn more', 'avvance'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Calculate monthly payment
     *
     * @param int $amount_cents Amount in cents
     * @return string Formatted monthly payment
     */
    private function calculate_monthly_payment($amount_cents) {
        // TODO: Replace with actual Avvance API call or calculation
        // This is a simple example assuming 12 months at 0% APR
        $months = 12;
        $monthly = ceil($amount_cents / $months) / 100;
        return number_format($monthly, 2);
    }

    /**
     * Get lowest price from grouped product children
     */
    private function get_grouped_product_lowest_price($product) {
        $children = array_filter(array_map('wc_get_product', $product->get_children()));

        $prices = array();
        foreach ($children as $child) {
            if ($child && $child->is_purchasable() && $child->get_price()) {
                $prices[] = $child->get_price();
            }
        }

        return !empty($prices) ? min($prices) : 0;
    }

    /**
     * AJAX handler for getting payment info
     */
    public function ajax_get_payment_info() {
        check_ajax_referer('avvance_widget_nonce', 'nonce');

        $amount = isset($_POST['amount']) ? intval($_POST['amount']) : 0;

        if ($amount <= 0) {
            wp_send_json_error(array('message' => 'Invalid amount'));
        }

        // TODO: Call Avvance API for actual payment options
        $monthly_payment = $this->calculate_monthly_payment($amount);

        wp_send_json_success(array(
            'monthly_payment' => $monthly_payment,
            'formatted' => '$' . $monthly_payment . '/month',
        ));
    }
}
```

### JavaScript Template

```javascript
/**
 * Avvance Widget JavaScript
 *
 * Handles dynamic updates to promotional messaging widgets
 */
(function($) {
    'use strict';

    var AvvanceWidget = {

        params: window.avvanceWidgetParams || {},

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // Variable product variation change
            $(document.body).on('found_variation', '.variations_form', function(e, variation) {
                self.updateProductWidget(variation.display_price * 100);
            });

            // Variation reset
            $(document.body).on('reset_data', '.variations_form', function() {
                self.resetProductWidget();
            });

            // Cart totals updated
            $(document.body).on('updated_cart_totals', function() {
                self.refreshCartWidget();
            });

            // Shipping method changed
            $(document.body).on('updated_shipping_method', function() {
                self.refreshCartWidget();
            });

            // Checkout updated
            $(document.body).on('updated_checkout', function() {
                self.refreshCheckoutWidget();
            });

            // Learn more click
            $(document).on('click', '.avvance-learn-more', function(e) {
                e.preventDefault();
                self.openLearnMoreModal($(this).data('amount'));
            });
        },

        updateProductWidget: function(amountCents) {
            var $widget = $('[class*="avvance-promo-message--product"]').first();
            this.updateWidget($widget, amountCents);
        },

        resetProductWidget: function() {
            // Reset to original price from data attribute or hide
            var $widget = $('[class*="avvance-promo-message--product"]').first();
            var originalAmount = $widget.data('original-amount') || $widget.data('amount');
            this.updateWidget($widget, originalAmount);
        },

        refreshCartWidget: function() {
            var $widget = $('[class*="avvance-promo-message--cart"]');
            if ($widget.length === 0) return;

            // Get updated cart total from the page
            var cartTotal = this.getCartTotalFromPage();
            if (cartTotal) {
                this.updateWidget($widget, cartTotal * 100);
            }
        },

        refreshCheckoutWidget: function() {
            // Implementation for checkout updates
        },

        updateWidget: function($widget, amountCents) {
            if ($widget.length === 0) return;

            amountCents = parseInt(amountCents);

            // Check min/max thresholds
            if (amountCents < this.params.min_amount || amountCents > this.params.max_amount) {
                $widget.addClass('avvance-promo-message--hidden');
                return;
            }

            $widget.removeClass('avvance-promo-message--hidden');
            $widget.attr('data-amount', amountCents);

            // Calculate and update monthly payment
            var monthly = this.calculateMonthlyPayment(amountCents);
            $widget.find('.avvance-monthly-amount').text('$' + monthly);
            $widget.find('.avvance-learn-more').data('amount', amountCents);
        },

        calculateMonthlyPayment: function(amountCents) {
            // Simple 12-month calculation - replace with actual logic
            var months = 12;
            var monthly = Math.ceil(amountCents / months) / 100;
            return monthly.toFixed(2);
        },

        getCartTotalFromPage: function() {
            // Try to get cart total from WooCommerce elements
            var $total = $('.cart_totals .order-total .amount').first();
            if ($total.length) {
                var text = $total.text().replace(/[^0-9.]/g, '');
                return parseFloat(text);
            }
            return null;
        },

        openLearnMoreModal: function(amountCents) {
            // TODO: Implement modal with more financing details
            console.log('Learn more clicked for amount:', amountCents);
        }
    };

    $(document).ready(function() {
        AvvanceWidget.init();
    });

})(jQuery);
```

### CSS Template

```css
/**
 * Avvance Widget Styles
 */

/* Base widget styles */
.avvance-promo-message {
    padding: 10px 0;
    font-size: 14px;
    line-height: 1.5;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
}

.avvance-promo-message--hidden {
    display: none !important;
}

/* Message text */
.avvance-message {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.avvance-monthly-amount {
    font-weight: 600;
}

/* Logo */
.avvance-logo-inline {
    height: 18px;
    width: auto;
    vertical-align: middle;
    margin: 0 2px;
}

.avvance-brand-name {
    font-weight: 600;
}

/* Learn more link */
.avvance-learn-more {
    color: inherit;
    text-decoration: underline;
    margin-left: 5px;
}

.avvance-learn-more:hover {
    text-decoration: none;
}

/* Theme: Light */
.avvance-promo-message--light {
    color: #333;
}

.avvance-promo-message--light .avvance-learn-more {
    color: #0066cc;
}

/* Theme: Dark */
.avvance-promo-message--dark {
    color: #fff;
}

.avvance-promo-message--dark .avvance-learn-more {
    color: #66b3ff;
}

/* Page-specific styles */

/* Product page */
.avvance-promo-message--product {
    margin: 10px 0;
}

/* Category/shop page */
.avvance-promo-message--category {
    font-size: 12px;
    justify-content: center;
    text-align: center;
}

/* Cart page */
.avvance-cart-row td {
    padding-top: 15px;
}

.avvance-promo-message--cart {
    justify-content: flex-end;
}

/* Checkout page */
.avvance-promo-message--checkout {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    margin: 10px 0;
}

/* Responsive */
@media (max-width: 768px) {
    .avvance-promo-message {
        font-size: 13px;
    }

    .avvance-promo-message--category {
        font-size: 11px;
    }

    .avvance-logo-inline {
        height: 16px;
    }
}
```

---

## File Structure for Avvance Plugin

```
avvance-woocommerce/
├── assets/
│   ├── css/
│   │   ├── avvance-widget.css          # Widget styles
│   │   ├── avvance-checkout.css        # Checkout-specific styles
│   │   └── avvance-admin.css           # Admin settings styles
│   ├── js/
│   │   ├── avvance-widget.js           # Main widget JS
│   │   ├── avvance-checkout-widget.js  # Checkout-specific JS
│   │   └── avvance-admin.js            # Admin settings JS
│   └── images/
│       ├── avvance-logo.svg            # Brand logo
│       └── avvance-icon.png            # Payment method icon
├── includes/
│   ├── class-wc-gateway-avvance.php    # Main payment gateway
│   ├── class-avvance-widget-manager.php # Widget management
│   ├── class-avvance-api.php           # API client
│   └── class-avvance-blocks.php        # WooCommerce Blocks support
└── avvance-woocommerce.php             # Main plugin file
```

---

## Notes for Development

1. **API Integration**: Replace the simple `calculateMonthlyPayment()` function with actual Avvance API calls once the API documentation is available.

2. **Caching**: Consider caching API responses using WordPress transients to reduce API calls:
   ```php
   $cache_key = 'avvance_payment_' . md5($amount);
   $cached = get_transient($cache_key);
   if ($cached === false) {
       $response = $this->call_api($amount);
       set_transient($cache_key, $response, HOUR_IN_SECONDS);
   }
   ```

3. **WooCommerce Blocks**: The new block-based cart and checkout may require additional integration. Reference the Affirm plugin's `class-wc-affirm-blocks-checkout.php` and `class-wc-affirm-blocks-cart.php` for patterns.

4. **Error Handling**: Always provide graceful fallbacks when the widget cannot be displayed (API error, ineligible amount, etc.).

5. **Accessibility**: Ensure widgets are accessible - proper ARIA labels, keyboard navigation for modals, sufficient color contrast.

---

*Document Version: 1.0*
*Created: Based on analysis of WooCommerce Gateway Affirm v3.0.3*
*For: Avvance WooCommerce Plugin Development*
