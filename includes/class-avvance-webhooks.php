<?php
/**
 * Avvance Webhooks Handler
 *
 * Handles incoming webhooks from Avvance for:
 * - Loan status updates (payment authorized, settled, declined)
 * - Pre-approval status updates
 *
 * Authentication (dual-mode transition):
 * 1. HMAC-SHA256 (preferred): sender signs the raw JSON body with the shared secret
 *    and sends the result as X-Webhook-Signature: sha256=<lowercase_hex>.
 * 2. Bearer token (legacy, deprecated): Authorization: Bearer <token> or X-Avvance-Token.
 *    Accepted as a fallback; a deprecation warning is logged. Remove once all senders
 *    have migrated to HMAC.
 *
 * The shared secret for both modes is the `webhook_auth_token` gateway setting.
 *
 * @package Avvance_For_WooCommerce
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles incoming webhooks from Avvance.
 */
class Avvance_Webhooks {

	/**
	 * Initialize webhook handler
	 */
	public static function init() {
		// Register WooCommerce API endpoint for webhooks.
		add_action( 'woocommerce_api_avvance_webhook', array( __CLASS__, 'handle_webhook' ) );
	}

	/**
	 * Main webhook handler
	 *
	 * Validates authentication and routes to appropriate processor
	 */
	public static function handle_webhook() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		// Only accept POST requests.
		if ( 'POST' !== $request_method ) {
			avvance_log( 'ERROR: Invalid request method: ' . $request_method, 'error' );
			wp_send_json_error( array( 'message' => 'Method not allowed' ), 405 );
		}

		// Read raw payload before auth — HMAC verification requires the original body bytes.
		$raw_payload = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( empty( $raw_payload ) ) {
			avvance_log( 'ERROR: Empty webhook payload', 'error' );
			wp_send_json_error( array( 'message' => 'Empty payload' ), 400 );
		}

		// Validate authentication: HMAC-SHA256 preferred, Bearer token as legacy fallback.
		if ( ! self::validate_webhook_auth( $raw_payload ) ) {
			avvance_log( 'ERROR: Webhook authentication failed', 'error' );
			status_header( 401 );
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
		}

		// Record that the webhook endpoint has been confirmed active.
		if ( get_option( 'avvance_webhook_status' ) !== 'active' ) {
			update_option( 'avvance_webhook_status', 'active' );
		}

		$payload = json_decode( $raw_payload, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			avvance_log( 'ERROR: Invalid JSON payload: ' . json_last_error_msg(), 'error' );
			wp_send_json_error( array( 'message' => 'Invalid JSON' ), 400 );
		}

		$event_type = $payload['eventType'] ?? 'unknown';
		avvance_log( 'Webhook event type: ' . $event_type );

		// Route to appropriate handler based on event type.
		$result = self::route_webhook( $payload );

