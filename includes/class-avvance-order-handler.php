<?php
/**
 * Avvance Order Handler
 *
 * Manages order lifecycle, cart resume, and cleanup.
 *
 * @package Avvance_For_WooCommerce
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages order lifecycle, cart resume, and cleanup.
 */
class Avvance_Order_Handler {

	/**
	 * Initialize order handler hooks.
	 */
	public static function init() {
		// Cart resume banner.
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'cart_resume_banner' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'cart_resume_banner' ) );

		// Manual status check AJAX.
		add_action( 'wp_ajax_avvance_manual_status_check', array( __CLASS__, 'ajax_manual_status_check' ) );
		add_action( 'wp_ajax_nopriv_avvance_manual_status_check', array( __CLASS__, 'ajax_manual_status_check' ) );

		// Cleanup expired URLs (daily).
		add_action( 'avvance_daily_cleanup', array( __CLASS__, 'cleanup_expired_urls' ) );
		if ( ! wp_next_scheduled( 'avvance_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'avvance_daily_cleanup' );
		}

		// Reconcile aging pending orders (hourly, via Action Scheduler) — a safety net for
		// webhooks that never arrived, or arrived but couldn't be fully processed.
		add_action( 'avvance_reconcile_pending_orders', array( __CLASS__, 'reconcile_pending_orders' ) );
		// Deferred to init: this fires during plugins_loaded, before Action Scheduler is
		// guaranteed to have loaded (it's bootstrapped by WooCommerce's own plugins_loaded
		// callback, whose relative order isn't guaranteed) — as_*() calls this early would
		// silently no-op via the function_exists() guard, never scheduling the job at all.
		add_action( 'init', array( __CLASS__, 'maybe_schedule_reconciliation_job' ) );

		// Admin order meta box.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ) );
	}

	/**
	 * Schedule the hourly reconciliation job via Action Scheduler, if not
	 * already scheduled. Run on init (not plugins_loaded) so Action
	 * Scheduler — bootstrapped by WooCommerce's own plugins_loaded callback —
	 * is guaranteed to have finished loading by the time this runs.
	 */
	public static function maybe_schedule_reconciliation_job() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			avvance_log( 'Action Scheduler not available - reconciliation job not scheduled', 'error' );
			return;
		}

		if ( ! as_has_scheduled_action( 'avvance_reconcile_pending_orders', array(), 'avvance' ) ) {
			as_schedule_recurring_action( time(), HOUR_IN_SECONDS, 'avvance_reconcile_pending_orders', array(), 'avvance' );
			avvance_log( 'Scheduled avvance_reconcile_pending_orders via Action Scheduler' );
		}
	}

	/**
	 * Show cart resume banner
	 */
	public static function cart_resume_banner() {
		if ( ! WC()->session ) {
			return;
		}

		$order_id = WC()->session->get( 'avvance_pending_order_id' );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->needs_payment() || 'avvance' !== $order->get_payment_method() ) {
			WC()->session->__unset( 'avvance_pending_order_id' );
			return;
		}

		$url = $order->get_meta( '_avvance_consumer_url' );
		if ( ! $url ) {
			return;
		}

		// Check if expired.
		if ( avvance_is_url_expired( $order_id ) ) {
			echo '<div class="woocommerce-info">';
			echo esc_html__( 'Your previous U.S. Bank Avvance application has expired. Please complete checkout to create a new application.', 'avvance-for-woocommerce' );
			echo '</div>';
			return;
		}

		?>
		<div class="woocommerce-info avvance-resume-banner">
			<p>
				<?php esc_html_e( 'You have a pending U.S. Bank Avvance application for this order.', 'avvance-for-woocommerce' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" class="button">
					<?php esc_html_e( 'Resume U.S. Bank Avvance Application', 'avvance-for-woocommerce' ); ?>
				</a>
				<button type="button" class="button" id="avvance-check-status-cart">
					<?php esc_html_e( 'Check U.S. Bank Avvance Application Status', 'avvance-for-woocommerce' ); ?>
				</button>
			</p>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#avvance-check-status-cart').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Checking...', 'avvance-for-woocommerce' ) ); ?>');

				$.ajax({
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					type: 'POST',
					data: {
						action: 'avvance_manual_status_check',
						order_id: <?php echo absint( $order_id ); ?>,
						nonce: '<?php echo esc_attr( wp_create_nonce( 'avvance_manual_check_' . $order_id ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							if (response.data.redirect) {
								window.location = response.data.redirect;
							} else {
								alert('<?php echo esc_js( __( 'Your application is still pending. Please complete it in the U.S. Bank Avvance window.', 'avvance-for-woocommerce' ) ); ?>');
								$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Check U.S. Bank Avvance Application Status', 'avvance-for-woocommerce' ) ); ?>');
							}
						} else {
							alert(response.data.message || '<?php echo esc_js( __( 'Unable to check status. Please try again.', 'avvance-for-woocommerce' ) ); ?>');
							$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Check U.S. Bank Avvance Application Status', 'avvance-for-woocommerce' ) ); ?>');
						}
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX: Manual status check
	 */
	public static function ajax_manual_status_check() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		avvance_log( 'Manual status check initiated for order: ' . $order_id );

		if ( ! wp_verify_nonce( $nonce, 'avvance_manual_check_' . $order_id ) ) {
			avvance_log( 'Manual status check failed: invalid nonce for order ' . $order_id, 'error' );
			wp_send_json_error( array( 'message' => __( 'Invalid security token', 'avvance-for-woocommerce' ) ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			avvance_log( 'Manual status check failed: order not found ' . $order_id, 'error' );
			wp_send_json_error( array( 'message' => __( 'Order not found', 'avvance-for-woocommerce' ) ) );
		}

		$result = self::reconcile_order( $order );

		switch ( $result['status'] ) {
			case 'already_paid':
			case 'paid':
			case 'refunded':
				wp_send_json_success( array( 'redirect' => $result['redirect'] ) );
				break;

			case 'not_yet_authorized':
			case 'pending':
				wp_send_json_success(
					array(
						'pending' => true,
						'status'  => $result['loan_status'] ?? 'not_yet_authorized',
						'message' => $result['message'],
					)
				);
				break;

			default: // missing_session, error, voided.
				wp_send_json_error( array( 'message' => $result['message'] ) );
				break;
		}
	}

	/**
	 * Check an order's live loan status via the Avvance API and reconcile the
	 * order accordingly (mark paid, cancel if voided, or leave pending).
	 * Shared by the manual "Check Avvance Status" AJAX action and the
	 * automated hourly reconciliation job — webhooks are best-effort, not
	 * guaranteed delivery, so both a user-triggered and an automatic path
	 * need to be able to fall back on this same live status check.
	 *
	 * @param WC_Order $order Order to reconcile.
	 * @return array {
	 *     @type string      $status      One of: already_paid, missing_session, error,
	 *                                    not_yet_authorized, paid, voided, refunded, pending.
	 *     @type string|null $message     Human-readable message, where applicable.
	 *     @type string|null $redirect    Order-received URL, where applicable.
	 *     @type string|null $loan_status Raw Avvance loan status, if the API call succeeded.
	 * }
	 */
	private static function reconcile_order( WC_Order $order ) {
		$order_id = $order->get_id();

		if ( $order->is_paid() ) {
			avvance_log( 'Reconcile: order ' . $order_id . ' already paid' );
			return array(
				'status'   => 'already_paid',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		$partner_session_id = $order->get_meta( '_avvance_partner_session_id' );

		if ( empty( $partner_session_id ) ) {
			avvance_log( 'Reconcile: missing partnerSessionId on order #' . $order_id, 'error' );
			return array(
				'status'  => 'missing_session',
				'message' => __( 'Application ID not found.', 'avvance-for-woocommerce' ),
			);
		}

		$gateway = avvance_get_gateway();
		if ( ! $gateway ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Payment gateway not available.', 'avvance-for-woocommerce' ),
			);
		}

		$api = new Avvance_Loan_Status_API(
			array(
				'client_key'    => $gateway->get_option( 'client_key' ),
				'client_secret' => $gateway->get_option( 'client_secret' ),
				'merchant_id'   => $gateway->get_option( 'merchant_id' ),
				'partner_id'    => $gateway->get_option( 'partner_id' ),
				'environment'   => $gateway->get_option( 'environment' ),
			)
		);

		avvance_log( 'Reconcile: calling loan-status for order #' . $order_id );

		$status = $api->get_loan_status( $partner_session_id );

		if ( is_wp_error( $status ) ) {
			if ( 'loan_not_authorized' === $status->get_error_code() ) {
				avvance_log( 'Reconcile: loan not yet authorized for order #' . $order_id );
				return array(
					'status'  => 'not_yet_authorized',
					'message' => __( 'Your application is still being processed.', 'avvance-for-woocommerce' ),
				);
			}
			avvance_log( 'Reconcile API error: ' . $status->get_error_message(), 'error' );
			return array(
				'status'  => 'error',
				'message' => __( 'Unable to check status. Please try again.', 'avvance-for-woocommerce' ),
			);
		}

		avvance_log( 'Reconcile: loan status is ' . $status . ' for order #' . $order_id );

		switch ( $status ) {
			case 'AUTHORIZED':
			case 'SETTLED':
				$order->payment_complete();
				$order->add_order_note(
					sprintf(
						/* translators: %s: loan status string */
						__( 'Payment confirmed via status reconciliation. Loan status: %s', 'avvance-for-woocommerce' ),
						$status
					)
				);
				return array(
					'status'      => 'paid',
					'redirect'    => $order->get_checkout_order_received_url(),
					'loan_status' => $status,
				);

			case 'VOIDED':
				$order->update_status(
					'cancelled',
					__( 'Loan voided - confirmed via status reconciliation.', 'avvance-for-woocommerce' )
				);
				return array(
					'status'      => 'voided',
					'message'     => __( 'Your financing application was voided. Please return to cart and select a different payment method.', 'avvance-for-woocommerce' ),
					'loan_status' => $status,
				);

			case 'REFUNDED':
			case 'REFUND_IN_PROGRESS':
				return array(
					'status'      => 'refunded',
					'redirect'    => $order->get_checkout_order_received_url(),
					'loan_status' => $status,
				);

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: loan status string */
						__( 'Status reconciliation: loan status is %s - still pending.', 'avvance-for-woocommerce' ),
						$status
					)
				);
				return array(
					'status'      => 'pending',
					'message'     => __( 'Your application is still being processed.', 'avvance-for-woocommerce' ),
					'loan_status' => $status,
				);
		}
	}

	/**
	 * Hourly Action Scheduler job: re-check loan status for aging pending
	 * Avvance orders as a safety net for webhooks that never arrived, or
	 * arrived but couldn't be fully processed. Orders without a partner
	 * session ID are skipped (nothing to check against the API) and left for
	 * the daily cleanup job to eventually cancel once their link expires.
	 */
	public static function reconcile_pending_orders() {
		avvance_log( 'Running hourly reconciliation of pending Avvance orders' );

		$orders = wc_get_orders(
			array(
				'limit'          => -1,
				'status'         => 'pending',
				'payment_method' => 'avvance',
				'date_created'   => '<' . ( time() - HOUR_IN_SECONDS ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_avvance_partner_session_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$resolved_count = 0;
		$pending_count  = 0;

		foreach ( $orders as $order ) {
			$result = self::reconcile_order( $order );

			if ( in_array( $result['status'], array( 'paid', 'voided', 'refunded', 'already_paid' ), true ) ) {
				++$resolved_count;
			} else {
				++$pending_count;
			}
		}

		avvance_log( "Reconciliation complete: {$resolved_count} resolved, {$pending_count} still pending" );
	}

	/**
	 * Daily cleanup of expired URLs
	 */
	public static function cleanup_expired_urls() {
		avvance_log( 'Running daily cleanup of expired Avvance URLs' );

		$orders = wc_get_orders(
			array(
				'limit'          => -1,
				'status'         => 'pending',
				'payment_method' => 'avvance',
				'return'         => 'ids',
			)
		);

		$cancelled_count = 0;

		foreach ( $orders as $order_id ) {
			if ( avvance_is_url_expired( $order_id ) ) {
				$order = wc_get_order( $order_id );
				if ( $order && ! $order->is_paid() ) {
					$order->update_status( 'cancelled', __( 'Avvance application link expired (30 days)', 'avvance-for-woocommerce' ) );
					++$cancelled_count;
				}
			}
		}

		if ( $cancelled_count > 0 ) {
			avvance_log( "Cancelled {$cancelled_count} orders with expired Avvance URLs" );
		}
	}

	/**
	 * Add Avvance meta box to order edit page
	 */
	public static function add_order_meta_box() {
		$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'avvance_order_details',
			esc_html__( 'Avvance Payment Details', 'avvance-for-woocommerce' ),
			array( __CLASS__, 'render_order_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render order meta box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 */
	public static function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;

		if ( ! $order || 'avvance' !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'Not an Avvance order', 'avvance-for-woocommerce' ) . '</p>';
			return;
		}

		$application_guid       = $order->get_meta( '_avvance_application_guid' );
		$partner_session_id     = $order->get_meta( '_avvance_partner_session_id' );
		$last_status            = $order->get_meta( '_avvance_last_webhook_status' );
		$payment_transaction_id = $order->get_meta( '_avvance_payment_transaction_id' );
		$approval_code          = $order->get_meta( '_avvance_approval_code' );

		?>
		<div class="avvance-order-details">
			<?php if ( $application_guid ) : ?>
				<p><strong><?php esc_html_e( 'Application ID:', 'avvance-for-woocommerce' ); ?></strong><br>
				<code><?php echo esc_html( $application_guid ); ?></code></p>
			<?php endif; ?>

			<?php if ( $partner_session_id ) : ?>
				<p><strong><?php esc_html_e( 'Session ID:', 'avvance-for-woocommerce' ); ?></strong><br>
				<code><?php echo esc_html( $partner_session_id ); ?></code></p>
			<?php endif; ?>

			<?php if ( $last_status ) : ?>
				<p><strong><?php esc_html_e( 'Last Status:', 'avvance-for-woocommerce' ); ?></strong><br>
				<?php echo esc_html( avvance_get_status_message( $last_status ) ); ?></p>
			<?php endif; ?>

			<?php if ( $payment_transaction_id ) : ?>
				<p><strong><?php esc_html_e( 'Transaction ID:', 'avvance-for-woocommerce' ); ?></strong><br>
				<code><?php echo esc_html( $payment_transaction_id ); ?></code></p>
			<?php endif; ?>

			<?php if ( $approval_code ) : ?>
				<p><strong><?php esc_html_e( 'Approval Code:', 'avvance-for-woocommerce' ); ?></strong><br>
				<code><?php echo esc_html( $approval_code ); ?></code></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
