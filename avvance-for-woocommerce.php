<?php
/**
 * Plugin Name: Avvance for WooCommerce
 * Plugin URI: https://www.usbank.com/avvance
 * Description: U.S. Bank point-of-sale financing for WooCommerce. Offer customers flexible installment loans at checkout.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Author: U.S. Bank Avvance
 * Author URI: https://www.usbank.com/avvance
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: avvance-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 5.6.0
 * WC tested up to: 9.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('AVVANCE_VERSION', '1.1.0');
define('AVVANCE_PLUGIN_FILE', __FILE__);
define('AVVANCE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AVVANCE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Main plugin class
 */
final class Avvance_For_WooCommerce {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }
    
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }
        
        // Include required files
        $this->includes();
        
        // Initialize components
        $this->init_hooks();
        
        // Register Blocks integration
        $this->register_blocks();
    }
    
    private function includes() {
        require_once AVVANCE_PLUGIN_PATH . 'includes/avvance-functions.php';
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-api-base.php';
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-api-client.php';
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-gateway.php';
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-webhooks.php';
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-order-handler.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-widget-handler.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-api.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-handler.php';
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-price-breakdown-api.php';
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-ucp-handler.php';

    }
    
    private function init_hooks() {
        // Register payment gateway
        add_filter('woocommerce_payment_gateways', [$this, 'add_gateway']);
        
        // Initialize webhook handler
        Avvance_Webhooks::init();
        
        // Initialize order handler
        Avvance_Order_Handler::init();
		
		// Initialize widget handler
		Avvance_Widget_Handler::init();

		// Initialize pre-approval handler (registers AJAX endpoints, DB table creation handled on activation)
		Avvance_PreApproval_Handler::init();

        // Initialize UCP Handler
        Avvance_UCP_Handler::init();
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function add_gateway($methods) {
        $methods[] = 'WC_Gateway_Avvance';
        return $methods;
    }
    
    public function enqueue_scripts() {
        if (is_checkout() || is_cart()) {
            wp_enqueue_style(
                'avvance-checkout',
                AVVANCE_PLUGIN_URL . 'assets/css/avvance-checkout.css',
                [],
                AVVANCE_VERSION
            );
            
            wp_enqueue_script(
                'avvance-checkout',
                AVVANCE_PLUGIN_URL . 'assets/js/avvance-checkout.js',
                ['jquery'],
                AVVANCE_VERSION,
                true
            );
            
            wp_localize_script('avvance-checkout', 'avvanceCheckout', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'pollInterval' => 5000, // 5 seconds
            ]);
        }
    }
    
    private function register_blocks() {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }
        
        add_action('woocommerce_blocks_loaded', function() {
            require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-blocks.php';
            
            add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
                $registry->register(new Avvance_Blocks_Integration());
            });
        });
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('Avvance for WooCommerce', 'avvance-for-woocommerce') . '</strong> ';
        echo esc_html__('requires WooCommerce to be installed and active.', 'avvance-for-woocommerce');
        echo '</p></div>';
    }
}

// Initialize plugin
Avvance_For_WooCommerce::instance();
// ADD THIS ACTIVATION HOOK:
register_activation_hook(AVVANCE_PLUGIN_FILE, function() {
    require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-handler.php';
    Avvance_PreApproval_Handler::create_preapproval_table();
});