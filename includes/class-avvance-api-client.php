<?php
/**
 * Avvance API Client
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_API_Client {
    
    const PARTNER_ID = 'CONVERGE';
    
    private $client_key;
    private $client_secret;
    private $merchant_id;
    private $base_url;
    private $environment;
    
    public function __construct($settings) {
        $this->client_key = $settings['client_key'];
        $this->client_secret = $settings['client_secret'];
        $this->merchant_id = $settings['merchant_id'];
        $this->environment = $settings['environment'];
        
        $this->base_url = ($this->environment === 'production')
            ? 'https://alpha-api.usbank.com'
            : 'https://alpha-api.usbank.com';
    }
    
    /**
     * Get OAuth access token (cached)
     */
    private function get_access_token() {
        $cache_key = 'avvance_token_' . md5($this->client_key);
        $cached = get_transient($cache_key);
        
        if ($cached) {
            avvance_log('Using cached access token');
            return $cached;
        }
        
        avvance_log('Requesting new access token');
        
        $auth = base64_encode($this->client_key . ':' . $this->client_secret);
        
        $response = wp_remote_post($this->base_url . '/auth/oauth2/v1/token', [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => 'grant_type=client_credentials',
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            avvance_log('Token request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code !== 200 || empty($body['accessToken'])) {
            avvance_log('Token request failed with code ' . $code, 'error');
            return new WP_Error('auth_failed', 'Failed to obtain access token');
        }
        
        // Cache for 10 minutes (expiresIn is typically 900 seconds)
        $ttl = max(60, intval($body['expiresIn'] ?? 600) - 60);
        set_transient($cache_key, $body['accessToken'], $ttl);
        
        avvance_log('Access token obtained and cached');
        return $body['accessToken'];
    }
    
    /**
     * Create financing request
     */
    public function create_financing_request($order) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        // Generate unique partner session ID
        $partner_session_id = wp_generate_uuid4();
        
        $payload = [
            'partnerSessionId' => $partner_session_id,
            'clientApplication' => 'MERCHANT_PORTAL',
            'merchantId' => $this->merchant_id,
            'invoiceAmount' => strval($order->get_total()),
            'invoiceId' => strval($order->get_id()),
            'merchantTransactionId' => $order->get_order_key(),
            'purchaseDescription' => sprintf('Order #%s from %s', $order->get_order_number(), get_bloginfo('name')),
            'consumer' => [
                'email' => $order->get_billing_email(),
                'firstName' => $order->get_billing_first_name(),
                'lastName' => $order->get_billing_last_name(),
                'mobilePhone' => preg_replace('/\D/', '', $order->get_billing_phone()),
                'billingAddress' => [
                    'street1' => $order->get_billing_address_1(),
                    'street2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postalCode' => $order->get_billing_postcode(),
                    'countryCode' => $order->get_billing_country()
                ],
                'shippingAddress' => [
                    'street1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
                    'street2' => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
                    'city' => $order->get_shipping_city() ?: $order->get_billing_city(),
                    'state' => $order->get_shipping_state() ?: $order->get_billing_state(),
                    'postalCode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
                    'countryCode' => $order->get_shipping_country() ?: $order->get_billing_country()
                ],
                'IPAddress' => $order->get_customer_ip_address()
            ],
            'partnerReturnErrorUrl' => wc_get_cart_url(),
            'metadata' => [
                ['key' => 'platform', 'value' => 'WooCommerce'],
                ['key' => 'plugin_version', 'value' => AVVANCE_VERSION],
                ['key' => 'order_id', 'value' => strval($order->get_id())]
            ]
        ];
        
        avvance_log('Creating financing request for order #' . $order->get_id());
        
        $response = wp_remote_post($this->base_url . '/poslp/services/avvance-loan/v1/create', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Correlation-ID' => wp_generate_uuid4(),
                'partner-ID' => self::PARTNER_ID
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            avvance_log('Financing request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code !== 201 || empty($body['consumerOnboardingURL'])) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'API request failed';
            avvance_log('Financing request failed: ' . $error_msg, 'error');
            return new WP_Error('api_error', $error_msg);
        }
        
        avvance_log('Financing request successful. Application GUID: ' . ($body['applicationGUID'] ?? 'N/A'));
        
        // Add partnerSessionId to response
        $body['partnerSessionId'] = $partner_session_id;
        
        return $body;
    }
    
/**
 * Get notification status
 */
public function get_notification_status($application_guid) {
    // FORCE fresh token for notification-status (don't use cache)
    $token = $this->get_fresh_access_token();
    
    if (is_wp_error($token)) {
        return $token;
    }
    
    avvance_log('Getting notification status for GUID: ' . $application_guid);
    avvance_log("Notification-status request headers: merchantId={$this->merchant_id}, notificationId={$application_guid}, environment={$this->environment}");
    $response = wp_remote_get($this->base_url . '/poslp/services/avvance-loan/v1/notification-status', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Correlation-ID' => wp_generate_uuid4(),
			'Content-Type' => 'application/json',
            'partner-ID' => self::PARTNER_ID,
            'merchant-Id' => $this->merchant_id,  // ✓ Already here
            'notificationId' => $application_guid
        ],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        avvance_log('Notification status request failed: ' . $response->get_error_message(), 'error');
        return $response;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    // Log the actual response for debugging
    avvance_log("Notification status response code: {$code}, body: {$body}");
    
    if ($code !== 200) {
        avvance_log('Notification status request failed with code ' . $code, 'error');
        
        // If 401, clear the token cache and log details
        if ($code === 401) {
            $cache_key = 'avvance_token_' . md5($this->client_key);
            delete_transient($cache_key);
            avvance_log('Token cache cleared due to 401 on notification-status', 'error');
        }
        
        return new WP_Error('api_error', "Failed to get notification status (HTTP {$code}): {$body}");
    }
    
    avvance_log('Notification status retrieved successfully');
    return json_decode($body, true);
}

/**
 * Get fresh access token (bypass cache)
 */
private function get_fresh_access_token() {
    avvance_log('Requesting fresh access token (bypassing cache)');
    
    $auth = base64_encode($this->client_key . ':' . $this->client_secret);
    
    $response = wp_remote_post($this->base_url . '/auth/oauth2/v1/token', [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => 'grant_type=client_credentials',
        'timeout' => 30
    ]);
    
    if (is_wp_error($response)) {
        avvance_log('Fresh token request failed: ' . $response->get_error_message(), 'error');
        return $response;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($code !== 200 || empty($body['accessToken'])) {
        avvance_log('Fresh token request failed with code ' . $code, 'error');
        return new WP_Error('auth_failed', 'Failed to obtain fresh access token');
    }
    
    // Update cache with new token
    $cache_key = 'avvance_token_' . md5($this->client_key);
    $ttl = max(60, intval($body['expiresIn'] ?? 600) - 60);
    set_transient($cache_key, $body['accessToken'], $ttl);
    
    avvance_log('Fresh access token obtained and cached');
    return $body['accessToken'];
}
    
    /**
     * Void transaction
     */
    public function void_transaction($partner_session_id) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        avvance_log('Voiding transaction for session: ' . $partner_session_id);
        
        $response = wp_remote_post($this->base_url . '/poslp/services/avvance-loan/v1/void', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Correlation-ID' => wp_generate_uuid4(),
                'partner-ID' => self::PARTNER_ID
            ],
            'body' => wp_json_encode([
                'merchantId' => $this->merchant_id,
                'partnerSessionId' => $partner_session_id
            ]),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            avvance_log('Void request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
		
		 // Log the raw response details
			avvance_log('API Response | Status Code: ' . $code . ' | Body: ' . $body);
		// --- End of new code ---

        
        if ($code !== 201 && $code !== 200) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Void failed';
            avvance_log('Void request failed: ' . $error_msg, 'error');
            return new WP_Error('void_failed', $error_msg);
        }
        
        avvance_log('Void successful');
        return $body;
    }
    
    /**
     * Refund transaction
     */
    public function refund_transaction($partner_session_id, $amount) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        avvance_log("Refunding transaction for session: {$partner_session_id}, amount: {$amount}");
        
        $response = wp_remote_post($this->base_url . '/poslp/services/avvance-loan/v1/refund', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Correlation-ID' => wp_generate_uuid4(),
                'partner-ID' => self::PARTNER_ID
            ],
            'body' => wp_json_encode([
                'merchantId' => $this->merchant_id,
                'partnerSessionId' => $partner_session_id,
                'refundAmount' => floatval($amount)
            ]),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            avvance_log('Refund request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code !== 201 && $code !== 200) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Refund failed';
            avvance_log('Refund request failed: ' . $error_msg, 'error');
            return new WP_Error('refund_failed', $error_msg);
        }
        
        avvance_log('Refund successful');
        return $body;
    }
}
