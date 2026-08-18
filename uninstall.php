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

// Exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
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

	// Always clear scheduled jobs, regardless of the data-retention choice below —
	// an orphaned cron/Action Scheduler entry isn't "data" a merchant would want kept.
	avvance_cleanup_scheduled_jobs();

	// Only clean up if user has confirmed (check for option).
	// Some merchants may want to keep data for audit purposes.
	$delete_data = get_option( 'avvance_delete_data_on_uninstall', false );

	if ( ! $delete_data ) {
		// Just clean up transients, keep order data.
		avvance_cleanup_transients();
		return;
	}

	// Drop custom tables.
	$table_name = $wpdb->prefix . 'avvance_preapprovals';
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

	// Clean up transients.
	avvance_cleanup_transients();

	// Remove plugin options.
	delete_option( 'woocommerce_avvance_settings' );
	delete_option( 'avvance_db_version' );
	delete_option( 'avvance_delete_data_on_uninstall' );

	// Clean up order meta (optional - kept for order history).
	// To also remove all Avvance order metadata, add delete queries for
	// {$wpdb->postmeta} and {$wpdb->prefix}wc_orders_meta here.
}

/**
 * Clear scheduled cron and Action Scheduler jobs
 */
function avvance_cleanup_scheduled_jobs() {
	wp_clear_scheduled_hook( 'avvance_daily_cleanup' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'avvance_reconcile_pending_orders', array(), 'avvance' );
	}
}

/**
 * Clean up transients
 */
function avvance_cleanup_transients() {
	global $wpdb;

	// Delete all Avvance token transients.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup, caching not needed
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_avvance_token_%' OR option_name LIKE '_transient_timeout_avvance_token_%'"
	);

	// Delete price breakdown cache transients.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup, caching not needed
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_avvance_price_%' OR option_name LIKE '_transient_timeout_avvance_price_%'"
	);
}

// Run cleanup.
avvance_uninstall_cleanup();
