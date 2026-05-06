<?php
/**
 * Avvance Loan Status API
 *
 * Retrieves current loan status via the loan-status endpoint.
 * Returns a clean status string on success.
 * Returns WP_Error('loan_not_authorized') when the loan is pending
 * authorization — callers should treat this as a polling signal, not a failure.
 *
 * @package Avvance_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API client for the Avvance loan-status endpoint.
 */
class Avvance_Loan_Status_API extends Avvance_API_Base {

	/**
	 * Get current loan status for a partner session.
	 *
	 * @param string $partner_session_id The partnerSessionId from the financing request.
	 * @return string|WP_Error Status string on success, WP_Error on failure.
	 *   WP_Error code 'loan_not_authorized' means loan is pending — not an error condition.
	 */
	public function get_loan_status( $partner_session_id ) {
		avvance_log( 'Getting loan status for partnerSessionId: ' . $partner_session_id );

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			$this->base_url . '/poslp/services/avvance-loan/v1/loan-status',
			array(
				'headers' => array(
					'Authorization'     => 'Bearer ' . $token,
					'Content-Type'      => 'application/json',
					'Correlation-ID'    => $this->generate_correlation_id(),
					'Partner-ID'        => $this->partner_id,
					'Merchant-ID'       => $this->merchant_id,
					'PartnerSession-ID' => $partner_session_id,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			avvance_log( 'Loan status request failed: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			$loan_status = $body['loanStatus'] ?? '';
			avvance_log( 'Loan status retrieved: ' . $loan_status );
			return $loan_status;
		}

		if ( 400 === $code ) {
			$message = $body['error']['message'] ?? '';
			if ( false !== stripos( $message, 'Loan yet to be authorized' ) ) {
				avvance_log( 'Loan not yet authorized for session: ' . $partner_session_id );
				return new WP_Error(
					'loan_not_authorized',
					'Loan pending authorization',
					array( 'status' => 400 )
				);
			}
			avvance_log( 'Loan status 400 error: ' . $message, 'error' );
			return new WP_Error(
				'loan_status_failed',
				$message,
				array( 'status' => 400 )
			);
		}

		avvance_log( 'Loan status unexpected response code: ' . $code, 'error' );
		return new WP_Error(
			'loan_status_failed',
			'Unexpected response code: ' . $code,
			array( 'status' => $code )
		);
	}
}
