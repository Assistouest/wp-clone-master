<?php
/**
 * Plugin Name: Clone Master
 * Plugin URI: https://github.com/Assistouest/clone-master
 * Description: Create resumable WordPress backups and perform staged migrations with strict validation and transactional rollback.
 * Version: 3.1.6
 * Author: Adrien Piron
 * Author URI: https://profiles.wordpress.org/adrienpiron/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clone-master
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WPCM_VERSION' ) ) {
    define( 'WPCM_VERSION', '3.1.6' );
}
if ( ! defined( 'WPCM_PLUGIN_DIR' ) ) {
    define( 'WPCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPCM_PLUGIN_URL' ) ) {
    define( 'WPCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
/**
 * Keep all runtime data in the historical wp-content paths for maximum host
 * compatibility. Direct web access is restricted with server deny files,
 * unpredictable filenames, and authenticated WordPress download handlers.
 */
if ( ! defined( 'WPCM_STORAGE_ROOT' ) ) {
    define( 'WPCM_STORAGE_ROOT', trailingslashit( WP_CONTENT_DIR ) );
}
if ( ! defined( 'WPCM_BACKUP_DIR' ) ) {
    define( 'WPCM_BACKUP_DIR', WPCM_STORAGE_ROOT . 'wpcm-backups/' );
}
if ( ! defined( 'WPCM_TEMP_DIR' ) ) {
    define( 'WPCM_TEMP_DIR', WPCM_STORAGE_ROOT . 'wpcm-temp/' );
}
if ( ! defined( 'WPCM_LOG_DIR' ) ) {
    define( 'WPCM_LOG_DIR', WPCM_STORAGE_ROOT . 'wpcm-logs/' );
}

// Autoload classes
spl_autoload_register( function( $class ) {
    $prefix = 'WPCM_';
    if ( strpos( $class, $prefix ) !== 0 ) return;
    $file = WPCM_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) ) . '.php';
    if ( file_exists( $file ) ) require_once $file;
});

/**
 * Main Plugin Class
 */
if ( ! class_exists( 'WPCM_Plugin' ) ) :

class WPCM_Plugin {

    private static $instance = null;

