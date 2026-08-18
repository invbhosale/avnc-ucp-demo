<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- main plugin file
/**
 * Plugin Name: Avvance for WooCommerce
 * Plugin URI: https://www.usbank.com/avvance
 * Description: U.S. Bank point-of-sale financing for WooCommerce. Offer customers flexible installment loans at checkout.
 * Version: 1.4.0
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Author: U.S. Bank Avvance
 * Author URI: https://www.usbank.com/avvance
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: avvance-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 5.6.0
 * WC tested up to: 9.4.0
 *
 * @package Avvance_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'AVVANCE_VERSION', '1.4.0' );
define( 'AVVANCE_PLUGIN_FILE', __FILE__ );
define( 'AVVANCE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'AVVANCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Declare HPOS compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Main plugin class
 */
final class Avvance_For_WooCommerce {

	/**
	 * Singleton instance.
	 *
	 * @var Avvance_For_WooCommerce|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Avvance_For_WooCommerce
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize the plugin.
	 */
	public function init() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Include required files.
		$this->includes();

		// Initialize components.
		$this->init_hooks();

		// Register Blocks integration.
		$this->register_blocks();
	}

	/**
	 * Include required files.
	 */
	private function includes() {
		require_once AVVANCE_PLUGIN_PATH . 'includes/avvance-functions.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-api-base.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-api-client.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-loan-status-api.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-gateway.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-webhooks.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-order-handler.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-widget-handler.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-api.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-handler.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-price-breakdown-api.php';
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-offers-api.php';
	}

	/**
	 * Initialize hooks and components.
	 */
	private function init_hooks() {
		// Register payment gateway.
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );

		// Initialize webhook handler.
		Avvance_Webhooks::init();

		// Initialize order handler.
		Avvance_Order_Handler::init();

		// Deferred to woocommerce_init: init() calls avvance_get_gateway(), which forces WC_Payment_Gateways
		// to construct every registered gateway (including third-party ones) if triggered during plugins_loaded.
		add_action( 'woocommerce_init', array( 'Avvance_Widget_Handler', 'init' ) );

		// Initialize pre-approval handler (registers AJAX endpoints, DB table creation handled on activation).
		Avvance_PreApproval_Handler::init();

		// Enqueue scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Tealium analytics must load before the widget/checkout scripts that fire events.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_analytics_scripts' ), 5 );
	}

	/**
	 * Enqueue the Tealium reporting layer and its page-level data layer.
	 */
	public function enqueue_analytics_scripts() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'avvance-tealium-container',
			'https://tags.tiqcdn.com/utag/usbank/apply-cloud-usl-v2/dev/utag.js',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN script; version is managed by Tealium, not this plugin.
			true
		);

		wp_enqueue_script(
			'avvance-tealium',
			AVVANCE_PLUGIN_URL . 'assets/js/avvance-tealium.js',
			array( 'avvance-tealium-container' ),
			AVVANCE_VERSION,
			true
		);

		wp_localize_script( 'avvance-tealium', 'avvanceTealium', $this->get_analytics_data() );
	}

	/**
	 * Build the Tealium data layer for the current request.
	 *
	 * @return array
	 */
	private function get_analytics_data() {
		$gateway = function_exists( 'avvance_get_gateway' ) ? avvance_get_gateway() : null;

		$page_type = 'other';
		$page_data = array();

		if ( function_exists( 'is_product' ) && is_product() ) {
			$page_type = 'product';
			global $product;
			$product_obj = is_object( $product ) ? $product : wc_get_product( get_the_ID() );
			if ( $product_obj ) {
				$page_data['product_id']    = (string) $product_obj->get_id();
				$page_data['product_name']  = $product_obj->get_name();
				$page_data['product_price'] = (float) $product_obj->get_price();
				$page_data['product_sku']   = $product_obj->get_sku();
			}
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			$page_type = 'cart';
			if ( WC()->cart ) {
				$page_data['cart_total']      = (float) WC()->cart->get_total( 'edit' );
				$page_data['cart_item_count'] = WC()->cart->get_cart_contents_count();
			}
		} elseif ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			$page_type = 'order_confirmation';
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$page_type = 'checkout';
			if ( WC()->cart ) {
				$page_data['cart_total']      = (float) WC()->cart->get_total( 'edit' );
				$page_data['cart_item_count'] = WC()->cart->get_cart_contents_count();
			}
		} elseif ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) {
			$page_type = 'category';
		} elseif ( is_front_page() ) {
			$page_type = 'home';
		}

		return array(
			'siteName'       => get_bloginfo( 'name' ),
			'pageName'       => wp_get_document_title(),
			'pageType'       => $page_type,
			'pageData'       => $page_data,
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'environment'    => $gateway ? $gateway->get_option( 'environment' ) : '',
			'gatewayEnabled' => $gateway && 'yes' === $gateway->get_option( 'enabled' ),
			'debug'          => $gateway && 'yes' === $gateway->get_option( 'debug_mode' ),
			'isLoggedIn'     => is_user_logged_in(),
			'version'        => AVVANCE_VERSION,
		);
	}

	/**
	 * Add Avvance gateway to WooCommerce.
	 *
	 * @param array $methods Payment gateway methods.
	 * @return array
	 */
	public function add_gateway( $methods ) {
		$methods[] = 'WC_Gateway_Avvance';
		return $methods;
	}

	/**
	 * Enqueue checkout scripts and styles.
	 */
	public function enqueue_scripts() {
		if ( is_checkout() || is_cart() ) {
			wp_enqueue_style(
				'avvance-checkout',
				AVVANCE_PLUGIN_URL . 'assets/css/avvance-checkout.css',
				array(),
				AVVANCE_VERSION
			);

			wp_enqueue_script(
				'avvance-checkout',
				AVVANCE_PLUGIN_URL . 'assets/js/avvance-checkout.js',
				array( 'jquery', 'avvance-tealium' ),
				AVVANCE_VERSION,
				true
			);

			wp_localize_script(
				'avvance-checkout',
				'avvanceCheckout',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'pollInterval' => 5000, // 5 seconds
				)
			);
		}
	}

	/**
	 * Register WooCommerce Blocks integration.
	 */
	private function register_blocks() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_loaded',
			function () {
				require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-blocks.php';

				add_action(
					'woocommerce_blocks_payment_method_type_registration',
					function ( $registry ) {
						$registry->register( new Avvance_Blocks_Integration() );
					}
				);
			}
		);
	}

	/**
	 * Display notice when WooCommerce is not active.
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo '<strong>' . esc_html__( 'Avvance for WooCommerce', 'avvance-for-woocommerce' ) . '</strong> ';
		echo esc_html__( 'requires WooCommerce to be installed and active.', 'avvance-for-woocommerce' );
		echo '</p></div>';
	}
}

// Initialize plugin.
Avvance_For_WooCommerce::instance();
// ADD THIS ACTIVATION HOOK:.
register_activation_hook(
	AVVANCE_PLUGIN_FILE,
	function () {
		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-handler.php';
		Avvance_PreApproval_Handler::create_preapproval_table();
	}
);
