<?php
/**
 * Avvance Price Breakdown API
 *
 * Gets monthly payment information for widget display,
 * showing customers estimated payment plans for products.
 *
 * @package Avvance_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Price_Breakdown_API extends Avvance_API_Base {

    /**
     * Cache TTL for price breakdown responses (1 hour)
     *
     * @var int
     */
    const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Get price breakdown for amount
     *
     * Results are cached for 1 hour per merchant/amount combination
     * to reduce API calls for repeated product page views.
     *
     * @param float $amount The intended spending amount
     * @param bool $bypass_cache Force fresh API call (default: false)
     * @return array|WP_Error API response or error
     */
    public function get_price_breakdown($amount, $bypass_cache = false) {
        $amount = floatval($amount);

        // Generate cache key based on merchant and amount
        $cache_key = 'avvance_price_' . md5($this->merchant_id . '_' . $amount);

        // Check cache first (unless bypass requested)
        if (!$bypass_cache) {
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                avvance_log('Using cached price breakdown for amount: ' . $amount);
                return $cached;
            }
        }

        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        avvance_log('Getting price breakdown for amount: ' . $amount);

        $response = wp_remote_post($this->base_url . '/poslp/services/avvance-loan/v1/price-breakdown', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Correlation-ID' => $this->generate_correlation_id(),
                'Partner-ID' => self::PARTNER_ID
            ],
            'body' => wp_json_encode([
                'merchantId' => $this->merchant_id,
                'intendedSpendingAmount' => $amount
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

        if (200 !== $code && 201 !== $code) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Price breakdown request failed';
            avvance_log('Price breakdown request failed: ' . $error_msg, 'error');
            return new WP_Error('price_breakdown_failed', $error_msg);
        }

        if (empty($body)) {
            avvance_log('Invalid price breakdown response structure', 'error');
            return new WP_Error('invalid_response', 'Invalid price breakdown response');
        }

        // Cache successful response
        set_transient($cache_key, $body, self::CACHE_TTL);
        avvance_log('Price breakdown request successful (cached for ' . self::CACHE_TTL . 's)');

        return $body;
    }

    /**
     * Clear cached price breakdown for a specific amount
     *
     * @param float $amount The amount to clear cache for
     */
    public function clear_cache($amount) {
        $cache_key = 'avvance_price_' . md5($this->merchant_id . '_' . floatval($amount));
        delete_transient($cache_key);
    }
}
