<?php
/**
 * Importer — Generates and manages a standalone installer
 *
 * APPROACH (same as Duplicator/All-in-One WP Migration):
 * Instead of importing through WordPress AJAX (which breaks when we replace
 * the database), we generate a STANDALONE PHP script that:
 * 1. Runs independently of WordPress (no WP bootstrap)
 * 2. Has its own authentication (secret token)
 * 3. Handles DB import, file extraction, URL replacement
 * 4. Deletes itself when done
 *
 * The WordPress plugin handles:
 * - Upload of the archive
 * - Extraction + manifest reading
 * - Generation of the standalone installer
 * - Redirecting the JS to talk to the installer directly
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPCM_Importer {

    /**
     * Called from WP AJAX — only handles upload, extract, and installer generation.
     * The actual import is done by the standalone installer.
     */
    public function run_step( $step, $session_id, $file_path = '', $new_url = '', $import_opts = '' ) {
        switch ( $step ) {
            case 'extract': return $this->step_extract( $file_path );
            case 'prepare': return $this->step_prepare( $session_id, $new_url, $import_opts );
            default:        throw new Exception( 'Unknown step: ' . $step );
        }
    }

    /**
     * Step 1: Extract archive and read manifest
     */
    private function step_extract( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            throw new Exception( 'Archive file not found' );
        }

        $session_id  = 'import_' . date( 'Ymd_His' ) . '_' . wp_generate_password( 6, false );
        $session_dir = WPCM_TEMP_DIR . $session_id . '/';
        wp_mkdir_p( $session_dir );

        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            throw new Exception( 'Cannot open ZIP archive' );
        }
        $zip->extractTo( $session_dir );
        $zip->close();

        // Find manifest
        $manifest_path = $this->find_manifest( $session_dir );
        if ( ! $manifest_path ) {
            throw new Exception( 'Invalid archive: manifest.json not found' );
        }
        $manifest = json_decode( file_get_contents( $manifest_path ), true );

        return [
            'session_id' => $session_id,
            'manifest'   => [
                'site_url'       => $manifest['site_url'] ?? 'Unknown',
                'wp_version'     => $manifest['wp_version'] ?? 'Unknown',
                'created_at'     => $manifest['created_at'] ?? 'Unknown',
                'db_prefix'      => $manifest['db_prefix'] ?? 'wp_',
                'tables_count'   => count( $manifest['tables'] ?? [] ),
                'active_plugins' => $manifest['active_plugins'] ?? [],
                'active_theme'   => $manifest['active_theme'] ?? 'Unknown',
            ],
            'next_step' => 'prepare',
            'progress'  => 15,
            'message'   => 'Archive extracted. Backup from: ' . ( $manifest['site_url'] ?? 'Unknown' ),
        ];
    }

    /**
     * Step 2: Generate the standalone installer and return its URL
     */
    private function step_prepare( $session_id, $new_url, $import_opts_json = '' ) {
        $session_dir = WPCM_TEMP_DIR . $session_id . '/';
        if ( ! is_dir( $session_dir ) ) {
            throw new Exception( 'Session not found' );
        }

        $manifest = json_decode( file_get_contents( $this->find_manifest( $session_dir ) ), true );

        // Generate a secure token for the installer
        $auth_token = wp_generate_password( 48, false );

        // Collect all info the installer needs
        $installer_config = [
            'auth_token'     => $auth_token,
            'session_dir'    => $session_dir,
            'old_url'        => $manifest['site_url'] ?? '',
            'old_home'       => $manifest['home_url'] ?? '',
            'new_url'        => $new_url ?: site_url(),
            'old_path'       => $manifest['abspath'] ?? '',
            'new_path'       => ABSPATH,
            'old_prefix'     => $manifest['db_prefix'] ?? 'wp_',
            'db_host'        => DB_HOST,
            'db_name'        => DB_NAME,
            'db_user'        => DB_USER,
            'db_pass'        => DB_PASSWORD,
            'db_charset'     => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
            'table_prefix'   => $GLOBALS['wpdb']->prefix,
            'wp_content_dir' => WP_CONTENT_DIR,
            'theme_root'     => get_theme_root(),
            'plugin_dir'     => WP_PLUGIN_DIR,
            'uploads_dir'    => wp_upload_dir()['basedir'],
            'abspath'        => ABSPATH,
            // The canonical WordPress slug for this plugin, e.g. "wp-clone-master/wp-clone-master.php".
            // IMPORTANT: __FILE__ here would resolve to class-importer.php (the current file),
            // not the main plugin file. We must use WPCM_PLUGIN_DIR which is defined via __FILE__
            // in wp-clone-master.php — the only place where __FILE__ gives the correct plugin root.
            'plugin_slug'    => plugin_basename( WPCM_PLUGIN_DIR . 'wp-clone-master.php' ),
            'plugin_folder'  => basename( rtrim( WPCM_PLUGIN_DIR, '/\\' ) ),
            // User-chosen import options (locale, reset_permalinks)
            'import_opts'    => json_decode( $import_opts_json ?: '{}', true ) ?: [],
        ];

        // Save config for the installer
        file_put_contents(
            $session_dir . 'installer_config.json',
            wp_json_encode( $installer_config, JSON_PRETTY_PRINT )
        );

        // Generate the standalone installer PHP file in WordPress root
        $installer_filename = 'wpcm-installer-' . substr( $auth_token, 0, 12 ) . '.php';
        $installer_path = ABSPATH . $installer_filename;
        $installer_code = $this->generate_installer_code( $session_dir, $auth_token );

        file_put_contents( $installer_path, $installer_code );

        if ( ! file_exists( $installer_path ) ) {
            throw new Exception( 'Failed to create installer at: ' . $installer_path );
        }

        $installer_url = site_url( '/' . $installer_filename );

        return [
            'session_id'    => $session_id,
            'installer_url' => $installer_url,
            'auth_token'    => $auth_token,
            'next_step'     => null,  // JS will now talk to the installer directly
            'progress'      => 20,
            'message'       => 'Standalone installer ready. Starting import...',
        ];
    }

    /**
     * Generate the standalone installer PHP code
     */
    private function generate_installer_code( $session_dir, $auth_token ) {
        $session_dir_escaped = addslashes( $session_dir );
        $auth_token_escaped  = addslashes( $auth_token );

        $installer_code = <<<'INSTALLER_PHP'
<?php
/**
 * WP Clone Master — Standalone Installer
 * This file runs OUTSIDE of WordPress to avoid session/nonce issues.
 * It self-destructs after completion.
 */
error_reporting( E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED );
@set_time_limit( 0 );
@ini_set( 'memory_limit', '512M' );

// ── Fatal-error safety net (Duplicator DUPX_Handler pattern) ──────────────────
// register_shutdown_function fires on ANY PHP termination: normal exit, die(),
// AND E_ERROR (max_execution_time exceeded, memory exhausted, parse error).
// try/catch does NOT catch E_ERROR — only this shutdown hook can.
// Without it, a fatal error inside ob_start() produces an empty HTTP body.
register_shutdown_function( function() {
    $err = error_get_last();
    if ( $err && in_array( $err['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ], true ) ) {
        // Discard any buffered output that would corrupt the JSON
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=utf-8' );
        }
        $msg = $err['message'];
        if ( stripos( $msg, 'execution time' ) !== false ) $msg = 'PHP max_execution_time exceeded. Chunk is too large — retrying will resume from here.';
        elseif ( stripos( $msg, 'memory' ) !== false )     $msg = 'PHP out of memory. Try increasing memory_limit in php.ini or .htaccess.';
        echo json_encode( [ 'success' => false, 'data' => [ 'message' => '[FATAL] ' . $msg ] ] );
    }
} );

// Capture any stray output (PHP notices, BOM, plugin hooks, etc.)
// so it never corrupts the JSON response.
ob_start();

// ============================================================
// Configuration (injected at generation time)
// ============================================================
INSTALLER_PHP
        . "\n\$SESSION_DIR = '" . $session_dir_escaped . "';\n"
        . "\$AUTH_TOKEN  = '" . $auth_token_escaped . "';\n"
        . <<<'INSTALLER_BODY'

// ============================================================
// CORS + Headers (required: JS fetch from WP admin origin)
// ============================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header( 'Access-Control-Allow-Origin: ' . $origin );
header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
header( 'Access-Control-Allow-Headers: Content-Type' );
header( 'Access-Control-Allow-Credentials: true' );
header( 'Content-Type: application/json; charset=utf-8' );
header( 'X-Content-Type-Options: nosniff' );

// Handle CORS preflight
if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) {
    http_response_code( 204 );
    exit;
}

// ============================================================
// Authentication
// ============================================================
$input_token = $_POST['auth_token'] ?? $_GET['auth_token'] ?? '';
if ( ! $input_token || ! hash_equals( $AUTH_TOKEN, $input_token ) ) {
    http_response_code( 403 );
    ob_end_clean();
    echo json_encode( [ 'success' => false, 'data' => [ 'message' => 'Unauthorized' ] ] );
    exit;
}

// Load config
$config_path = $SESSION_DIR . 'installer_config.json';
if ( ! file_exists( $config_path ) ) {
    die_json( 'Installer config not found at: ' . $config_path );
}
$CFG = json_decode( file_get_contents( $config_path ), true );
if ( ! $CFG ) {
    die_json( 'Invalid installer config' );
}

$step = $_POST['step'] ?? 'database';

// Inject resume state for the time-sliced database step
if ( $step === 'database' ) {
    $CFG['_db_file_index']     = isset( $_POST['file_index'] )    ? (int) $_POST['file_index']    : 0;
    $CFG['_db_byte_offset']    = isset( $_POST['byte_offset'] )   ? (int) $_POST['byte_offset']   : 0;
    $CFG['_db_queries_total']  = isset( $_POST['queries_total'] ) ? (int) $_POST['queries_total'] : 0;
    $CFG['_db_errors_total']   = isset( $_POST['errors_total'] )  ? (int) $_POST['errors_total']  : 0;
}
// Inject resume state for the time-sliced replace_urls step
if ( $step === 'replace_urls' ) {
    $CFG['_sr_table_index'] = isset( $_POST['table_index'] )  ? (int) $_POST['table_index']  : 0;
    $CFG['_sr_row_offset']  = isset( $_POST['row_offset'] )   ? (int) $_POST['row_offset']   : 0;
    $CFG['_sr_rows']        = isset( $_POST['sr_rows'] )      ? (int) $_POST['sr_rows']      : 0;
    $CFG['_sr_cells']       = isset( $_POST['sr_cells'] )     ? (int) $_POST['sr_cells']     : 0;
    $CFG['_sr_serial']      = isset( $_POST['sr_serial'] )    ? (int) $_POST['sr_serial']    : 0;
}

try {
    switch ( $step ) {
        case 'database':     $result = step_database( $CFG, $SESSION_DIR ); break;
        case 'files':        $result = step_files( $CFG, $SESSION_DIR ); break;
        case 'replace_urls': $result = step_replace_urls( $CFG ); break;
        case 'finalize':     $result = step_finalize( $CFG, $SESSION_DIR ); break;
        default:             die_json( 'Unknown step: ' . $step );
    }
    ob_end_clean();
    echo json_encode( [ 'success' => true, 'data' => $result ] );
} catch ( Exception $e ) {
    ob_end_clean();
    echo json_encode( [ 'success' => false, 'data' => [ 'message' => $e->getMessage() ] ] );
}
exit;

// ============================================================
// Step: Import Database
// Like Duplicator: statement-by-statement, SET max_allowed_packet,
// auto-reconnect on "gone away", proper SQL splitting
// ============================================================
function step_database( $CFG, $SESSION_DIR ) {
    // ── Time-sliced, byte-offset-resumable import ─────────────────────────────
    // Technique from All-in-One WP Migration (servmask/class-ai1wm-database.php):
    //   • ftell() saves position after each executed query
    //   • microtime() checks elapsed time
    //   • If time budget exceeded → return offset, JS calls again (fseek to resume)
    //   • Each HTTP call is guaranteed to finish within $time_budget seconds
    // This eliminates empty-response timeouts on shared hosting (30s/60s PHP limit).
    // ─────────────────────────────────────────────────────────────────────────────

    @set_time_limit( 0 ); // best-effort; ignored when disabled — that's OK

    $time_budget  = 8; // 8s — safe sous tout timeout FastCGI/Apache mutualisé (30s)
    $start_time   = microtime( true );

    $file_index   = isset( $CFG['_db_file_index'] )   ? (int) $CFG['_db_file_index']   : 0;
    $byte_offset  = isset( $CFG['_db_byte_offset'] )  ? (int) $CFG['_db_byte_offset']  : 0;
    $total_queries_so_far = isset( $CFG['_db_queries_total'] ) ? (int) $CFG['_db_queries_total'] : 0;
    $total_errors_so_far  = isset( $CFG['_db_errors_total'] )  ? (int) $CFG['_db_errors_total']  : 0;

    $sql_dir    = find_subdir( $SESSION_DIR, 'sql' );
    if ( ! $sql_dir ) throw new Exception( 'SQL directory not found' );

    $sql_files  = glob( $sql_dir . '*.sql' );
    if ( ! $sql_files ) throw new Exception( 'No SQL files found in archive' );
    sort( $sql_files );
    $total_files = count( $sql_files );

    // All files already processed
    if ( $file_index >= $total_files ) {
        return [
            'file_index'   => $file_index,
            'byte_offset'  => 0,
            'total_files'  => $total_files,
            'queries'      => 0,
            'errors'       => 0,
            'next_step'    => 'files',
            'progress'     => 45,
            'message'      => 'Database import complete. Restoring files...',
        ];
    }

    $mysqli = db_connect( $CFG );

    // ── Pre-drop toutes les tables de destination (premier appel uniquement) ────────
    // Duplicator/AI1WM strategy: purger la DB avant import évite les conflits FK et
    // les "Duplicate entry" sur INSERT IGNORE quand des tables existent déjà.
    //
    // Optimisation critique sur hébergement mutualisé (nantesodyssey.fr, etc.) :
    // N appels DROP TABLE individuels → N round-trips MySQL → risque de timeout FastCGI.
    // Solution : un seul DROP TABLE t1, t2, t3 (batch) = 1 round-trip, ~10× plus rapide.
    if ( $file_index === 0 && $byte_offset === 0 ) {
        $mysqli->query( "SET FOREIGN_KEY_CHECKS = 0" );
        $existing = $mysqli->query(
            "SHOW TABLES LIKE '" . $mysqli->real_escape_string( $CFG['table_prefix'] ) . "%'"
        );
        if ( $existing ) {
            $tables_to_drop = [];
            while ( $trow = $existing->fetch_row() ) {
                $tables_to_drop[] = '`' . $trow[0] . '`';
            }
            $existing->free();
            // Un seul DROP TABLE batch = 1 requête quelle que soit la taille de la DB
            if ( ! empty( $tables_to_drop ) ) {
                $mysqli->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $tables_to_drop ) );
            }
        }
        $mysqli->query( "SET FOREIGN_KEY_CHECKS = 1" );
    }

    // Read max_allowed_packet without touching it (needs SUPER / read-only on MySQL 8+)
    $mp_r       = $mysqli->query( "SELECT @@session.max_allowed_packet" );
    $max_packet = $mp_r ? (int) $mp_r->fetch_row()[0] : 1048576;
    if ( $mp_r ) $mp_r->free();
    $max_packet = max( $max_packet - 256, 65536 );

    foreach ( [
        "SET SESSION wait_timeout = 600",
        "SET SESSION net_read_timeout = 600",
        "SET SESSION net_write_timeout = 600",
        "SET SESSION interactive_timeout = 600",
        "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'",
        "SET FOREIGN_KEY_CHECKS = 0",
    ] as $_q ) { $mysqli->query( $_q ); }

    $old_prefix   = $CFG['old_prefix'];
    $new_prefix   = $CFG['table_prefix'];
    $call_queries = 0;
    $call_errors  = 0;
    $errors_log   = [];
    $completed    = true; // will be set false if time budget exceeded mid-file

    $sql_file  = $sql_files[ $file_index ];
    $file_size = filesize( $sql_file );

    $fh = @fopen( $sql_file, 'r' );
    if ( ! $fh ) throw new Exception( 'Cannot open SQL file: ' . basename( $sql_file ) );

    // Resume from saved byte position (the key: same as All-in-One WP Migration)
    if ( $byte_offset > 0 ) {
        fseek( $fh, $byte_offset );
    }

    $buffer = '';
    $in_str = false;
    $escape = false;

    while ( ! feof( $fh ) ) {
        $line = fgets( $fh, 1048576 ); // 1 MB line buffer — handles large BLOBs
        if ( $line === false ) break;

        // Prefix replacement
        if ( $old_prefix !== $new_prefix ) {
            $line = str_replace( '`' . $old_prefix, '`' . $new_prefix, $line );
        }

        // Skip comment-only lines fast
        $trimmed = ltrim( $line );
        if ( $trimmed === '' ) continue;
        if ( $trimmed[0] === '#' ) continue;
        if ( strlen( $trimmed ) >= 2 && $trimmed[0] === '-' && $trimmed[1] === '-' ) continue;

        // Parse character by character to detect statement end (`;` outside strings)
        for ( $i = 0, $len = strlen( $line ); $i < $len; $i++ ) {
            $c = $line[ $i ];
            if ( $escape )         { $buffer .= $c; $escape = false; continue; }
            if ( $c === '\\' )     { $buffer .= $c; $escape = true;  continue; }
            if ( $c === "'" && ! $in_str ) { $in_str = true;  $buffer .= $c; continue; }
            if ( $c === "'" && $in_str )   { $in_str = false; $buffer .= $c; continue; }

            if ( $c === ';' && ! $in_str ) {
                $stmt = trim( $buffer );
                $buffer = '';
                if ( $stmt === '' ) continue;

                // ── Guard against plugin-recreated tables (Fix: duplicate entry on 'url') ──
                // Some destination-site plugins (Yoast, Redirection, Pretty Links …) run
                // their activation/init hooks during the WP request that launched this
                // installer and silently INSERT seed rows into tables they create — even
                // after our pre-drop. If the backup then tries to INSERT the same URL the
                // plugin already inserted, MySQL raises error 1062.
                // Solution: whenever the SQL file emits a CREATE TABLE (with or without
                // IF NOT EXISTS), DROP the table first so it is guaranteed empty.
                // This mirrors what Duplicator and UpdraftPlus do in their SQL parsers.
                $to_run = [];
                if ( preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`[^`]+`|\S+)/i', $stmt, $ct_m ) ) {
                    $tbl_name = $ct_m[1];
                    // Normalise prefix if old != new (same replacement already applied to $stmt above)
                    $to_run[] = "DROP TABLE IF EXISTS {$tbl_name}";
                }
                if ( strlen( $stmt ) > $max_packet &&
                     preg_match( '/^(INSERT[\s\S]{1,60}?(?:VALUES|VALUE))\s*(\([\s\S]*)/i', $stmt, $m ) ) {
                    $ins_pfx = $m[1];
                    $rp      = $m[2];
                    $rows = []; $rb = ''; $dep = 0; $rs = false; $re = false;
                    for ( $j = 0, $rl = strlen( $rp ); $j < $rl; $j++ ) {
                        $rc = $rp[$j];
                        if ( $re )              { $rb .= $rc; $re = false; continue; }
                        if ( $rc === '\\' )     { $rb .= $rc; $re = true;  continue; }
                        if ( $rc === "'" && !$rs ) { $rs = true;  $rb .= $rc; continue; }
                        if ( $rc === "'" && $rs  ) { $rs = false; $rb .= $rc; continue; }
                        if ( !$rs ) {
                            if ( $rc === '(' ) { $dep++; $rb .= $rc; continue; }
                            if ( $rc === ')' ) {
                                $dep--; $rb .= $rc;
                                if ( $dep === 0 ) { $rows[] = $rb; $rb = ''; continue; }
                                continue;
                            }
                            if ( $rc === ',' && $dep === 0 ) continue;
                        }
                        $rb .= $rc;
                    }
                    if ( $rb !== '' ) $rows[] = $rb;
                    $chunk = []; $csz = strlen( $ins_pfx ) + 10;
                    foreach ( $rows as $row ) {
                        $rl2 = strlen( $row ) + 1;
                        if ( !empty( $chunk ) && ( $csz + $rl2 ) > $max_packet ) {
                            $to_run[] = $ins_pfx . ' ' . implode( ',', $chunk );
                            $chunk = []; $csz = strlen( $ins_pfx ) + 10;
                        }
                        $chunk[] = $row; $csz += $rl2;
                    }
                    if ( !empty( $chunk ) ) $to_run[] = $ins_pfx . ' ' . implode( ',', $chunk );
                } else {
                    $to_run[] = $stmt;
                }

                // ── Auto-reconnect ──────────────────────────────────────────
                if ( ! @$mysqli->ping() ) {
                    $mysqli->close(); $mysqli = db_connect( $CFG );
                    $mysqli->query( "SET FOREIGN_KEY_CHECKS = 0" );
                    $mysqli->query( "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'" );
                }

                // ── Execute queries ─────────────────────────────────────────
                foreach ( $to_run as $q ) {
                    $res = $mysqli->query( $q );
                    if ( $res === false ) {
                        $errno = $mysqli->errno;
                        $err   = $mysqli->error;

                        if ( stripos( $err, 'gone away' ) !== false || stripos( $err, 'lost connection' ) !== false ) {
                            // Reconnect and retry
                            $mysqli->close(); $mysqli = db_connect( $CFG );
                            $mysqli->query( "SET FOREIGN_KEY_CHECKS = 0" );
                            $res = $mysqli->query( $q );
                            $errno = $res === false ? $mysqli->errno : 0;
                            $err   = $res === false ? $mysqli->error : '';
                        }

                        if ( $res === false ) {
                            // errno 1062 = Duplicate entry — rewrite INSERT as REPLACE INTO
                            // REPLACE INTO does DELETE + INSERT, so the backup row always wins
                            // over any row pre-inserted by a plugin hook after the pre-drop.
                            if ( $errno === 1062 && preg_match( '/^INSERT\s+INTO\s/i', $q ) ) {
                                $q_replace = preg_replace( '/^INSERT\s+INTO\s/i', 'REPLACE INTO ', $q );
                                $res = $mysqli->query( $q_replace );
                                if ( $res !== false ) {
                                    if ( $res instanceof mysqli_result ) $res->free();
                                    $call_queries++;
                                    continue; // success with REPLACE — no error logged
                                }
                            }
                            $call_errors++;
                            if ( count( $errors_log ) < 10 ) {
                                $errors_log[] = '[' . $errno . '] ' . substr( $q, 0, 80 ) . ': ' . $mysqli->error;
                            }
                        }
                    }
                    if ( $res instanceof mysqli_result ) $res->free();
                    $call_queries++;
                }

                // ── Time budget check (All-in-One WP Migration technique) ───
                // Save position AFTER the query — same as ftell() in ai1wm-database.php
                $current_offset = ftell( $fh );
                if ( ( microtime( true ) - $start_time ) > $time_budget ) {
                    // Time exceeded — return current position, JS will resume
                    $completed = false;
                    break 2; // break out of both for() and while()
                }

                continue;
            }
            $buffer .= $c;
        }
    }

    $final_offset = $completed ? 0 : ftell( $fh );
    fclose( $fh );

    $mysqli->query( "SET FOREIGN_KEY_CHECKS = 1" );

    $next_file_index = $file_index;
    $next_byte_offset = $final_offset;

    if ( $completed ) {
        // File done — advance to next
        $next_file_index  = $file_index + 1;
        $next_byte_offset = 0;

        // On last file: force siteurl/home to new URL
        if ( $next_file_index >= $total_files ) {
            $opts = $new_prefix . 'options';
            $esc  = $mysqli->real_escape_string( $CFG['new_url'] );
            $mysqli->query( "UPDATE `{$opts}` SET option_value='{$esc}' WHERE option_name='siteurl'" );
            $mysqli->query( "UPDATE `{$opts}` SET option_value='{$esc}' WHERE option_name='home'" );
        }
    }
    $mysqli->close();

    $all_queries = $total_queries_so_far + $call_queries;
    $all_errors  = $total_errors_so_far  + $call_errors;
    $is_done     = ( $next_file_index >= $total_files );
    $pct         = $is_done ? 45 : ( 20 + (int)( ( $next_file_index / $total_files ) * 25 ) );

    return [
        'file_index'    => $next_file_index,
        'byte_offset'   => $next_byte_offset,
        'total_files'   => $total_files,
        'queries'       => $all_queries,
        'errors'        => $all_errors,
        'errors_log'    => $errors_log,
        'completed'     => $completed,
        'next_step'     => $is_done ? 'files' : 'database',
        'progress'      => $pct,
        'message'       => ( $is_done
            ? 'Database import complete. ' . $all_queries . ' queries.'
            : 'DB ' . $next_file_index . '/' . $total_files
              . ' (file ' . basename( $sql_files[ min( $file_index, $total_files-1 ) ] ) . ')'
              . ' — ' . $all_queries . ' queries'
              . ( $all_errors ? ", {$all_errors} errors" : '' ) ),
    ];
}

// Helper: connect to DB
function db_connect( $CFG ) {
    // PHP 8.1 changed MySQLi default error mode to MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT.
    // Any MySQL error (including 1062 Duplicate entry) now throws mysqli_sql_exception
    // instead of returning false. Our code checks $res === false and $mysqli->errno manually,
    // so we must disable exception mode — exactly as WordPress core did in Trac #52825,
    // and as Duplicator and AI1WM do in their DB importers.
    mysqli_report( MYSQLI_REPORT_OFF );

    $mysqli = new mysqli( $CFG['db_host'], $CFG['db_user'], $CFG['db_pass'], $CFG['db_name'] );
    if ( $mysqli->connect_errno ) {
        throw new Exception( 'DB connection failed: ' . $mysqli->connect_error );
    }
    $mysqli->set_charset( $CFG['db_charset'] ?: 'utf8mb4' );
    $mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 600 );
    return $mysqli;
}

// Split SQL content into individual statements (handles strings with semicolons)
function sql_split( $content ) {
    $stmts   = [];
    $current = '';
    $in_str  = false;
    $escape  = false;
    $len     = strlen( $content );

    for ( $i = 0; $i < $len; $i++ ) {
        $c = $content[ $i ];
        if ( $escape )   { $current .= $c; $escape = false; continue; }
        if ( $c === '\\' ) { $current .= $c; $escape = true; continue; }
        if ( $c === "'" && ! $in_str ) { $in_str = true;  $current .= $c; continue; }
        if ( $c === "'" && $in_str )   { $in_str = false; $current .= $c; continue; }
        if ( $c === ';' && ! $in_str ) {
            $t = trim( $current );
            if ( $t !== '' ) $stmts[] = $t;
            $current = '';
            continue;
        }
        $current .= $c;
    }
    $t = trim( $current );
    if ( $t !== '' ) $stmts[] = $t;
    return $stmts;
}

// ============================================================
// Step: Restore Files
// ============================================================
function step_files( $CFG, $SESSION_DIR ) {
    $files_dir = find_subdir( $SESSION_DIR, 'files' );
    if ( ! $files_dir ) {
        return [
            'next_step' => 'replace_urls',
            'progress'  => 65,
            'message'   => 'No file archives found. Running URL replacement...',
        ];
    }

    // ── Full wp-content wipe before restore (Duplicator "clean install" approach) ───
    // Duplicator and AI1WM both document this clearly: the destination must be clean.
    // "The process will overwrite everything — any existing content will be permanently lost."
    // Our previous approach (partial wipe by category) was wrong: it left orphaned files
    // from anything not covered by a given backup, causing size drift across migrations.
    //
    // Correct strategy: wipe ALL of wp-content before extracting the backup, keeping ONLY
    // wp-clone-master (the plugin must stay on disk so WordPress doesn't deactivate it).
    //
    // This is safe because:
    //   • The installer is standalone PHP — it does not need WordPress running.
    //   • WordPress only boots AFTER step_files has fully restored all files from backup.
    //   • The backup is a complete snapshot: themes, plugins, uploads, languages, drop-ins.
    //   • object-cache.php / advanced-cache.php from the old site are always wiped, which
    //     eliminates the stale-cache "plugin file does not exist" notice permanently.

    $wc_dir = rtrim( $CFG['wp_content_dir'], '/' );

    // Derive the top-level name of our temp directory inside wp-content.
    // e.g. wp-content/wpcm-temp/ → 'wpcm-temp'
    // We MUST keep it during the wipe: it contains installer_config.json and
    // all the SQL/zip archives we are currently importing.
    $session_wc_rel = basename( rtrim( dirname( rtrim( $SESSION_DIR, '/' ) ), '/' ) );
    // Also keep wpcm-backups and wpcm-logs — user data outside the migration scope.
    $wc_keep = [ '.', '..', $session_wc_rel, 'wpcm-backups', 'wpcm-logs' ];

    if ( is_dir( $wc_dir ) ) {
        foreach ( scandir( $wc_dir ) ?: [] as $item ) {
            if ( in_array( $item, $wc_keep, true ) ) continue;

            $path = $wc_dir . '/' . $item;

            // plugins/ — wipe contents but keep our own plugin folder
            if ( $item === 'plugins' && is_dir( $path ) ) {
                $own_folder = $CFG['plugin_folder'] ?? 'wp-clone-master';
                foreach ( scandir( $path ) ?: [] as $plugin_item ) {
                    if ( $plugin_item === '.' || $plugin_item === '..' ) continue;
                    if ( $plugin_item === $own_folder ) continue;
                    $pp = $path . '/' . $plugin_item;
                    if ( is_dir( $pp ) )      recursive_delete( $pp );
                    elseif ( is_file( $pp ) ) @unlink( $pp );
                }
                continue;
            }

            // Everything else: themes, uploads, mu-plugins, languages,
            // object-cache.php, advanced-cache.php, db.php, etc.
            if ( is_dir( $path ) )      recursive_delete( $path );
            elseif ( is_file( $path ) ) @unlink( $path );
        }
    }

    $zip_files = glob( $files_dir . '*.zip' );
        // ── Now restore from backup archives ──────────────────────────────────────
    $restored = 0;
    $errors = [];

    foreach ( $zip_files as $zip_path ) {
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            $errors[] = basename( $zip_path );
            continue;
        }

        // Peek at first entry to determine destination
        $first = $zip->getNameIndex( 0 );
        $top   = explode( '/', $first )[0];

        $target = null;
        if ( strpos( $top, 'themes' ) === 0 )     $target = $CFG['theme_root'];
        elseif ( strpos( $top, 'plugins' ) === 0 ) $target = $CFG['plugin_dir'];
        elseif ( strpos( $top, 'uploads' ) === 0 ) $target = $CFG['uploads_dir'];
        elseif ( $top === 'mu-plugins' )           $target = $CFG['wp_content_dir'] . '/mu-plugins';
        elseif ( $top === 'languages' )            $target = $CFG['wp_content_dir'] . '/languages';
        elseif ( $top === 'dropins' )              $target = $CFG['wp_content_dir']; // drop-ins live at wp-content root

        if ( ! $target ) {
            $zip->close();
            continue;
        }

        // Extract to temp then copy to destination
        $temp = $SESSION_DIR . 'ext_' . md5( $zip_path ) . '/';
        @mkdir( $temp, 0755, true );
        $zip->extractTo( $temp );
        $zip->close();

        // Find actual content root inside temp
        $source = is_dir( $temp . $top ) ? $temp . $top . '/' : $temp;

        // Determine sub-path (e.g., for uploads/2024/01)
        $category_prefixes = [ 'themes/', 'plugins/', 'uploads/', 'mu-plugins/', 'languages/', 'dropins/' ];
        $sub_path = '';
        foreach ( $category_prefixes as $pfx ) {
            if ( strpos( $top . '/', $pfx ) === 0 ) {
                $remaining = substr( $top, strlen( rtrim( $pfx, '/' ) ) );
                $sub_path  = ltrim( $remaining, '/' );
                break;
            }
        }

        $dest = $target;
        if ( $sub_path ) {
            $dest = $target . '/' . $sub_path;
            @mkdir( $dest, 0755, true );
        }

        copy_recursive( $source, $dest );
        recursive_delete( $temp );
        $restored++;
    }

    // Root files
    $root_patterns = [ '.htaccess', 'robots.txt', '.user.ini', 'php.ini' ];
    foreach ( $root_patterns as $f ) {
        $src = $files_dir . 'root_' . $f;
        if ( file_exists( $src ) ) {
            copy( $src, $CFG['abspath'] . $f );
        }
    }

    return [
        'restored'  => $restored,
        'errors'    => $errors,
        'next_step' => 'replace_urls',
        'progress'  => 65,
        'message'   => $restored . ' archives restored. Running URL replacement...',
    ];
}

// ============================================================
// Step: Replace URLs (serialization-safe)
// ============================================================
function step_replace_urls( $CFG ) {
    // ── Time-sliced, resumable URL search-replace ─────────────────────────────
    // Same microtime/table-index technique as step_database.
    // Each HTTP call processes tables until the time budget is exceeded,
    // then returns the next table_index for JS to resume.
    // On first call (table_index=0): force siteurl/home immediately.
    // On last call (all tables done): re-force siteurl/home and return 'finalize'.
    // ─────────────────────────────────────────────────────────────────────────────

    @set_time_limit( 0 );
    $time_budget = 3; // AI1WM standard: 3s per HTTP call, safe on any server
    $start_time  = microtime( true );

    $old_url     = $CFG['old_url'];
    $new_url     = rtrim( $CFG['new_url'], '/' );
    $prefix      = $CFG['table_prefix'];
    $table_index = isset( $CFG['_sr_table_index'] ) ? (int) $CFG['_sr_table_index'] : 0;
    $rows_so_far = isset( $CFG['_sr_rows'] )        ? (int) $CFG['_sr_rows']        : 0;
    $cells_so_far= isset( $CFG['_sr_cells'] )       ? (int) $CFG['_sr_cells']       : 0;
    $serial_so_far=isset( $CFG['_sr_serial'] )      ? (int) $CFG['_sr_serial']      : 0;

    mysqli_report( MYSQLI_REPORT_OFF ); // PHP 8.1+: disable auto-exceptions — same as WP core Trac #52825
    $mysqli = new mysqli( $CFG['db_host'], $CFG['db_user'], $CFG['db_pass'], $CFG['db_name'] );
    if ( $mysqli->connect_errno ) throw new Exception( 'DB connection failed: ' . $mysqli->connect_error );
    $mysqli->set_charset( $CFG['db_charset'] ?: 'utf8mb4' );

    $esc_new = $mysqli->real_escape_string( $new_url );

    // ── ALWAYS force siteurl + home on first call ──────────────────────────────
    // Duplicator pattern: set this unconditionally BEFORE any table scanning.
    // WordPress can boot immediately even if the scan times out later.
    if ( $table_index === 0 ) {
        $mysqli->query( "UPDATE `{$prefix}options` SET option_value = '{$esc_new}' WHERE option_name = 'siteurl'" );
        $mysqli->query( "UPDATE `{$prefix}options` SET option_value = '{$esc_new}' WHERE option_name = 'home'" );
    }

    $same_url  = ( ! $old_url || rtrim( $old_url, '/' ) === $new_url );
    $same_path = ( ! $CFG['old_path'] || $CFG['old_path'] === $CFG['new_path'] );

    // If URLs and paths are identical, no scan needed — return immediately
    if ( $same_url && $same_path ) {
        $mysqli->close();
        return [
            'table_index' => 0,
            'total_tables'=> 0,
            'rows'        => 0,
            'cells'       => 0,
            'serial'      => 0,
            'next_step'   => 'finalize',
            'progress'    => 85,
            'message'     => 'Same URL — siteurl/home forced to new domain.',
        ];
    }

    // Build replacement pairs (deduplicated)
    $pairs = build_url_pairs( $old_url, $new_url, $CFG['old_path'], $CFG['new_path'] );
    $old_home = $CFG['old_home'] ?? '';
    if ( $old_home && rtrim( $old_home, '/' ) !== $new_url ) {
        $pairs = array_merge( $pairs, build_url_pairs( $old_home, $new_url, $CFG['old_path'], $CFG['new_path'] ) );
    }
    $seen = [];
    $pairs = array_values( array_filter( $pairs, function( $p ) use ( &$seen ) {
        if ( $p[0] === $p[1] ) return false;
        if ( isset( $seen[ $p[0] ] ) ) return false;
        $seen[ $p[0] ] = true;
        return true;
    } ) );

    // Disable STRICT mode once for the session — avoids 1406 errors on narrow columns
    $mysqli->query( "SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode,'STRICT_TRANS_TABLES','')" );
    $mysqli->query( "SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode,'STRICT_ALL_TABLES','')" );

    // ── ONE query to get ALL text columns for ALL prefix tables ────────────────
    // Avoids N×DESCRIBE calls (one per table). On 100 tables that's 100 MySQL
    // round-trips saved per HTTP call — critical for staying within time budget.
    $db_name = $CFG['db_name'];
    $pfx_esc = $mysqli->real_escape_string( $prefix );
    $schema_sql = "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY
                   FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string( $db_name ) . "'
                     AND TABLE_NAME LIKE '{$pfx_esc}%'
                     AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','blob','enum','set')
                   ORDER BY TABLE_NAME, ORDINAL_POSITION";
    $schema_r = $mysqli->query( $schema_sql );

    $table_cols    = []; // table → [ col, ... ]
    $table_primary = []; // table → primary col
    $table_maxlen  = []; // table → [ col → maxlen ]
    if ( $schema_r ) {
        while ( $sc = $schema_r->fetch_assoc() ) {
            $t = $sc['TABLE_NAME'];
            $c = $sc['COLUMN_NAME'];
            $table_cols[$t][]  = $c;
            if ( $sc['COLUMN_KEY'] === 'PRI' && ! isset( $table_primary[$t] ) ) {
                $table_primary[$t] = $c;
            }
            $type = $sc['COLUMN_TYPE'];
            if ( preg_match( '/(?:var)?char\s*\((\d+)\)/i', $type, $lm ) ) {
                $table_maxlen[$t][$c] = (int) $lm[1];
            } elseif ( stripos( $type, 'longtext' ) !== false ) {
                $table_maxlen[$t][$c] = PHP_INT_MAX;
            } elseif ( stripos( $type, 'mediumtext' ) !== false ) {
                $table_maxlen[$t][$c] = 16777215;
            } elseif ( stripos( $type, 'text' ) !== false ) {
                $table_maxlen[$t][$c] = 65535;
            } else {
                $table_maxlen[$t][$c] = PHP_INT_MAX;
            }
        }
        $schema_r->free();
    }

    // For tables without a PRI column in text cols, get the first column via SHOW
    // (rare edge case — only needed for unusual tables)

    $tables = array_keys( $table_cols );
    sort( $tables );
    $total_tables = count( $tables );

    // ── Tables with UNIQUE URL indexes: TRUNCATE instead of search-replace ──────
    // Industry consensus (Pressable, WP-Staging, Yoast themselves): tables that
    // carry a UNIQUE key on a URL/permalink column MUST NOT be search-replaced.
    // Changing old-domain → new-domain via UPDATE causes a 1062 collision when
    // two rows end up with the same permalink after the rename.
    // Correct approach: TRUNCATE on first call; exclude from scan on every call.
    //   • wp_yoast_indexable*  — Yoast SEO rebuilds on next page load / wp yoast index
    //   • wp_redirection_items — plugin rebuilds on next admin load
    // References: Pressable Migration docs, WP-Staging source, Yoast GitHub #18269.
    $skip_table_patterns = [
        $prefix . 'yoast_indexable',       // exact match (also matches *_hierarchy below via prefix)
        $prefix . 'yoast_',                // all yoast_ tables
        $prefix . 'redirection_items',     // Redirection plugin: UNIQUE KEY on `url`
    ];
    $should_skip = function( $t ) use ( $skip_table_patterns ) {
        foreach ( $skip_table_patterns as $pat ) {
            if ( strpos( $t, $pat ) === 0 ) return true;
        }
        return false;
    };

    // On first call: TRUNCATE any of these tables that exist
    if ( $table_index === 0 ) {
        foreach ( $tables as $t ) {
            if ( ! $should_skip( $t ) ) continue;
            $mysqli->query( "TRUNCATE TABLE `{$t}`" );
        }
    }

    // Always exclude them from the search-replace scan (even on resume calls)
    $tables       = array_values( array_filter( $tables, function( $t ) use ( $should_skip ) {
        return ! $should_skip( $t );
    } ) );
    $total_tables = count( $tables );

    // row_offset: resume mid-table (like WP Migrate DB Pro's wpmdb_rows_per_segment)
    $row_offset  = isset( $CFG['_sr_row_offset'] ) ? (int) $CFG['_sr_row_offset'] : 0;

    $call_rows   = 0;
    $call_cells  = 0;
    $call_serial = 0;
    $completed   = true;

    for ( $i = $table_index; $i < $total_tables; $i++ ) {
        $t_name    = $tables[$i];
        $t_cols    = $table_cols[$t_name]    ?? [];
        $t_primary = $table_primary[$t_name] ?? null;
        $t_maxlen  = $table_maxlen[$t_name]  ?? [];

        // If no primary from schema, fall back to DESCRIBE (rare)
        if ( ! $t_primary ) {
            $dr = $mysqli->query( "DESCRIBE `{$t_name}`" );
            if ( $dr ) {
                while ( $dc = $dr->fetch_object() ) {
                    if ( $dc->Key === 'PRI' ) { $t_primary = $dc->Field; break; }
                }
                $dr->free();
            }
        }
        if ( ! $t_primary && ! empty( $t_cols ) ) {
            $t_primary = $t_cols[0]; // last resort — use first text col as PK
        }
        if ( ! $t_primary ) continue;

        // Process table in row-batches; stop when time budget exceeded
        $ts = replace_in_table_sliced(
            $mysqli, $t_name, $t_cols, $t_primary, $t_maxlen, $pairs,
            $row_offset, $time_budget, $start_time
        );
        $call_rows   += $ts['rows'];
        $call_cells  += $ts['cells'];
        $call_serial += $ts['serialized'];

        if ( ! $ts['table_done'] ) {
            // Time ran out mid-table — resume at same table, next offset
            $completed   = false;
            $table_index = $i;
            $row_offset  = $ts['next_offset'];
            break;
        }

        // Table fully done — reset offset for next table
        $row_offset = 0;

        if ( ( microtime( true ) - $start_time ) > $time_budget ) {
            $completed   = false;
            $table_index = $i + 1;
            break;
        }
    }

    $all_rows  = $rows_so_far  + $call_rows;
    $all_cells = $cells_so_far + $call_cells;
    $all_serial= $serial_so_far+ $call_serial;

    if ( $completed ) {
        // All tables done — re-force siteurl/home as final belt-and-suspenders
        $mysqli->query( "UPDATE `{$prefix}options` SET option_value = '{$esc_new}' WHERE option_name = 'siteurl'" );
        $mysqli->query( "UPDATE `{$prefix}options` SET option_value = '{$esc_new}' WHERE option_name = 'home'" );
        $mysqli->close();
        return [
            'table_index'  => $total_tables,
            'total_tables' => $total_tables,
            'row_offset'   => 0,
            'rows'         => $all_rows,
            'cells'        => $all_cells,
            'serial'       => $all_serial,
            'next_step'    => 'finalize',
            'progress'     => 85,
            'message'      => "URLs replaced: {$all_rows} rows, {$all_cells} cells, {$all_serial} serialized fixes",
        ];
    }

    $mysqli->close();
    $pct = 65 + (int)( ( $table_index / max( $total_tables, 1 ) ) * 20 );
    return [
        'table_index'  => $table_index,
        'total_tables' => $total_tables,
        'row_offset'   => $row_offset,
        'rows'         => $all_rows,
        'cells'        => $all_cells,
        'serial'       => $all_serial,
        'next_step'    => 'replace_urls',
        'progress'     => $pct,
        'message'      => "URL replace: table {$table_index}/{$total_tables}, row {$row_offset} — {$all_rows} rows updated",
    ];
}

// ============================================================
// Step: Finalize & self-destruct
// ============================================================
function step_finalize( $CFG, $SESSION_DIR ) {
    mysqli_report( MYSQLI_REPORT_OFF ); // PHP 8.1+: disable auto-exceptions
    $mysqli = new mysqli( $CFG['db_host'], $CFG['db_user'], $CFG['db_pass'], $CFG['db_name'] );
    if ( ! $mysqli->connect_errno ) {
        $prefix = $CFG['table_prefix'];

        // ── Standard WordPress cleanup ─────────────────────────────────────────
        // Transients (object cache entries with TTL)
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE '\_transient\_%'" );
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE '\_site\_transient\_%'" );
        // Rewrite rules — WordPress will regenerate on next load
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'" );
        // Any cached site URL overrides
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'auth_key'" );

        // ── Elementor cleanup ──────────────────────────────────────────────────
        // Elementor stores CSS as a serialized PHP object in postmeta (_elementor_css).
        // These objects contain absolute file paths/URLs to the old domain.
        // Deleting them forces Elementor to regenerate CSS with the new domain
        // on first page load — this is the approach Elementor itself recommends
        // (Elementor > Tools > Regenerate CSS).
        // Source: https://elementor.com/help/site-migration/
        $mysqli->query( "DELETE FROM `{$prefix}postmeta` WHERE meta_key = '_elementor_css'" );
        $mysqli->query( "DELETE FROM `{$prefix}postmeta` WHERE meta_key = '_elementor_page_assets'" );

        // Elementor global CSS option
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'elementor_css'" );
        // Elementor kit CSS
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE 'elementor\_css\_%'" );
        // Elementor version/data flags that reference URLs
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'elementor_global_css'" );
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = '_elementor_global_css'" );

        // ── WP Rocket / W3 Total Cache / WP Super Cache ────────────────────────
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE 'rocket\_%'" );
        $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE 'w3tc\_%'" );


        // ── Restore WordPress drop-in files from their plugin sources ───────────
        // Drop-in files (object-cache.php, advanced-cache.php…) live in wp-content/
        // and are installed by cache plugins when you enable their features.
        // After a migration wipe, these files are gone. The plugins do NOT recreate
        // them automatically — they require manual action in the plugin UI.
        //
        // Fix: after step_files restores all plugin files, copy the drop-in templates
        // back to wp-content/ from the plugin's own data directory.
        // This mirrors what cPanel's "WordPress Toolkit" migration does.
        //
        // Known drop-in sources:
        //   LiteSpeed Cache   → litespeed-cache/data/object-cache.php
        //   W3 Total Cache    → w3-total-cache/PgCache_WpObjectCache.php (different name)
        //   Redis Object Cache → redis-cache/includes/object-cache.php
        //   WP Fastest Cache  → (no object-cache drop-in, skip)
        $wc  = rtrim( $CFG['wp_content_dir'], '/' );
        $plg = rtrim( $CFG['plugin_dir'],     '/' );

        $dropin_sources = [
            // [ destination, source_in_plugin ]
            'object-cache.php'   => [
                $plg . '/litespeed-cache/data/object-cache.php',
                $plg . '/redis-cache/includes/object-cache.php',
                $plg . '/w3-total-cache/PgCache_WpObjectCache_ObjectCache.php',
            ],
            'advanced-cache.php' => [
                $plg . '/litespeed-cache/data/advanced-cache.php',
                $plg . '/w3-total-cache/advanced-cache.php',
                $plg . '/wp-rocket/inc/classes/subscriber/Cache/class-advanced-cache-subscriber.php',
            ],
        ];

        foreach ( $dropin_sources as $dropin_name => $candidates ) {
            $dest = $wc . '/' . $dropin_name;
            // Only restore if the drop-in is currently missing
            if ( ! file_exists( $dest ) ) {
                foreach ( $candidates as $src ) {
                    if ( file_exists( $src ) ) {
                        @copy( $src, $dest );
                        break;
                    }
                }
            }
        }

        // ── Apply user-selected import options ────────────────────────────────
        $import_opts = $CFG['import_opts'] ?? [];

        // Locale: update WPLANG option if user chose a different locale
        if ( ! empty( $import_opts['locale'] ) ) {
            $esc_locale = $mysqli->real_escape_string( $import_opts['locale'] );
            $mysqli->query( "UPDATE `{$prefix}options` SET option_value='{$esc_locale}' WHERE option_name='WPLANG'" );
            // If the row doesn't exist yet (default en_US installs omit it), insert it
            if ( $mysqli->affected_rows === 0 ) {
                $mysqli->query( "INSERT IGNORE INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('WPLANG', '{$esc_locale}', 'yes')" );
            }
        }

        // Reset permalinks: delete the cached rewrite_rules so WP regenerates them
        if ( ! empty( $import_opts['reset_permalinks'] ) ) {
            $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'" );
        }

        // ── Visibilité moteurs de recherche — stratégie 3 couches ──────────────────
        //
        // PROBLÈME RACINE (WP 5.7+) :
        //   WordPress maintient un cache alloptions (in-memory + Redis/Memcached).
        //   Un UPDATE SQL direct écrit bien en DB, mais le premier chargement WP peut
        //   lire depuis le cache persistant et retourner blog_public='1' (indexé).
        //   update_option() compare old==new : si le cache renvoie déjà '0', il skip.
        //
        // SOLUTION 3 COUCHES (même approche que WP Staging Pro / Duplicator Pro) :
        //
        //   1. SQL direct — écrit blog_public='0' en DB hors WordPress (fiable à 100 %)
        //   2. MU-plugin avec pre_option_blog_public — filtre qui s'exécute AVANT que
        //      WordPress ne lise le cache ou la DB → immunisé contre tout cache
        //   3. MU-plugin avec wp_robots filter — Robots API WP 5.7+ qui injecte le
        //      meta noindex,nofollow dans le HTML même si l'option DB est ignorée
        //   + Flush alloptions cache + $wpdb direct write sur init → permanent en DB
        //   + Auto-suppression après le premier chargement

        if ( ! empty( $import_opts['block_indexing'] ) ) {

            // ── Couche 1 : SQL direct (hors WordPress, cache inactif) ─────────────────
            $mysqli->query( "UPDATE `{$prefix}options` SET option_value = '0' WHERE option_name = 'blog_public'" );
            if ( $mysqli->affected_rows === 0 ) {
                // Ligne absente (rare, ex : install fraîche) → INSERT
                $mysqli->query( "INSERT IGNORE INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('blog_public', '0', 'yes')" );
            }

            // ── wpcm_noindex flag ─────────────────────────────────────────────────────
            // Le plugin principal (wp-clone-master.php) surveille cette option et applique
            // pre_option_blog_public + wp_robots filters tant qu'elle est à '1'.
            // Elle est auto-supprimée quand l'admin sauvegarde Réglages > Lecture.
            // Ce flag est la couche la plus robuste car il ne dépend ni du cache ni des
            // fichiers : le plugin WPCM est toujours actif après migration.
            $mysqli->query( "INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('wpcm_noindex', '1', 'yes') ON DUPLICATE KEY UPDATE option_value = '1'" );

            // ── Couches 2 & 3 : MU-plugin (s'exécute dans WordPress au premier chargement)
            $mu_dir = rtrim( $CFG['wp_content_dir'] ?? '', '/' ) . '/mu-plugins/';
            if ( ! is_dir( $mu_dir ) ) {
                mkdir( $mu_dir, 0755, true );
            }
            if ( is_dir( $mu_dir ) && is_writable( $mu_dir ) ) {
                // Le contenu du mu-plugin est assemblé en string pour éviter tout
                // problème d'indentation avec les heredoc (PHP < 7.3 exige le délimiteur
                // fermant en colonne 0, incompatible avec un heredoc imbriqué).
                $nl = "\n";
                $mu_code  = '<?php' . $nl;
                $mu_code .= '/**' . $nl;
                $mu_code .= ' * WP Clone Master — Force blog_public=0 (noindex)' . $nl;
                $mu_code .= ' * Auto-deleted on first WordPress load.' . $nl;
                $mu_code .= ' *' . $nl;
                $mu_code .= ' * Couche 2 : pre_option_blog_public' . $nl;
                $mu_code .= ' *   Filtre WordPress qui s\'exécute AVANT toute lecture de cache ou DB.' . $nl;
                $mu_code .= ' *   Immunisé contre Redis/Memcached/alloptions in-memory.' . $nl;
                $mu_code .= ' *' . $nl;
                $mu_code .= ' * Couche 3 : wp_robots (WP 5.7+ Robots API)' . $nl;
                $mu_code .= ' *   Injecte noindex,nofollow dans le meta robots HTML.' . $nl;
                $mu_code .= ' *   Complète la couche 2 même si un plugin override blog_public.' . $nl;
                $mu_code .= ' */' . $nl;
                $mu_code .= $nl;
                // Couche 2 : pre_option filtre — s'exécute avant tout cache
                $mu_code .= 'add_filter( \'pre_option_blog_public\', function() { return \'0\'; }, PHP_INT_MAX );' . $nl;
                $mu_code .= $nl;
                // Couche 3 : wp_robots — API WP 5.7+, injecte noindex dans le HTML
                $mu_code .= 'if ( function_exists( \'wp_robots_no_robots\' ) ) {' . $nl;
                $mu_code .= '    add_filter( \'wp_robots\', \'wp_robots_no_robots\', PHP_INT_MAX );' . $nl;
                $mu_code .= '} else {' . $nl;
                $mu_code .= '    // WP < 5.7 fallback : injection directe dans wp_head' . $nl;
                $mu_code .= '    add_action( \'wp_head\', function() {' . $nl;
                $mu_code .= '        echo \'<meta name="robots" content="noindex,nofollow" />\' . "\\n";' . $nl;
                $mu_code .= '    }, 1 );' . $nl;
                $mu_code .= '}' . $nl;
                $mu_code .= $nl;
                // Persistance en DB + flush cache + auto-suppression au premier chargement
                $mu_code .= 'add_action( \'init\', function() {' . $nl;
                $mu_code .= '    global $wpdb;' . $nl;
                $mu_code .= '    // Écriture directe $wpdb (bypass le check old==new de update_option)' . $nl;
                $mu_code .= '    $wpdb->update(' . $nl;
                $mu_code .= '        $wpdb->options,' . $nl;
                $mu_code .= '        array( \'option_value\' => \'0\' ),' . $nl;
                $mu_code .= '        array( \'option_name\'  => \'blog_public\' )' . $nl;
                $mu_code .= '    );' . $nl;
                $mu_code .= '    // Flush alloptions pour que le cache in-memory soit recalculé' . $nl;
                $mu_code .= '    wp_cache_delete( \'alloptions\', \'options\' );' . $nl;
                $mu_code .= '    // Auto-suppression : ce mu-plugin n\'est plus nécessaire' . $nl;
                $mu_code .= '    @unlink( __FILE__ );' . $nl;
                $mu_code .= '}, 1 );' . $nl;

                file_put_contents( $mu_dir . 'wpcm-block-indexing.php', $mu_code );
            }
        }

        // ── Désactivation des plugins problématiques après migration ──────────────
        //
        // Stratégie issue de l'analyse des plugins pro (WP Staging, Duplicator Pro,
        // WP Migrate DB Pro, All-in-One WP Migration) :
        //
        //  • WP Staging     → renomme .user.ini en .user.ini.bak (fichier Wordfence WAF)
        //  • Duplicator Pro → documente explicitement de désactiver Wordfence avant migration
        //  • WP Migrate Pro → avertit que Wordfence/Defender bloquent les requêtes push
        //  • AI1WM          → désactive en DB les plugins multisite-spécifiques après restore
        //
        // Notre approche : désactiver en base de données les plugins dont le
        // rechargement immédiat après migration cause des problèmes connus :
        //
        //  SÉCURITÉ / PARE-FEU
        //    • Wordfence     → WAF avec auto_prepend_file pointant vers un chemin absolu
        //                      de l'ancien serveur → fatal 500 si le chemin change
        //    • iThemes / Solid Security, Sucuri, AIOWPS, WP Cerber, NinjaFirewall,
        //      Shield, Defender → même problème de WAF ou de blocage d'IP/login
        //
        //  SPAM / CAPTCHA
        //    • Altcha Spam Protection → bloque les soumissions de formulaire de connexion
        //      si les clés CAPTCHA sont liées au domaine source
        //    • CleanTalk, Akismet, Google Captcha, Honeypot → idem (clés API domain-bound)
        //
        //  CACHE (déjà partiellement géré ci-dessus mais aussi en DB)
        //    • WP Rocket, W3TC, WP Super Cache, LiteSpeed Cache, WP Fastest Cache
        //      → les drop-ins (object-cache.php, advanced-cache.php) pointent vers
        //        des chemins absolus de l'ancien serveur
        //
        //  DOMAINE-SPÉCIFIQUE
        //    • Jetpack → connexion WordPress.com liée au domaine source, bloque admin
        //
        // Ces plugins sont MIS EN VEILLE (retirés de active_plugins) — pas supprimés.
        // L'administrateur les réactive manuellement après vérification, ce qui est la
        // pratique standard documentée par tous les éditeurs de plugins de migration pro.
        //
        // Les slugs sont les dossiers/fichiers canoniques tels qu'ils apparaissent
        // dans active_plugins — on compare par préfixe de dossier pour être robuste
        // aux renommages de fichiers principaux (ex: wordfence-3/wordfence.php).

        $security_folders = [
            // ── Sécurité / Pare-feu ──────────────────────────────────────────────
            'wordfence',              // Wordfence Security
            'wordfence-login-security', // Wordfence Login Security (module séparé)
            'better-wp-security',     // iThemes Security (ancien slug)
            'solid-security',         // iThemes Security / Solid Security (nouveau slug)
            'sucuri-scanner',         // Sucuri Security
            'all-in-one-wp-security-and-firewall', // All In One WP Security
            'wp-cerber',              // WP Cerber Security
            'ninja-firewall',         // NinjaFirewall
            'shield-security',        // Shield Security
            'wp-defender',            // Defender Security (MainWP)
            'defender-security',      // WPMU Dev Defender
            'bbq-firewall',           // BBQ Firewall (Block Bad Queries)
            'bbq-pro',                // BBQ Pro
            'malcare-security',       // MalCare Security
            'security-ninja',         // Security Ninja
            // ── Spam / CAPTCHA ───────────────────────────────────────────────────
            'altcha-spam-protection', // Altcha (mentionné par l'utilisateur)
            'cleantalk-spam-protect', // CleanTalk Anti-Spam
            'cleantalk',              // CleanTalk (autre slug)
            'anti-spam',              // Anti-Spam by CleanTalk
            'wp-captcha',             // WP Captcha
            'google-captcha',         // Google Captcha (reCAPTCHA) by BestWebSoft
            'advanced-google-recaptcha', // Advanced Google reCAPTCHA
            'really-simple-captcha',  // Really Simple CAPTCHA
            'cf7-honeypot',           // Contact Form 7 Honeypot
            'zero-spam',              // WordPress Zero Spam
            'wpbrainy-spam-protection', // WPBrainy Spam Protection
            // ── Domaine-spécifique ────────────────────────────────────────────────
            'jetpack',                // Jetpack (connexion .com liée au domaine)
            'jetpack-boost',          // Jetpack Boost
        ];

        $ap_r2 = $mysqli->query( "SELECT option_value FROM `{$prefix}options` WHERE option_name='active_plugins' LIMIT 1" );
        if ( $ap_r2 ) {
            $ap2_data  = $ap_r2->fetch_assoc();
            $ap_r2->free();
            $active_now = is_array( @unserialize( $ap2_data['option_value'] ?? '' ) )
                ? @unserialize( $ap2_data['option_value'] )
                : [];

            $deactivated_list = [];
            $active_filtered  = array_values( array_filter( $active_now, function( $slug ) use ( $security_folders, &$deactivated_list ) {
                $folder = explode( '/', $slug )[0];
                foreach ( $security_folders as $blocked ) {
                    if ( strtolower( $folder ) === strtolower( $blocked ) ) {
                        $deactivated_list[] = $slug;
                        return false; // retirer de active_plugins
                    }
                }
                return true; // conserver
            } ) );

            if ( ! empty( $deactivated_list ) ) {
                $ser2 = $mysqli->real_escape_string( serialize( $active_filtered ) );
                $mysqli->query( "UPDATE `{$prefix}options` SET option_value='{$ser2}' WHERE option_name='active_plugins'" );

                // Stocker la liste des plugins mis en veille dans wp_options pour
                // un éventuel affichage dans le tableau de bord post-migration
                $deactivated_ser = $mysqli->real_escape_string( serialize( $deactivated_list ) );
                $mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name='wpcm_deactivated_plugins'" );
                $mysqli->query( "INSERT INTO `{$prefix}options` (option_name, option_value, autoload)
                                 VALUES ('wpcm_deactivated_plugins', '{$deactivated_ser}', 'no')" );
            }
        }

        // ── Nettoyage des fichiers Wordfence WAF (approche WP Staging) ──────────
        //
        // Wordfence écrit un chemin absolu dans .user.ini / .htaccess / php.ini :
        //   auto_prepend_file = '/home/oldsite/public_html/wordfence-waf.php'
        //
        // Sur le nouveau serveur, ce chemin n'existe plus → PHP fatal error 500
        // avant même que WordPress se charge. C'est le cas le plus fréquent de
        // "site inaccessible après migration".
        //
        // Solution documentée par WP Staging : neutraliser les lignes auto_prepend_file.
        // Solution documentée par Wordfence eux-mêmes : supprimer wordfence-waf.php
        // et retirer la ligne auto_prepend_file de .user.ini / .htaccess.
        //
        // On neutralise (pas on supprime) pour être non-destructif : on commente les
        // lignes avec "# WPCM_DISABLED:" — l'administrateur peut les restaurer si besoin.

        $abspath = rtrim( $CFG['abspath'] ?? '', '/' );
        if ( $abspath ) {
            // Fichiers pouvant contenir auto_prepend_file
            $ini_candidates = [
                $abspath . '/.user.ini',
                $abspath . '/php.ini',
                $abspath . '/.htaccess',
                $abspath . '/wp-admin/.user.ini',
                $abspath . '/wp-admin/php.ini',
            ];

            foreach ( $ini_candidates as $ini_file ) {
                if ( ! file_exists( $ini_file ) || ! is_readable( $ini_file ) ) continue;
                $content = @file_get_contents( $ini_file );
                if ( $content === false ) continue;

                // Neutralise toutes les lignes auto_prepend_file pointant vers
                // wordfence-waf.php, wordfence.php, ou tout chemin absolu de WAF
                $modified = preg_replace(
                    '/^(\s*auto_prepend_file\s*=\s*[\'"]?.*(?:wordfence|waf|firewall|security)[\'"]?\s*)$/im',
                    '# WPCM_DISABLED: $1',
                    $content
                );

                // Pour .htaccess : neutralise aussi php_value auto_prepend_file
                if ( basename( $ini_file ) === '.htaccess' ) {
                    $modified = preg_replace(
                        '/^(\s*php_value\s+auto_prepend_file\s+.*(?:wordfence|waf|firewall|security).*)$/im',
                        '# WPCM_DISABLED: $1',
                        $modified
                    );
                }

                if ( $modified !== $content ) {
                    @file_put_contents( $ini_file, $modified );
                }
            }

            // Neutralise wordfence-waf.php à la racine : le renommer en .bak
            // (stratégie WP Staging — plus sûre que la suppression)
            $wf_file = $abspath . '/wordfence-waf.php';
            if ( file_exists( $wf_file ) ) {
                @rename( $wf_file, $wf_file . '.migration-bak' );
            }
        }

        // ── Ensure wp-clone-master stays active after import ─────────────────────
        // AI1WM / Duplicator strategy: DO NOT include the migration plugin files in
        // the backup. They are always pre-installed on the destination (that's how
        // the import was launched). The only thing to fix is the DB: the imported
        // active_plugins option from the source may or may not include this plugin.
        //
        // We read active_plugins, strip any stale wp-clone-master entry (in case
        // the source stored a different path or serialization), then write a fresh
        // canonical entry. Because the plugin files are already on disk (destination
        // pre-existing install), validate_plugin() will always pass on next WP boot.
        //
        // No MU-plugin, no race condition, no file writes that can fail silently.
        // Use the real slug stored at export time — the folder name may differ
        // from the default if the plugin was installed from a renamed zip.
        $wpcm_slug = $CFG['plugin_slug'] ?? 'wp-clone-master/wp-clone-master.php';
        $ap_r = $mysqli->query( "SELECT option_value FROM `{$prefix}options` WHERE option_name='active_plugins' LIMIT 1" );
        if ( $ap_r ) {
            $ap_data = $ap_r->fetch_assoc();
            $ap_r->free();
            $active = is_array( @unserialize( $ap_data['option_value'] ?? '' ) )
                ? @unserialize( $ap_data['option_value'] )
                : [];
            // Remove any existing entry (handles path differences from source)
            $own_folder = ( $CFG['plugin_folder'] ?? 'wp-clone-master' ) . '/';
            $active = array_values( array_filter( $active, function( $p ) use ( $own_folder ) {
                return strpos( $p, $own_folder ) !== 0;
            } ) );
            // Re-add with canonical slug
            $active[] = $wpcm_slug;
            $ser = $mysqli->real_escape_string( serialize( $active ) );
            $mysqli->query( "UPDATE `{$prefix}options` SET option_value='{$ser}' WHERE option_name='active_plugins'" );
        }

        $mysqli->close();
    }

    // ── Delete Elementor CSS files from uploads ────────────────────────────────
    // These are generated CSS files that contain absolute paths/URLs to the old domain.
    // They CANNOT be fixed by a DB search-replace — they're PHP-generated files.
    // Deleting them is safe: Elementor regenerates them automatically on next page load.
    // This is the standard post-migration procedure documented by Elementor.
    $uploads_dir = $CFG['uploads_dir'] ?? '';
    if ( $uploads_dir ) {
        $elementor_css = rtrim( $uploads_dir, '/' ) . '/elementor/css/';
        if ( is_dir( $elementor_css ) ) {
            foreach ( glob( $elementor_css . '*.css' ) ?: [] as $css_file ) {
                @unlink( $css_file );
            }
            foreach ( glob( $elementor_css . '*.min.css' ) ?: [] as $css_file ) {
                @unlink( $css_file );
            }
        }
        // Also clear Elementor data folder that stores fonts/assets with old URLs
        $elementor_data = rtrim( $uploads_dir, '/' ) . '/elementor/';
        if ( is_dir( $elementor_data . 'tmp/' ) ) {
            recursive_delete( $elementor_data . 'tmp/' );
        }

        // WP Rocket cache
        $rocket_cache = rtrim( $CFG['wp_content_dir'] ?? '', '/' ) . '/cache/wp-rocket/';
        if ( is_dir( $rocket_cache ) ) {
            recursive_delete( $rocket_cache );
        }
        // W3 Total Cache
        $w3_cache = rtrim( $CFG['wp_content_dir'] ?? '', '/' ) . '/cache/page_enhanced/';
        if ( is_dir( $w3_cache ) ) {
            recursive_delete( $w3_cache );
        }
    }

    // ── Cleanup session directory ──────────────────────────────────────────────
    if ( is_dir( $SESSION_DIR ) ) {
        recursive_delete( $SESSION_DIR );
    }

    // Self-destruct: delete this installer file
    $self = __FILE__;
    @unlink( $self );

    // Récupérer la liste des plugins mis en veille (stockée en option)
    // pour la renvoyer au JS qui affichera un résumé à l'utilisateur
    $deactivated_info = [];
    if ( isset( $mysqli ) && ! $mysqli->connect_errno ) {
        // mysqli peut être fermé ci-dessus — on essaie de relire depuis la DB
    }
    // On lit depuis le fichier de config car mysqli est déjà fermé
    $dp_option = ''; // sera lu par WP au premier chargement via wpcm_deactivated_plugins

    return [
        'next_step'           => null,
        'progress'            => 100,
        'message'             => 'Migration terminée avec succès.',
        'deactivated_plugins' => $deactivated_list ?? [],
    ];
}

// ============================================================
// URL replacement helpers
// ============================================================
function build_url_pairs( $old_url, $new_url, $old_path, $new_path ) {
    $old_url = rtrim( $old_url, '/' );
    $new_url = rtrim( $new_url, '/' );
    $pairs   = [];

    $old_https = str_replace( 'http://', 'https://', $old_url );
    $old_http  = str_replace( 'https://', 'http://', $old_url );

    // 1. Plain https / http
    $pairs[] = [ $old_https, $new_url ];
    if ( $old_http !== $old_https ) $pairs[] = [ $old_http, $new_url ];

    // 2. Protocol-relative  //domain.com
    $old_rel = preg_replace( '#^https?://#', '//', $old_https );
    $new_rel = preg_replace( '#^https?://#', '//', $new_url );
    if ( $old_rel !== $new_rel ) $pairs[] = [ $old_rel, $new_rel ];

    // 3. JSON-escaped  https:\/\/domain.com  (Elementor _elementor_data, WooCommerce, etc.)
    $slash_esc = function( $u ) { return str_replace( '/', '\\/', $u ); };
    $pairs[] = [ $slash_esc( $old_https ), $slash_esc( $new_url ) ];
    if ( $old_http !== $old_https ) {
        $pairs[] = [ $slash_esc( $old_http ), $slash_esc( $new_url ) ];
    }

    // 4. URL-percent-encoded  https%3A%2F%2Fdomain.com  (some redirect meta, form URLs)
    $old_enc = rawurlencode( $old_https );
    $new_enc = rawurlencode( $new_url );
    if ( $old_enc !== $new_enc ) $pairs[] = [ $old_enc, $new_enc ];

    // 5. Filesystem path
    if ( $old_path && $new_path ) {
        $old_path = rtrim( $old_path, '/' );
        $new_path = rtrim( $new_path, '/' );
        if ( $old_path !== $new_path ) {
            $pairs[] = [ $old_path, $new_path ];
            $pairs[] = [ $slash_esc( $old_path ), $slash_esc( $new_path ) ];
        }
    }

    // Remove no-op pairs and deduplicate
    $seen  = [];
    $clean = [];
    foreach ( $pairs as $p ) {
        if ( $p[0] === $p[1] || isset( $seen[ $p[0] ] ) ) continue;
        $seen[ $p[0] ] = true;
        $clean[]       = $p;
    }

    return $clean;
}
function replace_in_table_sliced( $mysqli, $table, $text_cols, $primary, $col_maxlen, $pairs, $start_offset, $time_budget, $start_time ) {
    $stats = [ 'rows' => 0, 'cells' => 0, 'serialized' => 0, 'table_done' => true, 'next_offset' => 0 ];

    if ( empty( $text_cols ) || ! $primary ) return $stats;

    // 20 rows per batch: smaller than WP Migrate DB Pro (100) to ensure
    // each batch finishes well within the 3s time budget even on slow servers.
    $batch          = 20;
    $offset         = $start_offset;
    $search_strings = array_column( $pairs, 0 );

    do {
        $select_cols = '`' . $primary . '`, `' . implode( '`, `', $text_cols ) . '`';
        $result = $mysqli->query( "SELECT {$select_cols} FROM `{$table}` LIMIT {$offset}, {$batch}" );
        if ( ! $result ) break;
        $row_count = $result->num_rows;

        while ( $row = $result->fetch_assoc() ) {
            $updates = [];
            foreach ( $text_cols as $col ) {
                if ( empty( $row[ $col ] ) ) continue;
                $val = $row[ $col ];
                $found = false;
                foreach ( $search_strings as $s ) {
                    if ( strpos( $val, $s ) !== false ) { $found = true; break; }
                }
                if ( ! $found ) continue;

                $new_val = replace_value( $val, $pairs, $stats );
                if ( $new_val === $val ) continue;

                // Column length guard — skip hash/key columns that would overflow
                $max = $col_maxlen[ $col ] ?? PHP_INT_MAX;
                if ( $max < PHP_INT_MAX && strlen( $new_val ) > $max ) continue;

                $updates[ $col ] = $new_val;
                $stats['cells']++;
            }

            if ( ! empty( $updates ) ) {
                $set_parts = [];
                foreach ( $updates as $col => $val ) {
                    $set_parts[] = "`{$col}` = '" . $mysqli->real_escape_string( $val ) . "'";
                }
                $pk_val = $mysqli->real_escape_string( $row[ $primary ] );
                $res    = $mysqli->query(
                    "UPDATE `{$table}` SET " . implode( ', ', $set_parts )
                    . " WHERE `{$primary}` = '{$pk_val}'"
                );
                if ( $res === false ) {
                    $up_errno = $mysqli->errno;
                    if ( $up_errno === 1406 && count( $updates ) > 1 ) {
                        // 1406 = Data too long — retry column by column
                        foreach ( $updates as $col => $val ) {
                            $mysqli->query(
                                "UPDATE `{$table}` SET `{$col}` = '" . $mysqli->real_escape_string( $val ) . "'"
                                . " WHERE `{$primary}` = '{$pk_val}'"
                            );
                        }
                    } elseif ( $up_errno === 1062 ) {
                        // 1062 = Duplicate entry — the new URL value already exists in another
                        // row (unique key conflict). Strategy used by WP Migrate DB Pro for
                        // tables with multiple unique indexes: DELETE the conflicting row, then
                        // update the current row. The deleted row was a stale/duplicate anyway —
                        // both rows would have had the same canonical URL after migration.
                        // Build the conflicting value: find which update column caused the clash.
                        foreach ( $updates as $col => $new_val ) {
                            $esc_new_val = $mysqli->real_escape_string( $new_val );
                            $conflict_r  = $mysqli->query(
                                "SELECT `{$primary}` FROM `{$table}`"
                                . " WHERE `{$col}` = '{$esc_new_val}'"
                                . " AND `{$primary}` != '{$pk_val}'"
                                . " LIMIT 1"
                            );
                            if ( $conflict_r && $conflict_r->num_rows > 0 ) {
                                $crow  = $conflict_r->fetch_row();
                                $c_pk  = $mysqli->real_escape_string( $crow[0] );
                                $mysqli->query( "DELETE FROM `{$table}` WHERE `{$primary}` = '{$c_pk}'" );
                                $conflict_r->free();
                                // Retry the original UPDATE
                                $mysqli->query(
                                    "UPDATE `{$table}` SET " . implode( ', ', $set_parts )
                                    . " WHERE `{$primary}` = '{$pk_val}'"
                                );
                                break; // one conflicting row resolved — re-run will catch remaining
                            }
                            if ( $conflict_r ) $conflict_r->free();
                        }
                    }
                }
                $stats['rows']++;
            }
        }

        $result->free();
        $offset += $batch;

        // Time-budget check after every batch (like AI1WM & WP Migrate DB)
        if ( ( microtime( true ) - $start_time ) > $time_budget ) {
            if ( $row_count === $batch ) {
                // More rows remain in this table — signal partial completion
                $stats['table_done']   = false;
                $stats['next_offset']  = $offset;
            }
            // If $row_count < $batch the table is done anyway
            break;
        }

    } while ( $row_count === $batch );

    return $stats;
}

function replace_value( $val, $pairs, &$stats ) {
    // ── Serialized PHP string ─────────────────────────────────────────────────
    // Use allowed_classes=>false (PHP 7+): unknown classes become __PHP_Incomplete_Class
    // instead of triggering Fatal Error in PHP 8.0+ when their properties are accessed.
    // Source: Search-Replace-DB issue #262, WordPress Trac #55257.
    if ( preg_match( '/^[aObids]:/', $val ) ) {
        $unserialized = @unserialize( $val, [ 'allowed_classes' => false ] );
        if ( $unserialized !== false || $val === 'b:0;' ) {
            $stats['serialized']++;
            // Check for __PHP_Incomplete_Class objects produced by allowed_classes=>false.
            // We cannot traverse/modify them (PHP 8.0+ Fatal Error if we try).
            // Use direct serialized-string replacement + fix s:N: length counters instead.
            if ( has_incomplete_class( $unserialized ) ) {
                return replace_serialized_string( $val, $pairs );
            }
            $replaced = replace_recursive( $unserialized, $pairs );
            return serialize( $replaced );
        }
    }

    // ── JSON string ───────────────────────────────────────────────────────────
    if ( $val !== '' && ( $val[0] === '{' || $val[0] === '[' ) ) {
        $json = json_decode( $val, true );
        if ( $json !== null ) {
            $replaced = replace_recursive( $json, $pairs );
            $encoded  = json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            return $encoded !== false ? $encoded : str_replace_pairs( $val, $pairs );
        }
    }

    return str_replace_pairs( $val, $pairs );
}

// Check if an unserialized value contains any __PHP_Incomplete_Class instances.
// These appear when allowed_classes=>false is used and the class is not loaded.
function has_incomplete_class( $data ) {
    if ( $data instanceof __PHP_Incomplete_Class ) return true;
    if ( is_array( $data ) ) {
        foreach ( $data as $v ) {
            if ( has_incomplete_class( $v ) ) return true;
        }
    }
    return false;
}

// Direct string replacement on serialized data — fixes s:N: byte-length counters.
// Used when the serialized value contains objects whose class is not loaded (Elementor,
// WPML, WooCommerce, etc.) — we cannot unserialize+reserialize them safely in a
// standalone script that has no WordPress autoloader.
//
// CRITICAL: we use a LENGTH-AWARE scanner to fix s:N:, NOT a lazy regex.
// The regex /s:(\d+):"(.*?)";/s was broken because:
//   - DOTALL + lazy matching stops at the FIRST "; — wrong if content contains ";
//   - This corrupted WordPress permalink tokens like %postname%, %event%
//     when they appeared inside serialized Polylang/WP options nearby.
// The length-aware approach uses the declared N to find the exact string boundary.
function replace_serialized_string( $val, $pairs ) {
    // Step 1: apply all replacements to the raw serialized string
    foreach ( $pairs as $pair ) {
        list( $search, $replace ) = $pair;
        if ( strpos( $val, $search ) !== false ) {
            $val = str_replace( $search, $replace, $val );
        }
    }

    // Step 2: fix s:N: byte lengths using a length-aware scanner.
    // Walk the string character by character. When we find s:N:", use N to jump
    // directly to the expected end. If the end is wrong (N changed due to str_replace),
    // search nearby for the real ";  — bounded by a ±500 byte window.
    $out   = '';
    $pos   = 0;
    $total = strlen( $val );

    while ( $pos < $total ) {
        // Detect s: prefix
        if ( $val[ $pos ] === 's' && $pos + 3 < $total && $val[ $pos + 1 ] === ':' ) {
            // Scan the integer N
            $ni = $pos + 2;
            $ne = $ni;
            while ( $ne < $total && $val[ $ne ] >= '0' && $val[ $ne ] <= '9' ) { $ne++; }

            if ( $ne > $ni                       // at least one digit
                && $ne + 1 < $total             // room for :"
                && $val[ $ne ] === ':'
                && $val[ $ne + 1 ] === '"' ) {

                $old_n     = (int) substr( $val, $ni, $ne - $ni );
                $str_start = $ne + 2;            // first byte of string content
                $exp_end   = $str_start + $old_n; // expected position of closing "

                // Case A: declared N is still correct — copy verbatim
                if ( $exp_end + 2 <= $total
                    && $val[ $exp_end ] === '"'
                    && $val[ $exp_end + 1 ] === ';' ) {
                    $out .= substr( $val, $pos, $exp_end + 2 - $pos );
                    $pos  = $exp_end + 2;
                    continue;
                }

                // Case B: N is wrong (str_replace changed this string's length).
                // Search in a ±500 byte window for a valid "; that could be the real end.
                // The window is generous enough for any URL change but tight enough to
                // avoid false positives (content containing "; is rare in URL data).
                $found = false;
                $lo = max( 0, $old_n - 500 );
                $hi = $old_n + 500;
                for ( $delta = 0; $delta <= ( $hi - $lo ); $delta++ ) {
                    // Alternate: try old_n+delta, old_n-delta
                    foreach ( [ $old_n + $delta, $old_n - $delta ] as $try_n ) {
                        if ( $try_n < 0 ) continue;
                        $try_end = $str_start + $try_n;
                        if ( $try_end + 2 <= $total
                            && $val[ $try_end ] === '"'
                            && $val[ $try_end + 1 ] === ';' ) {
                            $content = substr( $val, $str_start, $try_n );
                            $out    .= 's:' . $try_n . ':"' . $content . '";';
                            $pos     = $try_end + 2;
                            $found   = true;
                            break 2;
                        }
                    }
                    if ( $delta === 0 ) break; // no need to check delta=0 twice
                }
                if ( $found ) continue;
                // If we couldn't fix it, fall through and copy the byte as-is
            }
        }
        $out .= $val[ $pos ];
        $pos++;
    }

    return $out;
}

function replace_recursive( $data, $pairs ) {
    if ( is_string( $data ) ) {
        // Detect nested serialized string
        if ( preg_match( '/^[aObids]:/', $data ) ) {
            $nested = @unserialize( $data, [ 'allowed_classes' => false ] );
            if ( $nested !== false || $data === 'b:0;' ) {
                if ( has_incomplete_class( $nested ) ) {
                    return replace_serialized_string( $data, $pairs );
                }
                return serialize( replace_recursive( $nested, $pairs ) );
            }
        }
        // Detect nested JSON
        if ( $data !== '' && ( $data[0] === '{' || $data[0] === '[' ) ) {
            $json = json_decode( $data, true );
            if ( $json !== null ) {
                $replaced = replace_recursive( $json, $pairs );
                $encoded  = json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                return $encoded !== false ? $encoded : str_replace_pairs( $data, $pairs );
            }
        }
        return str_replace_pairs( $data, $pairs );
    }
    if ( is_array( $data ) ) {
        $result = [];
        foreach ( $data as $k => $v ) {
            $new_k            = is_string( $k ) ? str_replace_pairs( $k, $pairs ) : $k;
            $result[ $new_k ] = replace_recursive( $v, $pairs );
        }
        return $result;
    }
    // is_object: only reached for fully-loaded classes (allowed_classes=>false
    // converts unknown classes to __PHP_Incomplete_Class, handled above).
    // Safe to traverse: these are real stdClass or other loaded objects.
    if ( is_object( $data ) ) {
        foreach ( get_object_vars( $data ) as $k => $v ) {
            $data->$k = replace_recursive( $v, $pairs );
        }
        return $data;
    }
    return $data;
}

function str_replace_pairs( $str, $pairs ) {
    foreach ( $pairs as $pair ) {
        $str = str_replace( $pair[0], $pair[1], $str );
    }
    return $str;
}

// ============================================================
// Filesystem helpers
// ============================================================
function find_subdir( $dir, $name ) {
    if ( is_dir( $dir . $name . '/' ) ) return $dir . $name . '/';
    foreach ( glob( $dir . '*/', GLOB_ONLYDIR ) as $sub ) {
        if ( is_dir( $sub . $name . '/' ) ) return $sub . $name . '/';
    }
    return null;
}

function copy_recursive( $src, $dst ) {
    if ( ! is_dir( $src ) ) return;
    @mkdir( $dst, 0755, true );
    $real_src = realpath( $src );
    if ( ! $real_src ) return;
    $len = strlen( $real_src );
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ( $it as $item ) {
        $real = $item->getRealPath();
        if ( ! $real ) continue;
        $rel = substr( $real, $len );
        $target = $dst . $rel;
        if ( $item->isDir() ) {
            @mkdir( $target, 0755, true );
        } else {
            $dir = dirname( $target );
            if ( ! is_dir( $dir ) ) @mkdir( $dir, 0755, true );
            copy( $real, $target );
        }
    }
}

function recursive_delete( $dir ) {
    if ( ! is_dir( $dir ) ) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $it as $item ) {
        $item->isDir() ? @rmdir( $item->getRealPath() ) : @unlink( $item->getRealPath() );
    }
    @rmdir( $dir );
}

function die_json( $msg ) {
    ob_end_clean();
    echo json_encode( [ 'success' => false, 'data' => [ 'message' => $msg ] ] );
    exit;
}
INSTALLER_BODY;
        // Strip any BOM or stray bytes that could break PHP parsing of the generated file
        $installer_code = ltrim( $installer_code, "\xEF\xBB\xBF\x00" );
        return $installer_code;
    }

    private function find_manifest( $dir ) {
        if ( file_exists( $dir . 'manifest.json' ) ) return $dir . 'manifest.json';
        foreach ( glob( $dir . '*/', GLOB_ONLYDIR ) as $sub ) {
            if ( file_exists( $sub . 'manifest.json' ) ) return $sub . 'manifest.json';
        }
        return null;
    }
}
