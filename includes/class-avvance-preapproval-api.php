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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles pre-approval API requests.
 */
class Avvance_PreApproval_API extends Avvance_API_Base {

	/**
	 * Create pre-approval request
	 *
	 * @param string $session_id Session ID from Avvance widget.
	 * @return array|WP_Error API response or error
	 */
	public function create_preapproval( $session_id ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$request_url    = $this->base_url . '/poslp/services/pre-approval/v1/create';
		$correlation_id = $this->generate_correlation_id();

		$headers = array(
			'Authorization'  => 'Bearer ' . $token,
			'Content-Type'   => 'application/json',
			'channel-id'     => 'ECOM',
			'application-id' => 'woo',
			'Correlation-ID' => $correlation_id,
			'Session-ID'     => $session_id,
		);

		$request_body = array(
			'merchantID' => $this->merchant_id,
		);

		avvance_log( 'Creating pre-approval request for session: ' . $session_id );

		$response = wp_remote_post(
			$request_url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			avvance_log( 'Pre-approval request failed: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code && 201 !== $code ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Pre-approval request failed';
			avvance_log( 'Pre-approval request failed: ' . $error_msg, 'error' );
			return new WP_Error( 'preapproval_failed', $error_msg );
		}

		if ( empty( $body['preApprovalOnboardingURL'] ) || empty( $body['preApprovalRequestID'] ) ) {
			avvance_log( 'Invalid pre-approval response structure', 'error' );
			return new WP_Error( 'invalid_response', 'Invalid pre-approval response' );
		}

		avvance_log( 'Pre-approval request successful. Request ID: ' . $body['preApprovalRequestID'] );

		return $body;
	}
}
