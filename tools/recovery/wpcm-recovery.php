#!/usr/bin/env php
<?php
/**
 * Clone Master autonomous WPCM recovery utility.
 *
 * This file does not bootstrap WordPress. It can inspect, fully verify and
 * extract WPCM archives when WordPress, its plugins or its theme cannot load.
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This recovery utility can only run from the command line.\n" );
    exit( 1 );
}

if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "PHP 7.4 or newer is required.\n" );
    exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'KB_IN_BYTES' ) ) {
    define( 'KB_IN_BYTES', 1024 );
}
if ( ! defined( 'MB_IN_BYTES' ) ) {
    define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
}
if ( ! defined( 'GB_IN_BYTES' ) ) {
    define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
}
if ( ! defined( 'TB_IN_BYTES' ) ) {
    define( 'TB_IN_BYTES', 1024 * GB_IN_BYTES );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
        return json_encode( $value, $flags, $depth );
    }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $target ) {
        $target = rtrim( (string) $target, '/\\' );
        return is_dir( $target ) || @mkdir( $target, 0755, true );
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        return $value;
    }
}
if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
    function wp_convert_hr_to_bytes( $value ) {
        $value = trim( (string) $value );
        if ( '-1' === $value ) {
            return -1;
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
        return $bytes;
    }
}

require_once __DIR__ . '/lib/class-archive.php';
require_once __DIR__ . '/lib/class-archive-reader.php';
require_once __DIR__ . '/lib/class-recovery-tool.php';

function wpcm_recovery_usage(): void {
    $script = basename( __FILE__ );
    echo "Clone Master WPCM Recovery Tool\n\n";
    echo "Usage:\n";
    echo "  php {$script} info <archive.wpcm> [--json]\n";
    echo "  php {$script} verify <archive.wpcm> [--json]\n";
    echo "  php {$script} extract <archive.wpcm> <empty-directory> [--json]\n\n";
    echo "The extract command validates every block, resumes after interruption,\n";
    echo "and writes database.sql plus wp-content/ into the destination.\n";
}

function wpcm_recovery_format_bytes( int $bytes ): string {
    $units = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
    $value = (float) max( 0, $bytes );
    $unit  = 0;
    while ( $value >= 1024 && $unit < count( $units ) - 1 ) {
        $value /= 1024;
        $unit++;
    }
    return number_format( $value, 0 === $unit ? 0 : 2, '.', ' ' ) . ' ' . $units[ $unit ] . ' (' . number_format( $bytes, 0, '.', ' ' ) . ' bytes)';
}

$args = $argv;
array_shift( $args );
$command = strtolower( (string) array_shift( $args ) );
$json    = false !== array_search( '--json', $args, true );
$args    = array_values( array_filter( $args, static function ( $value ) { return '--json' !== $value; } ) );

if ( in_array( $command, array( '', 'help', '--help', '-h' ), true ) ) {
    wpcm_recovery_usage();
    exit( 0 );
}

try {
    if ( 'info' === $command ) {
        if ( count( $args ) !== 1 ) {
            throw new InvalidArgumentException( 'The info command requires one archive path.' );
        }
        $result   = WPCM_Recovery_Tool::info( $args[0] );
        $manifest = is_array( $result['manifest'] ?? null ) ? $result['manifest'] : array();
        $output   = array(
            'archive'        => (string) $result['archive_path'],
            'archive_bytes'  => (int) $result['archive_size'],
            'entries'        => (int) $result['entry_count'],
            'files'          => (int) $result['files_count'],
            'files_bytes'    => (int) $result['files_size'],
            'database_bytes' => (int) $result['database_size'],
            'site_url'       => (string) ( $manifest['site_url'] ?? '' ),
            'created_at'     => (string) ( $manifest['created_at'] ?? '' ),
            'wp_version'     => (string) ( $manifest['wp_version'] ?? '' ),
            'table_prefix'   => (string) ( $manifest['table_prefix'] ?? $manifest['db_prefix'] ?? '' ),
            'payload_sha256' => (string) $result['payload_sha256'],
        );
        if ( $json ) {
            echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
        } else {
            foreach ( $output as $key => $value ) {
                if ( substr( $key, -6 ) === '_bytes' ) {
                    $value = wpcm_recovery_format_bytes( (int) $value );
                }
                echo str_pad( $key, 18 ) . ': ' . $value . "\n";
            }
        }
        exit( 0 );
    }

    if ( 'verify' === $command ) {
        if ( count( $args ) !== 1 ) {
            throw new InvalidArgumentException( 'The verify command requires one archive path.' );
        }
        if ( ! $json ) {
            echo "Validating every WPCM block and checksum...\n";
        }
        $result = WPCM_Recovery_Tool::verify( $args[0] );
        $output = array(
            'valid'              => true,
            'files'              => (int) $result['files_count'],
            'files_bytes'        => (int) $result['files_size'],
            'uncompressed_bytes' => (int) $result['uncompressed_bytes'],
            'payload_sha256'     => (string) $result['sha256'],
            'file_sha256'        => (string) $result['file_sha256'],
        );
        if ( $json ) {
            echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
        } else {
            echo "OK: archive fully validated.\n";
            echo 'Files: ' . number_format( $output['files'], 0, '.', ' ' ) . "\n";
            echo 'Expanded data: ' . wpcm_recovery_format_bytes( $output['uncompressed_bytes'] ) . "\n";
            echo 'Payload SHA-256: ' . $output['payload_sha256'] . "\n";
            echo 'File SHA-256: ' . $output['file_sha256'] . "\n";
        }
        exit( 0 );
    }

    if ( 'extract' === $command ) {
        if ( count( $args ) !== 2 ) {
            throw new InvalidArgumentException( 'The extract command requires an archive and an empty destination directory.' );
        }
        @set_time_limit( 0 );
        $last_percent = -1;
        $result = WPCM_Recovery_Tool::extract(
            $args[0],
            $args[1],
            static function ( $progress ) use ( $json, &$last_percent ): void {
                if ( $json || (int) $progress['percent'] === $last_percent ) {
                    return;
                }
                $last_percent = (int) $progress['percent'];
                echo sprintf(
                    "[%3d%%] %s / %s, %s/s, %d files\n",
                    (int) $progress['percent'],
                    wpcm_recovery_format_bytes( (int) $progress['offset'] ),
                    wpcm_recovery_format_bytes( (int) $progress['total'] ),
                    wpcm_recovery_format_bytes( (int) $progress['throughput'] ),
                    (int) $progress['files']
                );
            }
        );
        $output = array(
            'valid'             => true,
            'destination'       => (string) $result['destination'],
            'database'          => (string) $result['database_path'],
            'wp_content'        => (string) $result['content_path'],
            'files'             => (int) $result['files_count'],
            'files_bytes'       => (int) $result['files_size'],
            'payload_sha256'    => (string) $result['payload_sha256'],
            'database_sha256'   => (string) $result['database_sha256'],
        );
        if ( $json ) {
            echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
        } else {
            echo "OK: archive fully validated and extracted.\n";
            echo 'Destination: ' . $output['destination'] . "\n";
            echo 'Database: ' . $output['database'] . "\n";
            echo 'WordPress content: ' . $output['wp_content'] . "\n";
            echo 'Database SHA-256: ' . $output['database_sha256'] . "\n";
        }
        exit( 0 );
    }

    throw new InvalidArgumentException( 'Unknown command: ' . $command );
} catch ( Throwable $error ) {
    if ( $json ) {
        echo json_encode( array( 'valid' => false, 'error' => $error->getMessage() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    } else {
        fwrite( STDERR, 'Error: ' . $error->getMessage() . "\n" );
    }
    exit( 1 );
}
