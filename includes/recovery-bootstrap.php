<?php
/**
 * Temporary MU-plugin bootstrap for interrupted Clone Master restores.
 *
 * This file is copied, never generated, and is removed after a successful
 * restore or rollback. It intentionally loads only the recovery classes.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_PLUGIN_DIR' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
    return;
}

if ( class_exists( 'WPCM_Recovery', false ) ) {
    WPCM_Recovery::maybe_recover();
    return;
}

$wpcm_recovery_roots = glob( trailingslashit( WP_PLUGIN_DIR ) . '*/includes/class-recovery.php' ) ?: array();
foreach ( $wpcm_recovery_roots as $wpcm_recovery_file ) {
    $wpcm_root = trailingslashit( dirname( dirname( $wpcm_recovery_file ) ) );
    if ( ! is_file( $wpcm_root . 'clone-master.php' ) || ! is_file( $wpcm_root . 'includes/class-reliability.php' ) ) {
        continue;
    }

    if ( ! defined( 'WPCM_PLUGIN_DIR' ) ) {
        define( 'WPCM_PLUGIN_DIR', $wpcm_root );
    }
    if ( ! defined( 'WPCM_STORAGE_ROOT' ) ) {
        define( 'WPCM_STORAGE_ROOT', trailingslashit( WP_CONTENT_DIR ) );
    }
    if ( ! defined( 'WPCM_TEMP_DIR' ) ) {
        define( 'WPCM_TEMP_DIR', WPCM_STORAGE_ROOT . 'wpcm-temp/' );
    }

    require_once $wpcm_root . 'includes/class-reliability.php';
    require_once $wpcm_recovery_file;
    if ( class_exists( 'WPCM_Recovery', false ) ) {
        WPCM_Recovery::maybe_recover();
    }
    break;
}

unset( $wpcm_recovery_roots, $wpcm_recovery_file, $wpcm_root );
