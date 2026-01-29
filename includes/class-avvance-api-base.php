<?php
/**
 * Avvance API Base Class
 *
 * Provides shared functionality for all Avvance API classes:
 * - OAuth token management (cached)
 * - Environment-based URL configuration
 * - Common API settings
 *
 * @package Avvance_For_WooCommerce
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class Avvance_API_Base {

    const PARTNER_ID = 'CONVERGE';

    /** @var string API client key */
    protected $client_key;

    /** @var string API client secret */
    protected $client_secret;

    /** @var string Merchant ID */
    protected $merchant_id;

    /** @var string API base URL */
    protected $base_url;

    /** @var string Environment (production or sandbox) */
    protected $environment;

    /**
     * Constructor
     *
     * @param array $settings API settings containing client_key, client_secret, merchant_id, environment
     */
    public function __construct($settings) {
        $this->client_key = $settings['client_key'] ?? '';
        $this->client_secret = $settings['client_secret'] ?? '';
        $this->merchant_id = $settings['merchant_id'] ?? '';
        $this->environment = $settings['environment'] ?? 'sandbox';

        $this->base_url = ($this->environment === 'production')
            ? 'https://alpha-api2.usbank.com'
            : 'https://alpha-api.usbank.com';
    }

    /**
     * Get OAuth access token (cached)
     *
     * Token is cached using WordPress transients for the duration specified
     * in the API response (typically 899 seconds), minus a 60-second buffer.
     *
     * @return string|WP_Error Access token or error
     */
    protected function get_access_token() {
        $cache_key = 'avvance_token_' . md5($this->client_key);
        $cached = get_transient($cache_key);

        if ($cached) {
            avvance_log('Using cached access token');
            return $cached;
        }

        return $this->fetch_new_token($cache_key);
    }

    /**
     * Get fresh access token (bypass cache)
     *
     * Use this when you need to ensure you have a fresh token,
     * for example after receiving a 401 Unauthorized response.
     *
     * @return string|WP_Error Access token or error
     */
    protected function get_fresh_access_token() {
        avvance_log('Requesting fresh access token (bypassing cache)');
        $cache_key = 'avvance_token_' . md5($this->client_key);
        return $this->fetch_new_token($cache_key);
    }

    /**
     * Fetch new token from API and cache it
     *
     * @param string $cache_key Transient cache key
     * @return string|WP_Error Access token or error
     */
    private function fetch_new_token($cache_key) {
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

        if (200 !== $code || empty($body['accessToken'])) {
            avvance_log('Token request failed with code ' . $code, 'error');
            return new WP_Error('auth_failed', 'Failed to obtain access token');
        }

        // Cache token with TTL from response minus 60 seconds buffer
        $ttl = max(60, intval($body['expiresIn'] ?? 600) - 60);
        set_transient($cache_key, $body['accessToken'], $ttl);

        avvance_log('Access token obtained and cached (TTL: ' . $ttl . 's)');
        return $body['accessToken'];
    }

    /**
     * Clear the cached token
     *
     * Use this after receiving authentication errors to force re-authentication.
     */
    protected function clear_token_cache() {
        $cache_key = 'avvance_token_' . md5($this->client_key);
        delete_transient($cache_key);
        avvance_log('Token cache cleared');
    }

    /**
     * Get routing key for environment
     *
     * @return string Routing key (az1 for production, uat3 for sandbox)
     */
    protected function get_routing_key() {
        return ($this->environment === 'production') ? 'az1' : 'uat3';
    }

    /**
     * Generate correlation ID for request tracking
     *
     * @return string UUID for correlation
     */
    protected function generate_correlation_id() {
        return wp_generate_uuid4();
    }
}
