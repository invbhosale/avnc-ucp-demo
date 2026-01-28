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
     * Get price breakdown for amount
     *
     * @param float $amount The intended spending amount
     * @return array|WP_Error API response or error
     */
    public function get_price_breakdown($amount) {
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
                'Partner-ID' => self::PARTNER_ID,
                'routingKey' => $this->get_routing_key()
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