    /** @var WPCM_Scheduler */
    private $scheduler;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->scheduler = new WPCM_Scheduler();
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        add_action( 'init', [ $this, 'protect_private_ajax_responses' ], 0 );
        add_action( 'init', [ $this, 'init' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_notices', [ $this, 'render_activation_warning' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        // ── Noindex enforcement ────────────────────────────────────────────────
        // wpcm_noindex is set to '1' by the standalone installer when block_indexing
        // is requested. These hooks enforce noindex across every WP request until the
        // admin explicitly saves Settings > Reading (which fires update_option_blog_public).
        //
        // pre_option_blog_public fires BEFORE WordPress reads the cache or the DB →
        // immune to Redis/Memcached/alloptions. Priority PHP_INT_MAX ensures no other
        // plugin can override it.
        add_filter( 'pre_option_blog_public', [ $this, 'wpcm_enforce_noindex_option' ], PHP_INT_MAX );

        // wp_robots (WP 5.7+ Robots API) : adds noindex/nofollow to the HTML meta tag.
        // Belt-and-suspenders: works even if a plugin ignores blog_public.
        add_filter( 'wp_robots', [ $this, 'wpcm_enforce_noindex_robots' ], PHP_INT_MAX );

        // WP < 5.7 fallback: inject meta robots directly in wp_head.
        if ( ! function_exists( 'wp_robots_no_robots' ) ) {
            add_action( 'wp_head', [ $this, 'wpcm_inject_noindex_meta' ], 1 );
        }

        // Auto-clear the flag when admin saves Settings > Reading.
        add_action( 'update_option_blog_public', [ $this, 'wpcm_clear_noindex_flag' ] );

        // Admin notices for noindex and automatic restore recovery.
        add_action( 'admin_notices', [ $this, 'wpcm_noindex_admin_notice' ] );
        add_action( 'admin_notices', [ $this, 'wpcm_recovery_admin_notice' ] );

        // AJAX handlers
        add_action( 'wp_ajax_wpcm_export', [ $this, 'ajax_export' ] );
        add_action( 'wp_ajax_wpcm_import', [ $this, 'ajax_import' ] );
        add_action( 'wp_ajax_wpcm_import_upload', [ $this, 'ajax_import_upload' ] );
        add_action( 'wp_ajax_wpcm_server_info', [ $this, 'ajax_server_info' ] );
        add_action( 'wp_ajax_wpcm_get_backups', [ $this, 'ajax_get_backups' ] );
        add_action( 'wp_ajax_wpcm_delete_backup', [ $this, 'ajax_delete_backup' ] );
        add_action( 'wp_ajax_wpcm_download_backup', [ $this, 'ajax_download_backup' ] );
        add_action( 'wp_ajax_wpcm_debug_get', [ $this, 'ajax_debug_get' ] );
        add_action( 'wp_ajax_wpcm_debug_clear', [ $this, 'ajax_debug_clear' ] );
        add_action( 'wp_ajax_wpcm_debug_download', [ $this, 'ajax_debug_download' ] );
        add_action( 'wp_ajax_wpcm_debug_client', [ $this, 'ajax_debug_client' ] );

        // ── Automatic backup scheduler ────────────────────────────────────────
        // Custom intervals (weekly, monthly)
        add_filter( 'cron_schedules', [ $this->scheduler, 'register_intervals' ] );

        // Recurring trigger and resumable continuation hook.
        add_action( WPCM_Scheduler::CRON_HOOK, [ $this->scheduler, 'run_backup' ] );
        add_action( WPCM_Scheduler::CONTINUE_HOOK, [ $this->scheduler, 'continue_backup' ] );

        // Schedule AJAX handlers
        add_action( 'wp_ajax_wpcm_get_schedule',       [ $this, 'ajax_get_schedule' ] );
        add_action( 'wp_ajax_wpcm_save_schedule',      [ $this, 'ajax_save_schedule' ] );
        add_action( 'wp_ajax_wpcm_run_backup_now',     [ $this, 'ajax_run_backup_now' ] );
        add_action( 'wp_ajax_wpcm_get_backup_status',  [ $this, 'ajax_get_backup_status' ] );
        add_action( 'wp_ajax_wpcm_get_backup_history', [ $this, 'ajax_get_backup_history' ] );
        add_action( 'wp_ajax_wpcm_clear_history',      [ $this, 'ajax_clear_history' ] );
        add_action( 'wp_ajax_wpcm_test_storage',       [ $this, 'ajax_test_storage' ] );
        add_action( 'wp_ajax_wpcm_nc_init_flow',       [ $this, 'ajax_nc_init_flow' ] );
        add_action( 'wp_ajax_wpcm_nc_poll_flow',       [ $this, 'ajax_nc_poll_flow' ] );
        add_action( 'wp_ajax_wpcm_nc_disconnect',      [ $this, 'ajax_nc_disconnect' ] );
    }

    public function activate() {
        foreach ( [ WPCM_BACKUP_DIR, WPCM_TEMP_DIR, WPCM_LOG_DIR ] as $dir ) {
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            self::protect_directory( $dir );
        }
        update_option( 'wpcm_version', WPCM_VERSION );

        // Initialise default schedule settings if not present
        if ( ! get_option( WPCM_Backup_Settings::OPTION_KEY ) ) {
            $s = new WPCM_Backup_Settings();
            $s->save( [] ); // Persist defaults.
        }

        $this->scheduler->reload_settings();
        try {
            $this->scheduler->schedule_backup();
            delete_option( 'wpcm_activation_warning' );
        } catch ( Throwable $error ) {
            update_option(
                'wpcm_activation_warning',
                sprintf(
                    /* translators: %s: scheduling error message. */
                    __( 'Clone Master was activated, but automatic backup scheduling could not be initialized: %s', 'clone-master' ),
                    $error->getMessage()
                ),
                false
            );
        }
    }

    /**
     * Prevent authenticated Clone Master AJAX responses from being cached by
     * reverse proxies, page-cache plugins, or CDN edge rules.
     *
     * This is defense in depth. WordPress normally sends no-cache headers for
     * admin-ajax.php, but some hosting stacks cache GET responses without
     * considering custom authentication or nonce parameters.
     *
     * @return void
     */
    public function protect_private_ajax_responses(): void {
        if ( ! wp_doing_ajax() ) {
            return;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; each handler verifies its nonce.
        if ( 0 !== strpos( $action, 'wpcm_' ) ) {
            return;
        }

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
            define( 'DONOTCACHEOBJECT', true );
        }
        if ( ! defined( 'DONOTCACHEDB' ) ) {
            define( 'DONOTCACHEDB', true );
        }

        if ( ! headers_sent() ) {
            nocache_headers();
            header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
            header( 'CDN-Cache-Control: no-store' );
            header( 'Surrogate-Control: no-store' );
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
            header( 'X-Accel-Expires: 0' );
            header( 'Vary: Cookie, Authorization, X-WPCM-Request-ID', false );
            header( 'X-Content-Type-Options: nosniff' );
        }
    }

    /**
     * Write all static protection files for a sensitive directory.
     *
     * Apache  : .htaccess "Deny from all" blocks direct HTTP access even when
     *           AllowOverride is enabled (the default on most shared hosting).
     * Nginx   : Nginx ignores .htaccess entirely. Protection requires a server-level
     *           `location` block; we cannot write that from PHP. Instead we:
     *           (a) drop an index.php silence file so directory listing is impossible,
     *           (b) write a README.nginx.txt explaining the required server config,
     *           (c) display an administration warning when Nginx is detected.
     * Both    : index.php prevents directory listing as a belt-and-suspenders measure.
     *
     * @param string $dir                Absolute path with trailing slash.
     * @param bool   $write_nginx_readme Whether to write the optional Nginx guidance file.
     */
    public static function protect_directory( string $dir, bool $write_nginx_readme = true ): void {
        // Apache : block direct HTTP access via .htaccess
        $htaccess = $dir . '.htaccess';
        // Apache 2.2 syntax: "Deny from all"
        // Apache 2.4 syntax: "Require all denied"
        // Writing both ensures compatibility across all commonly deployed versions.
        WPCM_Reliability::atomic_write( $htaccess, implode( "\n", [
            '# Apache 2.4+',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '# Apache 2.2 fallback',
            '<IfModule !mod_authz_core.c>',
            '    Deny from all',
            '</IfModule>',
            '',
        ] ) );

        // Silence / directory-listing guard (works on all servers).
        WPCM_Reliability::atomic_write( $dir . 'index.php', "<?php // Silence is golden.\n", 0644 );

        // Nginx reminder : PHP cannot change the virtual-host configuration.
        // Temporary operation directories deliberately omit this documentation
        // file so it can never collide with a legacy archive entry carrying the
        // same generated filename. The stable runtime roots still receive it.
        $nginx_readme = $dir . 'README.nginx.txt';
        if ( ! $write_nginx_readme ) {
            if ( is_file( $nginx_readme ) && ! is_link( $nginx_readme ) ) {
                @unlink( $nginx_readme );
            }
            return;
        }

        $content_path = (string) wp_parse_url( content_url( '/' ), PHP_URL_PATH );
        $content_root = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
        $runtime_root = rtrim( str_replace( '\\', '/', $dir ), '/' );
        $relative_dir = 0 === strpos( $runtime_root, $content_root . '/' )
            ? ltrim( substr( $runtime_root, strlen( $content_root ) ), '/' )
            : basename( $runtime_root );
        $public_path = '/' . trim( trailingslashit( $content_path ) . $relative_dir, '/' ) . '/';

        WPCM_Reliability::atomic_write( $nginx_readme, implode( "\n", [
            'Clone Master : optional Nginx hardening',
            '=========================================',
            'Nginx ignores .htaccess files. Add the following block to the',
            'matching server { } configuration to deny direct HTTP access:',
            '',
            '    location ^~ ' . $public_path . ' {',
            '        return 404;',
            '    }',
            '',
            'Clone Master uses unpredictable archive names and authenticated',
            'WordPress downloads, but this rule is the strongest server-level',
            'protection for the fixed wp-content runtime directory.',
            '',
        ] ) );
    }

    public function deactivate() {
        // Stop future scheduled starts, but preserve durable operation state.
        // Deactivation can occur while an export or restore is interrupted;
        // deleting the temporary directory here would destroy its recovery data.
        $this->scheduler->cancel_backup();
    }

    public function init() {
        load_plugin_textdomain(
            'clone-master',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    /**
     * Display a non-fatal activation or scheduling warning to administrators.
     *
     * @return void
     */
    public function render_activation_warning(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $warning = get_option( 'wpcm_activation_warning', '' );
        if ( ! is_string( $warning ) || '' === $warning ) {
            return;
        }
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $warning ) . '</p></div>';
    }

    public function admin_menu() {
        add_menu_page(
            __( 'Clone Master', 'clone-master' ),
            __( 'Clone Master', 'clone-master' ),
            'manage_options',
            'clone-master',
            [ $this, 'render_admin_page' ],
            'dashicons-database-export',
            80
        );
    }

    public function admin_assets( $hook ) {
        if ( $hook !== 'toplevel_page_clone-master' ) return;

        // Use file modification time as version to bust browser cache on every update
        $css_ver = WPCM_VERSION . '.' . filemtime( WPCM_PLUGIN_DIR . 'admin/css/admin.css' );
        $js_ver  = WPCM_VERSION . '.' . filemtime( WPCM_PLUGIN_DIR . 'admin/js/admin.js' );

        wp_enqueue_style( 'wpcm-admin', WPCM_PLUGIN_URL . 'admin/css/admin.css', [], $css_ver );
        wp_enqueue_script( 'wpcm-admin', WPCM_PLUGIN_URL . 'admin/js/admin.js', [ 'wp-element', 'wp-i18n' ], $js_ver, true );
        wp_set_script_translations( 'wpcm-admin', 'clone-master', WPCM_PLUGIN_DIR . 'languages' );

        // Schedule tab : loaded after admin.js, adds the ScheduleTab component
        $sched_ver = WPCM_VERSION . '.' . filemtime( WPCM_PLUGIN_DIR . 'admin/js/schedule-tab.js' );
        wp_enqueue_script( 'wpcm-schedule', WPCM_PLUGIN_URL . 'admin/js/schedule-tab.js', [ 'wpcm-admin', 'wp-i18n' ], $sched_ver, true );
        wp_set_script_translations( 'wpcm-schedule', 'clone-master', WPCM_PLUGIN_DIR . 'languages' );

        // Next scheduled run (resolved server-side so it's accurate regardless of timezone)
        $next_ts  = $this->scheduler->get_next_run();
        $settings = new WPCM_Backup_Settings();

        // Detect server type from SERVER_SOFTWARE for the Nginx backup-exposure warning.
        $server_software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
        $server_type     = 'unknown';
        if ( stripos( $server_software, 'nginx' )     !== false ) $server_type = 'nginx';
        elseif ( stripos( $server_software, 'apache' )    !== false ) $server_type = 'apache';
        elseif ( stripos( $server_software, 'litespeed' ) !== false ) $server_type = 'litespeed';

        $content_path       = (string) wp_parse_url( content_url( '/' ), PHP_URL_PATH );
        $backup_public_path = '/' . trim( trailingslashit( $content_path ) . 'wpcm-backups', '/' ) . '/';

        wp_localize_script( 'wpcm-admin', 'wpcmData', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'version'    => WPCM_VERSION,
            'nonce'      => wp_create_nonce( 'wpcm_nonce' ),
            'restUrl'    => rest_url( 'wpcm/v1/' ),
            'restNonce'  => wp_create_nonce( 'wp_rest' ),
            'siteUrl'    => site_url(),
            'homeUrl'    => home_url(),
            'pluginUrl'  => WPCM_PLUGIN_URL,
            'maxUpload'  => wp_max_upload_size(),
            // Passed to the schedule tab so it can disable Nextcloud when OpenSSL is absent
            // and warn the user that backup directories may be publicly reachable on Nginx.
            'hasOpenssl' => extension_loaded( 'openssl' ),
            'serverType'       => $server_type,
            'backupPublicPath' => $backup_public_path,
            'schedule'   => array_merge( $settings->to_array(), [
                'next_run'        => $next_ts,
                'next_run_human'  => $next_ts ? wp_date( 'D d M Y, H:i', $next_ts ) : null,
                'cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            ] ),
            'i18n'       => [
                'exporting'  => __( 'Exporting...', 'clone-master' ),
                'importing'  => __( 'Importing...', 'clone-master' ),
                'success'    => __( 'Operation completed successfully!', 'clone-master' ),
                'error'      => __( 'An error occurred.', 'clone-master' ),
                'supportUs'  => __( 'Support open-source', 'clone-master' ),
            ],
        ]);
    }

    public function render_admin_page() {
        WPCM_Debug_Log::write( 'info', 'admin_page_opened', array( 'screen' => 'clone-master' ) );
        ?>
        <div id="wpcm-admin-root">
            <div class="wpcm-app wpcm-server-fallback">
                <div class="wpcm-header">
                    <div class="wpcm-header-text">
                        <h1><?php echo esc_html__( 'Clone Master', 'clone-master' ); ?></h1>
                        <p><?php echo esc_html__( 'Loading the backup and restore workspace...', 'clone-master' ); ?></p>
                    </div>
                </div>
                <div class="wpcm-card">
                    <div class="wpcm-loading-row"><span class="wpcm-spinner"></span><?php echo esc_html__( 'Loading interface...', 'clone-master' ); ?></div>
                    <noscript><p><?php echo esc_html__( 'JavaScript is required for resumable backup and restore operations.', 'clone-master' ); ?></p></noscript>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX: Server Info
    // =========================================================================
    public function ajax_server_info() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        $detector = new WPCM_Server_Detector();
        wp_send_json_success( $detector->get_info() );
    }

    // =========================================================================
    // AJAX: Export
    // =========================================================================
    public function ajax_export() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        // Export progress responses must never be cached. Some hosting stacks can
        // otherwise replay the same database cursor response indefinitely.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! defined( 'DONOTCACHEDB' ) ) {
            define( 'DONOTCACHEDB', true );
        }
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
            define( 'DONOTCACHEOBJECT', true );
        }
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
        header( 'Pragma: no-cache', true );
        header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
        header( 'X-LiteSpeed-Cache-Control: no-cache', true );
        header( 'X-Accel-Expires: 0', true );
        header( 'Vary: Cookie, Authorization, X-WPCM-Request-ID', false );
        header( 'CDN-Cache-Control: no-store', true );
        header( 'Surrogate-Control: no-store', true );

        // Increase limits
        @set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required to prevent timeout during large exports/imports
        @ini_set( 'memory_limit', '512M' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for large site processing

        $step = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : 'init';
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $request_id = isset( $_POST['request_id'] ) ? sanitize_key( wp_unslash( $_POST['request_id'] ) ) : '';

        $exporter = new WPCM_Exporter();

        WPCM_Debug_Log::write( 'info', 'export_step_started', array( 'step' => $step, 'session_id' => $session_id ) );
        ob_start();
        try {
            $result = $exporter->run_step( $step, $session_id );
            $noise  = (string) ob_get_clean();
            if ( '' !== trim( $noise ) ) {
                WPCM_Debug_Log::write( 'warning', 'export_unexpected_output', array( 'step' => $step, 'output' => $noise ) );
            }
            $event_id = WPCM_Debug_Log::write(
                'info',
                'export_step_completed',
                array(
                    'step'       => $step,
                    'session_id' => $result['session_id'] ?? $session_id,
                    'next_step'  => $result['next_step'] ?? null,
                    'progress'   => $result['progress'] ?? null,
                )
            );
            if ( is_array( $result ) ) {
                $result['request_id'] = $request_id;
                $result['event_id']   = $event_id;
            }
            wp_send_json_success( $result );
        } catch ( Throwable $error ) {
            $noise = ob_get_level() ? (string) ob_get_clean() : '';
            $event_id = WPCM_Debug_Log::throwable(
                'export_step_failed',
                $error,
                array( 'step' => $step, 'session_id' => $session_id, 'output' => $noise )
            );
            wp_send_json_error( array( 'message' => $error->getMessage(), 'event_id' => $event_id ), 500 );
        }
    }

    // =========================================================================
    // AJAX: Import Upload : supports chunked uploads for large files
    // =========================================================================
    public function ajax_import_upload() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );
        }
        if ( empty( $_FILES['backup_chunk'] ) && empty( $_FILES['backup_file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No WPCM file was uploaded.', 'clone-master' ) ), 400 );
        }

        $chunk_index  = isset( $_POST['chunk_index'] ) ? (int) $_POST['chunk_index'] : -1;
        $total_chunks = isset( $_POST['total_chunks'] ) ? (int) $_POST['total_chunks'] : -1;
        $file_name    = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : '';
        $upload_id    = isset( $_POST['upload_id'] ) ? sanitize_key( wp_unslash( $_POST['upload_id'] ) ) : '';

        WPCM_Debug_Log::write( 'info', 'upload_request_received', array( 'chunk_index' => $chunk_index, 'total_chunks' => $total_chunks, 'file_name' => $file_name, 'upload_id' => $upload_id ) );

        if ( $chunk_index >= 0 || $total_chunks > 0 ) {
            if ( $chunk_index < 0 || $total_chunks < 1 || $chunk_index >= $total_chunks || $total_chunks > 100000 ) {
                wp_send_json_error( array( 'message' => __( 'Invalid chunk sequence metadata.', 'clone-master' ) ), 400 );
            }
            if ( 'wpcm' !== strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) || strlen( $file_name ) > 200 ) {
                wp_send_json_error( array( 'message' => __( 'Only .wpcm backup containers are accepted.', 'clone-master' ) ), 400 );
            }
            if ( '' === $upload_id ) {
                if ( 0 !== $chunk_index ) {
                    wp_send_json_error( array( 'message' => __( 'The first upload request must contain chunk zero.', 'clone-master' ) ), 409 );
                }
                $upload_id = 'upload_' . strtolower( wp_generate_password( 32, false, false ) );
            }
            if ( ! preg_match( '/^upload_[a-z0-9]{20,64}$/', $upload_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid upload session identifier.', 'clone-master' ) ), 400 );
            }

            $chunk = $_FILES['backup_chunk']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( ! is_array( $chunk ) || UPLOAD_ERR_OK !== (int) ( $chunk['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
                wp_send_json_error( array( 'message' => __( 'The uploaded chunk was rejected by PHP.', 'clone-master' ) ), 400 );
            }
            $temporary = (string) ( $chunk['tmp_name'] ?? '' );
            if ( '' === $temporary || ! is_uploaded_file( $temporary ) ) {
                wp_send_json_error( array( 'message' => __( 'The chunk is not a valid HTTP upload.', 'clone-master' ) ), 400 );
            }
            $chunk_size = (int) @filesize( $temporary );
            if ( $chunk_size < 1 || $chunk_size > max( wp_max_upload_size(), 64 * MB_IN_BYTES ) ) {
                wp_send_json_error( array( 'message' => __( 'The chunk size is outside the accepted range.', 'clone-master' ) ), 400 );
            }
            if ( 0 === $chunk_index ) {
                $handle = @fopen( $temporary, 'rb' );
                $magic  = is_resource( $handle ) ? fread( $handle, strlen( WPCM_Archive::MAGIC ) ) : false;
                if ( is_resource( $handle ) ) fclose( $handle );
                if ( WPCM_Archive::MAGIC !== $magic ) {
                    wp_send_json_error( array( 'message' => __( 'The uploaded file is not a WP Commander compatible WPCMARCHIVE2 container.', 'clone-master' ) ), 400 );
                }
            }

            $upload_dir = WPCM_TEMP_DIR . $upload_id . '/';
            $chunks_dir = $upload_dir . 'chunks/';
            if ( ! wp_mkdir_p( $chunks_dir ) ) {
                wp_send_json_error( array( 'message' => __( 'Unable to create the protected upload workspace.', 'clone-master' ) ), 500 );
            }
            self::protect_directory( $upload_dir, false );
            self::protect_directory( $chunks_dir, false );
            $lock = WPCM_Reliability::acquire_lock( $upload_dir . 'upload.lock' );
            if ( false === $lock ) {
                wp_send_json_error( array( 'message' => __( 'Another request is writing this upload session.', 'clone-master' ) ), 409 );
            }

            try {
                $context    = 'chunk-upload:' . $upload_id;
                $state_path = $upload_dir . 'upload-state.json';
                $state      = WPCM_Reliability::read_signed_json( $state_path, $context );
                if ( ! is_array( $state ) ) {
                    if ( 0 !== $chunk_index ) {
                        throw new RuntimeException( __( 'The upload session is missing; restart from chunk zero.', 'clone-master' ) );
                    }
                    $state = array(
                        'format'       => 3,
                        'upload_id'    => $upload_id,
                        'file_name'    => $file_name,
                        'total_chunks' => $total_chunks,
                        'received'     => array(),
                        'total_bytes'  => 0,
                        'created_at'   => time(),
                        'completed'    => false,
                    );
                }
                if ( $state['file_name'] !== $file_name || (int) $state['total_chunks'] !== $total_chunks ) {
                    throw new RuntimeException( __( 'Chunk metadata does not match the upload session.', 'clone-master' ) );
                }

                $part_path = $chunks_dir . sprintf( '%08d.part', $chunk_index );
                $hash      = hash_file( 'sha256', $temporary );
                if ( isset( $state['received'][ (string) $chunk_index ] ) ) {
                    $known = $state['received'][ (string) $chunk_index ];
                    if ( ! is_file( $part_path ) || (int) $known['size'] !== $chunk_size || ! hash_equals( (string) $known['sha256'], (string) $hash ) ) {
                        throw new RuntimeException( __( 'A duplicate upload chunk does not match the stored chunk.', 'clone-master' ) );
                    }
                } else {
                    WPCM_Reliability::atomic_copy( $temporary, $part_path, 0640 );
                    if ( ! hash_equals( (string) $hash, WPCM_Reliability::checksum( $part_path ) ) ) {
                        wp_delete_file( $part_path );
                        throw new RuntimeException( __( 'The stored upload chunk failed SHA-256 validation.', 'clone-master' ) );
                    }
                    $state['received'][ (string) $chunk_index ] = array( 'size' => $chunk_size, 'sha256' => $hash );
                    $state['total_bytes'] += $chunk_size;
                    WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );
                }

                $complete = count( $state['received'] ) === $total_chunks;
                $final_path = $upload_dir . $file_name;
                if ( $complete && ! is_file( $final_path ) ) {
                    $assembling = $final_path . '.partial';
                    $output = @fopen( $assembling, 'x+b' );
                    if ( ! is_resource( $output ) ) {
                        throw new RuntimeException( __( 'Unable to create the assembled WPCM file.', 'clone-master' ) );
                    }
                    try {
                        for ( $index = 0; $index < $total_chunks; $index++ ) {
                            $part = $chunks_dir . sprintf( '%08d.part', $index );
                            $meta = $state['received'][ (string) $index ] ?? null;
                            if ( ! is_array( $meta ) || ! is_file( $part )
                                || (int) @filesize( $part ) !== (int) $meta['size']
                                || ! hash_equals( (string) $meta['sha256'], WPCM_Reliability::checksum( $part ) ) ) {
                                throw new RuntimeException( sprintf( __( 'Stored upload chunk %d failed validation.', 'clone-master' ), $index + 1 ) );
                            }
                            $input = @fopen( $part, 'rb' );
                            if ( ! is_resource( $input ) ) throw new RuntimeException( __( 'Unable to reopen an upload chunk.', 'clone-master' ) );
                            while ( ! feof( $input ) ) {
                                $bytes = fread( $input, 8 * MB_IN_BYTES );
                                if ( false === $bytes ) { fclose( $input ); throw new RuntimeException( __( 'Unable to read an upload chunk.', 'clone-master' ) ); }
                                if ( '' !== $bytes ) WPCM_Archive::write_exact( $output, $bytes );
                            }
                            fclose( $input );
                        }
                        WPCM_Archive::flush( $output );
                    } finally {
                        fclose( $output );
                    }
                    WPCM_Archive::inspect( $assembling );
                    if ( ! @rename( $assembling, $final_path ) ) {
                        wp_delete_file( $assembling );
                        throw new RuntimeException( __( 'Unable to atomically publish the uploaded WPCM container.', 'clone-master' ) );
                    }
                    $state['completed']      = true;
                    $state['archive_sha256'] = WPCM_Reliability::checksum( $final_path );
                    $state['completed_at']   = time();
                    WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );
                } elseif ( $complete ) {
                    WPCM_Archive::inspect( $final_path );
                }

                $event_id = WPCM_Debug_Log::write(
                    'info',
                    $complete ? 'upload_completed' : 'upload_chunk_verified',
                    array(
                        'upload_id'    => $upload_id,
                        'chunk_index'  => $chunk_index,
                        'total_chunks' => $total_chunks,
                        'complete'     => $complete,
                        'total_bytes'  => (int) ( $state['total_bytes'] ?? 0 ),
                    )
                );
                wp_send_json_success( array(
                    'event_id'   => $event_id,
                    'upload_id'  => $upload_id,
                    'chunk'      => $chunk_index,
                    'total'      => $total_chunks,
                    'complete'   => $complete,
                    'file_path'  => $complete ? $final_path : '',
                    'session_id' => $complete ? $upload_id : '',
                    'message'    => $complete
                        ? sprintf( __( 'WPCM upload complete and fully verified (%s).', 'clone-master' ), size_format( filesize( $final_path ) ) )
                        : sprintf( __( 'Chunk %1$d of %2$d stored and verified.', 'clone-master' ), $chunk_index + 1, $total_chunks ),
                ) );
            } catch ( Throwable $error ) {
                $event_id = WPCM_Debug_Log::throwable( 'upload_chunk_failed', $error, array( 'upload_id' => $upload_id, 'chunk_index' => $chunk_index, 'total_chunks' => $total_chunks ) );
                wp_send_json_error( array( 'message' => $error->getMessage(), 'upload_id' => $upload_id, 'event_id' => $event_id ), 400 );
            } finally {
                WPCM_Reliability::release_lock( $lock );
            }
        }

        $file = $_FILES['backup_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
            wp_send_json_error( array( 'message' => __( 'The uploaded file was rejected by PHP.', 'clone-master' ) ), 400 );
        }
        if ( 'wpcm' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Only .wpcm backup containers are accepted.', 'clone-master' ) ), 400 );
        }
        $temporary = (string) ( $file['tmp_name'] ?? '' );
        if ( '' === $temporary || ! is_uploaded_file( $temporary ) ) {
            wp_send_json_error( array( 'message' => __( 'The file is not a valid HTTP upload.', 'clone-master' ) ), 400 );
        }
        $upload_id  = 'upload_' . strtolower( wp_generate_password( 32, false, false ) );
        $upload_dir = WPCM_TEMP_DIR . $upload_id . '/';
        if ( ! wp_mkdir_p( $upload_dir ) ) {
            wp_send_json_error( array( 'message' => __( 'Unable to create the protected upload workspace.', 'clone-master' ) ), 500 );
        }
        self::protect_directory( $upload_dir, false );
        $name       = sanitize_file_name( (string) $file['name'] );
        $assembling = $upload_dir . $name . '.partial';
        $final_path = $upload_dir . $name;
        try {
            WPCM_Reliability::atomic_copy( $temporary, $assembling, 0640 );
            WPCM_Archive::inspect( $assembling );
            if ( ! @rename( $assembling, $final_path ) ) {
                throw new RuntimeException( __( 'Unable to atomically publish the uploaded WPCM container.', 'clone-master' ) );
            }
            WPCM_Reliability::atomic_signed_json(
                $upload_dir . 'upload-state.json',
                array(
                    'format'         => 3,
                    'upload_id'      => $upload_id,
                    'file_name'      => $name,
                    'completed'      => true,
                    'archive_sha256' => WPCM_Reliability::checksum( $final_path ),
                    'completed_at'   => time(),
                ),
                'chunk-upload:' . $upload_id
            );
        } catch ( Throwable $error ) {
            if ( is_file( $assembling ) ) wp_delete_file( $assembling );
            $event_id = WPCM_Debug_Log::throwable( 'upload_file_failed', $error, array( 'upload_id' => $upload_id, 'file_name' => $name ) );
            wp_send_json_error( array( 'message' => $error->getMessage(), 'event_id' => $event_id ), 400 );
        }
        $event_id = WPCM_Debug_Log::write( 'info', 'upload_completed', array( 'upload_id' => $upload_id, 'file_name' => $name, 'bytes' => (int) @filesize( $final_path ) ) );
        wp_send_json_success( array( 'file_path' => $final_path, 'session_id' => $upload_id, 'complete' => true, 'event_id' => $event_id ) );
    }

    // =========================================================================
    // AJAX: Import : only handles extract + prepare (safe WP-side steps)
    // The actual DB/files/URL import runs via standalone installer
    // =========================================================================
    public function ajax_import() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );
        }

        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
        header( 'X-LiteSpeed-Cache-Control: no-cache', true );
        header( 'X-Accel-Expires: 0', true );
        header( 'CDN-Cache-Control: no-store', true );
        header( 'Surrogate-Control: no-store', true );

        @set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Bounded import preparation.
        @ini_set( 'memory_limit', '512M' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large manifest preparation.

        $step        = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'extract';
        $session_id  = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $new_url     = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';
        $request_id  = isset( $_POST['request_id'] ) ? sanitize_key( wp_unslash( $_POST['request_id'] ) ) : WPCM_Debug_Log::request_id();
        $import_opts = isset( $_POST['import_opts'] ) ? wp_unslash( $_POST['import_opts'] ) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded by the importer.

        $file_path = '';
        if ( ! empty( $_POST['file_path'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Strict realpath confinement follows.
            $raw_path      = sanitize_text_field( wp_unslash( $_POST['file_path'] ) );
            $real_temp_dir = realpath( WPCM_TEMP_DIR );
            $real_given    = realpath( $raw_path );
            if ( $real_temp_dir && $real_given && is_file( $real_given )
                && 0 === strpos( $real_given, rtrim( $real_temp_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR )
                && 'wpcm' === strtolower( pathinfo( $real_given, PATHINFO_EXTENSION ) ) ) {
                $file_path = $real_given;
            } else {
                wp_send_json_error( array( 'message' => __( 'Invalid or incomplete uploaded archive path.', 'clone-master' ) ), 400 );
            }
        } elseif ( ! empty( $_POST['backup_name'] ) ) {
            $raw_backup_name = wp_unslash( $_POST['backup_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Strict resolver follows.
            $backup          = $this->resolve_local_backup( (string) $raw_backup_name );
            if ( null !== $backup ) {
                $file_path = $backup['path'];
            } else {
                wp_send_json_error( array( 'message' => __( 'Invalid backup name.', 'clone-master' ) ), 400 );
            }
        }

        $started = microtime( true );
        WPCM_Debug_Log::write(
            'info',
            'import_step_started',
            array(
                'step'       => $step,
                'session_id' => $session_id,
                'request_id' => $request_id,
                'has_file'   => '' !== $file_path,
            )
        );

        $importer = new WPCM_Importer();
        ob_start();
        try {
            $result = $importer->run_step( $step, $session_id, $file_path, $new_url, $import_opts );
            $noise  = (string) ob_get_clean();
            if ( '' !== trim( $noise ) ) {
                WPCM_Debug_Log::write(
                    'warning',
                    'import_unexpected_output',
                    array( 'step' => $step, 'output' => $noise )
                );
            }
            $event_id = WPCM_Debug_Log::write(
                'info',
                'import_step_completed',
                array(
                    'step'        => $step,
                    'session_id'  => $result['session_id'] ?? $session_id,
                    'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
                )
            );
            if ( is_array( $result ) ) {
                $result['request_id'] = $request_id;
                $result['event_id']   = $event_id;
            }
            wp_send_json_success( $result );
        } catch ( Throwable $error ) {
            $noise = ob_get_level() ? (string) ob_get_clean() : '';
            $event_id = WPCM_Debug_Log::throwable(
                'import_step_failed',
                $error,
                array(
                    'step'        => $step,
                    'session_id'  => $session_id,
                    'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
                    'output'      => $noise,
                )
            );
            wp_send_json_error(
                array(
                    'message'  => $error->getMessage(),
                    'event_id' => $event_id,
                    'step'     => $step,
                ),
                500
            );
        }
    }

    // =========================================================================
    // AJAX: Get Backups List
    // =========================================================================
    public function ajax_get_backups() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        $backups = [];
        if ( is_dir( WPCM_BACKUP_DIR ) ) {
            // Build a filename → storage origin map from scheduler history
            $history    = WPCM_Backup_Settings::get_history();
            $origin_map = [];
            foreach ( $history as $entry ) {
                if ( ! empty( $entry['filename'] ) && ! empty( $entry['storage_driver'] ) ) {
                    $origin_map[ $entry['filename'] ] = $entry['storage_driver'];
                }
            }

            $files = glob( WPCM_BACKUP_DIR . '*.wpcm' );
            foreach ( $files as $file ) {
                $backup = $this->resolve_local_backup( basename( $file ) );
                if ( null === $backup ) {
                    continue;
                }
                $size  = filesize( $backup['path'] );
                $mtime = filemtime( $backup['path'] );
                if ( false === $size || false === $mtime ) {
                    continue;
                }
                $backups[] = [
                    'name'     => $backup['name'],
                    'size'     => size_format( (int) $size ),
                    'size_raw' => (int) $size,
                    'date'     => gmdate( 'Y-m-d H:i:s', (int) $mtime ),
                    'origin'   => $origin_map[ $backup['name'] ] ?? __( 'Local storage', 'clone-master' ),
                    // 'path' intentionally omitted : the absolute server path is not
                    // needed by the frontend (all operations use backup_name) and
                    // exposing it would leak the server's directory layout.
                ];
            }
            usort( $backups, function( $a, $b ) {
                return strtotime( $b['date'] ) - strtotime( $a['date'] );
            });
        }

        wp_send_json_success( $backups );
    }

    /**
     * Resolve a published local backup without allowing other runtime files.
     *
     * @param string $raw_name User-supplied archive name.
     * @return array{name:string,path:string}|null
     */
    private function resolve_local_backup( string $raw_name ): ?array {
        $normalized = str_replace( '\\', '/', trim( $raw_name ) );
        if ( '' === $normalized || $normalized !== basename( $normalized ) ) {
            return null;
        }
        $name = sanitize_file_name( $normalized );
        if ( $name !== $normalized || 'wpcm' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
            return null;
        }

        $base_real = realpath( WPCM_BACKUP_DIR );
        $path      = WPCM_BACKUP_DIR . $name;
        $path_real = realpath( $path );
        if ( false === $base_real || false === $path_real || ! is_file( $path_real ) || is_link( $path ) ) {
            return null;
        }

        $prefix = rtrim( $base_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        if ( 0 !== strpos( $path_real, $prefix ) ) {
            return null;
        }

        return array( 'name' => $name, 'path' => $path_real );
    }

    // =========================================================================
    // AJAX: Delete Backup
    // =========================================================================
    public function ajax_delete_backup() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        $raw_name = isset( $_POST['backup_name'] ) ? wp_unslash( $_POST['backup_name'] ) : '';
        $backup   = $this->resolve_local_backup( (string) $raw_name );
        if ( null !== $backup && wp_delete_file( $backup['path'] ) ) {
            foreach ( array( $backup['path'] . '.sha256', $backup['path'] . '.sha256.bak' ) as $sidecar ) {
                if ( is_file( $sidecar ) && WPCM_Reliability::path_is_within( $sidecar, WPCM_BACKUP_DIR ) ) {
                    wp_delete_file( $sidecar );
                }
            }
            wp_send_json_success();
        }
        wp_send_json_error( array( 'message' => __( 'File not found.', 'clone-master' ) ), 404 );
    }

    // =========================================================================
    // AJAX: Download Backup : streaming for large files
    // =========================================================================
    public function ajax_download_backup() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized request.', 'clone-master' ), '', array( 'response' => 403 ) );

        $raw_name = isset( $_GET['backup_name'] ) ? wp_unslash( $_GET['backup_name'] ) : '';
        $backup   = $this->resolve_local_backup( (string) $raw_name );
        if ( null === $backup ) {
            wp_die( esc_html__( 'File not found.', 'clone-master' ), '', array( 'response' => 404 ) );
        }
        $name = $backup['name'];
        $path = $backup['path'];

        $size   = filesize( $path );
        $handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary file streaming for download.
        if ( false === $size || ! is_resource( $handle ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
            wp_die( esc_html__( 'Unable to open the backup archive.', 'clone-master' ), '', array( 'response' => 500 ) );
        }

        // Disable output buffering for streaming.
        while ( ob_get_level() ) ob_end_clean();
        @ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for binary streaming
        @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for large file downloads

        $ascii_name = str_replace( array( '"', "\r", "\n" ), '', $name );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8' . "''" . rawurlencode( $name ) );
        header( 'Content-Length: ' . (int) $size );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
        header( 'CDN-Cache-Control: no-store' );
        header( 'Surrogate-Control: no-store' );
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
        header( 'Pragma: no-cache' );

        // Stream in 8 MiB chunks instead of loading the archive into memory.
        while ( ! feof( $handle ) ) {
            $chunk = fread( $handle, 8 * 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Binary WPCM streaming.
            if ( false === $chunk ) {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping would corrupt WPCM data.
            flush();
        }
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    // =========================================================================
    // AJAX: Persistent diagnostics
    // =========================================================================
    public function ajax_debug_get() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );
        }
        wp_send_json_success(
            array(
                'events'       => WPCM_Debug_Log::recent( 300 ),
                'download_url' => wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=wpcm_debug_download' ),
                    'wpcm_debug_download',
                    '_wpcm_debug_nonce'
                ),
                'log_path'     => 'wp-content/wpcm-logs/clone-master.jsonl',
            )
        );
    }

    public function ajax_debug_clear() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );
        }
        WPCM_Debug_Log::clear();
        $event_id = WPCM_Debug_Log::write( 'info', 'diagnostic_log_cleared', array() );
        wp_send_json_success( array( 'message' => __( 'Diagnostic log cleared.', 'clone-master' ), 'event_id' => $event_id ) );
    }

    public function ajax_debug_client() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );
        }
        $event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : 'client_error';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $details = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
        $event_id = WPCM_Debug_Log::write(
            'error',
            $event,
            array(
                'message' => $message,
                'details' => $details,
                'url'     => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
            )
        );
        wp_send_json_success( array( 'event_id' => $event_id ) );
    }

    public function ajax_debug_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized request.', 'clone-master' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'wpcm_debug_download', '_wpcm_debug_nonce' );
        $report = WPCM_Debug_Log::report();
        while ( ob_get_level() ) {
            ob_end_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="clone-master-diagnostic-' . gmdate( 'Ymd-His' ) . '.txt"' );
        header( 'Content-Length: ' . strlen( $report ) );
        echo $report; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text diagnostic download.
        exit;
    }

    // =========================================================================
    // Schedule AJAX handlers
    // =========================================================================

    /**
     * GET: returns current schedule settings + next run time.
     */
    public function ajax_get_schedule() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        $settings = new WPCM_Backup_Settings();
        $next_ts  = $this->scheduler->get_next_run();

        wp_send_json_success( array_merge( $settings->to_array(), [
            'next_run'       => $next_ts,
            'next_run_human' => $next_ts ? wp_date( 'D d M Y, H:i', $next_ts ) : null,
            'cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        ] ) );
    }

    /**
     * POST: saves schedule settings and reschedules cron.
     */
    public function ajax_save_schedule() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        $settings = new WPCM_Backup_Settings();
        $settings->save( [
            'enabled'              => ! empty( $_POST['enabled'] ) && sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) !== 'false',
            'frequency'            => sanitize_text_field( wp_unslash( $_POST['frequency'] ?? 'daily' ) ),
            'retention_mode'       => sanitize_text_field( wp_unslash( $_POST['retention_mode'] ?? 'count' ) ),
            'retention_count'      => absint( wp_unslash( $_POST['retention_count'] ?? 7 ) ),
            'retention_days'       => absint( wp_unslash( $_POST['retention_days'] ?? 30 ) ),
            'notify_email'         => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
            'notify_on'            => sanitize_text_field( wp_unslash( $_POST['notify_on'] ?? 'error' ) ),
            'storage_driver'       => sanitize_text_field( wp_unslash( $_POST['storage_driver'] ?? 'local' ) ),
            'nextcloud_path'       => sanitize_text_field( wp_unslash( $_POST['nextcloud_path'] ?? 'Backups/WordPress' ) ),
            'nextcloud_keep_local' => ! empty( $_POST['nextcloud_keep_local'] ) && $_POST['nextcloud_keep_local'] !== 'false',
        ] );

        // Reload into the scheduler so it picks up the new settings.
        $this->scheduler->reload_settings();
        try {
            $this->scheduler->schedule_backup();
            delete_option( 'wpcm_activation_warning' );
        } catch ( Throwable $error ) {
            update_option( 'wpcm_activation_warning', $error->getMessage(), false );
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %s: scheduling error message. */
                        __( 'Settings were saved, but the schedule could not be registered: %s', 'clone-master' ),
                        $error->getMessage()
                    ),
                ),
                500
            );
        }

        $next_ts = $this->scheduler->get_next_run();
        wp_send_json_success( [
            'message'        => __( 'Settings saved.', 'clone-master' ),
            'next_run'       => $next_ts,
            'next_run_human' => $next_ts ? wp_date( 'D d M Y, H:i', $next_ts ) : null,
        ] );
    }

    /**
     * Queue an immediate resumable backup.
     */
    public function ajax_run_backup_now() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );
        }

        $queued = $this->scheduler->queue_backup( 'manual' );
        if ( is_wp_error( $queued ) ) {
            $status = 'wpcm_backup_running' === $queued->get_error_code() ? 409 : 500;
            wp_send_json_error( array( 'message' => $queued->get_error_message() ), $status );
        }

        wp_send_json_success( array(
            'status'   => 'queued',
            'run_id'   => (string) $queued['run_id'],
            'progress' => (int) $queued['progress'],
            'message'  => __( 'The durable backup job was queued. WP-Cron or this browser will resume it.', 'clone-master' ),
        ) );
    }

    /**
     * Return status and opportunistically process a bounded fallback slice.
     */
    public function ajax_get_backup_status() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );
        }

        $active = $this->scheduler->get_active_job();
        if ( is_array( $active ) ) {
            // Browser-assisted progress keeps the job moving on hosts that block
            // WP-Cron loopback requests. The scheduler lock prevents overlap.
            $this->scheduler->resume_from_status_poll();
            $active = $this->scheduler->get_active_job();
        }

        $history = WPCM_Backup_Settings::get_history();
        $latest  = ! empty( $history ) ? $history[0] : null;
        if ( $latest ) {
            $latest['size_human'] = ! empty( $latest['size_bytes'] )
                ? size_format( (int) $latest['size_bytes'] )
                : ':';
        }

        wp_send_json_success( array(
            'running'  => is_array( $active ),
            'run_id'   => is_array( $active ) ? (string) ( $active['run_id'] ?? '' ) : '',
            'progress' => is_array( $active ) ? (int) ( $active['progress'] ?? 0 ) : 100,
            'message'  => is_array( $active ) ? (string) ( $active['message'] ?? '' ) : '',
            'latest'   => $latest,
        ) );
    }

    /**
     * GET: returns the backup history log.
     */
    public function ajax_get_backup_history() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        $history = WPCM_Backup_Settings::get_history();

        // Enrich each entry with a human-readable size
        foreach ( $history as &$entry ) {
            if ( ! empty( $entry['size_bytes'] ) ) {
                $entry['size_human'] = size_format( (int) $entry['size_bytes'] );
            } else {
                $entry['size_human'] = ':';
            }
        }
        unset( $entry );

        wp_send_json_success( $history );
    }

    /**
     * POST: clears the history log.
     */
    public function ajax_clear_history() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        WPCM_Backup_Settings::clear_history();
        wp_send_json_success( array( 'message' => __( 'History cleared.', 'clone-master' ) ) );
    }

    // =========================================================================
    // Nextcloud Login Flow v2 : https://docs.nextcloud.com/server/latest/
    //                           developer_manual/client_apis/LoginFlow/
    // =========================================================================

    /**
     * Step 1 : Initiate the Nextcloud Login Flow v2.
     *
     * Calls POST {nextcloud_url}/index.php/login/v2 on the NC server.
     * Stores the poll credentials in a short-lived transient so the browser
     * never sees the raw poll token.
     * Returns { login_url, session_id } to the JS.
     */
    public function ajax_nc_init_flow() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        // Sanitization order : two rules must both be satisfied simultaneously:
        //
        //   MissingUnslash    (WordPress.Security.ValidatedSanitizedInput.MissingUnslash)
        //   → wp_unslash() must be the innermost wrapper around $_POST directly.
        //
        //   InputNotSanitized (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized)
        //   → esc_url_raw() must receive the result of wp_unslash() as its direct
        //     argument. Any intermediate call (even trim()) breaks the sniff's
        //     recognition of the sanitization chain.
        //
        // Solution: sanitize first with esc_url_raw( wp_unslash( ... ) ), then trim
        // the already-sanitized value in a separate statement. This satisfies both
        // sniffs without a phpcs:ignore and keeps the logic correct : esc_url_raw()
        // normalises the URL before we strip surrounding whitespace.
        $nc_url = esc_url_raw( wp_unslash( $_POST['nextcloud_url'] ?? '' ) );
        $nc_url = trim( $nc_url );
        if ( ! $nc_url ) {
            wp_send_json_error( array( 'message' => __( 'Nextcloud URL is required.', 'clone-master' ) ), 400 );
        }

        $nc_url = rtrim( $nc_url, '/' );

        // Validate every resolved address before the first outbound request.
        $ssrf = $this->validate_nextcloud_url( $nc_url );
        if ( $ssrf['error'] ) {
            wp_send_json_error( array( 'message' => $ssrf['error'] ), 400 );
        }

        $endpoint = $nc_url . '/index.php/login/v2';
        $response = $this->safe_nextcloud_post(
            $endpoint,
            array(
                'timeout' => 15,
                'headers' => array( 'User-Agent' => 'Clone-Master/' . WPCM_VERSION ),
            ),
            $ssrf
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => sprintf( __( 'Unable to reach Nextcloud: %s', 'clone-master' ), $response->get_error_message() ) ), 502 );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || empty( $body['poll']['endpoint'] ) || empty( $body['poll']['token'] ) || empty( $body['login'] ) ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %d: HTTP response status. */
                        __( 'Unexpected response from Nextcloud (HTTP %d). Check the URL and confirm that Login Flow v2 is available.', 'clone-master' ),
                        $code
                    ),
                ),
                502
            );
        }

        $poll_url  = esc_url_raw( (string) $body['poll']['endpoint'] );
        $login_url = esc_url_raw( (string) $body['login'] );
        $poll_ssrf = $this->validate_nextcloud_url( $poll_url );
        $login_ssrf = $this->validate_nextcloud_url( $login_url );
        if ( $poll_ssrf['error'] || $login_ssrf['error'] || ! $this->same_url_origin( $nc_url, $poll_url ) || ! $this->same_url_origin( $nc_url, $login_url ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Nextcloud returned a login or poll endpoint outside the validated server origin. Connection cancelled.', 'clone-master' ) ),
                403
            );
        }

        // Store poll data server-side : the JS only gets an opaque session_id
        $session_id = wp_generate_password( 32, false );
        $stored = set_transient( 'wpcm_nc_flow_' . $session_id, [
            'endpoint'      => $poll_url,
            'token'         => $body['poll']['token'],
            'nextcloud_url' => $nc_url,
            'poll_count'    => 0,
        ], 10 * MINUTE_IN_SECONDS );
        if ( ! $stored ) {
            wp_send_json_error( array( 'message' => __( 'Unable to create the temporary Nextcloud authorization session.', 'clone-master' ) ), 500 );
        }

        wp_send_json_success( [
            'session_id' => $session_id,
            'login_url'  => $login_url,
        ] );
    }

    /**
     * Step 2 : Poll for the result of the Login Flow v2.
     *
     * Called every 2 s from the JS until Nextcloud returns credentials.
     * On success: saves url + user + encrypted app-password to settings.
     */
    public function ajax_nc_poll_flow() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        $session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
        $flow_data  = get_transient( 'wpcm_nc_flow_' . $session_id );

        if ( ! $flow_data ) {
            wp_send_json_error( array( 'message' => __( 'Session expired or invalid.', 'clone-master' ) ), 410 );
        }

        $flow_data['poll_count'] = (int) ( $flow_data['poll_count'] ?? 0 ) + 1;
        if ( $flow_data['poll_count'] > 300 ) {
            delete_transient( 'wpcm_nc_flow_' . $session_id );
            wp_send_json_error( array( 'message' => __( 'Nextcloud authorization timed out.', 'clone-master' ) ), 410 );
        }
        set_transient( 'wpcm_nc_flow_' . $session_id, $flow_data, 10 * MINUTE_IN_SECONDS );

        // Re-validate the short-lived poll endpoint before every request.
        $poll_ssrf = $this->validate_nextcloud_url( (string) $flow_data['endpoint'] );
        if ( $poll_ssrf['error'] || ! $this->same_url_origin( (string) $flow_data['nextcloud_url'], (string) $flow_data['endpoint'] ) ) {
            delete_transient( 'wpcm_nc_flow_' . $session_id );
            wp_send_json_error( array( 'message' => __( 'The saved Nextcloud poll endpoint is no longer safe.', 'clone-master' ) ), 403 );
        }

        $response = $this->safe_nextcloud_post(
            (string) $flow_data['endpoint'],
            array(
                'timeout' => 10,
                'headers' => array( 'User-Agent' => 'Clone-Master/' . WPCM_VERSION ),
                'body'    => array( 'token' => (string) $flow_data['token'] ),
            ),
            $poll_ssrf
        );

        if ( is_wp_error( $response ) ) {
            // Network error : keep polling
            wp_send_json_success( [ 'pending' => true ] );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 404 ) {
            // User hasn't authorized yet
            wp_send_json_success( [ 'pending' => true ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ! empty( $body['loginName'] ) && ! empty( $body['appPassword'] ) ) {
            // ── Success : save credentials to settings ────────────────────
            $settings = new WPCM_Backup_Settings();
            $settings->save( [
                'nextcloud_url'       => $flow_data['nextcloud_url'],
                'nextcloud_user'      => $body['loginName'],
                'nextcloud_pass'      => $body['appPassword'], // encrypted inside save()
                'nextcloud_connected' => true,
                'storage_driver'      => 'nextcloud',
            ] );

            delete_transient( 'wpcm_nc_flow_' . $session_id );

            wp_send_json_success( [
                'pending'       => false,
                'connected'     => true,
                'user'          => $body['loginName'],
                'nextcloud_url' => $flow_data['nextcloud_url'],
            ] );
        }

        // Unexpected response : abort
        delete_transient( 'wpcm_nc_flow_' . $session_id );
        wp_send_json_error( array( 'message' => sprintf( __( 'Unexpected response (HTTP %d).', 'clone-master' ), $code ) ), 502 );
    }

    /**
     * Step 3 : Disconnect: clears stored Nextcloud credentials.
     */
    public function ajax_nc_disconnect() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        $settings = new WPCM_Backup_Settings();
        $settings->save( [
            'nextcloud_url'       => '',
            'nextcloud_user'      => '',
            'nextcloud_pass'      => '',
            'nextcloud_connected' => false,
            'storage_driver'      => 'local',
        ] );

        wp_send_json_success( array( 'message' => __( 'Nextcloud account disconnected.', 'clone-master' ) ) );
    }

    /**
     * POST: tests storage driver connectivity (called from the UI test button).
     */
    public function ajax_test_storage() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );

        // Build a temporary settings object from POST values (not yet saved)
        // so the user can test before saving.
        $nc_url_raw = esc_url_raw( wp_unslash( $_POST['nextcloud_url'] ?? '' ) );

        // Apply the same SSRF validation as the authorization flow before the
        // temporary driver performs any outbound request. The driver validates
        // again and pins the resolved address when the cURL transport is used.
        if ( $nc_url_raw ) {
            $ssrf = $this->validate_nextcloud_url( $nc_url_raw );
            if ( $ssrf['error'] ) {
                wp_send_json_error( [ 'message' => $ssrf['error'] ] );
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $temporary_changes = array(
            'storage_driver' => sanitize_text_field( wp_unslash( $_POST['storage_driver'] ?? 'local' ) ),
            'nextcloud_url'  => $nc_url_raw,
            'nextcloud_user' => sanitize_text_field( wp_unslash( $_POST['nextcloud_user'] ?? '' ) ),
            'nextcloud_path' => sanitize_text_field( wp_unslash( $_POST['nextcloud_path'] ?? 'Backups/WordPress' ) ),
        );
        $posted_password = wp_unslash( $_POST['nextcloud_pass'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must preserve special characters and remain server-side.
        if ( '__stored__' !== $posted_password ) {
            $temporary_changes['nextcloud_pass'] = $posted_password;
        }
        $settings = WPCM_Backup_Settings::temporary( $temporary_changes );
        $driver   = WPCM_Storage_Driver::make( $settings );
        $result = $driver->test();

        if ( $result['ok'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    /**
     * Validate a Nextcloud URL and resolve it only to public addresses.
     *
     * @param string $url Sanitized URL.
     * @return array{error:string|null,ip:string|null,host:string,port:int}
     */
    private function validate_nextcloud_url( string $url ): array {
        $endpoint = WPCM_Storage_Nextcloud::resolve_public_endpoint( $url );
        if ( is_wp_error( $endpoint ) ) {
            return array(
                'error' => $endpoint->get_error_message(),
                'ip'    => null,
                'host'  => '',
                'port'  => 0,
            );
        }
        return array(
            'error' => null,
            'ip'    => (string) $endpoint['ip'],
            'host'  => (string) $endpoint['host'],
            'port'  => (int) $endpoint['port'],
        );
    }

    /**
     * Compare scheme, host, and effective port for two URLs.
     *
     * @param string $left  First URL.
     * @param string $right Second URL.
     * @return bool
     */
    private function same_url_origin( string $left, string $right ): bool {
        $a = wp_parse_url( $left );
        $b = wp_parse_url( $right );
        if ( ! is_array( $a ) || ! is_array( $b ) || empty( $a['scheme'] ) || empty( $a['host'] ) || empty( $b['scheme'] ) || empty( $b['host'] ) ) {
            return false;
        }
        $a_scheme = strtolower( (string) $a['scheme'] );
        $b_scheme = strtolower( (string) $b['scheme'] );
        $a_port   = isset( $a['port'] ) ? (int) $a['port'] : ( 'https' === $a_scheme ? 443 : 80 );
        $b_port   = isset( $b['port'] ) ? (int) $b['port'] : ( 'https' === $b_scheme ? 443 : 80 );
        return $a_scheme === $b_scheme && strtolower( (string) $a['host'] ) === strtolower( (string) $b['host'] ) && $a_port === $b_port;
    }

    /**
     * Perform a safe Nextcloud POST and pin the validated IP when WordPress uses cURL.
     *
     * @param string $url      Request URL.
     * @param array  $args     WordPress HTTP arguments.
     * @param array  $endpoint Validated endpoint metadata.
     * @return array|WP_Error
     */
    private function safe_nextcloud_post( string $url, array $args, array $endpoint ) {
        $args['reject_unsafe_urls'] = true;
        $args['redirection']        = 0;
        $args['sslverify']          = true;

        $callback = null;
        if ( function_exists( 'curl_setopt' ) && defined( 'CURLOPT_RESOLVE' ) && ! empty( $endpoint['host'] ) && ! empty( $endpoint['ip'] ) && ! empty( $endpoint['port'] ) ) {
            $ip      = false !== strpos( (string) $endpoint['ip'], ':' ) ? '[' . $endpoint['ip'] . ']' : (string) $endpoint['ip'];
            $resolve = (string) $endpoint['host'] . ':' . (int) $endpoint['port'] . ':' . $ip;
            $callback = static function ( $handle, $request_args, $request_url ) use ( $url, $resolve ) {
                if ( $request_url !== $url ) {
                    return;
                }
                curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve ) );
                curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
                if ( defined( 'CURLOPT_PROXY' ) ) {
                    curl_setopt( $handle, CURLOPT_PROXY, '' );
                }
                if ( defined( 'CURLOPT_NOPROXY' ) ) {
                    curl_setopt( $handle, CURLOPT_NOPROXY, '*' );
                }
                if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
                    curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
                }
            };
            add_action( 'http_api_curl', $callback, 10, 3 );
        }

        try {
            return wp_safe_remote_post( $url, $args );
        } finally {
            if ( null !== $callback ) {
                remove_action( 'http_api_curl', $callback, 10 );
            }
        }
    }

    private function recursive_delete( $dir ) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            $item->isDir() ? rmdir( $item->getRealPath() ) : wp_delete_file( $item->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem cannot do recursive delete in background context
        }
        rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }

    /**
     * Display the result of an automatic recovery attempt once.
     */
    public function wpcm_recovery_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $notice = get_option( 'wpcm_last_recovery_notice' );
        if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
            return;
        }
        delete_option( 'wpcm_last_recovery_notice' );
        $class = ( 'error' === ( $notice['type'] ?? '' ) ) ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr( $class ) . '"><p><strong>Clone Master</strong> : ' . esc_html( $notice['message'] ) . '</p></div>';
    }

    // =========================================================================
    // Noindex enforcement : active while wpcm_noindex option = '1'
    // Set by the standalone installer when block_indexing is requested.
    // Auto-cleared when admin saves Settings > Reading.
    // =========================================================================

    /**
     * Returns '0' (discourage indexing) for get_option('blog_public') while the flag is active.
     * Fires BEFORE cache/DB lookup → immune to Redis, Memcached, alloptions.
     */
    public function wpcm_enforce_noindex_option( $pre ) {
        if ( $this->wpcm_is_noindex_active() ) {
            return '0';
        }
        return $pre;
    }

    /**
     * Adds noindex/nofollow to the wp_robots filter array (WP 5.7+ Robots API).
     */
    public function wpcm_enforce_noindex_robots( $robots ) {
        if ( $this->wpcm_is_noindex_active() ) {
            if ( function_exists( 'wp_robots_no_robots' ) ) {
                return wp_robots_no_robots( $robots );
            }
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    /**
     * WP < 5.7 fallback: injects <meta name="robots"> directly in wp_head.
     */
    public function wpcm_inject_noindex_meta() {
        if ( $this->wpcm_is_noindex_active() ) {
            echo '<meta name="robots" content="noindex,nofollow">' . "
";
        }
    }

    /**
     * Clears the noindex flag when admin explicitly saves Settings > Reading.
     */
    public function wpcm_clear_noindex_flag() {
        delete_option( 'wpcm_noindex' );
    }

    /**
     * Shows an admin notice while noindex is active.
     */
    public function wpcm_noindex_admin_notice() {
        if ( ! $this->wpcm_is_noindex_active() ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        $url = admin_url( 'options-reading.php' );
        echo '<div class="notice notice-warning">'
           . '<p><strong>Clone Master</strong> : '
           . 'Search engines are <strong>blocked</strong> after migration. '
           . 'Go to <a href="' . esc_url( $url ) . '">Settings &gt; Reading</a> to enable indexing once the site is verified.</p>'
           . '</div>';
    }

    /**
     * Returns true if the noindex flag is currently active.
     * Uses static cache to avoid redundant DB queries within the same request.
     */
    private function wpcm_is_noindex_active() {
        static $result = null;
        if ( $result === null ) {
            $result = ( get_option( 'wpcm_noindex', '0' ) === '1' );
        }
        return $result;
    }
}

// Register persistent diagnostics before recovery and normal plugin hooks.
WPCM_Debug_Log::register();

// Recover a stale destructive restore transition before normal plugin hooks.
WPCM_Recovery::maybe_recover();

// Initialize.
WPCM_Plugin::instance();

endif; // class_exists WPCM_Plugin
