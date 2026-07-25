<?php
/**
 * Persistent production diagnostics for Clone Master.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPCM_Debug_Log {
    const MAX_BYTES = 5242880;
    const KEEP_FILES = 3;

    /** @var bool */
    private static $registered = false;

    /** @var string */
    private static $request_id = '';

    /**
     * Register fatal error capture once per request.
     *
     * @return void
     */
    public static function register(): void {
        if ( self::$registered ) {
            return;
        }
        self::$registered = true;
        self::$request_id = self::resolve_request_id();
        register_shutdown_function( array( __CLASS__, 'capture_shutdown' ) );
    }

    /**
     * Return the active diagnostic request ID.
     *
     * @return string
     */
    public static function request_id(): string {
        if ( '' === self::$request_id ) {
            self::$request_id = self::resolve_request_id();
        }
        return self::$request_id;
    }

    /**
     * Write a structured JSON line.
     *
     * @param string $level   Log level.
     * @param string $event   Stable event name.
     * @param array  $context Safe diagnostic context.
     * @return string Diagnostic event ID.
     */
    public static function write( string $level, string $event, array $context = array() ): string {
        $event_id = 'diag_' . gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', microtime( true ) . '|' . self::random_hex( 16 ) ), 0, 10 );
        $record = array(
            'time'       => gmdate( 'c' ),
            'event_id'   => $event_id,
            'level'      => strtolower( $level ),
            'event'      => self::sanitize_key_early( $event ),
            'request_id' => self::request_id(),
            'action'     => isset( $_REQUEST['action'] ) ? self::sanitize_key_early( self::unslash_early( $_REQUEST['action'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Diagnostic metadata only.
            'user_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'memory'     => memory_get_usage( true ),
            'peak'       => memory_get_peak_usage( true ),
            'php'        => PHP_VERSION,
            'context'    => self::redact( $context ),
        );

        $json = self::json_encode_early( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        if ( ! is_string( $json ) ) {
            return $event_id;
        }

        try {
            self::ensure_directory();
            self::rotate_if_needed();
            $path = self::path();
            $handle = @fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Durable append-only diagnostics.
            if ( ! is_resource( $handle ) ) {
                return $event_id;
            }
            if ( flock( $handle, LOCK_EX ) ) {
                fwrite( $handle, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Durable append-only diagnostics.
                fflush( $handle );
                if ( function_exists( 'fsync' ) ) {
                    @fsync( $handle );
                }
                flock( $handle, LOCK_UN );
            }
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            @chmod( $path, 0640 );
        } catch ( Throwable $ignored ) {
            // Diagnostics must never break backup or restore operations.
        }

        return $event_id;
    }

    /**
     * Log a caught Throwable with a trimmed stack trace.
     *
     * @param string    $event   Event name.
     * @param Throwable $error   Error.
     * @param array     $context Extra context.
     * @return string
     */
    public static function throwable( string $event, Throwable $error, array $context = array() ): string {
        $context['error_class'] = get_class( $error );
        $context['message']     = $error->getMessage();
        $context['file']        = self::relative_path( $error->getFile() );
        $context['line']        = $error->getLine();
        $context['trace']       = array_slice( explode( "\n", $error->getTraceAsString() ), 0, 18 );
        return self::write( 'error', $event, $context );
    }

    /**
     * Capture fatal PHP errors that bypass normal exception handling.
     *
     * @return void
     */
    public static function capture_shutdown(): void {
        $error = error_get_last();
        if ( ! is_array( $error ) || ! in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
            return;
        }
        $event_id = self::write(
            'critical',
            'php_fatal_shutdown',
            array(
                'type'    => (int) $error['type'],
                'message' => (string) $error['message'],
                'file'    => self::relative_path( (string) $error['file'] ),
                'line'    => (int) $error['line'],
            )
        );

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() && ! headers_sent() ) {
            while ( ob_get_level() ) {
                @ob_end_clean();
            }
            if ( function_exists( 'status_header' ) ) {
                status_header( 500 );
            } else {
                http_response_code( 500 );
            }
            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            } else {
                header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
                header( 'Pragma: no-cache' );
            }
            $charset = function_exists( 'get_option' ) ? (string) get_option( 'blog_charset', 'UTF-8' ) : 'UTF-8';
            header( 'Content-Type: application/json; charset=' . $charset );
            echo self::json_encode_early(
                array(
                    'success' => false,
                    'data'    => array(
                        'message'  => function_exists( '__' ) ? __( 'A fatal PHP error interrupted the request. Open Diagnostics and use the event ID below.', 'clone-master' ) : 'A fatal PHP error interrupted the request. Open Diagnostics and use the event ID below.',
                        'event_id' => $event_id,
                    ),
                )
            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
        }
    }

    /**
     * Read recent records, newest first.
     *
     * @param int $limit Maximum records.
     * @return array
     */
    public static function recent( int $limit = 200 ): array {
        $limit = max( 1, min( 1000, $limit ) );
        $lines = array();
        foreach ( array_reverse( self::all_paths() ) as $path ) {
            if ( ! is_readable( $path ) ) {
                continue;
            }
            $file_lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Small rotated diagnostic files.
            if ( ! is_array( $file_lines ) ) {
                continue;
            }
            foreach ( array_reverse( $file_lines ) as $line ) {
                $decoded = json_decode( $line, true );
                if ( is_array( $decoded ) ) {
                    $lines[] = $decoded;
                }
                if ( count( $lines ) >= $limit ) {
                    return $lines;
                }
            }
        }
        return $lines;
    }

    /**
     * Build a support report without secrets.
     *
     * @return string
     */
    public static function report(): string {
        global $wpdb;
        $server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown';
        $lines = array(
            'Clone Master diagnostic report',
            'Generated: ' . gmdate( 'c' ),
            'Plugin version: ' . ( defined( 'WPCM_VERSION' ) ? WPCM_VERSION : 'Unknown' ),
            'WordPress version: ' . get_bloginfo( 'version' ),
            'PHP version: ' . PHP_VERSION,
            'Database server: ' . ( isset( $wpdb ) ? $wpdb->db_version() : 'Unknown' ),
            'Web server: ' . $server,
            'Memory limit: ' . ini_get( 'memory_limit' ),
            'Max execution time: ' . ini_get( 'max_execution_time' ),
            'WP-Cron disabled: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'yes' : 'no' ),
            'OpenSSL: ' . ( extension_loaded( 'openssl' ) ? 'yes' : 'no' ),
            'cURL: ' . ( extension_loaded( 'curl' ) ? 'yes' : 'no' ),
            'Backup directory writable: ' . ( is_writable( WPCM_BACKUP_DIR ) ? 'yes' : 'no' ),
            'Temporary directory writable: ' . ( is_writable( WPCM_TEMP_DIR ) ? 'yes' : 'no' ),
            '',
            'Recent events:',
        );
        foreach ( array_reverse( self::recent( 500 ) ) as $record ) {
            $lines[] = self::json_encode_early( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Clear rotated diagnostic files.
     *
     * @return void
     */
    public static function clear(): void {
        foreach ( self::all_paths() as $path ) {
            if ( is_file( $path ) && self::path_is_within_early( $path, WPCM_LOG_DIR ) ) {
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $path );
                } else {
                    @unlink( $path );
                }
            }
        }
    }

    /** @return string */
    public static function path(): string {
        return self::trailingslashit_early( WPCM_LOG_DIR ) . 'clone-master.jsonl';
    }

    /** @return array */
    private static function all_paths(): array {
        $paths = array();
        for ( $index = self::KEEP_FILES; $index >= 1; $index-- ) {
            $paths[] = self::path() . '.' . $index;
        }
        $paths[] = self::path();
        return $paths;
    }

    /** @return void */
    private static function ensure_directory(): void {
        if ( ! is_dir( WPCM_LOG_DIR ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( WPCM_LOG_DIR );
            } else {
                @mkdir( WPCM_LOG_DIR, 0750, true );
            }
        }
        if ( class_exists( 'WPCM_Plugin', false )
            && class_exists( 'WPCM_Reliability' )
            && function_exists( 'content_url' )
            && function_exists( 'wp_parse_url' )
            && function_exists( 'trailingslashit' ) ) {
            WPCM_Plugin::protect_directory( WPCM_LOG_DIR );
        }
    }

    /** @return void */
    private static function rotate_if_needed(): void {
        $path = self::path();
        clearstatcache( true, $path );
        if ( ! is_file( $path ) || (int) @filesize( $path ) < self::MAX_BYTES ) {
            return;
        }

        $lock_path = self::trailingslashit_early( WPCM_LOG_DIR ) . '.rotation.lock';
        $lock      = @fopen( $lock_path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Cross-process log rotation lock.
        if ( ! is_resource( $lock ) || ! flock( $lock, LOCK_EX ) ) {
            if ( is_resource( $lock ) ) {
                fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
            return;
        }

        try {
            clearstatcache( true, $path );
            if ( ! is_file( $path ) || (int) @filesize( $path ) < self::MAX_BYTES ) {
                return;
            }
            for ( $index = self::KEEP_FILES; $index >= 1; $index-- ) {
                $source = 1 === $index ? $path : $path . '.' . ( $index - 1 );
                $target = $path . '.' . $index;
                if ( is_file( $target ) ) {
                    @unlink( $target );
                }
                if ( is_file( $source ) ) {
                    @rename( $source, $target );
                }
            }
        } finally {
            flock( $lock, LOCK_UN );
            fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }
    }

    /** @return string */
    private static function resolve_request_id(): string {
        $candidate = '';
        if ( isset( $_SERVER['HTTP_X_WPCM_REQUEST_ID'] ) ) {
            $candidate = self::sanitize_key_early( self::unslash_early( $_SERVER['HTTP_X_WPCM_REQUEST_ID'] ) );
        } elseif ( isset( $_REQUEST['request_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Diagnostic metadata only.
            $candidate = self::sanitize_key_early( self::unslash_early( $_REQUEST['request_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        if ( '' !== $candidate ) {
            return substr( $candidate, 0, 96 );
        }
        return 'req_' . substr( hash( 'sha256', microtime( true ) . '|' . self::random_hex( 18 ) ), 0, 18 );
    }


    /**
     * Generate bootstrap-safe entropy without depending on WordPress pluggable functions.
     *
     * @param int $bytes Number of random bytes.
     * @return string Lowercase hexadecimal entropy.
     */
    private static function random_hex( int $bytes ): string {
        $bytes = max( 8, min( 64, $bytes ) );
        try {
            return bin2hex( random_bytes( $bytes ) );
        } catch ( Throwable $ignored ) {
            // Continue with OpenSSL or a process-local fallback.
        }

        if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
            $strong = false;
            $raw    = @openssl_random_pseudo_bytes( $bytes, $strong );
            if ( is_string( $raw ) && strlen( $raw ) === $bytes ) {
                return bin2hex( $raw );
            }
        }

        $material = implode(
            '|',
            array(
                microtime( true ),
                function_exists( 'hrtime' ) ? json_encode( hrtime() ) : '',
                function_exists( 'getmypid' ) ? getmypid() : 0,
                memory_get_usage( true ),
                uniqid( '', true ),
                isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (string) $_SERVER['REQUEST_TIME_FLOAT'] : '',
            )
        );
        return hash( 'sha512', $material );
    }

    /**
     * Sanitize a diagnostic key before WordPress formatting helpers are guaranteed.
     *
     * @param mixed $value Candidate value.
     * @return string
     */
    private static function sanitize_key_early( $value ): string {
        if ( function_exists( 'sanitize_key' ) ) {
            return sanitize_key( (string) $value );
        }
        $value = strtolower( (string) $value );
        return (string) preg_replace( '/[^a-z0-9_\-]/', '', $value );
    }

    /**
     * Remove slashes before wp_unslash() is guaranteed to exist.
     *
     * @param mixed $value Value.
     * @return mixed
     */
    private static function unslash_early( $value ) {
        if ( function_exists( 'wp_unslash' ) ) {
            return wp_unslash( $value );
        }
        if ( is_array( $value ) ) {
            return array_map( array( __CLASS__, 'unslash_early' ), $value );
        }
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }

    /**
     * Encode JSON before wp_json_encode() is guaranteed to exist.
     *
     * @param mixed $value Value.
     * @param int   $flags JSON flags.
     * @return string|false
     */
    private static function json_encode_early( $value, int $flags = 0 ) {
        if ( function_exists( 'wp_json_encode' ) ) {
            return wp_json_encode( $value, $flags );
        }
        return json_encode( $value, $flags );
    }

    /**
     * Add a trailing slash without requiring trailingslashit().
     *
     * @param string $path Path.
     * @return string
     */
    private static function trailingslashit_early( string $path ): string {
        if ( function_exists( 'trailingslashit' ) ) {
            return trailingslashit( $path );
        }
        return rtrim( $path, "/\\" ) . '/';
    }

    /**
     * Normalize a path without requiring wp_normalize_path().
     *
     * @param string $path Path.
     * @return string
     */
    private static function normalize_path_early( string $path ): string {
        if ( function_exists( 'wp_normalize_path' ) ) {
            return wp_normalize_path( $path );
        }
        return str_replace( '\\', '/', $path );
    }

    /**
     * Confirm that a file belongs to a fixed diagnostic root.
     *
     * @param string $path File path.
     * @param string $root Root path.
     * @return bool
     */
    private static function path_is_within_early( string $path, string $root ): bool {
        if ( class_exists( 'WPCM_Reliability', false ) ) {
            return WPCM_Reliability::path_is_within( $path, $root );
        }
        $path = self::normalize_path_early( $path );
        $root = self::trailingslashit_early( self::normalize_path_early( $root ) );
        return 0 === strpos( $path, $root );
    }

    /**
     * Remove secrets recursively.
     *
     * @param mixed $value Value.
     * @param string $key  Parent key.
     * @return mixed
     */
    private static function redact( $value, string $key = '' ) {
        $sensitive = array( 'password', 'pass', 'token', 'nonce', 'secret', 'authorization', 'cookie', 'db_pass', 'recovery_key', 'installer_token' );
        foreach ( $sensitive as $needle ) {
            if ( false !== stripos( $key, $needle ) ) {
                return '[redacted]';
            }
        }
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $child_key => $child ) {
                $clean[ $child_key ] = self::redact( $child, (string) $child_key );
            }
            return $clean;
        }
        if ( is_object( $value ) ) {
            return '[object ' . get_class( $value ) . ']';
        }
        if ( is_string( $value ) ) {
            $value = preg_replace(
                '/(?i)(password|passwd|db_pass|token|nonce|secret|authorization|cookie)(\s*[=:]\s*)[^\s,;&]+/',
                '$1$2[redacted]',
                $value
            );
            if ( strlen( $value ) > 4000 ) {
                return substr( $value, 0, 4000 ) . ' [truncated]';
            }
        }
        return $value;
    }

    /** @return string */
    private static function relative_path( string $path ): string {
        $normalized = self::normalize_path_early( $path );
        $root = self::normalize_path_early( ABSPATH );
        if ( 0 === strpos( $normalized, $root ) ) {
            return ltrim( substr( $normalized, strlen( $root ) ), '/' );
        }
        return basename( $normalized );
    }
}
