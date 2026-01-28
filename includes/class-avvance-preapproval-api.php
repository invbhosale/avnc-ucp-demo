<?php
/**
 * Avvance Pre-Approval API Handler
 *
 * Handles pre-approval requests for customers to check their
 * financing eligibility before completing a purchase.
 *
 * @package Avvance_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_PreApproval_API extends Avvance_API_Base {

    /**
     * Create pre-approval request
     *
     * @param string $session_id Session ID from Avvance widget
     * @param string $hashed_mid Hashed merchant ID
     * @return array|WP_Error API response or error
     */
    public function create_preapproval($session_id, $hashed_mid) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        avvance_log('Creating pre-approval request for session: ' . $session_id);

        $response = wp_remote_post($this->base_url . '/poslp/services/pre-approval/v1/create', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'channel-id' => 'owa',
                'Correlation-ID' => $this->generate_correlation_id(),
                'application-id' => 'woo',
                'routingKey' => $this->get_routing_key(),
                'clientdata' => json_encode(['ChannelID' => 'owa']),
                'Session-ID' => $session_id
            ],
            'body' => wp_json_encode([
                'hashedMID' => $hashed_mid
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            avvance_log('Pre-approval request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        avvance_log('Pre-approval API response code: ' . $code);
        // Note: Response body not logged to prevent PII exposure (GDPR/CCPA compliance)

        if ($code !== 200 && $code !== 201) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Pre-approval request failed';
            avvance_log('Pre-approval request failed: ' . $error_msg, 'error');
            return new WP_Error('preapproval_failed', $error_msg);
        }

        if (empty($body['preApprovalOnboardingURL']) || empty($body['preApprovalRequestID'])) {
            avvance_log('Invalid pre-approval response structure', 'error');
            return new WP_Error('invalid_response', 'Invalid pre-approval response');
        }

        avvance_log('Pre-approval request successful. Request ID: ' . $body['preApprovalRequestID']);

        return $body;
    }
}
