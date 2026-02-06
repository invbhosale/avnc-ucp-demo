<?php
/**
 * Avvance Widget Handler - COMPLETE WITH CRITICAL FEATURES
 * 
 * NEW FEATURES:
 * 1. Category/shop page widgets
 * 2. Checkout page widget
 * 3. Enhanced admin settings support
 * 4. Multiple widget positions
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Widget_Handler {
    
    private static $settings = array();
    private static $gateway = null;
    
    public static function init() {
        self::$gateway = avvance_get_gateway();
        self::load_settings();
        self::register_hooks();
    }
    
    /**
     * Load widget settings from gateway options
     */
    private static function load_settings() {
        if (!self::$gateway) {
            return;
        }
        
        self::$settings = array(
            'category_enabled'    => self::$gateway->get_option('category_widget_enabled', 'yes') === 'yes',
            'product_enabled'     => self::$gateway->get_option('product_widget_enabled', 'yes') === 'yes',
            'product_position'    => self::$gateway->get_option('product_widget_position', 'after_price'),
            'cart_enabled'        => self::$gateway->get_option('cart_widget_enabled', 'yes') === 'yes',
            'checkout_enabled'    => self::$gateway->get_option('checkout_widget_enabled', 'yes') === 'yes',
            'theme'               => self::$gateway->get_option('widget_theme', 'light'),
            'show_logo'           => self::$gateway->get_option('widget_show_logo', 'yes') === 'yes',
            'min_amount'          => floatval(self::$gateway->get_option('min_order_amount', 300)),
            'max_amount'          => floatval(self::$gateway->get_option('max_order_amount', 25000)),
        );
    }
    
    /**
     * Register all WordPress/WooCommerce hooks
     */
    private static function register_hooks() {
        // Check if gateway is enabled
        if (!self::$gateway || self::$gateway->enabled !== 'yes') {
            avvance_log('Widget hooks not registered: Gateway not enabled');
            return;
        }
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_widget_scripts']);
        
        // Category/shop page widget
        if (self::$settings['category_enabled']) {
            add_action('woocommerce_after_shop_loop_item', [__CLASS__, 'render_category_widget'], 10);
        }
        
        // Product page widget (based on position setting)
        if (self::$settings['product_enabled']) {
            $position = self::$settings['product_position'];
            
            if ($position === 'after_price' || $position === 'both') {
                add_action('woocommerce_single_product_summary', [__CLASS__, 'render_product_widget'], 15);
            }
            
            if ($position === 'after_add_cart' || $position === 'both') {
                add_action('woocommerce_after_add_to_cart_form', [__CLASS__, 'render_product_widget_after_cart'], 10);
            }
        }
        
        // Cart page widget (multiple hooks for compatibility)
        if (self::$settings['cart_enabled']) {
            add_action('woocommerce_before_cart_collaterals', [__CLASS__, 'render_cart_widget'], 10);
            add_action('woocommerce_after_cart_table', [__CLASS__, 'render_cart_widget_fallback'], 10);
            add_action('woocommerce_cart_collaterals', [__CLASS__, 'render_cart_widget_fallback2'], 5);
        }
        
        // Checkout page widget
        if (self::$settings['checkout_enabled']) {
            add_action('woocommerce_review_order_before_payment', [__CLASS__, 'render_checkout_widget'], 10);
        }

        // Ensure modal is rendered on cart/checkout pages (for WooCommerce Blocks compatibility)
        add_action('wp_footer', [__CLASS__, 'ensure_modal_rendered'], 10);

        // AJAX endpoints
        add_action('wp_ajax_avvance_get_price_breakdown', [__CLASS__, 'ajax_get_price_breakdown']);
        add_action('wp_ajax_nopriv_avvance_get_price_breakdown', [__CLASS__, 'ajax_get_price_breakdown']);
        
        add_action('wp_ajax_avvance_check_preapproval', [__CLASS__, 'ajax_check_preapproval']);
        add_action('wp_ajax_nopriv_avvance_check_preapproval', [__CLASS__, 'ajax_check_preapproval']);
    }
    
    /**
     * Generate unique session ID for tracking
     */
    private static function generate_session_id() {
        return 'avv_' . uniqid() . '_' . time();
    }
    
    /**
     * Get current pre-approval data from database
     */
    private static function get_current_preapproval() {
        $fingerprint = self::get_browser_fingerprint();
        
        if (!$fingerprint) {
            return null;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';
        
        $record = $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$table_name}
             WHERE browser_fingerprint = %s
             ORDER BY created_at DESC
             LIMIT 1",
            $fingerprint
        ), ARRAY_A);
        
        return $record;
    }
    
    /**
     * Get browser fingerprint from cookie
     */
    private static function get_browser_fingerprint() {
        $cookie_name = 'avvance_browser_id';
        
        if (isset($_COOKIE[$cookie_name])) {
            return sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
        }
        
        return null;
    }
    
    /**
     * Calculate monthly payment (simple calculation - replace with API call if needed)
     */
    private static function calculate_monthly_payment($amount) {
        // Simple 6-month calculation
        // TODO: Replace with actual Avvance API call if available
        $months = 6;
        $monthly = ceil(($amount * 100) / $months) / 100;
        return number_format($monthly, 2);
    }
    
    /**
     * Enqueue widget scripts and styles
     */
    public static function enqueue_widget_scripts() {
        // Only on relevant pages
        if (!is_product() && !is_cart() && !is_checkout() && !is_shop() && !is_product_category() && !is_product_tag()) {
            return;
        }
        
        wp_enqueue_style(
            'avvance-widget',
            AVVANCE_PLUGIN_URL . 'assets/css/avvance-widget.css',
            [],
            AVVANCE_VERSION
        );
        
        wp_enqueue_script(
            'avvance-widget',
            AVVANCE_PLUGIN_URL . 'assets/js/avvance-widget.js',
            ['jquery'],
            AVVANCE_VERSION,
            true
        );
        
        wp_localize_script('avvance-widget', 'avvanceWidget', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('avvance_preapproval'),
            'checkInterval' => 3000,
            'logoUrl' => AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg',
            'minAmount' => self::$settings['min_amount'],
            'maxAmount' => self::$settings['max_amount'],
            'isProductPage' => is_product(),
            'isCartPage' => is_cart(),
            'isCheckoutPage' => is_checkout(),
        ]);
    }
    
    /**
     * AJAX: Get price breakdown
     */
    public static function ajax_get_price_breakdown() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public-facing AJAX for price display, no state change
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        
        if ($amount < 300 || $amount > 25000) {
            wp_send_json_error(['message' => 'Amount must be between $300 and $25,000']);
        }
        
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            wp_send_json_error(['message' => 'Gateway not available']);
        }
        
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-price-breakdown-api.php';
        
        $api = new Avvance_Price_Breakdown_API([
            'client_key' => $gateway->get_option('client_key'),
            'client_secret' => $gateway->get_option('client_secret'),
            'merchant_id' => $gateway->get_option('merchant_id'),
            'environment' => $gateway->get_option('environment')
        ]);
        
        $response = $api->get_price_breakdown($amount);

        if (is_wp_error($response)) {
            avvance_log('Price breakdown AJAX error: ' . $response->get_error_message(), 'error');
            wp_send_json_error(['message' => 'Unable to get price breakdown']);
        }

        wp_send_json_success($response);
    }
    
    /**
     * AJAX: Check pre-approval status
     *
     * Lead status values (only 2 possible):
     * - PRE_APPROVED: Customer is pre-approved with max amount
     * - NOT_APPROVED: Customer is declined
     */
    public static function ajax_check_preapproval() {
        $preapproval = self::get_current_preapproval();

        if (!$preapproval) {
            wp_send_json_success([
                'has_preapproval' => false,
                'status' => 'none',
                'message' => 'Check your spending power'
            ]);
        }

        $status = $preapproval['status'] ?? 'pending';

        // Only PRE_APPROVED is considered approved (NOT_APPROVED is declined)
        if ($status !== 'PRE_APPROVED') {
            // For NOT_APPROVED or pending, show the default CTA
            wp_send_json_success([
                'has_preapproval' => false,
                'status' => $status,
                'message' => 'Check your spending power'
            ]);
        }

        // PRE_APPROVED - check for valid max amount
        $has_valid_amount = isset($preapproval['max_amount']) && floatval($preapproval['max_amount']) > 0;

        if (!$has_valid_amount) {
            wp_send_json_success([
                'has_preapproval' => false,
                'status' => $status,
                'message' => 'Check your spending power'
            ]);
        }

        // Check if expired
        if (!empty($preapproval['expiry_date'])) {
            $expiry = strtotime($preapproval['expiry_date']);
            if ($expiry && $expiry < time()) {
                wp_send_json_success([
                    'has_preapproval' => false,
                    'status' => 'expired',
                    'message' => 'Check your spending power'
                ]);
            }
        }

        $max_amount = number_format($preapproval['max_amount'], 0);

        wp_send_json_success([
            'has_preapproval' => true,
            'status' => 'PRE_APPROVED',
            'max_amount' => $preapproval['max_amount'],
            'max_amount_formatted' => $max_amount,
            'message' => "You're preapproved for up to $" . $max_amount
        ]);
    }
    
    /**
     * Render widget on category/shop page
     */
    public static function render_category_widget() {
        global $product;
        
        if (!$product || !$product->get_price()) {
            return;
        }
        
        $price = $product->get_price();
        
        // Check min/max
        if ($price < self::$settings['min_amount'] || $price > self::$settings['max_amount']) {
            return;
        }
        
        $monthly = self::calculate_monthly_payment($price);
        $widget_id = 'avvance-category-widget-' . $product->get_id();
        
        ?>
        <div id="<?php echo esc_attr($widget_id); ?>" 
             class="avvance-category-widget avvance-widget-<?php echo esc_attr(self::$settings['theme']); ?>"
             data-amount="<?php echo esc_attr($price); ?>"
             data-product-id="<?php echo esc_attr($product->get_id()); ?>">
            <span class="avvance-message-small">
                Or <strong>$<?php echo esc_html($monthly); ?>/mo</strong> with
                <?php if (self::$settings['show_logo']): ?>
                    <img src="<?php echo esc_url(AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg'); ?>" 
                         alt="Avvance" class="avvance-logo-small">
                <?php else: ?>
                    <span class="avvance-brand">Avvance</span>
                <?php endif; ?>
            </span>
        </div>
        <?php
    }
    
    /**
     * Render widget on product page (after price)
     */
    public static function render_product_widget() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $price = $product->get_price();
        
        // For variable products, get the base price or range
        if ($product->is_type('variable')) {
            $prices = $product->get_variation_prices(true);
            if (!empty($prices['price'])) {
                $price = min($prices['price']);
            }
        }
        
        // For grouped products, get lowest child price
        if ($product->is_type('grouped')) {
            $price = self::get_grouped_product_lowest_price($product);
        }
        
        if (!$price || $price < self::$settings['min_amount'] || $price > self::$settings['max_amount']) {
            // Render placeholder for variable products (JS will update)
            if ($product->is_type('variable')) {
                self::render_product_widget_placeholder($product);
            }
            return;
        }
        
        $session_id = self::generate_session_id();
        $preapproval = self::get_current_preapproval();
        
        self::render_widget($price, $preapproval, $session_id, 'product', [
            'product_id' => $product->get_id(),
            'product_type' => $product->get_type(),
        ]);
        
        // Render modal (only once per page)
        static $modal_rendered = false;
        if (!$modal_rendered) {
            self::render_modal();
            $modal_rendered = true;
        }
    }
    
    /**
     * Render widget after add to cart button
     */
    public static function render_product_widget_after_cart() {
        // Same as render_product_widget but in different position
        self::render_product_widget();
    }
    
    /**
     * Render placeholder for variable products (will be updated by JS)
     */
    private static function render_product_widget_placeholder($product) {
        $session_id = self::generate_session_id();
        ?>
        <div id="avvance-product-widget" 
             class="avvance-product-widget avvance-widget-<?php echo esc_attr(self::$settings['theme']); ?>"
             data-amount="0"
             data-session-id="<?php echo esc_attr($session_id); ?>"
             data-product-id="<?php echo esc_attr($product->get_id()); ?>"
             data-product-type="variable"
             data-min-amount="<?php echo esc_attr(self::$settings['min_amount']); ?>"
             data-max-amount="<?php echo esc_attr(self::$settings['max_amount']); ?>"
             style="display: none;">
            <div class="avvance-widget-content">
                <div class="avvance-price-message"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Get lowest price from grouped product children
     */
    private static function get_grouped_product_lowest_price($product) {
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
     * Render widget on cart page (primary hook)
     */
    public static function render_cart_widget() {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        
        self::render_cart_widget_internal();
        $rendered = true;
    }
    
    public static function render_cart_widget_fallback() {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        
        self::render_cart_widget_internal();
        $rendered = true;
    }
    
    public static function render_cart_widget_fallback2() {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        
        self::render_cart_widget_internal();
        $rendered = true;
    }
    
    private static function render_cart_widget_internal() {
        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        
        $total = WC()->cart->get_total('');
        
        if ($total < self::$settings['min_amount'] || $total > self::$settings['max_amount']) {
            return;
        }
        
        $session_id = self::generate_session_id();
        $preapproval = self::get_current_preapproval();
        
        echo '<div class="avvance-cart-widget-container" style="margin: 20px 0;">';
        self::render_widget($total, $preapproval, $session_id, 'cart');
        echo '</div>';
        
        static $modal_rendered = false;
        if (!$modal_rendered) {
            self::render_modal();
            $modal_rendered = true;
        }
    }
    
    /**
     * Render widget on checkout page
     */
    public static function render_checkout_widget() {
        if (!WC()->cart) {
            return;
        }
        
        $total = WC()->cart->get_total('');
        
        if ($total < self::$settings['min_amount'] || $total > self::$settings['max_amount']) {
            return;
        }
        
        $session_id = self::generate_session_id();
        $preapproval = self::get_current_preapproval();
        
        ?>
        <div id="avvance-checkout-widget-container" style="display: none; margin: 20px 0;">
            <?php
            // Only PRE_APPROVED status is considered approved (NOT_APPROVED is declined)
            $is_preapproved = $preapproval && $preapproval['status'] === 'PRE_APPROVED';
            $has_valid_amount = $is_preapproved && isset($preapproval['max_amount']) && floatval($preapproval['max_amount']) > 0;
            $is_expired = false;

            if ($is_preapproved && !empty($preapproval['expiry_date'])) {
                $expiry = strtotime($preapproval['expiry_date']);
                $is_expired = ($expiry && $expiry < time());
            }
            ?>

            <?php if ($is_preapproved && $has_valid_amount && !$is_expired): ?>
                <div class="avvance-preapproved-banner">
                    <div class="avvance-checkmark">✓</div>
                    <div class="avvance-banner-content">
                        <strong>You're preapproved for up to $<?php echo number_format($preapproval['max_amount'], 0); ?></strong>
                        <p>Complete your purchase with flexible monthly payments from U.S. Bank</p>
                    </div>
                </div>
            <?php else: ?>
                <?php self::render_checkout_standard_message($total, $session_id); ?>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(function($) {
            // Show/hide widget based on payment method selection
            function updateAvvanceCheckoutWidget() {
                if ($('input[name="payment_method"]:checked').val() === 'avvance') {
                    $('#avvance-checkout-widget-container').slideDown(300);
                } else {
                    $('#avvance-checkout-widget-container').slideUp(300);
                }
            }
            
            // On payment method change
            $('form.checkout').on('change', 'input[name="payment_method"]', updateAvvanceCheckoutWidget);
            
            // On page load
            updateAvvanceCheckoutWidget();
            
            // After AJAX checkout update
            $(document.body).on('updated_checkout', updateAvvanceCheckoutWidget);
        });
        </script>
        <?php
        
        static $modal_rendered = false;
        if (!$modal_rendered) {
            self::render_modal();
            $modal_rendered = true;
        }
    }
    
    /**
     * Render standard checkout message (no pre-approval)
     */
    private static function render_checkout_standard_message($total, $session_id) {
        $monthly = self::calculate_monthly_payment($total);
        ?>
        <div class="avvance-checkout-message">
            <div class="avvance-checkout-header">
                <?php if (self::$settings['show_logo']): ?>
                    <img src="<?php echo esc_url(AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg'); ?>" 
                         alt="U.S. Bank Avvance" class="avvance-logo-checkout">
                <?php else: ?>
                    <span class="avvance-brand-large">Avvance</span>
                <?php endif; ?>
            </div>
            <p class="avvance-checkout-tagline">
                Pay as low as <strong>$<?php echo esc_html($monthly); ?>/month</strong> with flexible installment financing
            </p>
            <a href="#" class="avvance-prequal-link-checkout" data-session-id="<?php echo esc_attr($session_id); ?>">
                Check if you prequalify →
            </a>
        </div>
        <?php
    }
    
    /**
     * Render the widget
     */
    private static function render_widget($amount, $preapproval, $session_id, $context, $extra_data = array()) {
        $container_class = 'avvance-' . $context . '-widget';
        $widget_id = 'avvance-' . $context . '-widget';
        
        if (isset($extra_data['product_id'])) {
            $widget_id .= '-' . $extra_data['product_id'];
        }
        
        ?>
        <div id="<?php echo esc_attr($widget_id); ?>"
             class="<?php echo esc_attr($container_class); ?> avvance-widget-<?php echo esc_attr(self::$settings['theme']); ?>"
             data-amount="<?php echo esc_attr($amount); ?>"
             data-session-id="<?php echo esc_attr($session_id); ?>"
             data-context="<?php echo esc_attr($context); ?>"
             <?php if (isset($extra_data['product_type'])): ?>
             data-product-type="<?php echo esc_attr($extra_data['product_type']); ?>"
             <?php endif; ?>
             data-min-amount="<?php echo esc_attr(self::$settings['min_amount']); ?>"
             data-max-amount="<?php echo esc_attr(self::$settings['max_amount']); ?>">
            <div class="avvance-widget-content">
                <div class="avvance-price-message"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render CTA link based on pre-approval status
     *
     * Only PRE_APPROVED status shows the preapproved message.
     * NOT_APPROVED or pending shows the default "Check your spending power" link.
     */
    private static function render_cta_link($preapproval, $session_id) {
        // Only PRE_APPROVED status is considered approved
        if ($preapproval && $preapproval['status'] === 'PRE_APPROVED') {
            $has_valid_amount = isset($preapproval['max_amount']) && floatval($preapproval['max_amount']) > 0;

            if ($has_valid_amount) {
                $is_expired = false;
                if (!empty($preapproval['expiry_date'])) {
                    $expiry = strtotime($preapproval['expiry_date']);
                    if ($expiry && $expiry < time()) {
                        $is_expired = true;
                    }
                }

                if (!$is_expired) {
                    $max_amount = number_format($preapproval['max_amount'], 0);
                    ?>
                    <span class="avvance-preapproved-message" data-preapproved="true">
                        You're preapproved for up to $<?php echo esc_html($max_amount); ?>
                    </span>
                    <?php
                    return;
                }
            }
        }

        // Default: show "Check your spending power" link
        // This covers: no preapproval, NOT_APPROVED, pending, expired, or no valid amount
        ?>
        <a href="#"
           class="avvance-prequal-link"
           data-session-id="<?php echo esc_attr($session_id); ?>">
            Check your spending power
        </a>
        <?php
    }
    
    /**
     * Ensure modal is rendered on cart/checkout pages (WooCommerce Blocks compatibility)
     */
    public static function ensure_modal_rendered() {
        // Only render on cart or checkout pages
        if (!is_cart() && !is_checkout()) {
            return;
        }

        // Check if modal was already rendered by other hooks
        static $modal_rendered_in_footer = false;
        if ($modal_rendered_in_footer) {
            return;
        }

        // Check if modal element already exists (rendered by product/cart widget hooks)
        // We use a global flag since static vars in different methods are separate
        global $avvance_modal_rendered;
        if (!empty($avvance_modal_rendered)) {
            return;
        }

        // Render the modal for Blocks cart/checkout pages
        self::render_modal();
        $modal_rendered_in_footer = true;
        $avvance_modal_rendered = true;
    }

    /**
     * Render the pre-approval modal (Modal 2)
     *
     * Opened when "Check your spending power" is clicked.
     * Loan cards are populated dynamically by JS from the price breakdown API.
     */
    private static function render_modal() {
        // Mark modal as rendered globally
        global $avvance_modal_rendered;
        $avvance_modal_rendered = true;
        $gateway = avvance_get_gateway();
        $hashed_mid = $gateway ? $gateway->get_option('hashed_merchant_id') : '';
        $logo_url = AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg';
        ?>
        <div id="avvance-preapproval-modal" class="avvance-modal" style="display: none;">
            <div class="avvance-modal-overlay"></div>
            <div class="avvance-modal-dialog">
                <div class="avvance-modal-header">
                    <div class="avvance-modal-logo">
                        <img src="<?php echo esc_url($logo_url); ?>" alt="U.S. Bank Avvance" class="avvance-modal-logo-img">
                    </div>
                    <button class="avvance-modal-close">&times;</button>
                </div>

                <div class="avvance-modal-body">
                    <h1 class="avvance-modal-heading">Pay over time and make your purchase possible</h1>
                    <p class="avvance-modal-subtitle">Applying won't impact your credit score.</p>

                    <div class="avvance-input-group">
                        <span class="avvance-input-label">Example loan options for</span>
                        <input type="text" class="avvance-currency-input" id="avvance-modal-amount" value="">
                        <button type="button" class="avvance-calc-btn" id="avvance-calc-btn">Calculate monthly payments</button>
                    </div>

                    <div class="avvance-loan-cards" id="avvance-modal-loan-cards"></div>

                    <button type="button" class="avvance-btn-primary avvance-qualify-button" data-hashed-mid="<?php echo esc_attr($hashed_mid); ?>">
                        See if you qualify
                    </button>
                </div>

                <div class="avvance-slider-section">
                    <div class="avvance-slider-title">
                        How to get pre-approved with
                        <img src="<?php echo esc_url($logo_url); ?>" alt="U.S. Bank Avvance" class="avvance-slider-logo">
                    </div>

                    <div class="avvance-slider-container" id="avvance-slider-preapproval">
                        <div class="avvance-slide active">
                            <div class="avvance-step-number">1</div>
                            <div class="avvance-step-text">Apply to see if you qualify.</div>
                        </div>
                        <div class="avvance-slide">
                            <div class="avvance-step-number">2</div>
                            <div class="avvance-step-text">If approved, see your spending power.</div>
                        </div>
                        <div class="avvance-slide">
                            <div class="avvance-step-number">3</div>
                            <div class="avvance-step-text">Calculate your monthly payments.</div>
                        </div>

                        <div class="avvance-arrow-nav avvance-arrow-prev" data-slider="avvance-slider-preapproval" data-dir="-1">&#8249;</div>
                        <div class="avvance-arrow-nav avvance-arrow-next" data-slider="avvance-slider-preapproval" data-dir="1">&#8250;</div>
                    </div>

                    <div class="avvance-slider-dots" id="avvance-dots-preapproval">
                        <div class="avvance-dot active" data-slider="avvance-slider-preapproval" data-index="0"></div>
                        <div class="avvance-dot" data-slider="avvance-slider-preapproval" data-index="1"></div>
                        <div class="avvance-dot" data-slider="avvance-slider-preapproval" data-index="2"></div>
                    </div>

                    <p class="avvance-disclaimer">
                        Annual Percentage Rates (APR) range from 0% to 24.99% and are subject to eligibility check and approval.
                        <br><a href="#" class="avvance-learn-more">Learn more about U.S. Bank Avvance</a>
                    </p>
                </div>
            </div>
        </div>

        <?php
        // Preapproved details modal (shown when "See your details" is clicked)
        self::render_preapproved_modal();
    }

    /**
     * Render the preapproved details modal (Modal 3)
     *
     * Opened when a pre-approved customer clicks "See your details".
     * Shows spending power banner, loan cards (populated by JS), and checkout steps.
     */
    private static function render_preapproved_modal() {
        $preapproval = self::get_current_preapproval();
        $max_amount = ($preapproval && isset($preapproval['max_amount'])) ? number_format($preapproval['max_amount'], 2) : '0.00';
        $max_amount_raw = ($preapproval && isset($preapproval['max_amount'])) ? floatval($preapproval['max_amount']) : 0;
        $expiry_date = '';
        if ($preapproval && !empty($preapproval['expiry_date'])) {
            $expiry_timestamp = strtotime($preapproval['expiry_date']);
            if ($expiry_timestamp) {
                $expiry_date = gmdate('m/d/Y', $expiry_timestamp) . ', 11:59 PM PST';
            }
        }
        $min_amount = self::$settings['min_amount'];
        $logo_url = AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg';
        ?>
        <div id="avvance-preapproved-details-modal" class="avvance-modal" style="display: none;"
             data-max-amount="<?php echo esc_attr($max_amount_raw); ?>">
            <div class="avvance-modal-overlay"></div>
            <div class="avvance-modal-dialog">
                <div class="avvance-modal-header">
                    <div class="avvance-modal-logo">
                        <img src="<?php echo esc_url($logo_url); ?>" alt="U.S. Bank Avvance" class="avvance-modal-logo-img">
                    </div>
                    <button class="avvance-modal-close">&times;</button>
                </div>

                <div class="avvance-modal-body">
                    <div class="avvance-success-banner">
                        <div class="avvance-success-title">
                            <span class="avvance-success-check">&#10003;</span>
                            Your spending power is $<?php echo esc_html($max_amount); ?>!
                        </div>
                        <p class="avvance-success-text">
                            You've been pre-approved for U.S. Bank Avvance for $<?php echo esc_html($max_amount); ?>.
                            To use your spending power, your purchase must be between
                            $<?php echo esc_html(number_format($min_amount, 0)); ?> and $<?php echo esc_html($max_amount); ?>.
                        </p>
                        <?php if ($expiry_date) : ?>
                        <p class="avvance-success-expiry">
                            Expires on <?php echo esc_html($expiry_date); ?>.
                        </p>
                        <?php endif; ?>
                    </div>

                    <div class="avvance-input-group">
                        <span class="avvance-input-label">Example loan options for</span>
                        <input type="text" class="avvance-currency-input" id="avvance-preapproved-modal-amount" value="$<?php echo esc_attr($max_amount); ?>">
                        <button type="button" class="avvance-calc-btn" id="avvance-preapproved-calc-btn">Calculate monthly payments</button>
                    </div>

                    <div class="avvance-loan-cards" id="avvance-preapproved-modal-loan-cards"></div>

                    <button type="button" class="avvance-btn-primary avvance-continue-shopping-btn">
                        Continue shopping
                    </button>
                </div>

                <div class="avvance-slider-section">
                    <div class="avvance-slider-title">
                        How to checkout with
                        <img src="<?php echo esc_url($logo_url); ?>" alt="U.S. Bank Avvance" class="avvance-slider-logo">
                    </div>

                    <div class="avvance-slider-container" id="avvance-slider-preapproved">
                        <div class="avvance-slide active">
                            <div class="avvance-step-number">1</div>
                            <div class="avvance-step-text">Select Pay with U.S. Bank Avvance at checkout.</div>
                        </div>
                        <div class="avvance-slide">
                            <div class="avvance-step-number">2</div>
                            <div class="avvance-step-text">Choose the loan that works best for you.</div>
                        </div>
                        <div class="avvance-slide">
                            <div class="avvance-step-number">3</div>
                            <div class="avvance-step-text">Review terms and complete your purchase.</div>
                        </div>

                        <div class="avvance-arrow-nav avvance-arrow-prev" data-slider="avvance-slider-preapproved" data-dir="-1">&#8249;</div>
                        <div class="avvance-arrow-nav avvance-arrow-next" data-slider="avvance-slider-preapproved" data-dir="1">&#8250;</div>
                    </div>

                    <div class="avvance-slider-dots" id="avvance-dots-preapproved">
                        <div class="avvance-dot active" data-slider="avvance-slider-preapproved" data-index="0"></div>
                        <div class="avvance-dot" data-slider="avvance-slider-preapproved" data-index="1"></div>
                        <div class="avvance-dot" data-slider="avvance-slider-preapproved" data-index="2"></div>
                    </div>

                    <p class="avvance-disclaimer">
                        Your pre-approval expires on the earlier of (i) completion of a single Avvance transaction or (ii) the expiration date shown above.
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}