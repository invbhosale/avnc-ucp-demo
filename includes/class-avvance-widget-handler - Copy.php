<?php
/**
 * Avvance Widget Handler
 * Displays price breakdown widget with modal for pre-approval
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Widget_Handler {
    
    public static function init() {
        // Product page widget
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_product_widget'], 25);

        // Cart page widget - try multiple hooks for compatibility
        add_action('woocommerce_before_cart_collaterals', [__CLASS__, 'render_cart_widget'], 10);
        add_action('woocommerce_after_cart_table', [__CLASS__, 'render_cart_widget_fallback'], 10);
        add_action('woocommerce_cart_collaterals', [__CLASS__, 'render_cart_widget_fallback2'], 5);

        // Checkout page widget (after order review)
        add_action('woocommerce_review_order_before_payment', [__CLASS__, 'render_checkout_widget']);

        // Enqueue widget scripts and styles
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_widget_scripts']);
        
        // AJAX endpoint for getting price breakdown
        add_action('wp_ajax_avvance_get_price_breakdown', [__CLASS__, 'ajax_get_price_breakdown']);
        add_action('wp_ajax_nopriv_avvance_get_price_breakdown', [__CLASS__, 'ajax_get_price_breakdown']);
        
        // AJAX endpoint for checking pre-approval status
        add_action('wp_ajax_avvance_check_preapproval_status', [__CLASS__, 'ajax_check_preapproval_status']);
        add_action('wp_ajax_nopriv_avvance_check_preapproval_status', [__CLASS__, 'ajax_check_preapproval_status']);
    }
    
    /**
     * Generate unique session ID for tracking
     */
    private static function generate_session_id() {
        return 'avv_' . uniqid() . '_' . time();
    }
    
    /**
     * Enqueue widget scripts and styles
     */
    public static function enqueue_widget_scripts() {
        if (is_product() || is_checkout() || is_cart()) {
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
                'logoUrl' => AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg'
            ]);
        }
    }
    
    /**
     * AJAX: Get price breakdown
     */
    public static function ajax_get_price_breakdown() {
        avvance_log('=== PRICE BREAKDOWN REQUEST ===');
        avvance_log('POST data: ' . print_r($_POST, true));
        
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        
        avvance_log('Amount received: ' . $amount);
        
        if ($amount < 300 || $amount > 25000) {
            avvance_log('Amount out of range: ' . $amount, 'error');
            wp_send_json_error(['message' => 'Amount must be between $300 and $25,000']);
        }
        
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            avvance_log('Gateway not available', 'error');
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
            avvance_log('Price breakdown failed: ' . $response->get_error_message(), 'error');
            wp_send_json_error(['message' => 'Unable to get price breakdown']);
        }
        
        avvance_log('Price breakdown success');
        wp_send_json_success($response);
    }
    
    /**
     * AJAX: Check pre-approval status
     */
    public static function ajax_check_preapproval_status() {
        // Verify nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'avvance_preapproval')) {
            wp_send_json_success(['status' => 'none']); // Fail silently for security
        }
        
        $preapproval_data = Avvance_PreApproval_Handler::get_preapproval_data();
        
        if (!$preapproval_data) {
            wp_send_json_success(['status' => 'none']);
        }
        
        // Check if expired (15 days)
        if (isset($preapproval_data['expiry_date'])) {
            $expiry = strtotime($preapproval_data['expiry_date']);
            if ($expiry && $expiry < time()) {
                wp_send_json_success(['status' => 'expired']);
            }
        }
        
        wp_send_json_success([
            'status' => $preapproval_data['status'] ?? 'pending',
            'max_amount' => $preapproval_data['max_amount'] ?? null,
            'customer_name' => $preapproval_data['customer_name'] ?? null
        ]);
    }
    
    /**
     * Render widget on product page
     */
    public static function render_product_widget() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        // Check if Avvance is enabled
        $gateway = avvance_get_gateway();
        if (!$gateway || $gateway->enabled !== 'yes') {
            return;
        }
        
        // Check if price is within range ($300 - $25,000)
        $price = $product->get_price();
        if (empty($price) || $price < 300 || $price > 25000) {
            return;
        }
        
        // Generate session ID
        $session_id = self::generate_session_id();
        
        // Check for existing pre-approval
        $preapproval = Avvance_PreApproval_Handler::get_preapproval_data();
        
        self::render_widget($price, $preapproval, $session_id, 'product');
        
        // Render modal (only once per page)
        static $modal_rendered = false;
        if (!$modal_rendered) {
            self::render_modal();
            $modal_rendered = true;
        }
    }
    
    /**
     * Render widget on cart page (primary hook)
     */
    public static function render_cart_widget() {
        static $rendered = false;
        if ($rendered) {
            return; // Already rendered, prevent duplicates
        }

        avvance_log('=== CART WIDGET RENDER CALLED (primary hook) ===');
        self::render_cart_widget_internal();
        $rendered = true;
    }

    /**
     * Render widget on cart page (fallback hook 1)
     */
    public static function render_cart_widget_fallback() {
        static $rendered = false;
        if ($rendered) {
            return; // Already rendered, prevent duplicates
        }

        avvance_log('=== CART WIDGET RENDER CALLED (fallback hook 1) ===');
        self::render_cart_widget_internal();
        $rendered = true;
    }

    /**
     * Render widget on cart page (fallback hook 2)
     */
    public static function render_cart_widget_fallback2() {
        static $rendered = false;
        if ($rendered) {
            return; // Already rendered, prevent duplicates
        }

        avvance_log('=== CART WIDGET RENDER CALLED (fallback hook 2) ===');
        self::render_cart_widget_internal();
        $rendered = true;
    }

    /**
     * Internal cart widget render logic
     */
    private static function render_cart_widget_internal() {
        // Check if Avvance is enabled
        $gateway = avvance_get_gateway();
        avvance_log('Gateway exists: ' . ($gateway ? 'yes' : 'no'));
        avvance_log('Gateway enabled: ' . ($gateway ? $gateway->enabled : 'N/A'));

        if (!$gateway || $gateway->enabled !== 'yes') {
            avvance_log('Cart widget NOT rendered: Gateway not enabled');
            return;
        }

        // Check if cart is not empty
        if (!WC()->cart || WC()->cart->is_empty()) {
            avvance_log('Cart widget NOT rendered: Cart is empty');
            return;
        }

        // Get cart total
        $total = WC()->cart->get_total('');
        avvance_log('Cart total: ' . $total);

        if ($total < 300 || $total > 25000) {
            avvance_log('Cart widget NOT rendered: Total out of range ($300-$25,000)');
            return;
        }

        avvance_log('Rendering cart widget for total: $' . $total);

        // Generate session ID
        $session_id = self::generate_session_id();

        // Check for existing pre-approval
        $preapproval = Avvance_PreApproval_Handler::get_preapproval_data();

        // Render widget directly (not in table)
        echo '<div class="avvance-cart-widget-container" style="margin: 20px 0;">';
        self::render_widget($total, $preapproval, $session_id, 'cart');
        echo '</div>';

        avvance_log('Cart widget HTML rendered');

        // Render modal (only once per page)
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
        // Check if Avvance is enabled
        $gateway = avvance_get_gateway();
        if (!$gateway || $gateway->enabled !== 'yes') {
            return;
        }

        // Check if Avvance is available for current cart
        if (!$gateway->is_available()) {
            return;
        }

        // Get cart total
        $total = WC()->cart ? WC()->cart->get_total('') : 0;

        if ($total < 300 || $total > 25000) {
            return;
        }

        // Generate session ID
        $session_id = self::generate_session_id();

        // Check for existing pre-approval
        $preapproval = Avvance_PreApproval_Handler::get_preapproval_data();

        self::render_widget($total, $preapproval, $session_id, 'checkout');

        // Render modal (only once per page)
        static $modal_rendered = false;
        if (!$modal_rendered) {
            self::render_modal();
            $modal_rendered = true;
        }
    }
    
    /**
     * Render the widget
     */
    private static function render_widget($amount, $preapproval, $session_id, $context) {
        // Set appropriate CSS class based on context
        $container_class = 'avvance-product-widget'; // default
        if ($context === 'checkout') {
            $container_class = 'avvance-checkout-widget';
        } elseif ($context === 'cart') {
            $container_class = 'avvance-cart-widget';
        }
        ?>
        <div class="<?php echo esc_attr($container_class); ?>" 
             data-amount="<?php echo esc_attr($amount); ?>" 
             data-session-id="<?php echo esc_attr($session_id); ?>">
            <div class="avvance-widget-content">
                <div class="avvance-price-message">
                    <span class="avvance-loading">Loading payment options...</span>
                </div>
                <div class="avvance-prequal-cta" style="margin-top: 8px;">
                    <?php self::render_cta_link($preapproval, $session_id); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render CTA link based on pre-approval status
     */
    private static function render_cta_link($preapproval, $session_id) {
        if ($preapproval && isset($preapproval['status'])) {
            // Check if qualified and not expired
            if ($preapproval['status'] === 'Qualified lead' && isset($preapproval['max_amount'])) {
                $max_amount = number_format($preapproval['max_amount'], 0);
                ?>
                <span class="avvance-preapproved-message">
                    You're preapproved for up to $<?php echo esc_html($max_amount); ?>
                </span>
                <?php
                return;
            }
        }
        
        // Default: Show "Check your spending power" link
        ?>
        <a href="#" 
           class="avvance-prequal-link" 
           data-session-id="<?php echo esc_attr($session_id); ?>">
            Check your spending power
        </a>
        <?php
    }
    
    /**
     * Render the pre-approval modal
     */
    private static function render_modal() {
        $gateway = avvance_get_gateway();
        $hashed_mid = $gateway ? $gateway->get_option('hashed_merchant_id') : '';
        ?>
        <div id="avvance-preapproval-modal" class="avvance-modal" style="display: none;">
            <div class="avvance-modal-overlay"></div>
            <div class="avvance-modal-content">
                <button class="avvance-modal-close">&times;</button>
                
                <div class="avvance-modal-header">
                    <img src="<?php echo esc_url(AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg'); ?>" 
                         alt="U.S. Bank Avvance" 
                         class="avvance-modal-logo">
                </div>
                
                <div class="avvance-modal-body">
                    <h2>Flexible Payment Options</h2>
                    <p class="avvance-modal-description">
                        Get prequalified for flexible installment loans from U.S. Bank. 
                        Check your spending power with no impact to your credit score.
                    </p>
                    
                    <div class="avvance-modal-benefits">
                        <div class="avvance-benefit">
                            <svg width="24" height="24" fill="#0073aa" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                            <span>No impact to your credit score</span>
                        </div>
                        <div class="avvance-benefit">
                            <svg width="24" height="24" fill="#0073aa" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                            <span>Flexible payment terms</span>
                        </div>
                        <div class="avvance-benefit">
                            <svg width="24" height="24" fill="#0073aa" viewBox="0 0 24 24">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                            </svg>
                            <span>Quick and easy application</span>
                        </div>
                    </div>
                    
                    <button class="avvance-qualify-button" data-hashed-mid="<?php echo esc_attr($hashed_mid); ?>">
                        See if you qualify
                    </button>
                    
                    <p class="avvance-modal-footer-text">
                        Qualification for payment options are subject to application approval.
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}