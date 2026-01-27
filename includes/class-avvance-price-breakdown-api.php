<?php
/**
 * Avvance Price Breakdown API
 * Gets monthly payment information for widget display
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Price_Breakdown_API {
    
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
        
        $this->base_url = 'https://alpha-api.usbank.com';
    }
    
    /**
     * Get OAuth access token (reuse from main API client)
     */
    private function get_access_token() {
        $cache_key = 'avvance_token_' . md5($this->client_key);
        $cached = get_transient($cache_key);
        
        if ($cached) {
            return $cached;
        }
        
        avvance_log('Requesting new access token for price breakdown');
        
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
        
        $ttl = max(60, intval($body['expiresIn'] ?? 600) - 60);
        set_transient($cache_key, $body['accessToken'], $ttl);
        
        return $body['accessToken'];
    }
    
    /**
     * Get price breakdown for amount
     */
    public function get_price_breakdown($amount) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        avvance_log('Getting price breakdown for amount: ' . $amount);
        
        $routing_key = ($this->environment === 'production') ? 'az1' : 'uat3';
        
        $response = wp_remote_post($this->base_url . '/poslp/services/avvance-loan/v1/price-breakdown', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Correlation-ID' => wp_generate_uuid4(),
                'Partner-ID' => self::PARTNER_ID,
                'routingKey' => $routing_key
            ],
            'body' => wp_json_encode([
                'merchantId' => $this->merchant_id,
                'intendedSpendingAmount' => floatval($amount)
            ]),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            avvance_log('Price breakdown request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        avvance_log('Price breakdown API response code: ' . $code);
        // Note: Response body not logged to prevent PII exposure (GDPR/CCPA compliance)
        
        if ($code !== 200 && $code !== 201) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Price breakdown request failed';
            avvance_log('Price breakdown request failed: ' . $error_msg, 'error');
            return new WP_Error('price_breakdown_failed', $error_msg);
        }
        
        if (empty($body)) {
            avvance_log('Invalid price breakdown response structure', 'error');
            return new WP_Error('invalid_response', 'Invalid price breakdown response');
        }
        
        avvance_log('Price breakdown request successful');
        
        return $body;
    }
}