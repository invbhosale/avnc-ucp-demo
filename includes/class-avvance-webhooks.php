<?php
/**
 * Avvance Webhooks Handler with Authentication Debug
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Webhooks {
    
    public static function init() {
        add_action('woocommerce_api_avvance_webhook', [__CLASS__, 'handle_webhook']);
    }
    
    /**
     * Handle incoming webhook
     */
    public static function handle_webhook() {
        avvance_log('=== WEBHOOK RECEIVED ===');
        avvance_log('Request Method: ' . $_SERVER['REQUEST_METHOD']);
        avvance_log('Request URI: ' . $_SERVER['REQUEST_URI']);
        
        // Log all headers
        $headers = self::get_request_headers();
        avvance_log('Request Headers: ' . print_r($headers, true));
        
        // Verify Basic Auth
        $auth_result = self::verify_auth();
        
        if (!$auth_result) {
            avvance_log('❌ WEBHOOK AUTHENTICATION FAILED', 'error');
            status_header(401);
            header('WWW-Authenticate: Basic realm="Avvance Webhook"');
            echo 'Unauthorized';
            exit;
        }
        
        avvance_log('✅ Authentication successful');
        
        // Get raw payload
        $raw_payload = file_get_contents('php://input');
        avvance_log('Raw payload length: ' . strlen($raw_payload));
        
        $payload = json_decode($raw_payload, true);
        
        if (!$payload) {
            avvance_log('Invalid webhook payload - JSON decode failed', 'error');
            status_header(400);
            exit;
        }
        
        avvance_log('Webhook payload: ' . print_r($payload, true));
        
        // Validate required fields
        if (!isset($payload['eventName']) || !isset($payload['eventDetails'])) {
            avvance_log('Missing required webhook fields', 'error');
            status_header(400);
            exit;
        }
        
        // Route based on event type
        $event_name = $payload['eventName'];
        avvance_log('Event name: ' . $event_name);
        
        if ($event_name === 'PRE_APPROVAL_LEADS') {
            avvance_log('Routing to pre-approval handler');
            $result = Avvance_PreApproval_Handler::process_preapproval_webhook($payload);
            
            if (is_wp_error($result)) {
                avvance_log('Pre-approval webhook processing failed: ' . $result->get_error_message(), 'error');
                status_header(500);
                exit;
            }
            
            avvance_log('Pre-approval webhook processed successfully');
            status_header(200);
            exit;
            
        } elseif ($event_name === 'LOAN_STATUS_DETAILS') {
            avvance_log('Processing loan status webhook');
            $result = self::process_loan_status($payload);
            
            if (is_wp_error($result)) {
                avvance_log('Webhook processing failed: ' . $result->get_error_message(), 'error');
                status_header(500);
                exit;
            }
            
            avvance_log('Loan status webhook processed successfully');
            status_header(200);
            exit;
            
        } else {
            avvance_log('Ignoring unknown event type: ' . $event_name);
            status_header(200);
            exit;
        }
    }
    
    /**
     * Get all request headers
     */
    private static function get_request_headers() {
        $headers = [];
        
        // Try different methods to get headers
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        } else {
            // Fallback: parse from $_SERVER
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $header = str_replace('_', '-', substr($key, 5));
                    $headers[$header] = $value;
                }
            }
        }
        
        return $headers;
    }
    
    /**
     * Verify Basic Auth
     */
    private static function verify_auth() {
        avvance_log('--- Starting Authentication Check ---');
        
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            avvance_log('❌ Gateway not found', 'error');
            return false;
        }
        
        $expected_username = $gateway->get_option('webhook_username');
        $expected_password = html_entity_decode($gateway->get_option('webhook_password'), ENT_QUOTES | ENT_HTML5);
        
        avvance_log('Expected username: ' . $expected_username);
        avvance_log('Expected password: ' . substr($expected_password, 0, 5) . '***');
        
        if (empty($expected_username) || empty($expected_password)) {
            avvance_log('❌ Webhook credentials not configured', 'error');
            return false;
        }
        
        // Check for Authorization header in multiple places
        $auth_header = null;
        
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
            avvance_log('Auth header from HTTP_AUTHORIZATION: ' . $auth_header);
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            avvance_log('Auth header from REDIRECT_HTTP_AUTHORIZATION: ' . $auth_header);
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $auth_header = $headers['Authorization'];
                avvance_log('Auth header from apache_request_headers: ' . $auth_header);
            }
        }
        
        // Also check PHP_AUTH variables
        if (!$auth_header && isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            $username = $_SERVER['PHP_AUTH_USER'];
            $password = $_SERVER['PHP_AUTH_PW'];
            avvance_log('Auth from PHP_AUTH - Username: ' . $username);
            avvance_log('Auth from PHP_AUTH - Password: ' . substr($password, 0, 5) . '***');
            
            // Verify credentials
            $username_match = hash_equals($expected_username, $username);
            $password_match = hash_equals($expected_password, $password);
            
            avvance_log('Username match: ' . ($username_match ? 'YES' : 'NO'));
            avvance_log('Password match: ' . ($password_match ? 'YES' : 'NO'));
            
            return $username_match && $password_match;
        }
        
        if (!$auth_header) {
            avvance_log('❌ No Authorization header found', 'error');
            avvance_log('Available $_SERVER keys: ' . implode(', ', array_keys($_SERVER)));
            return false;
        }
        
        // Parse Basic Auth
        if (strpos($auth_header, 'Basic ') !== 0) {
            avvance_log('❌ Not Basic Auth: ' . $auth_header, 'error');
            return false;
        }
        
        $credentials = base64_decode(substr($auth_header, 6));
        avvance_log('Decoded credentials length: ' . strlen($credentials));
        
        if (strpos($credentials, ':') === false) {
            avvance_log('❌ Invalid credentials format (no colon)', 'error');
            return false;
        }
        
        list($username, $password) = explode(':', $credentials, 2);
        
        avvance_log('Received username: ' . $username);
        avvance_log('Received password: ' . substr($password, 0, 5) . '***');
        
        // Compare credentials
        $username_match = hash_equals($expected_username, $username);
        $password_match = hash_equals($expected_password, $password);
        
        avvance_log('Username match: ' . ($username_match ? 'YES' : 'NO'));
        avvance_log('Password match: ' . ($password_match ? 'YES' : 'NO'));
        
        if (!$username_match) {
            avvance_log('❌ Username mismatch!', 'error');
            avvance_log('Expected: ' . $expected_username);
            avvance_log('Received: ' . $username);
        }
        
        if (!$password_match) {
            avvance_log('❌ Password mismatch!', 'error');
            avvance_log('Expected length: ' . strlen($expected_password));
            avvance_log('Received length: ' . strlen($password));
        }
        
        return $username_match && $password_match;
    }
    
    /**
     * Process loan status webhook
     */
    private static function process_loan_status($payload) {
        $event_details = $payload['eventDetails'];
        
        // Get identifiers
        $application_guid = $event_details['applicationGUID'] ?? '';
        $partner_session_id = $event_details['partnerSessionId'] ?? '';
        $status = $event_details['loanStatus']['status'] ?? '';
        
        if (empty($application_guid) && empty($partner_session_id)) {
            return new WP_Error('missing_identifiers', 'Missing applicationGUID or partnerSessionId');
        }
        
        // Find the order
        $order = self::find_order($application_guid, $partner_session_id);
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found for webhook');
        }
        
        // Check for duplicate webhook (idempotency)
        $last_status = $order->get_meta('_avvance_last_webhook_status');
        if ($last_status === $status) {
            avvance_log('Duplicate webhook status, skipping: ' . $status);
            return true;
        }
        
        // Store webhook history
        $history = $order->get_meta('_avvance_webhook_history') ?: [];
        $history[] = [
            'status' => $status,
            'timestamp' => current_time('mysql'),
            'payload' => $event_details
        ];
        $order->update_meta_data('_avvance_webhook_history', $history);
        $order->update_meta_data('_avvance_last_webhook_status', $status);
        
        // Process based on status
        switch ($status) {
            case 'APPLICATION_STARTED':
                $order->add_order_note(__('Customer started Avvance application', 'avvance-for-woocommerce'));
                break;
                
            case 'APPLICATION_APPROVED':
                $order->add_order_note(__('Avvance application approved - awaiting customer to complete purchase', 'avvance-for-woocommerce'));
                break;
                
            case 'APPLICATION_PENDING_REQUIRE_CUSTOMER_ACTION':
                $order->add_order_note(__('Avvance application requires customer action (e.g., credit freeze removal)', 'avvance-for-woocommerce'));
                break;
                
            case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':
                $payment_transaction_id = $event_details['paymentTransactionId'] ?? '';
                $approval_code = $event_details['approvalCode'] ?? '';
                
                $order->update_meta_data('_avvance_payment_transaction_id', $payment_transaction_id);
                $order->update_meta_data('_avvance_approval_code', $approval_code);
                
                if (isset($event_details['loanSummary'])) {
                    $order->update_meta_data('_avvance_loan_summary', $event_details['loanSummary']);
                }
                
                $order->payment_complete($payment_transaction_id);
                $order->add_order_note(sprintf(
                    __('Avvance payment authorized. Transaction ID: %s, Approval Code: %s', 'avvance-for-woocommerce'),
                    $payment_transaction_id,
                    $approval_code
                ));
                
                if (WC()->session) {
                    WC()->session->__unset('avvance_pending_order_id');
                }
                
                avvance_log('Order #' . $order->get_id() . ' payment completed via webhook');
                break;
                
            case 'INVOICE_PAYMENT_TRANSACTION_SETTLED':
                $order->add_order_note(__('Avvance payment settled (merchant settlement processed)', 'avvance-for-woocommerce'));
                break;
                
            case 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT':
            case 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT':
                if (!$order->is_paid()) {
                    $order->update_status('cancelled', sprintf(
                        __('Avvance application %s - customer should use alternate payment', 'avvance-for-woocommerce'),
                        $status === 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT' ? 'declined' : 'encountered system error'
                    ));
                }
                
                if (WC()->session) {
                    WC()->session->__unset('avvance_pending_order_id');
                }
                break;
                
            case 'APPLICATION_LINK_EXPIRED':
                if (!$order->is_paid()) {
                    $order->add_order_note(__('Avvance application link expired (30 days)', 'avvance-for-woocommerce'));
                }
                break;
                
            default:
                $order->add_order_note(sprintf(__('Avvance status update: %s', 'avvance-for-woocommerce'), avvance_get_status_message($status)));
        }
        
        $order->save();
        
        return true;
    }
    
    /**
     * Find order by application GUID or partner session ID
     */
    private static function find_order($application_guid, $partner_session_id) {
        if (!empty($application_guid)) {
            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_avvance_application_guid',
                'meta_value' => $application_guid,
                'return' => 'objects'
            ]);
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        if (!empty($partner_session_id)) {
            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_avvance_partner_session_id',
                'meta_value' => $partner_session_id,
                'return' => 'objects'
            ]);
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        return null;
    }
}