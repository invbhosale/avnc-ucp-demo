<?php
/**
 * Avvance Order Handler
 *
 * Manages order lifecycle, cart resume, and cleanup.
 *
 * @package Avvance_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Order_Handler {

    public static function init() {
        // Cart resume banner
        add_action('woocommerce_before_cart', [__CLASS__, 'cart_resume_banner']);
        add_action('woocommerce_before_checkout_form', [__CLASS__, 'cart_resume_banner']);

        // Manual status check AJAX
        add_action('wp_ajax_avvance_manual_status_check', [__CLASS__, 'ajax_manual_status_check']);
        add_action('wp_ajax_nopriv_avvance_manual_status_check', [__CLASS__, 'ajax_manual_status_check']);

        // Cleanup expired URLs (daily)
        add_action('avvance_daily_cleanup', [__CLASS__, 'cleanup_expired_urls']);
        if (!wp_next_scheduled('avvance_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'avvance_daily_cleanup');
        }

        // Admin order meta box
        add_action('add_meta_boxes', [__CLASS__, 'add_order_meta_box']);
    }

    /**
     * Show cart resume banner
     */
    public static function cart_resume_banner() {
        if (!WC()->session) {
            return;
        }

        $order_id = WC()->session->get('avvance_pending_order_id');
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->needs_payment() || 'avvance' !== $order->get_payment_method()) {
            WC()->session->__unset('avvance_pending_order_id');
            return;
        }

        $url = $order->get_meta('_avvance_consumer_url');
        if (!$url) {
            return;
        }

        // Check if expired
        if (avvance_is_url_expired($order_id)) {
            echo '<div class="woocommerce-info">';
            echo esc_html__('Your previous Avvance application has expired. Please complete checkout to create a new application.', 'avvance-for-woocommerce');
            echo '</div>';
            return;
        }

        ?>
        <div class="woocommerce-info avvance-resume-banner">
            <p>
                <?php esc_html_e('You have a pending Avvance application for this order.', 'avvance-for-woocommerce'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($url); ?>" target="_blank" class="button">
                    <?php esc_html_e('Resume Avvance Application', 'avvance-for-woocommerce'); ?>
                </a>
                <button type="button" class="button" id="avvance-check-status-cart">
                    <?php esc_html_e('Check Application Status', 'avvance-for-woocommerce'); ?>
                </button>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#avvance-check-status-cart').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Checking...', 'avvance-for-woocommerce')); ?>');

                $.ajax({
                    url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                    type: 'POST',
                    data: {
                        action: 'avvance_manual_status_check',
                        order_id: <?php echo absint($order_id); ?>,
                        nonce: '<?php echo esc_attr(wp_create_nonce('avvance_manual_check_' . $order_id)); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.redirect) {
                                window.location = response.data.redirect;
                            } else {
                                alert('<?php echo esc_js(__('Your application is still pending. Please complete it in the Avvance window.', 'avvance-for-woocommerce')); ?>');
                                $btn.prop('disabled', false).text('<?php echo esc_js(__('Check Application Status', 'avvance-for-woocommerce')); ?>');
                            }
                        } else {
                            alert(response.data.message || '<?php echo esc_js(__('Unable to check status. Please try again.', 'avvance-for-woocommerce')); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Check Application Status', 'avvance-for-woocommerce')); ?>');
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
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        avvance_log('Manual status check initiated for order: ' . $order_id);

        if (!wp_verify_nonce($nonce, 'avvance_manual_check_' . $order_id)) {
            avvance_log('Manual status check failed: invalid nonce for order ' . $order_id, 'error');
            wp_send_json_error(['message' => __('Invalid security token', 'avvance-for-woocommerce')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            avvance_log('Manual status check failed: order not found ' . $order_id, 'error');
            wp_send_json_error(['message' => __('Order not found', 'avvance-for-woocommerce')]);
        }

        // If already paid, redirect to order received
        if ($order->is_paid()) {
            avvance_log('Manual status check: order ' . $order_id . ' already paid, redirecting');
            wp_send_json_success(['redirect' => $order->get_checkout_order_received_url()]);
        }

        // Call notification status API
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            avvance_log('Manual status check failed: gateway not available', 'error');
            wp_send_json_error(['message' => __('Gateway not available', 'avvance-for-woocommerce')]);
        }

        $partner_session_id = $order->get_meta('_avvance_partner_session_id');
        $application_guid = $order->get_meta('_avvance_application_guid');
        avvance_log('Manual status check: partner_session_id = ' . ($partner_session_id ?: '(empty)') . ', application_guid = ' . ($application_guid ?: '(empty)') . ', order_id = ' . $order_id);

        if (!$partner_session_id) {
            avvance_log('Manual status check failed: no partner session ID on order ' . $order_id, 'error');
            wp_send_json_error(['message' => __('Application ID not found', 'avvance-for-woocommerce')]);
        }

        $environment = $gateway->get_option('environment');
        avvance_log('Manual status check: creating API client with environment=' . $environment . ', merchant_id=' . $gateway->get_option('merchant_id'));

        $api = new Avvance_API_Client([
            'client_key' => $gateway->get_option('client_key'),
            'client_secret' => $gateway->get_option('client_secret'),
            'merchant_id' => $gateway->get_option('merchant_id'),
            'environment' => $environment
        ]);

        $response = $api->get_notification_status($partner_session_id);

        if (is_wp_error($response)) {
            avvance_log('Manual status check API error: ' . $response->get_error_code() . ' - ' . $response->get_error_message(), 'error');
            wp_send_json_error(['message' => __('Unable to check status. Please try again.', 'avvance-for-woocommerce')]);
        }

        avvance_log('Manual status check API response: ' . wp_json_encode($response));

        // Process the status manually (same logic as webhook)
        $status = $response['eventDetails']['loanStatus']['status'] ?? '';

        if ('INVOICE_PAYMENT_TRANSACTION_AUTHORIZED' === $status) {
            // Mark order as paid
            $payment_transaction_id = $response['eventDetails']['paymentTransactionId'] ?? '';
            $order->payment_complete($payment_transaction_id);
            $order->add_order_note(__('Payment completed via manual status check', 'avvance-for-woocommerce'));

            wp_send_json_success(['redirect' => $order->get_checkout_order_received_url()]);
        } elseif (in_array($status, ['APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT', 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT'], true)) {
            // Declined
            $order->update_status('cancelled', __('Application declined (manual check)', 'avvance-for-woocommerce'));
            wp_send_json_error(['message' => __('Your application was declined. Please use another payment method.', 'avvance-for-woocommerce')]);
        } else {
            // Still pending
            /* translators: %s: Avvance application status message */
            $order->add_order_note(sprintf(__('Manual status check: %s', 'avvance-for-woocommerce'), avvance_get_status_message($status)));
            wp_send_json_success(['pending' => true, 'status' => $status]);
        }
    }

    /**
     * Daily cleanup of expired URLs
     */
    public static function cleanup_expired_urls() {
        avvance_log('Running daily cleanup of expired Avvance URLs');

        $orders = wc_get_orders([
            'limit' => -1,
            'status' => 'pending',
            'payment_method' => 'avvance',
            'return' => 'ids'
        ]);

        $cancelled_count = 0;

        foreach ($orders as $order_id) {
            if (avvance_is_url_expired($order_id)) {
                $order = wc_get_order($order_id);
                if ($order && !$order->is_paid()) {
                    $order->update_status('cancelled', __('Avvance application link expired (30 days)', 'avvance-for-woocommerce'));
                    $cancelled_count++;
                }
            }
        }

        if ($cancelled_count > 0) {
            avvance_log("Cancelled {$cancelled_count} orders with expired Avvance URLs");
        }
    }

    /**
     * Add Avvance meta box to order edit page
     */
    public static function add_order_meta_box() {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'avvance_order_details',
            esc_html__('Avvance Payment Details', 'avvance-for-woocommerce'),
            [__CLASS__, 'render_order_meta_box'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render order meta box
     */
    public static function render_order_meta_box($post_or_order) {
        $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;

        if (!$order || 'avvance' !== $order->get_payment_method()) {
            echo '<p>' . esc_html__('Not an Avvance order', 'avvance-for-woocommerce') . '</p>';
            return;
        }

        $application_guid = $order->get_meta('_avvance_application_guid');
        $partner_session_id = $order->get_meta('_avvance_partner_session_id');
        $last_status = $order->get_meta('_avvance_last_webhook_status');
        $payment_transaction_id = $order->get_meta('_avvance_payment_transaction_id');
        $approval_code = $order->get_meta('_avvance_approval_code');

        ?>
        <div class="avvance-order-details">
            <?php if ($application_guid) : ?>
                <p><strong><?php esc_html_e('Application ID:', 'avvance-for-woocommerce'); ?></strong><br>
                <code><?php echo esc_html($application_guid); ?></code></p>
            <?php endif; ?>

            <?php if ($partner_session_id) : ?>
                <p><strong><?php esc_html_e('Session ID:', 'avvance-for-woocommerce'); ?></strong><br>
                <code><?php echo esc_html($partner_session_id); ?></code></p>
            <?php endif; ?>

            <?php if ($last_status) : ?>
                <p><strong><?php esc_html_e('Last Status:', 'avvance-for-woocommerce'); ?></strong><br>
                <?php echo esc_html(avvance_get_status_message($last_status)); ?></p>
            <?php endif; ?>

            <?php if ($payment_transaction_id) : ?>
                <p><strong><?php esc_html_e('Transaction ID:', 'avvance-for-woocommerce'); ?></strong><br>
                <code><?php echo esc_html($payment_transaction_id); ?></code></p>
            <?php endif; ?>

            <?php if ($approval_code) : ?>
                <p><strong><?php esc_html_e('Approval Code:', 'avvance-for-woocommerce'); ?></strong><br>
                <code><?php echo esc_html($approval_code); ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
