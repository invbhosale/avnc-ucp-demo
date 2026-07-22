<?php
/**
 * Avvance Pre-Approval Offers API
 *
 * Gets the actual loan offers a pre-approved consumer has prequalified for,
 * keyed by their pre-approval request ID.
 *
 * @package Avvance_For_WooCommerce
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets loan offers for a pre-approved consumer.
 */
class Avvance_PreApproval_Offers_API extends Avvance_API_Base {

	/**
	 * Cache TTL for pre-approval offers responses (1 hour)
	 *
	 * @var int
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Get pre-approval offers for a request ID and amount
	 *
	 * Results are cached for 1 hour per request-id/amount combination
	 * to reduce API calls for repeated modal opens/recalculations.
	 *
	 * @param string $request_id   The pre-approval request ID (used as shoppingCartId).
	 * @param float  $amount       The intended spending amount.
	 * @param bool   $bypass_cache Force fresh API call (default: false).
	 * @return array|WP_Error API response or error
	 */
	public function get_offers( $request_id, $amount, $bypass_cache = false ) {
		$request_id = sanitize_text_field( $request_id );
		$amount     = floatval( $amount );

		if ( empty( $request_id ) ) {
			return new WP_Error( 'missing_request_id', 'Pre-approval request ID is required' );
		}

		// Generate cache key based on request id and amount.
		$cache_key = 'avvance_preapproval_offers_' . md5( $request_id . '_' . $amount );

		// Check cache first (unless bypass requested).
		if ( ! $bypass_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				avvance_log( 'Using cached pre-approval offers for amount: ' . $amount );
				return $cached;
			}
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		avvance_log( 'Getting pre-approval offers for amount: ' . $amount );

		$response = wp_remote_post(
			$this->base_url . '/poslp/services/avvance-loan/v1/preapproval-offers',
			array(
				'headers' => array(
					'Authorization'  => 'Bearer ' . $token,
					'Content-Type'   => 'application/json',
					'Correlation-ID' => $this->generate_correlation_id(),
					'Partner-ID'     => $this->partner_id,
					'Channel-ID'     => 'ECOM',
				),
				'body'    => wp_json_encode(
					array(
						'shoppingCartId'         => $request_id,
						'merchantId'             => $this->merchant_id,
						'intendedSpendingAmount' => $amount,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			avvance_log( 'Pre-approval offers request failed: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		avvance_log( 'Pre-approval offers API response code: ' . $code );
		// Note: Response body not logged to prevent PII exposure (GDPR/CCPA compliance).

		if ( 200 !== $code && 201 !== $code ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Pre-approval offers request failed';
			avvance_log( 'Pre-approval offers request failed: ' . $error_msg, 'error' );
			return new WP_Error( 'preapproval_offers_failed', $error_msg );
		}

		if ( empty( $body ) ) {
			avvance_log( 'Invalid pre-approval offers response structure', 'error' );
			return new WP_Error( 'invalid_response', 'Invalid pre-approval offers response' );
		}

		// Cache successful response.
		set_transient( $cache_key, $body, self::CACHE_TTL );
		avvance_log( 'Pre-approval offers request successful (cached for ' . self::CACHE_TTL . 's)' );

		return $body;
	}

	/**
	 * Clear cached pre-approval offers for a specific request id/amount
	 *
	 * @param string $request_id The pre-approval request ID.
	 * @param float  $amount     The amount to clear cache for.
	 */
	public function clear_cache( $request_id, $amount ) {
		$cache_key = 'avvance_preapproval_offers_' . md5( sanitize_text_field( $request_id ) . '_' . floatval( $amount ) );
		delete_transient( $cache_key );
	}
}
