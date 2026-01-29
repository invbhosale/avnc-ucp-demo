<?php
/**
 * Avvance API Client
 *
 * Handles core financing operations:
 * - Creating financing requests
 * - Getting notification status
 * - Voiding transactions
 * - Processing refunds
 *
 * @package Avvance_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_API_Client extends Avvance_API_Base {

    /**
     * Create financing request
     *
     * @param WC_Order $order WooCommerce order object
     * @return array|WP_Error API response or error
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
                'Correlation-ID' => $this->generate_correlation_id(),
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

        if (201 !== $code || empty($body['consumerOnboardingURL'])) {
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
     *
     * @param string $application_guid Application GUID
     * @return array|WP_Error API response or error
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
                'Correlation-ID' => $this->generate_correlation_id(),
                'Content-Type' => 'application/json',
                'partner-ID' => self::PARTNER_ID,
                'merchant-Id' => $this->merchant_id,
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

        if (200 !== $code) {
            avvance_log('Notification status request failed with code ' . $code, 'error');

            // If 401, clear the token cache and log details
            if (401 === $code) {
                $this->clear_token_cache();
                avvance_log('Token cache cleared due to 401 on notification-status', 'error');
            }

            return new WP_Error('api_error', "Failed to get notification status (HTTP {$code}): {$body}");
        }

        avvance_log('Notification status retrieved successfully');
        return json_decode($body, true);
    }

    /**
     * Void transaction
     *
     * @param string $partner_session_id Partner session ID
     * @return array|WP_Error API response or error
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
                'Correlation-ID' => $this->generate_correlation_id(),
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
        avvance_log('API Response | Status Code: ' . $code . ' | Body: ' . wp_json_encode($body));

        if (201 !== $code && 200 !== $code) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Void failed';
            avvance_log('Void request failed: ' . $error_msg, 'error');
            return new WP_Error('void_failed', $error_msg);
        }

        avvance_log('Void successful');
        return $body;
    }

    /**
     * Refund transaction
     *
     * @param string $partner_session_id Partner session ID
     * @param float $amount Refund amount
     * @return array|WP_Error API response or error
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
                'Correlation-ID' => $this->generate_correlation_id(),
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

        if (201 !== $code && 200 !== $code) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Refund failed';
            avvance_log('Refund request failed: ' . $error_msg, 'error');
            return new WP_Error('refund_failed', $error_msg);
        }

        avvance_log('Refund successful');
        return $body;
    }
}
