<?php
/**
 * Avvance Payment Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Avvance extends WC_Payment_Gateway {
    
    public function __construct() {
        $this->id = 'avvance';
        $this->method_title = __('U.S. Bank Avvance', 'avvance-for-woocommerce');
        $this->method_description = __('Offer customers flexible installment financing through U.S. Bank Avvance.', 'avvance-for-woocommerce');
        $this->has_fields = true;
        $this->supports = ['products', 'refunds'];
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
		// Get settings
		$this->title = 'U.S. Bank Avvance';  // Simple name for order/admin
		$this->description = $this->get_option('description');
		$this->enabled = $this->get_option('enabled');

		// Hardcoded title and description (not editable by admin)
		$this->title = 'Pay over time with <img src="' . AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg" alt="Avvance" style="height: 24px; vertical-align: middle; margin: 0 8px;"> <a href="https://www.usbank.com/avvance-installment-loans.html" target="_blank" rel="noopener noreferrer" style="font-size: 0.9em;">Learn more</a>';

		$this->description = "To view payment options that you may qualify for, select 'Pay with U.S. Bank Avvance' to leave this site and enter the U.S. Bank Avvance loan application in a new window. Qualification for payment options are subject to application approval.\n\nImportant: After completing your application, please return to this window to see your order confirmation. Keep this window open during your application.";       
				// Set icon
				$this->icon = AVVANCE_PLUGIN_URL . 'assets/images/avvance-icon.svg';
				
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('wp_ajax_avvance_check_order_status', [$this, 'ajax_check_order_status']);
        add_action('wp_ajax_nopriv_avvance_check_order_status', [$this, 'ajax_check_order_status']);
    }
    
    /**
     * Initialize form fields
     */
	public function init_form_fields() {
        // Generate webhook credentials if they don't exist
        if (!$this->get_option('webhook_username')) {
            $credentials = avvance_generate_webhook_credentials();
            $this->update_option('webhook_username', $credentials['username']);
            $this->update_option('webhook_password', $credentials['password']);
        }
        
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'avvance-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Avvance', 'avvance-for-woocommerce'),
                'default' => 'no'
            ],
            'environment' => [
                'title' => __('Environment', 'avvance-for-woocommerce'),
                'type' => 'select',
                'options' => [
                    'sandbox' => __('Sandbox (Testing)', 'avvance-for-woocommerce'),
                    'production' => __('Production', 'avvance-for-woocommerce'),
                ],
                'default' => 'sandbox'
            ],
            'api_credentials_title' => [
                'title' => __('API Credentials', 'avvance-for-woocommerce'),
                'type' => 'title',
                'description' => __('Enter your Avvance API credentials from the Avvance Merchant Portal.', 'avvance-for-woocommerce'),
            ],
            'client_key' => [
                'title' => __('Client Key', 'avvance-for-woocommerce'),
                'type' => 'text',
                'description' => __('Your Avvance OAuth Client Key', 'avvance-for-woocommerce'),
                'desc_tip' => true,
            ],
            'client_secret' => [
                'title' => __('Client Secret', 'avvance-for-woocommerce'),
                'type' => 'password',
                'description' => __('Your Avvance OAuth Client Secret', 'avvance-for-woocommerce'),
                'desc_tip' => true,
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'avvance-for-woocommerce'),
                'type' => 'text',
                'description' => __('Your Elavon Merchant ID (MID)', 'avvance-for-woocommerce'),
                'desc_tip' => true,
            ],
            'hashed_merchant_id' => [
                'title' => __('Hashed Merchant ID', 'avvance-for-woocommerce'),
                'type' => 'text',
                'description' => __('Your Hashed Merchant ID for pre-approval (provided by Avvance)', 'avvance-for-woocommerce'),
                'desc_tip' => true,
                'placeholder' => 'e.g., aa613b14'
            ],
            'webhook_title' => [
                'title' => __('Webhook Configuration', 'avvance-for-woocommerce'),
                'type' => 'title',
                'description' => __('Provide these details to Avvance Support to register your webhook endpoint. This webhook will receive both loan status updates and pre-approval notifications.', 'avvance-for-woocommerce'),
            ],
            'webhook_url' => [
                'title' => __('Webhook URL', 'avvance-for-woocommerce'),
                'type' => 'text',
                'description' => __('Provide this URL to Avvance Support for both loan status and pre-approval webhooks', 'avvance-for-woocommerce'),
                'default' => WC()->api_request_url('avvance_webhook'),
                'custom_attributes' => ['readonly' => 'readonly'],
                'desc_tip' => true,
            ],
            'webhook_username' => [
                'title' => __('Webhook Username', 'avvance-for-woocommerce'),
                'type' => 'text',
                'description' => __('Provide this username to Avvance Support for Basic Auth', 'avvance-for-woocommerce'),
                'custom_attributes' => ['readonly' => 'readonly'],
                'desc_tip' => true,
            ],
            'webhook_password' => [
                'title' => __('Webhook Password', 'avvance-for-woocommerce'),
                'type' => 'text',
                'description' => __('Provide this password to Avvance Support for Basic Auth', 'avvance-for-woocommerce'),
                'custom_attributes' => ['readonly' => 'readonly'],
                'desc_tip' => true,
            ],
            'debug_mode' => [
                'title' => __('Debug Mode', 'avvance-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable debug logging', 'avvance-for-woocommerce'),
                'description' => __('Log API requests and responses to WooCommerce logs', 'avvance-for-woocommerce'),
                'default' => 'no',
                'desc_tip' => true,
            ],
        ];
    }
    
    /**
     * Payment fields (show disclosure)
     */
	public function payment_fields() {
		// Show fancy "Pay over time" message with logo and learn more link
		echo '<div style="margin-bottom: 10px; font-size: 1.1em;">';
		echo 'Pay over time with ';
		echo '<img src="' . esc_url(AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg') . '" alt="Avvance" style="height: 24px; vertical-align: middle; margin: 0 8px;"> ';
		echo '<a href="https://www.usbank.com/avvance-installment-loans.html" target="_blank" rel="noopener noreferrer" style="font-size: 0.9em; text-decoration: underline;">Learn more</a>';
		echo '</div>';
		
		// Show disclosure description
		if ($this->description) {
			echo '<div class="avvance-description">';
			echo wpautop(wp_kses_post($this->description));
			echo '</div>';
		}
	}
    
    /**
     * Check if gateway is available
     */
    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }
        
        // Check if credentials are configured
        if (empty($this->get_option('client_key')) || 
            empty($this->get_option('client_secret')) || 
            empty($this->get_option('merchant_id'))) {
            return false;
        }
        
        // Check currency (USD only)
        if (get_woocommerce_currency() !== 'USD') {
            return false;
        }
        
        // Check cart total (min $300, max $25,000)
        if (WC()->cart) {
            $total = WC()->cart->get_total('');
            if ($total < 300 || $total > 25000) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Process payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        // Validate order amount
        $total = $order->get_total();
        if ($total < 300 || $total > 25000) {
            wc_add_notice(__('Avvance financing is available for orders between $300 and $25,000.', 'avvance-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }
        
        // Get API client
        $api = new Avvance_API_Client($this->get_api_settings());
        
        // Create financing request
        $response = $api->create_financing_request($order);
        
        if (is_wp_error($response)) {
            avvance_log('Financing request failed for order #' . $order_id . ': ' . $response->get_error_message(), 'error');
            wc_add_notice(__('Unable to process Avvance payment. Please try again or use another payment method.', 'avvance-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }
        
        // Store response data on order
        $order->update_meta_data('_avvance_application_guid', $response['applicationGUID']);
        $order->update_meta_data('_avvance_partner_session_id', $response['partnerSessionId']);
        $order->update_meta_data('_avvance_consumer_url', $response['consumerOnboardingURL']);
        $order->update_meta_data('_avvance_url_created_at', time());
        $order->add_order_note(sprintf(__('Avvance application created. Application ID: %s', 'avvance-for-woocommerce'), $response['applicationGUID']));
        $order->save();
        
        // Store order ID in session for cart resume banner
        if (WC()->session) {
            WC()->session->set('avvance_pending_order_id', $order_id);
        }
        
        avvance_log('Order #' . $order_id . ' ready for Avvance. URL: ' . $response['consumerOnboardingURL']);
        
        // Check if this is a Blocks checkout (will redirect full page)
        if ($this->is_blocks_checkout()) {
            return [
                'result' => 'success',
                'redirect' => $response['consumerOnboardingURL']
            ];
        }
        
        // Classic checkout - redirect to thank you page (will open new window there)
        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        ];
    }
    
    /**
     * Thank you page (classic checkout only)
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        // Only show for pending orders
        if (!$order || !$order->needs_payment()) {
            return;
        }
        
        $url = $order->get_meta('_avvance_consumer_url');
        if (empty($url)) {
            return;
        }
        
        // Check if URL is expired
        if (avvance_is_url_expired($order_id)) {
            echo '<div class="woocommerce-info">';
            echo __('Your Avvance application link has expired. Please contact us to complete your order.', 'avvance-for-woocommerce');
            echo '</div>';
            return;
        }
        
        ?>
        <div class="avvance-thankyou">
            <h2><?php _e('Complete Your Avvance Application', 'avvance-for-woocommerce'); ?></h2>
            <p><?php _e('Opening your Avvance application in a new window...', 'avvance-for-woocommerce'); ?></p>
            <p id="avvance-status" style="font-weight: bold; color: #0073aa;">
                <?php _e('Waiting for application completion...', 'avvance-for-woocommerce'); ?>
            </p>
            <div id="avvance-manual-link" style="display:none; margin-top: 20px;">
                <p><?php _e('Pop-up blocked? Click below to open your application:', 'avvance-for-woocommerce'); ?></p>
                <a href="<?php echo esc_url($url); ?>" target="_blank" class="button"><?php _e('Open Avvance Application', 'avvance-for-woocommerce'); ?></a>
            </div>
            <div id="avvance-manual-check" style="display:none; margin-top: 30px;">
                <p><?php _e('Completed your application?', 'avvance-for-woocommerce'); ?></p>
                <button type="button" class="button" id="avvance-check-status-btn">
                    <?php _e('Check Application Status', 'avvance-for-woocommerce'); ?>
                </button>
            </div>
        </div>
        
        <script>
        (function($) {
            var orderId = <?php echo absint($order_id); ?>;
            var pollCount = 0;
            var maxPolls = 120; // 10 minutes at 5-second intervals
            
            // Try to open window
            var avvanceWindow = window.open('<?php echo esc_js($url); ?>', '_blank', 'toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=600,height=700');
            
            if (!avvanceWindow || avvanceWindow.closed || typeof avvanceWindow.closed === 'undefined') {
                // Pop-up blocked
                $('#avvance-manual-link').show();
                $('#avvance-status').text('<?php _e('Please open your Avvance application using the button below.', 'avvance-for-woocommerce'); ?>');
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
                                $('#avvance-status').text('<?php _e('Payment completed! Redirecting...', 'avvance-for-woocommerce'); ?>');
                                location.reload();
                            } else if (response.data.status === 'cancelled') {
                                clearInterval(statusInterval);
                                $('#avvance-status').html('<?php _e('Application declined. Please choose another payment method.', 'avvance-for-woocommerce'); ?>');
                                setTimeout(function() {
                                    window.location = '<?php echo esc_js(wc_get_cart_url()); ?>';
                                }, 3000);
                            }
                        }
                    }
                });
                
                // Stop polling after max attempts
                if (pollCount >= maxPolls) {
                    clearInterval(statusInterval);
                    $('#avvance-status').text('<?php _e('Still waiting? Use the button below to check your status.', 'avvance-for-woocommerce'); ?>');
                }
            }, 5000);
            
            // Manual check button
            $('#avvance-check-status-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php _e('Checking...', 'avvance-for-woocommerce'); ?>');
                
                $.ajax({
                    url: avvanceCheckout.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'avvance_manual_status_check',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('avvance_manual_check_' . $order_id); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php _e('Unable to check status. Please try again.', 'avvance-for-woocommerce'); ?>');
                            $btn.prop('disabled', false).text('<?php _e('Check Application Status', 'avvance-for-woocommerce'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php _e('Unable to check status. Please try again.', 'avvance-for-woocommerce'); ?>');
                        $btn.prop('disabled', false).text('<?php _e('Check Application Status', 'avvance-for-woocommerce'); ?>');
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
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }
        
        $status = 'pending';
        
        if ($order->is_paid()) {
            $status = 'completed';
        } elseif (in_array($order->get_status(), ['cancelled', 'failed'])) {
            $status = 'cancelled';
        }
        
        wp_send_json_success(['status' => $status]);
    }
    
    /**
     * Process refund
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        avvance_log("=== REFUND PROCESS STARTED ===");
        avvance_log("Order ID: {$order_id}");
        avvance_log("Refund Amount: " . ($amount ? $amount : 'FULL'));
        avvance_log("Reason: " . ($reason ? $reason : 'No reason provided'));
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            avvance_log("ERROR: Order #{$order_id} not found", 'error');
            return new WP_Error('invalid_order', __('Invalid order', 'avvance-for-woocommerce'));
        }
        
        avvance_log("Order found. Order Status: " . $order->get_status());
        avvance_log("Order Total: " . $order->get_total());
        avvance_log("Order Payment Method: " . $order->get_payment_method());
        
        $partner_session_id = $order->get_meta('_avvance_partner_session_id');
        avvance_log("Partner Session ID: " . ($partner_session_id ? $partner_session_id : 'NOT FOUND'));
        
        if (!$partner_session_id) {
            avvance_log("ERROR: Missing Avvance partner session ID", 'error');
            return new WP_Error('missing_session', __('Avvance session ID not found', 'avvance-for-woocommerce'));
        }
        
        $application_guid = $order->get_meta('_avvance_application_guid');
        avvance_log("Application GUID: " . ($application_guid ? $application_guid : 'NOT FOUND'));
        
        $api = new Avvance_API_Client($this->get_api_settings());
        $last_status = $order->get_meta('_avvance_last_webhook_status');
        
        avvance_log("Last Webhook Status (from order meta): " . ($last_status ? $last_status : 'NOT SET'));
        
        // Get all order meta for debugging
        $all_meta = $order->get_meta_data();
        avvance_log("All Avvance-related order meta:");
        foreach ($all_meta as $meta) {
            if (strpos($meta->key, '_avvance') !== false) {
                avvance_log("  {$meta->key}: " . print_r($meta->value, true));
            }
        }
        
        // Check current status from API if we have application GUID
        if ($application_guid) {
            avvance_log("Fetching current notification status from Avvance API...");
            
            $status_response = $api->get_notification_status($application_guid);
            
            if (!is_wp_error($status_response)) {
                avvance_log("Notification status API response received");
                avvance_log("Full API Response: " . print_r($status_response, true));
                
                $current_status = $status_response['eventDetails']['loanStatus']['status'] ?? null;
                
                if ($current_status) {
                    avvance_log("Current Status from API: {$current_status}");
                    
                    if ($current_status !== $last_status) {
                        avvance_log("STATUS MISMATCH DETECTED!", 'warning');
                        avvance_log("  Stored in order meta: {$last_status}", 'warning');
                        avvance_log("  Current from API: {$current_status}", 'warning');
                        avvance_log("Updating order meta with current status");
                        
                        $order->update_meta_data('_avvance_last_webhook_status', $current_status);
                        $order->add_order_note(sprintf(
                            __('Avvance status updated during refund: %s → %s', 'avvance-for-woocommerce'),
                            $last_status,
                            $current_status
                        ));
                        $order->save();
                        
                        $last_status = $current_status;
                    } else {
                        avvance_log("Status matches - no update needed");
                    }
                } else {
                    avvance_log("WARNING: Could not extract status from API response", 'warning');
                }
            } else {
                avvance_log("ERROR: Failed to get notification status from API", 'error');
                avvance_log("API Error: " . $status_response->get_error_message(), 'error');
            }
        } else {
            avvance_log("WARNING: No application GUID - cannot check current status from API", 'warning');
        }
        
        avvance_log("Final status to use for decision: " . ($last_status ? $last_status : 'NONE'));
        
        // Determine if void or refund
        if ($last_status === 'INVOICE_PAYMENT_TRANSACTION_SETTLED') {
            avvance_log("Decision: REFUND (transaction is settled)");
            avvance_log("Calling refund API with amount: " . ($amount ?: $order->get_total()));
            
            $result = $api->refund_transaction($partner_session_id, $amount ?: $order->get_total());
            $action = 'refund';
            
        } elseif ($last_status === 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED') {
            avvance_log("Decision: VOID (transaction is authorized but not settled)");
            avvance_log("Calling void API (full void only)");
            
            $result = $api->void_transaction($partner_session_id);
            $action = 'void';
            
        } else {
            avvance_log("ERROR: Cannot process refund/void for status: {$last_status}", 'error');
            avvance_log("Valid statuses are:");
            avvance_log("  - INVOICE_PAYMENT_TRANSACTION_SETTLED (for refund)");
            avvance_log("  - INVOICE_PAYMENT_TRANSACTION_AUTHORIZED (for void)");
            avvance_log("=== REFUND PROCESS FAILED ===");
            
            return new WP_Error('invalid_status', 
                sprintf(
                    __('Order cannot be refunded in current status: %s. Valid statuses are AUTHORIZED or SETTLED.', 'avvance-for-woocommerce'),
                    $last_status
                )
            );
        }
        
        if (is_wp_error($result)) {
            avvance_log("ERROR: {$action} API call failed", 'error');
            avvance_log("Error message: " . $result->get_error_message(), 'error');
            avvance_log("=== REFUND PROCESS FAILED ===");
            return $result;
        }
        
        avvance_log("{$action} API call successful");
        avvance_log("API Response: " . print_r($result, true));
        
        $order->add_order_note(sprintf(
            __('Avvance %s processed: %s', 'avvance-for-woocommerce'),
            $action,
            $amount ? wc_price($amount) : __('full amount', 'avvance-for-woocommerce')
        ));
        
        avvance_log("=== REFUND PROCESS COMPLETED SUCCESSFULLY ===");
        
        return true;
    }
    
    /**
     * Get API settings
     */
    private function get_api_settings() {
        return [
            'client_key' => $this->get_option('client_key'),
            'client_secret' => $this->get_option('client_secret'),
            'merchant_id' => $this->get_option('merchant_id'),
            'environment' => $this->get_option('environment')
        ];
    }
    
    /**
     * Check if this is a Blocks checkout
     */
    private function is_blocks_checkout() {
        return isset($_POST['wc-avvance-payment-token']) || 
               (function_exists('has_block') && has_block('woocommerce/checkout'));
    }
}
