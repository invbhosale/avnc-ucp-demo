<?php
/**
 * Avvance Pre-Approval Database Migration
 * 
 * Run this ONCE to update existing installations with the browser_fingerprint column.
 * 
 * OPTIONS:
 * 1. Add to plugin activation hook
 * 2. Run manually via WP-CLI
 * 3. Add as admin notice with "Run Migration" button
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_PreApproval_Migration {
    
    /**
     * Run migration
     */
    public static function migrate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';
        
        avvance_log('=== STARTING PRE-APPROVAL MIGRATION ===');
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        if (!$table_exists) {
            avvance_log('Table does not exist yet, creating new table');
            Avvance_PreApproval_Handler::create_preapproval_table();
            avvance_log('Migration complete - new table created');
            return ['status' => 'success', 'message' => 'New table created'];
        }
        
        avvance_log('Table exists, checking for browser_fingerprint column');
        
        // Check if browser_fingerprint column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'browser_fingerprint'");
        
        if (!empty($column_exists)) {
            avvance_log('browser_fingerprint column already exists, no migration needed');
            return ['status' => 'success', 'message' => 'Already migrated'];
        }
        
        avvance_log('Adding browser_fingerprint column');
        
        // Add browser_fingerprint column
        $wpdb->query("
            ALTER TABLE {$table_name} 
            ADD COLUMN browser_fingerprint varchar(255) NOT NULL DEFAULT '' AFTER session_id
        ");
        
        // Add index for browser_fingerprint
        $wpdb->query("
            ALTER TABLE {$table_name} 
            ADD INDEX browser_fingerprint (browser_fingerprint)
        ");
        
        // Add index for created_at if not exists
        $wpdb->query("
            ALTER TABLE {$table_name} 
            ADD INDEX idx_created_at (created_at)
        ");
        
        avvance_log('Columns and indexes added');
        
        // Backfill browser_fingerprint for existing records
        // Strategy: Generate unique fingerprints for each existing record
        $existing_records = $wpdb->get_results("
            SELECT id, session_id 
            FROM {$table_name} 
            WHERE browser_fingerprint = ''
        ");
        
        $backfilled = 0;
        
        foreach ($existing_records as $record) {
            // Generate fingerprint from session_id for consistency
            $fingerprint = 'avv_fp_' . md5($record->session_id);
            
            $wpdb->update(
                $table_name,
                ['browser_fingerprint' => $fingerprint],
                ['id' => $record->id],
                ['%s'],
                ['%d']
            );
            
            $backfilled++;
        }
        
        avvance_log("Backfilled {$backfilled} existing records with browser fingerprints");
        
        // Verify migration
        $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $records_with_fingerprint = $wpdb->get_var("
            SELECT COUNT(*) FROM {$table_name} 
            WHERE browser_fingerprint != ''
        ");
        
        avvance_log("Migration verification:");
        avvance_log("  Total records: {$total_records}");
        avvance_log("  Records with fingerprint: {$records_with_fingerprint}");
        
        if ($total_records == $records_with_fingerprint) {
            avvance_log('✅ Migration completed successfully');
            return [
                'status' => 'success',
                'message' => "Migration complete. Backfilled {$backfilled} records.",
                'total_records' => $total_records
            ];
        } else {
            avvance_log('⚠️ Migration incomplete - some records missing fingerprints', 'warning');
            return [
                'status' => 'warning',
                'message' => 'Migration partially complete. Some records may be missing fingerprints.',
                'total_records' => $total_records,
                'with_fingerprint' => $records_with_fingerprint
            ];
        }
    }
    
    /**
     * Check if migration is needed
     */
    public static function needs_migration() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        if (!$table_exists) {
            return false; // New installation
        }
        
        // Check if browser_fingerprint column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'browser_fingerprint'");
        
        return empty($column_exists);
    }
}

// ============================================
// USAGE EXAMPLES
// ============================================

/**
 * OPTION 1: Add to plugin activation hook
 * 
 * Add this to your main plugin file:
 */
/*
register_activation_hook(AVVANCE_PLUGIN_FILE, function() {
    require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-handler.php';
    require_once AVVANCE_PLUGIN_PATH . 'includes/avvance-preapproval-migration.php';
    
    Avvance_PreApproval_Handler::create_preapproval_table();
    
    if (Avvance_PreApproval_Migration::needs_migration()) {
        $result = Avvance_PreApproval_Migration::migrate();
        error_log('Avvance migration result: ' . print_r($result, true));
    }
});
*/

/**
 * OPTION 2: WP-CLI command
 * 
 * wp eval-file avvance-preapproval-migration.php
 * 
 * Or create a WP-CLI command:
 */
/*
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('avvance migrate-preapprovals', function() {
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-handler.php';
        
        WP_CLI::line('Starting Avvance pre-approval migration...');
        
        if (!Avvance_PreApproval_Migration::needs_migration()) {
            WP_CLI::success('No migration needed. Database is already up to date.');
            return;
        }
        
        $result = Avvance_PreApproval_Migration::migrate();
        
        if ($result['status'] === 'success') {
            WP_CLI::success($result['message']);
        } else {
            WP_CLI::warning($result['message']);
        }
    });
}
*/

/**
 * OPTION 3: Admin notice with manual trigger
 * 
 * Add this to your main plugin class:
 */
/*
add_action('admin_notices', function() {
    if (!Avvance_PreApproval_Migration::needs_migration()) {
        return;
    }
    
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    
    // Check if migration was just run
    if (isset($_GET['avvance_migrate']) && wp_verify_nonce($_GET['_wpnonce'], 'avvance_migrate')) {
        $result = Avvance_PreApproval_Migration::migrate();
        
        if ($result['status'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Avvance:</strong> ' . esc_html($result['message']) . '</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Avvance:</strong> ' . esc_html($result['message']) . '</p>';
            echo '</div>';
        }
        return;
    }
    
    // Show migration notice
    $migrate_url = wp_nonce_url(
        add_query_arg('avvance_migrate', '1'),
        'avvance_migrate'
    );
    
    echo '<div class="notice notice-info">';
    echo '<p><strong>Avvance:</strong> Database update required for pre-approval feature.</p>';
    echo '<p><a href="' . esc_url($migrate_url) . '" class="button button-primary">Run Migration</a></p>';
    echo '</div>';
});
*/

/**
 * OPTION 4: Automatic migration on plugin load (RECOMMENDED)
 * 
 * Add this to your main plugin init:
 */
/*
add_action('plugins_loaded', function() {
    // Only run once per installation
    $migration_version = get_option('avvance_migration_version', '0');
    
    if (version_compare($migration_version, '1.1.0', '<')) {
        require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-handler.php';
        require_once AVVANCE_PLUGIN_PATH . 'includes/avvance-preapproval-migration.php';
        
        if (Avvance_PreApproval_Migration::needs_migration()) {
            $result = Avvance_PreApproval_Migration::migrate();
            
            if ($result['status'] === 'success') {
                update_option('avvance_migration_version', '1.1.0');
            }
        } else {
            update_option('avvance_migration_version', '1.1.0');
        }
    }
}, 5);
*/
