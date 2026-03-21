<?php
/**
 * Server Detector — Scans hosting environment constraints
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPCM_Server_Detector {

    public function get_info() {
        return [
            'php'        => $this->php_info(),
            'mysql'      => $this->mysql_info(),
            'wordpress'  => $this->wp_info(),
            'server'     => $this->server_info(),
            'limits'     => $this->limits_info(),
            'disk'       => $this->disk_info(),
            'extensions' => $this->extensions_info(),
            'writable'   => $this->writable_info(),
            'features'   => $this->features_info(),
        ];
    }

    private function php_info() {
        return [
            'version'     => phpversion(),
            'sapi'        => php_sapi_name(),
            'os'          => PHP_OS,
            '64bit'       => PHP_INT_SIZE === 8,
        ];
    }

    private function mysql_info() {
        global $wpdb;
        return [
            'version'       => $wpdb->db_version(),
            'server_info'   => $wpdb->db_server_info(),
            'charset'       => $wpdb->charset,
            'collate'       => $wpdb->collate,
            'prefix'        => $wpdb->prefix,
            'table_count'   => count( $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" ) ),
            'total_size'    => $this->get_db_size(),
        ];
    }

    private function get_db_size() {
        global $wpdb;
        $size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
                DB_NAME,
                $wpdb->prefix . '%'
            )
        );
        return (int) $size;
    }

    private function wp_info() {
        global $wp_version;
        $plugins = get_plugins();
        $active = get_option( 'active_plugins', [] );
        $theme = wp_get_theme();

        return [
            'version'        => $wp_version,
            'multisite'      => is_multisite(),
            'plugins_total'  => count( $plugins ),
            'plugins_active' => count( $active ),
            'theme'          => $theme->get( 'Name' ),
            'theme_version'  => $theme->get( 'Version' ),
            'child_theme'    => $theme->parent() ? true : false,
            'parent_theme'   => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
            'permalink'      => get_option( 'permalink_structure' ),
            'site_url'       => site_url(),
            'home_url'       => home_url(),
            'content_dir'    => WP_CONTENT_DIR,
            'abspath'        => ABSPATH,
            'uploads_dir'    => wp_upload_dir()['basedir'],
            'uploads_size'   => $this->dir_size( wp_upload_dir()['basedir'] ),
        ];
    }

    private function server_info() {
        $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown';
        $type = 'unknown';
        if ( stripos( $server_software, 'apache' ) !== false ) $type = 'apache';
        elseif ( stripos( $server_software, 'nginx' ) !== false ) $type = 'nginx';
        elseif ( stripos( $server_software, 'litespeed' ) !== false ) $type = 'litespeed';

        return [
            'software' => $server_software,
            'type'     => $type,
            'hostname' => gethostname(),
        ];
    }

    private function limits_info() {
        return [
            'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
            'memory_limit'        => $this->return_bytes( ini_get( 'memory_limit' ) ),
            'memory_limit_human'  => ini_get( 'memory_limit' ),
            'upload_max_filesize' => $this->return_bytes( ini_get( 'upload_max_filesize' ) ),
            'post_max_size'       => $this->return_bytes( ini_get( 'post_max_size' ) ),
            'max_input_vars'      => (int) ini_get( 'max_input_vars' ),
            'wp_max_upload'       => wp_max_upload_size(),
            'wp_max_upload_human' => size_format( wp_max_upload_size() ),
            // Computed recommended chunk sizes
            'recommended_chunk'   => $this->compute_chunk_size(),
        ];
    }

    private function compute_chunk_size() {
        $memory = $this->return_bytes( ini_get( 'memory_limit' ) );
        $upload = wp_max_upload_size();
        // Use 25% of memory or upload limit, whichever is smaller, capped at 50MB
        $chunk = min( $memory * 0.25, $upload * 0.8, 50 * 1024 * 1024 );
        return max( (int) $chunk, 5 * 1024 * 1024 ); // Minimum 5MB
    }

    private function disk_info() {
        $free = @disk_free_space( WP_CONTENT_DIR );
        $total = @disk_total_space( WP_CONTENT_DIR );
        return [
            'free'        => $free !== false ? (int) $free : null,
            'free_human'  => $free !== false ? size_format( $free ) : 'Unknown',
            'total'       => $total !== false ? (int) $total : null,
            'total_human' => $total !== false ? size_format( $total ) : 'Unknown',
        ];
    }

    private function extensions_info() {
        $required = [ 'zip', 'mysqli', 'json', 'mbstring', 'zlib' ];
        $optional = [ 'openssl', 'curl', 'fileinfo', 'gd', 'imagick' ];
        $result = [];
        foreach ( $required as $ext ) {
            $result[ $ext ] = [ 'loaded' => extension_loaded( $ext ), 'required' => true ];
        }
        foreach ( $optional as $ext ) {
            $result[ $ext ] = [ 'loaded' => extension_loaded( $ext ), 'required' => false ];
        }
        return $result;
    }

    private function writable_info() {
        return [
            'wp_content'  => wp_is_writable( WP_CONTENT_DIR ),
            'plugins'     => wp_is_writable( WP_PLUGIN_DIR ),
            'themes'      => wp_is_writable( get_theme_root() ),
            'uploads'     => wp_is_writable( wp_upload_dir()['basedir'] ),
            'backup_dir'  => wp_is_writable( WPCM_BACKUP_DIR ) || wp_mkdir_p( WPCM_BACKUP_DIR ),
        ];
    }

    private function features_info() {
        return [
            'exec_available'  => function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ) ),
            'wp_cli'          => defined( 'WP_CLI' ),
            'shell_exec'      => function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ) ),
            'zip_archive'     => class_exists( 'ZipArchive' ),
        ];
    }

    private function dir_size( $dir ) {
        $size = 0;
        if ( ! is_dir( $dir ) ) return 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $file ) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function return_bytes( $val ) {
        $val = trim( $val );
        $last = strtolower( $val[ strlen( $val ) - 1 ] );
        $val = (int) $val;
        switch ( $last ) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }
}
