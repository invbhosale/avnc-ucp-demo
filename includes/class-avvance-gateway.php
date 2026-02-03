<?php
/**
 * Avvance Payment Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_Avvance extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'avvance';
        $this->method_title = __( 'U.S. Bank Avvance', 'avvance-for-woocommerce' );
        $this->method_description = __( 'Offer customers flexible installment financing through U.S. Bank Avvance.', 'avvance-for-woocommerce' );
        $this->has_fields = true;
        $this->supports = ['products', 'refunds'];

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

		// Get settings
		$this->enabled = $this->get_option( 'enabled' );

		// Clean title for orders and admin (what gets saved to order meta)
		$this->title = 'U.S. Bank Avvance';

		// Description shown on checkout
		$this->description = "To view payment options that you may qualify for, select 'Pay with U.S. Bank Avvance' to leave this site and enter the U.S. Bank Avvance loan application in a new window. Qualification for payment options are subject to application approval.\n\nImportant: After completing your application, please return to this window to see your order confirmation. Keep this window open during your application.";

		// Filter to show marketing message on checkout page only
		add_filter( 'woocommerce_gateway_title', [$this, 'customize_checkout_title'], 10, 2 );
				// Set icon
				$this->icon = AVVANCE_PLUGIN_URL . 'assets/images/avvance-icon.svg';

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options'] );
        add_action( 'woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page'] );
        add_action( 'wp_ajax_avvance_check_order_status', [$this, 'ajax_check_order_status'] );
        add_action( 'wp_ajax_nopriv_avvance_check_order_status', [$this, 'ajax_check_order_status'] );
    }

    /**
     * Initialize form fields
     */
	public function init_form_fields() {
        // Generate webhook credentials if they don't exist
        if ( ! $this->get_option( 'webhook_username' ) ) {
            $credentials = avvance_generate_webhook_credentials();
            $this->update_option( 'webhook_username', $credentials['username'] );
            $this->update_option( 'webhook_password', $credentials['password'] );
        }

        $this->form_fields = [
            'enabled' => [
                'title' => __( 'Enable/Disable', 'avvance-for-woocommerce' ),
                'type' => 'checkbox',
                'label' => __( 'Enable Avvance', 'avvance-for-woocommerce' ),
                'default' => 'no'
            ],
            'environment' => [
                'title' => __( 'Environment', 'avvance-for-woocommerce' ),
                'type' => 'select',
                'options' => [
                    'sandbox' => __( 'Sandbox (Testing)', 'avvance-for-woocommerce' ),
                    'production' => __( 'Production', 'avvance-for-woocommerce' ),
                ],
                'default' => 'sandbox'
            ],
            'api_credentials_title' => [
                'title' => __( 'API Credentials', 'avvance-for-woocommerce' ),
                'type' => 'title',
                'description' => __( 'Enter your Avvance API credentials from the Avvance Merchant Portal.', 'avvance-for-woocommerce' ),
            ],
            'client_key' => [
                'title' => __( 'Client Key', 'avvance-for-woocommerce' ),
                'type' => 'text',
                'description' => __( 'Your Avvance OAuth Client Key', 'avvance-for-woocommerce' ),
                'desc_tip' => true,
            ],
            'client_secret' => [
                'title' => __( 'Client Secret', 'avvance-for-woocommerce' ),
                'type' => 'password',
                'description' => __( 'Your Avvance OAuth Client Secret', 'avvance-for-woocommerce' ),
                'desc_tip' => true,
            ],
            'merchant_id' => [
                'title' => __( 'Merchant ID', 'avvance-for-woocommerce' ),
                'type' => 'text',
                'description' => __( 'Your Elavon Merchant ID (MID)', 'avvance-for-woocommerce' ),
                'desc_tip' => true,
            ],
            'hashed_merchant_id' => [
                'title' => __( 'Hashed Merchant ID', 'avvance-for-woocommerce' ),
                'type' => 'text',
                'description' => __( 'Your Hashed Merchant ID for pre-approval (provided by Avvance)', 'avvance-for-woocommerce' ),
                'desc_tip' => true,
                'placeholder' => 'e.g., aa613b14'
            ],

            // ==========================================
            // WIDGET DISPLAY SETTINGS SECTION
            // ==========================================
            'widget_settings_title' => [
                'title' => __( 'Widget Display Settings', 'avvance-for-woocommerce' ),
                'type'  => 'title',
                'description' => __( 'Control where Avvance payment messaging appears on your store.', 'avvance-for-woocommerce' ),
            ],

            // Category page widget
            'category_widget_enabled' => [
                'title'       => __( 'Category Page Widget', 'avvance-for-woocommerce' ),
                'label'       => __( 'Show payment messaging on shop/category pages', 'avvance-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Display "Pay as low as $X/mo" under each product in shop and category listings.', 'avvance-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],

            // Product page widget
            'product_widget_enabled' => [
                'title'       => __( 'Product Page Widget', 'avvance-for-woocommerce' ),
                'label'       => __( 'Show payment messaging on product pages', 'avvance-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Display financing information on individual product pages.', 'avvance-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],

            // Product widget position
            'product_widget_position' => [
                'title'       => __( 'Product Widget Position', 'avvance-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'description' => __( 'Choose where the widget appears on product pages.', 'avvance-for-woocommerce' ),
                'default'     => 'after_price',
                'options'     => [
                    'after_price'    => __( 'After product price (Recommended)', 'avvance-for-woocommerce' ),
                    'after_add_cart' => __( 'After Add to Cart button', 'avvance-for-woocommerce' ),
                    'both'           => __( 'Both locations', 'avvance-for-woocommerce' ),
                ],
                'desc_tip'    => true,
            ],

            // Cart page widget
            'cart_widget_enabled' => [
                'title'       => __( 'Cart Page Widget', 'avvance-for-woocommerce' ),
                'label'       => __( 'Show payment messaging on cart page', 'avvance-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Display financing options based on cart total.', 'avvance-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],

            // Checkout widget
            'checkout_widget_enabled' => [
                'title'       => __( 'Checkout Widget', 'avvance-for-woocommerce' ),
                'label'       => __( 'Show payment details on checkout page', 'avvance-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Display payment messaging when Avvance is selected as the payment method.', 'avvance-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],

            // ==========================================
            // WIDGET APPEARANCE SETTINGS
            // ==========================================
            'widget_appearance_title' => [
                'title' => __( 'Widget Appearance', 'avvance-for-woocommerce' ),
                'type'  => 'title',
                'description' => __( 'Customize the look and feel of Avvance widgets.', 'avvance-for-woocommerce' ),
            ],

            // Theme/Color
            'widget_theme' => [
                'title'       => __( 'Widget Theme', 'avvance-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'description' => __( 'Color scheme for the payment messaging widgets.', 'avvance-for-woocommerce' ),
                'default'     => 'light',
                'options'     => [
                    'light' => __( 'Light (for light backgrounds)', 'avvance-for-woocommerce' ),
                    'dark'  => __( 'Dark (for dark backgrounds)', 'avvance-for-woocommerce' ),
                ],
                'desc_tip'    => true,
            ],

            // Show Logo
            'widget_show_logo' => [
                'title'       => __( 'Show Avvance Logo', 'avvance-for-woocommerce' ),
                'label'       => __( 'Display the Avvance logo in widget messaging', 'avvance-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'When disabled, "Avvance" text will be shown instead of the logo.', 'avvance-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ],

            // ==========================================
            // ELIGIBILITY SETTINGS
            // ==========================================
            'eligibility_title' => [
                'title' => __( 'Eligibility Settings', 'avvance-for-woocommerce' ),
                'type'  => 'title',
                'description' => __( 'Configure minimum and maximum order amounts for Avvance financing.', 'avvance-for-woocommerce' ),
            ],

            'min_order_amount' => [
                'title'       => __( 'Minimum Order Amount', 'avvance-for-woocommerce' ),
                'type'        => 'number',
                'description' => __( 'Minimum order amount for Avvance to be available (in dollars). Widgets will not show for amounts below this.', 'avvance-for-woocommerce' ),
                'default'     => '300',
                'custom_attributes' => [
                    'min'  => '0',
                    'step' => '1',
                ],
                'desc_tip'    => true,
            ],

            'max_order_amount' => [
                'title'       => __( 'Maximum Order Amount', 'avvance-for-woocommerce' ),
                'type'        => 'number',
                'description' => __( 'Maximum order amount for Avvance (in dollars). Widgets will not show for amounts above this.', 'avvance-for-woocommerce' ),
                'default'     => '25000',
                'custom_attributes' => [
                    'min'  => '0',
                    'step' => '1',
                ],
                'desc_tip'    => true,
            ],

            'webhook_title' => [
                'title' => __( 'Webhook Configuration', 'avvance-for-woocommerce' ),
                'type' => 'title',
                'description' => __( 'Provide these details to Avvance Support to register your webhook endpoint. This webhook will receive both loan status updates and pre-approval notifications.', 'avvance-for-woocommerce' ),
            ],
            'webhook_url' => [
                'title' => __( 'Webhook URL', 'avvance-for-woocommerce' ),
                'type' => 'text',
                'description' => __( 'Provide this URL to Avvance Support for both loan status and pre-approval webhooks', 'avvance-for-woocommerce' ),
                'default' => WC()->api_request_url( 'avvance_webhook' ),
                'custom_attributes' => ['readonly' => 'readonly'],
                'desc_tip' => true,
            ],
            'webhook_username' => [
                'title' => __( 'Webhook Username', 'avvance-for-woocommerce' ),
                'type' => 'text',
                'description' => __( 'Provide this username to Avvance Support for Basic Auth', 'avvance-for-woocommerce' ),
                'custom_attributes' => ['readonly' => 'readonly'],
                'desc_tip' => true,
            ],
            'webhook_password' => [
                'title' => __( 'Webhook Password', 'avvance-for-woocommerce' ),
                'type' => 'text',
                'description' => __( 'Provide this password to Avvance Support for Basic Auth', 'avvance-for-woocommerce' ),
                'custom_attributes' => ['readonly' => 'readonly'],
                'desc_tip' => true,
            ],
            'debug_mode' => [
                'title' => __( 'Debug Mode', 'avvance-for-woocommerce' ),
                'type' => 'checkbox',
                'label' => __( 'Enable debug logging', 'avvance-for-woocommerce' ),
                'description' => __( 'Log API requests and responses to WooCommerce logs', 'avvance-for-woocommerce' ),
                'default' => 'no',
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Payment fields (show disclosure)
     */
	public function payment_fields() {
		// Show disclosure description
		if ( $this->description ) {
			echo '<div class="avvance-description">';
			echo wp_kses_post( wpautop( wp_kses_post( $this->description ) ) );
			echo '</div>';
		}
	}

	/**
	 * Customize the gateway title for checkout page display
	 * Shows marketing message on checkout, clean title everywhere else (orders, admin, emails)
	 */
	public function customize_checkout_title( $title, $gateway_id ) {
		// Only modify our gateway's title
		if ( $gateway_id !== $this->id ) {
			return $title;
		}

		// Show marketing message only on checkout page (frontend)
		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			return 'Pay over time with <img src="' . esc_url( AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg' ) . '" alt="Avvance" style="height: 24px; vertical-align: middle; margin: 0 8px;"> <a href="https://www.usbank.com/avvance-installment-loans.html" target="_blank" rel="noopener noreferrer" style="font-size: 0.9em;">Learn more</a>';
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

        // Check if credentials are configured
        if ( empty( $this->get_option( 'client_key' ) ) ||
            empty( $this->get_option( 'client_secret' ) ) ||
            empty( $this->get_option( 'merchant_id' ) ) ) {
            return false;
        }

        // Check currency (USD only)
        if ( 'USD' !== get_woocommerce_currency() ) {
            return false;
        }

        // Check cart total using configured min/max amounts
        if ( WC()->cart ) {
            $total = WC()->cart->get_total( '' );
            $min = floatval( $this->get_option( 'min_order_amount', 300 ) );
            $max = floatval( $this->get_option( 'max_order_amount', 25000 ) );

            if ( $total < $min || $total > $max ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Process payment
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        // Validate order amount using configured min/max
        $total = $order->get_total();
        $min = floatval( $this->get_option( 'min_order_amount', 300 ) );
        $max = floatval( $this->get_option( 'max_order_amount', 25000 ) );

        if ( $total < $min || $total > $max ) {
            wc_add_notice(
                /* translators: %1$s: minimum order amount, %2$s: maximum order amount */
                sprintf(
                    __( 'Avvance financing is available for orders between $%1$s and $%2$s.', 'avvance-for-woocommerce' ),
                    number_format( $min, 2 ),
                    number_format( $max, 2 )
                ),
                'error'
            );
            return ['result' => 'failure'];
        }

        // Get API client
        $api = new Avvance_API_Client( $this->get_api_settings() );

        // Create financing request
        $response = $api->create_financing_request( $order );

        if ( is_wp_error( $response ) ) {
            avvance_log( 'Financing request failed for order #' . $order_id . ': ' . $response->get_error_message(), 'error' );
            wc_add_notice( __( 'Unable to process Avvance payment. Please try again or use another payment method.', 'avvance-for-woocommerce' ), 'error' );
            return ['result' => 'failure'];
        }

        // Store response data on order
        $order->update_meta_data( '_avvance_application_guid', $response['applicationGUID'] );
        $order->update_meta_data( '_avvance_partner_session_id', $response['partnerSessionId'] );
        $order->update_meta_data( '_avvance_consumer_url', $response['consumerOnboardingURL'] );
        $order->update_meta_data( '_avvance_url_created_at', time() );
        $order->add_order_note(
            /* translators: %s: Avvance application GUID */
            sprintf( __( 'Avvance application created. Application ID: %s', 'avvance-for-woocommerce' ), $response['applicationGUID'] )
        );
        $order->save();

        // Store order ID in session for cart resume banner
        if ( WC()->session ) {
            WC()->session->set( 'avvance_pending_order_id', $order_id );
        }

        avvance_log( 'Order #' . $order_id . ' ready for Avvance. URL: ' . $response['consumerOnboardingURL'] );

        // Check if this is a Blocks checkout (will redirect full page)
        if ( $this->is_blocks_checkout() ) {
            return [
                'result' => 'success',
                'redirect' => $response['consumerOnboardingURL']
            ];
        }

        // Classic checkout - redirect to thank you page (will open new window there)
        return [
            'result' => 'success',
            'redirect' => $this->get_return_url( $order )
        ];
    }

    /**
     * Thank you page (classic checkout only)
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );

        // Only show for pending orders
        if ( ! $order || ! $order->needs_payment() ) {
            return;
        }

        $url = $order->get_meta( '_avvance_consumer_url' );
        if ( empty( $url ) ) {
            return;
        }

        // Check if URL is expired
        if ( avvance_is_url_expired( $order_id ) ) {
            echo '<div class="woocommerce-info">';
            echo esc_html__( 'Your Avvance application link has expired. Please contact us to complete your order.', 'avvance-for-woocommerce' );
            echo '</div>';
            return;
        }

        ?>
        <div class="avvance-thankyou">
            <h2><?php esc_html_e( 'Complete Your Avvance Application', 'avvance-for-woocommerce' ); ?></h2>
            <p><?php esc_html_e( 'Opening your Avvance application in a new window...', 'avvance-for-woocommerce' ); ?></p>
            <p id="avvance-status" style="font-weight: bold; color: #0073aa;">
                <?php esc_html_e( 'Waiting for application completion...', 'avvance-for-woocommerce' ); ?>
            </p>
            <div id="avvance-manual-link" style="display:none; margin-top: 20px;">
                <p><?php esc_html_e( 'Pop-up blocked? Click below to open your application:', 'avvance-for-woocommerce' ); ?></p>
                <a href="<?php echo esc_url( $url ); ?>" target="_blank" class="button"><?php esc_html_e( 'Open Avvance Application', 'avvance-for-woocommerce' ); ?></a>
            </div>
            <div id="avvance-manual-check" style="display:none; margin-top: 30px;">
                <p><?php esc_html_e( 'Completed your application?', 'avvance-for-woocommerce' ); ?></p>
                <button type="button" class="button" id="avvance-check-status-btn">
                    <?php esc_html_e( 'Check Application Status', 'avvance-for-woocommerce' ); ?></button>
            </div>
        </div>

        <script>
        (function($) {
            var orderId = <?php echo absint( $order_id ); ?>;
            var pollCount = 0;
            var maxPolls = 120; // 10 minutes at 5-second intervals

            // Try to open window
            var avvanceWindow = window.open('<?php echo esc_js( $url ); ?>', '_blank', 'toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=600,height=700');

            if (!avvanceWindow || avvanceWindow.closed || typeof avvanceWindow.closed === 'undefined') {
                // Pop-up blocked
                $('#avvance-manual-link').show();
                $('#avvance-status').text('<?php echo esc_js( __( 'Please open your Avvance application using the button below.', 'avvance-for-woocommerce' ) ); ?>');
            } else {
                // Focus the new window
                try {
                    avvanceWindow.focus();
                } catch(e) {}
            }

            // Show manual check button after 2 minutes
            setTimeout(function() {
                $('#avvance-manual-check').show();
            }, 120000);

            // Poll order status
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
                            } else if (response.data.status === 'cancelled') {
                                clearInterval(statusInterval);
                                $('#avvance-status').html('<?php echo esc_js( __( 'Application declined. Please choose another payment method.', 'avvance-for-woocommerce' ) ); ?>');
                                setTimeout(function() {
                                    window.location = '<?php echo esc_js( wc_get_cart_url() ); ?>';
                                }, 3000);
                            }
                        }
                    }
                });

                // Stop polling after max attempts
                if (pollCount >= maxPolls) {
                    clearInterval(statusInterval);
                    $('#avvance-status').text('<?php echo esc_js( __( 'Still waiting? Use the button below to check your status.', 'avvance-for-woocommerce' ) ); ?>');
                }
            }, 5000);

            // Manual check button
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
                            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Check Application Status', 'avvance-for-woocommerce' ) ); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js( __( 'Unable to check status. Please try again.', 'avvance-for-woocommerce' ) ); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Check Application Status', 'avvance-for-woocommerce' ) ); ?>');
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
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( ['message' => 'Order not found'] );
        }

        $status = 'pending';

        if ( $order->is_paid() ) {
            $status = 'completed';
        } elseif ( in_array( $order->get_status(), ['cancelled', 'failed'], true ) ) {
            $status = 'cancelled';
        }

        wp_send_json_success( ['status' => $status] );
    }

    /**
     * Process refund
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        avvance_log( '=== REFUND PROCESS STARTED ===' );
        avvance_log( "Order ID: {$order_id}" );
        avvance_log( 'Refund Amount: ' . ( $amount ? $amount : 'FULL' ) );
        avvance_log( 'Reason: ' . ( $reason ? $reason : 'No reason provided' ) );

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            avvance_log( "ERROR: Order #{$order_id} not found", 'error' );
            return new WP_Error( 'invalid_order', __( 'Invalid order', 'avvance-for-woocommerce' ) );
        }

        avvance_log( 'Order found. Order Status: ' . $order->get_status() );
        avvance_log( 'Order Total: ' . $order->get_total() );
        avvance_log( 'Order Payment Method: ' . $order->get_payment_method() );

        $partner_session_id = $order->get_meta( '_avvance_partner_session_id' );
        avvance_log( 'Partner Session ID: ' . ( $partner_session_id ? $partner_session_id : 'NOT FOUND' ) );

        if ( ! $partner_session_id ) {
            avvance_log( 'ERROR: Missing Avvance partner session ID', 'error' );
            return new WP_Error( 'missing_session', __( 'Avvance session ID not found', 'avvance-for-woocommerce' ) );
        }

        $application_guid = $order->get_meta( '_avvance_application_guid' );
        avvance_log( 'Application GUID: ' . ( $application_guid ? $application_guid : 'NOT FOUND' ) );

        $api = new Avvance_API_Client( $this->get_api_settings() );
        $last_status = $order->get_meta( '_avvance_last_webhook_status' );

        avvance_log( 'Last Webhook Status (from order meta): ' . ( $last_status ? $last_status : 'NOT SET' ) );

        // Get all order meta for debugging (redact sensitive values)
        $all_meta = $order->get_meta_data();
        avvance_log( 'All Avvance-related order meta:' );
        foreach ( $all_meta as $meta ) {
            if ( false !== strpos( $meta->key, '_avvance' ) ) {
                // Redact potentially sensitive values, only log key and type
                $value_preview = is_string( $meta->value ) ? substr( $meta->value, 0, 20 ) . '...' : gettype( $meta->value );
                avvance_log( "  {$meta->key}: [{$value_preview}]" );
            }
        }

        // Check current status from API if we have application GUID
        if ( $application_guid ) {
            avvance_log( 'Fetching current notification status from Avvance API...' );

            $status_response = $api->get_notification_status( $application_guid );

            if ( ! is_wp_error( $status_response ) ) {
                avvance_log( 'Notification status API response received' );
                // Note: Full API response not logged to prevent PII exposure (GDPR/CCPA compliance)

                $current_status = $status_response['eventDetails']['loanStatus']['status'] ?? null;

                if ( $current_status ) {
                    avvance_log( "Current Status from API: {$current_status}" );

                    if ( $current_status !== $last_status ) {
                        avvance_log( 'STATUS MISMATCH DETECTED!', 'warning' );
                        avvance_log( "  Stored in order meta: {$last_status}", 'warning' );
                        avvance_log( "  Current from API: {$current_status}", 'warning' );
                        avvance_log( 'Updating order meta with current status' );

                        $order->update_meta_data( '_avvance_last_webhook_status', $current_status );
                        $order->add_order_note(
                            /* translators: %1$s: previous status, %2$s: new status */
                            sprintf(
                                __( 'Avvance status updated during refund: %1$s → %2$s', 'avvance-for-woocommerce' ),
                                $last_status,
                                $current_status
                            )
                        );
                        $order->save();

                        $last_status = $current_status;
                    } else {
                        avvance_log( 'Status matches - no update needed' );
                    }
                } else {
                    avvance_log( 'WARNING: Could not extract status from API response', 'warning' );
                }
            } else {
                avvance_log( 'ERROR: Failed to get notification status from API', 'error' );
                avvance_log( 'API Error: ' . $status_response->get_error_message(), 'error' );
            }
        } else {
            avvance_log( 'WARNING: No application GUID - cannot check current status from API', 'warning' );
        }

        avvance_log( 'Final status to use for decision: ' . ( $last_status ? $last_status : 'NONE' ) );

        // Determine if void or refund
        if ( 'INVOICE_PAYMENT_TRANSACTION_SETTLED' === $last_status ) {
            avvance_log( 'Decision: REFUND (transaction is settled)' );
            avvance_log( 'Calling refund API with amount: ' . ( $amount ? $amount : $order->get_total() ) );

            $result = $api->refund_transaction( $partner_session_id, $amount ? $amount : $order->get_total() );
            $action = 'refund';

        } elseif ( 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED' === $last_status ) {
            avvance_log( 'Decision: VOID (transaction is authorized but not settled)' );
            avvance_log( 'Calling void API (full void only)' );

            $result = $api->void_transaction( $partner_session_id );
            $action = 'void';

        } else {
            avvance_log( "ERROR: Cannot process refund/void for status: {$last_status}", 'error' );
            avvance_log( 'Valid statuses are:' );
            avvance_log( '  - INVOICE_PAYMENT_TRANSACTION_SETTLED (for refund)' );
            avvance_log( '  - INVOICE_PAYMENT_TRANSACTION_AUTHORIZED (for void)' );
            avvance_log( '=== REFUND PROCESS FAILED ===' );

            return new WP_Error( 'invalid_status',
                /* translators: %s: current order status */
                sprintf(
                    __( 'Order cannot be refunded in current status: %s. Valid statuses are AUTHORIZED or SETTLED.', 'avvance-for-woocommerce' ),
                    $last_status
                )
            );
        }

        if ( is_wp_error( $result ) ) {
            avvance_log( "ERROR: {$action} API call failed", 'error' );
            avvance_log( 'Error message: ' . $result->get_error_message(), 'error' );
            avvance_log( '=== REFUND PROCESS FAILED ===' );
            return $result;
        }

        avvance_log( "{$action} API call successful" );
        // Note: API response not logged to prevent PII exposure (GDPR/CCPA compliance)

        $order->add_order_note(
            /* translators: %1$s: action type (refund or void), %2$s: refund amount or "full amount" */
            sprintf(
                __( 'Avvance %1$s processed: %2$s', 'avvance-for-woocommerce' ),
                $action,
                $amount ? wc_price( $amount ) : __( 'full amount', 'avvance-for-woocommerce' )
            )
        );

        avvance_log( '=== REFUND PROCESS COMPLETED SUCCESSFULLY ===' );

        return true;
    }

    /**
     * Get API settings
     */
    private function get_api_settings() {
        return [
            'client_key' => $this->get_option( 'client_key' ),
            'client_secret' => $this->get_option( 'client_secret' ),
            'merchant_id' => $this->get_option( 'merchant_id' ),
            'environment' => $this->get_option( 'environment' )
        ];
    }

    /**
     * Check if this is a Blocks checkout
     */
    private function is_blocks_checkout() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for checkout
        return isset( $_POST['wc-avvance-payment-token'] ) ||
               ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) );
    }
}
