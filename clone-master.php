<?php
/**
 * Plugin Name: Clone Master
 * Plugin URI: https://github.com/Assistouest/clone-master
 * Description: Create resumable WordPress backups and perform staged migrations with strict validation and transactional rollback.
 * Version: 3.2.11
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
    define( 'WPCM_VERSION', '3.2.11' );
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
if ( ! defined( 'WPCM_DOWNLOAD_DIR' ) ) {
    define( 'WPCM_DOWNLOAD_DIR', WPCM_STORAGE_ROOT . 'wpcm-downloads/' );
}
if ( ! defined( 'WPCM_DOWNLOAD_URL' ) ) {
    define( 'WPCM_DOWNLOAD_URL', trailingslashit( content_url( 'wpcm-downloads' ) ) );
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
        add_action( 'init', [ $this, 'cleanup_expired_download_links' ], 1 );
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
        $this->prepare_download_directory();
        $this->prepare_recovery_kit();
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

        // Plugin updates do not always execute the activation hook. Refresh the
        // autonomous recovery kit whenever the installed version changes.
        if ( (string) get_option( 'wpcm_version', '' ) !== WPCM_VERSION ) {
            foreach ( array( WPCM_BACKUP_DIR, WPCM_TEMP_DIR, WPCM_LOG_DIR ) as $dir ) {
                if ( ! is_dir( $dir ) ) {
                    wp_mkdir_p( $dir );
                }
                self::protect_directory( $dir );
            }
            $this->prepare_download_directory();
            $this->prepare_recovery_kit();
            update_option( 'wpcm_version', WPCM_VERSION );
        }
    }

    /**
     * Publish an autonomous CLI recovery kit next to local backups.
     *
     * The kit contains only the streaming archive reader and does not bootstrap
     * WordPress. It therefore remains usable when plugins, themes or WordPress
     * itself fail during normal loading.
     *
     * @return void
     */
    public function prepare_recovery_kit(): void {
        $source = WPCM_PLUGIN_DIR . 'tools/recovery/';
        $target = WPCM_BACKUP_DIR . 'recovery-kit/';
        if ( ! is_dir( $source ) ) {
            return;
        }

        try {
            if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
                throw new RuntimeException( 'Unable to create the recovery-kit directory.' );
            }
            self::protect_directory( $target, false );

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $iterator as $item ) {
                $relative    = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source ) ) ), '/' );
                $destination = $target . $relative;
                if ( $item->isDir() ) {
                    if ( ! is_dir( $destination ) && ! wp_mkdir_p( $destination ) ) {
                        throw new RuntimeException( 'Unable to create a recovery-kit subdirectory.' );
                    }
                    continue;
                }
                $contents = @file_get_contents( $item->getPathname() );
                if ( ! is_string( $contents ) ) {
                    throw new RuntimeException( 'Unable to read a recovery-kit source file.' );
                }
                if ( ! is_dir( dirname( $destination ) ) && ! wp_mkdir_p( dirname( $destination ) ) ) {
                    throw new RuntimeException( 'Unable to create a recovery-kit parent directory.' );
                }
                WPCM_Reliability::atomic_write( $destination, $contents, 0640 );
            }

            $launcher = $target . 'wpcm-recovery.php';
            if ( is_file( $launcher ) ) {
                @chmod( $launcher, 0750 );
                WPCM_Reliability::atomic_write(
                    $launcher . '.sha256',
                    hash_file( 'sha256', $launcher ) . "\n",
                    0640
                );
            }
        } catch ( Throwable $error ) {
            update_option(
                'wpcm_activation_warning',
                sprintf(
                    /* translators: %s: recovery-kit publication error. */
                    __( 'Clone Master could not publish its autonomous recovery kit: %s', 'clone-master' ),
                    $error->getMessage()
                ),
                false
            );
        }
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

        // Content-addressed asset filenames prevent stale proxy, CDN, browser and optimization-plugin caches.
        wp_enqueue_style( 'wpcm-admin', WPCM_PLUGIN_URL . 'admin/css/admin.73a3aa2fea18.css', [], WPCM_VERSION );
        wp_enqueue_script( 'wpcm-admin', WPCM_PLUGIN_URL . 'admin/js/admin.221f5641ea8f.js', [ 'wp-element', 'wp-i18n' ], WPCM_VERSION, true );
        wp_set_script_translations( 'wpcm-admin', 'clone-master', WPCM_PLUGIN_DIR . 'languages' );

        // Schedule tab: loaded after admin.js and addressed by its content hash.
        wp_enqueue_script( 'wpcm-schedule', WPCM_PLUGIN_URL . 'admin/js/schedule-tab.5ac474ae07bc.js', [ 'wpcm-admin', 'wp-i18n' ], WPCM_VERSION, true );
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
            'pluginUrl'       => WPCM_PLUGIN_URL,
            'backupDir'       => WPCM_BACKUP_DIR,
            'recoveryKitPath' => WPCM_BACKUP_DIR . 'recovery-kit/wpcm-recovery.php',
            'sitePath'        => ABSPATH,
            'maxUpload'          => wp_max_upload_size(),
            'uploadChunkMax'     => $this->stream_upload_chunk_limit(),
            'uploadChunkInitial' => $this->stream_upload_initial_chunk(),
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

        // Raise resources only for this authenticated export request. WordPress
        // never lowers an existing host limit when wp_raise_memory_limit() runs.
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to the authenticated export request.
        }

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

    /**
     * Return the maximum request body used by the adaptive streaming uploader.
     *
     * The client stays below PHP, WordPress and common reverse-proxy limits.
     * A 64 MiB ceiling keeps requests comfortably below Cloudflare's 100 MB
     * Free/Pro limit while still amortising WordPress bootstrap overhead.
     *
     * @return int Bytes.
     */
    private function stream_upload_chunk_limit() {
        $hard_cap   = 64 * MB_IN_BYTES;
        $candidates = array( $hard_cap );
        $wp_limit   = (int) wp_max_upload_size();
        if ( $wp_limit > 0 ) {
            $candidates[] = (int) floor( $wp_limit * 0.80 );
        }
        foreach ( array( 'upload_max_filesize', 'post_max_size' ) as $setting ) {
            $value = wp_convert_hr_to_bytes( (string) ini_get( $setting ) );
            if ( is_int( $value ) && $value > 0 ) {
                $candidates[] = (int) floor( $value * 0.80 );
            }
        }
        $limit = min( $candidates );
        $step  = 256 * KB_IN_BYTES;
        $limit = (int) floor( max( $step, $limit ) / $step ) * $step;
        return max( $step, $limit );
    }

    /**
     * Initial adaptive upload request size.
     *
     * @return int Bytes.
     */
    private function stream_upload_initial_chunk() {
        $limit = $this->stream_upload_chunk_limit();
        $start = min( 16 * MB_IN_BYTES, $limit );
        if ( $limit >= 8 * MB_IN_BYTES ) {
            $start = max( 8 * MB_IN_BYTES, $start );
        }
        $step = 256 * KB_IN_BYTES;
        return max( $step, (int) floor( $start / $step ) * $step );
    }

    /**
     * Format an exact byte count for upload and validation messages.
     *
     * @param int $bytes Byte count.
     * @return string
     */
    private function format_exact_transfer_size( $bytes ) {
        $bytes = max( 0, (int) $bytes );
        if ( $bytes >= GB_IN_BYTES ) {
            $human = number_format_i18n( $bytes / GB_IN_BYTES, 2 ) . ' ' . __( 'GiB', 'clone-master' );
        } elseif ( $bytes >= MB_IN_BYTES ) {
            $human = number_format_i18n( $bytes / MB_IN_BYTES, 2 ) . ' ' . __( 'MiB', 'clone-master' );
        } elseif ( $bytes >= KB_IN_BYTES ) {
            $human = number_format_i18n( $bytes / KB_IN_BYTES, 1 ) . ' ' . __( 'KiB', 'clone-master' );
        } else {
            $human = number_format_i18n( $bytes, 0 ) . ' B';
        }
        return sprintf(
            /* translators: 1: human-readable binary size, 2: exact byte count. */
            __( '%1$s (%2$s bytes)', 'clone-master' ),
            $human,
            number_format_i18n( $bytes, 0 )
        );
    }

    /**
     * Read a positive integer sent in an upload request header.
     *
     * @param string $server_key Key in $_SERVER.
     * @return int
     */
    private function upload_header_int( $server_key ) {
        $raw = isset( $_SERVER[ $server_key ] ) ? trim( (string) wp_unslash( $_SERVER[ $server_key ] ) ) : '';
        return preg_match( '/^[0-9]+$/', $raw ) ? (int) $raw : -1;
    }

    /**
     * Send the current state of an offset-based upload.
     *
     * @param string $upload_id Upload identifier.
     * @return void
     */
    private function send_offset_upload_status( $upload_id ) {
        if ( ! preg_match( '/^upload_[a-z0-9]{20,64}$/', $upload_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid upload session identifier.', 'clone-master' ) ), 400 );
        }
        $upload_dir = WPCM_TEMP_DIR . $upload_id . '/';
        $state      = WPCM_Reliability::read_signed_json( $upload_dir . 'upload-state.json', 'chunk-upload:' . $upload_id );
        if ( ! is_array( $state ) || 'offset-v1' !== (string) ( $state['protocol'] ?? '' ) ) {
            wp_send_json_error( array( 'message' => __( 'The resumable upload session was not found.', 'clone-master' ) ), 404 );
        }
        wp_send_json_success( array(
            'upload_id'      => $upload_id,
            'expected_offset'=> (int) ( $state['offset'] ?? 0 ),
            'total_size'     => (int) ( $state['total_size'] ?? 0 ),
            'complete'       => ! empty( $state['completed'] ),
            'file_path'      => ! empty( $state['completed'] ) ? $upload_dir . (string) $state['file_name'] : '',
            'session_id'     => ! empty( $state['completed'] ) ? $upload_id : '',
            'chunk_limit'    => $this->stream_upload_chunk_limit(),
            'expires_at'     => (int) ( $state['updated_at'] ?? $state['created_at'] ?? time() ) + DAY_IN_SECONDS,
        ) );
    }

    /**
     * Handle a sequential, offset-based raw-body upload.
     *
     * Each request appends directly to one protected .partial file. This avoids
     * storing hundreds of part files and avoids rewriting the complete archive
     * during final assembly. The next import stage performs the full block-level
     * WPCM validation and extraction.
     *
     * @return void
     */
    private function ajax_import_upload_offset() {
        $nonce = isset( $_SERVER['HTTP_X_WPCM_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WPCM_NONCE'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'wpcm_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'The WordPress security session expired. Reload the page and try again.', 'clone-master' ) ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'clone-master' ) ), 403 );
        }

        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
        header( 'X-LiteSpeed-Cache-Control: no-cache', true );
        header( 'X-Accel-Expires: 0', true );

        $upload_id = isset( $_SERVER['HTTP_X_WPCM_UPLOAD_ID'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_X_WPCM_UPLOAD_ID'] ) ) : '';
        $is_status = isset( $_SERVER['HTTP_X_WPCM_UPLOAD_STATUS'] ) && '1' === trim( (string) $_SERVER['HTTP_X_WPCM_UPLOAD_STATUS'] );
        if ( $is_status ) {
            $this->send_offset_upload_status( $upload_id );
        }

        $file_name  = isset( $_SERVER['HTTP_X_WPCM_FILE_NAME'] ) ? sanitize_file_name( rawurldecode( (string) wp_unslash( $_SERVER['HTTP_X_WPCM_FILE_NAME'] ) ) ) : '';
        $file_size  = $this->upload_header_int( 'HTTP_X_WPCM_FILE_SIZE' );
        $offset     = $this->upload_header_int( 'HTTP_X_WPCM_UPLOAD_OFFSET' );
        $body_size  = isset( $_SERVER['CONTENT_LENGTH'] ) && preg_match( '/^[0-9]+$/', (string) $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : -1;
        $chunk_cap  = $this->stream_upload_chunk_limit();

        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) )
            || 'wpcm' !== strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) )
            || strlen( $file_name ) > 200
            || $file_size < strlen( WPCM_Archive::MAGIC ) + WPCM_Archive::FOOTER_BYTES
            || $offset < 0
            || $offset > $file_size
            || $body_size < 1
            || $body_size > $chunk_cap
            || $offset + $body_size > $file_size ) {
            wp_send_json_error( array(
                'message'     => __( 'Invalid resumable upload metadata or request size.', 'clone-master' ),
                'chunk_limit' => $chunk_cap,
            ), 400 );
        }

        if ( '' === $upload_id ) {
            if ( 0 !== $offset ) {
                wp_send_json_error( array( 'message' => __( 'A new upload must start at byte zero.', 'clone-master' ) ), 409 );
            }
            $upload_id = 'upload_' . strtolower( wp_generate_password( 32, false, false ) );
        }
        if ( ! preg_match( '/^upload_[a-z0-9]{20,64}$/', $upload_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid upload session identifier.', 'clone-master' ) ), 400 );
        }

        $upload_dir  = WPCM_TEMP_DIR . $upload_id . '/';
        $state_path  = $upload_dir . 'upload-state.json';
        $partial     = $upload_dir . $file_name . '.partial';
        $final_path  = $upload_dir . $file_name;
        if ( ! wp_mkdir_p( $upload_dir ) ) {
            wp_send_json_error( array( 'message' => __( 'Unable to create the protected upload workspace.', 'clone-master' ) ), 500 );
        }
        self::protect_directory( $upload_dir, false );
        $lock = WPCM_Reliability::acquire_lock( $upload_dir . 'upload.lock' );
        if ( false === $lock ) {
            wp_send_json_error( array( 'message' => __( 'Another request is writing this upload session.', 'clone-master' ) ), 409 );
        }

        $output = null;
        $input  = null;
        try {
            $context = 'chunk-upload:' . $upload_id;
            $state   = WPCM_Reliability::read_signed_json( $state_path, $context );
            if ( ! is_array( $state ) ) {
                if ( 0 !== $offset ) {
                    throw new RuntimeException( __( 'The upload session is missing; resume from the server offset.', 'clone-master' ) );
                }
                $state = array(
                    'format'      => 4,
                    'protocol'    => 'offset-v1',
                    'upload_id'   => $upload_id,
                    'file_name'   => $file_name,
                    'total_size'  => $file_size,
                    'offset'      => 0,
                    'created_at'  => time(),
                    'updated_at'  => time(),
                    'completed'   => false,
                );
            }
            if ( 'offset-v1' !== (string) ( $state['protocol'] ?? '' )
                || (string) $state['file_name'] !== $file_name
                || (int) $state['total_size'] !== $file_size ) {
                throw new RuntimeException( __( 'Upload metadata does not match the resumable session.', 'clone-master' ) );
            }
            $expected_offset = (int) ( $state['offset'] ?? 0 );
            if ( is_file( $final_path ) && (int) @filesize( $final_path ) === $file_size
                && null !== WPCM_Archive::footer_payload_hash( $final_path ) ) {
                $state['offset']       = $file_size;
                $state['completed']    = true;
                $state['completed_at'] = (int) ( $state['completed_at'] ?? time() );
                $state['updated_at']   = time();
                WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );
                $expected_offset = $file_size;
            }
            if ( $offset !== $expected_offset ) {
                wp_send_json_error( array(
                    'message'         => __( 'The upload offset changed. Clone Master can resume from the server position.', 'clone-master' ),
                    'upload_id'       => $upload_id,
                    'expected_offset' => $expected_offset,
                    'chunk_limit'     => $chunk_cap,
                ), 409 );
            }
            if ( ! empty( $state['completed'] ) && is_file( $final_path ) ) {
                wp_send_json_success( array(
                    'upload_id'       => $upload_id,
                    'expected_offset' => $file_size,
                    'complete'        => true,
                    'file_path'       => $final_path,
                    'session_id'      => $upload_id,
                    'chunk_limit'     => $chunk_cap,
                    'message'         => sprintf( __( 'Upload complete: %s received. Structural analysis is starting.', 'clone-master' ), $this->format_exact_transfer_size( $file_size ) ),
                ) );
            }

            $disk_size = is_file( $partial ) ? (int) @filesize( $partial ) : 0;
            if ( $disk_size !== $expected_offset ) {
                throw new RuntimeException( __( 'The partial upload size does not match its authenticated state.', 'clone-master' ) );
            }

            $input = @fopen( 'php://input', 'rb' );
            if ( ! is_resource( $input ) ) {
                throw new RuntimeException( __( 'Unable to read the upload request body.', 'clone-master' ) );
            }
            if ( is_link( $partial ) ) {
                throw new RuntimeException( __( 'The partial upload path is unsafe.', 'clone-master' ) );
            }
            $output = @fopen( $partial, 'c+b' );
            if ( ! is_resource( $output ) ) {
                throw new RuntimeException( __( 'Unable to open the partial WPCM upload.', 'clone-master' ) );
            }
            if ( 0 !== @fseek( $output, $expected_offset ) ) {
                throw new RuntimeException( __( 'Unable to seek to the resumable upload offset.', 'clone-master' ) );
            }

            $received     = 0;
            $prefix       = '';
            $hash_context = hash_init( 'sha256' );
            while ( $received < $body_size ) {
                $bytes = fread( $input, min( MB_IN_BYTES, $body_size - $received ) );
                if ( false === $bytes || '' === $bytes ) {
                    throw new RuntimeException( __( 'The upload request ended before the declared chunk size.', 'clone-master' ) );
                }
                if ( 0 === $expected_offset && strlen( $prefix ) < strlen( WPCM_Archive::MAGIC ) ) {
                    $prefix .= substr( $bytes, 0, strlen( WPCM_Archive::MAGIC ) - strlen( $prefix ) );
                }
                hash_update( $hash_context, $bytes );
                WPCM_Archive::write_exact( $output, $bytes );
                $received += strlen( $bytes );
            }
            if ( 0 === $expected_offset && WPCM_Archive::MAGIC !== $prefix ) {
                throw new RuntimeException( __( 'The uploaded file is not a WP Commander compatible WPCMARCHIVE2 container.', 'clone-master' ) );
            }
            WPCM_Archive::flush( $output );
            fclose( $output );
            $output = null;
            fclose( $input );
            $input = null;

            clearstatcache( true, $partial );
            $new_offset = $expected_offset + $received;
            if ( (int) @filesize( $partial ) !== $new_offset ) {
                throw new RuntimeException( __( 'The partial upload was not written completely.', 'clone-master' ) );
            }

            $state['offset']      = $new_offset;
            $state['updated_at']  = time();
            $state['last_chunk']  = array(
                'offset' => $expected_offset,
                'size'   => $received,
                'sha256' => hash_final( $hash_context ),
            );
            $complete = $new_offset === $file_size;
            if ( $complete ) {
                if ( null === WPCM_Archive::footer_payload_hash( $partial ) ) {
                    throw new RuntimeException( __( 'The received archive does not contain a valid finalized WPCM footer.', 'clone-master' ) );
                }
                if ( ! @rename( $partial, $final_path ) ) {
                    throw new RuntimeException( __( 'Unable to atomically publish the uploaded WPCM container.', 'clone-master' ) );
                }
                $state['completed']    = true;
                $state['completed_at'] = time();
            }
            WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );

            $event_id = WPCM_Debug_Log::write( 'info', $complete ? 'upload_completed' : 'upload_chunk_verified', array(
                'upload_id'       => $upload_id,
                'protocol'        => 'offset-v1',
                'offset'          => $new_offset,
                'total_size'      => $file_size,
                'chunk_size'      => $received,
                'complete'        => $complete,
            ) );
            wp_send_json_success( array(
                'event_id'        => $event_id,
                'upload_id'       => $upload_id,
                'expected_offset' => $new_offset,
                'total_size'      => $file_size,
                'chunk_size'      => $received,
                'chunk_sha256'    => (string) $state['last_chunk']['sha256'],
                'chunk_limit'     => $chunk_cap,
                'complete'        => $complete,
                'file_path'       => $complete ? $final_path : '',
                'session_id'      => $complete ? $upload_id : '',
                'message'         => $complete
                    ? sprintf( __( 'Upload complete: %s received. Structural analysis is starting.', 'clone-master' ), $this->format_exact_transfer_size( $file_size ) )
                    : sprintf( __( '%1$s received of %2$s.', 'clone-master' ), size_format( $new_offset ), size_format( $file_size ) ),
            ) );
        } catch ( Throwable $error ) {
            if ( is_resource( $output ) ) {
                @ftruncate( $output, max( 0, $offset ) );
                @fflush( $output );
                fclose( $output );
            } elseif ( isset( $partial ) && is_file( $partial ) && ! is_link( $partial ) ) {
                $rollback = @fopen( $partial, 'c+b' );
                if ( is_resource( $rollback ) ) {
                    @ftruncate( $rollback, max( 0, $offset ) );
                    @fflush( $rollback );
                    fclose( $rollback );
                }
            }
            if ( is_resource( $input ) ) {
                fclose( $input );
            }
            $event_id = WPCM_Debug_Log::throwable( 'upload_chunk_failed', $error, array(
                'upload_id'  => $upload_id,
                'protocol'   => 'offset-v1',
                'offset'     => $offset,
                'body_size'  => $body_size,
                'file_name'  => $file_name,
            ) );
            wp_send_json_error( array(
                'message'         => $error->getMessage(),
                'upload_id'       => $upload_id,
                'expected_offset' => is_array( $state ?? null ) ? (int) ( $state['offset'] ?? 0 ) : 0,
                'chunk_limit'     => $chunk_cap,
                'event_id'        => $event_id,
            ), 400 );
        } finally {
            WPCM_Reliability::release_lock( $lock );
        }
    }

    // =========================================================================
    // AJAX: Import Upload : supports chunked uploads for large files
    // =========================================================================
    public function ajax_import_upload() {
        $upload_protocol = isset( $_GET['upload_protocol'] ) ? sanitize_key( wp_unslash( $_GET['upload_protocol'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The protocol branch verifies its nonce header.
        if ( 'offset-v1' === $upload_protocol ) {
            $this->ajax_import_upload_offset();
            return;
        }

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
                    $payload_hash = WPCM_Archive::footer_payload_hash( $assembling );
                    if ( null === $payload_hash ) {
                        wp_delete_file( $assembling );
                        throw new RuntimeException( __( 'The received archive does not contain a valid finalized WPCM footer.', 'clone-master' ) );
                    }
                    if ( ! @rename( $assembling, $final_path ) ) {
                        wp_delete_file( $assembling );
                        throw new RuntimeException( __( 'Unable to atomically publish the uploaded WPCM container.', 'clone-master' ) );
                    }
                    $state['completed']      = true;
                    $state['payload_sha256'] = $payload_hash;
                    $state['completed_at']   = time();
                    WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );
                } elseif ( $complete && null === WPCM_Archive::footer_payload_hash( $final_path ) ) {
                    throw new RuntimeException( __( 'The received archive does not contain a valid finalized WPCM footer.', 'clone-master' ) );
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
                        ? sprintf( __( 'Upload complete: %s received. Structural analysis is starting.', 'clone-master' ), $this->format_exact_transfer_size( (int) filesize( $final_path ) ) )
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
            $payload_hash = WPCM_Archive::footer_payload_hash( $assembling );
            if ( null === $payload_hash ) {
                throw new RuntimeException( __( 'The received archive does not contain a valid finalized WPCM footer.', 'clone-master' ) );
            }
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
                    'payload_sha256' => $payload_hash,
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

        @set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Each adaptive request is deliberately short.
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $step        = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'analyze';
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
        if ( ! WPCM_Reliability::is_safe_archive_filename( $normalized ) ) {
            return null;
        }
        $name = $normalized;

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
            $this->remove_download_links_for_backup( $backup['name'] );
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized request.', 'clone-master' ), '', array( 'response' => 403 ) );
        }

        $raw_name = isset( $_GET['backup_name'] ) ? wp_unslash( $_GET['backup_name'] ) : '';
        $backup   = $this->resolve_local_backup( (string) $raw_name );
        if ( null === $backup ) {
            wp_die( esc_html__( 'File not found.', 'clone-master' ), '', array( 'response' => 404 ) );
        }

        // Preferred path: create a zero-copy hard link behind an unpredictable,
        // short-lived capability URL. The next request is then served directly
        // by Apache, LiteSpeed or Nginx instead of keeping PHP-FPM and an origin
        // proxy connection occupied for a multi-gigabyte response.
        $accelerated_url = $this->create_accelerated_download_url( $backup );
        if ( is_string( $accelerated_url ) && '' !== $accelerated_url ) {
            $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
            $accelerated_uri = (string) wp_parse_url( $accelerated_url, PHP_URL_PATH );
            if ( false !== stripos( $server_software, 'litespeed' ) && '' !== $accelerated_uri ) {
                $ascii_name = str_replace( array( '"', "\r", "\n" ), '', $backup['name'] );
                header( 'Content-Type: application/octet-stream' );
                header( 'Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8' . "''" . rawurlencode( $backup['name'] ) );
                header( 'X-LiteSpeed-Location: ' . $accelerated_uri );
                exit;
            }
            wp_safe_redirect( $accelerated_url, 302, 'Clone Master' );
            exit;
        }

        // Portable fallback for hosts that forbid hard links. It supports byte
        // ranges so interrupted transfers can resume instead of restarting.
        $this->stream_backup_with_range_support( $backup );
    }

    /**
     * Prepare the public capability directory used only for temporary downloads.
     * Archive sources remain in the private, denied WPCM_BACKUP_DIR directory.
     *
     * @return bool
     */
    private function prepare_download_directory(): bool {
        if ( ! is_dir( WPCM_DOWNLOAD_DIR ) && ! wp_mkdir_p( WPCM_DOWNLOAD_DIR ) ) {
            return false;
        }
        WPCM_Reliability::atomic_write( WPCM_DOWNLOAD_DIR . 'index.html', '', 0644 );
        WPCM_Reliability::atomic_write(
            WPCM_DOWNLOAD_DIR . '.htaccess',
            implode( "\n", array(
                'Options -Indexes',
                '<IfModule mod_mime.c>',
                '    ForceType application/octet-stream',
                '</IfModule>',
                '<IfModule mod_headers.c>',
                '    Header always set Content-Disposition "attachment"',
                '    Header always set Cache-Control "private, no-store, no-cache, must-revalidate, max-age=0"',
                '    Header always set X-Content-Type-Options "nosniff"',
                '    Header always set Accept-Ranges "bytes"',
                '</IfModule>',
                '',
            ) ),
            0644
        );
        return is_writable( WPCM_DOWNLOAD_DIR );
    }

    /**
     * Create a random temporary hard link for direct web-server delivery.
     *
     * @param array{name:string,path:string} $backup Resolved backup.
     * @return string|null
     */
    private function create_accelerated_download_url( array $backup ): ?string {
        if ( ! $this->prepare_download_directory() || ! function_exists( 'random_bytes' ) || ! function_exists( 'link' ) ) {
            return null;
        }
        try {
            $token = bin2hex( random_bytes( 32 ) );
        } catch ( Throwable $error ) {
            return null;
        }
        $token_dir = trailingslashit( WPCM_DOWNLOAD_DIR . $token );
        if ( ! wp_mkdir_p( $token_dir ) ) {
            return null;
        }
        WPCM_Reliability::atomic_write( $token_dir . 'index.html', '', 0644 );
        $target = $token_dir . $backup['name'];

        $linked = @link( $backup['path'], $target );
        if ( ! $linked || ! is_file( $target ) || (int) @filesize( $target ) !== (int) @filesize( $backup['path'] ) ) {
            if ( is_file( $target ) ) {
                @unlink( $target );
            }
            @unlink( $token_dir . 'index.html' );
            @rmdir( $token_dir );
            return null;
        }

        WPCM_Reliability::atomic_write(
            $token_dir . '.expires',
            (string) ( time() + $this->download_link_ttl() ) . "\n",
            0644
        );
        return WPCM_DOWNLOAD_URL . rawurlencode( $token ) . '/' . rawurlencode( $backup['name'] );
    }

    /**
     * Remove expired temporary delivery links without touching private archives.
     *
     * @return void
     */
    public function cleanup_expired_download_links(): void {
        if ( ! is_dir( WPCM_DOWNLOAD_DIR ) ) {
            return;
        }
        $last = (int) get_transient( 'wpcm_download_cleanup' );
        if ( $last > time() - HOUR_IN_SECONDS ) {
            return;
        }
        set_transient( 'wpcm_download_cleanup', time(), HOUR_IN_SECONDS );
        foreach ( glob( WPCM_DOWNLOAD_DIR . '[a-f0-9]*', GLOB_ONLYDIR ) ?: array() as $dir ) {
            $expires_file = trailingslashit( $dir ) . '.expires';
            $expires      = is_file( $expires_file ) ? (int) trim( (string) @file_get_contents( $expires_file ) ) : 0;
            $mtime        = (int) @filemtime( $dir );
            if ( ( $expires > 0 && $expires <= time() ) || ( 0 === $expires && $mtime < time() - DAY_IN_SECONDS ) ) {
                foreach ( glob( trailingslashit( $dir ) . '*' ) ?: array() as $file ) {
                    if ( is_file( $file ) || is_link( $file ) ) {
                        @unlink( $file );
                    }
                }
                @unlink( $expires_file );
                @rmdir( $dir );
            }
        }
    }

    /**
     * Remove temporary direct links for a deleted private archive.
     *
     * @param string $name Published archive basename.
     * @return void
     */
    private function remove_download_links_for_backup( string $name ): void {
        if ( ! is_dir( WPCM_DOWNLOAD_DIR ) ) {
            return;
        }
        foreach ( glob( WPCM_DOWNLOAD_DIR . '[a-f0-9]*', GLOB_ONLYDIR ) ?: array() as $dir ) {
            $target = trailingslashit( $dir ) . $name;
            if ( is_file( $target ) || is_link( $target ) ) {
                @unlink( $target );
            }
            $remaining = array_values( array_filter( glob( trailingslashit( $dir ) . '*' ) ?: array(), 'is_file' ) );
            if ( count( $remaining ) <= 1 ) {
                @unlink( trailingslashit( $dir ) . 'index.html' );
                @unlink( trailingslashit( $dir ) . '.expires' );
                @rmdir( $dir );
            }
        }
    }

    /**
     * Configurable lifetime of a capability download URL.
     *
     * @return int Seconds.
     */
    private function download_link_ttl(): int {
        return max( 15 * MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, (int) apply_filters( 'wpcm_download_link_ttl', 2 * HOUR_IN_SECONDS ) ) );
    }

    /**
     * Stream a backup with RFC 7233-style single-range support.
     *
     * @param array{name:string,path:string} $backup Resolved backup.
     * @return void
     */
    private function stream_backup_with_range_support( array $backup ): void {
        $name = $backup['name'];
        $path = $backup['path'];
        $size = @filesize( $path );
        if ( false === $size || $size < 0 ) {
            wp_die( esc_html__( 'Unable to open the backup archive.', 'clone-master' ), '', array( 'response' => 500 ) );
        }

        $mtime = (int) @filemtime( $path );
        $etag  = '"wpcm-' . dechex( (int) $size ) . '-' . dechex( max( 0, $mtime ) ) . '"';
        $start = 0;
        $end   = max( 0, (int) $size - 1 );
        $status = 200;
        $range_header = isset( $_SERVER['HTTP_RANGE'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
        $if_range     = isset( $_SERVER['HTTP_IF_RANGE'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_IF_RANGE'] ) ) : '';
        if ( '' !== $range_header && '' !== $if_range && $if_range !== $etag && ( $mtime <= 0 || strtotime( $if_range ) !== $mtime ) ) {
            $range_header = '';
        }
        if ( '' !== $range_header ) {
            if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', $range_header, $match ) || ( '' === $match[1] && '' === $match[2] ) ) {
                status_header( 416 );
                header( 'Content-Range: bytes */' . (int) $size );
                exit;
            }
            if ( '' === $match[1] ) {
                $suffix = min( (int) $size, max( 0, (int) $match[2] ) );
                $start  = max( 0, (int) $size - $suffix );
            } else {
                $start = (int) $match[1];
            }
            if ( '' !== $match[2] ) {
                $end = min( $end, (int) $match[2] );
            }
            if ( $start > $end || $start >= (int) $size ) {
                status_header( 416 );
                header( 'Content-Range: bytes */' . (int) $size );
                exit;
            }
            $status = 206;
        }

        $handle = @fopen( $path, 'rb' );
        if ( ! is_resource( $handle ) || 0 !== @fseek( $handle, $start ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            wp_die( esc_html__( 'Unable to open the backup archive.', 'clone-master' ), '', array( 'response' => 500 ) );
        }

        while ( ob_get_level() ) {
            ob_end_clean();
        }
        @ini_set( 'zlib.output_compression', 'Off' );
        @set_time_limit( 0 );
        ignore_user_abort( true );

        $length     = $end - $start + 1;
        $ascii_name = str_replace( array( '"', "\r", "\n" ), '', $name );
        status_header( $status );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8' . "''" . rawurlencode( $name ) );
        header( 'Content-Length: ' . $length );
        header( 'Accept-Ranges: bytes' );
        header( 'ETag: ' . $etag );
        if ( $mtime > 0 ) {
            header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
        }
        header( 'X-Accel-Buffering: no' );
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
        header( 'CDN-Cache-Control: no-store' );
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
        if ( 206 === $status ) {
            header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . (int) $size );
        }

        $remaining = $length;
        while ( $remaining > 0 && ! feof( $handle ) ) {
            $chunk = fread( $handle, min( 8 * MB_IN_BYTES, $remaining ) );
            if ( false === $chunk || '' === $chunk ) {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary archive bytes.
            $remaining -= strlen( $chunk );
            flush();
        }
        fclose( $handle );
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

// Register the command tree only inside WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WPCM_CLI::register();
}

endif; // class_exists WPCM_Plugin
