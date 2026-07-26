<?php
/**
 * WP Commander compatible append-only WPCM archive container.
 *
 * Binary compatibility is intentional. The magic header, record layout,
 * chunk size, allowed entry paths, manifest schema, and footer are identical
 * to the WP Commander agent WPCMARCHIVE2 implementation.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPCM_Archive {

    public const MAGIC        = "WPCMARCHIVE2\n";
    public const FOOTER_MAGIC = 'HASH';
    public const FOOTER_BYTES = 68;
    public const CHUNK_BYTES  = 512000;

    /**
     * Create a new partial container.
     *
     * @param string $path Container path.
     * @return int Durable offset after the magic header.
     */
    public static function create( $path ) {
        $handle = @fopen( $path, 'x+b' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'Unable to create the WPCM container.' );
        }
        try {
            self::write_exact( $handle, self::MAGIC );
            self::flush( $handle );
        } finally {
            fclose( $handle );
        }
        return strlen( self::MAGIC );
    }

    /**
     * Reopen a partial container at its last durable checkpoint.
     *
     * Bytes beyond the authenticated state offset are uncommitted and are
     * discarded before the next append operation.
     *
     * @param string $path   Container path.
     * @param int    $offset Durable byte offset.
     * @return resource
     */
    public static function open_at_checkpoint( $path, $offset ) {
        $offset = (int) $offset;
        if ( $offset < strlen( self::MAGIC ) || ! is_file( $path ) ) {
            throw new RuntimeException( 'The WPCM checkpoint is invalid.' );
        }
        $size = @filesize( $path );
        if ( false === $size || $size < $offset ) {
            throw new RuntimeException( 'The WPCM container is shorter than its checkpoint.' );
        }
        $handle = @fopen( $path, 'c+b' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'Unable to reopen the WPCM container.' );
        }
        rewind( $handle );
        $magic = fread( $handle, strlen( self::MAGIC ) );
        if ( self::MAGIC !== $magic || ! @ftruncate( $handle, $offset ) || 0 !== @fseek( $handle, $offset ) ) {
            fclose( $handle );
            throw new RuntimeException( 'Unable to restore the WPCM checkpoint.' );
        }
        return $handle;
    }

    public static function append_file_header( $handle, $entry, $size, $mtime ) {
        $entry = self::normalize_entry( $entry );
        self::assert_allowed_entry( $entry );
        $path_length = strlen( $entry );
        if ( $path_length < 1 || $path_length > 1048576 ) {
            throw new RuntimeException( 'Invalid file path in WPCM container.' );
        }
        self::write_exact(
            $handle,
            'FILE' . pack( 'N', $path_length ) . self::pack_uint64( $size ) . self::pack_uint64( $mtime ) . $entry
        );
    }

    public static function append_chunk( $handle, $raw, $allow_compression = true ) {
        $raw_length = strlen( $raw );
        if ( $raw_length < 1 || $raw_length > self::CHUNK_BYTES ) {
            throw new RuntimeException( 'Invalid WPCM block size.' );
        }
        $compressed      = $allow_compression && function_exists( 'gzdeflate' ) ? gzdeflate( $raw, 1 ) : false;
        $use_compression = is_string( $compressed ) && strlen( $compressed ) + 64 < $raw_length;
        $payload         = $use_compression ? $compressed : $raw;
        $record          = 'CHNK'
            . pack( 'N', $raw_length )
            . pack( 'N', strlen( $payload ) )
            . ( $use_compression ? "\x01" : "\x00" )
            . hash( 'sha256', $raw, true )
            . $payload;
        self::write_exact( $handle, $record );
        return strlen( $record );
    }

    public static function append_file_end( $handle, $size ) {
        self::write_exact( $handle, 'FEND' . self::pack_uint64( $size ) );
    }

    /**
     * Finalize an agent-compatible WPCM container.
     *
     * @param string $path     Partial container path.
     * @param array  $manifest Manifest using schema_version 2.0.
     * @return string SHA-256 of all bytes before the footer.
     */
    public static function finalize( $path, array $manifest ) {
        $json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        if ( ! is_string( $json ) || strlen( $json ) > 16 * MB_IN_BYTES ) {
            throw new RuntimeException( 'Unable to encode the WPCM manifest.' );
        }
        $handle = @fopen( $path, 'ab' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'Unable to finalize the WPCM container.' );
        }
        try {
            self::write_exact( $handle, 'DONE' . pack( 'N', strlen( $json ) ) . $json );
            self::flush( $handle );
        } finally {
            fclose( $handle );
        }
        $hash = hash_file( 'sha256', $path );
        if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            throw new RuntimeException( 'Unable to calculate the WPCM payload checksum.' );
        }
        $handle = @fopen( $path, 'ab' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'Unable to append the WPCM footer.' );
        }
        try {
            self::write_exact( $handle, self::FOOTER_MAGIC . $hash );
            self::flush( $handle );
        } finally {
            fclose( $handle );
        }
        return $hash;
    }


    /**
     * Read the authenticated payload hash stored in a finalized footer without
     * decompressing the complete archive. This is intentionally a lightweight
     * state check; full structural validation remains available through inspect().
     *
     * @param string $path Container path.
     * @return string|null Lowercase SHA-256 payload hash, or null when unfinished.
     */
    public static function footer_payload_hash( $path ) {
        $size = @filesize( $path );
        if ( false === $size || $size < strlen( self::MAGIC ) + self::FOOTER_BYTES ) {
            return null;
        }
        $handle = @fopen( $path, 'rb' );
        if ( ! is_resource( $handle ) ) {
            return null;
        }
        try {
            if ( 0 !== @fseek( $handle, $size - self::FOOTER_BYTES ) ) {
                return null;
            }
            $footer = fread( $handle, self::FOOTER_BYTES );
        } finally {
            fclose( $handle );
        }
        if ( ! is_string( $footer ) || strlen( $footer ) !== self::FOOTER_BYTES || self::FOOTER_MAGIC !== substr( $footer, 0, 4 ) ) {
            return null;
        }
        $hash = substr( $footer, 4 );
        return preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : null;
    }

    /**
     * Fully validate and optionally extract a WPCMARCHIVE2 container.
     *
     * Every data block is decompressed and verified before publication. Files
     * are written to sibling .partial paths, flushed, and renamed atomically.
     *
     * @param string      $path         Container path.
     * @param string|null $extract_root Optional extraction root.
     * @param array       $limits       Optional max_entries/max_expanded_bytes.
     * @return array Inspection details compatible with the agent.
     */
    public static function inspect( $path, $extract_root = null, array $limits = array() ) {
        $size = @filesize( $path );
        if ( false === $size || $size < strlen( self::MAGIC ) + self::FOOTER_BYTES ) {
            throw new RuntimeException( 'The WPCM container is truncated.' );
        }
        $max_entries  = max( 1, (int) ( $limits['max_entries'] ?? 200000 ) );
        $max_expanded = max( 1, (int) ( $limits['max_expanded_bytes'] ?? min( 500 * GB_IN_BYTES, max( 2 * GB_IN_BYTES, $size * 80 ) ) ) );
        $handle       = @fopen( $path, 'rb' );
        if ( ! is_resource( $handle ) ) {
            throw new RuntimeException( 'The WPCM container is unreadable.' );
        }

        $created  = array();
        $partials = array();
        try {
            $payload_length = $size - self::FOOTER_BYTES;
            if ( 0 !== fseek( $handle, $payload_length ) ) {
                throw new RuntimeException( 'The WPCM footer is inaccessible.' );
            }
            $footer = self::read_exact( $handle, self::FOOTER_BYTES );
            if ( self::FOOTER_MAGIC !== substr( $footer, 0, 4 ) ) {
                throw new RuntimeException( 'The WPCM container was not finalized.' );
            }
            $expected_hash = substr( $footer, 4 );
            if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
                throw new RuntimeException( 'The WPCM footer is invalid.' );
            }

            rewind( $handle );
            $hash_context = hash_init( 'sha256' );
            if ( self::MAGIC !== self::read_payload_exact( $handle, strlen( self::MAGIC ), $payload_length, $hash_context ) ) {
                throw new RuntimeException( 'Unsupported WPCM container version.' );
            }

            if ( null !== $extract_root && ! is_dir( $extract_root ) && ! wp_mkdir_p( $extract_root ) ) {
                throw new RuntimeException( 'Unable to create the WPCM extraction directory.' );
            }
            $real_root = null !== $extract_root ? realpath( $extract_root ) : false;
            if ( null !== $extract_root && false === $real_root ) {
                throw new RuntimeException( 'Unable to resolve the WPCM extraction directory.' );
            }

            $manifest          = null;
            $files_count       = 0;
            $files_size        = 0;
            $uncompressed      = 0;
            $database_found    = false;
            $database_hash     = null;
            $seen              = array();
            $entry_count       = 0;

            while ( ftell( $handle ) < $payload_length ) {
                $tag = self::read_payload_exact( $handle, 4, $payload_length, $hash_context );
                if ( 'DONE' === $tag ) {
                    $manifest_length = unpack( 'Nlength', self::read_payload_exact( $handle, 4, $payload_length, $hash_context ) )['length'];
                    if ( $manifest_length < 2 || $manifest_length > 16 * MB_IN_BYTES ) {
                        throw new RuntimeException( 'The WPCM manifest length is invalid.' );
                    }
                    $manifest = json_decode( self::read_payload_exact( $handle, $manifest_length, $payload_length, $hash_context ), true );
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

                $path_length = unpack( 'Nlength', self::read_payload_exact( $handle, 4, $payload_length, $hash_context ) )['length'];
                if ( $path_length < 1 || $path_length > 1048576 ) {
                    throw new RuntimeException( 'Invalid WPCM path length.' );
                }
                $expected_size = self::unpack_uint64( self::read_payload_exact( $handle, 8, $payload_length, $hash_context ) );
                $mtime         = self::unpack_uint64( self::read_payload_exact( $handle, 8, $payload_length, $hash_context ) );
                $entry         = self::normalize_entry( self::read_payload_exact( $handle, $path_length, $payload_length, $hash_context ) );
                self::assert_allowed_entry( $entry );
                $collision = strtolower( $entry );
                if ( isset( $seen[ $collision ] ) ) {
                    throw new RuntimeException( 'The WPCM container contains a duplicate or case-colliding path.' );
                }
                $seen[ $collision ] = true;
                if ( 'database.sql' === $entry && $database_found ) {
                    throw new RuntimeException( 'The WPCM container contains multiple database dumps.' );
                }
                if ( $uncompressed + $expected_size > $max_expanded ) {
                    throw new RuntimeException( 'The WPCM expanded-size limit was exceeded.' );
                }

                $output     = null;
                $target     = null;
                $partial    = null;
                if ( null !== $extract_root ) {
                    $target = rtrim( $real_root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $entry );
                    $directory = dirname( $target );
                    if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
                        throw new RuntimeException( 'Unable to create a WPCM extraction directory.' );
                    }
                    $real_directory = realpath( $directory );
                    $root_prefix    = rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
                    if ( false === $real_directory
                        || ( $real_directory !== $real_root && 0 !== strpos( $real_directory, $root_prefix ) ) ) {
                        throw new RuntimeException( 'A WPCM extraction path escaped its protected root.' );
                    }
                    $target = rtrim( $real_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . basename( $target );
                    if ( file_exists( $target ) || is_link( $target ) ) {
                        throw new RuntimeException( 'A WPCM extraction target already exists: ' . $entry );
                    }
                    $partial = $target . '.partial';
                    if ( file_exists( $partial ) || is_link( $partial ) ) {
                        throw new RuntimeException( 'A WPCM partial extraction target already exists: ' . $entry );
                    }
                    $output = @fopen( $partial, 'xb' );
                    if ( ! is_resource( $output ) ) {
                        throw new RuntimeException( 'Unable to create a WPCM extraction file.' );
                    }
                    $partials[ $partial ] = true;
                }

                $written    = 0;
                $entry_hash = 'database.sql' === $entry ? hash_init( 'sha256' ) : null;
                try {
                    while ( true ) {
                        $chunk_tag = self::read_payload_exact( $handle, 4, $payload_length, $hash_context );
                        if ( 'FEND' === $chunk_tag ) {
                            $declared_size = self::unpack_uint64( self::read_payload_exact( $handle, 8, $payload_length, $hash_context ) );
                            if ( $declared_size !== $expected_size || $written !== $expected_size ) {
                                throw new RuntimeException( 'A WPCM file size is inconsistent.' );
                            }
                            break;
                        }
                        if ( 'CHNK' !== $chunk_tag ) {
                            throw new RuntimeException( 'Invalid WPCM data record.' );
                        }
                        $raw_length   = unpack( 'Nlength', self::read_payload_exact( $handle, 4, $payload_length, $hash_context ) )['length'];
                        $payload_size = unpack( 'Nlength', self::read_payload_exact( $handle, 4, $payload_length, $hash_context ) )['length'];
                        $compressed   = ord( self::read_payload_exact( $handle, 1, $payload_length, $hash_context ) );
                        $chunk_hash   = self::read_payload_exact( $handle, 32, $payload_length, $hash_context );
                        if ( $raw_length < 1 || $raw_length > self::CHUNK_BYTES
                            || $payload_size < 1 || $payload_size > self::CHUNK_BYTES
                            || ! in_array( $compressed, array( 0, 1 ), true )
                            || ( 0 === $compressed && $payload_size !== $raw_length )
                            || ( 1 === $compressed && $payload_size >= $raw_length ) ) {
                            throw new RuntimeException( 'A WPCM data block exceeds its safety limits.' );
                        }
                        $payload = self::read_payload_exact( $handle, $payload_size, $payload_length, $hash_context );
                        $raw     = 1 === $compressed ? @gzinflate( $payload, $raw_length ) : $payload;
                        if ( ! is_string( $raw ) || strlen( $raw ) !== $raw_length
                            || ! hash_equals( $chunk_hash, hash( 'sha256', $raw, true ) )
                            || $written + $raw_length > $expected_size ) {
                            throw new RuntimeException( 'A WPCM data block failed SHA-256 validation.' );
                        }
                        if ( is_resource( $output ) ) {
                            self::write_exact( $output, $raw );
                        }
                        if ( is_resource( $entry_hash ) || is_object( $entry_hash ) ) {
                            hash_update( $entry_hash, $raw );
                        }
                        $written += $raw_length;
                    }
                    if ( is_resource( $output ) ) {
                        self::flush( $output );
                    }
                } finally {
                    if ( is_resource( $output ) ) {
                        fclose( $output );
                    }
                }

                if ( null !== $extract_root ) {
                    if ( ! @rename( $partial, $target ) ) {
                        @unlink( $partial );
                        throw new RuntimeException( 'Unable to atomically publish a WPCM extraction file.' );
                    }
                    unset( $partials[ $partial ] );
                    $created[] = $target;
                    if ( $mtime > 0 ) {
                        @touch( $target, $mtime );
                    }
                }

                if ( 'database.sql' === $entry ) {
                    $database_found = true;
                    $database_hash  = hash_final( $entry_hash );
                } else {
                    $files_count++;
                    $files_size += $expected_size;
                }
                $uncompressed += $expected_size;
            }

            $actual_hash = hash_final( $hash_context );
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
            if ( ! hash_equals( $expected_hash, $actual_hash ) ) {
                throw new RuntimeException( 'The WPCM global payload checksum is invalid.' );
            }
            if ( ! is_string( $database_hash )
                || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $manifest['sha256_database'] ?? '' ) )
                || ! hash_equals( (string) $manifest['sha256_database'], $database_hash ) ) {
                throw new RuntimeException( 'The WPCM database checksum is invalid.' );
            }

            $file_hash = hash_file( 'sha256', $path );
            if ( ! is_string( $file_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $file_hash ) ) {
                throw new RuntimeException( 'Unable to calculate the WPCM file checksum.' );
            }
            return array(
                'manifest'           => $manifest,
                'files_count'        => $files_count,
                'files_size'         => $files_size,
                'uncompressed_bytes' => $uncompressed,
                'sha256'             => $expected_hash,
                'file_sha256'        => $file_hash,
                'archive_sha256'     => $file_hash,
            );
        } catch ( Throwable $error ) {
            foreach ( array_keys( $partials ) as $partial_path ) {
                if ( is_file( $partial_path ) || is_link( $partial_path ) ) {
                    @unlink( $partial_path );
                }
            }
            foreach ( array_reverse( $created ) as $created_path ) {
                if ( is_file( $created_path ) || is_link( $created_path ) ) {
                    @unlink( $created_path );
                }
            }
            throw $error;
        } finally {
            fclose( $handle );
        }
    }

    public static function write_exact( $handle, $data ) {
        $length = strlen( $data );
        $offset = 0;
        while ( $offset < $length ) {
            $written = @fwrite( $handle, substr( $data, $offset ) );
            if ( ! is_int( $written ) || $written < 1 ) {
                throw new RuntimeException( 'Incomplete WPCM write: disk space or quota may be exhausted.' );
            }
            $offset += $written;
        }
    }

    public static function flush( $handle ) {
        if ( ! @fflush( $handle ) ) {
            throw new RuntimeException( 'Unable to flush WPCM data.' );
        }
        if ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) {
            throw new RuntimeException( 'Unable to durably synchronize WPCM data.' );
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

    private static function read_payload_exact( $handle, $length, $payload_length, $hash_context ) {
        $position = ftell( $handle );
        if ( ! is_int( $position ) || $length < 0 || $position + $length > $payload_length ) {
            throw new RuntimeException( 'A WPCM record exceeds the payload boundary.' );
        }
        $data = self::read_exact( $handle, $length );
        hash_update( $hash_context, $data );
        return $data;
    }

    private static function pack_uint64( $value ) {
        $value = max( 0, (int) $value );
        $high  = intdiv( $value, 4294967296 );
        $low   = $value % 4294967296;
        return pack( 'NN', $high, $low );
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
