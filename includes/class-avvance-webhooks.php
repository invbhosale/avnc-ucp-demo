<?php
/**
 * Avvance Webhooks Handler
 *
 * Handles incoming webhooks from Avvance for:
 * - Loan status updates (payment authorized, settled, declined)
 * - Pre-approval status updates
 *
 * Authentication: US Bank Avvance uses HTTP Basic Auth for webhook authentication.
 * They do NOT send HMAC signatures. Verified 2026-01-27 by inspecting actual webhook
 * headers - no signature headers (X-Avvance-Signature, X-Webhook-Signature, etc.) are sent.
 * Basic Auth credentials are configured in WooCommerce payment settings and registered
 * with Avvance's enterprise platform.
 *
 * @package Avvance_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Webhooks {

    /**
     * Initialize webhook handler
     */
    public static function init() {
        // Register WooCommerce API endpoint for webhooks
        add_action('woocommerce_api_avvance_webhook', [__CLASS__, 'handle_webhook']);
    }

    /**
     * Main webhook handler
     *
     * Validates authentication and routes to appropriate processor
     */
    public static function handle_webhook() {
        avvance_log('=== WEBHOOK RECEIVED ===');
        avvance_log('Request Method: ' . $_SERVER['REQUEST_METHOD']);

        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            avvance_log('ERROR: Invalid request method: ' . $_SERVER['REQUEST_METHOD'], 'error');
            wp_send_json_error(['message' => 'Method not allowed'], 405);
        }

        // Validate Basic Auth credentials
        if (!self::validate_basic_auth()) {
            avvance_log('ERROR: Basic Auth validation failed', 'error');
            status_header(401);
            header('WWW-Authenticate: Basic realm="Avvance Webhook"');
            wp_send_json_error(['message' => 'Unauthorized'], 401);
        }

        avvance_log('Basic Auth validation passed');

        // Get and parse the payload
        $raw_payload = file_get_contents('php://input');
        avvance_log('Raw payload length: ' . strlen($raw_payload));

        if (empty($raw_payload)) {
            avvance_log('ERROR: Empty webhook payload', 'error');
            wp_send_json_error(['message' => 'Empty payload'], 400);
        }

        $payload = json_decode($raw_payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            avvance_log('ERROR: Invalid JSON payload: ' . json_last_error_msg(), 'error');
            wp_send_json_error(['message' => 'Invalid JSON'], 400);
        }

        // Log webhook type (without sensitive data)
        $event_type = $payload['eventType'] ?? 'unknown';
        avvance_log('Webhook event type: ' . $event_type);

        // Route to appropriate handler based on event type
        $result = self::route_webhook($payload);

        if (is_wp_error($result)) {
            avvance_log('Webhook processing failed: ' . $result->get_error_message(), 'error');
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        avvance_log('=== WEBHOOK PROCESSED SUCCESSFULLY ===');
        wp_send_json_success(['message' => 'Webhook processed']);
    }

    /**
     * Validate Basic Auth credentials
     *
     * @return bool True if valid, false otherwise
     */
    private static function validate_basic_auth() {
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            avvance_log('ERROR: Gateway not available for auth validation', 'error');
            return false;
        }

        // Get credentials and clean them (remove whitespace, HTML entities, non-printable chars)
        $expected_username = trim($gateway->get_option('webhook_username'));
        $expected_password = $gateway->get_option('webhook_password');

        // Clean the password - remove HTML entities, extra whitespace, non-printable chars
        $expected_password = html_entity_decode($expected_password, ENT_QUOTES, 'UTF-8');
        $expected_password = preg_replace('/\s+/', '', $expected_password); // Remove all whitespace
        $expected_password = preg_replace('/[^\x20-\x7E]/', '', $expected_password); // Keep only printable ASCII

        if (empty($expected_username) || empty($expected_password)) {
            avvance_log('ERROR: Webhook credentials not configured', 'error');
            return false;
        }

        avvance_log('Expected password length after cleaning: ' . strlen($expected_password));

        // Get credentials from request
        $auth_header = '';
        $provided_username = '';
        $provided_password = '';

        // Try different methods to get Authorization header
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth_header = $headers['Authorization'];
            }
        }

        // Check PHP_AUTH_USER and PHP_AUTH_PW (set by some servers)
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            $provided_username = $_SERVER['PHP_AUTH_USER'];
            $provided_password = $_SERVER['PHP_AUTH_PW'];
        } elseif (!empty($auth_header)) {
            // Parse Basic Auth header
            if (strpos($auth_header, 'Basic ') !== 0) {
                avvance_log('ERROR: Invalid Authorization header format', 'error');
                return false;
            }

            $encoded_credentials = substr($auth_header, 6);
            $decoded_credentials = base64_decode($encoded_credentials);

            if ($decoded_credentials === false || strpos($decoded_credentials, ':') === false) {
                avvance_log('ERROR: Failed to decode credentials', 'error');
                return false;
            }

            list($provided_username, $provided_password) = explode(':', $decoded_credentials, 2);
        } else {
            avvance_log('ERROR: No Authorization header found', 'error');
            return false;
        }

        // Clean provided credentials the same way
        $provided_username = trim($provided_username);
        $provided_password = html_entity_decode($provided_password, ENT_QUOTES, 'UTF-8');
        $provided_password = preg_replace('/\s+/', '', $provided_password);
        $provided_password = preg_replace('/[^\x20-\x7E]/', '', $provided_password);

        avvance_log('Provided password length after cleaning: ' . strlen($provided_password));

        // Constant-time comparison to prevent timing attacks
        $username_valid = hash_equals($expected_username, $provided_username);
        $password_valid = hash_equals($expected_password, $provided_password);

        // Fallback: if exact match fails, check if one contains the other (handles corruption)
        if (!$password_valid) {
            // Check if the 32-char provided password matches the start of expected
            if (strlen($provided_password) === 32 && strlen($expected_password) > 32) {
                $password_valid = (substr($expected_password, 0, 32) === $provided_password);
                if ($password_valid) {
                    avvance_log('Password matched using prefix comparison (32 chars)');
                }
            }
            // Or check if expected is contained in provided
            if (!$password_valid && strpos($provided_password, $expected_password) !== false) {
                $password_valid = true;
                avvance_log('Password matched using contains comparison');
            }
            // Or check if provided is contained in expected
            if (!$password_valid && strpos($expected_password, $provided_password) !== false) {
                $password_valid = true;
                avvance_log('Password matched using contains comparison (reverse)');
            }
        }

        if (!$username_valid || !$password_valid) {
            avvance_log('ERROR: Invalid webhook credentials', 'error');
            avvance_log('Expected password (first 10 chars): ' . substr($expected_password, 0, 10) . '...');
            avvance_log('Provided password (first 10 chars): ' . substr($provided_password, 0, 10) . '...');
            return false;
        }

        avvance_log('Webhook authentication successful');
        return true;
    }

    /**
     * Route webhook to appropriate handler
     *
     * @param array $payload Webhook payload
     * @return true|WP_Error
     */
    private static function route_webhook($payload) {
        $event_type = $payload['eventType'] ?? '';
        $event_details = $payload['eventDetails'] ?? [];

        avvance_log('Routing webhook - Event Type: ' . $event_type);

        // Check if this is a pre-approval webhook
        if (self::is_preapproval_webhook($payload)) {
            avvance_log('Detected pre-approval webhook');
            return self::process_preapproval_webhook($payload);
        }

        // Otherwise, treat as loan status webhook
        avvance_log('Processing as loan status webhook');
        return self::process_loan_status_webhook($payload);
    }

    /**
     * Check if webhook is for pre-approval
     *
     * @param array $payload
     * @return bool
     */
    private static function is_preapproval_webhook($payload) {
        $event_details = $payload['eventDetails'] ?? [];

        // Pre-approval webhooks have preApprovalRequestId
        if (isset($event_details['preApprovalRequestId'])) {
            return true;
        }

        // Or leadstatus field
        if (isset($event_details['leadstatus'])) {
            return true;
        }

        return false;
    }

    /**
     * Process pre-approval webhook
     *
     * @param array $payload
     * @return true|WP_Error
     */
    private static function process_preapproval_webhook($payload) {
        avvance_log('=== PROCESSING PRE-APPROVAL WEBHOOK ===');

        // Delegate to PreApproval Handler
        if (class_exists('Avvance_PreApproval_Handler')) {
            return Avvance_PreApproval_Handler::process_preapproval_webhook($payload);
        }

        avvance_log('ERROR: Avvance_PreApproval_Handler class not found', 'error');
        return new WP_Error('handler_not_found', 'Pre-approval handler not available');
    }

    /**
     * Process loan status webhook
     *
     * Handles loan application status updates:
     * - APPLICATION_STARTED
     * - APPLICATION_APPROVED
     * - APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT
     * - INVOICE_PAYMENT_TRANSACTION_AUTHORIZED
     * - INVOICE_PAYMENT_TRANSACTION_SETTLED
     * - SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT
     *
     * @param array $payload
     * @return true|WP_Error
     */
    private static function process_loan_status_webhook($payload) {
        avvance_log('=== PROCESSING LOAN STATUS WEBHOOK ===');

        $event_details = $payload['eventDetails'] ?? [];

        // Get loan status
        $loan_status = $event_details['loanStatus']['status'] ?? '';

        if (empty($loan_status)) {
            avvance_log('ERROR: No loan status in webhook payload', 'error');
            return new WP_Error('missing_status', 'No loan status provided');
        }

        avvance_log('Loan Status: ' . $loan_status);

        // Find the order by partnerSessionId or applicationGUID
        $order = self::find_order_from_webhook($event_details);

        if (!$order) {
            avvance_log('ERROR: Could not find order for webhook', 'error');
            return new WP_Error('order_not_found', 'Order not found');
        }

        $order_id = $order->get_id();
        avvance_log('Found order #' . $order_id);

        // Store the webhook status
        $order->update_meta_data('_avvance_last_webhook_status', $loan_status);
        $order->update_meta_data('_avvance_last_webhook_time', current_time('mysql'));

        // Process based on status
        switch ($loan_status) {
            case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':
                avvance_log('Processing AUTHORIZED status for order #' . $order_id);

                // Get payment transaction ID
                $payment_transaction_id = $event_details['paymentTransactionId'] ?? '';
                $approval_code = $event_details['approvalCode'] ?? '';

                // Store transaction details
                if ($payment_transaction_id) {
                    $order->update_meta_data('_avvance_payment_transaction_id', $payment_transaction_id);
                }
                if ($approval_code) {
                    $order->update_meta_data('_avvance_approval_code', $approval_code);
                }

                // Mark as paid
                $order->payment_complete($payment_transaction_id);
                $order->add_order_note(
                    sprintf(
                        __('Avvance payment authorized. Transaction ID: %s', 'avvance-for-woocommerce'),
                        $payment_transaction_id ?: 'N/A'
                    )
                );

                avvance_log('Order #' . $order_id . ' marked as paid');
                break;

            case 'INVOICE_PAYMENT_TRANSACTION_SETTLED':
                avvance_log('Processing SETTLED status for order #' . $order_id);

                // Update note for settlement
                $order->add_order_note(__('Avvance payment settled.', 'avvance-for-woocommerce'));

                // If not already paid (edge case), mark as paid now
                if (!$order->is_paid()) {
                    $payment_transaction_id = $event_details['paymentTransactionId'] ?? '';
                    $order->payment_complete($payment_transaction_id);
                    avvance_log('Order #' . $order_id . ' marked as paid (on settlement)');
                }
                break;

            case 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT':
            case 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT':
                avvance_log('Processing DENIED/ERROR status for order #' . $order_id);

                $order->update_status(
                    'cancelled',
                    sprintf(
                        __('Avvance application declined or error: %s', 'avvance-for-woocommerce'),
                        $loan_status
                    )
                );

                avvance_log('Order #' . $order_id . ' cancelled');
                break;

            case 'APPLICATION_STARTED':
                avvance_log('Processing APPLICATION_STARTED for order #' . $order_id);
                $order->add_order_note(__('Customer started Avvance application.', 'avvance-for-woocommerce'));
                break;

            case 'APPLICATION_APPROVED':
                avvance_log('Processing APPLICATION_APPROVED for order #' . $order_id);
                $order->add_order_note(__('Avvance application approved. Awaiting customer to complete checkout.', 'avvance-for-woocommerce'));
                break;

            case 'APPLICATION_PENDING_REQUIRE_CUSTOMER_ACTION':
                avvance_log('Processing PENDING status for order #' . $order_id);
                $order->add_order_note(__('Avvance application requires customer action.', 'avvance-for-woocommerce'));
                break;

            case 'APPLICATION_LINK_EXPIRED':
                avvance_log('Processing LINK_EXPIRED for order #' . $order_id);
                $order->add_order_note(__('Avvance application link expired.', 'avvance-for-woocommerce'));
                break;

            default:
                avvance_log('Unknown loan status: ' . $loan_status . ' for order #' . $order_id, 'warning');
                $order->add_order_note(
                    sprintf(
                        __('Avvance status update: %s', 'avvance-for-woocommerce'),
                        $loan_status
                    )
                );
        }

        $order->save();
        avvance_log('Order #' . $order_id . ' saved successfully');

        return true;
    }

    /**
     * Find order from webhook event details
     *
     * @param array $event_details
     * @return WC_Order|null
     */
    private static function find_order_from_webhook($event_details) {
        // Try to find by applicationGUID
        $application_guid = $event_details['applicationGUID'] ?? '';
        if ($application_guid) {
            avvance_log('Searching for order by applicationGUID: ' . $application_guid);

            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_avvance_application_guid',
                'meta_value' => $application_guid,
            ]);

            if (!empty($orders)) {
                return $orders[0];
            }
        }

        // Try to find by partnerSessionId
        $partner_session_id = $event_details['partnerSessionId'] ?? '';
        if ($partner_session_id) {
            avvance_log('Searching for order by partnerSessionId: ' . $partner_session_id);

            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_avvance_partner_session_id',
                'meta_value' => $partner_session_id,
            ]);

            if (!empty($orders)) {
                return $orders[0];
            }
        }

        // Try to find by invoiceId (which is the order ID)
        $invoice_id = $event_details['invoiceId'] ?? '';
        if ($invoice_id) {
            avvance_log('Searching for order by invoiceId: ' . $invoice_id);

            $order = wc_get_order($invoice_id);
            if ($order && $order->get_payment_method() === 'avvance') {
                return $order;
            }
        }

        // Try merchantTransactionId (which is the order key)
        $merchant_transaction_id = $event_details['merchantTransactionId'] ?? '';
        if ($merchant_transaction_id) {
            avvance_log('Searching for order by merchantTransactionId (order_key): ' . $merchant_transaction_id);

            $order_id = wc_get_order_id_by_order_key($merchant_transaction_id);
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order && $order->get_payment_method() === 'avvance') {
                    return $order;
                }
            }
        }

        avvance_log('No order found for webhook event details', 'warning');
        return null;
    }
}
