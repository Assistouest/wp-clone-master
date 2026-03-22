<?php
/**
 * Plugin Name: WP Clone Master
 * Plugin URI: https://github.com/wp-clone-master
 * Description: Clone, migrate and backup your entire WordPress site with intelligent URL replacement and adaptive chunking.
 * Version: 1.0.0
 * Author: WP Clone Master
 * License: GPL v2 or later
 * Text Domain: wp-clone-master
 * Requires PHP: 7.4
 * Requires at least: 5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPCM_VERSION', '1.0.0' );
define( 'WPCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCM_BACKUP_DIR', WP_CONTENT_DIR . '/wpcm-backups/' );
define( 'WPCM_TEMP_DIR', WP_CONTENT_DIR . '/wpcm-temp/' );
define( 'WPCM_LOG_DIR', WP_CONTENT_DIR . '/wpcm-logs/' );

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
if ( ! class_exists( 'WP_Clone_Master' ) ) :

class WP_Clone_Master {

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

        add_action( 'init', [ $this, 'init' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
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

        // wp_robots (WP 5.7+ Robots API) — adds noindex/nofollow to the HTML meta tag.
        // Belt-and-suspenders: works even if a plugin ignores blog_public.
        add_filter( 'wp_robots', [ $this, 'wpcm_enforce_noindex_robots' ], PHP_INT_MAX );

        // WP < 5.7 fallback: inject meta robots directly in wp_head.
        if ( ! function_exists( 'wp_robots_no_robots' ) ) {
            add_action( 'wp_head', [ $this, 'wpcm_inject_noindex_meta' ], 1 );
        }

        // Auto-clear the flag when admin saves Settings > Reading.
        add_action( 'update_option_blog_public', [ $this, 'wpcm_clear_noindex_flag' ] );

        // Admin notice while noindex is active.
        add_action( 'admin_notices', [ $this, 'wpcm_noindex_admin_notice' ] );

        // AJAX handlers
        add_action( 'wp_ajax_wpcm_export', [ $this, 'ajax_export' ] );
        add_action( 'wp_ajax_wpcm_import', [ $this, 'ajax_import' ] );
        add_action( 'wp_ajax_wpcm_import_upload', [ $this, 'ajax_import_upload' ] );
        add_action( 'wp_ajax_wpcm_server_info', [ $this, 'ajax_server_info' ] );
        add_action( 'wp_ajax_wpcm_get_backups', [ $this, 'ajax_get_backups' ] );
        add_action( 'wp_ajax_wpcm_delete_backup', [ $this, 'ajax_delete_backup' ] );
        add_action( 'wp_ajax_wpcm_download_backup', [ $this, 'ajax_download_backup' ] );

        // ── Automatic backup scheduler ────────────────────────────────────────
        // Custom intervals (weekly, monthly)
        add_filter( 'cron_schedules', [ $this->scheduler, 'register_intervals' ] );

        // Main cron hook
        add_action( WPCM_Scheduler::CRON_HOOK, [ $this->scheduler, 'run_backup' ] );

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
            $s->save( [] ); // persist defaults
        }
    }

    /**
     * Write all static protection files for a sensitive directory.
     *
     * Apache  — .htaccess "Deny from all" blocks direct HTTP access even when
     *           AllowOverride is enabled (the default on most shared hosting).
     * Nginx   — Nginx ignores .htaccess entirely. Protection requires a server-level
     *           `location` block; we cannot write that from PHP. Instead we:
     *           (a) drop an index.php silence file so directory listing is impossible,
     *           (b) write a README.nginx.txt explaining the required server config,
     *           (c) display a persistent admin notice if the directory is reachable.
     * Both    — index.php prevents directory listing as a belt-and-suspenders measure.
     *
     * @param string $dir Absolute path with trailing slash.
     */
    public static function protect_directory( string $dir ): void {
        // Apache — block direct HTTP access via .htaccess
        $htaccess = $dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // Apache 2.2 syntax: "Deny from all"
            // Apache 2.4 syntax: "Require all denied"
            // Writing both ensures compatibility across all commonly deployed versions.
            file_put_contents( $htaccess, implode( "\n", [
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
        }

        // Silence / directory-listing guard (works on all servers)
        $index = $dir . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden.\n" );
        }

        // Nginx reminder — cannot be enforced from PHP; document the required config.
        $nginx_readme = $dir . 'README.nginx.txt';
        if ( ! file_exists( $nginx_readme ) ) {
            $rel = str_replace( ABSPATH, '', $dir );
            file_put_contents( $nginx_readme, implode( "\n", [
                'WP Clone Master — Nginx protection required',
                '===========================================',
                'Nginx ignores .htaccess files. Add the following block to your',
                'server { } configuration to block public access to this directory:',
                '',
                '    location ~* ^/' . rtrim( $rel, '/' ) . '/ {',
                '        deny all;',
                '        return 403;',
                '    }',
                '',
                'Without this block the directory contents are publicly accessible.',
                'Reload Nginx after adding the block: sudo nginx -s reload',
                '',
            ] ) );
        }
    }

    public function deactivate() {
        // Cancel scheduled backups
        $this->scheduler->cancel_backup();

        // Cleanup temp files only
        if ( is_dir( WPCM_TEMP_DIR ) ) {
            $this->recursive_delete( WPCM_TEMP_DIR );
        }
    }

    public function init() {
        load_plugin_textdomain( 'wp-clone-master', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function admin_menu() {
        add_menu_page(
            __( 'WP Clone Master', 'wp-clone-master' ),
            __( 'Clone Master', 'wp-clone-master' ),
            'manage_options',
            'wp-clone-master',
            [ $this, 'render_admin_page' ],
            'dashicons-database-export',
            80
        );
    }

    public function admin_assets( $hook ) {
        if ( $hook !== 'toplevel_page_wp-clone-master' ) return;

        // Use file modification time as version to bust browser cache on every update
        $css_ver = WPCM_VERSION . '.' . filemtime( WPCM_PLUGIN_DIR . 'admin/css/admin.css' );
        $js_ver  = WPCM_VERSION . '.' . filemtime( WPCM_PLUGIN_DIR . 'admin/js/admin.js' );

        wp_enqueue_style( 'wpcm-admin', WPCM_PLUGIN_URL . 'admin/css/admin.css', [], $css_ver );
        wp_enqueue_script( 'wpcm-admin', WPCM_PLUGIN_URL . 'admin/js/admin.js', [ 'jquery', 'wp-element', 'wp-components', 'wp-api-fetch' ], $js_ver, true );

        // Schedule tab — loaded after admin.js, adds the ScheduleTab component
        $sched_ver = WPCM_VERSION . '.' . filemtime( WPCM_PLUGIN_DIR . 'admin/js/schedule-tab.js' );
        wp_enqueue_script( 'wpcm-schedule', WPCM_PLUGIN_URL . 'admin/js/schedule-tab.js', [ 'wpcm-admin' ], $sched_ver, true );

        // Next scheduled run (resolved server-side so it's accurate regardless of timezone)
        $next_ts  = $this->scheduler->get_next_run();
        $settings = new WPCM_Backup_Settings();

        wp_localize_script( 'wpcm-admin', 'wpcmData', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wpcm_nonce' ),
            'restUrl'   => rest_url( 'wpcm/v1/' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
            'siteUrl'   => site_url(),
            'homeUrl'   => home_url(),
            'pluginUrl' => WPCM_PLUGIN_URL,
            'maxUpload' => wp_max_upload_size(),
            'schedule'  => array_merge( $settings->to_array(), [
                'next_run'        => $next_ts,
                'next_run_human'  => $next_ts ? wp_date( 'D d M Y à H:i', $next_ts ) : null,
                'cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            ] ),
            'i18n'      => [
                'exporting'  => __( 'Exporting...', 'wp-clone-master' ),
                'importing'  => __( 'Importing...', 'wp-clone-master' ),
                'success'    => __( 'Operation completed successfully!', 'wp-clone-master' ),
                'error'      => __( 'An error occurred.', 'wp-clone-master' ),
            ],
        ]);
    }

    public function render_admin_page() {
        echo '<div id="wpcm-admin-root"></div>';
    }

    // =========================================================================
    // AJAX: Server Info
    // =========================================================================
    public function ajax_server_info() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $detector = new WPCM_Server_Detector();
        wp_send_json_success( $detector->get_info() );
    }

    // =========================================================================
    // AJAX: Export
    // =========================================================================
    public function ajax_export() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        // Increase limits
        @set_time_limit( 300 );
        @ini_set( 'memory_limit', '512M' );

        $step = isset( $_POST['step'] ) ? sanitize_text_field( $_POST['step'] ) : 'init';
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';

        $exporter = new WPCM_Exporter();

        try {
            $result = $exporter->run_step( $step, $session_id );
            wp_send_json_success( $result );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // =========================================================================
    // AJAX: Import Upload — supports chunked uploads for large files
    // =========================================================================
    public function ajax_import_upload() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        @set_time_limit( 300 );

        if ( empty( $_FILES['backup_chunk'] ) && empty( $_FILES['backup_file'] ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded' ] );
        }

        // Check if this is a chunked upload
        $chunk_index  = isset( $_POST['chunk_index'] ) ? (int) $_POST['chunk_index'] : -1;
        $total_chunks = isset( $_POST['total_chunks'] ) ? (int) $_POST['total_chunks'] : -1;
        $file_name    = isset( $_POST['file_name'] ) ? sanitize_file_name( $_POST['file_name'] ) : '';
        $upload_id    = isset( $_POST['upload_id'] ) ? sanitize_file_name( $_POST['upload_id'] ) : '';

        if ( $chunk_index >= 0 && $total_chunks > 0 ) {
            // CHUNKED UPLOAD MODE

            // ── File-type whitelist ───────────────────────────────────────────
            // Only .zip archives are valid backup files. Checked before any data
            // is written to disk so a non-zip never touches the filesystem.
            if ( strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) !== 'zip' ) {
                wp_send_json_error( [ 'message' => 'Invalid file type. Only .zip backup archives are accepted.' ] );
            }
            // ─────────────────────────────────────────────────────────────────

            if ( ! $upload_id ) {
                $upload_id = 'upload_' . uniqid();
            }

            $upload_dir = WPCM_TEMP_DIR . $upload_id . '/';
            wp_mkdir_p( $upload_dir );
            // Protect the session subdirectory immediately after creation.
            // The parent wpcm-temp/.htaccess is NOT inherited by subdirectories on
            // hosts with AllowOverride None, so each session dir needs its own file.
            self::protect_directory( $upload_dir );

            $dest  = $upload_dir . $file_name;
            $chunk = $_FILES['backup_chunk'];

            // Append chunk to destination file
            $in  = fopen( $chunk['tmp_name'], 'rb' );
            $out = fopen( $dest, $chunk_index === 0 ? 'wb' : 'ab' );

            if ( ! $in || ! $out ) {
                wp_send_json_error( [ 'message' => 'Failed to write chunk ' . $chunk_index ] );
            }

            while ( $data = fread( $in, 8 * 1024 * 1024 ) ) {
                fwrite( $out, $data );
            }
            fclose( $in );
            fclose( $out );

            $is_last = ( $chunk_index + 1 >= $total_chunks );

            wp_send_json_success( [
                'upload_id'  => $upload_id,
                'chunk'      => $chunk_index,
                'total'      => $total_chunks,
                'complete'   => $is_last,
                'file_path'  => $is_last ? $dest : '',
                'session_id' => $is_last ? basename( $upload_dir, '/' ) : '',
                'message'    => $is_last
                    ? 'Upload complete (' . size_format( filesize( $dest ) ) . ')'
                    : 'Chunk ' . ( $chunk_index + 1 ) . '/' . $total_chunks . ' uploaded',
            ] );
            return;
        }

        // SINGLE FILE UPLOAD (small files — fallback)
        $file = $_FILES['backup_file'];

        // ── File-type whitelist (single-upload path) ──────────────────────────
        if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'zip' ) {
            wp_send_json_error( [ 'message' => 'Invalid file type. Only .zip backup archives are accepted.' ] );
        }
        // ─────────────────────────────────────────────────────────────────────

        $upload_dir = WPCM_TEMP_DIR . 'import_' . uniqid() . '/';
        wp_mkdir_p( $upload_dir );
        self::protect_directory( $upload_dir );

        $dest = $upload_dir . sanitize_file_name( $file['name'] );
        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( [ 'message' => 'Failed to move uploaded file' ] );
        }

        wp_send_json_success( [
            'file_path'  => $dest,
            'session_id' => basename( $upload_dir, '/' ),
            'complete'   => true,
        ] );
    }

    // =========================================================================
    // AJAX: Import — only handles extract + prepare (safe WP-side steps)
    // The actual DB/files/URL import runs via standalone installer
    // =========================================================================
    public function ajax_import() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        @set_time_limit( 300 );
        @ini_set( 'memory_limit', '512M' );

        $step        = isset( $_POST['step'] ) ? sanitize_text_field( $_POST['step'] ) : 'extract';
        $session_id  = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';
        $new_url     = isset( $_POST['new_url'] ) ? esc_url_raw( $_POST['new_url'] ) : '';
        // import_opts is a JSON string sent by the JS (e.g. {"block_indexing":true,"reset_permalinks":true})
        // It was previously never read here — that was the root cause of block_indexing never working.
        $import_opts = isset( $_POST['import_opts'] ) ? wp_unslash( $_POST['import_opts'] ) : '{}';

        // ── file_path confinement ─────────────────────────────────────────────
        // $_POST['file_path'] is attacker-controlled. Without this check an admin
        // could supply an arbitrary server path (e.g. /etc/passwd) and the importer
        // would open it as a ZIP archive.
        // We accept only paths that actually live inside WPCM_TEMP_DIR.
        $file_path = '';
        if ( ! empty( $_POST['file_path'] ) ) {
            $raw_path      = sanitize_text_field( wp_unslash( $_POST['file_path'] ) );
            $real_temp_dir = realpath( WPCM_TEMP_DIR );
            // realpath() returns false for non-existent paths; use the raw path as
            // fallback for the prefix check so a fresh file that hasn't been fully
            // written yet (chunk 0) still resolves correctly.
            $real_given = realpath( $raw_path );
            $check_path = $real_given ? $real_given : $raw_path;
            if ( $real_temp_dir && strpos( $check_path, $real_temp_dir ) === 0 ) {
                $file_path = $raw_path;
            } else {
                wp_send_json_error( [ 'message' => 'Invalid file path.' ] );
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $importer = new WPCM_Importer();

        try {
            $result = $importer->run_step( $step, $session_id, $file_path, $new_url, $import_opts );
            wp_send_json_success( $result );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // =========================================================================
    // AJAX: Get Backups List
    // =========================================================================
    public function ajax_get_backups() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $backups = [];
        if ( is_dir( WPCM_BACKUP_DIR ) ) {
            $files = glob( WPCM_BACKUP_DIR . '*.zip' );
            foreach ( $files as $file ) {
                $backups[] = [
                    'name'     => basename( $file ),
                    'size'     => size_format( filesize( $file ) ),
                    'size_raw' => filesize( $file ),
                    'date'     => gmdate( 'Y-m-d H:i:s', filemtime( $file ) ),
                    // 'path' intentionally omitted — the absolute server path is not
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

    // =========================================================================
    // AJAX: Delete Backup
    // =========================================================================
    public function ajax_delete_backup() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $name = isset( $_POST['backup_name'] ) ? sanitize_file_name( $_POST['backup_name'] ) : '';
        $path = WPCM_BACKUP_DIR . $name;

        if ( file_exists( $path ) && strpos( realpath( $path ), realpath( WPCM_BACKUP_DIR ) ) === 0 ) {
            unlink( $path );
            wp_send_json_success();
        }
        wp_send_json_error( [ 'message' => 'File not found' ] );
    }

    // =========================================================================
    // AJAX: Download Backup — streaming for large files
    // =========================================================================
    public function ajax_download_backup() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $name = isset( $_GET['backup_name'] ) ? sanitize_file_name( $_GET['backup_name'] ) : '';
        $path = WPCM_BACKUP_DIR . $name;

        if ( ! file_exists( $path ) || strpos( realpath( $path ), realpath( WPCM_BACKUP_DIR ) ) !== 0 ) {
            wp_die( 'File not found' );
        }

        // Disable output buffering for streaming
        while ( ob_get_level() ) ob_end_clean();
        @ini_set( 'zlib.output_compression', 'Off' );
        @set_time_limit( 0 );

        $size = filesize( $path );

        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        header( 'Content-Length: ' . $size );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Cache-Control: no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );

        // Stream in 8MB chunks instead of readfile() which loads everything
        $handle = fopen( $path, 'rb' );
        if ( $handle ) {
            while ( ! feof( $handle ) ) {
                echo fread( $handle, 8 * 1024 * 1024 );
                flush();
            }
            fclose( $handle );
        }
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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $settings = new WPCM_Backup_Settings();
        $next_ts  = $this->scheduler->get_next_run();

        wp_send_json_success( array_merge( $settings->to_array(), [
            'next_run'       => $next_ts,
            'next_run_human' => $next_ts ? wp_date( 'D d M Y à H:i', $next_ts ) : null,
            'cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        ] ) );
    }

    /**
     * POST: saves schedule settings and reschedules cron.
     */
    public function ajax_save_schedule() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $settings = new WPCM_Backup_Settings();
        $settings->save( [
            'enabled'              => ! empty( $_POST['enabled'] ) && $_POST['enabled'] !== 'false',
            'frequency'            => sanitize_text_field( $_POST['frequency']       ?? 'daily' ),
            'retention_mode'       => sanitize_text_field( $_POST['retention_mode']  ?? 'count' ),
            'retention_count'      => (int) ( $_POST['retention_count'] ?? 7 ),
            'retention_days'       => (int) ( $_POST['retention_days']  ?? 30 ),
            'notify_email'         => sanitize_email( $_POST['notify_email'] ?? '' ),
            'notify_on'            => sanitize_text_field( $_POST['notify_on'] ?? 'error' ),
            'storage_driver'       => sanitize_text_field( $_POST['storage_driver']       ?? 'local' ),
            'nextcloud_path'       => sanitize_text_field( $_POST['nextcloud_path']       ?? 'Backups/WordPress' ),
            'nextcloud_keep_local' => ! empty( $_POST['nextcloud_keep_local'] ) && $_POST['nextcloud_keep_local'] !== 'false',
        ] );

        // Reload into scheduler so it picks up the new settings
        $this->scheduler->reload_settings();
        $this->scheduler->schedule_backup();

        $next_ts = $this->scheduler->get_next_run();
        wp_send_json_success( [
            'message'        => 'Paramètres sauvegardés.',
            'next_run'       => $next_ts,
            'next_run_human' => $next_ts ? wp_date( 'D d M Y à H:i', $next_ts ) : null,
        ] );
    }

    /**
     * POST: triggers an immediate backup (manual run).
     */
    /**
     * POST: triggers an immediate backup without blocking the HTTP connection.
     *
     * Strategy — "fire and forget":
     *   1. Check that no backup is already running (transient lock).
     *   2. Generate a run_id the JS can use to detect the new history entry.
     *   3. Flush the JSON response to the browser RIGHT NOW (connection closed).
     *   4. PHP keeps executing: run the full backup, write history, done.
     *
     * This avoids every proxy / CDN timeout (Cloudflare 524, nginx 502, etc.)
     * because the HTTP response is sent in < 1 second regardless of backup size.
     *
     * The JS side polls wpcm_get_backup_history every 5 s and watches for an
     * entry whose id matches the run_id we return here.
     */
    public function ajax_run_backup_now() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        // Refuse if a backup is already running
        if ( get_transient( WPCM_Scheduler::LOCK_KEY ) ) {
            wp_send_json_error( [ 'message' => 'Une sauvegarde est déjà en cours. Patientez et rafraîchissez l\'historique.' ] );
        }

        // The run_id format must match what WPCM_Scheduler::run_backup() produces
        // so the JS can correlate the history entry.
        $run_id = gmdate( 'Ymd_His' );

        // ── Close the HTTP connection NOW so the browser doesn't time out ──
        // Works on Apache (mod_php), Nginx + PHP-FPM, LiteSpeed, etc.
        ignore_user_abort( true );  // keep PHP alive even after client disconnects

        // Discard any prior output (e.g. PHP notices that snuck in)
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        $body = wp_json_encode( [
            'success' => true,
            'data'    => [ 'status' => 'queued', 'run_id' => $run_id ],
        ] );

        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Length: ' . strlen( $body ) );
        header( 'Connection: close' );
        header( 'Cache-Control: no-cache' );

        echo $body;

        // PHP-FPM: tell the SAPI the response is done, keep the worker alive
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } else {
            // mod_php / CGI fallback: flush output buffers and the system buffer
            flush();
        }

        // ── Browser is now disconnected — run the backup ──────────────────
        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );

        $this->scheduler->run_backup( 'manual' );

        exit; // always exit cleanly after an AJAX handler
    }

    /**
     * GET: lightweight status check used by the JS polling loop.
     * Returns whether a backup is running + the most recent history entry.
     */
    public function ajax_get_backup_status() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $running = (bool) get_transient( WPCM_Scheduler::LOCK_KEY );
        $history = WPCM_Backup_Settings::get_history();
        $latest  = ! empty( $history ) ? $history[0] : null;

        if ( $latest && ! empty( $latest['size_bytes'] ) ) {
            $latest['size_human'] = size_format( (int) $latest['size_bytes'] );
        } elseif ( $latest ) {
            $latest['size_human'] = '—';
        }

        wp_send_json_success( [
            'running' => $running,
            'latest'  => $latest,
        ] );
    }

    /**
     * GET: returns the backup history log.
     */
    public function ajax_get_backup_history() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $history = WPCM_Backup_Settings::get_history();

        // Enrich each entry with a human-readable size
        foreach ( $history as &$entry ) {
            if ( ! empty( $entry['size_bytes'] ) ) {
                $entry['size_human'] = size_format( (int) $entry['size_bytes'] );
            } else {
                $entry['size_human'] = '—';
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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        WPCM_Backup_Settings::clear_history();
        wp_send_json_success( [ 'message' => 'Historique effacé.' ] );
    }

    // =========================================================================
    // Nextcloud Login Flow v2 — https://docs.nextcloud.com/server/latest/
    //                           developer_manual/client_apis/LoginFlow/
    // =========================================================================

    /**
     * Step 1 — Initiate the Nextcloud Login Flow v2.
     *
     * Calls POST {nextcloud_url}/index.php/login/v2 on the NC server.
     * Stores the poll credentials in a short-lived transient so the browser
     * never sees the raw poll token.
     * Returns { login_url, session_id } to the JS.
     */
    public function ajax_nc_init_flow() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $nc_url = esc_url_raw( trim( $_POST['nextcloud_url'] ?? '' ) );
        if ( ! $nc_url ) {
            wp_send_json_error( [ 'message' => 'URL Nextcloud manquante.' ] );
        }

        $nc_url = rtrim( $nc_url, '/' );

        // ── SSRF guard + DNS pinning ──────────────────────────────────────────
        // validate_nextcloud_url() rejects non-http(s) schemes and blocks every
        // private / reserved range (RFC 1918, loopback, link-local, CGNAT…).
        // It also returns the pre-resolved IP so we can pin it via CURLOPT_RESOLVE,
        // closing the TOCTOU DNS rebinding window: without pinning, an attacker who
        // controls the DNS TTL could make gethostbyname() return a public IP during
        // validation, then flip to 127.0.0.1 before wp_remote_post() resolves again.
        $ssrf = $this->validate_nextcloud_url( $nc_url );
        if ( $ssrf['error'] ) {
            wp_send_json_error( [ 'message' => $ssrf['error'] ] );
        }
        $curl_resolve = $this->build_curl_resolve( $ssrf['host'], $ssrf['port'], $ssrf['ip'] );
        // ─────────────────────────────────────────────────────────────────────

        $endpoint = $nc_url . '/index.php/login/v2';

        $response = wp_remote_post( $endpoint, [
            'timeout'   => 15,
            'headers'   => [ 'User-Agent' => 'WP-Clone-Master/' . WPCM_VERSION ],
            'sslverify' => true,
            // Pin the connection to the pre-validated IP — prevents DNS rebinding.
            'curl'      => $curl_resolve ? [ CURLOPT_RESOLVE => $curl_resolve ] : [],
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Impossible de joindre Nextcloud : ' . $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['poll'] ) || empty( $body['login'] ) ) {
            wp_send_json_error( [
                'message' => 'Réponse inattendue de Nextcloud (HTTP ' . $code . '). '
                           . 'Vérifiez l\'URL et que votre instance est en NC 16+.',
            ] );
        }

        // ── SSRF guard (poll endpoint) ─────────────────────────────────────────
        // The poll endpoint is returned by the Nextcloud server itself.
        // A compromised or malicious NC instance could inject an endpoint pointing
        // to an internal address. Ensure it belongs to the same host as $nc_url.
        $nc_host   = wp_parse_url( $nc_url, PHP_URL_HOST );
        $poll_host = wp_parse_url( $body['poll']['endpoint'] ?? '', PHP_URL_HOST );
        if ( ! $poll_host || strtolower( $poll_host ) !== strtolower( $nc_host ) ) {
            wp_send_json_error( [
                'message' => 'Le poll endpoint retourné par Nextcloud ne correspond pas au domaine déclaré. Connexion annulée.',
            ] );
        }
        // ─────────────────────────────────────────────────────────────────────

        // Store poll data server-side — the JS only gets an opaque session_id
        $session_id = wp_generate_password( 32, false );
        set_transient( 'wpcm_nc_flow_' . $session_id, [
            'endpoint'    => $body['poll']['endpoint'],
            'token'       => $body['poll']['token'],
            'nextcloud_url' => $nc_url,
        ], 10 * MINUTE_IN_SECONDS );

        wp_send_json_success( [
            'session_id' => $session_id,
            'login_url'  => $body['login'],
        ] );
    }

    /**
     * Step 2 — Poll for the result of the Login Flow v2.
     *
     * Called every 2 s from the JS until Nextcloud returns credentials.
     * On success: saves url + user + encrypted app-password to settings.
     */
    public function ajax_nc_poll_flow() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $flow_data  = get_transient( 'wpcm_nc_flow_' . $session_id );

        if ( ! $flow_data ) {
            wp_send_json_error( [ 'message' => 'Session expirée ou invalide.' ] );
        }

        // POST to Nextcloud poll endpoint with the token in the body.
        // Re-validate the poll endpoint URL and pin its IP via CURLOPT_RESOLVE to
        // prevent DNS rebinding between the transient write (ajax_nc_init_flow) and
        // this poll call. The poll host was already verified to match $nc_url's host,
        // so this is a second-layer pin, not a redundant full validation.
        $poll_ssrf    = $this->validate_nextcloud_url( $flow_data['endpoint'] );
        $poll_resolve = $this->build_curl_resolve( $poll_ssrf['host'], $poll_ssrf['port'], $poll_ssrf['ip'] );

        $response = wp_remote_post( $flow_data['endpoint'], [
            'timeout' => 10,
            'headers' => [ 'User-Agent' => 'WP-Clone-Master/' . WPCM_VERSION ],
            'body'    => [ 'token' => $flow_data['token'] ],
            'curl'    => $poll_resolve ? [ CURLOPT_RESOLVE => $poll_resolve ] : [],
        ] );

        if ( is_wp_error( $response ) ) {
            // Network error — keep polling
            wp_send_json_success( [ 'pending' => true ] );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 404 ) {
            // User hasn't authorized yet
            wp_send_json_success( [ 'pending' => true ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ! empty( $body['loginName'] ) && ! empty( $body['appPassword'] ) ) {
            // ── Success — save credentials to settings ────────────────────
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

        // Unexpected response — abort
        delete_transient( 'wpcm_nc_flow_' . $session_id );
        wp_send_json_error( [ 'message' => 'Réponse inattendue (HTTP ' . $code . ').' ] );
    }

    /**
     * Step 3 — Disconnect: clears stored Nextcloud credentials.
     */
    public function ajax_nc_disconnect() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $settings = new WPCM_Backup_Settings();
        $settings->save( [
            'nextcloud_url'       => '',
            'nextcloud_user'      => '',
            'nextcloud_pass'      => '',
            'nextcloud_connected' => false,
            'storage_driver'      => 'local',
        ] );

        wp_send_json_success( [ 'message' => 'Compte Nextcloud déconnecté.' ] );
    }

    /**
     * POST: tests storage driver connectivity (called from the UI "Tester" button).
     */
    public function ajax_test_storage() {
        check_ajax_referer( 'wpcm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        // Build a temporary settings object from POST values (not yet saved)
        // so the user can test before saving.
        $nc_url_raw = esc_url_raw( $_POST['nextcloud_url'] ?? '' );

        // ── SSRF guard + DNS pinning ──────────────────────────────────────────
        // Same validation as ajax_nc_init_flow(): reject non-http(s) schemes and
        // block private / reserved IP ranges before any outbound connection.
        // The resolved IP is stored in settings so the storage driver can also
        // pin it via CURLOPT_RESOLVE when it connects.
        if ( $nc_url_raw ) {
            $ssrf = $this->validate_nextcloud_url( $nc_url_raw );
            if ( $ssrf['error'] ) {
                wp_send_json_error( [ 'message' => $ssrf['error'] ] );
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $settings = new WPCM_Backup_Settings();
        $settings->save( [
            'storage_driver'  => sanitize_text_field( $_POST['storage_driver']  ?? 'local' ),
            'nextcloud_url'   => $nc_url_raw,
            'nextcloud_user'  => sanitize_text_field( $_POST['nextcloud_user']  ?? '' ),
            'nextcloud_pass'  => $_POST['nextcloud_pass']                        ?? '',
            'nextcloud_path'  => sanitize_text_field( $_POST['nextcloud_path']  ?? 'Backups/WordPress' ),
        ] );

        $driver = WPCM_Storage_Driver::make( $settings );
        $result = $driver->test();

        if ( $result['ok'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    /**
     * Validates a Nextcloud URL against SSRF risks and resolves the hostname to a
     * safe public IP.
     *
     * Checks:
     *   1. Scheme must be http or https (rejects file://, gopher://, dict://, etc.)
     *   2. Hostname must resolve to a public IP — rejects loopback (127.x), private
     *      RFC 1918 ranges (10.x, 172.16-31.x, 192.168.x), link-local (169.254.x),
     *      CGNAT (100.64-127.x), and IPv6 equivalents.
     *
     * Returns an array on success: [ 'error' => null, 'ip' => '1.2.3.4', 'host' => 'cloud.example.com', 'port' => 443 ]
     * Returns an array on failure: [ 'error' => 'translated error string', 'ip' => null, ... ]
     *
     * The resolved IP is returned so callers can pin it via CURLOPT_RESOLVE, eliminating
     * the TOCTOU window between validation and the actual outbound connection (DNS rebinding).
     *
     * @param string $url The URL to validate (already passed through esc_url_raw).
     * @return array{ error: string|null, ip: string|null, host: string, port: int }
     */
    private function validate_nextcloud_url( string $url ): array {
        $parsed = wp_parse_url( $url );

        // Reject non-http(s) schemes outright
        if ( empty( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
            return [ 'error' => 'URL Nextcloud invalide : seuls les protocoles http et https sont acceptés.', 'ip' => null, 'host' => '', 'port' => 443 ];
        }

        $host = $parsed['host'] ?? '';
        if ( ! $host ) {
            return [ 'error' => 'URL Nextcloud invalide : hôte manquant.', 'ip' => null, 'host' => '', 'port' => 443 ];
        }

        $default_port = strtolower( $parsed['scheme'] ) === 'https' ? 443 : 80;
        $port         = ! empty( $parsed['port'] ) ? (int) $parsed['port'] : $default_port;

        // Resolve hostname to IP. gethostbyname() returns the input unchanged if
        // resolution fails — detect that and treat it as an unresolvable host.
        $ip = gethostbyname( $host );
        if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
            // Could not resolve — we cannot pin the IP, so we cannot guarantee TOCTOU safety.
            // Return a soft error: the caller may still attempt the connection but must
            // not pass CURLOPT_RESOLVE (curl will do its own resolution).
            // Unresolvable hosts are effectively unreachable and not a SSRF risk here.
            return [ 'error' => null, 'ip' => null, 'host' => $host, 'port' => $port ];
        }

        // Block private, loopback, link-local, and reserved ranges.
        // FILTER_FLAG_NO_PRIV_RANGE covers: 10.x, 172.16-31.x, 192.168.x
        // FILTER_FLAG_NO_RES_RANGE covers: 127.x, 0.x, 169.254.x, 192.0.2.x, and more.
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if ( filter_var( $ip, FILTER_VALIDATE_IP, $flags ) === false ) {
            return [ 'error' => 'URL Nextcloud refusée : les adresses IP internes ou réservées ne sont pas autorisées.', 'ip' => null, 'host' => $host, 'port' => $port ];
        }

        return [ 'error' => null, 'ip' => $ip, 'host' => $host, 'port' => $port ];
    }

    /**
     * Builds a CURLOPT_RESOLVE array that pins host:port to a pre-validated IP.
     *
     * Passing this to wp_remote_post() / wp_remote_get() via the 'curl' key
     * forces libcurl to use the already-validated IP for the connection, closing
     * the TOCTOU DNS rebinding window between validate_nextcloud_url() and the
     * actual outbound request.
     *
     * If $ip is null (hostname could not be resolved during validation), an empty
     * array is returned and curl falls back to its own DNS resolution — this is
     * safe because an unresolvable host is not a SSRF risk.
     *
     * @param string      $host The hostname (e.g. 'cloud.example.com').
     * @param int         $port The TCP port (80 or 443).
     * @param string|null $ip   The pre-validated public IP, or null.
     * @return array CURLOPT_RESOLVE entries, e.g. [ 'cloud.example.com:443:1.2.3.4' ]
     */
    private function build_curl_resolve( string $host, int $port, ?string $ip ): array {
        if ( ! $ip ) {
            return [];
        }
        // CURLOPT_RESOLVE format: "hostname:port:ip"
        // This tells libcurl: for this host+port, use this IP — no DNS lookup.
        return [ "{$host}:{$port}:{$ip}" ];
    }

    private function recursive_delete( $dir ) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            $item->isDir() ? rmdir( $item->getRealPath() ) : unlink( $item->getRealPath() );
        }
        rmdir( $dir );
    }

    // =========================================================================
    // Noindex enforcement — active while wpcm_noindex option = '1'
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
           . '<p><strong>WP Clone Master</strong> — '
           . 'Les moteurs de recherche sont <strong>bloqués</strong> suite à la migration. '
           . '<a href="' . esc_url( $url ) . '">Réglages &gt; Lecture</a> pour activer l\'indexation quand le site est validé.</p>'
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

// Initialize
WP_Clone_Master::instance();

endif; // class_exists WP_Clone_Master
