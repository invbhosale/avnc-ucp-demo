<?php
/**
 * Avvance Widget Handler
 *
 * @package Avvance_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Avvance widget rendering across store pages.
 */
class Avvance_Widget_Handler {

	/**
	 * Widget settings.
	 *
	 * @var array
	 */
	private static $settings = array();

	/**
	 * Gateway instance.
	 *
	 * @var WC_Gateway_Avvance|null
	 */
	private static $gateway = null;

	/**
	 * Initialize widget handler.
	 */
	public static function init() {
		self::$gateway = avvance_get_gateway();
		self::load_settings();
		self::register_hooks();
	}

	/**
	 * Load widget settings from gateway options
	 */
	private static function load_settings() {
		if ( ! self::$gateway ) {
			return;
		}

		self::$settings = array(
			'category_enabled' => self::$gateway->get_option( 'category_widget_enabled', 'yes' ) === 'yes',
			'product_enabled'  => self::$gateway->get_option( 'product_widget_enabled', 'yes' ) === 'yes',
			'product_position' => self::$gateway->get_option( 'product_widget_position', 'after_price' ),
			'cart_enabled'     => self::$gateway->get_option( 'cart_widget_enabled', 'yes' ) === 'yes',
			'checkout_enabled' => self::$gateway->get_option( 'checkout_widget_enabled', 'yes' ) === 'yes',
			'theme'            => self::$gateway->get_option( 'widget_theme', 'light' ),
			'show_logo'        => self::$gateway->get_option( 'widget_show_logo', 'yes' ) === 'yes',
			'min_amount'       => floatval( self::$gateway->get_option( 'min_order_amount', 300 ) ),
			'max_amount'       => floatval( self::$gateway->get_option( 'max_order_amount', 25000 ) ),
		);
	}

