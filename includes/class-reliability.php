<?php
/**
 * Reliability primitives used by backup and restore operations.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPCM_Reliability {

    /**
     * Atomically replace a file in the same directory.
     *
     * The payload is written to an exclusively-created temporary file, flushed,
     * optionally fsynced, and then published with rename(). A readable previous
     * copy is preserved as .bak so interrupted state writes remain recoverable.
     *
     * @param string $path Destination path.
     * @param string $data File contents.
     * @param int    $mode File mode.
     * @return void
     * @throws RuntimeException When the write cannot be completed.
     */
    public static function atomic_write( $path, $data, $mode = 0640 ) {
        $directory = dirname( $path );
        if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
            throw new RuntimeException( 'Unable to create directory: ' . $directory );
        }

        try {
            $suffix = bin2hex( random_bytes( 8 ) );
        } catch ( Exception $e ) {
            $suffix = preg_replace( '/[^a-zA-Z0-9]/', '', wp_generate_password( 20, false, false ) );
        }

        $temporary = $path . '.tmp-' . $suffix;
        $handle    = @fopen( $temporary, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive native writes are required for atomic publication.
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'Unable to create temporary file: ' . $temporary );
        }

        $length  = strlen( $data );
        $written = 0;
        try {
            while ( $written < $length ) {
                $result = fwrite( $handle, substr( $data, $written ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Low-level durable writes are required.
                if ( false === $result || 0 === $result ) {
                    throw new RuntimeException( 'Unable to write temporary file: ' . $temporary );
                }
                $written += $result;
            }
            if ( ! fflush( $handle ) ) {
                throw new RuntimeException( 'Unable to flush temporary file: ' . $temporary );
            }
            if ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) {
                throw new RuntimeException( 'Unable to synchronize temporary file: ' . $temporary );
            }
        } catch ( Throwable $e ) {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matches exclusive fopen above.
            @unlink( $temporary );
            throw $e;
        }
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matches exclusive fopen above.

        @chmod( $temporary, $mode );

        if ( is_file( $path ) && ! @copy( $path, $path . '.bak' ) ) {
            @unlink( $temporary );
            throw new RuntimeException( 'Unable to preserve the previous state file: ' . $path );
        }

        if ( ! @rename( $temporary, $path ) ) {
            @unlink( $temporary );
            throw new RuntimeException( 'Unable to atomically publish file: ' . $path );
        }
    }

    /**
     * Atomically copy a local file to a destination on the same filesystem.
     *
     * @param string $source Source file.
     * @param string $target Destination file.
     * @param int    $mode   Destination mode.
     * @return void
     * @throws RuntimeException On read, write, or publication failure.
     */
    public static function atomic_copy( $source, $target, $mode = 0640 ) {
        if ( ! is_file( $source ) || ! is_readable( $source ) ) {
            throw new RuntimeException( 'Unable to read source file: ' . $source );
        }

        $directory = dirname( $target );
        if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
            throw new RuntimeException( 'Unable to create directory: ' . $directory );
        }

        $temporary = $target . '.partial-' . bin2hex( random_bytes( 6 ) );
        $input     = @fopen( $source, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming copy avoids loading large files in memory.
        $output    = @fopen( $temporary, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive native writes are required.
        if ( ! is_resource( $input ) || ! is_resource( $output ) ) {
            if ( is_resource( $input ) ) {
                fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
            if ( is_resource( $output ) ) {
                fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
            @unlink( $temporary );
            throw new RuntimeException( 'Unable to create atomic file copy.' );
        }

        try {
            while ( ! feof( $input ) ) {
                $chunk = fread( $input, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming copy.
                if ( false === $chunk ) {
                    throw new RuntimeException( 'Unable to read source file: ' . $source );
                }
                $offset = 0;
                $length = strlen( $chunk );
                while ( $offset < $length ) {
                    $written = fwrite( $output, substr( $chunk, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming copy.
                    if ( false === $written || 0 === $written ) {
                        throw new RuntimeException( 'Unable to write destination file: ' . $target );
                    }
                    $offset += $written;
                }
            }
            if ( ! fflush( $output ) ) {
                throw new RuntimeException( 'Unable to flush destination file: ' . $target );
            }
            if ( function_exists( 'fsync' ) && ! @fsync( $output ) ) {
                throw new RuntimeException( 'Unable to synchronize destination file: ' . $target );
            }
        } catch ( Throwable $e ) {
            fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            @unlink( $temporary );
            throw $e;
        }

        fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        @chmod( $temporary, $mode );

        if ( ! @rename( $temporary, $target ) ) {
            @unlink( $temporary );
            throw new RuntimeException( 'Unable to atomically publish file copy: ' . $target );
        }
    }

    /**
     * Atomically write JSON data.
     *
     * @param string $path Destination path.
     * @param mixed  $data JSON-serializable data.
     * @return void
     */
    public static function atomic_json( $path, $data ) {
        $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        if ( false === $json ) {
            throw new RuntimeException( 'Unable to encode JSON state.' );
        }
        self::atomic_write( $path, $json . "\n" );
    }

    /**
     * Write an HMAC-authenticated JSON envelope.
     *
     * @param string $path    Destination path.
     * @param mixed  $payload Payload.
     * @param string $context Domain-separation context.
     * @return void
     */
    public static function atomic_signed_json( $path, $payload, $context ) {
        $canonical = self::canonical_json( $payload );
        $envelope  = array(
            'payload' => $payload,
            'hmac'    => hash_hmac( 'sha256', $canonical, self::site_state_key( $context ) ),
        );
        self::atomic_json( $path, $envelope );
    }

    /**
     * Read and authenticate an HMAC-protected JSON envelope with .bak fallback.
     *
     * @param string $path    State path.
     * @param string $context Domain-separation context.
     * @return array|null
     */
    public static function read_signed_json( $path, $context ) {
        foreach ( array( $path, $path . '.bak' ) as $candidate ) {
            if ( ! is_readable( $candidate ) ) {
                continue;
            }
            $raw = file_get_contents( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local state file.
            if ( false === $raw ) {
                continue;
            }
            $envelope = json_decode( $raw, true );
            if ( ! is_array( $envelope ) || ! array_key_exists( 'payload', $envelope ) || empty( $envelope['hmac'] ) ) {
                continue;
            }
            $actual = hash_hmac( 'sha256', self::canonical_json( $envelope['payload'] ), self::site_state_key( $context ) );
            if ( hash_equals( (string) $envelope['hmac'], $actual ) && is_array( $envelope['payload'] ) ) {
                return $envelope['payload'];
            }
        }
        return null;
    }

    /**
     * Read JSON with a .bak fallback.
     *
     * @param string $path File path.
     * @return array|null
     */
    public static function read_json( $path ) {
        foreach ( array( $path, $path . '.bak' ) as $candidate ) {
            if ( ! is_readable( $candidate ) ) {
                continue;
            }
            $raw = file_get_contents( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local state file.
            if ( false === $raw ) {
                continue;
            }
            $data = json_decode( $raw, true );
            if ( is_array( $data ) ) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Acquire a non-blocking file lock.
     *
     * @param string $path Lock file path.
     * @return resource|false
     */
    public static function acquire_lock( $path ) {
        $directory = dirname( $path );
        if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
            return false;
        }
        $handle = @fopen( $path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock requires a native handle.
        if ( ! is_resource( $handle ) ) {
            return false;
        }
        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matches native fopen.
            return false;
        }
        ftruncate( $handle, 0 );
        fwrite( $handle, (string) getmypid() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Lock metadata only.
        fflush( $handle );
        return $handle;
    }

    /**
     * Release a file lock.
     *
     * @param resource|false $handle Lock handle.
     * @return void
     */
    public static function release_lock( $handle ) {
        if ( is_resource( $handle ) ) {
            flock( $handle, LOCK_UN );
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matches native fopen.
        }
    }

    /**
     * Return a SHA-256 checksum for a readable file.
     *
     * @param string $path File path.
     * @return string
     */
    public static function checksum( $path ) {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            throw new RuntimeException( 'Unable to checksum file: ' . $path );
        }
        $hash = hash_file( 'sha256', $path );
        if ( false === $hash ) {
            throw new RuntimeException( 'Unable to checksum file: ' . $path );
        }
        return $hash;
    }

    /**
     * Determine whether a real path is strictly contained in a real directory.
     *
     * @param string $path      Candidate path.
     * @param string $directory Parent directory.
     * @return bool
     */
    public static function path_is_within( $path, $directory ) {
        $real_path = realpath( $path );
        $real_dir  = realpath( $directory );
        if ( false === $real_path || false === $real_dir ) {
            return false;
        }
        $real_dir = rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        return 0 === strpos( $real_path, $real_dir );
    }

    /**
     * Produce deterministic JSON for HMAC inputs.
     *
     * @param mixed $value Value.
     * @return string
     */
    public static function canonical_json( $value ) {
        $normalized = self::canonicalize( $value );
        $json       = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        if ( false === $json ) {
            throw new RuntimeException( 'Unable to encode canonical JSON.' );
        }
        return $json;
    }

    /**
     * Recursively sort associative arrays while preserving list order.
     *
     * @param mixed $value Value.
     * @return mixed
     */
    private static function canonicalize( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        $keys    = array_keys( $value );
        $is_list = $keys === range( 0, count( $value ) - 1 );
        if ( ! $is_list ) {
            ksort( $value, SORT_STRING );
        }
        foreach ( $value as $key => $item ) {
            $value[ $key ] = self::canonicalize( $item );
        }
        return $value;
    }

    /**
     * Read a WordPress authentication salt before pluggable.php is available.
     *
     * The temporary recovery MU-plugin runs before WordPress loads pluggable
     * functions. Calling wp_salt() there causes a fatal error, so this method
     * mirrors the core constant and site-option lookup without generating new
     * secrets. In normal requests it delegates to wp_salt() so salt filters and
     * standard WordPress behavior remain unchanged.
     *
     * @param string $scheme Authentication scheme.
     * @return string Combined key and salt.
     * @throws RuntimeException When the configured salt cannot be resolved.
     */
    public static function wordpress_salt( $scheme = 'auth' ) {
        $scheme = strtolower( (string) $scheme );
        if ( function_exists( 'wp_salt' ) ) {
            return wp_salt( $scheme );
        }
        return self::bootstrap_wordpress_salt( $scheme );
    }

    /**
     * Resolve a salt without depending on pluggable functions or salt filters.
     *
     * Recovery envelopes must use the same key during a normal request and in
     * the early MU-plugin bootstrap. Keeping this derivation independent from
     * runtime filters prevents the encryption key from changing between phases.
     *
     * @param string $scheme Authentication scheme.
     * @return string Combined key and salt.
     * @throws RuntimeException When the configured salt cannot be resolved.
     */
    private static function bootstrap_wordpress_salt( $scheme ) {
        $scheme = strtolower( (string) $scheme );
        $duplicated = array();
        foreach ( array( 'AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE', 'SECRET' ) as $first ) {
            foreach ( array( 'KEY', 'SALT' ) as $second ) {
                $constant = $first . '_' . $second;
                if ( ! defined( $constant ) ) {
                    continue;
                }
                $value                = (string) constant( $constant );
                $duplicated[ $value ] = isset( $duplicated[ $value ] );
            }
        }
        $duplicated['put your unique phrase here'] = true;
        if ( function_exists( '__' ) ) {
            $duplicated[ (string) __( 'put your unique phrase here' ) ] = true;
        }

        $values = array(
            'key'  => '',
            'salt' => '',
        );
        if ( defined( 'SECRET_KEY' ) && SECRET_KEY && empty( $duplicated[ (string) SECRET_KEY ] ) ) {
            $values['key'] = (string) SECRET_KEY;
        }
        if ( 'auth' === $scheme && defined( 'SECRET_SALT' ) && SECRET_SALT && empty( $duplicated[ (string) SECRET_SALT ] ) ) {
            $values['salt'] = (string) SECRET_SALT;
        }

        if ( in_array( $scheme, array( 'auth', 'secure_auth', 'logged_in', 'nonce' ), true ) ) {
            foreach ( array( 'key', 'salt' ) as $type ) {
                $constant = strtoupper( $scheme . '_' . $type );
                if ( defined( $constant ) && constant( $constant ) && empty( $duplicated[ (string) constant( $constant ) ] ) ) {
                    $values[ $type ] = (string) constant( $constant );
                    continue;
                }
                if ( '' === $values[ $type ] && function_exists( 'get_site_option' ) ) {
                    $stored = get_site_option( $scheme . '_' . $type );
                    if ( is_string( $stored ) && '' !== $stored ) {
                        $values[ $type ] = $stored;
                    }
                }
            }
        }

        if ( '' === $values['key'] || '' === $values['salt'] ) {
            throw new RuntimeException( 'WordPress authentication salts are unavailable during early recovery.' );
        }
        return $values['key'] . $values['salt'];
    }

    /**
     * Derive the encryption key used by the automatic recovery envelope.
     *
     * @return string Binary AES key.
     */
    public static function recovery_encryption_key() {
        return hash( 'sha256', self::bootstrap_wordpress_salt( 'auth' ) . self::bootstrap_wordpress_salt( 'secure_auth' ), true );
    }

    /**
     * Derive an HMAC key from WordPress salts with domain separation.
     *
     * @param string $context Context.
     * @return string Binary key.
     */
    private static function site_state_key( $context ) {
        return hash( 'sha256', self::wordpress_salt( 'auth' ) . "\0" . self::wordpress_salt( 'secure_auth' ) . "\0" . $context, true );
    }
}
