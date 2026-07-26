<?php
/**
 * Shared recovery operations for WP-CLI and the standalone emergency tool.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPCM_Recovery_Tool {

    /**
     * Return a structural inventory without inflating archive payloads.
     *
     * @param string $archive Absolute archive path.
     * @return array<string,mixed>
     */
    public static function info( $archive ) {
        return WPCM_Archive_Reader::scan( self::resolve_archive( $archive ) );
    }

    /**
     * Fully validate every archive block, the database stream and the footer.
     *
     * @param string $archive Absolute archive path.
     * @return array<string,mixed>
     */
    public static function verify( $archive ) {
        return WPCM_Archive::inspect( self::resolve_archive( $archive ) );
    }

    /**
     * Validate and extract a WPCM archive with durable local checkpoints.
     *
     * The extraction destination must be empty for a new operation. When a
     * checkpoint is present, the operation resumes from the last verified block.
     *
     * @param string        $archive   Absolute archive path.
     * @param string        $destination Empty or resumable destination directory.
     * @param callable|null $progress  Optional callback receiving progress details.
     * @param array         $options   Optional time_budget, memory_limit, slice_bytes.
     * @return array<string,mixed>
     */
    public static function extract( $archive, $destination, $progress = null, array $options = array() ) {
        $archive     = self::resolve_archive( $archive );
        $destination = self::prepare_destination( $destination );
        $state_file  = $destination . DIRECTORY_SEPARATOR . '.wpcm-recovery-state.json';
        $scan_file   = $destination . DIRECTORY_SEPARATOR . '.wpcm-recovery-scan.json';

        $scan     = null;
        $new_scan = false;
        if ( is_file( $scan_file ) ) {
            $decoded = json_decode( (string) @file_get_contents( $scan_file ), true );
            if ( is_array( $decoded ) ) {
                $scan = $decoded;
            }
        }
        if ( ! is_array( $scan ) ) {
            self::assert_new_destination_is_empty( $destination, array( basename( $state_file ), basename( $scan_file ) ) );
            $scan     = WPCM_Archive_Reader::scan( $archive );
            $new_scan = true;
            self::atomic_json( $scan_file, $scan );
        }

        $expected_fingerprint = (string) ( $scan['source_fingerprint'] ?? '' );
        $actual_scan          = $new_scan ? $scan : WPCM_Archive_Reader::scan( $archive );
        if ( '' === $expected_fingerprint || ! hash_equals( $expected_fingerprint, (string) $actual_scan['source_fingerprint'] ) ) {
            throw new RuntimeException( 'The WPCM source changed after the recovery checkpoint was created.' );
        }

        $state = null;
        if ( is_file( $state_file ) ) {
            $decoded = json_decode( (string) @file_get_contents( $state_file ), true );
            if ( is_array( $decoded ) ) {
                $state = $decoded;
            }
        }
        if ( ! is_array( $state ) ) {
            $slice = isset( $options['slice_bytes'] ) ? (int) $options['slice_bytes'] : 64 * MB_IN_BYTES;
            $state = WPCM_Archive_Reader::initial_state( $scan, $slice );
            self::atomic_json( $state_file, $state );
        }

        if ( ! hash_equals( $expected_fingerprint, (string) ( $state['source_fingerprint'] ?? '' ) ) ) {
            throw new RuntimeException( 'The WPCM recovery state belongs to another archive.' );
        }

        $time_budget  = isset( $options['time_budget'] ) ? (float) $options['time_budget'] : 20.0;
        $memory_limit = array_key_exists( 'memory_limit', $options ) ? (int) $options['memory_limit'] : self::effective_memory_limit();
        $last_percent = -1;

        while ( 'extracted' !== (string) ( $state['phase'] ?? '' ) ) {
            if ( ! empty( $state['pending_publish'] ) ) {
                self::publish_pending_files( $destination, $state );
                self::atomic_json( $state_file, $state );
            }

            $result = WPCM_Archive_Reader::extract_step(
                $archive,
                $destination,
                $state,
                $time_budget,
                $memory_limit
            );
            $state = $result['state'];

            // Persist the verified offsets before publishing completed files.
            self::atomic_json( $state_file, $state );
            if ( ! empty( $state['pending_publish'] ) ) {
                self::publish_pending_files( $destination, $state );
                self::atomic_json( $state_file, $state );
            }

            $total   = max( 1, (int) ( $state['payload_length'] ?? 1 ) );
            $offset  = min( $total, max( 0, (int) ( $state['offset'] ?? 0 ) ) );
            $percent = min( 100, (int) floor( $offset / $total * 100 ) );
            if ( is_callable( $progress ) && ( $percent !== $last_percent || ! empty( $result['done'] ) ) ) {
                call_user_func(
                    $progress,
                    array(
                        'percent'        => $percent,
                        'offset'         => $offset,
                        'total'          => $total,
                        'files'          => (int) ( $state['files_count'] ?? 0 ),
                        'entries'        => (int) ( $state['entry_count'] ?? 0 ),
                        'throughput'     => (float) ( $result['throughput'] ?? 0 ),
                        'slice_bytes'    => (int) ( $state['slice_bytes'] ?? 0 ),
                        'current_entry'  => is_array( $state['current'] ?? null ) ? (string) ( $state['current']['entry'] ?? '' ) : '',
                        'adaptive_reason'=> (string) ( $state['last_reason'] ?? '' ),
                    )
                );
                $last_percent = $percent;
            }
        }

        if ( 'final-pass' === (string) ( $state['hash_mode'] ?? '' ) ) {
            $state['payload_sha256'] = WPCM_Archive_Reader::verify_payload_checksum(
                $archive,
                (string) $state['expected_payload_hash']
            );
        }

        $manifest = is_array( $state['manifest'] ?? null ) ? $state['manifest'] : array();
        $database = $destination . DIRECTORY_SEPARATOR . 'database.sql';
        if ( ! is_file( $database ) || ! is_readable( $database ) ) {
            throw new RuntimeException( 'The validated database.sql file is missing after extraction.' );
        }
        $database_hash = (string) ( $state['database_hash'] ?? '' );
        if ( '' === $database_hash ) {
            $database_hash = (string) hash_file( 'sha256', $database );
        }
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $database_hash )
            || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $manifest['sha256_database'] ?? '' ) )
            || ! hash_equals( (string) $manifest['sha256_database'], $database_hash ) ) {
            throw new RuntimeException( 'The extracted database stream failed SHA-256 validation.' );
        }
        if ( (int) ( $manifest['files_count'] ?? -1 ) !== (int) ( $state['files_count'] ?? -2 )
            || (int) ( $manifest['files_size'] ?? -1 ) !== (int) ( $state['files_size'] ?? -2 ) ) {
            throw new RuntimeException( 'The extracted file inventory no longer matches the WPCM manifest.' );
        }

        $result = array(
            'archive'          => $archive,
            'destination'      => $destination,
            'manifest'         => $manifest,
            'archive_size'     => (int) ( $state['archive_size'] ?? @filesize( $archive ) ),
            'payload_sha256'   => (string) ( $state['payload_sha256'] ?? $state['expected_payload_hash'] ?? '' ),
            'database_sha256'  => $database_hash,
            'files_count'      => (int) ( $state['files_count'] ?? 0 ),
            'files_size'       => (int) ( $state['files_size'] ?? 0 ),
            'database_path'    => $database,
            'content_path'     => $destination . DIRECTORY_SEPARATOR . 'wp-content',
        );

        @unlink( $state_file );
        @unlink( $scan_file );
        return $result;
    }

    /** @return string */
    private static function resolve_archive( $archive ) {
        $real = realpath( (string) $archive );
        if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
            throw new RuntimeException( 'The WPCM archive does not exist or is not readable.' );
        }
        if ( '.wpcm' !== strtolower( substr( $real, -5 ) ) ) {
            throw new RuntimeException( 'The recovery source must use the .wpcm extension.' );
        }
        return $real;
    }

    /** @return string */
    private static function prepare_destination( $destination ) {
        $destination = rtrim( (string) $destination, '/\\' );
        if ( '' === $destination ) {
            throw new RuntimeException( 'A recovery destination is required.' );
        }
        if ( ! is_dir( $destination ) && ! wp_mkdir_p( $destination ) ) {
            throw new RuntimeException( 'Unable to create the recovery destination.' );
        }
        $real = realpath( $destination );
        if ( false === $real || ! is_dir( $real ) || ! is_writable( $real ) ) {
            throw new RuntimeException( 'The recovery destination is not writable.' );
        }
        $root = realpath( DIRECTORY_SEPARATOR );
        if ( false !== $root && rtrim( $real, DIRECTORY_SEPARATOR ) === rtrim( $root, DIRECTORY_SEPARATOR ) ) {
            throw new RuntimeException( 'Refusing to extract a WPCM archive directly into the filesystem root.' );
        }
        return $real;
    }

    /** @return void */
    private static function assert_new_destination_is_empty( $destination, array $ignored ) {
        $entries = @scandir( $destination );
        if ( ! is_array( $entries ) ) {
            throw new RuntimeException( 'Unable to inspect the recovery destination.' );
        }
        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry || in_array( $entry, $ignored, true ) ) {
                continue;
            }
            throw new RuntimeException( 'The recovery destination must be empty before a new extraction.' );
        }
    }

    /** @return void */
    private static function publish_pending_files( $destination, array &$state ) {
        $root_real = realpath( $destination );
        if ( false === $root_real ) {
            throw new RuntimeException( 'Unable to resolve the recovery destination.' );
        }
        $prefix       = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        $parent_cache = array();

        foreach ( (array) ( $state['pending_publish'] ?? array() ) as $pending ) {
            if ( ! is_array( $pending ) ) {
                throw new RuntimeException( 'The pending WPCM publication state is invalid.' );
            }
            $entry = str_replace( '\\', '/', (string) ( $pending['entry'] ?? '' ) );
            if ( '' === $entry
                || false !== strpos( $entry, "\0" )
                || '/' === $entry[0]
                || false !== strpos( $entry, '//' )
                || preg_match( '#(^|/)\.{1,2}(/|$)#', $entry )
                || ! ( 'database.sql' === $entry || 0 === strpos( $entry, 'wp-content/' ) ) ) {
                throw new RuntimeException( 'The pending WPCM publication path is invalid.' );
            }

            $target      = $prefix . str_replace( '/', DIRECTORY_SEPARATOR, $entry );
            $partial     = $target . '.partial';
            $size        = (int) ( $pending['expected_size'] ?? -1 );
            $parent_path = dirname( $target );
            if ( isset( $parent_cache[ $parent_path ] ) ) {
                $target_parent = $parent_cache[ $parent_path ];
            } else {
                $target_parent = realpath( $parent_path );
                if ( false === $target_parent
                    || 0 !== strpos( rtrim( $target_parent, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR, $prefix ) ) {
                    throw new RuntimeException( 'A pending WPCM path escaped the recovery destination.' );
                }
                $parent_cache[ $parent_path ] = $target_parent;
            }

            clearstatcache( true, $target );
            clearstatcache( true, $partial );
            if ( is_file( $target ) && ! is_link( $target ) ) {
                if ( (int) @filesize( $target ) !== $size ) {
                    throw new RuntimeException( 'A published WPCM file has an unexpected size.' );
                }
                if ( is_file( $partial ) ) {
                    @unlink( $partial );
                }
            } elseif ( is_file( $partial ) && ! is_link( $partial ) ) {
                if ( (int) @filesize( $partial ) !== $size || ! @rename( $partial, $target ) ) {
                    throw new RuntimeException( 'Unable to atomically publish a recovered WPCM file.' );
                }
            } else {
                throw new RuntimeException( 'A completed WPCM recovery file is missing.' );
            }
            if ( (int) ( $pending['mtime'] ?? 0 ) > 0 ) {
                @touch( $target, (int) $pending['mtime'] );
            }
        }
        $state['pending_publish'] = array();
    }

    /** @return int */
    private static function effective_memory_limit() {
        $value = trim( (string) ini_get( 'memory_limit' ) );
        if ( '' === $value || '-1' === $value ) {
            return 0;
        }
        if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
            $bytes = (int) wp_convert_hr_to_bytes( $value );
            return $bytes > 0 ? $bytes : 0;
        }
        $last  = strtolower( substr( $value, -1 ) );
        $bytes = (int) $value;
        if ( 'g' === $last ) {
            $bytes *= 1024;
        }
        if ( in_array( $last, array( 'g', 'm' ), true ) ) {
            $bytes *= 1024;
        }
        if ( in_array( $last, array( 'g', 'm', 'k' ), true ) ) {
            $bytes *= 1024;
        }
        return max( 0, $bytes );
    }

    /** @return void */
    private static function atomic_json( $path, array $data ) {
        $json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        if ( ! is_string( $json ) ) {
            throw new RuntimeException( 'Unable to encode the WPCM recovery state.' );
        }
        $temporary = $path . '.tmp-' . bin2hex( random_bytes( 8 ) );
        $handle    = @fopen( $temporary, 'xb' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'Unable to create the WPCM recovery checkpoint.' );
        }
        try {
            WPCM_Archive::write_exact( $handle, $json . "\n" );
            fflush( $handle );
            if ( function_exists( 'fsync' ) ) {
                @fsync( $handle );
            }
        } finally {
            fclose( $handle );
        }
        @chmod( $temporary, 0600 );
        if ( ! @rename( $temporary, $path ) ) {
            @unlink( $temporary );
            throw new RuntimeException( 'Unable to publish the WPCM recovery checkpoint.' );
        }
    }
}