		if ( is_wp_error( $result ) ) {
			avvance_log( 'Webhook processing failed: ' . $result->get_error_message(), 'error' );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => 'Webhook processed' ) );
	}

	/**
	 * Validate webhook authentication.
	 *
	 * Tries HMAC-SHA256 first (preferred). Falls back to legacy Bearer token
	 * and logs a deprecation warning so the migration can be tracked.
	 *
	 * @param string $raw_payload Raw request body bytes.
	 * @return bool True if authentication succeeds, false otherwise.
	 */
	private static function validate_webhook_auth( $raw_payload ) {
		$gateway = avvance_get_gateway();
		if ( ! $gateway ) {
			avvance_log( 'Webhook auth: gateway not available', 'error' );
			return false;
		}

		$secret = trim( $gateway->get_option( 'webhook_auth_token' ) );
		if ( empty( $secret ) ) {
			avvance_log( 'Webhook auth: webhook_auth_token not configured in plugin settings', 'error' );
			return false;
		}

		// Preferred: HMAC-SHA256 via X-Webhook-Signature header.
		if ( self::verify_hmac_signature( $raw_payload, $secret ) ) {
			return true;
		}

		// Legacy fallback: Bearer token (deprecated — migrate to HMAC).
		if ( self::verify_bearer_token( $secret ) ) {
			avvance_log( 'Webhook auth: authenticated via legacy Bearer token — configure HMAC signing to remove this warning', 'warning' );
			return true;
		}

		avvance_log( 'Webhook auth: HMAC and Bearer authentication both failed', 'error' );
		return false;
	}

	/**
	 * Verify HMAC-SHA256 signature from X-Webhook-Signature header.
	 *
	 * Expected header format: X-Webhook-Signature: sha256=<lowercase_hex>
	 * Signed message: raw request body (no timestamp).
	 *
	 * @param string $raw_payload Raw request body bytes.
	 * @param string $secret      Shared signing secret.
	 * @return bool True if signature is valid, false if header absent or mismatch.
	 */
	private static function verify_hmac_signature( $raw_payload, $secret ) {
		$provided_sig = '';

		// Primary: $_SERVER maps X-Webhook-Signature → HTTP_X_WEBHOOK_SIGNATURE.
		if ( ! empty( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ) {
			$provided_sig = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) );
		}

		// Fallback: getallheaders() for servers that don't expose custom headers in $_SERVER.
		if ( empty( $provided_sig ) && function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $key => $value ) {
				if ( 'x-webhook-signature' === strtolower( $key ) ) {
					$provided_sig = sanitize_text_field( $value );
					break;
				}
			}
		}

		// No HMAC header present — this is not an HMAC request.
		if ( empty( $provided_sig ) ) {
			return false;
		}

		$expected_sig = 'sha256=' . hash_hmac( 'sha256', $raw_payload, $secret );
		return hash_equals( $expected_sig, $provided_sig );
	}

	/**
	 * Verify legacy Bearer token authentication.
	 *
	 * Checks Authorization: Bearer <token> and X-Avvance-Token headers.
	 * Deprecated: use HMAC signing instead.
	 *
	 * @param string $expected_token The configured webhook token.
	 * @return bool True if a matching token is found, false otherwise.
	 */
	private static function verify_bearer_token( $expected_token ) {
		$provided_token = '';

		// Attempt A: Authorization: Bearer <token> via $_SERVER.
		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( ! empty( $auth_header ) && str_starts_with( trim( $auth_header ), 'Bearer ' ) ) {
			$provided_token = trim( substr( trim( $auth_header ), 7 ) );
		}

		// Attempt B: X-Avvance-Token custom header via $_SERVER.
		if ( empty( $provided_token ) && ! empty( $_SERVER['HTTP_X_AVVANCE_TOKEN'] ) ) {
			$provided_token = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AVVANCE_TOKEN'] ) ) );
		}

		// Attempt C: getallheaders() case-insensitive fallback.
		if ( empty( $provided_token ) && function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $key => $value ) {
				$key_lower = strtolower( $key );
				if ( 'authorization' === $key_lower && str_starts_with( trim( $value ), 'Bearer ' ) ) {
					$provided_token = trim( substr( trim( $value ), 7 ) );
					break;
				}
				if ( 'x-avvance-token' === $key_lower && ! empty( $value ) ) {
					$provided_token = trim( $value );
					break;
				}
			}
		}

		if ( empty( $provided_token ) ) {
			return false;
		}

		return hash_equals( $expected_token, $provided_token );
	}

	/**
	 * Route webhook to appropriate handler
	 *
	 * @param array $payload Webhook payload.
	 * @return true|WP_Error
	 */
	private static function route_webhook( $payload ) {
		// Check if this is a pre-approval webhook.
		if ( self::is_preapproval_webhook( $payload ) ) {
			return self::process_preapproval_webhook( $payload );
		}

		return self::process_loan_status_webhook( $payload );
	}

	/**
	 * Check if webhook is for pre-approval
	 *
	 * @param array $payload Webhook payload.
	 * @return bool
	 */
	private static function is_preapproval_webhook( $payload ) {
		$event_details = $payload['eventDetails'] ?? array();

		// Pre-approval webhooks have preApprovalRequestId.
		if ( isset( $event_details['preApprovalRequestId'] ) ) {
			return true;
		}

		// Or leadstatus field.
		if ( isset( $event_details['leadstatus'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Process pre-approval webhook
	 *
	 * @param array $payload Webhook payload.
	 * @return true|WP_Error
	 */
	private static function process_preapproval_webhook( $payload ) {
		// Delegate to PreApproval Handler.
		if ( class_exists( 'Avvance_PreApproval_Handler' ) ) {
			return Avvance_PreApproval_Handler::process_preapproval_webhook( $payload );
		}

		avvance_log( 'ERROR: Avvance_PreApproval_Handler class not found', 'error' );
		return new WP_Error( 'handler_not_found', 'Pre-approval handler not available' );
	}

	/**
	 * Process loan status webhook
	 *
	 * Handles loan application status updates:
	 * - APPLICATION_STARTED
	 * - APPLICATION_APPROVED
	 * - APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT
	 * - INVOICE_PAYMENT_TRANSACTION_AUTHORIZED
	 * - INVOICE_PAYMENT_TRANSACTION_SETTLED
	 * - SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT
	 *
	 * @param array $payload Webhook payload.
	 * @return true|WP_Error
	 */
	private static function process_loan_status_webhook( $payload ) {
		avvance_log( '=== PROCESSING LOAN STATUS WEBHOOK ===' );

		$event_details = $payload['eventDetails'] ?? array();

		// Get loan status.
		$loan_status = $event_details['loanStatus']['status'] ?? '';

		if ( empty( $loan_status ) ) {
			avvance_log( 'ERROR: No loan status in webhook payload', 'error' );
			return new WP_Error( 'missing_status', 'No loan status provided' );
		}

		avvance_log( 'Loan Status: ' . $loan_status );

		// Find the order by partnerSessionId or applicationGUID.
		$order = self::find_order_from_webhook( $event_details );

		if ( ! $order ) {
			avvance_log( 'ERROR: Could not find order for webhook', 'error' );
			return new WP_Error( 'order_not_found', 'Order not found' );
		}

		$order_id = $order->get_id();
		avvance_log( 'Found order #' . $order_id );

		// Store the webhook status.
		$order->update_meta_data( '_avvance_last_webhook_status', $loan_status );
		$order->update_meta_data( '_avvance_last_webhook_time', current_time( 'mysql' ) );

		// Process based on status.
		switch ( $loan_status ) {
			case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':
				avvance_log( 'Processing AUTHORIZED status for order #' . $order_id );

				$partner_session_id = $order->get_meta( '_avvance_partner_session_id' );

				if ( empty( $partner_session_id ) ) {
					avvance_log( 'AUTHORIZED webhook: missing partnerSessionId on order #' . $order_id, 'error' );
					$order->add_order_note(
						__( 'Avvance payment authorization received but partnerSessionId missing. Pending reconciliation.', 'avvance-for-woocommerce' )
					);
					$order->save();
					break;
				}

				$gateway         = avvance_get_gateway();
				$loan_status_api = new Avvance_Loan_Status_API(
					array(
						'client_key'    => $gateway->get_option( 'client_key' ),
						'client_secret' => $gateway->get_option( 'client_secret' ),
						'merchant_id'   => $gateway->get_option( 'merchant_id' ),
						'partner_id'    => $gateway->get_option( 'partner_id' ),
						'environment'   => $gateway->get_option( 'environment' ),
					)
				);

				$confirmed_status = $loan_status_api->get_loan_status( $partner_session_id );

				if ( is_wp_error( $confirmed_status ) ) {
					avvance_log(
						'AUTHORIZED webhook: loan-status confirmation failed: ' . $confirmed_status->get_error_message(),
						'warning'
					);
					$order->add_order_note(
						__( 'Avvance payment authorization received but could not be confirmed via loan-status API. Pending reconciliation.', 'avvance-for-woocommerce' )
					);
					$order->save();
					// Return 200 to Avvance — cron will reconcile.
					break;
				}

				if ( 'AUTHORIZED' !== $confirmed_status ) {
					avvance_log(
						'AUTHORIZED webhook: loan-status mismatch. Webhook claimed AUTHORIZED but API returned: ' . $confirmed_status,
						'error'
					);
					$order->add_order_note(
						sprintf(
							/* translators: %s: actual loan status from API */
							__( 'Avvance webhook claimed AUTHORIZED but loan-status returned: %s. Order not marked paid.', 'avvance-for-woocommerce' ),
							$confirmed_status
						)
					);
					$order->save();
					break;
				}

				// Confirmed AUTHORIZED — safe to mark paid.
				$payment_transaction_id = $event_details['paymentTransactionId'] ?? '';
				$approval_code          = $event_details['approvalCode'] ?? '';

				if ( $payment_transaction_id ) {
					$order->update_meta_data( '_avvance_payment_transaction_id', $payment_transaction_id );
				}
				if ( $approval_code ) {
					$order->update_meta_data( '_avvance_approval_code', $approval_code );
				}
				if ( isset( $event_details['loanSummary'] ) ) {
					$order->update_meta_data( '_avvance_loan_summary', $event_details['loanSummary'] );
				}

				$order->payment_complete( $payment_transaction_id );
				$order->add_order_note(
					sprintf(
						/* translators: %s: payment transaction ID */
						__( 'Avvance payment authorized and confirmed via loan-status API. Transaction ID: %s', 'avvance-for-woocommerce' ),
						$payment_transaction_id ? $payment_transaction_id : 'N/A'
					)
				);

				if ( WC()->session ) {
					WC()->session->__unset( 'avvance_pending_order_id' );
				}

				avvance_log( 'Order #' . $order_id . ' marked as paid - loan-status confirmed AUTHORIZED' );
				break;

			case 'INVOICE_PAYMENT_TRANSACTION_SETTLED':
				avvance_log( 'Processing SETTLED status for order #' . $order_id );

				// Update note for settlement.
				$order->add_order_note( __( 'Avvance payment settled.', 'avvance-for-woocommerce' ) );

				// If not already paid (edge case), mark as paid now.
				if ( ! $order->is_paid() ) {
					$payment_transaction_id = $event_details['paymentTransactionId'] ?? '';
					$order->payment_complete( $payment_transaction_id );
					avvance_log( 'Order #' . $order_id . ' marked as paid (on settlement)' );
				}
				break;

			case 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT':
			case 'APPLICATION_PARTIALLY_APPROVED':
			case 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT':
				avvance_log( 'Processing DENIED/PARTIAL/ERROR status for order #' . $order_id );

				// Keep order as pending so consumer can retry (e.g., spouse applying).
				$order->add_order_note(
					sprintf(
						/* translators: %s: loan status */
						__( 'Avvance application declined or error: %s. Order kept pending for retry.', 'avvance-for-woocommerce' ),
						$loan_status
					)
				);

				avvance_log( 'Order #' . $order_id . ' kept pending for retry (status: ' . $loan_status . ')' );
				break;

			case 'APPLICATION_STARTED':
				avvance_log( 'Processing APPLICATION_STARTED for order #' . $order_id );
				$order->add_order_note( __( 'Customer started Avvance application.', 'avvance-for-woocommerce' ) );
				break;

			case 'APPLICATION_APPROVED':
				avvance_log( 'Processing APPLICATION_APPROVED for order #' . $order_id );
				$order->add_order_note( __( 'Avvance application approved. Awaiting customer to complete checkout.', 'avvance-for-woocommerce' ) );
				break;

			case 'APPLICATION_PENDING_REQUIRE_CUSTOMER_ACTION':
				avvance_log( 'Processing PENDING status for order #' . $order_id );
				$order->add_order_note( __( 'Avvance application requires customer action.', 'avvance-for-woocommerce' ) );
				break;

			case 'APPLICATION_LINK_EXPIRED':
				avvance_log( 'Processing APPLICATION_LINK_EXPIRED for order #' . $order_id );
				if ( ! $order->is_paid() ) {
					$order->update_status(
						'cancelled',
						__( 'Avvance application link expired (webhook notification received).', 'avvance-for-woocommerce' )
					);
				}
				avvance_log( 'Order #' . $order_id . ' cancelled due to expired application link' );
				break;

			default:
				avvance_log( 'Unknown loan status: ' . $loan_status . ' for order #' . $order_id, 'warning' );
				$order->add_order_note(
					sprintf(
						/* translators: %s: loan status */
						__( 'Avvance status update: %s', 'avvance-for-woocommerce' ),
						$loan_status
					)
				);
		}

		$order->save();
		avvance_log( 'Order #' . $order_id . ' saved successfully' );

		return true;
	}

	/**
	 * Find order from webhook event details
	 *
	 * @param array $event_details Webhook event details.
	 * @return WC_Order|null
	 */
	private static function find_order_from_webhook( $event_details ) {
		// Try to find by applicationGUID.
		$application_guid = $event_details['applicationGUID'] ?? '';
		if ( $application_guid ) {
			avvance_log( 'Searching for order by applicationGUID: ' . $application_guid );

			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- required for order lookup by Avvance GUID
					'meta_key'   => '_avvance_application_guid',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value' => $application_guid,
				)
			);

			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}

		// Try to find by partnerSessionId.
		$partner_session_id = $event_details['partnerSessionId'] ?? '';
		if ( $partner_session_id ) {
			avvance_log( 'Searching for order by partnerSessionId: ' . $partner_session_id );

			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- required for order lookup by session ID
					'meta_key'   => '_avvance_partner_session_id',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value' => $partner_session_id,
				)
			);

			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}

		// Try to find by invoiceId (which is the order ID).
		$invoice_id = $event_details['invoiceId'] ?? '';
		if ( $invoice_id ) {
			avvance_log( 'Searching for order by invoiceId: ' . $invoice_id );

			$order = wc_get_order( $invoice_id );
			if ( $order && 'avvance' === $order->get_payment_method() ) {
				return $order;
			}
		}

		// Try merchantTransactionId (which is the order key).
		$merchant_transaction_id = $event_details['merchantTransactionId'] ?? '';
		if ( $merchant_transaction_id ) {
			avvance_log( 'Searching for order by merchantTransactionId (order_key): ' . $merchant_transaction_id );

			$order_id = wc_get_order_id_by_order_key( $merchant_transaction_id );
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order && 'avvance' === $order->get_payment_method() ) {
					return $order;
				}
			}
		}

		avvance_log( 'No order found for webhook event details', 'warning' );
		return null;
	}
}
