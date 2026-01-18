<?php
/**
 * Helper functions
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get Avvance gateway instance
 */
function avvance_get_gateway() {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return null;
    }
    
    $gateways = WC()->payment_gateways()->payment_gateways();
    return isset($gateways['avvance']) ? $gateways['avvance'] : null;
}

/**
 * Log debug message
 */
function avvance_log($message, $level = 'info') {
    $gateway = avvance_get_gateway();
    if (!$gateway || $gateway->get_option('debug_mode') !== 'yes') {
        return;
    }
    
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $context = ['source' => 'avvance'];
        
        switch ($level) {
            case 'error':
                $logger->error($message, $context);
                break;
            case 'warning':
                $logger->warning($message, $context);
                break;
            default:
                $logger->info($message, $context);
        }
    }
}

/**
 * Generate webhook credentials
 */
function avvance_generate_webhook_credentials() {
    return [
        'username' => 'avvance_' . substr(md5(wp_generate_uuid4()), 0, 16),
        'password' => wp_generate_password(32, true, true)
    ];
}

/**
 * Check if order is Avvance order
 */
function avvance_is_avvance_order($order) {
    if (is_numeric($order)) {
        $order = wc_get_order($order);
    }
    
    return $order && $order->get_payment_method() === 'avvance';
}

/**
 * Get order's Avvance application URL
 */
function avvance_get_order_url($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return null;
    }
    
    return $order->get_meta('_avvance_consumer_url');
}

/**
 * Check if Avvance URL is expired (30 days)
 */
function avvance_is_url_expired($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return true;
    }
    
    $created = $order->get_meta('_avvance_url_created_at');
    if (!$created) {
        return true;
    }
    
    // 30 days = 2592000 seconds
    return (time() - $created) > 2592000;
}

/**
 * Get friendly status message for merchant
 */
function avvance_get_status_message($status) {
    $messages = [
        'APPLICATION_STARTED' => __('Customer started application', 'avvance-for-woocommerce'),
        'APPLICATION_APPROVED' => __('Application approved - awaiting customer', 'avvance-for-woocommerce'),
        'APPLICATION_PENDING_REQUIRE_CUSTOMER_ACTION' => __('Customer action required', 'avvance-for-woocommerce'),
        'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT' => __('Application declined', 'avvance-for-woocommerce'),
        'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT' => __('System error - use alternate payment', 'avvance-for-woocommerce'),
        'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED' => __('Payment authorized', 'avvance-for-woocommerce'),
        'INVOICE_PAYMENT_TRANSACTION_SETTLED' => __('Payment settled', 'avvance-for-woocommerce'),
        'APPLICATION_LINK_EXPIRED' => __('Application link expired', 'avvance-for-woocommerce'),
    ];
    
    return isset($messages[$status]) ? $messages[$status] : $status;
}
