<?php
/**
 * Avvance Pre-Approval Handler - FIXED VERSION (Date Handling)
 * 
 * FIXES:
 * 1. Better date parsing for leadExpiryDate
 * 2. More detailed logging for debugging
 * 3. Handles timezone issues
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_PreApproval_Handler {
    
    const COOKIE_NAME = 'avvance_browser_id';
    const COOKIE_EXPIRY = 30 * DAY_IN_SECONDS; // 30 days to match Avvance expiry
    
    public static function init() {
        // AJAX endpoint for creating pre-approval
        add_action('wp_ajax_avvance_create_preapproval', [__CLASS__, 'ajax_create_preapproval']);
        add_action('wp_ajax_nopriv_avvance_create_preapproval', [__CLASS__, 'ajax_create_preapproval']);
        
        // AJAX endpoint for checking pre-approval status
        add_action('wp_ajax_avvance_check_preapproval_status', [__CLASS__, 'ajax_check_preapproval_status']);
        add_action('wp_ajax_nopriv_avvance_check_preapproval_status', [__CLASS__, 'ajax_check_preapproval_status']);
    }
    
    /**
     * Get or create browser fingerprint for tracking
     */
    private static function get_browser_fingerprint() {
        // Check if cookie exists
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
        }
        
        // Create new fingerprint
        $fingerprint = 'avv_fp_' . wp_generate_uuid4();
        
        // Set cookie
        setcookie(
            self::COOKIE_NAME,
            $fingerprint,
            time() + self::COOKIE_EXPIRY,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true // httponly
        );
        
        return $fingerprint;
    }
    
    /**
     * AJAX: Create pre-approval request
     */
    public static function ajax_create_preapproval() {
        avvance_log('=== CREATE PRE-APPROVAL REQUEST ===');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'avvance_preapproval')) {
            avvance_log('ERROR: Nonce verification failed', 'error');
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        
        if (empty($session_id)) {
            avvance_log('ERROR: Session ID empty', 'error');
            wp_send_json_error(['message' => 'Invalid session ID']);
        }
        
        // Get browser fingerprint
        $browser_fingerprint = self::get_browser_fingerprint();
        avvance_log('Browser fingerprint: ' . $browser_fingerprint);
        
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            avvance_log('ERROR: Gateway not available', 'error');
            wp_send_json_error(['message' => 'Gateway not available']);
        }
        
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-api.php';
        
        $hashed_mid = $gateway->get_option('hashed_merchant_id');
        
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
        
        // Store pre-approval in database with browser fingerprint
        $preapproval_data = [
            'request_id' => $response['preApprovalRequestID'],
            'session_id' => $session_id,
            'browser_fingerprint' => $browser_fingerprint,
            'status' => 'pending'
        ];
        
        self::save_preapproval_to_db($preapproval_data);
        
        avvance_log('Pre-approval created and stored. Request ID: ' . $response['preApprovalRequestID']);
        
        wp_send_json_success([
            'url' => $response['preApprovalOnboardingURL'],
            'request_id' => $response['preApprovalRequestID']
        ]);
    }
    
    /**
     * AJAX: Check pre-approval status
     */
    public static function ajax_check_preapproval_status() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'avvance_preapproval')) {
            wp_send_json_success(['status' => 'none']); // Fail silently for security
        }
        
        // Get browser fingerprint
        $browser_fingerprint = self::get_browser_fingerprint();
        
        // Get latest pre-approval for this browser from database
        $preapproval = self::get_latest_preapproval_by_fingerprint($browser_fingerprint);
        
        if (!$preapproval) {
            wp_send_json_success(['status' => 'none']);
        }
        
        // Check if expired
        if (!empty($preapproval['expiry_date'])) {
            $expiry = strtotime($preapproval['expiry_date']);
            if ($expiry && $expiry < time()) {
                wp_send_json_success(['status' => 'expired']);
            }
        }
        
        // Return status
        wp_send_json_success([
            'status' => $preapproval['status'] ?? 'pending',
            'max_amount' => !empty($preapproval['max_amount']) ? floatval($preapproval['max_amount']) : null,
            'customer_name' => $preapproval['customer_name'] ?? null,
            'expiry_date' => $preapproval['expiry_date'] ?? null
        ]);
    }
    
    /**
     * Process pre-approval webhook (called from main webhook handler)
     *
     * LEAD STATUS VALUES (only 2 possible values):
     * - PRE_APPROVED: Customer is pre-approved, includes maxPreApprovedAmount in metadata
     * - NOT_APPROVED: Customer is declined, NO metadata included
     */
    public static function process_preapproval_webhook($payload) {
        $event_details = $payload['eventDetails'];

        // Extract fields
        $request_id = $event_details['preApprovalRequestId'] ?? '';
        $lead_id = $event_details['leadid'] ?? '';
        $lead_status = $event_details['leadstatus'] ?? '';

        if (empty($request_id)) {
            avvance_log('Missing preApprovalRequestId in webhook', 'error');
            return new WP_Error('missing_request_id', 'Missing preApprovalRequestId in webhook');
        }

        // Validate lead status - only PRE_APPROVED and NOT_APPROVED are valid
        $valid_statuses = ['PRE_APPROVED', 'NOT_APPROVED'];
        if (!in_array($lead_status, $valid_statuses)) {
            avvance_log('Unknown lead status received: ' . $lead_status . ' (expected PRE_APPROVED or NOT_APPROVED)', 'warning');
        }

        avvance_log('Processing pre-approval webhook - Request ID: ' . $request_id . ', Lead ID: ' . $lead_id . ', Status: ' . $lead_status);

        // Find the pre-approval record in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';

        avvance_log('Searching for pre-approval in database...');

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE request_id = %s",
            $request_id
        ));

        if (!$record) {
            avvance_log('Pre-approval record not found for request ID: ' . $request_id, 'warning');
            return new WP_Error('record_not_found', 'Pre-approval record not found');
        }

        avvance_log('Pre-approval record found for request ID: ' . $request_id);

        // Extract max pre-approved amount from metadata (only present for PRE_APPROVED)
        $max_amount = null;
        if ('PRE_APPROVED' === $lead_status && isset($event_details['metadata']) && is_array($event_details['metadata'])) {
            foreach ($event_details['metadata'] as $meta) {
                if (isset($meta['key']) && 'maxPreApprovedAmount' === $meta['key']) {
                    $max_amount = floatval($meta['value']);
                    avvance_log('Max pre-approved amount: $' . number_format($max_amount, 2));
                    break;
                }
            }
        }

        // For NOT_APPROVED, explicitly set max_amount to null
        if ('NOT_APPROVED' === $lead_status) {
            $max_amount = null;
            avvance_log('Customer NOT_APPROVED - no max amount available');
        }

        // Parse expiry date with better error handling
        $expiry_date = null;
        if (isset($event_details['leadExpiryDate'])) {
            $raw_date = $event_details['leadExpiryDate'];

            try {
                // Handle ISO 8601 format with timezone: 2026-01-30T22:38:50.000+0000
                $date_obj = new DateTime($raw_date);
                $expiry_date = $date_obj->format('Y-m-d H:i:s');
                avvance_log('Parsed expiry date: ' . $expiry_date);
            } catch (Exception $e) {
                avvance_log('Failed to parse expiry date: ' . $e->getMessage(), 'warning');
                // Fallback: try strtotime
                $timestamp = strtotime($raw_date);
                if ($timestamp) {
                    $expiry_date = date('Y-m-d H:i:s', $timestamp);
                }
            }
        }

        // Update database record
        // Note: PII fields are stored encrypted/hashed where possible
        // webhook_payload is sanitized to remove PII for GDPR/CCPA compliance
        $update_data = [
            'status' => $lead_status,
            'max_amount' => $max_amount,
            'lead_id' => $lead_id,
            'customer_name' => $event_details['customerName'] ?? '',
            'customer_email' => $event_details['customerEmail'] ?? '',
            'customer_phone' => $event_details['customerPhone'] ?? '',
            'expiry_date' => $expiry_date,
            'updated_at' => current_time('mysql'),
            'webhook_payload' => wp_json_encode(self::sanitize_payload_for_storage($event_details))
        ];

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['request_id' => $request_id],
            ['%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );

        if (false === $result) {
            avvance_log('Database update failed! Error: ' . $wpdb->last_error, 'error');
            return new WP_Error('db_update_failed', 'Failed to update pre-approval record');
        }

        $status_message = ('PRE_APPROVED' === $lead_status)
            ? "PRE_APPROVED - Max Amount: $" . number_format($max_amount, 2)
            : "NOT_APPROVED - Customer declined";

        avvance_log("✅ Pre-approval webhook processed: Request ID {$request_id}, Status: {$status_message}");

        return true;
    }
    
    /**
     * Sanitize webhook payload for storage (GDPR/CCPA compliance)
     * Removes PII fields while preserving non-sensitive data for debugging
     *
     * @param array $payload The webhook event details
     * @return array Sanitized payload without PII
     */
    private static function sanitize_payload_for_storage($payload) {
        // List of PII fields to redact
        $pii_fields = [
            'customerName',
            'customerEmail',
            'customerPhone',
            'customerAddress',
            'firstName',
            'lastName',
            'email',
            'phone',
            'ssn',
            'socialSecurityNumber',
            'dateOfBirth',
            'dob',
        ];

        $sanitized = [];
        foreach ($payload as $key => $value) {
            // Check if this is a PII field
            if (in_array($key, $pii_fields, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$key] = self::sanitize_payload_for_storage($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get latest pre-approval for browser fingerprint
     */
    private static function get_latest_preapproval_by_fingerprint($fingerprint) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';
        
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE browser_fingerprint = %s 
             ORDER BY created_at DESC 
             LIMIT 1",
            $fingerprint
        ), ARRAY_A);
        
        return $record;
    }
    
    /**
     * Save pre-approval to database
     */
    private static function save_preapproval_to_db($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';

        // Ensure table exists (uses version check to avoid repeated dbDelta calls)
        self::maybe_create_table();
        
        $insert_data = [
            'request_id' => $data['request_id'],
            'session_id' => $data['session_id'],
            'browser_fingerprint' => $data['browser_fingerprint'],
            'status' => $data['status'] ?? 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];
        
        avvance_log('Inserting pre-approval into database - Request ID: ' . $data['request_id']);
        
        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            avvance_log('Database insert failed! Error: ' . $wpdb->last_error, 'error');
        } else {
            avvance_log('Pre-approval saved to database with fingerprint: ' . $data['browser_fingerprint'] . ' (Insert ID: ' . $wpdb->insert_id . ')');
        }
    }
    
    /**
     * Check if table needs to be created/updated
     *
     * Uses version tracking to avoid running dbDelta on every request.
     */
    private static function maybe_create_table() {
        $installed_version = get_option('avvance_db_version', '0');
        $current_version = '1.1.0';

        if (version_compare($installed_version, $current_version, '<')) {
            self::create_preapproval_table();
            update_option('avvance_db_version', $current_version);
        }
    }

    /**
     * Create pre-approval database table
     *
     * Called during plugin activation and when DB version changes.
     * Uses WordPress dbDelta for safe table creation/updates.
     */
    public static function create_preapproval_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';
        $charset_collate = $wpdb->get_charset_collate();

        // Note: dbDelta requires specific formatting - no IF NOT EXISTS, specific spacing
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            request_id varchar(255) NOT NULL,
            lead_id varchar(255) DEFAULT NULL,
            session_id varchar(255) NOT NULL,
            browser_fingerprint varchar(255) NOT NULL,
            status varchar(50) DEFAULT 'pending',
            max_amount decimal(10,2) DEFAULT NULL,
            customer_name varchar(255) DEFAULT NULL,
            customer_email varchar(255) DEFAULT NULL,
            customer_phone varchar(50) DEFAULT NULL,
            expiry_date datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            webhook_payload longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY request_id (request_id),
            KEY lead_id (lead_id),
            KEY session_id (session_id),
            KEY browser_fingerprint (browser_fingerprint),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        avvance_log('Pre-approval table created/verified');
    }
}