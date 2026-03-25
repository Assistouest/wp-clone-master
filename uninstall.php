<?php
/**
 * Uninstall Clone Master
 *
 * Fired when the user clicks "Delete" in the Plugins screen.
 * Removes all options stored by the plugin in the database.
 *
 * @package Clone_Master
 */

// Security: only run when WordPress triggers an uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Database options ──────────────────────────────────────────────────────────
delete_option( 'wpcm_schedule_settings' );
delete_option( 'wpcm_backup_history' );
delete_option( 'wpcm_version' );
delete_option( 'wpcm_noindex' );
delete_option( 'wpcm_deactivated_plugins' );

// ── Filesystem cleanup ────────────────────────────────────────────────────────
//
// wpcm-temp/  — temporary session directories created during export and import.
//               Always safe to delete: these are transient working files that
//               should not exist outside an active operation.
//
// wpcm-logs/  — rotation logs written by the scheduler. No user-facing value
//               once the plugin is removed.
//
// wpcm-backups/ is intentionally NOT deleted here.
// Backup archives represent critical, potentially irreplaceable copies of the
// user's site data. Silently removing them on plugin deletion would be
// destructive and unexpected. Users who wish to free the disk space must delete
// wp-content/wpcm-backups/ manually.
//
foreach ( array( WP_CONTENT_DIR . '/wpcm-temp/', WP_CONTENT_DIR . '/wpcm-logs/' ) as $wpcm_dir ) {
    if ( ! is_dir( $wpcm_dir ) ) {
        continue;
    }
    // Recursive delete — mirrors the logic in WPCM_Plugin::recursive_delete().
    $wpcm_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $wpcm_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $wpcm_iterator as $wpcm_item ) {
        if ( $wpcm_item->isDir() ) {
            rmdir( $wpcm_item->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem unavailable in uninstall context
        } else {
            wp_delete_file( $wpcm_item->getRealPath() );
        }
    }
    rmdir( $wpcm_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem unavailable in uninstall context
}

// Multisite: remove options from every sub-site.
if ( is_multisite() ) {
    foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $wpcm_site_id ) {
        switch_to_blog( $wpcm_site_id );
        delete_option( 'wpcm_schedule_settings' );
        delete_option( 'wpcm_backup_history' );
        delete_option( 'wpcm_version' );
        delete_option( 'wpcm_noindex' );
        delete_option( 'wpcm_deactivated_plugins' );
        restore_current_blog();
    }
}

// ── Scheduled events ─────────────────────────────────────────────────────────
$wpcm_timestamp = wp_next_scheduled( 'wpcm_scheduled_backup' );
if ( $wpcm_timestamp ) {
    wp_unschedule_event( $wpcm_timestamp, 'wpcm_scheduled_backup' );
}
wp_clear_scheduled_hook( 'wpcm_scheduled_backup' );