	/**
	 * Register all WordPress/WooCommerce hooks
	 */
	private static function register_hooks() {
		// Check if gateway is enabled.
		if ( ! self::$gateway || 'yes' !== self::$gateway->enabled ) {
			avvance_log( 'Widget hooks not registered: Gateway not enabled' );
			return;
		}

		// Enqueue scripts.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_widget_scripts' ) );

		// Category/shop page widget.
		if ( self::$settings['category_enabled'] ) {
			add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'render_category_widget' ), 10 );
		}

		// Product page widget (based on position setting).
		if ( self::$settings['product_enabled'] ) {
			$position = self::$settings['product_position'];

			if ( 'after_price' === $position || 'both' === $position ) {
				add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_product_widget' ), 15 );
			}

			if ( 'after_add_cart' === $position || 'both' === $position ) {
				add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'render_product_widget_after_cart' ), 10 );
			}
		}

		// Cart page widget (multiple hooks for compatibility).
		if ( self::$settings['cart_enabled'] ) {
			add_action( 'woocommerce_before_cart_collaterals', array( __CLASS__, 'render_cart_widget' ), 10 );
			add_action( 'woocommerce_after_cart_table', array( __CLASS__, 'render_cart_widget_fallback' ), 10 );
			add_action( 'woocommerce_cart_collaterals', array( __CLASS__, 'render_cart_widget_fallback2' ), 5 );
		}

		// Checkout page widget.
		if ( self::$settings['checkout_enabled'] ) {
			add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_checkout_widget' ), 5 );
		}

		// Ensure modal is rendered on cart/checkout pages (for WooCommerce Blocks compatibility).
		add_action( 'wp_footer', array( __CLASS__, 'ensure_modal_rendered' ), 10 );

		// AJAX endpoints.
		add_action( 'wp_ajax_avvance_get_price_breakdown', array( __CLASS__, 'ajax_get_price_breakdown' ) );
		add_action( 'wp_ajax_nopriv_avvance_get_price_breakdown', array( __CLASS__, 'ajax_get_price_breakdown' ) );

		add_action( 'wp_ajax_avvance_check_preapproval', array( __CLASS__, 'ajax_check_preapproval' ) );
		add_action( 'wp_ajax_nopriv_avvance_check_preapproval', array( __CLASS__, 'ajax_check_preapproval' ) );
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

		if ( ! $fingerprint ) {
			return null;
		}

		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'avvance_preapprovals' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$record = $wpdb->get_row(
			$wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, prefixed with $wpdb->prefix
				"SELECT * FROM {$table_name} WHERE browser_fingerprint = %s ORDER BY created_at DESC LIMIT 1",
				sanitize_text_field( $fingerprint )
			),
			ARRAY_A
		);

		return $record;
	}

	/**
	 * Get browser fingerprint from cookie
	 */
	private static function get_browser_fingerprint() {
		$cookie_name = 'avvance_browser_id';

		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		}

		return null;
	}

	/**
	 * Calculate monthly payment (simple calculation).
	 *
	 * @param float $amount Product or cart amount.
	 * @return string Formatted monthly payment.
	 */
	private static function calculate_monthly_payment( $amount ) {
		// Simple 6-month calculation.
		// TODO: Replace with actual Avvance API call if available.
		$months  = 6;
		$monthly = ceil( ( $amount * 100 ) / $months ) / 100;
		return number_format( $monthly, 2 );
	}

	/**
	 * Enqueue widget scripts and styles
	 */
	public static function enqueue_widget_scripts() {
		// Only on relevant pages.
		if ( ! is_product() && ! is_cart() && ! is_checkout() && ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
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
			array( 'jquery' ),
			AVVANCE_VERSION,
			true
		);

		wp_localize_script(
			'avvance-widget',
			'avvanceWidget',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'avvance_preapproval' ),
				'checkInterval'  => 3000,
				'logoUrl'        => AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg',
				'imagesUrl'      => AVVANCE_PLUGIN_URL . 'assets/images/',
				'retailerName'   => get_bloginfo( 'name' ),
				'logoUrlLight'   => AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg',
				'logoUrlDark'    => AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo-white.svg',
				'theme'          => self::$settings['theme'],
				'minAmount'      => self::$settings['min_amount'],
				'maxAmount'      => self::$settings['max_amount'],
				'isProductPage'  => is_product(),
				'isCartPage'     => is_cart(),
				'isCheckoutPage' => is_checkout(),
				'showLogo'       => self::$settings['show_logo'],
			)
		);
	}

	/**
	 * AJAX: Get price breakdown
	 */
	public static function ajax_get_price_breakdown() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public-facing AJAX for price display, no state change
		$amount = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;

		$gateway = avvance_get_gateway();
		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => 'Gateway not available' ) );
		}

		$min_amount = floatval( $gateway->get_option( 'min_order_amount', 300 ) );
		$max_amount = floatval( $gateway->get_option( 'max_order_amount', 25000 ) );

		if ( $amount < $min_amount || $amount > $max_amount ) {
			wp_send_json_error( array( 'message' => 'Amount must be between $' . number_format( $min_amount, 0 ) . ' and $' . number_format( $max_amount, 0 ) ) );
		}

		require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-price-breakdown-api.php';

		$api = new Avvance_Price_Breakdown_API(
			array(
				'client_key'    => $gateway->get_option( 'client_key' ),
				'client_secret' => $gateway->get_option( 'client_secret' ),
				'merchant_id'   => $gateway->get_option( 'merchant_id' ),
				'partner_id'    => $gateway->get_option( 'partner_id' ),
				'environment'   => $gateway->get_option( 'environment' ),
			)
		);

		$response = $api->get_price_breakdown( $amount );

		if ( is_wp_error( $response ) ) {
			avvance_log( 'Price breakdown AJAX error: ' . $response->get_error_message(), 'error' );
			wp_send_json_error( array( 'message' => 'Unable to get price breakdown' ) );
		}

		wp_send_json_success( $response );
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

		if ( ! $preapproval ) {
			wp_send_json_success(
				array(
					'has_preapproval' => false,
					'status'          => 'none',
					'message'         => 'Check your spending power',
				)
			);
		}

		$status = $preapproval['status'] ?? 'pending';

		// Only PRE_APPROVED is considered approved (NOT_APPROVED is declined).
		if ( 'PRE_APPROVED' !== $status ) {
			// For NOT_APPROVED or pending, show the default CTA.
			wp_send_json_success(
				array(
					'has_preapproval' => false,
					'status'          => $status,
					'message'         => 'Check your spending power',
				)
			);
		}

		// PRE_APPROVED - check for valid max amount.
		$has_valid_amount = isset( $preapproval['max_amount'] ) && floatval( $preapproval['max_amount'] ) > 0;

		if ( ! $has_valid_amount ) {
			wp_send_json_success(
				array(
					'has_preapproval' => false,
					'status'          => $status,
					'message'         => 'Check your spending power',
				)
			);
		}

		// Check if expired.
		if ( ! empty( $preapproval['expiry_date'] ) ) {
			$expiry = strtotime( $preapproval['expiry_date'] );
			if ( $expiry && $expiry < time() ) {
				wp_send_json_success(
					array(
						'has_preapproval' => false,
						'status'          => 'expired',
						'message'         => 'Check your spending power',
					)
				);
			}
		}

		$max_amount = number_format( $preapproval['max_amount'], 0 );

		wp_send_json_success(
			array(
				'has_preapproval'      => true,
				'status'               => 'PRE_APPROVED',
				'max_amount'           => $preapproval['max_amount'],
				'max_amount_formatted' => $max_amount,
				'message'              => "You're preapproved for up to $" . $max_amount,
			)
		);
	}

	/**
	 * Render widget on category/shop page
	 */
	public static function render_category_widget() {
		global $product;

		if ( ! $product || ! $product->get_price() ) {
			return;
		}

		$price = $product->get_price();

		if ( $price < self::$settings['min_amount'] || $price > self::$settings['max_amount'] ) {
			return;
		}

		$widget_id  = 'avvance-category-widget-' . $product->get_id();
		$session_id = self::generate_session_id();

		?>
		<div id="<?php echo esc_attr( $widget_id ); ?>"
			class="avvance-category-widget avvance-widget-<?php echo esc_attr( self::$settings['theme'] ); ?>"
			data-amount="<?php echo esc_attr( $price ); ?>"
			data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
			data-session-id="<?php echo esc_attr( $session_id ); ?>"
			data-context="category"
			data-min-amount="<?php echo esc_attr( self::$settings['min_amount'] ); ?>"
			data-max-amount="<?php echo esc_attr( self::$settings['max_amount'] ); ?>">
			<div class="avvance-widget-content">
				<div class="avvance-price-message"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render widget on product page (after price)
	 */
	public static function render_product_widget() {
		global $product;

		if ( ! $product ) {
			return;
		}

		$price = $product->get_price();

		// For variable products, get the base price or range.
		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices( true );
			if ( ! empty( $prices['price'] ) ) {
				$price = min( $prices['price'] );
			}
		}

		// For grouped products, get lowest child price.
		if ( $product->is_type( 'grouped' ) ) {
			$price = self::get_grouped_product_lowest_price( $product );
		}

		if ( ! $price || $price < self::$settings['min_amount'] || $price > self::$settings['max_amount'] ) {
			// Render placeholder for variable products (JS will update).
			if ( $product->is_type( 'variable' ) ) {
				self::render_product_widget_placeholder( $product );
			}
			return;
		}

		$session_id  = self::generate_session_id();
		$preapproval = self::get_current_preapproval();

		self::render_widget(
			$price,
			$preapproval,
			$session_id,
			'product',
			array(
				'product_id'   => $product->get_id(),
				'product_type' => $product->get_type(),
			)
		);
	}

	/**
	 * Render widget after add to cart button
	 */
	public static function render_product_widget_after_cart() {
		// Same as render_product_widget but in different position.
		self::render_product_widget();
	}

	/**
	 * Render placeholder for variable products (will be updated by JS).
	 *
	 * @param WC_Product $product WooCommerce product.
	 */
	private static function render_product_widget_placeholder( $product ) {
		$session_id = self::generate_session_id();
		?>
		<div id="avvance-product-widget" 
			class="avvance-product-widget avvance-widget-<?php echo esc_attr( self::$settings['theme'] ); ?>"
			data-amount="0"
			data-session-id="<?php echo esc_attr( $session_id ); ?>"
			data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
			data-product-type="variable"
			data-min-amount="<?php echo esc_attr( self::$settings['min_amount'] ); ?>"
			data-max-amount="<?php echo esc_attr( self::$settings['max_amount'] ); ?>"
			style="display: none;">
			<div class="avvance-widget-content">
				<div class="avvance-price-message"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get lowest price from grouped product children.
	 *
	 * @param WC_Product $product WooCommerce grouped product.
	 * @return float
	 */
	private static function get_grouped_product_lowest_price( $product ) {
		$children = array_filter( array_map( 'wc_get_product', $product->get_children() ) );

		$prices = array();
		foreach ( $children as $child ) {
			if ( $child && $child->is_purchasable() && $child->get_price() ) {
				$prices[] = $child->get_price();
			}
		}

		return ! empty( $prices ) ? min( $prices ) : 0;
	}

	/**
	 * Render widget on cart page (primary hook)
	 */
	public static function render_cart_widget() {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}

		self::render_cart_widget_internal();
		$rendered = true;
	}

	/**
	 * Render cart widget fallback (after cart table).
	 */
	public static function render_cart_widget_fallback() {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}

		self::render_cart_widget_internal();
		$rendered = true;
	}

	/**
	 * Render cart widget fallback (cart collaterals).
	 */
	public static function render_cart_widget_fallback2() {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}

		self::render_cart_widget_internal();
		$rendered = true;
	}

	/**
	 * Internal cart widget rendering logic.
	 */
	private static function render_cart_widget_internal() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$total = WC()->cart->get_total( '' );

		if ( $total < self::$settings['min_amount'] || $total > self::$settings['max_amount'] ) {
			return;
		}

		$session_id  = self::generate_session_id();
		$preapproval = self::get_current_preapproval();

		echo '<div class="avvance-cart-widget-container" style="margin: 20px 0;">';
		self::render_widget( $total, $preapproval, $session_id, 'cart' );
		echo '</div>';
	}

	/**
	 * Render checkout banner above payment methods (always visible, priority 5).
	 */
	public static function render_checkout_widget() {
		if ( ! WC()->cart ) {
			return;
		}

		$total = WC()->cart->get_total( '' );

		if ( $total < self::$settings['min_amount'] || $total > self::$settings['max_amount'] ) {
			return;
		}

		$session_id  = self::generate_session_id();
		$preapproval = self::get_current_preapproval();

		$is_preapproved   = $preapproval && 'PRE_APPROVED' === $preapproval['status'];
		$has_valid_amount = $is_preapproved && isset( $preapproval['max_amount'] ) && floatval( $preapproval['max_amount'] ) > 0;
		$is_expired       = false;

		if ( $is_preapproved && ! empty( $preapproval['expiry_date'] ) ) {
			$expiry     = strtotime( $preapproval['expiry_date'] );
			$is_expired = ( $expiry && $expiry < time() );
		}

		$show_preapproved = $is_preapproved && $has_valid_amount && ! $is_expired;
		$theme            = strtolower( (string) self::$settings['theme'] );
		if ( ! in_array( $theme, array( 'light', 'dark' ), true ) ) {
			$theme = 'light';
		}
		$theme_logo_url = ( 'dark' === $theme )
			? AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo-white.svg'
			: AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg';
		?>
		<div id="avvance-checkout-banner" class="avvance-checkout-banner avvance-widget-<?php echo esc_attr( $theme ); ?>">
			<?php if ( $show_preapproved ) : ?>
				<div class="avvance-checkout-preapproved">
					<div class="avvance-checkout-banner-check">&#10003;</div>
					<div class="avvance-checkout-banner-text">
						<strong>You're pre-approved for $<?php echo number_format( $preapproval['max_amount'], 0 ); ?>!</strong>
						Pay over time with
						<?php if ( self::$settings['show_logo'] ) : ?>
							<img src="<?php echo esc_url( $theme_logo_url ); ?>" alt="U.S. Bank Avvance" class="avvance-logo-inline">
						<?php else : ?>
							<span class="avvance-brand">U.S. Bank Avvance</span>
						<?php endif; ?>
						<a href="#" class="avvance-cta-link" data-modal="preapproved-details">See your details</a>
					</div>
				</div>
			<?php else : ?>
				<div class="avvance-checkout-widget avvance-widget-<?php echo esc_attr( self::$settings['theme'] ); ?>"
					data-amount="<?php echo esc_attr( $total ); ?>"
					data-session-id="<?php echo esc_attr( $session_id ); ?>"
					data-context="checkout"
					data-min-amount="<?php echo esc_attr( self::$settings['min_amount'] ); ?>"
					data-max-amount="<?php echo esc_attr( self::$settings['max_amount'] ); ?>">
					<div class="avvance-widget-content">
						<div class="avvance-price-message"></div>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the widget.
	 *
	 * @param float      $amount      Product or cart amount.
	 * @param array|null $preapproval Pre-approval data.
	 * @param string     $session_id  Tracking session ID.
	 * @param string     $context     Widget context (product, cart, checkout).
	 * @param array      $extra_data  Additional data attributes.
	 */
	private static function render_widget( $amount, $preapproval, $session_id, $context, $extra_data = array() ) {
		$container_class = 'avvance-' . $context . '-widget';
		$widget_id       = 'avvance-' . $context . '-widget';

		if ( isset( $extra_data['product_id'] ) ) {
			$widget_id .= '-' . $extra_data['product_id'];
		}

		?>
		<div id="<?php echo esc_attr( $widget_id ); ?>"
			class="<?php echo esc_attr( $container_class ); ?> avvance-widget-<?php echo esc_attr( self::$settings['theme'] ); ?>"
			data-amount="<?php echo esc_attr( $amount ); ?>"
			data-session-id="<?php echo esc_attr( $session_id ); ?>"
			data-context="<?php echo esc_attr( $context ); ?>"
			<?php if ( isset( $extra_data['product_type'] ) ) : ?>
			data-product-type="<?php echo esc_attr( $extra_data['product_type'] ); ?>"
			<?php endif; ?>
			data-min-amount="<?php echo esc_attr( self::$settings['min_amount'] ); ?>"
			data-max-amount="<?php echo esc_attr( self::$settings['max_amount'] ); ?>">
			<div class="avvance-widget-content">
				<div class="avvance-price-message"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render CTA link based on pre-approval status.
	 *
	 * Only PRE_APPROVED status shows the preapproved message.
	 * NOT_APPROVED or pending shows the default "Check your spending power" link.
	 *
	 * @param array|null $preapproval Pre-approval data.
	 * @param string     $session_id  Tracking session ID.
	 */
	private static function render_cta_link( $preapproval, $session_id ) {
		// Only PRE_APPROVED status is considered approved.
		if ( $preapproval && 'PRE_APPROVED' === $preapproval['status'] ) {
			$has_valid_amount = isset( $preapproval['max_amount'] ) && floatval( $preapproval['max_amount'] ) > 0;

			if ( $has_valid_amount ) {
				$is_expired = false;
				if ( ! empty( $preapproval['expiry_date'] ) ) {
					$expiry = strtotime( $preapproval['expiry_date'] );
					if ( $expiry && $expiry < time() ) {
						$is_expired = true;
					}
				}

				if ( ! $is_expired ) {
					$max_amount = number_format( $preapproval['max_amount'], 0 );
					?>
					<span class="avvance-preapproved-message" data-preapproved="true">
						You're preapproved for up to $<?php echo esc_html( $max_amount ); ?>
					</span>
					<?php
					return;
				}
			}
		}

		// Default: show "Check your spending power" link.
		// This covers: no preapproval, NOT_APPROVED, pending, expired, or no valid amount.
		?>
		<a href="#"
			class="avvance-prequal-link"
			data-session-id="<?php echo esc_attr( $session_id ); ?>">
			Check your spending power
		</a>
		<?php
	}

	/**
	 * Ensure modals are rendered on relevant pages (footer hook).
	 */
	public static function ensure_modal_rendered() {
		if ( ! is_cart() && ! is_checkout() && ! is_shop() && ! is_product_category() && ! is_product_tag() && ! is_product() ) {
			return;
		}

		static $modal_rendered_in_footer = false;
		if ( $modal_rendered_in_footer ) {
			return;
		}

		global $avvance_modal_rendered;
		if ( ! empty( $avvance_modal_rendered ) ) {
			return;
		}

		// Modal A: checkout context only ("Learn more").
		if ( is_checkout() ) {
			self::render_modal_a();
		}

		// Modal B and C: all relevant pages.
		self::render_modal_b();
		self::render_modal_c();

		$modal_rendered_in_footer = true;
		$avvance_modal_rendered   = true;
	}

	/**
	 * Render Modal A — checkout "Learn more".
	 *
	 * No sticky CTA. Shows loan options and checkout steps.
	 */
	private static function render_modal_a() {
		global $avvance_modal_rendered;
		$avvance_modal_rendered = true;
		$logo_url               = AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg';
		$icon_base              = AVVANCE_PLUGIN_URL . 'assets/images/';
		?>
		<div id="avvance-modal-a" class="avvance-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="avvance-modal-a-heading">
			<div class="avvance-modal-overlay" aria-hidden="true"></div>
			<div class="avvance-modal-dialog">
				<div class="avvance-modal-sticky-cta-closeicon">
					<button class="avvance-modal-close" aria-label="Close dialog"><img src="<?php echo esc_url( $icon_base . 'Close.svg' ); ?>" alt="" aria-hidden="true"></button>
				</div>
				<div class="avvance-modal-scrollable">
					<div class="avvance-modal-header">
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="U.S. Bank Avvance" class="avvance-modal-logo-img">
					</div>
					<div class="avvance-modal-body">
						<h2 id="avvance-modal-a-heading" class="avvance-modal-heading">Pay over time and make your purchase possible</h2>
						<p class="avvance-modal-subtitle">Applying won't impact your credit score.</p>

					</div>
					<div class="avvance-modal-body-calculator">
						<div class="avvance-calculator-row" data-target="avvance-modal-a-cards">
							<div>
								<span class="avvance-calculator-label">Sample loan options for:</span>
								<input type="text" class="avvance-currency-input" id="avvance-modal-a-amount" value="" aria-label="Loan amount">
							</div>
							<button type="button" class="avvance-calc-btn">Calculate</button>
						</div>

						<div class="avvance-loan-cards" id="avvance-modal-a-cards" aria-live="polite" aria-atomic="true"></div>
					</div>

					<div class="avvance-carousel-section">
						<div class="avvance-carousel-title">
							<span>How to checkout</span>
							<div class="avvance-carousel-nav">
								<button class="avvance-arrow-nav" data-slider="avvance-slider-modal-a" data-dots="avvance-dots-modal-a" data-dir="-1" aria-label="Previous"><img src="<?php echo esc_url( $icon_base . 'chevron-left.svg' ); ?>" alt="Previous"></button>
								<button class="avvance-arrow-nav" data-slider="avvance-slider-modal-a" data-dots="avvance-dots-modal-a" data-dir="1" aria-label="Next"><img src="<?php echo esc_url( $icon_base . 'chevron-right.svg' ); ?>" alt="Next"></button>
							</div>
						</div>

						<div class="avvance-carousel-container" id="avvance-slider-modal-a">
							<div class="avvance-slide active">
								<img src="<?php echo esc_url( $icon_base . 'Checkmark.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">Select “Pay with U.S. Bank Avvance” at checkout.</span>
							</div>
							<div class="avvance-slide" aria-hidden="true">
								<img src="<?php echo esc_url( $icon_base . 'Money_stack.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">Apply and if approved, see your loan options.</span>
							</div>
							<div class="avvance-slide" aria-hidden="true">
								<img src="<?php echo esc_url( $icon_base . 'Shopping_cart.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">Choose your loan and complete your purchase.</span>
							</div>
						</div>

						<div class="avvance-slider-dots" id="avvance-dots-modal-a">
							<button class="avvance-dot active" data-slider="avvance-slider-modal-a" data-dots="avvance-dots-modal-a" data-index="0" aria-label="Step 1" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" alt=""></button>
							<button class="avvance-dot" data-slider="avvance-slider-modal-a" data-dots="avvance-dots-modal-a" data-index="1" aria-label="Step 2" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>" alt=""></button>
							<button class="avvance-dot" data-slider="avvance-slider-modal-a" data-dots="avvance-dots-modal-a" data-index="2" aria-label="Step 3" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>" alt=""></button>
						</div>
					</div>

					<p class="avvance-disclaimer">
						Annual Percentage Rates (APR) range from 0%-24.99%. Not all rates are available for all merchants. 0% APR loan options, including promotions, may be available depending on merchant participation and customer qualification. All rates are subject to an eligibility check and approval. Maximum loan amounts and available loan options provided by U.S. Bank depend on your credit score and purchase amount. Loan options with promotion rates will have a higher cost if the loan is held until maturity.
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Modal B — "Check your spending power".
	 *
	 * Has sticky "See if you qualify" CTA.
	 */
	private static function render_modal_b() {
		$logo_url  = AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg';
		$icon_base = AVVANCE_PLUGIN_URL . 'assets/images/';
		?>
		<div id="avvance-modal-b" class="avvance-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="avvance-modal-b-heading">
			<div class="avvance-modal-overlay" aria-hidden="true"></div>
			<div class="avvance-modal-dialog">
				<div class="avvance-modal-sticky-cta-closeicon">
					<button class="avvance-modal-close" aria-label="Close dialog"><img src="<?php echo esc_url( $icon_base . 'Close.svg' ); ?>" alt="" aria-hidden="true"></button>
				</div>
				<div class="avvance-modal-scrollable avvance-modal-scrollable--has-cta">
					<div class="avvance-modal-header">
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="U.S. Bank Avvance" class="avvance-modal-logo-img">
					</div>

					<div class="avvance-modal-body">
						<h2 id="avvance-modal-b-heading" class="avvance-modal-heading">Pay over time and make your purchase possible</h2>
						<p class="avvance-modal-subtitle">Applying won't impact your credit score.</p>

					</div>
					<div class="avvance-modal-body-calculator"> 
						<div class="avvance-calculator-row" data-target="avvance-modal-b-cards">
							<div>
								<span class="avvance-calculator-label">Sample loan options for:</span>
								<input type="text" class="avvance-currency-input" id="avvance-modal-b-amount" value="" aria-label="Loan amount">
							</div>
							<button type="button" class="avvance-calc-btn">Calculate</button>
						</div>

						<div class="avvance-loan-cards" id="avvance-modal-b-cards" aria-live="polite" aria-atomic="true"></div>
					</div>

					<div class="avvance-carousel-section">
						<div class="avvance-carousel-title">
							<span>How to get pre-approved</span>
							<div class="avvance-carousel-nav">
								<button class="avvance-arrow-nav" data-slider="avvance-slider-modal-b" data-dots="avvance-dots-modal-b" data-dir="-1" aria-label="Previous"><img src="<?php echo esc_url( $icon_base . 'chevron-left.svg' ); ?>" alt="Previous"></button>
								<button class="avvance-arrow-nav" data-slider="avvance-slider-modal-b" data-dots="avvance-dots-modal-b" data-dir="1" aria-label="Next"><img src="<?php echo esc_url( $icon_base . 'chevron-right.svg' ); ?>" alt="Next"></button>
							</div>
						</div>

						<div class="avvance-carousel-container" id="avvance-slider-modal-b">
							<div class="avvance-slide active">
								<img src="<?php echo esc_url( $icon_base . 'Checklist.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">Apply to see if you qualify.</span>
							</div>
							<div class="avvance-slide" aria-hidden="true">
								<img src="<?php echo esc_url( $icon_base . 'Money_stack.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">If approved, see your spending power.</span>
							</div>
							<div class="avvance-slide" aria-hidden="true">
								<img src="<?php echo esc_url( $icon_base . 'Calculator.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">Calculate your monthly payments.</span>
							</div>
						</div>

						<div class="avvance-slider-dots" id="avvance-dots-modal-b">
							<button class="avvance-dot active" data-slider="avvance-slider-modal-b" data-dots="avvance-dots-modal-b" data-index="0" aria-label="Step 1" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" alt=""></button>
							<button class="avvance-dot" data-slider="avvance-slider-modal-b" data-dots="avvance-dots-modal-b" data-index="1" aria-label="Step 2" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>" alt=""></button>
							<button class="avvance-dot" data-slider="avvance-slider-modal-b" data-dots="avvance-dots-modal-b" data-index="2" aria-label="Step 3" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>" alt=""></button>
						</div>
					</div>

					<p class="avvance-disclaimer">
						Annual Percentage Rates (APR) range from 0%-24.99%. Not all rates are available for all merchants. 0% APR loan options, including promotions, may be available depending on merchant participation and customer qualification. All rates are subject to an eligibility check and approval. Maximum loan amounts and available loan options provided by U.S. Bank depend on your credit score and purchase amount. Loan options with promotion rates will have a higher cost if the loan is held until maturity.
					</p>
				</div>

				<div class="avvance-modal-sticky-cta">
					<button type="button" class="avvance-btn-primary avvance-qualify-button">
						See if you qualify
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Modal C — pre-approved "See your details".
	 *
	 * Has sticky "Continue shopping" CTA.
	 */
	private static function render_modal_c() {
		$preapproval      = self::get_current_preapproval();
		$max_amount_raw   = ( $preapproval && isset( $preapproval['max_amount'] ) ) ? floatval( $preapproval['max_amount'] ) : 0;
		$max_amount_fmt   = number_format( $max_amount_raw, 2 );
		$max_amount_short = number_format( $max_amount_raw, 0 );
		$expiry_date      = '';
		if ( $preapproval && ! empty( $preapproval['expiry_date'] ) ) {
			$expiry_timestamp = strtotime( $preapproval['expiry_date'] );
			if ( $expiry_timestamp ) {
				$expiry_date = gmdate( 'M j, Y', $expiry_timestamp );
			}
		}
		$min_amount     = self::$settings['min_amount'];
		$min_amount_fmt = number_format( $min_amount, 0 );
		$retailer_name  = get_bloginfo( 'name' );
		$logo_url       = AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg';
		$icon_base      = AVVANCE_PLUGIN_URL . 'assets/images/';
		?>
		<div id="avvance-modal-c" class="avvance-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="avvance-modal-c-heading"
			data-max-amount="<?php echo esc_attr( $max_amount_raw ); ?>">
			<div class="avvance-modal-overlay" aria-hidden="true"></div>
			<div class="avvance-modal-dialog">
				<div class="avvance-modal-sticky-cta-closeicon">
					<button class="avvance-modal-close" aria-label="Close dialog"><img src="<?php echo esc_url( $icon_base . 'Close.svg' ); ?>" alt="" aria-hidden="true"></button>
				</div>
				<div class="avvance-modal-scrollable avvance-modal-scrollable--has-cta">
					<div class="avvance-modal-header">
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="U.S. Bank Avvance" class="avvance-modal-logo-img">
					</div>

					<div class="avvance-modal-body">
						<div class="avvance-success-banner">
							<div class="avvance-success-title" id="avvance-modal-c-heading">
								<img src="<?php echo esc_url( $icon_base . 'Avvance-Checkmark.svg' ); ?>" alt="" class="avvance-success-check-icon">
								Your spending power is $<?php echo esc_html( $max_amount_short ); ?>!
							</div>
						</div>
						<div class="avvance-success-details">
							<div class="avvance-detail-row">
								<span class="avvance-detail-label">Single-purchase range:</span>
								<span class="avvance-detail-value">$<?php echo esc_html( $min_amount_fmt ); ?>–$<?php echo esc_html( $max_amount_short ); ?></span>
							</div>
							<div class="avvance-detail-row">
        						<span class="avvance-detail-label">Eligible Merchant:</span>
        						<span class="avvance-detail-value"><?php echo esc_html( $retailer_name ); ?></span>
    						</div>
							<?php if ( $expiry_date ) : ?>
							<div class="avvance-detail-row">
        						<span class="avvance-detail-label">Offer Expires:</span>
        						<span class="avvance-detail-value"><?php echo esc_html( $expiry_date ); ?></span>
    						</div>
							<?php endif; ?>
						</div>
						<div class="avvance-modal-body-calculator">
							<div class="avvance-calculator-row" data-target="avvance-modal-c-cards">
								<div>	
								<span class="avvance-calculator-label">See your loan options for:</span>
									<input type="text" class="avvance-currency-input" id="avvance-modal-c-amount" value="$<?php echo esc_attr( $max_amount_fmt ); ?>" aria-label="Loan amount">
								</div>
								<button type="button" class="avvance-calc-btn">Calculate</button>
							</div>
						</div>	

						<div class="avvance-loan-cards" id="avvance-modal-c-cards" aria-live="polite" aria-atomic="true"></div>
						<button class="avvance-see-more-btn" aria-label="See more loan options">See more loan options</button>
					</div>

					<div class="avvance-carousel-section">
						<div class="avvance-carousel-title">
							<span>How to checkout</span>
							<div class="avvance-carousel-nav">
								<button class="avvance-arrow-nav" data-slider="avvance-slider-modal-c" data-dots="avvance-dots-modal-c" data-dir="-1" aria-label="Previous"><img src="<?php echo esc_url( $icon_base . 'chevron-left.svg' ); ?>" alt="Previous"></button>
								<button class="avvance-arrow-nav" data-slider="avvance-slider-modal-c" data-dots="avvance-dots-modal-c" data-dir="1" aria-label="Next"><img src="<?php echo esc_url( $icon_base . 'chevron-right.svg' ); ?>" alt="Next"></button>
							</div>
						</div>

						<div class="avvance-carousel-container" id="avvance-slider-modal-c">
							<div class="avvance-slide active">
								<img src="<?php echo esc_url( $icon_base . 'Checkmark.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">Select “Pay with U.S. Bank Avvance” at checkout.</span>
							</div>
							<div class="avvance-slide" aria-hidden="true">
								<img src="<?php echo esc_url( $icon_base . 'Money_stack.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">Choose the loan that works best for you.</span>
							</div>
							<div class="avvance-slide" aria-hidden="true">
								<img src="<?php echo esc_url( $icon_base . 'Shopping_cart.svg' ); ?>" class="avvance-step-icon" alt="">
								<span class="avvance-step-text">Review terms and complete your purchase.</span>
							</div>
						</div>

						<div class="avvance-slider-dots" id="avvance-dots-modal-c">
							<button class="avvance-dot active" data-slider="avvance-slider-modal-c" data-dots="avvance-dots-modal-c" data-index="0" aria-label="Step 1" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" alt=""></button>
							<button class="avvance-dot" data-slider="avvance-slider-modal-c" data-dots="avvance-dots-modal-c" data-index="1" aria-label="Step 2" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>" alt=""></button>
							<button class="avvance-dot" data-slider="avvance-slider-modal-c" data-dots="avvance-dots-modal-c" data-index="2" aria-label="Step 3" data-active-img="<?php echo esc_url( $icon_base . 'ellipse-active.svg' ); ?>" data-inactive-img="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>"><img src="<?php echo esc_url( $icon_base . 'Ellipse.svg' ); ?>" alt=""></button>
						</div>
					</div>

					<p class="avvance-disclaimer">
						Annual Percentage Rates (APR) range from 0%-24.99%. Not all rates are available for all merchants. 0% APR loan options, including promotions, may be available depending on merchant participation and customer qualification. All rates are subject to an eligibility check and approval. Maximum loan amounts and available loan options provided by U.S. Bank depend on your credit score and purchase amount. Loan options with promotion rates will have a higher cost if the loan is held until maturity.
					</p>
				</div>

				<div class="avvance-modal-sticky-cta">
					<button type="button" class="avvance-btn-primary avvance-continue-shopping-btn">
						Continue shopping
					</button>
				</div>
			</div>
		</div>
		<?php
	}
}
