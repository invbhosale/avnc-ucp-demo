<?php
/**
 * Avvance Pre-Approval Handler
 * Manages pre-approval requests, session storage, and webhook processing
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_PreApproval_Handler {
    
    const COOKIE_NAME = 'avvance_preapproval';
    const COOKIE_EXPIRY = 15 * DAY_IN_SECONDS; // 15 days
    
    public static function init() {
        // AJAX endpoint for creating pre-approval
        add_action('wp_ajax_avvance_create_preapproval', [__CLASS__, 'ajax_create_preapproval']);
        add_action('wp_ajax_nopriv_avvance_create_preapproval', [__CLASS__, 'ajax_create_preapproval']);
        
        // NOTE: Pre-approval status check is now handled by Widget_Handler
        // to avoid duplicate AJAX endpoints
    }
    
    /**
     * AJAX: Create pre-approval request
     */
    public static function ajax_create_preapproval() {
        avvance_log('=== CREATE PRE-APPROVAL REQUEST ===');
        avvance_log('POST data: ' . print_r($_POST, true));
        
        // Verify nonce
        if (!isset($_POST['nonce'])) {
            avvance_log('ERROR: Nonce not provided', 'error');
            wp_send_json_error(['message' => 'Security check failed - nonce missing']);
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'avvance_preapproval')) {
            avvance_log('ERROR: Nonce verification failed', 'error');
            wp_send_json_error(['message' => 'Security check failed - invalid nonce']);
        }
        
        avvance_log('Nonce verified successfully');
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        
        avvance_log('Session ID: ' . $session_id);
        
        if (empty($session_id)) {
            avvance_log('ERROR: Session ID empty', 'error');
            wp_send_json_error(['message' => 'Invalid session ID']);
        }
        
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            avvance_log('ERROR: Gateway not available', 'error');
            wp_send_json_error(['message' => 'Gateway not available']);
        }
        
        avvance_log('Gateway found');
        
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-api.php';
        
        $hashed_mid = $gateway->get_option('hashed_merchant_id');
        
        avvance_log('Hashed Merchant ID: ' . ($hashed_mid ? $hashed_mid : 'EMPTY'));
        
        if (empty($hashed_mid)) {
            avvance_log('ERROR: Hashed Merchant ID not configured', 'error');
            wp_send_json_error(['message' => 'Pre-approval not configured. Please contact merchant.']);
        }
        
        $api = new Avvance_PreApproval_API([
            'client_key' => $gateway->get_option('client_key'),
            'client_secret' => $gateway->get_option('client_secret'),
            'merchant_id' => $gateway->get_option('merchant_id'),
            'environment' => $gateway->get_option('environment')
        ]);
        
        avvance_log('API client created, calling create_preapproval');
        
        $response = $api->create_preapproval($session_id, $hashed_mid);
        
        if (is_wp_error($response)) {
            avvance_log('Pre-approval creation failed: ' . $response->get_error_message(), 'error');
            wp_send_json_error(['message' => 'Unable to create pre-approval request']);
        }
        
        // Store pre-approval data in session/cookie
        $preapproval_data = [
            'request_id' => $response['preApprovalRequestID'],
            'session_id' => $session_id,
            'created_at' => time(),
            'status' => 'pending'
        ];
        
        self::store_preapproval_data($preapproval_data);
        
        // Also store in database for webhook lookup
        self::save_preapproval_to_db($preapproval_data);
        
        avvance_log('Pre-approval created and stored. Request ID: ' . $response['preApprovalRequestID']);
        
        wp_send_json_success([
            'url' => $response['preApprovalOnboardingURL'],
            'request_id' => $response['preApprovalRequestID']
        ]);
    }
    
    /**
     * Process pre-approval webhook (called from main webhook handler)
     */
    public static function process_preapproval_webhook($payload) {
        $event_details = $payload['eventDetails'];

        // Use preApprovalRequestId for lookup (matches what we store when creating pre-approval)
        $request_id = $event_details['preApprovalRequestId'] ?? '';
        $lead_id = $event_details['leadid'] ?? '';
        $lead_status = $event_details['leadstatus'] ?? '';

        if (empty($request_id)) {
            avvance_log('Missing preApprovalRequestId in webhook', 'error');
            return new WP_Error('missing_request_id', 'Missing preApprovalRequestId in webhook');
        }

        avvance_log('Processing pre-approval webhook - Request ID: ' . $request_id . ', Lead ID: ' . $lead_id);

        // Find the pre-approval record in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE request_id = %s",
            $request_id
        ));

        if (!$record) {
            avvance_log('Pre-approval record not found for request ID: ' . $request_id, 'warning');
            return new WP_Error('record_not_found', 'Pre-approval record not found');
        }

        // Extract max pre-approved amount from metadata
        $max_amount = null;
        if (isset($event_details['metadata']) && is_array($event_details['metadata'])) {
            foreach ($event_details['metadata'] as $meta) {
                if (isset($meta['key']) && $meta['key'] === 'maxPreApprovedAmount') {
                    $max_amount = floatval($meta['value']);
                    break;
                }
            }
        }

        // Update database record
        $wpdb->update(
            $table_name,
            [
                'status' => $lead_status,
                'max_amount' => $max_amount,
                'lead_id' => $lead_id, // Store lead_id separately for reference
                'customer_name' => $event_details['customerName'] ?? '',
                'customer_email' => $event_details['customerEmail'] ?? '',
                'customer_phone' => $event_details['customerPhone'] ?? '',
                'expiry_date' => isset($event_details['leadExpiryDate'])
                    ? date('Y-m-d H:i:s', strtotime($event_details['leadExpiryDate']))
                    : null,
                'updated_at' => current_time('mysql'),
                'webhook_payload' => wp_json_encode($event_details)
            ],
            ['request_id' => $request_id],
            ['%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );

        avvance_log("Pre-approval updated: Request ID {$request_id}, Lead ID: {$lead_id}, Status: {$lead_status}, Max Amount: " . ($max_amount ?? 'N/A'));

        return true;
    }
    
    /**
     * Store pre-approval data in cookie and session
     */
    private static function store_preapproval_data($data) {
        // Store in cookie
        setcookie(
            self::COOKIE_NAME,
            wp_json_encode($data),
            time() + self::COOKIE_EXPIRY,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
        
        // Store in WooCommerce session if available
        if (WC()->session) {
            WC()->session->set('avvance_preapproval', $data);
        }
    }
    
    /**
     * Get pre-approval data from cookie or session
     */
    public static function get_preapproval_data() {
        // Try WooCommerce session first
        if (WC()->session) {
            $session_data = WC()->session->get('avvance_preapproval');
            if ($session_data) {
                // Sync with database
                return self::get_preapproval_from_db($session_data['request_id']);
            }
        }
        
        // Try cookie
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            $cookie_data = json_decode(stripslashes($_COOKIE[self::COOKIE_NAME]), true);
            if ($cookie_data && isset($cookie_data['request_id'])) {
                return self::get_preapproval_from_db($cookie_data['request_id']);
            }
        }
        
        return null;
    }
    
    /**
     * Clear pre-approval data
     */
    private static function clear_preapproval_data() {
        setcookie(self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        if (WC()->session) {
            WC()->session->__unset('avvance_preapproval');
        }
    }
    
    /**
     * Save pre-approval to database
     */
    private static function save_preapproval_to_db($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';
        
        // Create table if it doesn't exist
        self::create_preapproval_table();
        
        $wpdb->insert(
            $table_name,
            [
                'request_id' => $data['request_id'],
                'session_id' => $data['session_id'],
                'status' => $data['status'] ?? 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }
    
    /**
     * Get pre-approval from database
     */
    private static function get_preapproval_from_db($request_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';
        
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE request_id = %s",
            $request_id
        ), ARRAY_A);
        
        return $record;
    }
    
    /**
     * Create pre-approval database table
     */
    public static function create_preapproval_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            request_id varchar(255) NOT NULL,
            lead_id varchar(255) DEFAULT NULL,
            session_id varchar(255) NOT NULL,
            status varchar(50) DEFAULT 'pending',
            max_amount decimal(10,2) DEFAULT NULL,
            customer_name varchar(255) DEFAULT NULL,
            customer_email varchar(255) DEFAULT NULL,
            customer_phone varchar(50) DEFAULT NULL,
            expiry_date datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            webhook_payload longtext DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY request_id (request_id),
            KEY lead_id (lead_id),
            KEY session_id (session_id),
            KEY status (status)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}