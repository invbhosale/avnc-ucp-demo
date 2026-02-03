<?php
/**
 * Avvance for WooCommerce Uninstall
 *
 * Cleans up plugin data when uninstalled via WordPress admin.
 * This file is called automatically by WordPress when the plugin is deleted.
 *
 * @package Avvance_For_WooCommerce
 * @since 1.1.0
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up plugin data
 *
 * Only runs when plugin is fully deleted (not just deactivated).
 * Removes:
 * - Database tables (avvance_preapprovals)
 * - Transients (cached tokens)
 * - Plugin options (gateway settings)
 */
function avvance_uninstall_cleanup() {
    global $wpdb;

    // Only clean up if user has confirmed (check for option)
    // Some merchants may want to keep data for audit purposes
    $delete_data = get_option('avvance_delete_data_on_uninstall', false);

    if (!$delete_data) {
        // Just clean up transients, keep order data
        avvance_cleanup_transients();
        return;
    }

    // Drop custom tables
    $table_name = $wpdb->prefix . 'avvance_preapprovals';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

    // Clean up transients
    avvance_cleanup_transients();

    // Remove plugin options
    delete_option('woocommerce_avvance_settings');
    delete_option('avvance_db_version');
    delete_option('avvance_delete_data_on_uninstall');

    // Clean up order meta (optional - kept for order history)
    // Uncomment if you want to remove all Avvance order data:
    // $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_avvance_%'");
    // $wpdb->query("DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '_avvance_%'");
}

/**
 * Clean up transients
 */
function avvance_cleanup_transients() {
    global $wpdb;

    // Delete all Avvance token transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_avvance_token_%' OR option_name LIKE '_transient_timeout_avvance_token_%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    // Delete price breakdown cache transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_avvance_price_%' OR option_name LIKE '_transient_timeout_avvance_price_%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Run cleanup
avvance_uninstall_cleanup();
