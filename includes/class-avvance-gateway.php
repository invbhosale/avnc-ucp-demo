<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class name follows WooCommerce convention
/**
 * Avvance Payment Gateway
 *
 * @package Avvance_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Avvance payment gateway.
 */
class WC_Gateway_Avvance extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'avvance';
		$this->method_title       = __( 'U.S. Bank Avvance', 'avvance-for-woocommerce' );
		$this->method_description = __( 'Grow your business with U.S. Bank Avvance®. Log into the Avvance Merchant Portal to retrieve your activation settings. If you haven’t already signed up, visit <a href="https://avvance.usbank.com/businesses.html" target="_blank" rel="noopener noreferrer">Avvance.com</a> to get started.', 'avvance-for-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		// Load settings.
		$this->init_form_fields();
		$this->init_settings();

		// Get settings.
		$this->enabled = $this->get_option( 'enabled' );

		// Clean title for orders and admin (what gets saved to order meta).
		$this->title = 'U.S. Bank Avvance';

		// Description shown on checkout.
		$this->description = "To view payment options that you may qualify for, select 'Pay with U.S. Bank Avvance' to leave this site and enter the U.S. Bank Avvance loan application in a new window. Qualification for payment options are subject to application approval.\n\nImportant: After completing your application, please return to this window to see your order confirmation. Keep this window open during your application.";

		// Filter to show marketing message on checkout page only.
		add_filter( 'woocommerce_gateway_title', array( $this, 'customize_checkout_title' ), 10, 2 );
				// Set icon.
				$this->icon = AVVANCE_PLUGIN_URL . 'assets/images/avvance-icon.svg';

		// Hooks.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'validate_api_credentials' ), 20 );
		add_action( 'admin_notices', array( $this, 'show_credential_error_notice' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_filter( 'woocommerce_endpoint_order-received_title', array( $this, 'customize_order_received_title' ), 10, 2 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'customize_order_received_text' ), 10, 2 );
		add_action( 'wp_ajax_avvance_check_order_status', array( $this, 'ajax_check_order_status' ) );
		add_action( 'wp_ajax_nopriv_avvance_check_order_status', array( $this, 'ajax_check_order_status' ) );
	}

	/**
	 * Initialize form fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                 => array(
				'title'   => __( 'Enable/Disable', 'avvance-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Avvance', 'avvance-for-woocommerce' ),
				'default' => 'no',
			),
			'environment'             => array(
				'title'   => __( 'Environment', 'avvance-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'sandbox'    => __( 'Sandbox (Testing)', 'avvance-for-woocommerce' ),
					'production' => __( 'Production', 'avvance-for-woocommerce' ),
				),
				'default' => 'sandbox',
			),
			'api_credentials_title'   => array(
				'title'       => __( 'API Credentials', 'avvance-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Enter your Avvance API credentials from the Avvance Merchant Portal.', 'avvance-for-woocommerce' ),
			),
			'client_key'              => array(
				'title'       => __( 'Client Key', 'avvance-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your Avvance OAuth Client Key', 'avvance-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'client_secret'           => array(
				'title'       => __( 'Client Secret', 'avvance-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your Avvance OAuth Client Secret', 'avvance-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'merchant_id'             => array(
				'title'       => __( 'Merchant ID', 'avvance-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your Elavon Merchant ID (MID)', 'avvance-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'partner_id'              => array(
				'title'       => __( 'Partner ID', 'avvance-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your Avvance Partner ID (provided by Avvance). Used to identify your integration in API requests.', 'avvance-for-woocommerce' ),
				'desc_tip'    => true,
				'placeholder' => 'e.g., CONVERGE',
			),
			'webhook_auth_token'      => array(
				'title'       => __( 'Authentication Token', 'avvance-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your authentication token from the Avvance Merchant Portal.', 'avvance-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),

			// ==========================================.
			// WIDGET DISPLAY SETTINGS SECTION.
			// ==========================================.
			'widget_settings_title'   => array(
				'title'       => __( 'Widget Display Settings', 'avvance-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Control where Avvance payment messaging appears on your store.', 'avvance-for-woocommerce' ),
			),

			// Category page widget.
			'category_widget_enabled' => array(
				'title'       => __( 'Category Page Widget', 'avvance-for-woocommerce' ),
				'label'       => __( 'Show payment messaging on shop/category pages', 'avvance-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Display "Pay as low as $X/mo" under each product in shop and category listings.', 'avvance-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),

			// Product page widget.
			'product_widget_enabled'  => array(
				'title'       => __( 'Product Page Widget', 'avvance-for-woocommerce' ),
				'label'       => __( 'Show payment messaging on product pages', 'avvance-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Display financing information on individual product pages.', 'avvance-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),

			// Product widget position.
			'product_widget_position' => array(
				'title'       => __( 'Product Widget Position', 'avvance-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Choose where the widget appears on product pages.', 'avvance-for-woocommerce' ),
				'default'     => 'after_price',
				'options'     => array(
					'after_price'    => __( 'After product price (Recommended)', 'avvance-for-woocommerce' ),
					'after_add_cart' => __( 'After Add to Cart button', 'avvance-for-woocommerce' ),
					'both'           => __( 'Both locations', 'avvance-for-woocommerce' ),
				),
				'desc_tip'    => true,
			),

			// Cart page widget.
			'cart_widget_enabled'     => array(
				'title'       => __( 'Cart Page Widget', 'avvance-for-woocommerce' ),
				'label'       => __( 'Show payment messaging on cart page', 'avvance-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Display financing options based on cart total.', 'avvance-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),

			// Checkout widget.
			'checkout_widget_enabled' => array(
				'title'       => __( 'Checkout Widget', 'avvance-for-woocommerce' ),
				'label'       => __( 'Show payment details on checkout page', 'avvance-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Display payment messaging when Avvance is selected as the payment method.', 'avvance-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),

			// ==========================================.
			// WIDGET APPEARANCE SETTINGS.
			// ==========================================.
			'widget_appearance_title' => array(
				'title'       => __( 'Widget Appearance', 'avvance-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Customize the look and feel of Avvance widgets.', 'avvance-for-woocommerce' ),
			),

			// Theme/Color.
			'widget_theme'            => array(
				'title'       => __( 'Widget Theme', 'avvance-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Color scheme for the payment messaging widgets.', 'avvance-for-woocommerce' ),
				'default'     => 'light',
				'options'     => array(
					'light' => __( 'Light (for light backgrounds)', 'avvance-for-woocommerce' ),
					'dark'  => __( 'Dark (for dark backgrounds)', 'avvance-for-woocommerce' ),
				),
				'desc_tip'    => true,
			),

			// Show Logo.
			'widget_show_logo'        => array(
				'title'       => __( 'Show Avvance Logo', 'avvance-for-woocommerce' ),
				'label'       => __( 'Display the Avvance logo in widget messaging', 'avvance-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'When disabled, "Avvance" text will be shown instead of the logo.', 'avvance-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),

			// ==========================================.
			// ELIGIBILITY SETTINGS.
			// ==========================================.
			'eligibility_title'       => array(
				'title'       => __( 'Eligibility Settings', 'avvance-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Configure minimum and maximum order amounts for Avvance financing.', 'avvance-for-woocommerce' ),
			),

			'min_order_amount'        => array(
				'title'             => __( 'Minimum Order Amount', 'avvance-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Minimum order amount for Avvance to be available (in dollars). Widgets will not show for amounts below this.', 'avvance-for-woocommerce' ),
				'default'           => '300',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			'max_order_amount'        => array(
				'title'             => __( 'Maximum Order Amount', 'avvance-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Maximum order amount for Avvance (in dollars). Widgets will not show for amounts above this.', 'avvance-for-woocommerce' ),
				'default'           => '25000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			'debug_mode'              => array(
				'title'       => __( 'Debug Mode', 'avvance-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable debug logging', 'avvance-for-woocommerce' ),
				'description' => __( 'Log API requests and responses to WooCommerce logs', 'avvance-for-woocommerce' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Payment fields (show disclosure)
	 */
	public function payment_fields() {
		// Show disclosure description.
		if ( $this->description ) {
			echo '<div class="avvance-description">';
			echo wp_kses_post( wpautop( wp_kses_post( $this->description ) ) );
			echo '</div>';
		}
	}

	/**
	 * Customize the gateway title for checkout page display.
	 * Shows marketing message on checkout, clean title everywhere else (orders, admin, emails).
	 *
	 * @param string $title      Gateway title.
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	public function customize_checkout_title( $title, $gateway_id ) {
		// Only modify our gateway's title.
		if ( $gateway_id !== $this->id ) {
			return $title;
		}

		// Show marketing message on checkout and order-pay pages (frontend), not order-received.
		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			return 'Pay over time with U.S. Bank Avvance <a href="https://www.usbank.com/avvance-installment-loans.html" target="_blank" rel="noopener noreferrer" style="font-size: 0.9em;">Learn more</a>';
		}

		// Return clean title for orders, admin, emails, thank you page, etc.
		return $title;
	}

	/**
	 * Check if gateway is available
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		// Check if credentials are configured.
		if ( empty( $this->get_option( 'client_key' ) ) ||
			empty( $this->get_option( 'client_secret' ) ) ||
			empty( $this->get_option( 'merchant_id' ) ) ) {
			return false;
		}

		// Check currency (USD only).
		if ( 'USD' !== get_woocommerce_currency() ) {
			return false;
		}

		// Check order total on the order-pay page (cart may be empty).
		$min = floatval( $this->get_option( 'min_order_amount', 300 ) );
		$max = floatval( $this->get_option( 'max_order_amount', 25000 ) );

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			global $wp;
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			$order    = wc_get_order( $order_id );

			if ( $order ) {
				$total = $order->get_total();
				if ( $total < $min || $total > $max ) {
					return false;
				}
				return true;
			}
		}

		// Check cart total using configured min/max amounts.
		if ( WC()->cart ) {
			$total = WC()->cart->get_total( '' );

			if ( $total < $min || $total > $max ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Process payment.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Validate order amount using configured min/max.
		$total = $order->get_total();
		$min   = floatval( $this->get_option( 'min_order_amount', 300 ) );
		$max   = floatval( $this->get_option( 'max_order_amount', 25000 ) );

		if ( $total < $min || $total > $max ) {
			wc_add_notice(
				sprintf(
					/* translators: %1$s: minimum order amount, %2$s: maximum order amount */
					__( 'U.S. Bank Avvance financing is available for orders between $%1$s and $%2$s.', 'avvance-for-woocommerce' ),
					number_format( $min, 2 ),
					number_format( $max, 2 )
				),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		// Get API client.
		$api = new Avvance_API_Client( $this->get_api_settings() );

		// Create financing request.
		$response = $api->create_financing_request( $order );

		if ( is_wp_error( $response ) ) {
			avvance_log( 'Financing request failed for order #' . $order_id . ': ' . $response->get_error_message(), 'error' );
			wc_add_notice( __( 'Unable to process U.S. Bank Avvance payment. Please try again or use another payment method.', 'avvance-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Store response data on order.
		$order->update_meta_data( '_avvance_application_guid', $response['applicationGUID'] );
		$order->update_meta_data( '_avvance_partner_session_id', $response['partnerSessionId'] );
		$order->update_meta_data( '_avvance_consumer_url', $response['consumerOnboardingURL'] );
		$order->update_meta_data( '_avvance_url_created_at', time() );
		$order->add_order_note(
			/* translators: %s: Avvance application GUID */
			sprintf( __( 'Avvance application created. Application ID: %s', 'avvance-for-woocommerce' ), $response['applicationGUID'] )
		);
		$order->save();

		// Store order ID in session for cart resume banner.
		if ( WC()->session ) {
			WC()->session->set( 'avvance_pending_order_id', $order_id );
		}

		avvance_log( 'Order #' . $order_id . ' ready for Avvance. URL: ' . $response['consumerOnboardingURL'] );

		// Check if this is a Blocks checkout (will redirect full page).
		if ( $this->is_blocks_checkout() ) {
			return array(
				'result'   => 'success',
				'redirect' => $response['consumerOnboardingURL'],
			);
		}

		// Classic checkout - redirect to thank you page (will open new window there).
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Change "Order received" title to "Pay for order" for pending Avvance orders.
	 *
	 * @param string $title Original endpoint title.
	 * @param string $id    Endpoint ID.
	 * @return string
	 */
	public function customize_order_received_title( $title, $id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading order ID from URL on thank-you page.
		$order_id = isset( $_GET['key'] ) ? wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( $order && 'avvance' === $order->get_payment_method() && $order->needs_payment() ) {
			return __( 'Pay for order', 'avvance-for-woocommerce' );
		}

		return $title;
	}

	/**
	 * Remove "Thank you. Your order has been received." text for pending Avvance orders.
	 *
	 * @param string   $text  Original thank-you text.
	 * @param WC_Order $order WooCommerce order object.
	 * @return string
	 */
	public function customize_order_received_text( $text, $order ) {
		if ( $order && 'avvance' === $order->get_payment_method() && $order->needs_payment() ) {
			return '';
		}

		return $text;
	}

	/**
	 * Thank you page (classic checkout only).
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		// Only show for pending orders.
		if ( ! $order || ! $order->needs_payment() ) {
			return;
		}

		$url = $order->get_meta( '_avvance_consumer_url' );
		if ( empty( $url ) ) {
			return;
		}

		// Check if URL is expired.
		if ( avvance_is_url_expired( $order_id ) ) {
			echo '<div class="woocommerce-info">';
			echo esc_html__( 'Your U.S. Bank Avvance application link has expired. Please contact us to complete your order.', 'avvance-for-woocommerce' );
			echo '</div>';
			return;
		}

		?>
		<script>
		(function() {
			var orderEl = document.querySelector('.woocommerce-order');
			if (orderEl) { orderEl.classList.add('avvance-pay-for-order'); }
		})();
		</script>
		<div class="avvance-thankyou">
			<h2><?php esc_html_e( 'Complete Your U.S. Bank Avvance Application', 'avvance-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Opening your U.S. Bank Avvance application in a new window...', 'avvance-for-woocommerce' ); ?></p>
			<p id="avvance-status" style="font-weight: bold; color: #0073aa;">
				<?php esc_html_e( 'Waiting for U.S. Bank Avvance application completion...', 'avvance-for-woocommerce' ); ?>
			</p>
			<div id="avvance-manual-link" style="display:none; margin-top: 20px;">
				<p><?php esc_html_e( 'Pop-up blocked? Click below to open your application:', 'avvance-for-woocommerce' ); ?></p>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" class="button"><?php esc_html_e( 'Open U.S. Bank Avvance Application', 'avvance-for-woocommerce' ); ?></a>
			</div>
			<div id="avvance-manual-check" style="display:none; margin-top: 30px;">
				<p><?php esc_html_e( 'Completed your U.S. Bank Avvance application?', 'avvance-for-woocommerce' ); ?></p>
				<button type="button" class="button" id="avvance-check-status-btn">
					<?php esc_html_e( 'Check U.S. Bank Avvance Application Status', 'avvance-for-woocommerce' ); ?></button>
			</div>
		</div>

		<script>
		(function($) {
			var orderId = <?php echo absint( $order_id ); ?>;
			var pollCount = 0;
			var maxPolls = 120; // 10 minutes at 5-second intervals

			// Try to open window.
			var avvanceWindow = window.open('<?php echo esc_js( $url ); ?>', '_blank', 'toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=600,height=700');

			if (!avvanceWindow || avvanceWindow.closed || typeof avvanceWindow.closed === 'undefined') {
				// Pop-up blocked.
				$('#avvance-manual-link').show();
				$('#avvance-status').text('<?php echo esc_js( __( 'Please open your U.S. Bank Avvance application using the button below.', 'avvance-for-woocommerce' ) ); ?>');
			} else {
				// Focus the new window.
				try {
					avvanceWindow.focus();
				} catch(e) {}
			}

			// Show manual check button after 2 minutes.
			setTimeout(function() {
				$('#avvance-manual-check').show();
			}, 120000);

			// Poll order status.
			var statusInterval = setInterval(function() {
				pollCount++;

				$.ajax({
					url: avvanceCheckout.ajaxUrl,
					type: 'GET',
					data: {
						action: 'avvance_check_order_status',
						order_id: orderId
					},
					success: function(response) {
						if (response.success && response.data.status) {
							if (response.data.status === 'completed') {
								clearInterval(statusInterval);
								$('#avvance-status').text('<?php echo esc_js( __( 'Payment completed! Redirecting...', 'avvance-for-woocommerce' ) ); ?>');
								location.reload();
							} else if (response.data.status === 'cancelled' &&
							           response.data.avvance_status === 'VOIDED') {
								clearInterval(statusInterval);
								$('#avvance-status').html('<?php echo esc_js( __( 'Your financing application was voided. Redirecting to cart...', 'avvance-for-woocommerce' ) ); ?>');
								setTimeout(function() {
									window.location = '<?php echo esc_js( wc_get_cart_url() ); ?>';
								}, 4000);
							} else if (response.data.status === 'cancelled') {
								clearInterval(statusInterval);
								$('#avvance-status').html('<?php echo esc_js( __( 'Application declined. Redirecting so you can try again...', 'avvance-for-woocommerce' ) ); ?>');
								setTimeout(function() {
									window.location = <?php echo wp_json_encode( $order->get_checkout_payment_url() ); ?>;
								}, 3000);
							}

							// Check for Avvance declined/error while order is still pending.
							var avvanceStatus = response.data.avvance_status || '';
							var declinedStatuses = [
								'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT',
								'APPLICATION_PARTIALLY_APPROVED',
								'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT'
							];
							if (response.data.status === 'pending' && declinedStatuses.indexOf(avvanceStatus) !== -1) {
								clearInterval(statusInterval);
								$('#avvance-status').html('<?php echo esc_js( __( 'Your U.S. Bank Avvance application was not approved. Redirecting so you can try again...', 'avvance-for-woocommerce' ) ); ?>');
								setTimeout(function() {
									window.location = <?php echo wp_json_encode( $order->get_checkout_payment_url() ); ?>;
								}, 4000);
							}
						}
					}
				});

				// Stop polling after max attempts.
				if (pollCount >= maxPolls) {
					clearInterval(statusInterval);
					$('#avvance-status').text('<?php echo esc_js( __( 'Still waiting? Use the button below to check your status.', 'avvance-for-woocommerce' ) ); ?>');
				}
			}, 5000);

			// Manual check button.
			$('#avvance-check-status-btn').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Checking...', 'avvance-for-woocommerce' ) ); ?>');

				$.ajax({
					url: avvanceCheckout.ajaxUrl,
					type: 'POST',
					data: {
						action: 'avvance_manual_status_check',
						order_id: orderId,
						nonce: '<?php echo esc_attr( wp_create_nonce( 'avvance_manual_check_' . $order_id ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							location.reload();
						} else {
							alert(response.data.message || '<?php echo esc_js( __( 'Unable to check status. Please try again.', 'avvance-for-woocommerce' ) ); ?>');
							$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Check U.S. Bank Avvance Application Status', 'avvance-for-woocommerce' ) ); ?>');
						}
					},
					error: function() {
						alert('<?php echo esc_js( __( 'Unable to check status. Please try again.', 'avvance-for-woocommerce' ) ); ?>');
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Check U.S. Bank Avvance Application Status', 'avvance-for-woocommerce' ) ); ?>');
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * AJAX: Check order status (for polling)
	 */
	public function ajax_check_order_status() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- called via polling from thankyou page, order_id validated via WC order lookup
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Order not found' ) );
		}

		$status = 'pending';

		if ( $order->is_paid() ) {
			$status = 'completed';
		} elseif ( in_array( $order->get_status(), array( 'cancelled', 'failed' ), true ) ) {
			$status = 'cancelled';
		}

		// Include Avvance webhook status so JS can detect declined/error while order is still pending.
		$avvance_status = $order->get_meta( '_avvance_last_webhook_status' );

		wp_send_json_success(
			array(
				'status'         => $status,
				'avvance_status' => $avvance_status ? $avvance_status : '',
			)
		);
	}

	/**
	 * Process refund.
	 *
	 * @param int        $order_id WooCommerce order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
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
	}

	/**
	 * Get API settings
	 */
	private function get_api_settings() {
		return array(
			'client_key'    => $this->get_option( 'client_key' ),
			'client_secret' => $this->get_option( 'client_secret' ),
			'merchant_id'   => $this->get_option( 'merchant_id' ),
			'partner_id'    => $this->get_option( 'partner_id' ),
			'environment'   => $this->get_option( 'environment' ),
		);
	}

	/**
	 * Test API credentials after settings are saved and store a transient on failure.
	 */
	public function validate_api_credentials() {
		$client_key    = $this->get_option( 'client_key' );
		$client_secret = $this->get_option( 'client_secret' );
		$environment   = $this->get_option( 'environment' );

		if ( empty( $client_key ) || empty( $client_secret ) ) {
			return;
		}

		$base_url = ( 'production' === $environment )
			? 'https://alpha-api2.usbank.com'
			: 'https://alpha-api.usbank.com';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required for HTTP Basic Auth
		$auth = base64_encode( $client_key . ':' . $client_secret );

		$response = wp_remote_post(
			$base_url . '/auth/oauth2/v1/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
				'timeout' => 15,
			)
		);

		$success = ! is_wp_error( $response )
			&& 200 === wp_remote_retrieve_response_code( $response )
			&& ! empty( json_decode( wp_remote_retrieve_body( $response ), true )['accessToken'] );

		if ( ! $success ) {
			set_transient( 'avvance_credential_error', 1, 60 );
			avvance_log( 'Credential validation failed after settings save', 'error' );
		} else {
			delete_transient( 'avvance_credential_error' );
		}
	}

	/**
	 * Display admin notice if credential validation failed on last save.
	 */
	public function show_credential_error_notice() {
		if ( ! get_transient( 'avvance_credential_error' ) ) {
			return;
		}
		delete_transient( 'avvance_credential_error' );
		echo '<div class="notice notice-error"><p>';
		echo '<strong>' . esc_html__( 'U.S. Bank Avvance', 'avvance-for-woocommerce' ) . ':</strong> ';
		echo esc_html__( 'Credentials saved but API connection failed — please verify your Client Key and Secret.', 'avvance-for-woocommerce' );
		echo '</p></div>';
	}

	/**
	 * Check if this is a Blocks checkout
	 */
	private function is_blocks_checkout() {
		// Order-pay page always uses classic form, even when checkout page has blocks.
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for checkout
		return isset( $_POST['wc-avvance-payment-token'] ) ||
				( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) );
	}
}
