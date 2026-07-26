<?php
/**
 * Resumable WPCM archive analysis and extraction.
 *
 * The reader performs a lightweight structural scan first, then validates and
 * extracts the archive in bounded requests. On PHP 8+, native HashContext state
 * is serialized inside an HMAC-protected state file so the footer SHA-256 can be
 * verified without rereading the complete archive. PHP 7.4 keeps the same
 * resumable extraction path and performs one final sequential checksum pass.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPCM_Archive_Reader {

    const STATE_VERSION = 1;
    const MIN_SLICE     = 8 * 1024 * 1024;
    const MAX_SLICE     = 512 * 1024 * 1024;

    /**
     * Build a non-destructive structural index without inflating file payloads.
     *
     * @param string $path   Archive path.
     * @param array  $limits Optional safety limits.
     * @return array<string,mixed>
     */
    public static function scan( $path, array $limits = array() ) {
        $size = @filesize( $path );
        if ( false === $size || $size < strlen( WPCM_Archive::MAGIC ) + WPCM_Archive::FOOTER_BYTES ) {
            throw new RuntimeException( 'The WPCM container is truncated.' );
        }

        $mtime        = @filemtime( $path );
        $max_entries  = max( 1, (int) ( $limits['max_entries'] ?? 200000 ) );
        $max_expanded = max( 1, (int) ( $limits['max_expanded_bytes'] ?? min( 500 * GB_IN_BYTES, max( 2 * GB_IN_BYTES, $size * 80 ) ) ) );
        $handle       = @fopen( $path, 'rb' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'The WPCM container is unreadable.' );
        }

        try {
            $payload_length = $size - WPCM_Archive::FOOTER_BYTES;
            if ( 0 !== @fseek( $handle, $payload_length ) ) {
                throw new RuntimeException( 'The WPCM footer is inaccessible.' );
            }
            $footer = self::read_exact( $handle, WPCM_Archive::FOOTER_BYTES );
            if ( WPCM_Archive::FOOTER_MAGIC !== substr( $footer, 0, 4 ) ) {
                throw new RuntimeException( 'The WPCM container was not finalized.' );
            }
            $expected_hash = substr( $footer, 4 );
            if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
                throw new RuntimeException( 'The WPCM footer is invalid.' );
            }

            rewind( $handle );
            if ( WPCM_Archive::MAGIC !== self::read_payload_exact( $handle, strlen( WPCM_Archive::MAGIC ), $payload_length ) ) {
                throw new RuntimeException( 'Unsupported WPCM container version.' );
            }

            $manifest       = null;
            $manifest_json  = '';
            $files_count    = 0;
            $files_size     = 0;
            $database_found = false;
            $database_size  = 0;
            $uncompressed   = 0;
            $entry_count    = 0;
            $chunk_count    = 0;
            $seen           = array();

            while ( ftell( $handle ) < $payload_length ) {
                $tag = self::read_payload_exact( $handle, 4, $payload_length );
                if ( 'DONE' === $tag ) {
                    $manifest_length = unpack( 'Nlength', self::read_payload_exact( $handle, 4, $payload_length ) )['length'];
                    if ( $manifest_length < 2 || $manifest_length > 16 * MB_IN_BYTES ) {
                        throw new RuntimeException( 'The WPCM manifest length is invalid.' );
                    }
                    $manifest_json = self::read_payload_exact( $handle, $manifest_length, $payload_length );
                    $manifest      = json_decode( $manifest_json, true );
                    if ( ! is_array( $manifest ) ) {
                        throw new RuntimeException( 'The WPCM manifest is invalid.' );
                    }
                    break;
                }
                if ( 'FILE' !== $tag ) {
                    throw new RuntimeException( 'Invalid WPCM record.' );
                }

                $entry_count++;
                if ( $entry_count > $max_entries ) {
                    throw new RuntimeException( 'The WPCM entry limit was exceeded.' );
                }
                $path_length = unpack( 'Nlength', self::read_payload_exact( $handle, 4, $payload_length ) )['length'];
                if ( $path_length < 1 || $path_length > 1048576 ) {
                    throw new RuntimeException( 'Invalid WPCM path length.' );
                }
                $expected_size = self::unpack_uint64( self::read_payload_exact( $handle, 8, $payload_length ) );
                self::read_payload_exact( $handle, 8, $payload_length ); // mtime.
                $entry = self::normalize_entry( self::read_payload_exact( $handle, $path_length, $payload_length ) );
                self::assert_allowed_entry( $entry );

                $collision = strtolower( $entry );
                if ( isset( $seen[ $collision ] ) ) {
                    throw new RuntimeException( 'The WPCM container contains a duplicate or case-colliding path.' );
                }
                $seen[ $collision ] = true;

                if ( 'database.sql' === $entry ) {
                    if ( $database_found ) {
                        throw new RuntimeException( 'The WPCM container contains multiple database dumps.' );
                    }
                    $database_found = true;
                    $database_size  = $expected_size;
                } else {
                    $files_count++;
                    $files_size += $expected_size;
                }
                $uncompressed += $expected_size;
                if ( $uncompressed > $max_expanded ) {
                    throw new RuntimeException( 'The WPCM expanded-size limit was exceeded.' );
                }

                $written = 0;
                while ( true ) {
                    $chunk_tag = self::read_payload_exact( $handle, 4, $payload_length );
                    if ( 'FEND' === $chunk_tag ) {
                        $declared_size = self::unpack_uint64( self::read_payload_exact( $handle, 8, $payload_length ) );
                        if ( $declared_size !== $expected_size || $written !== $expected_size ) {
                            throw new RuntimeException( 'A WPCM file size is inconsistent.' );
                        }
                        break;
                    }
                    if ( 'CHNK' !== $chunk_tag ) {
                        throw new RuntimeException( 'Invalid WPCM data record.' );
                    }
                    $raw_length   = unpack( 'Nlength', self::read_payload_exact( $handle, 4, $payload_length ) )['length'];
                    $payload_size = unpack( 'Nlength', self::read_payload_exact( $handle, 4, $payload_length ) )['length'];
                    $compressed   = ord( self::read_payload_exact( $handle, 1, $payload_length ) );
                    self::read_payload_exact( $handle, 32, $payload_length ); // Per-block SHA-256.
                    if ( $raw_length < 1 || $raw_length > WPCM_Archive::CHUNK_BYTES
                        || $payload_size < 1 || $payload_size > WPCM_Archive::CHUNK_BYTES
                        || ! in_array( $compressed, array( 0, 1 ), true )
                        || ( 0 === $compressed && $payload_size !== $raw_length )
                        || ( 1 === $compressed && $payload_size >= $raw_length )
                        || $written + $raw_length > $expected_size ) {
                        throw new RuntimeException( 'A WPCM data block exceeds its safety limits.' );
                    }
                    self::skip_payload_exact( $handle, $payload_size, $payload_length );
                    $written += $raw_length;
                    $chunk_count++;
                }
            }

            if ( ! is_array( $manifest )
                || '2.0' !== (string) ( $manifest['schema_version'] ?? '' )
                || 'wpcm-append-only' !== (string) ( $manifest['format'] ?? '' )
                || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) ( $manifest['table_prefix'] ?? '' ) )
                || ! $database_found
                || ftell( $handle ) !== $payload_length ) {
                throw new RuntimeException( 'The WPCM manifest or structure is incomplete.' );
            }
            if ( (int) ( $manifest['files_count'] ?? -1 ) !== $files_count
                || (int) ( $manifest['files_size'] ?? -1 ) !== $files_size ) {
                throw new RuntimeException( 'The WPCM file inventory does not match its manifest.' );
            }
            if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $manifest['sha256_database'] ?? '' ) ) ) {
                throw new RuntimeException( 'The WPCM database checksum metadata is invalid.' );
            }

            $real_path = realpath( $path );
            if ( false === $real_path ) {
                throw new RuntimeException( 'Unable to resolve the WPCM source path.' );
            }

            return array(
                'archive_path'            => $real_path,
                'archive_size'            => (int) $size,
                'archive_mtime'           => false === $mtime ? 0 : (int) $mtime,
                'payload_length'          => (int) $payload_length,
                'payload_sha256'          => $expected_hash,
                'manifest'                => $manifest,
                'manifest_payload_sha256' => hash( 'sha256', $manifest_json ),
                'files_count'             => $files_count,
                'files_size'              => $files_size,
                'database_size'           => $database_size,
                'uncompressed_bytes'      => $uncompressed,
                'entry_count'             => $entry_count,
                'chunk_count'             => $chunk_count,
                'source_fingerprint'      => hash( 'sha256', $real_path . '|' . $size . '|' . ( false === $mtime ? 0 : $mtime ) . '|' . $expected_hash ),
            );
        } finally {
            fclose( $handle );
        }
    }

    /**
     * Create the initial resumable extraction state.
     *
     * @param array $scan        Result from scan().
     * @param int   $slice_bytes Initial adaptive byte target.
     * @return array<string,mixed>
     */
    public static function initial_state( array $scan, $slice_bytes ) {
        $hash_mode    = PHP_VERSION_ID >= 80000 ? 'native-resumable' : 'final-pass';
        $hash_context = '';
        if ( 'native-resumable' === $hash_mode ) {
            $context = hash_init( 'sha256' );
            hash_update( $context, WPCM_Archive::MAGIC );
            $hash_context = self::encode_hash_context( $context );
        }

        return array(
            'version'               => self::STATE_VERSION,
            'phase'                 => 'extracting',
            'source_fingerprint'    => (string) $scan['source_fingerprint'],
            'archive_size'          => (int) $scan['archive_size'],
            'archive_mtime'         => (int) $scan['archive_mtime'],
            'payload_length'        => (int) $scan['payload_length'],
            'expected_payload_hash' => (string) $scan['payload_sha256'],
            'manifest_payload_hash' => (string) $scan['manifest_payload_sha256'],
            'offset'                => strlen( WPCM_Archive::MAGIC ),
            'current'               => null,
            'pending_publish'       => array(),
            'files_count'           => 0,
            'files_size'            => 0,
            'database_found'        => false,
            'database_hash'         => '',
            'uncompressed_bytes'    => 0,
            'entry_count'           => 0,
            'hash_mode'             => $hash_mode,
            'payload_hash_context'  => $hash_context,
            'payload_sha256'        => '',
            'slice_bytes'           => max( self::MIN_SLICE, min( self::MAX_SLICE, (int) $slice_bytes ) ),
            'growth_streak'         => 0,
            'last_duration_ms'      => 0,
            'last_archive_bytes'    => 0,
            'last_expanded_bytes'   => 0,
            'last_memory_peak'      => 0,
            'last_reason'           => 'learning',
            'started_at'            => gmdate( 'c' ),
            'updated_at'            => gmdate( 'c' ),
        );
    }

    /**
     * Validate and extract one bounded slice.
     *
     * Completed files are returned in pending_publish. The caller must persist
     * the returned state before atomically renaming those files, then persist a
     * second state with pending_publish cleared. This keeps interrupted requests
     * replay-safe without one state write per file.
     *
     * @param string $path         Archive path.
     * @param string $extract_root Protected extraction root.
     * @param array  $state        Signed extraction state.
     * @param float  $time_budget  Wall-clock target in seconds.
     * @param int    $memory_limit Effective PHP memory limit in bytes.
     * @return array<string,mixed>
     */
    public static function extract_step( $path, $extract_root, array $state, $time_budget, $memory_limit ) {
        if ( self::STATE_VERSION !== (int) ( $state['version'] ?? 0 ) || 'extracting' !== (string) ( $state['phase'] ?? '' ) ) {
            throw new RuntimeException( 'The WPCM extraction state is invalid.' );
        }
        $size  = @filesize( $path );
        $mtime = @filemtime( $path );
        if ( false === $size || (int) $size !== (int) $state['archive_size'] || ( false !== $mtime && (int) $mtime !== (int) $state['archive_mtime'] ) ) {
            throw new RuntimeException( 'The WPCM source changed after structural analysis.' );
        }
        $footer_hash = WPCM_Archive::footer_payload_hash( $path );
        if ( ! is_string( $footer_hash ) || ! hash_equals( (string) $state['expected_payload_hash'], $footer_hash ) ) {
            throw new RuntimeException( 'The WPCM footer changed after structural analysis.' );
        }

        if ( ! is_dir( $extract_root ) && ! wp_mkdir_p( $extract_root ) ) {
            throw new RuntimeException( 'Unable to create the WPCM extraction directory.' );
        }
        $real_root = realpath( $extract_root );
        if ( false === $real_root ) {
            throw new RuntimeException( 'Unable to resolve the WPCM extraction directory.' );
        }

        $payload_length = (int) $state['payload_length'];
        $offset         = (int) $state['offset'];
        if ( $offset < strlen( WPCM_Archive::MAGIC ) || $offset > $payload_length ) {
            throw new RuntimeException( 'The WPCM extraction offset is invalid.' );
        }

        $payload_context = null;
        if ( 'native-resumable' === (string) $state['hash_mode'] ) {
            $payload_context = self::decode_hash_context( (string) $state['payload_hash_context'] );
        }

        $handle = @fopen( $path, 'rb' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'The WPCM container is unreadable.' );
        }
        if ( 0 !== @fseek( $handle, $offset ) ) {
            fclose( $handle );
            throw new RuntimeException( 'Unable to resume WPCM extraction.' );
        }

        $started         = microtime( true );
        $start_offset    = $offset;
        $expanded_start  = (int) $state['uncompressed_bytes'];
        $start_current_written = is_array( $state['current'] ?? null ) ? max( 0, (int) ( $state['current']['written'] ?? 0 ) ) : 0;
        $target_bytes    = max( self::MIN_SLICE, min( self::MAX_SLICE, (int) $state['slice_bytes'] ) );
        $time_budget     = max( 1.0, min( 24.0, (float) $time_budget ) );
        $pending         = array();
        $output          = null;
        $current         = is_array( $state['current'] ?? null ) ? $state['current'] : null;
        $database_context = null;
        $completed_files  = 0;
        $pending_state_bytes = 0;

        try {
            if ( is_array( $current ) ) {
                list( $output, $database_context ) = self::resume_current_output( $current, $real_root );
            }

            while ( ftell( $handle ) < $payload_length ) {
                if ( is_array( $current ) ) {
                    $chunk_tag = self::read_payload_with_hash( $handle, 4, $payload_length, $payload_context );
                    if ( 'FEND' === $chunk_tag ) {
                        $declared_size = self::unpack_uint64( self::read_payload_with_hash( $handle, 8, $payload_length, $payload_context ) );
                        if ( $declared_size !== (int) $current['expected_size'] || (int) $current['written'] !== (int) $current['expected_size'] ) {
                            throw new RuntimeException( 'A WPCM file size is inconsistent.' );
                        }
                        if ( is_resource( $output ) ) {
                            self::flush_extracted_output( $output, (string) $current['entry'], (int) $current['expected_size'] );
                            fclose( $output );
                            $output = null;
                        }
                        if ( 'database.sql' === (string) $current['entry'] ) {
                            if ( 'native-resumable' === (string) $state['hash_mode'] ) {
                                $state['database_hash'] = hash_final( $database_context );
                            }
                            $state['database_found'] = true;
                        } else {
                            $state['files_count'] = (int) $state['files_count'] + 1;
                            $state['files_size']  = (int) $state['files_size'] + (int) $current['expected_size'];
                        }
                        $state['uncompressed_bytes'] = (int) $state['uncompressed_bytes'] + (int) $current['expected_size'];
                        $state['entry_count']        = (int) $state['entry_count'] + 1;
                        $pending_entry = array(
                            'entry'         => (string) $current['entry'],
                            'expected_size' => (int) $current['expected_size'],
                            'mtime'         => (int) $current['mtime'],
                        );
                        $pending[] = $pending_entry;
                        $pending_state_bytes += strlen( (string) $current['entry'] ) + 96;
                        $current = null;
                        $state['current'] = null;
                        $state['offset']  = ftell( $handle );
                        $database_context = null;
                        $completed_files++;
                        // Keep the signed checkpoint compact while avoiding
                        // hundreds of AJAX requests on sites containing 100,000+
                        // tiny files. Paths are recomputed by the caller, so the
                        // pending journal stores only compact relative metadata.
                        if ( $completed_files >= 12000 || $pending_state_bytes >= 6 * MB_IN_BYTES ) {
                            break;
                        }
                        continue;
                    }
                    if ( 'CHNK' !== $chunk_tag ) {
                        throw new RuntimeException( 'Invalid WPCM data record.' );
                    }
                    $raw_length   = unpack( 'Nlength', self::read_payload_with_hash( $handle, 4, $payload_length, $payload_context ) )['length'];
                    $payload_size = unpack( 'Nlength', self::read_payload_with_hash( $handle, 4, $payload_length, $payload_context ) )['length'];
                    $compressed   = ord( self::read_payload_with_hash( $handle, 1, $payload_length, $payload_context ) );
                    $chunk_hash   = self::read_payload_with_hash( $handle, 32, $payload_length, $payload_context );
                    if ( $raw_length < 1 || $raw_length > WPCM_Archive::CHUNK_BYTES
                        || $payload_size < 1 || $payload_size > WPCM_Archive::CHUNK_BYTES
                        || ! in_array( $compressed, array( 0, 1 ), true )
                        || ( 0 === $compressed && $payload_size !== $raw_length )
                        || ( 1 === $compressed && $payload_size >= $raw_length )
                        || (int) $current['written'] + $raw_length > (int) $current['expected_size'] ) {
                        throw new RuntimeException( 'A WPCM data block exceeds its safety limits.' );
                    }
                    $payload = self::read_payload_with_hash( $handle, $payload_size, $payload_length, $payload_context );
                    $raw     = 1 === $compressed ? @gzinflate( $payload, $raw_length ) : $payload;
                    if ( ! is_string( $raw ) || strlen( $raw ) !== $raw_length || ! hash_equals( $chunk_hash, hash( 'sha256', $raw, true ) ) ) {
                        throw new RuntimeException( 'A WPCM data block failed SHA-256 validation.' );
                    }
                    WPCM_Archive::write_exact( $output, $raw );
                    if ( 'database.sql' === (string) $current['entry'] && 'native-resumable' === (string) $state['hash_mode'] ) {
                        hash_update( $database_context, $raw );
                    }
                    $current['written'] = (int) $current['written'] + $raw_length;
                    $state['current']   = $current;
                    $state['offset']    = ftell( $handle );

                    $archive_processed = (int) $state['offset'] - $start_offset;
                    $expanded_processed = max( 0, (int) $state['uncompressed_bytes'] - $expanded_start - $start_current_written + (int) $current['written'] );
                    $work_processed     = max( $archive_processed, $expanded_processed );
                    if ( $work_processed >= $target_bytes || ( microtime( true ) - $started ) >= $time_budget ) {
                        break;
                    }
                    continue;
                }

                $tag = self::read_payload_with_hash( $handle, 4, $payload_length, $payload_context );
                if ( 'DONE' === $tag ) {
                    $manifest_length = unpack( 'Nlength', self::read_payload_with_hash( $handle, 4, $payload_length, $payload_context ) )['length'];
                    if ( $manifest_length < 2 || $manifest_length > 16 * MB_IN_BYTES ) {
                        throw new RuntimeException( 'The WPCM manifest length is invalid.' );
                    }
                    $manifest_json = self::read_payload_with_hash( $handle, $manifest_length, $payload_length, $payload_context );
                    $manifest      = json_decode( $manifest_json, true );
                    if ( ! is_array( $manifest ) || ftell( $handle ) !== $payload_length ) {
                        throw new RuntimeException( 'The WPCM manifest or structure is incomplete.' );
                    }
                    if ( ! hash_equals( (string) $state['manifest_payload_hash'], hash( 'sha256', $manifest_json ) ) ) {
                        throw new RuntimeException( 'The WPCM manifest changed after structural analysis.' );
                    }
                    $state['manifest'] = $manifest;
                    $state['offset']   = ftell( $handle );
                    if ( 'native-resumable' === (string) $state['hash_mode'] ) {
                        $actual_hash = hash_final( $payload_context );
                        if ( ! hash_equals( (string) $state['expected_payload_hash'], $actual_hash ) ) {
                            throw new RuntimeException( 'The WPCM global payload checksum is invalid.' );
                        }
                        $state['payload_sha256'] = $actual_hash;
                        $state['payload_hash_context'] = '';
                    }
                    $state['phase'] = 'extracted';
                    break;
                }
                if ( 'FILE' !== $tag ) {
                    throw new RuntimeException( 'Invalid WPCM record.' );
                }

                $path_length = unpack( 'Nlength', self::read_payload_with_hash( $handle, 4, $payload_length, $payload_context ) )['length'];
                if ( $path_length < 1 || $path_length > 1048576 ) {
                    throw new RuntimeException( 'Invalid WPCM path length.' );
                }
                $expected_size = self::unpack_uint64( self::read_payload_with_hash( $handle, 8, $payload_length, $payload_context ) );
                $mtime_value   = self::unpack_uint64( self::read_payload_with_hash( $handle, 8, $payload_length, $payload_context ) );
                $entry         = self::normalize_entry( self::read_payload_with_hash( $handle, $path_length, $payload_length, $payload_context ) );
                self::assert_allowed_entry( $entry );
                if ( 'database.sql' === $entry && ! empty( $state['database_found'] ) ) {
                    throw new RuntimeException( 'The WPCM container contains multiple database dumps.' );
                }

                list( $target, $partial ) = self::resolve_output_paths( $real_root, $entry );
                if ( file_exists( $target ) || is_link( $target ) ) {
                    throw new RuntimeException( 'A WPCM extraction target already exists: ' . $entry );
                }
                if ( file_exists( $partial ) || is_link( $partial ) ) {
                    @unlink( $partial );
                }
                $output = @fopen( $partial, 'xb' );
                if ( ! is_resource( $output ) ) {
                    throw new RuntimeException( 'Unable to create a WPCM extraction file.' );
                }
                $database_context = null;
                if ( 'database.sql' === $entry && 'native-resumable' === (string) $state['hash_mode'] ) {
                    $database_context = hash_init( 'sha256' );
                }
                $current = array(
                    'entry'                 => $entry,
                    'expected_size'         => $expected_size,
                    'mtime'                 => $mtime_value,
                    'written'               => 0,
                    'target'                => $target,
                    'partial'               => $partial,
                    'database_hash_context' => '',
                );
                $state['current'] = $current;
                $state['offset']  = ftell( $handle );
            }

            if ( is_resource( $output ) ) {
                self::flush_extracted_output( $output, is_array( $current ) ? (string) $current['entry'] : '', is_array( $current ) ? (int) $current['expected_size'] : 0 );
                fclose( $output );
                $output = null;
            }

            if ( 'native-resumable' === (string) $state['hash_mode'] && 'extracted' !== (string) $state['phase'] ) {
                $state['payload_hash_context'] = self::encode_hash_context( $payload_context );
                if ( is_array( $current ) && 'database.sql' === (string) $current['entry'] ) {
                    $current['database_hash_context'] = self::encode_hash_context( $database_context );
                    $state['current'] = $current;
                }
            }

            $duration             = max( 0.001, microtime( true ) - $started );
            $archive_bytes        = max( 0, (int) $state['offset'] - $start_offset );
            $expanded_bytes       = max( 0, (int) $state['uncompressed_bytes'] - $expanded_start - $start_current_written + ( is_array( $current ) ? (int) $current['written'] : 0 ) );
            $memory_peak          = memory_get_peak_usage( true );
            $adaptive             = self::adapt_slice( (int) $state['slice_bytes'], $duration, max( $archive_bytes, $expanded_bytes ), $memory_peak, (int) $memory_limit, $time_budget, (int) ( $state['growth_streak'] ?? 0 ) );
            $state['slice_bytes']        = $adaptive['bytes'];
            $state['growth_streak']      = $adaptive['growth_streak'];
            $state['last_duration_ms']   = (int) round( $duration * 1000 );
            $state['last_archive_bytes'] = $archive_bytes;
            $state['last_expanded_bytes']= $expanded_bytes;
            $state['last_memory_peak']   = $memory_peak;
            $state['last_reason']        = $adaptive['reason'];
            $state['updated_at']         = gmdate( 'c' );
            $state['pending_publish']    = $pending;

            return array(
                'state'          => $state,
                'done'           => 'extracted' === (string) $state['phase'],
                'archive_bytes'  => $archive_bytes,
                'expanded_bytes' => $expanded_bytes,
                'duration'       => $duration,
                'throughput'     => max( $archive_bytes, $expanded_bytes ) / $duration,
                'reason'         => $adaptive['reason'],
            );
        } finally {
            if ( is_resource( $output ) ) {
                fclose( $output );
            }
            fclose( $handle );
        }
    }

    /**
     * Verify the payload footer hash in one sequential pass for PHP 7.4.
     *
     * @param string $path          Archive path.
     * @param string $expected_hash Footer SHA-256.
     * @return string
     */
    public static function verify_payload_checksum( $path, $expected_hash ) {
        $size = @filesize( $path );
        if ( false === $size || $size < WPCM_Archive::FOOTER_BYTES ) {
            throw new RuntimeException( 'The WPCM container is truncated.' );
        }
        $remaining = (int) $size - WPCM_Archive::FOOTER_BYTES;
        $handle    = @fopen( $path, 'rb' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'The WPCM container is unreadable.' );
        }
        $context = hash_init( 'sha256' );
        try {
            while ( $remaining > 0 ) {
                $chunk = fread( $handle, min( 8 * MB_IN_BYTES, $remaining ) );
                if ( ! is_string( $chunk ) || '' === $chunk ) {
                    throw new RuntimeException( 'Unexpected end of WPCM container during checksum validation.' );
                }
                hash_update( $context, $chunk );
                $remaining -= strlen( $chunk );
            }
        } finally {
            fclose( $handle );
        }
        $actual = hash_final( $context );
        if ( ! hash_equals( (string) $expected_hash, $actual ) ) {
            throw new RuntimeException( 'The WPCM global payload checksum is invalid.' );
        }
        return $actual;
    }

    /** @return array{0:resource,1:mixed} */
    private static function resume_current_output( array &$current, $real_root ) {
        self::assert_allowed_entry( (string) $current['entry'] );
        list( $target, $partial ) = self::resolve_output_paths( $real_root, (string) $current['entry'] );
        if ( $target !== (string) $current['target'] || $partial !== (string) $current['partial'] || file_exists( $target ) || is_link( $target ) ) {
            throw new RuntimeException( 'The resumable WPCM extraction target changed.' );
        }
        $output = @fopen( $partial, 'c+b' );
        if ( ! is_resource( $output ) ) {
            throw new RuntimeException( 'Unable to resume a WPCM extraction file.' );
        }
        $written = max( 0, (int) $current['written'] );
        if ( ! @ftruncate( $output, $written ) || 0 !== @fseek( $output, $written ) ) {
            fclose( $output );
            throw new RuntimeException( 'Unable to restore the WPCM extraction checkpoint.' );
        }
        $database_context = null;
        if ( 'database.sql' === (string) $current['entry'] ) {
            $encoded = (string) ( $current['database_hash_context'] ?? '' );
            if ( '' !== $encoded ) {
                $database_context = self::decode_hash_context( $encoded );
            } elseif ( PHP_VERSION_ID >= 80000 && 0 === $written ) {
                $database_context = hash_init( 'sha256' );
            }
        }
        return array( $output, $database_context );
    }

    /**
     * Flush one extracted staging file without forcing a physical disk barrier
     * for every tiny WordPress file.
     *
     * Per-block SHA-256 validation is completed before data reaches this method,
     * and files are still published by atomic rename. A strict data sync remains
     * enabled for database.sql and large files where replay cost is material.
     *
     * @param resource $handle        Open extracted-file handle.
     * @param string   $entry         Archive entry name.
     * @param int      $expected_size Expected uncompressed file size.
     * @return void
     */
    private static function flush_extracted_output( $handle, $entry, $expected_size ) {
        if ( ! @fflush( $handle ) ) {
            throw new RuntimeException( 'Unable to flush extracted WPCM data.' );
        }

        $threshold = 32 * MB_IN_BYTES;
        if ( function_exists( 'apply_filters' ) ) {
            $threshold = max( MB_IN_BYTES, (int) apply_filters( 'wpcm_import_durable_file_threshold', $threshold, $entry, $expected_size ) );
        }
        $requires_durable_sync = 'database.sql' === (string) $entry || (int) $expected_size >= $threshold;
        if ( ! $requires_durable_sync ) {
            return;
        }

        if ( function_exists( 'fdatasync' ) ) {
            if ( ! @fdatasync( $handle ) ) {
                throw new RuntimeException( 'Unable to durably synchronize extracted WPCM data.' );
            }
        } elseif ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) {
            throw new RuntimeException( 'Unable to durably synchronize extracted WPCM data.' );
        }
    }

    /** @return array{0:string,1:string} */
    private static function resolve_output_paths( $real_root, $entry ) {
        static $directory_cache = array();

        $target    = rtrim( $real_root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $entry );
        $directory = dirname( $target );
        $cache_key = $real_root . "\0" . $directory;
        if ( isset( $directory_cache[ $cache_key ] ) ) {
            $real_directory = $directory_cache[ $cache_key ];
        } else {
            if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
                throw new RuntimeException( 'Unable to create a WPCM extraction directory.' );
            }
            $real_directory = realpath( $directory );
            $root_prefix    = rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
            if ( false === $real_directory || ( $real_directory !== $real_root && 0 !== strpos( $real_directory, $root_prefix ) ) ) {
                throw new RuntimeException( 'A WPCM extraction path escaped its protected root.' );
            }
            $directory_cache[ $cache_key ] = $real_directory;
        }
        $target  = rtrim( $real_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . basename( $target );
        $partial = $target . '.partial';
        return array( $target, $partial );
    }

    private static function adapt_slice( $current, $duration, $processed, $memory_peak, $memory_limit, $time_budget, $growth_streak ) {
        $current      = max( self::MIN_SLICE, min( self::MAX_SLICE, (int) $current ) );
        $duration     = max( 0.001, (float) $duration );
        $processed    = max( 0, (int) $processed );
        $memory_ratio = $memory_limit > 0 ? $memory_peak / $memory_limit : 0.0;
        $next         = $current;
        $reason       = 'stable';

        // Reaching the requested wall-clock budget is normal. The previous
        // algorithm treated that normal stop as an overload, reduced the slice,
        // then increased it again on the next request. Only a material overrun
        // or genuine memory pressure should force an immediate reduction.
        if ( $memory_limit > 0 && $memory_ratio >= 0.86 ) {
            $next          = (int) floor( $current * 0.55 );
            $growth_streak = 0;
            $reason        = 'reduced_memory';
        } elseif ( $duration > $time_budget * 1.30 ) {
            $next          = (int) floor( $current * 0.80 );
            $growth_streak = 0;
            $reason        = 'reduced_time';
        } elseif ( $processed >= (int) floor( $current * 0.92 ) && $duration < $time_budget * 0.78 && $memory_ratio < 0.72 ) {
            $growth_streak++;
            $reason = 'learning';
            if ( $growth_streak >= 2 ) {
                $throughput = $processed / $duration;
                $ideal      = (int) floor( $throughput * max( 3.0, $time_budget * 0.88 ) );
                $next       = min( (int) ceil( $current * 1.30 ), max( $current, $ideal ) );
                $growth_streak = 0;
                $reason = $next > $current ? 'increased' : 'stable';
            }
        } elseif ( $duration >= $time_budget * 0.70 ) {
            $growth_streak = 0;
            $reason        = 'stable';
        } else {
            $growth_streak = max( 0, $growth_streak - 1 );
        }

        $step = 4 * 1024 * 1024;
        $next = max( self::MIN_SLICE, min( self::MAX_SLICE, (int) floor( $next / $step ) * $step ) );
        return array( 'bytes' => $next, 'reason' => $reason, 'growth_streak' => $growth_streak );
    }

    private static function encode_hash_context( $context ) {
        if ( PHP_VERSION_ID < 80000 || ! is_object( $context ) ) {
            throw new RuntimeException( 'Native resumable SHA-256 state is unavailable.' );
        }
        $serialized = serialize( $context );
        if ( ! is_string( $serialized ) || '' === $serialized ) {
            throw new RuntimeException( 'Unable to serialize the SHA-256 checkpoint.' );
        }
        return base64_encode( $serialized );
    }

    private static function decode_hash_context( $encoded ) {
        if ( PHP_VERSION_ID < 80000 || '' === $encoded ) {
            throw new RuntimeException( 'The SHA-256 checkpoint is unavailable.' );
        }
        $serialized = base64_decode( $encoded, true );
        if ( false === $serialized ) {
            throw new RuntimeException( 'The SHA-256 checkpoint encoding is invalid.' );
        }
        $context = @unserialize( $serialized, array( 'allowed_classes' => array( 'HashContext' ) ) );
        if ( ! is_object( $context ) || 'HashContext' !== get_class( $context ) ) {
            throw new RuntimeException( 'The SHA-256 checkpoint is invalid.' );
        }
        return $context;
    }

    private static function read_payload_with_hash( $handle, $length, $payload_length, $hash_context ) {
        $data = self::read_payload_exact( $handle, $length, $payload_length );
        if ( is_object( $hash_context ) ) {
            hash_update( $hash_context, $data );
        }
        return $data;
    }

    private static function read_payload_exact( $handle, $length, $payload_length ) {
        $position = ftell( $handle );
        if ( ! is_int( $position ) || $length < 0 || $position + $length > $payload_length ) {
            throw new RuntimeException( 'A WPCM record exceeds the payload boundary.' );
        }
        return self::read_exact( $handle, $length );
    }

    private static function skip_payload_exact( $handle, $length, $payload_length ) {
        $position = ftell( $handle );
        if ( ! is_int( $position ) || $length < 0 || $position + $length > $payload_length || 0 !== @fseek( $handle, $length, SEEK_CUR ) ) {
            throw new RuntimeException( 'A WPCM data block exceeds the payload boundary.' );
        }
    }

    private static function read_exact( $handle, $length ) {
        $data = '';
        while ( strlen( $data ) < $length ) {
            $part = fread( $handle, $length - strlen( $data ) );
            if ( ! is_string( $part ) || '' === $part ) {
                throw new RuntimeException( 'Unexpected end of WPCM container.' );
            }
            $data .= $part;
        }
        return $data;
    }

    private static function unpack_uint64( $bytes ) {
        $parts = unpack( 'Nhigh/Nlow', $bytes );
        return (int) ( $parts['high'] * 4294967296 + $parts['low'] );
    }

    private static function normalize_entry( $entry ) {
        return str_replace( '\\', '/', (string) $entry );
    }

    private static function assert_allowed_entry( $entry ) {
        if ( '' === $entry
            || false !== strpos( $entry, "\0" )
            || '/' === $entry[0]
            || '/' === substr( $entry, -1 )
            || false !== strpos( $entry, '//' )
            || preg_match( '#(^|/)\.{1,2}(/|$)#', $entry )
            || ! ( 'database.sql' === $entry || 0 === strpos( $entry, 'wp-content/' ) ) ) {
            throw new RuntimeException( 'The WPCM container contains a disallowed path.' );
        }
    }
}
