<?php
/**
 * WP-CLI commands for Clone Master backups and emergency recovery.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPCM_CLI {

    /** Register the public command tree. */
    public static function register() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
            return;
        }

        $commands = array(
            'clone-master backup create'  => 'backup_create',
            'clone-master backup list'    => 'backup_list',
            'clone-master archive info'   => 'archive_info',
            'clone-master archive verify' => 'archive_verify',
            'clone-master archive extract'=> 'archive_extract',
            'clone-master recovery-kit'   => 'recovery_kit',
            'wpcm backup create'           => 'backup_create',
            'wpcm backup list'             => 'backup_list',
            'wpcm archive info'            => 'archive_info',
            'wpcm archive verify'          => 'archive_verify',
            'wpcm archive extract'         => 'archive_extract',
            'wpcm recovery-kit'            => 'recovery_kit',
        );

        foreach ( $commands as $name => $method ) {
            WP_CLI::add_command(
                $name,
                array( __CLASS__, $method ),
                array(
                    'shortdesc' => self::short_description( $method ),
                )
            );
        }
    }

    /**
     * Create a complete WPCM backup using the same durable exporter as wp-admin.
     *
     * [--copy-to=<path>]
     * : Copy the completed archive to an additional local path.
     *
     * ## EXAMPLES
     *
     *     wp clone-master backup create
     *     wp clone-master backup create --copy-to=/srv/backups/client.wpcm
     */
    public static function backup_create( $args, $assoc_args ) {
        self::prepare_long_operation();
        $exporter = new WPCM_Exporter();
        $response = $exporter->run_step( 'init' );
        $session  = (string) ( $response['session_id'] ?? '' );
        $step     = (string) ( $response['next_step'] ?? '' );
        $package  = array();
        $last_message = '';

        if ( '' === $session || '' === $step ) {
            WP_CLI::error( 'Clone Master could not initialize the export session.' );
        }

        WP_CLI::log( 'Export session: ' . $session );
        try {
            while ( '' !== $step ) {
                $response = $exporter->run_step( $step, $session );
                $message  = trim( (string) ( $response['message'] ?? '' ) );
                if ( '' !== $message && $message !== $last_message ) {
                    WP_CLI::log( sprintf( '[%3d%%] %s', (int) ( $response['progress'] ?? 0 ), $message ) );
                    $last_message = $message;
                }
                if ( ! empty( $response['filename'] ) ) {
                    $package = $response;
                }
                $next = $response['next_step'] ?? null;
                $step = is_string( $next ) ? $next : '';
            }
        } catch ( Throwable $error ) {
            WP_CLI::error( $error->getMessage() );
        }

        $filename = (string) ( $package['filename'] ?? '' );
        $archive  = '' !== $filename ? WPCM_BACKUP_DIR . $filename : '';
        if ( '' === $archive || ! is_file( $archive ) ) {
            WP_CLI::error( 'The export finished without a published WPCM archive.' );
        }

        if ( ! empty( $assoc_args['copy-to'] ) ) {
            $copy_to = self::absolute_output_path( (string) $assoc_args['copy-to'] );
            if ( is_file( $copy_to ) || is_link( $copy_to ) ) {
                WP_CLI::error( 'The --copy-to destination already exists.' );
            }
            if ( ! is_dir( dirname( $copy_to ) ) && ! wp_mkdir_p( dirname( $copy_to ) ) ) {
                WP_CLI::error( 'Unable to create the --copy-to parent directory.' );
            }
            if ( ! @copy( $archive, $copy_to ) ) {
                WP_CLI::error( 'Unable to copy the completed WPCM archive.' );
            }
            $archive = $copy_to;
        }

        WP_CLI::success( 'Backup created: ' . $archive );
        WP_CLI::log( 'Size: ' . self::format_bytes( (int) filesize( $archive ) ) );
        WP_CLI::log( 'SHA-256: ' . (string) hash_file( 'sha256', $archive ) );
    }

    /**
     * List local WPCM archives.
     *
     * [--format=<format>]
     * : Accepted values: table, csv, json, yaml, count. Default: table.
     */
    public static function backup_list( $args, $assoc_args ) {
        $items = array();
        foreach ( glob( WPCM_BACKUP_DIR . '*.wpcm' ) ?: array() as $archive ) {
            if ( ! is_file( $archive ) || ! is_readable( $archive ) ) {
                continue;
            }
            $sidecar = $archive . '.sha256';
            $sha256  = is_file( $sidecar ) ? strtolower( trim( (string) @file_get_contents( $sidecar ) ) ) : '';
            $footer  = WPCM_Archive::footer_payload_hash( $archive );
            $items[] = array(
                'name'           => basename( $archive ),
                'size'           => self::format_bytes( (int) @filesize( $archive ) ),
                'bytes'          => (int) @filesize( $archive ),
                'modified_utc'   => gmdate( 'Y-m-d H:i:s', (int) @filemtime( $archive ) ),
                'status'         => is_string( $footer ) ? 'finalized' : 'invalid',
                'payload_sha256' => is_string( $footer ) ? $footer : '',
                'file_sha256'    => preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ? $sha256 : '',
                'path'           => $archive,
            );
        }
        usort(
            $items,
            static function ( $a, $b ) {
                return strcmp( (string) $b['modified_utc'], (string) $a['modified_utc'] );
            }
        );
        WP_CLI\Utils\format_items(
            (string) ( $assoc_args['format'] ?? 'table' ),
            $items,
            array( 'name', 'size', 'modified_utc', 'status', 'file_sha256', 'path' )
        );
    }

    /**
     * Display the authenticated structural inventory of a WPCM archive.
     *
     * <archive>
     * : Archive path or filename stored in the Clone Master backup directory.
     *
     * [--format=<format>]
     * : Accepted values: table, json, yaml. Default: table.
     */
    public static function archive_info( $args, $assoc_args ) {
        $archive = self::resolve_archive_argument( (string) ( $args[0] ?? '' ) );
        try {
            $scan = WPCM_Recovery_Tool::info( $archive );
        } catch ( Throwable $error ) {
            WP_CLI::error( $error->getMessage() );
        }
        $manifest = is_array( $scan['manifest'] ?? null ) ? $scan['manifest'] : array();
        $items = array(
            array( 'field' => 'archive', 'value' => $archive ),
            array( 'field' => 'archive_size', 'value' => self::format_bytes( (int) $scan['archive_size'] ) ),
            array( 'field' => 'entries', 'value' => (string) (int) $scan['entry_count'] ),
            array( 'field' => 'files', 'value' => (string) (int) $scan['files_count'] ),
            array( 'field' => 'files_size', 'value' => self::format_bytes( (int) $scan['files_size'] ) ),
            array( 'field' => 'database_size', 'value' => self::format_bytes( (int) $scan['database_size'] ) ),
            array( 'field' => 'site_url', 'value' => (string) ( $manifest['site_url'] ?? '' ) ),
            array( 'field' => 'created_at', 'value' => (string) ( $manifest['created_at'] ?? '' ) ),
            array( 'field' => 'wp_version', 'value' => (string) ( $manifest['wp_version'] ?? '' ) ),
            array( 'field' => 'table_prefix', 'value' => (string) ( $manifest['table_prefix'] ?? $manifest['db_prefix'] ?? '' ) ),
            array( 'field' => 'payload_sha256', 'value' => (string) $scan['payload_sha256'] ),
        );
        WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, array( 'field', 'value' ) );
    }

    /**
     * Validate a WPCM archive.
     *
     * <archive>
     * : Archive path or filename stored in the Clone Master backup directory.
     *
     * [--quick]
     * : Validate only the structure, footer and manifest inventory. The default
     *   validates every compressed block and the database checksum.
     */
    public static function archive_verify( $args, $assoc_args ) {
        self::prepare_long_operation();
        $archive = self::resolve_archive_argument( (string) ( $args[0] ?? '' ) );
        WP_CLI::log( isset( $assoc_args['quick'] ) ? 'Running structural WPCM validation...' : 'Running full WPCM block validation...' );
        try {
            $result = isset( $assoc_args['quick'] ) ? WPCM_Recovery_Tool::info( $archive ) : WPCM_Recovery_Tool::verify( $archive );
        } catch ( Throwable $error ) {
            WP_CLI::error( $error->getMessage() );
        }
        WP_CLI::success( 'The WPCM archive is valid.' );
        WP_CLI::log( 'Archive: ' . $archive );
        WP_CLI::log( 'Files: ' . number_format( (int) ( $result['files_count'] ?? 0 ), 0, '.', ' ' ) );
        WP_CLI::log( 'Expanded data: ' . self::format_bytes( (int) ( $result['uncompressed_bytes'] ?? 0 ) ) );
        WP_CLI::log( 'Payload SHA-256: ' . (string) ( $result['sha256'] ?? $result['payload_sha256'] ?? '' ) );
        if ( ! empty( $result['file_sha256'] ) ) {
            WP_CLI::log( 'File SHA-256: ' . (string) $result['file_sha256'] );
        }
    }

    /**
     * Validate and extract a WPCM archive into an empty directory.
     *
     * <archive>
     * : Archive path or filename stored in the Clone Master backup directory.
     *
     * <destination>
     * : Empty destination directory. Interrupted operations resume automatically.
     *
     * ## EXAMPLES
     *
     *     wp clone-master archive extract backup.wpcm /srv/recovery/client
     *     wp wpcm archive extract /srv/backups/site.wpcm /tmp/site-recovery
     */
    public static function archive_extract( $args, $assoc_args ) {
        self::prepare_long_operation();
        $archive     = self::resolve_archive_argument( (string) ( $args[0] ?? '' ) );
        $destination = self::absolute_output_path( (string) ( $args[1] ?? '' ) );
        $last_line   = -1;

        try {
            $result = WPCM_Recovery_Tool::extract(
                $archive,
                $destination,
                static function ( $progress ) use ( &$last_line ) {
                    $percent = (int) $progress['percent'];
                    if ( $percent === $last_line ) {
                        return;
                    }
                    $last_line = $percent;
                    WP_CLI::log(
                        sprintf(
                            '[%3d%%] %s / %s, %s/s, %d files%s',
                            $percent,
                            self::format_bytes( (int) $progress['offset'] ),
                            self::format_bytes( (int) $progress['total'] ),
                            self::format_bytes( (int) $progress['throughput'] ),
                            (int) $progress['files'],
                            '' !== (string) $progress['current_entry'] ? ' : ' . (string) $progress['current_entry'] : ''
                        )
                    );
                }
            );
        } catch ( Throwable $error ) {
            WP_CLI::error( $error->getMessage() );
        }

        WP_CLI::success( 'WPCM archive fully validated and extracted.' );
        WP_CLI::log( 'Destination: ' . $result['destination'] );
        WP_CLI::log( 'Database: ' . $result['database_path'] );
        WP_CLI::log( 'WordPress content: ' . $result['content_path'] );
        WP_CLI::log( 'Database SHA-256: ' . $result['database_sha256'] );
    }

    /** Display the autonomous recovery kit path and usage. */
    public static function recovery_kit( $args, $assoc_args ) {
        $path = WPCM_BACKUP_DIR . 'recovery-kit/wpcm-recovery.php';
        if ( ! is_file( $path ) ) {
            WPCM_Plugin::instance()->prepare_recovery_kit();
        }
        if ( ! is_file( $path ) ) {
            WP_CLI::error( 'Unable to prepare the autonomous recovery kit.' );
        }
        WP_CLI::success( 'Recovery kit ready: ' . $path );
        WP_CLI::log( 'Inspect: php ' . escapeshellarg( $path ) . ' info /path/backup.wpcm' );
        WP_CLI::log( 'Verify:  php ' . escapeshellarg( $path ) . ' verify /path/backup.wpcm' );
        WP_CLI::log( 'Extract: php ' . escapeshellarg( $path ) . ' extract /path/backup.wpcm /path/empty-directory' );
    }

    /** @return string */
    private static function short_description( $method ) {
        $map = array(
            'backup_create'  => 'Create a complete resumable WPCM backup.',
            'backup_list'    => 'List local WPCM backup archives.',
            'archive_info'   => 'Inspect a WPCM archive without extracting it.',
            'archive_verify' => 'Validate a WPCM archive and its checksums.',
            'archive_extract'=> 'Validate and extract a WPCM archive safely.',
            'recovery_kit'   => 'Show the autonomous emergency recovery tool.',
        );
        return (string) ( $map[ $method ] ?? 'Clone Master command.' );
    }

    /** @return void */
    private static function prepare_long_operation() {
        @set_time_limit( 0 );
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }
    }

    /** @return string */
    private static function resolve_archive_argument( $argument ) {
        if ( '' === trim( $argument ) ) {
            WP_CLI::error( 'A WPCM archive path or filename is required.' );
        }
        $real = realpath( $argument );
        if ( false !== $real && is_file( $real ) ) {
            return $real;
        }
        $name = basename( $argument );
        $real = realpath( WPCM_BACKUP_DIR . $name );
        if ( false === $real || ! is_file( $real ) ) {
            WP_CLI::error( 'The requested WPCM archive was not found.' );
        }
        return $real;
    }

    /** @return string */
    private static function absolute_output_path( $path ) {
        $path = trim( $path );
        if ( '' === $path ) {
            WP_CLI::error( 'A destination path is required.' );
        }
        if ( DIRECTORY_SEPARATOR !== substr( $path, 0, 1 ) && ! preg_match( '/^[A-Za-z]:[\\\\\/]/', $path ) ) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }
        return $path;
    }

    /** @return string */
    private static function format_bytes( $bytes ) {
        $bytes = max( 0, (int) $bytes );
        $units = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
        $value = (float) $bytes;
        $unit  = 0;
        while ( $value >= 1024 && $unit < count( $units ) - 1 ) {
            $value /= 1024;
            $unit++;
        }
        $decimals = 0 === $unit ? 0 : 2;
        return number_format( $value, $decimals, '.', ' ' ) . ' ' . $units[ $unit ] . ' (' . number_format( $bytes, 0, '.', ' ' ) . ' bytes)';
    }
}
