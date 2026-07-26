<?php
/**
 * Clone Master transactional standalone installer.
 *
 * This static file runs outside the WordPress bootstrap. The source site remains
 * untouched while database tables and files are prepared in staging areas. The
 * final promotion is journaled, reversible, and performed under maintenance mode.
 *
 * @package Clone_Master
 */

// phpcs:disable WordPress.DB.RestrictedFunctions,WordPress.DB.RestrictedClasses
// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.PHP.DevelopmentFunctions

if ( ! defined( 'WPCM_INSTALLER_LIBRARY_ONLY' ) || true !== WPCM_INSTALLER_LIBRARY_ONLY ) {

error_reporting( E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED );
@set_time_limit( 0 );
// Raise a low standalone-installer limit without reducing a host that already
// grants more memory. An unlimited limit is left untouched.
$wpcm_boot_memory_limit = wpcm_runtime_memory_limit_bytes();
if ( $wpcm_boot_memory_limit > 0 && $wpcm_boot_memory_limit < 512 * 1024 * 1024 ) {
    @ini_set( 'memory_limit', '512M' );
}
ob_start();

$wpcm_sid = isset( $_GET['sid'] ) ? (string) $_GET['sid'] : '';
if ( ! preg_match( '/^[a-zA-Z0-9_]{1,100}$/', $wpcm_sid ) ) {
    wpcm_plain_404();
}

$wpcm_locator = isset( $_GET['loc'] ) ? (string) $_GET['loc'] : '';
$wpcm_locator = strtr( $wpcm_locator, '-_', '+/' );
$wpcm_locator .= str_repeat( '=', ( 4 - strlen( $wpcm_locator ) % 4 ) % 4 );
$wpcm_content_guess = $wpcm_locator ? base64_decode( $wpcm_locator, true ) : false;
if ( ! is_string( $wpcm_content_guess ) || '' === $wpcm_content_guess || ! is_dir( $wpcm_content_guess ) ) {
    // Backward-compatible fallback for standard wp-content/plugin layouts.
    $wpcm_content_guess = dirname( dirname( __DIR__ ) );
}
$wpcm_content_real = realpath( $wpcm_content_guess );
if ( ! $wpcm_content_real || ! is_dir( $wpcm_content_real ) ) {
    wpcm_plain_404();
}
$wpcm_session_dir = rtrim( $wpcm_content_real, '/\\' ) . '/wpcm-temp/' . $wpcm_sid . '/';
$wpcm_config_path = $wpcm_session_dir . 'installer-config.php';
if ( ! is_file( $wpcm_config_path ) ) {
    wpcm_plain_404();
}

$wpcm_cfg = wpcm_read_inert_json( $wpcm_config_path );
if ( ! is_array( $wpcm_cfg ) || ( $wpcm_cfg['session_id'] ?? '' ) !== $wpcm_sid ) {
    wpcm_json_error( 'Invalid installer configuration.', 410 );
}
if ( empty( $wpcm_cfg['expires_at'] ) || time() > (int) $wpcm_cfg['expires_at'] ) {
    wpcm_json_error( 'Installer session expired. Start the restore again.', 410 );
}

$wpcm_origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
if ( '' !== $wpcm_origin && ! empty( $wpcm_cfg['allowed_origin'] ) && $wpcm_origin !== $wpcm_cfg['allowed_origin'] ) {
    wpcm_json_error( 'Origin not allowed.', 403 );
}
if ( ! headers_sent() ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
    header( 'CDN-Cache-Control: no-store' );
    header( 'Surrogate-Control: no-store' );
    header( 'X-LiteSpeed-Cache-Control: no-cache' );
    header( 'X-Accel-Expires: 0' );
    header( 'Vary: Cookie, Authorization, X-WPCM-Request-ID' );
    header( 'X-Content-Type-Options: nosniff' );
    if ( ! empty( $wpcm_cfg['allowed_origin'] ) ) {
        header( 'Access-Control-Allow-Origin: ' . $wpcm_cfg['allowed_origin'] );
        header( 'Vary: Origin' );
    }
}

$wpcm_token = isset( $_POST['installer_token'] ) ? (string) $_POST['installer_token'] : '';
if ( ! preg_match( '/^[a-f0-9]{64}$/', $wpcm_token ) || empty( $wpcm_cfg['token_hash'] ) || ! password_verify( $wpcm_token, $wpcm_cfg['token_hash'] ) ) {
    wpcm_json_error( 'Unauthorized.', 403 );
}

$wpcm_loc_raw = isset( $_GET['loc'] ) ? (string) $_GET['loc'] : '';
$wpcm_lsig    = isset( $_GET['lsig'] ) ? (string) $_GET['lsig'] : '';
if ( '' !== $wpcm_loc_raw ) {
    $wpcm_expected_lsig = hash_hmac( 'sha256', $wpcm_sid . '|' . $wpcm_loc_raw, hash( 'sha256', $wpcm_token, true ) );
    if ( ! preg_match( '/^[a-f0-9]{64}$/', $wpcm_lsig ) || ! hash_equals( $wpcm_expected_lsig, $wpcm_lsig ) ) {
        wpcm_json_error( 'Invalid installer location signature.', 403 );
    }
}
wpcm_validate_runtime_config( $wpcm_cfg, $wpcm_session_dir, $wpcm_content_real );
wpcm_debug_log( $wpcm_cfg, 'info', 'installer_request_authenticated', array( 'session_id' => $wpcm_sid ) );

$wpcm_db = wpcm_decrypt_database_config( $wpcm_cfg, $wpcm_token );
$wpcm_cfg = array_merge( $wpcm_cfg, $wpcm_db );
unset( $wpcm_db, $wpcm_cfg['db_credentials'] );

$wpcm_lock = @fopen( $wpcm_session_dir . 'restore.lock', 'c+' );
if ( ! is_resource( $wpcm_lock ) || ! flock( $wpcm_lock, LOCK_EX | LOCK_NB ) ) {
    if ( is_resource( $wpcm_lock ) ) {
        fclose( $wpcm_lock );
    }
    wpcm_json_error( 'Another restore request is already running. Retry shortly.', 409 );
}

$wpcm_state = wpcm_load_state( $wpcm_cfg, $wpcm_token );
if ( ! is_array( $wpcm_state ) ) {
    wpcm_release_lock( $wpcm_lock );
    wpcm_json_error( 'Restore journal is missing or has been altered.', 410 );
}

$wpcm_runtime = array(
    'cfg'   => &$wpcm_cfg,
    'token' => &$wpcm_token,
    'state' => &$wpcm_state,
    'lock'  => &$wpcm_lock,
);

register_shutdown_function(
    function () use ( &$wpcm_runtime ) {
        $error = error_get_last();
        if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
            return;
        }
        $message = (string) $error['message'];
        $event_id = wpcm_debug_log( $wpcm_runtime['cfg'], 'critical', 'installer_fatal_shutdown', array( 'message' => $message, 'file' => basename( (string) $error['file'] ), 'line' => (int) $error['line'] ) );
        $timeout = false !== stripos( $message, 'execution time' );
        if ( ! $timeout && wpcm_state_is_destructive( $wpcm_runtime['state'] ) ) {
            try {
                wpcm_rollback( $wpcm_runtime['cfg'], $wpcm_runtime['state'], $wpcm_runtime['token'] );
            } catch ( Throwable $ignored ) {
                // Best effort only. The signed journal remains available for the next request.
            }
        }
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=utf-8' );
            http_response_code( 500 );
        }
        echo json_encode(
            array(
                'success' => false,
                'data'    => array(
                    'message' => $timeout
                        ? 'PHP execution time was exceeded. The operation is resumable; retry the same step.'
                        : 'Fatal restore error. A rollback was attempted: ' . $message,
                    'event_id' => $event_id,
                ),
            )
        );
    }
);

$wpcm_step = isset( $_POST['step'] ) ? (string) $_POST['step'] : 'database';
$wpcm_step_event = wpcm_debug_log( $wpcm_cfg, 'info', 'installer_step_started', array( 'step' => $wpcm_step, 'phase' => $wpcm_state['phase'] ?? '' ) );
try {
    wpcm_recover_interrupted_transition( $wpcm_cfg, $wpcm_state, $wpcm_token );

    switch ( $wpcm_step ) {
        case 'database':
            $wpcm_result = wpcm_step_database( $wpcm_cfg, $wpcm_state, $wpcm_token );
            break;
        case 'files':
            $wpcm_result = wpcm_step_files( $wpcm_cfg, $wpcm_state, $wpcm_token );
            break;
        case 'replace_urls':
            $wpcm_result = wpcm_step_replace_urls( $wpcm_cfg, $wpcm_state, $wpcm_token );
            break;
        case 'finalize':
            $wpcm_result = wpcm_step_finalize( $wpcm_cfg, $wpcm_state, $wpcm_token );
            break;
        default:
            throw new RuntimeException( 'Unknown restore step.' );
    }

    $wpcm_success_event = wpcm_debug_log( $wpcm_cfg, 'info', 'installer_step_completed', array( 'step' => $wpcm_step, 'next_step' => $wpcm_result['next_step'] ?? null, 'progress' => $wpcm_result['progress'] ?? null ) );
    if ( is_array( $wpcm_result ) ) {
        $wpcm_result['event_id'] = $wpcm_success_event;
    }
    wpcm_release_lock( $wpcm_lock );
    ob_end_clean();
    echo json_encode( array( 'success' => true, 'data' => $wpcm_result ) );
} catch ( Throwable $e ) {
    $wpcm_error_event = wpcm_debug_log( $wpcm_cfg, 'error', 'installer_step_failed', array( 'step' => $wpcm_step, 'message' => $e->getMessage(), 'file' => basename( $e->getFile() ), 'line' => $e->getLine() ) );
    $rollback_message = '';
    if ( wpcm_state_is_destructive( $wpcm_state ) ) {
        try {
            wpcm_rollback( $wpcm_cfg, $wpcm_state, $wpcm_token );
            $rollback_message = ' Previous site data was restored.';
        } catch ( Throwable $rollback_error ) {
            $rollback_message = ' Automatic rollback also failed: ' . $rollback_error->getMessage();
        }
    }
    wpcm_release_lock( $wpcm_lock );
    ob_end_clean();
    http_response_code( 500 );
    echo json_encode( array( 'success' => false, 'data' => array( 'message' => $e->getMessage() . $rollback_message, 'event_id' => $wpcm_error_event ) ) );
}
exit;
}

// -----------------------------------------------------------------------------
// Bootstrap and state helpers.
// -----------------------------------------------------------------------------

function wpcm_debug_log( $cfg, $level, $event, $context = array() ) {
    $event_id = 'diag_' . gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', microtime( true ) . '|' . mt_rand() ), 0, 10 );
    if ( ! is_array( $cfg ) || empty( $cfg['debug_log_path'] ) ) {
        return $event_id;
    }
    $path = (string) $cfg['debug_log_path'];
    $directory = dirname( $path );
    if ( ! is_dir( $directory ) ) {
        @mkdir( $directory, 0750, true );
    }
    $record = array(
        'time'       => gmdate( 'c' ),
        'event_id'   => $event_id,
        'level'      => strtolower( (string) $level ),
        'event'      => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $event ) ),
        'request_id' => isset( $_SERVER['HTTP_X_WPCM_REQUEST_ID'] ) ? substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $_SERVER['HTTP_X_WPCM_REQUEST_ID'] ), 0, 96 ) : '',
        'action'     => 'standalone_installer',
        'user_id'    => 0,
        'memory'     => memory_get_usage( true ),
        'peak'       => memory_get_peak_usage( true ),
        'php'        => PHP_VERSION,
        'context'    => is_array( $context ) ? $context : array(),
    );
    $json = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
    if ( ! is_string( $json ) ) {
        return $event_id;
    }
    $handle = @fopen( $path, 'ab' );
    if ( is_resource( $handle ) ) {
        if ( flock( $handle, LOCK_EX ) ) {
            fwrite( $handle, $json . "\n" );
            fflush( $handle );
            if ( function_exists( 'fsync' ) ) {
                @fsync( $handle );
            }
            flock( $handle, LOCK_UN );
        }
        fclose( $handle );
        @chmod( $path, 0640 );
    }
    return $event_id;
}

function wpcm_plain_404() {
    while ( ob_get_level() > 0 ) {
        @ob_end_clean();
    }
    http_response_code( 404 );
    exit;
}

function wpcm_json_error( $message, $status = 400 ) {
    while ( ob_get_level() > 0 ) {
        @ob_end_clean();
    }
    if ( ! headers_sent() ) {
        header( 'Content-Type: application/json; charset=utf-8' );
    }
    http_response_code( $status );
    echo json_encode( array( 'success' => false, 'data' => array( 'message' => $message ) ) );
    exit;
}

function wpcm_release_lock( $lock ) {
    if ( is_resource( $lock ) ) {
        flock( $lock, LOCK_UN );
        fclose( $lock );
    }
}

function wpcm_read_inert_json( $path ) {
    $raw = @file_get_contents( $path );
    if ( false === $raw ) {
        return null;
    }
    $marker = "__halt_compiler();\n";
    $offset = strpos( $raw, $marker );
    if ( false === $offset ) {
        return null;
    }
    return json_decode( substr( $raw, $offset + strlen( $marker ) ), true );
}


function wpcm_validate_runtime_config( $cfg, $session_dir, $content_dir ) {
    $required = array(
        'session_dir', 'staging_dir', 'rollback_dir', 'wp_content_dir',
        'plugin_dir', 'abspath', 'old_prefix', 'staging_prefix', 'rollback_prefix', 'target_prefix',
    );
    foreach ( $required as $key ) {
        if ( ! isset( $cfg[ $key ] ) || ! is_string( $cfg[ $key ] ) || '' === $cfg[ $key ] ) {
            throw new RuntimeException( 'Installer configuration field is missing: ' . $key );
        }
    }

    $canonical_session = rtrim( str_replace( '\\', '/', $session_dir ), '/' ) . '/';
    $configured_session = rtrim( str_replace( '\\', '/', $cfg['session_dir'] ), '/' ) . '/';
    $canonical_content = rtrim( str_replace( '\\', '/', $content_dir ), '/' );
    $configured_content = rtrim( str_replace( '\\', '/', $cfg['wp_content_dir'] ), '/' );

    if ( ! hash_equals( $canonical_session, $configured_session ) || ! hash_equals( $canonical_content, $configured_content ) ) {
        throw new RuntimeException( 'Installer path configuration does not match the signed location.' );
    }
    if ( 0 !== strpos( rtrim( str_replace( '\\', '/', $cfg['staging_dir'] ), '/' ) . '/', $canonical_session ) ) {
        throw new RuntimeException( 'Staging directory is outside the import session.' );
    }
    if ( 0 !== strpos( rtrim( str_replace( '\\', '/', $cfg['rollback_dir'] ), '/' ) . '/', $canonical_content . '/' ) ) {
        throw new RuntimeException( 'Rollback directory is outside wp-content.' );
    }
    foreach ( array( 'old_prefix', 'staging_prefix', 'rollback_prefix', 'target_prefix' ) as $key ) {
        if ( ! preg_match( '/^[A-Za-z0-9_]{1,50}$/', $cfg[ $key ] ) ) {
            throw new RuntimeException( 'Invalid database prefix in installer configuration.' );
        }
    }
}

function wpcm_decrypt_database_config( $cfg, $token ) {
    if ( ! function_exists( 'openssl_decrypt' ) || empty( $cfg['db_credentials'] ) ) {
        throw new RuntimeException( 'Encrypted database credentials are unavailable.' );
    }
    $raw = base64_decode( $cfg['db_credentials'], true );
    if ( false === $raw || strlen( $raw ) < 29 ) {
        throw new RuntimeException( 'Encrypted database credentials are invalid.' );
    }
    $iv     = substr( $raw, 0, 12 );
    $tag    = substr( $raw, 12, 16 );
    $cipher = substr( $raw, 28 );
    $key    = hash( 'sha256', $token, true );
    $plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) ( $cfg['db_aad'] ?? '' ) );
    if ( false === $plain ) {
        throw new RuntimeException( 'Database credential authentication failed.' );
    }
    $data = json_decode( $plain, true );
    if ( ! is_array( $data ) || empty( $data['db_host'] ) || empty( $data['db_name'] ) ) {
        throw new RuntimeException( 'Decrypted database configuration is invalid.' );
    }
    return $data;
}

function wpcm_atomic_write( $path, $data, $mode = 0600 ) {
    $dir = dirname( $path );
    if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) ) {
        throw new RuntimeException( 'Unable to create state directory.' );
    }
    $tmp = $path . '.tmp-' . bin2hex( random_bytes( 6 ) );
    $fh  = @fopen( $tmp, 'xb' );
    if ( ! is_resource( $fh ) ) {
        throw new RuntimeException( 'Unable to create atomic state file.' );
    }
    $length  = strlen( $data );
    $written = 0;
    while ( $written < $length ) {
        $n = fwrite( $fh, substr( $data, $written ) );
        if ( false === $n || 0 === $n ) {
            fclose( $fh );
            @unlink( $tmp );
            throw new RuntimeException( 'Unable to write atomic state file.' );
        }
        $written += $n;
    }
    if ( ! fflush( $fh ) ) {
        fclose( $fh );
        @unlink( $tmp );
        throw new RuntimeException( 'Unable to flush atomic state file.' );
    }
    if ( function_exists( 'fsync' ) && ! @fsync( $fh ) ) {
        fclose( $fh );
        @unlink( $tmp );
        throw new RuntimeException( 'Unable to synchronize atomic state file.' );
    }
    fclose( $fh );
    @chmod( $tmp, $mode );
    if ( is_file( $path ) && ! @copy( $path, $path . '.bak' ) ) {
        @unlink( $tmp );
        throw new RuntimeException( 'Unable to preserve the previous atomic state file.' );
    }
    if ( ! @rename( $tmp, $path ) ) {
        @unlink( $tmp );
        throw new RuntimeException( 'Unable to publish atomic state file.' );
    }
}

function wpcm_state_envelope( $state, $token ) {
    $state['sequence']   = max( 1, (int) ( $state['sequence'] ?? 0 ) + 1 );
    $state['updated_at'] = gmdate( 'c' );
    $json = json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
    return array(
        'state' => $state,
        'hmac'  => hash_hmac( 'sha256', $json, hash( 'sha256', $token, true ) ),
    );
}

function wpcm_save_state( $cfg, &$state, $token ) {
    $envelope = wpcm_state_envelope( $state, $token );
    $state    = $envelope['state'];
    $json     = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
    $slot     = 0 === ( (int) $state['sequence'] % 2 ) ? 'a' : 'b';
    wpcm_atomic_write( $cfg['session_dir'] . 'restore-state-' . $slot . '.json', $json . "\n" );

    if ( ! empty( $cfg['recovery_key'] ) && ctype_xdigit( $cfg['recovery_key'] ) && 64 === strlen( $cfg['recovery_key'] ) ) {
        $plan = array(
            'state'  => $state,
            'config' => array(
                'session_id'         => $cfg['session_id'],
                'restore_id'         => $cfg['restore_id'],
                'staging_prefix'     => $cfg['staging_prefix'],
                'rollback_prefix'    => $cfg['rollback_prefix'],
                'target_prefix'      => $cfg['target_prefix'],
                'rollback_dir'       => $cfg['rollback_dir'],
                'staging_dir'        => $cfg['staging_dir'],
                'abspath'            => $cfg['abspath'],
                'wp_content_dir'     => $cfg['wp_content_dir'],
                'plugin_dir'         => $cfg['plugin_dir'],
                'plugin_folder'      => $cfg['plugin_folder'],
                'recovery_bootstrap' => $cfg['recovery_bootstrap'] ?? '',
            ),
        );
        $plan_json = json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        $plan['hmac'] = hash_hmac( 'sha256', $plan_json, hex2bin( $cfg['recovery_key'] ) );
        $payload = "<?php\nhttp_response_code(404);\nexit;\n__halt_compiler();\n" . json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        wpcm_atomic_write( $cfg['session_dir'] . 'recovery-plan.php', $payload, 0600 );

        $terminal_phase = in_array( (string) ( $state['phase'] ?? '' ), array( 'completed', 'rolled_back' ), true );
        if ( ! is_dir( $cfg['rollback_dir'] ) && ! $terminal_phase && ! @mkdir( $cfg['rollback_dir'], 0750, true ) ) {
            throw new RuntimeException( 'Unable to create redundant rollback storage.' );
        }
        if ( is_dir( $cfg['rollback_dir'] ) ) {
            wpcm_protect_runtime_directory( $cfg['rollback_dir'] );
            wpcm_atomic_write( rtrim( $cfg['rollback_dir'], '/\\' ) . '/recovery-plan.php', $payload, 0600 );
        }
    }
}

function wpcm_load_state( $cfg, $token ) {
    $best = null;
    foreach ( array( 'restore-state-a.json', 'restore-state-b.json', 'restore-state-a.json.bak', 'restore-state-b.json.bak' ) as $name ) {
        $path = $cfg['session_dir'] . $name;
        if ( ! is_readable( $path ) ) {
            continue;
        }
        $envelope = json_decode( (string) @file_get_contents( $path ), true );
        if ( ! is_array( $envelope ) || ! is_array( $envelope['state'] ?? null ) || empty( $envelope['hmac'] ) ) {
            continue;
        }
        $json = json_encode( $envelope['state'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        $hmac = hash_hmac( 'sha256', $json, hash( 'sha256', $token, true ) );
        if ( ! hash_equals( (string) $envelope['hmac'], $hmac ) ) {
            continue;
        }
        $candidate_sequence = (int) ( $envelope['state']['sequence'] ?? 0 );
        $best_sequence      = (int) ( $best['sequence'] ?? -1 );
        if ( null === $best
            || $candidate_sequence > $best_sequence
            || ( $candidate_sequence === $best_sequence
                && strcmp( (string) ( $envelope['state']['updated_at'] ?? '' ), (string) ( $best['updated_at'] ?? '' ) ) > 0 ) ) {
            $best = $envelope['state'];
        }
    }
    return $best;
}

function wpcm_state_is_destructive( $state ) {
    return in_array(
        (string) ( $state['phase'] ?? '' ),
        array( 'promoting_files', 'files_promoted', 'committing_database', 'database_committed', 'health_check', 'rollback_required' ),
        true
    );
}

function wpcm_db_connect( $cfg ) {
    mysqli_report( MYSQLI_REPORT_OFF );
    $mysqli = new mysqli( $cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name'] );
    if ( $mysqli->connect_errno ) {
        throw new RuntimeException( 'Database connection failed: ' . $mysqli->connect_error );
    }
    $mysqli->set_charset( $cfg['db_charset'] ?: 'utf8mb4' );
    $mysqli->query( 'SET SESSION wait_timeout = 600' );
    $mysqli->query( 'SET SESSION net_read_timeout = 600' );
    $mysqli->query( 'SET SESSION net_write_timeout = 600' );
    $mysqli->query( 'SET FOREIGN_KEY_CHECKS = 0' );
    return $mysqli;
}

function wpcm_quote_identifier( $name ) {
    return '`' . str_replace( '`', '``', $name ) . '`';
}

function wpcm_tables_with_prefix( $mysqli, $prefix ) {
    $escaped = $mysqli->real_escape_string( $prefix );
    $length  = strlen( $prefix );
    $result  = $mysqli->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, {$length}) = '{$escaped}' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    );
    if ( ! $result ) {
        throw new RuntimeException( 'Unable to enumerate database tables: ' . $mysqli->error );
    }
    $tables = array();
    while ( $row = $result->fetch_row() ) {
        $tables[] = $row[0];
    }
    $result->free();
    return $tables;
}

// -----------------------------------------------------------------------------
// Adaptive restore workers.
// -----------------------------------------------------------------------------

function wpcm_runtime_memory_limit_bytes() {
    $value = trim( (string) ini_get( 'memory_limit' ) );
    if ( '' === $value || '-1' === $value ) return 0;
    $unit  = strtolower( substr( $value, -1 ) );
    $bytes = (int) $value;
    if ( 'g' === $unit ) $bytes *= 1024;
    if ( in_array( $unit, array( 'g', 'm' ), true ) ) $bytes *= 1024;
    if ( in_array( $unit, array( 'g', 'm', 'k' ), true ) ) $bytes *= 1024;
    return max( 0, $bytes );
}

function wpcm_initial_worker_budget() {
    $configured = (int) ini_get( 'max_execution_time' );
    $reference  = $configured > 0 ? $configured : 30;
    return max( 8.0, min( 20.0, $reference * 0.35 ) );
}

function wpcm_adapt_worker_budget( $worker, $duration, $processed, $completed_naturally ) {
    $worker = is_array( $worker ) ? $worker : array();
    $budget = max( 6.0, min( 20.0, (float) ( $worker['time_budget'] ?? wpcm_initial_worker_budget() ) ) );
    $streak = max( 0, (int) ( $worker['growth_streak'] ?? 0 ) );
    $limit  = wpcm_runtime_memory_limit_bytes();
    $peak   = memory_get_peak_usage( true );
    $ratio  = $limit > 0 ? $peak / $limit : 0.0;
    $reason = 'stable';

    if ( $limit > 0 && $ratio >= 0.86 ) {
        $budget = max( 6.0, $budget * 0.60 );
        $streak = 0;
        $reason = 'reduced_memory';
    } elseif ( $duration > $budget * 1.30 ) {
        $budget = max( 6.0, $budget * 0.82 );
        $streak = 0;
        $reason = 'reduced_time';
    } elseif ( ! $completed_naturally && $processed > 0 && $duration < $budget * 0.78 && $ratio < 0.72 ) {
        $streak++;
        $reason = 'learning';
        if ( $streak >= 2 ) {
            $budget = min( 20.0, $budget * 1.20 );
            $streak = 0;
            $reason = 'increased';
        }
    } elseif ( $duration >= $budget * 0.70 ) {
        // Reaching the normal worker budget is expected. Keep it stable rather
        // than treating the cooperative yield as a server-side slowdown.
        $streak = 0;
    } else {
        $streak = max( 0, $streak - 1 );
    }

    return array(
        'time_budget'       => round( $budget, 2 ),
        'growth_streak'     => $streak,
        'last_duration_ms'  => (int) round( max( 0.0, $duration ) * 1000 ),
        'last_processed'    => max( 0, (int) $processed ),
        'last_memory_peak'  => $peak,
        'last_reason'       => $reason,
    );
}

function wpcm_format_binary_bytes( $bytes ) {
    $bytes = max( 0, (int) $bytes );
    if ( $bytes >= 1073741824 ) return number_format( $bytes / 1073741824, 2, '.', '' ) . ' GiB';
    if ( $bytes >= 1048576 ) return number_format( $bytes / 1048576, 1, '.', '' ) . ' MiB';
    if ( $bytes >= 1024 ) return number_format( $bytes / 1024, 1, '.', '' ) . ' KiB';
    return $bytes . ' B';
}

// -----------------------------------------------------------------------------
// Database staging.
// -----------------------------------------------------------------------------

function wpcm_database_journal_table( $cfg ) {
    return 'wpcm_restore_journal_' . substr( hash( 'sha256', (string) ( $cfg['restore_id'] ?? '' ) ), 0, 16 );
}

function wpcm_database_journal_create( $mysqli, $cfg, $reset = false ) {
    $table = wpcm_database_journal_table( $cfg );
    if ( $reset && ! $mysqli->query( 'DROP TABLE IF EXISTS ' . wpcm_quote_identifier( $table ) ) ) {
        throw new RuntimeException( 'Unable to reset the database restore journal: ' . $mysqli->error );
    }
    $sql = 'CREATE TABLE IF NOT EXISTS ' . wpcm_quote_identifier( $table ) . ' ('
        . '`id` tinyint unsigned NOT NULL,'
        . '`file_index` int unsigned NOT NULL DEFAULT 0,'
        . '`byte_offset` bigint unsigned NOT NULL DEFAULT 0,'
        . '`queries_applied` bigint unsigned NOT NULL DEFAULT 0,'
        . '`schema_hashes` longtext NOT NULL,'
        . '`updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB';
    if ( ! $mysqli->query( $sql ) ) {
        throw new RuntimeException( 'Unable to create the database restore journal: ' . $mysqli->error );
    }
    if ( $reset && ! $mysqli->query( 'INSERT INTO ' . wpcm_quote_identifier( $table ) . " (`id`,`file_index`,`byte_offset`,`queries_applied`,`schema_hashes`) VALUES (1,0,0,0,'{}')" ) ) {
        throw new RuntimeException( 'Unable to initialize the database restore journal: ' . $mysqli->error );
    }
    return $table;
}

function wpcm_database_journal_read( $mysqli, $table ) {
    $result = $mysqli->query( 'SELECT `file_index`,`byte_offset`,`queries_applied`,`schema_hashes` FROM ' . wpcm_quote_identifier( $table ) . ' WHERE `id`=1' );
    if ( ! $result ) {
        throw new RuntimeException( 'Unable to read the database restore journal: ' . $mysqli->error );
    }
    $row = $result->fetch_assoc();
    $result->free();
    if ( ! is_array( $row ) ) {
        return array( 'file_index' => 0, 'byte_offset' => 0, 'queries' => 0, 'schema_hashes' => array() );
    }
    $schema_hashes = json_decode( (string) ( $row['schema_hashes'] ?? '' ), true );
    if ( ! is_array( $schema_hashes ) ) {
        $schema_hashes = array();
    }
    return array(
        'file_index'    => max( 0, (int) $row['file_index'] ),
        'byte_offset'   => max( 0, (int) $row['byte_offset'] ),
        'queries'       => max( 0, (int) $row['queries_applied'] ),
        'schema_hashes' => $schema_hashes,
    );
}

function wpcm_database_journal_update( $mysqli, $table, $file_index, $byte_offset, $queries, $schema_hashes = array() ) {
    $schema_json = json_encode( is_array( $schema_hashes ) ? $schema_hashes : array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( ! is_string( $schema_json ) ) {
        throw new RuntimeException( 'Unable to encode the database schema checkpoint.' );
    }
    // Use a hexadecimal SQL literal so the checkpoint remains independent of
    // NO_BACKSLASH_ESCAPES, connection collations, and JSON punctuation.
    $schema_sql = '0x' . bin2hex( $schema_json );
    $sql = 'INSERT INTO ' . wpcm_quote_identifier( $table )
        . ' (`id`,`file_index`,`byte_offset`,`queries_applied`,`schema_hashes`) VALUES (1,'
        . (int) $file_index . ',' . (int) $byte_offset . ',' . (int) $queries . ',' . $schema_sql . ')'
        . ' ON DUPLICATE KEY UPDATE `file_index`=VALUES(`file_index`),`byte_offset`=VALUES(`byte_offset`),`queries_applied`=VALUES(`queries_applied`),`schema_hashes`=VALUES(`schema_hashes`)';
    if ( ! $mysqli->query( $sql ) ) {
        throw new RuntimeException( 'Unable to update the database restore journal: ' . $mysqli->error );
    }
}

function wpcm_extract_insert_table_name( $statement ) {
    if ( preg_match( '/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`([^`]+)`/i', (string) $statement, $match ) ) {
        return (string) $match[1];
    }
    return '';
}

function wpcm_database_table_engine( $mysqli, $table, &$cache ) {
    if ( isset( $cache[ $table ] ) ) {
        return (string) $cache[ $table ];
    }
    $escaped = $mysqli->real_escape_string( $table );
    $result  = $mysqli->query( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$escaped}' LIMIT 1" );
    if ( ! $result ) {
        throw new RuntimeException( 'Unable to inspect the staged table engine: ' . $mysqli->error );
    }
    $row = $result->fetch_assoc();
    $result->free();
    $cache[ $table ] = is_array( $row ) ? strtoupper( (string) ( $row['ENGINE'] ?? '' ) ) : '';
    return (string) $cache[ $table ];
}

function wpcm_flush_database_insert_batch( $mysqli, $journal_table, &$batch, &$state ) {
    if ( empty( $batch['statements'] ) ) {
        return;
    }
    if ( ! $mysqli->query( 'START TRANSACTION' ) ) {
        throw new RuntimeException( 'Unable to start the staged database transaction: ' . $mysqli->error );
    }
    try {
        foreach ( $batch['statements'] as $statement ) {
            if ( ! $mysqli->query( $statement ) ) {
                throw new RuntimeException( 'SQL staging failed [' . $mysqli->errno . ']: ' . $mysqli->error );
            }
        }
        $new_queries = (int) $state['db']['queries'] + count( $batch['statements'] );
        wpcm_database_journal_update(
            $mysqli,
            $journal_table,
            (int) $batch['file_index'],
            (int) $batch['byte_offset'],
            $new_queries,
            (array) ( $state['db']['schema_portable_hashes'] ?? array() )
        );
        if ( ! $mysqli->query( 'COMMIT' ) ) {
            throw new RuntimeException( 'Unable to commit the staged database transaction: ' . $mysqli->error );
        }
        $state['db']['queries']     = $new_queries;
        $state['db']['file_index']  = (int) $batch['file_index'];
        $state['db']['byte_offset'] = (int) $batch['byte_offset'];
    } catch ( Throwable $error ) {
        $mysqli->query( 'ROLLBACK' );
        throw $error;
    }
    $batch = array(
        'statements'  => array(),
        'bytes'       => 0,
        'file_index'  => (int) $state['db']['file_index'],
        'byte_offset' => (int) $state['db']['byte_offset'],
    );
}

function wpcm_step_database( $cfg, &$state, $token ) {
    $started        = microtime( true );
    $database_path  = rtrim( $cfg['session_dir'], '/\\' ) . '/database.sql';
    $sql_files      = is_file( $database_path ) ? array( $database_path ) : array();
    $database_total = is_file( $database_path ) ? max( 1, (int) @filesize( $database_path ) ) : 1;
    if ( empty( $sql_files ) ) {
        throw new RuntimeException( 'database.sql is missing from the validated WPCM container.' );
    }

    $expected = is_array( $cfg['expected_tables'] ?? null ) ? array_values( $cfg['expected_tables'] ) : array();
    if ( empty( $expected ) ) {
        throw new RuntimeException( 'The archive does not contain a verifiable database table manifest.' );
    }
    $expected_by_name = array();
    foreach ( $expected as $expected_meta ) {
        if ( is_array( $expected_meta ) && ! empty( $expected_meta['name'] ) ) {
            $expected_by_name[ (string) $expected_meta['name'] ] = $expected_meta;
        }
    }

    $mysqli = wpcm_db_connect( $cfg );
    if ( 'prepared' === ( $state['phase'] ?? '' ) ) {
        foreach ( wpcm_tables_with_prefix( $mysqli, $cfg['staging_prefix'] ) as $stale ) {
            if ( ! $mysqli->query( 'DROP TABLE IF EXISTS ' . wpcm_quote_identifier( $stale ) ) ) {
                $error = $mysqli->error;
                $mysqli->close();
                throw new RuntimeException( 'Unable to remove stale staging table: ' . $error );
            }
        }
        $journal_table = wpcm_database_journal_create( $mysqli, $cfg, true );
        $state['phase'] = 'staging_database';
        $state['db']    = array(
            'file_index'      => 0,
            'byte_offset'     => 0,
            'queries'         => 0,
            'errors'          => 0,
            'validation_index'=> 0,
            'schema_portable_hashes' => array(),
            'worker'          => array(
                'time_budget'   => wpcm_initial_worker_budget(),
                'growth_streak' => 0,
                'last_reason'   => 'learning',
            ),
        );
        wpcm_save_state( $cfg, $state, $token );
    } else {
        $journal_table = wpcm_database_journal_create( $mysqli, $cfg, false );
    }

    if ( ! in_array( $state['phase'], array( 'staging_database', 'validating_database', 'database_staged' ), true ) ) {
        $mysqli->close();
        throw new RuntimeException( 'Database staging was requested in an invalid restore phase.' );
    }
    $worker      = is_array( $state['db']['worker'] ?? null ) ? $state['db']['worker'] : array();
    $time_budget = max( 6.0, min( 20.0, (float) ( $worker['time_budget'] ?? wpcm_initial_worker_budget() ) ) );

    if ( 'database_staged' === $state['phase'] ) {
        $mysqli->query( 'DROP TABLE IF EXISTS ' . wpcm_quote_identifier( $journal_table ) );
        $mysqli->close();
        return array(
            'file_index' => count( $sql_files ),
            'byte_offset'=> 0,
            'queries'    => (int) $state['db']['queries'],
            'errors'     => 0,
            'next_step'  => 'files',
            'progress'   => 45,
            'message'    => 'Database staging already validated. Preparing files...',
        );
    }

    if ( 'staging_database' === $state['phase'] ) {
        $db_checkpoint = wpcm_database_journal_read( $mysqli, $journal_table );
        $state_file_index = (int) ( $state['db']['file_index'] ?? 0 );
        $state_offset     = (int) ( $state['db']['byte_offset'] ?? 0 );
        $state_queries    = (int) ( $state['db']['queries'] ?? 0 );
        $journal_is_ahead = $db_checkpoint['file_index'] > $state_file_index
            || ( $db_checkpoint['file_index'] === $state_file_index && $db_checkpoint['byte_offset'] > $state_offset )
            || ( $db_checkpoint['file_index'] === $state_file_index && $db_checkpoint['byte_offset'] === $state_offset && $db_checkpoint['queries'] > $state_queries );
        if ( $journal_is_ahead ) {
            $state['db']['file_index']  = $db_checkpoint['file_index'];
            $state['db']['byte_offset'] = $db_checkpoint['byte_offset'];
            $state['db']['queries']     = $db_checkpoint['queries'];
        }
        if ( ! empty( $db_checkpoint['schema_hashes'] ) ) {
            $state['db']['schema_portable_hashes'] = array_merge(
                (array) ( $state['db']['schema_portable_hashes'] ?? array() ),
                (array) $db_checkpoint['schema_hashes']
            );
        }

        $file_index          = (int) ( $state['db']['file_index'] ?? 0 );
        $offset              = (int) ( $state['db']['byte_offset'] ?? 0 );
        $request_start_offset= $offset;
        $engine_cache        = array();
        $batch               = array(
            'statements'  => array(),
            'bytes'       => 0,
            'file_index'  => $file_index,
            'byte_offset' => $offset,
        );

        while ( $file_index < count( $sql_files ) ) {
            $path = $sql_files[ $file_index ];
            $fh   = @fopen( $path, 'rb' );
            if ( ! is_resource( $fh ) ) {
                $mysqli->close();
                throw new RuntimeException( 'Unable to open SQL file: ' . basename( $path ) );
            }
            if ( $offset > 0 && 0 !== fseek( $fh, $offset ) ) {
                fclose( $fh );
                $mysqli->close();
                throw new RuntimeException( 'Unable to resume SQL file: ' . basename( $path ) );
            }

            $buffer    = '';
            $in_string = false;
            $escaped   = false;
            while ( ! feof( $fh ) ) {
                $line_start = ftell( $fh );
                $line       = fgets( $fh, 1048576 );
                if ( false === $line ) {
                    break;
                }
                $trimmed = ltrim( $line );
                if ( ! $in_string && '' === trim( $buffer ) && ( '' === $trimmed || '#' === $trimmed[0] || 0 === strpos( $trimmed, '--' ) ) ) {
                    $offset = ftell( $fh );
                    continue;
                }

                $length = strlen( $line );
                for ( $i = 0; $i < $length; $i++ ) {
                    $char = $line[ $i ];
                    if ( $escaped ) {
                        $buffer .= $char;
                        $escaped = false;
                        continue;
                    }
                    if ( '\\' === $char ) {
                        $buffer .= $char;
                        $escaped = true;
                        continue;
                    }
                    if ( "'" === $char ) {
                        $in_string = ! $in_string;
                        $buffer   .= $char;
                        continue;
                    }
                    if ( ';' === $char && ! $in_string ) {
                        $source_statement = trim( $buffer );
                        $buffer           = '';
                        $offset           = $line_start + $i + 1;

                        if ( '' !== $source_statement ) {
                            $source_table = wpcm_extract_create_table_name( $source_statement );
                            if ( '' !== $source_table ) {
                                if ( ! isset( $expected_by_name[ $source_table ] ) ) {
                                    fclose( $fh );
                                    $mysqli->close();
                                    throw new RuntimeException( 'The SQL stream contains an unexpected table schema: ' . $source_table );
                                }

                                $expected_meta = $expected_by_name[ $source_table ];
                                $expected_hash = (string) ( $expected_meta['schema_hash'] ?? '' );
                                $source_sql    = wpcm_normalize_archived_create_table_for_hash( $source_statement );
                                $source_hash   = hash( 'sha256', $source_sql );
                                if ( '' !== $expected_hash && ! hash_equals( $expected_hash, $source_hash ) ) {
                                    fclose( $fh );
                                    $mysqli->close();
                                    throw new RuntimeException( 'Archived schema checksum mismatch before staging table ' . $source_table . '.' );
                                }

                                if ( ! isset( $state['db']['schema_portable_hashes'] ) || ! is_array( $state['db']['schema_portable_hashes'] ) ) {
                                    $state['db']['schema_portable_hashes'] = array();
                                }
                                $state['db']['schema_portable_hashes'][ $source_table ] = hash(
                                    'sha256',
                                    wpcm_normalize_create_table_portable_for_hash( $source_sql )
                                );
                            }

                            $statement   = wpcm_rewrite_statement_for_staging( $source_statement, $cfg );
                            $insert_table= '' !== $statement ? wpcm_extract_insert_table_name( $statement ) : '';
                            $batchable   = '' !== $insert_table && 'INNODB' === wpcm_database_table_engine( $mysqli, $insert_table, $engine_cache );

                            if ( $batchable ) {
                                $batch['statements'][] = $statement;
                                $batch['bytes']       += strlen( $statement );
                                $batch['file_index']   = $file_index;
                                $batch['byte_offset']  = $offset;
                                if ( count( $batch['statements'] ) >= 32 || $batch['bytes'] >= 16 * 1024 * 1024 || ( microtime( true ) - $started ) >= $time_budget * 0.90 ) {
                                    wpcm_flush_database_insert_batch( $mysqli, $journal_table, $batch, $state );
                                }
                            } elseif ( '' !== $statement ) {
                                wpcm_flush_database_insert_batch( $mysqli, $journal_table, $batch, $state );
                                if ( ! $mysqli->query( $statement ) ) {
                                    $errno = (int) $mysqli->errno;
                                    // A CREATE TABLE may have completed immediately before a
                                    // process interruption. Replaying it is safe when the staged
                                    // table already exists and the schema is validated later.
                                    if ( 1050 !== $errno || '' === $source_table ) {
                                        $error = $mysqli->error;
                                        fclose( $fh );
                                        $mysqli->close();
                                        throw new RuntimeException( 'SQL staging failed [' . $errno . ']: ' . $error );
                                    }
                                }
                                $state['db']['queries'] = (int) $state['db']['queries'] + 1;
                                $state['db']['file_index']  = $file_index;
                                $state['db']['byte_offset'] = $offset;
                                wpcm_database_journal_update( $mysqli, $journal_table, $file_index, $offset, (int) $state['db']['queries'], (array) ( $state['db']['schema_portable_hashes'] ?? array() ) );
                            }
                        }

                        if ( ( microtime( true ) - $started ) >= $time_budget ) {
                            wpcm_flush_database_insert_batch( $mysqli, $journal_table, $batch, $state );
                            fclose( $fh );
                            $duration  = max( 0.001, microtime( true ) - $started );
                            $processed = max( 0, $offset - $request_start_offset );
                            $state['db']['worker'] = wpcm_adapt_worker_budget( $worker, $duration, $processed, false );
                            wpcm_save_state( $cfg, $state, $token );
                            $mysqli->close();
                            $ratio    = min( 1, max( 0, $offset / $database_total ) );
                            $progress = 20 + (int) floor( $ratio * 20 );
                            $speed    = $processed > 0 ? $processed / $duration : 0;
                            return array(
                                'file_index'       => $file_index,
                                'byte_offset'      => $offset,
                                'queries'          => (int) $state['db']['queries'],
                                'errors'           => 0,
                                'next_step'        => 'database',
                                'progress'         => $progress,
                                'processed_bytes'  => $offset,
                                'total_bytes'      => $database_total,
                                'duration_ms'      => (int) round( $duration * 1000 ),
                                'throughput_bps'   => $speed,
                                'adaptive_budget'  => (float) $state['db']['worker']['time_budget'],
                                'adaptive_reason'  => (string) $state['db']['worker']['last_reason'],
                                'message'          => 'Importing database: ' . wpcm_format_binary_bytes( $offset ) . ' of ' . wpcm_format_binary_bytes( $database_total ) . ' (' . (int) floor( $ratio * 100 ) . '%), ' . (int) $state['db']['queries'] . ' statements applied.',
                            );
                        }
                        continue;
                    }
                    $buffer .= $char;
                }
            }
            wpcm_flush_database_insert_batch( $mysqli, $journal_table, $batch, $state );
            fclose( $fh );
            if ( '' !== trim( $buffer ) ) {
                $mysqli->close();
                throw new RuntimeException( 'Incomplete SQL statement at the end of ' . basename( $path ) );
            }
            $file_index++;
            $offset = 0;
            $state['db']['file_index']  = $file_index;
            $state['db']['byte_offset'] = 0;
            wpcm_database_journal_update( $mysqli, $journal_table, $file_index, 0, (int) $state['db']['queries'], (array) ( $state['db']['schema_portable_hashes'] ?? array() ) );
            wpcm_save_state( $cfg, $state, $token );
        }

        $duration  = max( 0.001, microtime( true ) - $started );
        $processed = max( 0, $database_total - $request_start_offset );
        $state['db']['worker'] = wpcm_adapt_worker_budget( $worker, $duration, $processed, true );
        $state['phase'] = 'validating_database';
        $state['db']['validation_index'] = 0;
        wpcm_save_state( $cfg, $state, $token );
        $worker      = $state['db']['worker'];
        $time_budget = max( 6.0, min( 20.0, (float) $worker['time_budget'] ) );
        $started     = microtime( true );
    }

    $actual_tables = wpcm_tables_with_prefix( $mysqli, $cfg['staging_prefix'] );
    $expected_stage_names = array();
    foreach ( $expected as $meta ) {
        if ( ! is_array( $meta ) || empty( $meta['name'] ) ) {
            $mysqli->close();
            throw new RuntimeException( 'Invalid expected table metadata.' );
        }
        $source_name = (string) $meta['name'];
        if ( 0 !== strpos( $source_name, $cfg['old_prefix'] ) ) {
            $mysqli->close();
            throw new RuntimeException( 'Source table does not match the archived prefix: ' . $source_name );
        }
        $expected_stage_names[] = $cfg['staging_prefix'] . substr( $source_name, strlen( $cfg['old_prefix'] ) );
    }
    $actual_sorted   = $actual_tables;
    $expected_sorted = $expected_stage_names;
    sort( $actual_sorted );
    sort( $expected_sorted );
    if ( $actual_sorted !== $expected_sorted ) {
        $mysqli->close();
        throw new RuntimeException( 'Staged database table set does not exactly match the validated manifest.' );
    }

    $validation_index      = (int) ( $state['db']['validation_index'] ?? 0 );
    $validation_checkpoint = $validation_index;
    while ( $validation_index < count( $expected ) ) {
        $meta        = $expected[ $validation_index ];
        $source_name = (string) $meta['name'];
        $stage_name  = $expected_stage_names[ $validation_index ];

        $result = $mysqli->query( 'SELECT COUNT(*) FROM ' . wpcm_quote_identifier( $stage_name ) );
        if ( ! $result ) {
            $error = $mysqli->error;
            $mysqli->close();
            throw new RuntimeException( 'Unable to validate staged row count: ' . $error );
        }
        $actual_rows = (int) $result->fetch_row()[0];
        $result->free();
        if ( isset( $meta['rows'] ) && $actual_rows !== (int) $meta['rows'] ) {
            $mysqli->close();
            throw new RuntimeException( 'Staged row count mismatch for table ' . $source_name . '.' );
        }

        $create_result = $mysqli->query( 'SHOW CREATE TABLE ' . wpcm_quote_identifier( $stage_name ) );
        if ( ! $create_result || ! $create_result->num_rows ) {
            $error = $mysqli->error;
            if ( $create_result ) {
                $create_result->free();
            }
            $mysqli->close();
            throw new RuntimeException( 'Unable to validate staged schema for ' . $source_name . ': ' . $error );
        }
        $create_row = $create_result->fetch_row();
        $create_result->free();
        $raw_create_sql = (string) ( $create_row[1] ?? '' );

        $legacy_create_sql = preg_replace( '/^CREATE TABLE `' . preg_quote( $stage_name, '/' ) . '`/i', 'CREATE TABLE `' . $source_name . '`', $raw_create_sql );
        $legacy_create_sql = preg_replace( '/\s+AUTO_INCREMENT=\d+/i', '', trim( (string) $legacy_create_sql ) );
        $legacy_hash       = hash( 'sha256', $legacy_create_sql );
        $expected_hash     = (string) ( $meta['schema_hash'] ?? '' );
        $schema_matches    = '' === $expected_hash || hash_equals( $expected_hash, $legacy_hash );

        $canonical_sql  = '';
        $canonical_hash = '';
        $portable_hash  = '';
        $expected_portable_hash = (string) ( $meta['schema_portable_hash'] ?? '' );
        if ( '' === $expected_portable_hash && isset( $state['db']['schema_portable_hashes'][ $source_name ] ) ) {
            $expected_portable_hash = (string) $state['db']['schema_portable_hashes'][ $source_name ];
        }

        if ( ! $schema_matches ) {
            $canonical_sql  = wpcm_normalize_staged_create_table_for_hash( $raw_create_sql, $cfg );
            $canonical_hash = hash( 'sha256', $canonical_sql );
            $schema_matches = hash_equals( $expected_hash, $canonical_hash );
            if ( $schema_matches ) {
                wpcm_debug_log( $cfg, 'warning', 'installer_schema_prefix_normalized', array(
                    'table'          => $source_name,
                    'expected_hash'  => $expected_hash,
                    'legacy_hash'    => $legacy_hash,
                    'canonical_hash' => $canonical_hash,
                ) );
            }
        }

        if ( ! $schema_matches && '' !== $expected_portable_hash ) {
            if ( '' === $canonical_sql ) {
                $canonical_sql = wpcm_normalize_staged_create_table_for_hash( $raw_create_sql, $cfg );
            }
            $portable_hash  = hash( 'sha256', wpcm_normalize_create_table_portable_for_hash( $canonical_sql ) );
            $schema_matches = hash_equals( $expected_portable_hash, $portable_hash );
            if ( $schema_matches ) {
                wpcm_debug_log( $cfg, 'warning', 'installer_schema_binary_default_normalized', array(
                    'table'                  => $source_name,
                    'expected_hash'          => $expected_hash,
                    'legacy_hash'            => $legacy_hash,
                    'canonical_hash'         => $canonical_hash,
                    'expected_portable_hash' => $expected_portable_hash,
                    'portable_hash'          => $portable_hash,
                ) );
            }
        }

        if ( $schema_matches && (int) ( $cfg['staging_rewrite_version'] ?? 1 ) < 2 ) {
            wpcm_debug_log( $cfg, 'error', 'installer_legacy_staging_restart_required', array(
                'table'          => $source_name,
                'expected_hash'  => $expected_hash,
                'legacy_hash'    => $legacy_hash,
                'canonical_hash' => $canonical_hash,
                'portable_hash'  => $portable_hash,
            ) );
            $mysqli->close();
            throw new RuntimeException( 'This restore session was staged by an older SQL identifier rewriter. Start over with the updated plugin so the database can be staged safely.' );
        }

        if ( ! $schema_matches ) {
            if ( '' === $expected_portable_hash ) {
                wpcm_debug_log( $cfg, 'error', 'installer_schema_portable_hash_missing', array(
                    'table'          => $source_name,
                    'expected_hash'  => $expected_hash,
                    'legacy_hash'    => $legacy_hash,
                    'canonical_hash' => $canonical_hash,
                ) );
                $mysqli->close();
                throw new RuntimeException( 'This restore session started before portable schema validation was available. Restart the restore with the updated plugin so the database can be staged and verified safely.' );
            }
            wpcm_debug_log( $cfg, 'error', 'installer_schema_hash_mismatch', array(
                'table'                  => $source_name,
                'expected_hash'          => $expected_hash,
                'legacy_hash'            => $legacy_hash,
                'canonical_hash'         => $canonical_hash,
                'expected_portable_hash' => $expected_portable_hash,
                'portable_hash'          => $portable_hash,
            ) );
            $mysqli->close();
            throw new RuntimeException( 'Staged schema checksum mismatch for table ' . $source_name . '.' );
        }

        if ( strlen( $cfg['target_prefix'] . substr( $stage_name, strlen( $cfg['staging_prefix'] ) ) ) > 64 ) {
            $mysqli->close();
            throw new RuntimeException( 'A destination table name would exceed MySQL\'s 64-character limit: ' . $stage_name );
        }

        $validation_index++;
        $state['db']['validation_index'] = $validation_index;
        if ( 0 === $validation_index % 25 ) {
            wpcm_save_state( $cfg, $state, $token );
            $validation_checkpoint = $validation_index;
        }

        if ( ( microtime( true ) - $started ) >= $time_budget ) {
            $duration = max( 0.001, microtime( true ) - $started );
            $state['db']['worker'] = wpcm_adapt_worker_budget( $worker, $duration, $validation_index - $validation_checkpoint, false );
            wpcm_save_state( $cfg, $state, $token );
            $mysqli->close();
            return array(
                'file_index'       => count( $sql_files ),
                'byte_offset'      => 0,
                'queries'          => (int) $state['db']['queries'],
                'errors'           => 0,
                'next_step'        => 'database',
                'progress'         => 40 + (int) floor( ( $validation_index / max( count( $expected ), 1 ) ) * 5 ),
                'duration_ms'      => (int) round( $duration * 1000 ),
                'adaptive_budget'  => (float) $state['db']['worker']['time_budget'],
                'adaptive_reason'  => (string) $state['db']['worker']['last_reason'],
                'message'          => 'Validating staged database: table ' . $validation_index . '/' . count( $expected ) . '.',
            );
        }
    }

    $state['phase']             = 'database_staged';
    $state['db']['table_count'] = count( $actual_tables );
    wpcm_save_state( $cfg, $state, $token );
    $mysqli->query( 'DROP TABLE IF EXISTS ' . wpcm_quote_identifier( $journal_table ) );
    $mysqli->close();

    return array(
        'file_index' => count( $sql_files ),
        'byte_offset'=> 0,
        'queries'    => (int) $state['db']['queries'],
        'errors'     => 0,
        'next_step'  => 'files',
        'progress'   => 45,
        'message'    => 'Database staged and validated against row counts and schema hashes. Existing tables are still untouched.',
    );
}

function wpcm_rewrite_all_quoted_identifier_prefix( $statement, $from_prefix, $to_prefix ) {
    $output  = '';
    $length  = strlen( $statement );
    $quote   = '';
    $escaped = false;

    for ( $index = 0; $index < $length; $index++ ) {
        $char = $statement[ $index ];

        if ( '' !== $quote ) {
            $output .= $char;
            if ( $escaped ) {
                $escaped = false;
                continue;
            }
            if ( '\\' === $char ) {
                $escaped = true;
                continue;
            }
            if ( $char === $quote ) {
                if ( $index + 1 < $length && $statement[ $index + 1 ] === $quote ) {
                    $output .= $statement[ ++$index ];
                    continue;
                }
                $quote = '';
            }
            continue;
        }

        if ( "'" === $char || '"' === $char ) {
            $quote   = $char;
            $output .= $char;
            continue;
        }

        if ( '`' !== $char ) {
            $output .= $char;
            continue;
        }

        $identifier = '';
        $closed     = false;
        for ( $cursor = $index + 1; $cursor < $length; $cursor++ ) {
            $current = $statement[ $cursor ];
            if ( '`' === $current ) {
                if ( $cursor + 1 < $length && '`' === $statement[ $cursor + 1 ] ) {
                    $identifier .= '`';
                    $cursor++;
                    continue;
                }
                $closed = true;
                break;
            }
            $identifier .= $current;
        }
        if ( ! $closed ) {
            throw new RuntimeException( 'Unterminated quoted identifier in restore SQL.' );
        }
        if ( '' !== $from_prefix && 0 === strpos( $identifier, $from_prefix ) ) {
            $identifier = $to_prefix . substr( $identifier, strlen( $from_prefix ) );
        }
        $output .= '`' . str_replace( '`', '``', $identifier ) . '`';
        $index   = $cursor;
    }

    if ( '' !== $quote ) {
        throw new RuntimeException( 'Unterminated quoted string in restore SQL.' );
    }
    return $output;
}

/**
 * Rewrite only table references and collision-prone constraint symbols.
 *
 * The previous implementation rewrote every quoted identifier beginning with
 * the WordPress prefix. That could rename a column or index such as
 * `wp_source_id`, then make the staged SHOW CREATE TABLE hash differ even
 * though the archive was valid. Table targets and REFERENCES clauses must be
 * staged, but column and index identifiers must remain byte-for-byte intact.
 */
function wpcm_rewrite_quoted_identifiers_for_staging( $statement, $old_prefix, $staging_prefix ) {
    $output            = '';
    $length            = strlen( $statement );
    $quote             = '';
    $escaped           = false;
    $statement_kind    = '';
    $primary_table_seen = false;
    $expect_identifier = '';

    for ( $index = 0; $index < $length; $index++ ) {
        $char = $statement[ $index ];

        if ( '' !== $quote ) {
            $output .= $char;
            if ( $escaped ) {
                $escaped = false;
                continue;
            }
            if ( '\\' === $char ) {
                $escaped = true;
                continue;
            }
            if ( $char === $quote ) {
                if ( $index + 1 < $length && $statement[ $index + 1 ] === $quote ) {
                    $output .= $statement[ ++$index ];
                    continue;
                }
                $quote = '';
            }
            continue;
        }

        if ( "'" === $char || '"' === $char ) {
            $quote   = $char;
            $output .= $char;
            continue;
        }

        if ( preg_match( '/[A-Za-z_]/', $char ) ) {
            $cursor = $index + 1;
            while ( $cursor < $length && preg_match( '/[A-Za-z0-9_$]/', $statement[ $cursor ] ) ) {
                $cursor++;
            }
            $word  = substr( $statement, $index, $cursor - $index );
            $upper = strtoupper( $word );
            $output .= $word;

            if ( '' === $statement_kind && in_array( $upper, array( 'CREATE', 'DROP', 'INSERT' ), true ) ) {
                $statement_kind = $upper;
            } elseif ( ! $primary_table_seen
                && in_array( $statement_kind, array( 'CREATE', 'DROP' ), true )
                && 'TABLE' === $upper ) {
                $expect_identifier = 'primary_table';
            } elseif ( ! $primary_table_seen && 'INSERT' === $statement_kind && 'INTO' === $upper ) {
                $expect_identifier = 'primary_table';
            } elseif ( 'REFERENCES' === $upper ) {
                $expect_identifier = 'referenced_table';
            } elseif ( 'CONSTRAINT' === $upper ) {
                $expect_identifier = 'constraint';
            }

            $index = $cursor - 1;
            continue;
        }

        if ( '`' !== $char ) {
            $output .= $char;
            continue;
        }

        $identifier = '';
        $closed     = false;
        for ( $cursor = $index + 1; $cursor < $length; $cursor++ ) {
            $current = $statement[ $cursor ];
            if ( '`' === $current ) {
                if ( $cursor + 1 < $length && '`' === $statement[ $cursor + 1 ] ) {
                    $identifier .= '`';
                    $cursor++;
                    continue;
                }
                $closed = true;
                break;
            }
            $identifier .= $current;
        }
        if ( ! $closed ) {
            throw new RuntimeException( 'Unterminated quoted identifier in restore SQL.' );
        }

        $should_rewrite = in_array( $expect_identifier, array( 'primary_table', 'referenced_table' ), true );
        if ( 'constraint' === $expect_identifier && 0 === strpos( $identifier, $old_prefix ) ) {
            $should_rewrite = true;
        }
        if ( $should_rewrite && 0 === strpos( $identifier, $old_prefix ) ) {
            $identifier = $staging_prefix . substr( $identifier, strlen( $old_prefix ) );
        }
        if ( 'primary_table' === $expect_identifier ) {
            $primary_table_seen = true;
        }
        if ( '' !== $expect_identifier ) {
            $expect_identifier = '';
        }

        $output .= '`' . str_replace( '`', '``', $identifier ) . '`';
        $index   = $cursor;
    }

    if ( '' !== $quote ) {
        throw new RuntimeException( 'Unterminated quoted string in restore SQL.' );
    }
    return $output;
}

function wpcm_extract_create_table_name( $statement ) {
    if ( preg_match( '/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`((?:``|[^`])+)`/i', (string) $statement, $matches ) ) {
        return str_replace( '``', '`', (string) $matches[1] );
    }
    return '';
}

function wpcm_normalize_archived_create_table_for_hash( $create_sql ) {
    $normalized = preg_replace(
        '/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i',
        'CREATE TABLE',
        trim( (string) $create_sql )
    );
    $normalized = preg_replace( '/\s+AUTO_INCREMENT=\d+/i', '', (string) $normalized );

    return rtrim( trim( (string) $normalized ), ";\r\n\t " );
}

function wpcm_decode_mysql_string_literal_for_hash( $literal, &$safe ) {
    $safe    = true;
    $output  = '';
    $length  = strlen( $literal );
    $escapes = array(
        '0'  => "\0",
        'b'  => "\x08",
        'n'  => "\n",
        'r'  => "\r",
        't'  => "\t",
        'Z'  => "\x1a",
        '\\' => '\\',
        "'"  => "'",
        '"'  => '"',
    );

    for ( $index = 0; $index < $length; $index++ ) {
        $char = $literal[ $index ];
        if ( "'" === $char && $index + 1 < $length && "'" === $literal[ $index + 1 ] ) {
            $output .= "'";
            $index++;
            continue;
        }
        if ( '\\' !== $char ) {
            $output .= $char;
            continue;
        }
        if ( $index + 1 >= $length ) {
            $safe = false;
            return '';
        }

        $escaped = $literal[ ++$index ];
        if ( ! array_key_exists( $escaped, $escapes ) ) {
            $safe = false;
            return '';
        }
        $output .= $escapes[ $escaped ];
    }

    return $output;
}

function wpcm_normalize_create_table_portable_for_hash( $create_sql ) {
    return preg_replace_callback(
        '/(^\s*`(?:``|[^`])+`\s+(?:var)?binary\s*\(\s*\d+\s*\)[^\r\n]*?\bDEFAULT\s+)(?:_binary\s+)?(?:(?:x|X)\'([0-9a-fA-F]*)\'|0x([0-9a-fA-F]+)|\'((?:\'\'|\\\\.|[^\'])*)\')/im',
        function ( $matches ) {
            $hex = '';
            if ( isset( $matches[2] ) && '' !== (string) $matches[2] ) {
                $hex = strtolower( (string) $matches[2] );
            } elseif ( isset( $matches[3] ) && '' !== (string) $matches[3] ) {
                $hex = strtolower( (string) $matches[3] );
            } else {
                $safe  = false;
                $bytes = wpcm_decode_mysql_string_literal_for_hash( (string) ( $matches[4] ?? '' ), $safe );
                if ( ! $safe ) {
                    return $matches[0];
                }
                $hex = bin2hex( $bytes );
            }

            return $matches[1] . "x'" . $hex . "'";
        },
        wpcm_normalize_archived_create_table_for_hash( $create_sql )
    );
}

function wpcm_normalize_staged_create_table_for_hash( $create_sql, $cfg ) {
    $normalized = wpcm_rewrite_all_quoted_identifier_prefix(
        trim( (string) $create_sql ),
        (string) $cfg['staging_prefix'],
        (string) $cfg['old_prefix']
    );
    $normalized = preg_replace( '/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i', 'CREATE TABLE', $normalized );
    $normalized = preg_replace( '/\s+AUTO_INCREMENT=\d+/i', '', $normalized );
    return rtrim( trim( (string) $normalized ), ";\r\n\t " );
}

function wpcm_rewrite_statement_for_staging( $statement, $cfg ) {
    $statement = trim( $statement );
    if ( '' === $statement ) {
        return '';
    }

    $statement = wpcm_rewrite_quoted_identifiers_for_staging(
        $statement,
        (string) $cfg['old_prefix'],
        (string) $cfg['staging_prefix']
    );

    if ( preg_match( '/^(LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i', $statement ) ) {
        return '';
    }
    if ( preg_match( '/^SET\s+(NAMES|CHARACTER_SET_CLIENT|FOREIGN_KEY_CHECKS|SQL_MODE|TIME_ZONE)\b/i', $statement ) ) {
        return $statement;
    }
    if ( preg_match( '/^DROP\s+TABLE\s+IF\s+EXISTS\b/i', $statement ) ) {
        return $statement;
    }
    if ( preg_match( '/^CREATE\s+TABLE\b/i', $statement ) ) {
        return preg_replace( '/^CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $statement, 1 );
    }
    if ( preg_match( '/^INSERT\s+INTO\b/i', $statement ) ) {
        // INSERT IGNORE makes replay safe if MySQL commits before the durable
        // byte cursor can be persisted after an interrupted request.
        return preg_replace( '/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $statement, 1 );
    }

    throw new RuntimeException( 'Unsupported SQL statement in restore archive: ' . substr( preg_replace( '/\s+/', ' ', $statement ), 0, 120 ) );
}


// -----------------------------------------------------------------------------
// File staging.
// -----------------------------------------------------------------------------

function wpcm_step_files( $cfg, &$state, $token ) {
    if ( ! in_array( $state['phase'], array( 'database_staged', 'staging_files', 'files_staged' ), true ) ) {
        throw new RuntimeException( 'File staging was requested in an invalid restore phase.' );
    }
    if ( 'files_staged' === $state['phase'] ) {
        return array( 'next_step' => 'replace_urls', 'progress' => 65, 'message' => 'WPCM files are already staged. Replacing URLs in staged tables...' );
    }

    $source_content = rtrim( $cfg['session_dir'], '/\\' ) . '/wp-content';
    $stage_content  = rtrim( $cfg['staging_dir'], '/\\' ) . '/content';
    $stage_root     = rtrim( $cfg['staging_dir'], '/\\' ) . '/root';
    if ( ! is_dir( dirname( $stage_content ) ) && ! @mkdir( dirname( $stage_content ), 0750, true ) ) {
        throw new RuntimeException( 'Unable to create the file staging area.' );
    }
    if ( ! is_dir( $stage_root ) && ! @mkdir( $stage_root, 0750, true ) ) {
        throw new RuntimeException( 'Unable to create the root-file staging area.' );
    }

    $state['phase'] = 'staging_files';
    wpcm_save_state( $cfg, $state, $token );

    if ( is_dir( $source_content ) && ! file_exists( $stage_content ) ) {
        if ( ! @rename( $source_content, $stage_content ) ) {
            throw new RuntimeException( 'Unable to atomically move validated WPCM files into staging.' );
        }
    } elseif ( is_dir( $source_content ) && is_dir( $stage_content ) ) {
        throw new RuntimeException( 'Both extracted and staged wp-content trees exist; refusing an ambiguous resume.' );
    } elseif ( ! is_dir( $source_content ) && ! is_dir( $stage_content ) ) {
        if ( (int) ( $cfg['files_count'] ?? 0 ) > 0 ) {
            throw new RuntimeException( 'The validated WPCM wp-content tree is missing.' );
        }
        if ( ! @mkdir( $stage_content, 0750, true ) ) {
            throw new RuntimeException( 'Unable to represent an empty staged wp-content tree.' );
        }
    }

    // The archive reader has already verified every block hash, every file size,
    // the complete manifest inventory, and the global payload checksum before the
    // installer can be generated. Rewalking a tree containing 100,000+ files here
    // would repeat the slowest filesystem operation without adding new integrity.
    if ( is_link( $stage_content ) || ! is_dir( $stage_content ) ) {
        throw new RuntimeException( 'The validated WPCM staging root is unsafe or missing.' );
    }
    $count = max( 0, (int) ( $cfg['files_count'] ?? 0 ) );
    $bytes = max( 0, (int) ( $cfg['files_size'] ?? 0 ) );

    foreach ( array( 'themes', 'plugins', 'uploads', 'mu-plugins', 'languages' ) as $name ) {
        if ( ! empty( $cfg['content_presence'][ $name ] ) && ! is_dir( $stage_content . '/' . $name ) && ! @mkdir( $stage_content . '/' . $name, 0750, true ) ) {
            throw new RuntimeException( 'Unable to create an empty staged content directory: ' . $name );
        }
    }

    $state['files']['staged'] = true;
    $state['files']['file_count'] = $count;
    $state['files']['file_bytes'] = $bytes;
    $state['phase'] = 'files_staged';
    wpcm_save_state( $cfg, $state, $token );
    return array(
        'restored' => $count,
        'errors' => 0,
        'next_step' => 'replace_urls',
        'progress' => 65,
        'message' => number_format( $count, 0, '.', ',' ) . ' validated WPCM file(s) staged by atomic rename (' . wpcm_format_binary_bytes( $bytes ) . '). Existing files are still untouched.',
    );
}




function wpcm_safe_relative_path( $path ) {
    if ( '' === $path || false !== strpos( $path, "\0" ) || '/' === $path[0] || '\\' === $path[0] || preg_match( '/^[a-zA-Z]:/', $path ) ) {
        return false;
    }
    $normalized = str_replace( '\\', '/', $path );
    $is_dir     = '/' === substr( $normalized, -1 );
    $parts      = explode( '/', $normalized );
    foreach ( $parts as $index => $part ) {
        if ( $is_dir && $index === count( $parts ) - 1 && '' === $part ) {
            continue;
        }
        if ( '' === $part || '.' === $part || '..' === $part ) {
            return false;
        }
    }
    return true;
}






// -----------------------------------------------------------------------------
// URL and path replacement in staged tables.
// -----------------------------------------------------------------------------

function wpcm_step_replace_urls( $cfg, &$state, $token ) {
    if ( ! in_array( $state['phase'], array( 'files_staged', 'replacing_urls', 'urls_replaced' ), true ) ) {
        throw new RuntimeException( 'URL replacement was requested in an invalid restore phase.' );
    }
    if ( 'urls_replaced' === $state['phase'] ) {
        return array(
            'table_index' => (int) ( $state['replace']['table_index'] ?? 0 ),
            'row_offset'  => 0,
            'rows'        => (int) ( $state['replace']['rows'] ?? 0 ),
            'cells'       => (int) ( $state['replace']['cells'] ?? 0 ),
            'serial'      => (int) ( $state['replace']['serialized'] ?? 0 ),
            'next_step'   => 'finalize',
            'progress'    => 85,
            'message'     => 'URL replacement already completed.',
        );
    }
    if ( 'files_staged' === $state['phase'] ) {
        $state['phase'] = 'replacing_urls';
        $state['replace']['strategy_version'] = 2;
        wpcm_save_state( $cfg, $state, $token );
    }

    $mysqli = wpcm_db_connect( $cfg );
    $pairs  = wpcm_build_replacement_pairs( $cfg );
    $schema = wpcm_replacement_schema( $mysqli, $cfg['staging_prefix'] );
    $tables = array_keys( $schema );
    sort( $tables );
    $expected_rows = array();
    $total_rows    = 0;
    foreach ( (array) ( $cfg['expected_tables'] ?? array() ) as $expected_meta ) {
        if ( ! is_array( $expected_meta ) || empty( $expected_meta['name'] ) ) continue;
        $source_name = (string) $expected_meta['name'];
        if ( 0 !== strpos( $source_name, $cfg['old_prefix'] ) ) continue;
        $stage_name = $cfg['staging_prefix'] . substr( $source_name, strlen( $cfg['old_prefix'] ) );
        if ( ! isset( $schema[ $stage_name ] ) ) continue;
        $rows = max( 0, (int) ( $expected_meta['rows'] ?? 0 ) );
        $expected_rows[ $stage_name ] = $rows;
        $total_rows += $rows;
    }

    foreach ( array( 'rows', 'cells', 'serialized', 'scanned', 'candidates' ) as $counter ) {
        $state['replace'][ $counter ] = max( 0, (int) ( $state['replace'][ $counter ] ?? 0 ) );
    }
    if ( ! isset( $state['replace']['skipped'] ) || ! is_array( $state['replace']['skipped'] ) ) {
        $state['replace']['skipped'] = array();
    }

    $table_index = max( 0, (int) ( $state['replace']['table_index'] ?? 0 ) );
    $last_key    = $state['replace']['last_key'] ?? null;
    $page_size   = max( 100, min( 10000, (int) ( $state['replace']['page_size'] ?? 1000 ) ) );
    $page_streak = max( 0, (int) ( $state['replace']['growth_streak'] ?? 0 ) );
    $worker      = array(
        'time_budget'   => (float) ( $state['replace']['time_budget'] ?? wpcm_initial_worker_budget() ),
        'growth_streak' => (int) ( $state['replace']['worker_growth_streak'] ?? 0 ),
    );
    $time_budget = max( 6.0, min( 20.0, (float) $worker['time_budget'] ) );
    $started      = microtime( true );
    $selected_this_request = 0;

    while ( $table_index < count( $tables ) ) {
        $table = $tables[ $table_index ];
        $meta  = $schema[ $table ];
        if ( empty( $meta['key_columns'] ) || empty( $meta['columns'] ) ) {
            $state['replace']['skipped'][] = $table;
            $state['replace']['scanned']  += (int) ( $expected_rows[ $table ] ?? 0 );
            $table_index++;
            $last_key = null;
            $state['replace']['table_index'] = $table_index;
            $state['replace']['last_key']    = null;
            continue;
        }

        $page_started = microtime( true );
        $result        = wpcm_replace_table_page( $mysqli, $table, $meta, $pairs, $last_key, $page_size );
        $page_duration = max( 0.001, microtime( true ) - $page_started );
        $selected      = (int) $result['selected'];
        $selected_this_request       += $selected;
        $state['replace']['candidates'] += $selected;

        $memory_limit = wpcm_runtime_memory_limit_bytes();
        $memory_ratio = $memory_limit > 0 ? memory_get_peak_usage( true ) / $memory_limit : 0.0;
        $page_reason  = 'stable';
        if ( $memory_limit > 0 && $memory_ratio >= 0.84 ) {
            $page_size   = max( 100, (int) floor( $page_size * 0.50 ) );
            $page_streak = 0;
            $page_reason = 'reduced_memory';
        } elseif ( $page_duration > 4.5 ) {
            $page_size   = max( 100, (int) floor( $page_size * 0.65 ) );
            $page_streak = 0;
            $page_reason = 'reduced_time';
        } elseif ( $selected >= $page_size && $page_duration < 1.5 && $memory_ratio < 0.72 ) {
            $page_streak++;
            $page_reason = 'learning';
            if ( $page_streak >= 2 ) {
                $page_size   = min( 10000, (int) ceil( $page_size * 1.60 ) );
                $page_streak = 0;
                $page_reason = 'increased';
            }
        } elseif ( $page_duration >= 2.5 ) {
            $page_streak = 0;
        } else {
            $page_streak = max( 0, $page_streak - 1 );
        }
        $page_size = max( 100, min( 10000, (int) ( floor( $page_size / 100 ) * 100 ) ) );
        $state['replace']['page_size']     = $page_size;
        $state['replace']['growth_streak'] = $page_streak;
        $state['replace']['last_reason']   = $page_reason;
        $state['replace']['last_page_ms']  = (int) round( $page_duration * 1000 );

        $state['replace']['rows']       += (int) $result['rows'];
        $state['replace']['cells']      += (int) $result['cells'];
        $state['replace']['serialized'] += (int) $result['serialized'];
        if ( $result['done'] ) {
            // Exact global progress advances only after the complete table has
            // been searched. Candidate rows are tracked separately.
            $state['replace']['scanned'] += (int) ( $expected_rows[ $table ] ?? 0 );
            $table_index++;
            $last_key = null;
        } else {
            $last_key = $result['last_key'];
        }
        $state['replace']['table_index'] = $table_index;
        $state['replace']['last_key']    = $last_key;

        if ( ( microtime( true ) - $started ) >= $time_budget ) {
            $duration = max( 0.001, microtime( true ) - $started );
            $adapted  = wpcm_adapt_worker_budget( $worker, $duration, $selected_this_request, false );
            $state['replace']['time_budget']          = (float) $adapted['time_budget'];
            $state['replace']['worker_growth_streak'] = (int) $adapted['growth_streak'];
            $state['replace']['worker_reason']        = (string) $adapted['last_reason'];
            wpcm_save_state( $cfg, $state, $token );
            $mysqli->close();
            return array(
                'table_index'       => $table_index,
                'row_offset'        => 0,
                'rows'              => (int) $state['replace']['rows'],
                'cells'             => (int) $state['replace']['cells'],
                'serial'            => (int) $state['replace']['serialized'],
                'next_step'         => 'replace_urls',
                'progress'          => 65 + (int) floor( min( 1, (int) $state['replace']['scanned'] / max( $total_rows, 1 ) ) * 20 ),
                'page_size'         => $page_size,
                'duration_ms'       => (int) round( $duration * 1000 ),
                'adaptive_budget'   => (float) $adapted['time_budget'],
                'adaptive_reason'   => (string) $state['replace']['last_reason'],
                'scanned_rows'      => (int) $state['replace']['scanned'],
                'candidate_rows'    => (int) $state['replace']['candidates'],
                'total_rows'        => $total_rows,
                'message'           => 'Replacing URLs: table ' . $table_index . '/' . count( $tables ) . '; ' . number_format( (int) $state['replace']['candidates'], 0, '.', ',' ) . ' matching rows processed; adaptive page ' . $page_size . '. Existing tables are still untouched.',
            );
        }
    }

    wpcm_prepare_staged_wordpress_options( $mysqli, $cfg );
    $mysqli->close();
    $state['phase'] = 'urls_replaced';
    $state['replace']['table_index'] = count( $tables );
    $state['replace']['last_key']    = null;
    $state['replace']['scanned']     = $total_rows;
    wpcm_save_state( $cfg, $state, $token );
    return array(
        'table_index' => count( $tables ),
        'row_offset'  => 0,
        'rows'        => (int) $state['replace']['rows'],
        'cells'       => (int) $state['replace']['cells'],
        'serial'      => (int) $state['replace']['serialized'],
        'next_step'   => 'finalize',
        'progress'    => 85,
        'message'     => 'URL replacement completed in staged tables. Ready for transactional promotion.',
    );
}

function wpcm_replacement_schema( $mysqli, $prefix ) {
    $prefix_esc = $mysqli->real_escape_string( $prefix );
    $prefix_len = strlen( $prefix );
    $sql = "SELECT c.TABLE_NAME,c.COLUMN_NAME,c.DATA_TYPE,c.CHARACTER_MAXIMUM_LENGTH,c.ORDINAL_POSITION,t.ENGINE
            FROM information_schema.COLUMNS c
            INNER JOIN information_schema.TABLES t
                ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE='BASE TABLE'
            WHERE c.TABLE_SCHEMA=DATABASE() AND LEFT(c.TABLE_NAME,{$prefix_len})='{$prefix_esc}'
              AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','enum','set')
            ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION";
    $result = $mysqli->query( $sql );
    if ( ! $result ) {
        throw new RuntimeException( 'Unable to inspect staged table schema: ' . $mysqli->error );
    }
    $schema = array();
    while ( $row = $result->fetch_assoc() ) {
        $table = $row['TABLE_NAME'];
        $schema[ $table ]['columns'][] = $row['COLUMN_NAME'];
        $schema[ $table ]['max'][ $row['COLUMN_NAME'] ] = null === $row['CHARACTER_MAXIMUM_LENGTH'] ? PHP_INT_MAX : (int) $row['CHARACTER_MAXIMUM_LENGTH'];
        $schema[ $table ]['engine'] = strtoupper( (string) ( $row['ENGINE'] ?? '' ) );
    }
    $result->free();

    // URL replacement requires a unique, non-null traversal key whose columns
    // are not themselves modified by replacement. Composite keys are supported.
    foreach ( array_keys( $schema ) as $table ) {
        $table_escaped = $mysqli->real_escape_string( $table );
        $unique = $mysqli->query(
            "SELECT s.INDEX_NAME,s.COLUMN_NAME,s.SEQ_IN_INDEX,c.IS_NULLABLE
             FROM information_schema.STATISTICS s
             INNER JOIN information_schema.COLUMNS c
                ON c.TABLE_SCHEMA=s.TABLE_SCHEMA AND c.TABLE_NAME=s.TABLE_NAME AND c.COLUMN_NAME=s.COLUMN_NAME
             WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME='{$table_escaped}' AND s.NON_UNIQUE=0
             ORDER BY (s.INDEX_NAME='PRIMARY') DESC,s.INDEX_NAME ASC,s.SEQ_IN_INDEX ASC"
        );
        if ( ! $unique ) {
            throw new RuntimeException( 'Unable to inspect a unique traversal key for ' . $table . ': ' . $mysqli->error );
        }

        $candidates = array();
        while ( $row = $unique->fetch_assoc() ) {
            $index_name = (string) ( $row['INDEX_NAME'] ?? '' );
            $column     = (string) ( $row['COLUMN_NAME'] ?? '' );
            if ( '' === $index_name ) continue;
            if ( ! isset( $candidates[ $index_name ] ) ) {
                $candidates[ $index_name ] = array( 'index_name' => $index_name, 'columns' => array(), 'eligible' => true );
            }
            if ( '' === $column ) {
                $candidates[ $index_name ]['eligible'] = false;
                continue;
            }
            if ( 'NO' !== (string) ( $row['IS_NULLABLE'] ?? '' ) || in_array( $column, $schema[ $table ]['columns'], true ) ) {
                $candidates[ $index_name ]['eligible'] = false;
            }
            $sequence = max( 1, (int) ( $row['SEQ_IN_INDEX'] ?? 1 ) );
            $candidates[ $index_name ]['columns'][ $sequence ] = $column;
        }
        $unique->free();

        $eligible = array();
        foreach ( $candidates as $candidate ) {
            if ( empty( $candidate['eligible'] ) || empty( $candidate['columns'] ) ) continue;
            ksort( $candidate['columns'], SORT_NUMERIC );
            $candidate['columns'] = array_values( $candidate['columns'] );
            unset( $candidate['eligible'] );
            $eligible[] = $candidate;
        }
        usort( $eligible, function ( $left, $right ) {
            $left_primary = 'PRIMARY' === $left['index_name'];
            $right_primary = 'PRIMARY' === $right['index_name'];
            if ( $left_primary !== $right_primary ) return $left_primary ? -1 : 1;
            $column_compare = count( $left['columns'] ) <=> count( $right['columns'] );
            return 0 !== $column_compare ? $column_compare : strcmp( $left['index_name'], $right['index_name'] );
        } );
        if ( ! empty( $eligible ) ) {
            $schema[ $table ]['key_index']   = $eligible[0]['index_name'];
            $schema[ $table ]['key_columns'] = $eligible[0]['columns'];
        }
    }
    return $schema;
}

function wpcm_encode_resume_key( array $values ) {
    return array_map( function ( $value ) { return base64_encode( (string) $value ); }, array_values( $values ) );
}

function wpcm_decode_resume_key( array $encoded, $expected ) {
    if ( count( $encoded ) !== (int) $expected ) throw new RuntimeException( 'Invalid staged-table resume key.' );
    $values = array();
    foreach ( $encoded as $value ) {
        if ( ! is_string( $value ) ) throw new RuntimeException( 'Invalid staged-table resume key value.' );
        $decoded = base64_decode( $value, true );
        if ( false === $decoded ) throw new RuntimeException( 'Invalid staged-table resume key encoding.' );
        $values[] = $decoded;
    }
    return $values;
}

function wpcm_extract_resume_key( array $row, array $columns, $table ) {
    $values = array();
    foreach ( $columns as $column ) {
        if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) {
            throw new RuntimeException( 'Incomplete staged-table resume key for ' . $table . '.' );
        }
        $values[] = $row[ $column ];
    }
    return wpcm_encode_resume_key( $values );
}

function wpcm_sql_literal( $mysqli, $value ) {
    return "'" . $mysqli->real_escape_string( (string) $value ) . "'";
}

function wpcm_keyset_sql( $mysqli, array $columns, array $encoded, $relation ) {
    if ( empty( $columns ) || ! in_array( $relation, array( 'after', 'exact' ), true ) ) {
        throw new RuntimeException( 'Unable to build a staged-table keyset predicate.' );
    }
    $values = wpcm_decode_resume_key( $encoded, count( $columns ) );
    if ( 'exact' === $relation ) {
        $parts = array();
        foreach ( $columns as $index => $column ) {
            $parts[] = wpcm_quote_identifier( $column ) . '=' . wpcm_sql_literal( $mysqli, $values[ $index ] );
        }
        return '(' . implode( ' AND ', $parts ) . ')';
    }

    $terms = array();
    foreach ( $columns as $index => $column ) {
        $parts = array();
        for ( $prefix = 0; $prefix < $index; $prefix++ ) {
            $parts[] = wpcm_quote_identifier( $columns[ $prefix ] ) . '=' . wpcm_sql_literal( $mysqli, $values[ $prefix ] );
        }
        $parts[] = wpcm_quote_identifier( $column ) . '>' . wpcm_sql_literal( $mysqli, $values[ $index ] );
        $terms[] = '(' . implode( ' AND ', $parts ) . ')';
    }
    return '(' . implode( ' OR ', $terms ) . ')';
}

function wpcm_replacement_candidate_sql( $mysqli, array $columns, array $pairs ) {
    $needles = array();
    foreach ( $pairs as $pair ) {
        $needle = isset( $pair[0] ) ? (string) $pair[0] : '';
        if ( '' !== $needle ) {
            $needles[ $needle ] = true;
        }
    }
    if ( empty( $needles ) || empty( $columns ) ) {
        return '';
    }

    $terms = array();
    foreach ( $columns as $column ) {
        $identifier = wpcm_quote_identifier( $column );
        foreach ( array_keys( $needles ) as $needle ) {
            // LOCATE avoids LIKE wildcard ambiguity for percent-encoded URLs
            // while BINARY preserves byte-for-byte, case-sensitive matching.
            $terms[] = 'LOCATE(BINARY ' . wpcm_sql_literal( $mysqli, $needle ) . ', BINARY ' . $identifier . ')>0';
        }
    }
    return '(' . implode( ' OR ', $terms ) . ')';
}

function wpcm_replace_table_page( $mysqli, $table, $meta, $pairs, $last_key, $limit ) {
    $limit       = max( 100, min( 10000, (int) $limit ) );
    $key_columns = array_values( $meta['key_columns'] );
    $columns     = $meta['columns'];
    $candidate   = wpcm_replacement_candidate_sql( $mysqli, $columns, $pairs );
    if ( '' === $candidate ) {
        return array( 'done' => true, 'last_key' => $last_key, 'rows' => 0, 'cells' => 0, 'serialized' => 0, 'selected' => 0 );
    }

    $select_columns = array_values( array_unique( array_merge( $key_columns, $columns ) ) );
    $select = implode( ',', array_map( 'wpcm_quote_identifier', $select_columns ) );
    $where_parts = array( $candidate );
    if ( null !== $last_key ) {
        $where_parts[] = wpcm_keyset_sql( $mysqli, $key_columns, $last_key, 'after' );
    }
    $where = ' WHERE ' . implode( ' AND ', $where_parts );
    $order = implode( ',', array_map( function ( $column ) { return wpcm_quote_identifier( $column ) . ' ASC'; }, $key_columns ) );
    $query = 'SELECT ' . $select . ' FROM ' . wpcm_quote_identifier( $table ) . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit;
    $result = $mysqli->query( $query );
    if ( ! $result ) throw new RuntimeException( 'Unable to read staged table ' . $table . ': ' . $mysqli->error );

    $transactional = 'INNODB' === strtoupper( (string) ( $meta['engine'] ?? '' ) );
    if ( $transactional && $result->num_rows > 0 && ! $mysqli->query( 'START TRANSACTION' ) ) {
        $result->free();
        throw new RuntimeException( 'Unable to start URL replacement transaction for ' . $table . ': ' . $mysqli->error );
    }

    $rows = 0; $cells = 0; $serialized = 0; $last = $last_key;
    try {
        while ( $row = $result->fetch_assoc() ) {
            $row_key = wpcm_extract_resume_key( $row, $key_columns, $table );
            $last    = $row_key;
            $updates = array();
            foreach ( $columns as $column ) {
                if ( ! isset( $row[ $column ] ) || ! is_string( $row[ $column ] ) || '' === $row[ $column ] ) continue;
                $changed = false;
                foreach ( $pairs as $pair ) {
                    if ( false !== strpos( $row[ $column ], $pair[0] ) ) { $changed = true; break; }
                }
                if ( ! $changed ) continue;
                try {
                    $new_value = wpcm_replace_value( $row[ $column ], $pairs, $serialized );
                } catch ( Throwable $error ) {
                    throw new RuntimeException(
                        'URL replacement failed in staged table ' . $table . ', column ' . $column . ': ' . $error->getMessage(),
                        0,
                        $error
                    );
                }
                if ( $new_value === $row[ $column ] ) continue;
                $max = $meta['max'][ $column ] ?? PHP_INT_MAX;
                if ( $max < PHP_INT_MAX && strlen( $new_value ) > $max ) continue;
                $updates[ $column ] = $new_value;
            }
            if ( ! empty( $updates ) ) {
                $parts = array();
                foreach ( $updates as $column => $value ) {
                    $parts[] = wpcm_quote_identifier( $column ) . "='" . $mysqli->real_escape_string( $value ) . "'";
                }
                $sql = 'UPDATE ' . wpcm_quote_identifier( $table ) . ' SET ' . implode( ',', $parts ) . ' WHERE ' . wpcm_keyset_sql( $mysqli, $key_columns, $row_key, 'exact' ) . ' LIMIT 1';
                if ( ! $mysqli->query( $sql ) ) {
                    if ( 1062 !== $mysqli->errno ) {
                        throw new RuntimeException( 'URL replacement failed in ' . $table . ': ' . $mysqli->error );
                    }
                } else {
                    $rows++;
                    $cells += count( $updates );
                }
            }
        }
        if ( $transactional && $result->num_rows > 0 && ! $mysqli->query( 'COMMIT' ) ) {
            throw new RuntimeException( 'Unable to commit URL replacement transaction for ' . $table . ': ' . $mysqli->error );
        }
    } catch ( Throwable $error ) {
        if ( $transactional ) {
            $mysqli->query( 'ROLLBACK' );
        }
        $result->free();
        throw $error;
    }

    $count = $result->num_rows;
    $result->free();
    return array( 'done' => $count < $limit, 'last_key' => $last, 'rows' => $rows, 'cells' => $cells, 'serialized' => $serialized, 'selected' => $count );
}

function wpcm_build_replacement_pairs( $cfg ) {
    $pairs = array();
    foreach ( array( $cfg['old_url'] ?? '', $cfg['old_home'] ?? '' ) as $old ) {
        $old = rtrim( (string) $old, '/' );
        $new = rtrim( (string) $cfg['new_url'], '/' );
        if ( '' !== $old && $old !== $new ) {
            $pairs[] = array( $old, $new );
            $pairs[] = array( str_replace( '/', '\\/', $old ), str_replace( '/', '\\/', $new ) );
            $pairs[] = array( rawurlencode( $old ), rawurlencode( $new ) );
        }
    }
    $old_path = rtrim( (string) ( $cfg['old_path'] ?? '' ), '/\\' );
    $new_path = rtrim( (string) ( $cfg['new_path'] ?? '' ), '/\\' );
    if ( '' !== $old_path && $old_path !== $new_path ) {
        $pairs[] = array( $old_path, $new_path );
        $pairs[] = array( str_replace( '/', '\\/', $old_path ), str_replace( '/', '\\/', $new_path ) );
    }
    $unique = array();
    foreach ( $pairs as $pair ) $unique[ $pair[0] ] = $pair;
    return array_values( $unique );
}

function wpcm_contains_percent_placeholder( $value ) {
    return is_string( $value ) && 1 === preg_match( '/\{[a-f0-9]{64}\}/i', $value );
}

function wpcm_string_needs_replacement( $value, $pairs ) {
    foreach ( $pairs as $pair ) {
        if ( '' !== (string) $pair[0] && false !== strpos( $value, (string) $pair[0] ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Determine whether a database string has a complete PHP serialization header.
 *
 * Prefixes such as "s:" or "a:" may legitimately occur in excerpts, logs, CSS,
 * and filesystem-related text. Treating every such prefix as serialized data can
 * block a migration when the same cell also contains an old absolute path. This
 * check only accepts canonical headers emitted by PHP's serialize() function.
 * Once accepted, the strict token parser still validates the complete payload.
 *
 * @param string $value Candidate database value.
 * @return bool Whether the value has a canonical serialized-value header.
 */
function wpcm_is_serialized_value_candidate( $value ) {
    if ( 'N;' === $value ) {
        return true;
    }

    if ( 1 === preg_match( '/^(?:s|E):[0-9]+:"/s', $value )
        || 1 === preg_match( '/^a:[0-9]+:\{/s', $value )
        || 1 === preg_match( '/^[OC]:[0-9]+:"/s', $value ) ) {
        return true;
    }

    if ( 1 === preg_match( '/^b:[01];$/D', $value )
        || 1 === preg_match( '/^(?:i|R|r):-?[0-9]+;$/D', $value )
        || 1 === preg_match( '/^d:(?:NAN|-?INF|[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:E[+-]?[0-9]+)?);$/iD', $value ) ) {
        return true;
    }

    return false;
}

function wpcm_replace_value( $value, $pairs, &$serialized_count ) {
    $value = (string) $value;
    if ( empty( $pairs ) || ! wpcm_string_needs_replacement( $value, $pairs ) ) {
        return $value;
    }

    if ( wpcm_is_serialized_value_candidate( $value ) ) {
        try {
            $serialized = wpcm_replace_serialized_value( $value, $pairs );
        } catch ( RuntimeException $parse_error ) {
            /*
             * Match WP-CLI search-replace semantics: only a complete payload
             * that can be parsed is treated as serialized data. Reference and
             * excerpt tables may store truncated snippets that begin with a
             * valid serialization header. Those snippets are ordinary text,
             * not database serialization, so replace them as strings instead
             * of aborting the complete transactional restore.
             *
             * The safe token parser remains the validator. We intentionally do
             * not call unserialize() on archive data or instantiate classes.
             */
            return wpcm_replace_string( $value, $pairs );
        }

        if ( wpcm_replace_serialized_value( $serialized, array() ) !== $serialized ) {
            throw new RuntimeException( 'A modified serialized value did not survive a parser validation round trip.' );
        }
        if ( wpcm_contains_percent_placeholder( $serialized ) ) {
            throw new RuntimeException( 'An unresolved WordPress percent placeholder appeared in a serialized value.' );
        }
        $serialized_count++;
        return $serialized;
    }

    if ( '' !== $value && ( '{' === $value[0] || '[' === $value[0] ) ) {
        $data = json_decode( $value, true );
        if ( JSON_ERROR_NONE === json_last_error() ) {
            $json = json_encode( wpcm_replace_recursive( $data, $pairs ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
            if ( ! is_string( $json ) || JSON_ERROR_NONE !== json_last_error() ) {
                throw new RuntimeException( 'A modified JSON value failed validation.' );
            }
            if ( wpcm_contains_percent_placeholder( $json ) ) {
                throw new RuntimeException( 'An unresolved WordPress percent placeholder appeared in a JSON value.' );
            }
            return $json;
        }
    }

    return wpcm_replace_string( $value, $pairs );
}

/**
 * Replace strings inside a PHP serialized payload without instantiating classes.
 *
 * This parser preserves object class names, property names, references, scalar
 * types, and container counts. Only serialized string payloads are changed, and
 * their byte lengths are recalculated. This safely supports nested objects that
 * unserialize() exposes as __PHP_Incomplete_Class when classes are disabled.
 *
 * @param string $value Serialized payload.
 * @param array  $pairs Replacement pairs.
 * @return string Rewritten serialized payload.
 */
function wpcm_replace_serialized_value( $value, $pairs ) {
    $offset    = 0;
    $rewritten = wpcm_replace_serialized_token( $value, $offset, $pairs, true );
    if ( $offset !== strlen( $value ) ) {
        throw new RuntimeException( 'A serialized database value contains trailing bytes.' );
    }
    return $rewritten;
}

/**
 * Parse and rewrite one PHP serialized token.
 *
 * @param string $input           Serialized input.
 * @param int    $offset          Current byte offset, updated by reference.
 * @param array  $pairs           Replacement pairs.
 * @param bool   $replace_strings Whether string payloads may be replaced.
 * @return string Rewritten token.
 */
function wpcm_replace_serialized_token( $input, &$offset, $pairs, $replace_strings ) {
    $length = strlen( $input );
    if ( $offset >= $length ) {
        throw new RuntimeException( 'A serialized database value ended unexpectedly.' );
    }

    $type = $input[ $offset++ ];
    if ( 'N' === $type ) {
        wpcm_serialized_expect( $input, $offset, ';' );
        return 'N;';
    }

    if ( in_array( $type, array( 'b', 'i', 'd', 'R', 'r' ), true ) ) {
        wpcm_serialized_expect( $input, $offset, ':' );
        $end = strpos( $input, ';', $offset );
        if ( false === $end ) {
            throw new RuntimeException( 'A serialized scalar value is incomplete.' );
        }
        $payload = substr( $input, $offset, $end - $offset );
        if ( ( 'b' === $type && ! preg_match( '/^[01]$/', $payload ) )
            || ( in_array( $type, array( 'i', 'R', 'r' ), true ) && ! preg_match( '/^-?[0-9]+$/', $payload ) )
            || ( 'd' === $type && ! preg_match( '/^(?:NAN|-?INF|[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:E[+-]?[0-9]+)?)$/i', $payload ) ) ) {
            throw new RuntimeException( 'A serialized scalar value is invalid.' );
        }
        $offset = $end + 1;
        return $type . ':' . $payload . ';';
    }

    if ( 's' === $type || 'E' === $type ) {
        wpcm_serialized_expect( $input, $offset, ':' );
        $string_length = wpcm_serialized_read_length( $input, $offset );
        wpcm_serialized_expect( $input, $offset, '"' );
        if ( $string_length > $length - $offset ) {
            throw new RuntimeException( 'A serialized string length exceeds the available payload.' );
        }
        $payload = substr( $input, $offset, $string_length );
        $offset += $string_length;
        wpcm_serialized_expect( $input, $offset, '"' );
        wpcm_serialized_expect( $input, $offset, ';' );
        $updated = ( 's' === $type && $replace_strings ) ? wpcm_replace_string( $payload, $pairs ) : $payload;
        return $type . ':' . strlen( $updated ) . ':"' . $updated . '";';
    }

    if ( 'a' === $type ) {
        wpcm_serialized_expect( $input, $offset, ':' );
        $count = wpcm_serialized_read_length( $input, $offset );
        wpcm_serialized_expect( $input, $offset, '{' );
        $output = 'a:' . $count . ':{';
        for ( $index = 0; $index < $count; $index++ ) {
            $output .= wpcm_replace_serialized_token( $input, $offset, $pairs, true );
            $output .= wpcm_replace_serialized_token( $input, $offset, $pairs, true );
        }
        wpcm_serialized_expect( $input, $offset, '}' );
        return $output . '}';
    }

    if ( 'O' === $type ) {
        wpcm_serialized_expect( $input, $offset, ':' );
        $class_length = wpcm_serialized_read_length( $input, $offset );
        wpcm_serialized_expect( $input, $offset, '"' );
        if ( $class_length > $length - $offset ) {
            throw new RuntimeException( 'A serialized object class name is incomplete.' );
        }
        $class_name = substr( $input, $offset, $class_length );
        $offset += $class_length;
        wpcm_serialized_expect( $input, $offset, '"' );
        wpcm_serialized_expect( $input, $offset, ':' );
        $property_count = wpcm_serialized_read_length( $input, $offset );
        wpcm_serialized_expect( $input, $offset, '{' );
        $output = 'O:' . $class_length . ':"' . $class_name . '":' . $property_count . ':{';
        for ( $index = 0; $index < $property_count; $index++ ) {
            $output .= wpcm_replace_serialized_token( $input, $offset, $pairs, false );
            $output .= wpcm_replace_serialized_token( $input, $offset, $pairs, true );
        }
        wpcm_serialized_expect( $input, $offset, '}' );
        return $output . '}';
    }

    if ( 'C' === $type ) {
        wpcm_serialized_expect( $input, $offset, ':' );
        $class_length = wpcm_serialized_read_length( $input, $offset );
        wpcm_serialized_expect( $input, $offset, '"' );
        if ( $class_length > $length - $offset ) {
            throw new RuntimeException( 'A custom-serialized object class name is incomplete.' );
        }
        $class_name = substr( $input, $offset, $class_length );
        $offset += $class_length;
        wpcm_serialized_expect( $input, $offset, '"' );
        wpcm_serialized_expect( $input, $offset, ':' );
        $payload_length = wpcm_serialized_read_length( $input, $offset );
        wpcm_serialized_expect( $input, $offset, '{' );
        if ( $payload_length > $length - $offset ) {
            throw new RuntimeException( 'A custom-serialized object payload is incomplete.' );
        }
        $payload = substr( $input, $offset, $payload_length );
        $offset += $payload_length;
        wpcm_serialized_expect( $input, $offset, '}' );

        $updated = $payload;
        if ( $replace_strings && wpcm_string_needs_replacement( $payload, $pairs ) ) {
            $nested_offset = 0;
            try {
                $nested = wpcm_replace_serialized_token( $payload, $nested_offset, $pairs, true );
                $updated = $nested_offset === strlen( $payload ) ? $nested : wpcm_replace_string( $payload, $pairs );
            } catch ( Throwable $ignored ) {
                $updated = wpcm_replace_string( $payload, $pairs );
            }
        }
        return 'C:' . $class_length . ':"' . $class_name . '":' . strlen( $updated ) . ':{' . $updated . '}';
    }

    throw new RuntimeException( 'Unsupported serialized token encountered during URL replacement.' );
}

/**
 * Read a non-negative serialized length followed by a colon.
 *
 * @param string $input  Serialized input.
 * @param int    $offset Current byte offset, updated by reference.
 * @return int Parsed length.
 */
function wpcm_serialized_read_length( $input, &$offset ) {
    $end = strpos( $input, ':', $offset );
    if ( false === $end ) {
        throw new RuntimeException( 'A serialized length field is incomplete.' );
    }
    $digits = substr( $input, $offset, $end - $offset );
    if ( '' === $digits || ! ctype_digit( $digits ) ) {
        throw new RuntimeException( 'A serialized length field is invalid.' );
    }
    $offset = $end + 1;
    return (int) $digits;
}

/**
 * Consume an exact byte from serialized input.
 *
 * @param string $input    Serialized input.
 * @param int    $offset   Current byte offset, updated by reference.
 * @param string $expected Expected byte.
 * @return void
 */
function wpcm_serialized_expect( $input, &$offset, $expected ) {
    if ( ! isset( $input[ $offset ] ) || $input[ $offset ] !== $expected ) {
        throw new RuntimeException( 'A serialized database value has invalid syntax.' );
    }
    $offset++;
}

function wpcm_replace_recursive( $value, $pairs ) {
    if ( is_string( $value ) ) return wpcm_replace_string( $value, $pairs );
    if ( is_array( $value ) ) {
        $out = array();
        foreach ( $value as $key => $item ) {
            $new_key = is_string( $key ) ? wpcm_replace_string( $key, $pairs ) : $key;
            if ( array_key_exists( $new_key, $out ) && $new_key !== $key ) {
                throw new RuntimeException( 'URL replacement would create a duplicate serialized array key.' );
            }
            $out[ $new_key ] = wpcm_replace_recursive( $item, $pairs );
        }
        return $out;
    }
    if ( is_object( $value ) || is_resource( $value ) ) {
        throw new RuntimeException( 'Unsupported value type encountered during recursive URL replacement.' );
    }
    return $value;
}

function wpcm_replace_string( $value, $pairs ) {
    $original = (string) $value;
    $value    = $original;
    foreach ( $pairs as $pair ) {
        $from = (string) $pair[0];
        if ( '' !== $from ) {
            $value = str_replace( $from, (string) $pair[1], $value );
        }
    }
    if ( wpcm_contains_percent_placeholder( $value ) && ! wpcm_contains_percent_placeholder( $original ) ) {
        throw new RuntimeException( 'URL replacement introduced an unresolved WordPress percent placeholder.' );
    }
    return $value;
}

function wpcm_query_or_throw( $mysqli, $sql, $context ) {
    $result = $mysqli->query( $sql );
    if ( false === $result ) {
        throw new RuntimeException( $context . ': ' . $mysqli->error );
    }
    return $result;
}

function wpcm_prepare_staged_wordpress_options( $mysqli, $cfg ) {
    $options  = $cfg['staging_prefix'] . 'options';
    $usermeta = $cfg['staging_prefix'] . 'usermeta';
    $tables   = wpcm_tables_with_prefix( $mysqli, $cfg['staging_prefix'] );
    if ( ! in_array( $options, $tables, true ) ) {
        throw new RuntimeException( 'The staged WordPress options table is missing.' );
    }

    $new_url = $mysqli->real_escape_string( rtrim( $cfg['new_url'], '/' ) );
    wpcm_query_or_throw(
        $mysqli,
        'UPDATE ' . wpcm_quote_identifier( $options ) . " SET option_value='{$new_url}' WHERE option_name IN ('siteurl','home')",
        'Unable to update staged WordPress URLs'
    );

    $old_role = $mysqli->real_escape_string( $cfg['old_prefix'] . 'user_roles' );
    $new_role = $mysqli->real_escape_string( $cfg['target_prefix'] . 'user_roles' );
    wpcm_query_or_throw(
        $mysqli,
        'UPDATE ' . wpcm_quote_identifier( $options ) . " SET option_name='{$new_role}' WHERE option_name='{$old_role}'",
        'Unable to update the staged user-role option name'
    );

    if ( in_array( $usermeta, $tables, true ) ) {
        $old = $mysqli->real_escape_string( $cfg['old_prefix'] );
        $new = $mysqli->real_escape_string( $cfg['target_prefix'] );
        $len = strlen( $cfg['old_prefix'] ) + 1;
        wpcm_query_or_throw(
            $mysqli,
            'UPDATE ' . wpcm_quote_identifier( $usermeta ) . " SET meta_key=CONCAT('{$new}',SUBSTRING(meta_key,{$len})) WHERE LEFT(meta_key," . strlen( $cfg['old_prefix'] ) . ")='{$old}'",
            'Unable to update staged user metadata prefixes'
        );
    }

    // Keep Clone Master active and first during cutover so recovery can run before
    // other normal plugins if the process is interrupted after promotion.
    $active_result = wpcm_query_or_throw(
        $mysqli,
        'SELECT option_value FROM ' . wpcm_quote_identifier( $options ) . " WHERE option_name='active_plugins' LIMIT 1",
        'Unable to read the staged active_plugins option'
    );
    $active = array();
    if ( $active_result->num_rows ) {
        $active = @unserialize( (string) $active_result->fetch_row()[0], array( 'allowed_classes' => false ) );
        if ( ! is_array( $active ) ) {
            $active = array();
        }
    }
    $active_result->free();

    $active = array_values(
        array_filter(
            $active,
            function ( $plugin ) use ( $cfg ) {
                return is_string( $plugin ) && $plugin !== $cfg['plugin_slug'];
            }
        )
    );
    array_unshift( $active, $cfg['plugin_slug'] );
    $serialized = $mysqli->real_escape_string( serialize( $active ) );
    if ( $active ) {
        wpcm_query_or_throw(
            $mysqli,
            'INSERT INTO ' . wpcm_quote_identifier( $options ) . " (option_name,option_value,autoload) VALUES ('active_plugins','{$serialized}','yes') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",
            'Unable to persist the staged active_plugins option'
        );
    }
}


// -----------------------------------------------------------------------------
// Transactional promotion, health check, and rollback.
// -----------------------------------------------------------------------------

function wpcm_step_finalize( $cfg, &$state, $token ) {
    if ( 'completed' === ( $state['phase'] ?? '' ) ) {
        return array( 'next_step' => null, 'progress' => 100, 'message' => 'Migration already completed successfully.', 'deactivated_plugins' => array() );
    }
    if ( ! in_array( $state['phase'], array( 'urls_replaced', 'promoting_files', 'files_promoted', 'committing_database', 'database_committed', 'health_check' ), true ) ) {
        throw new RuntimeException( 'Final promotion was requested in an invalid restore phase.' );
    }

    wpcm_enable_maintenance( $cfg['abspath'] );

    if ( in_array( $state['phase'], array( 'urls_replaced', 'promoting_files' ), true ) ) {
        wpcm_promote_files( $cfg, $state, $token );
    }
    if ( 'files_promoted' === $state['phase'] || 'committing_database' === $state['phase'] ) {
        wpcm_commit_database( $cfg, $state, $token );
    }

    $state['phase'] = 'health_check';
    wpcm_save_state( $cfg, $state, $token );
    wpcm_apply_destination_options( $cfg );

    // Temporarily leave maintenance mode only for the health probe. Any failure
    // immediately re-enables maintenance before rollback.
    wpcm_disable_maintenance( $cfg['abspath'] );
    $health = wpcm_health_check( $cfg );
    $state['health'] = $health;
    if ( empty( $health['ok'] ) ) {
        wpcm_enable_maintenance( $cfg['abspath'] );
        $state['phase'] = 'rollback_required';
        wpcm_save_state( $cfg, $state, $token );
        wpcm_rollback( $cfg, $state, $token );
        throw new RuntimeException( 'Post-promotion health check failed: ' . implode( '; ', $health['errors'] ) );
    }

    // The durable completion marker is written before optional cleanup. Once
    // health validation has passed, a cleanup error must never trigger rollback
    // of an otherwise healthy destination.
    $state['phase'] = 'completed';
    wpcm_save_state( $cfg, $state, $token );
    wpcm_disable_maintenance( $cfg['abspath'] );

    $warnings = $health['warnings'] ?? array();
    try {
        wpcm_remove_recovery_bootstrap( $cfg, $state );
    } catch ( Throwable $cleanup_error ) {
        $warnings[] = 'Unable to remove the temporary recovery bootstrap: ' . $cleanup_error->getMessage();
    }
    $warnings = array_merge( $warnings, wpcm_cleanup_rollback_data( $cfg, $state ) );
    $message  = 'Migration completed successfully after transactional promotion, health validation, and durable completion.';
    if ( ! empty( $warnings ) ) {
        $message .= ' Warning: ' . implode( '; ', $warnings );
    }

    // Make the static installer inert. Completion journals remain available for
    // a short cleanup grace period handled by the WordPress plugin.
    @unlink( $cfg['session_dir'] . 'installer-config.php' );
    wpcm_recursive_delete( $cfg['staging_dir'] );

    return array(
        'next_step'           => null,
        'progress'            => 100,
        'message'             => $message,
        'deactivated_plugins' => array(),
    );
}

function wpcm_promote_files( $cfg, &$state, $token ) {
    $state['phase'] = 'promoting_files';
    if ( ! is_dir( $cfg['rollback_dir'] ) && ! @mkdir( $cfg['rollback_dir'], 0750, true ) ) {
        throw new RuntimeException( 'Unable to create the rollback directory.' );
    }
    wpcm_protect_runtime_directory( $cfg['rollback_dir'] );

    if ( empty( $state['files']['promotion_plan'] ) ) {
        $plan = wpcm_file_promotion_plan( $cfg );
        $records = array();
        foreach ( $plan as $index => $item ) {
            $backup = rtrim( $cfg['rollback_dir'], '/\\' ) . '/files/' . sprintf( '%04d', $index ) . '-' . basename( $item['target'] );
            $source = $item['source'];

            if ( 'replace' === $item['mode'] ) {
                if ( ! file_exists( $source ) && ! is_link( $source ) ) {
                    throw new RuntimeException( 'Staged promotion source is missing: ' . $source );
                }
                if ( ! wpcm_paths_share_device( $source, dirname( $item['target'] ) ) ) {
                    if ( is_file( $source ) ) {
                        $near_target = dirname( $item['target'] ) . '/.wpcm-stage-' . $cfg['restore_id'] . '-' . basename( $item['target'] );
                        $data = @file_get_contents( $source );
                        if ( false === $data ) {
                            throw new RuntimeException( 'Unable to prepare a cross-filesystem staged file.' );
                        }
                        wpcm_atomic_write( $near_target, $data, 0640 );
                        $source = $near_target;
                    } else {
                        throw new RuntimeException( 'A staged directory and its destination are on different filesystems; atomic promotion is unavailable.' );
                    }
                }
            }
            if ( ! wpcm_paths_share_device( dirname( $item['target'] ), dirname( $backup ) ) ) {
                throw new RuntimeException( 'Rollback storage and destination are on different filesystems.' );
            }

            $records[] = array(
                'mode'       => $item['mode'],
                'source'     => $source,
                'target'     => $item['target'],
                'backup'     => $backup,
                'had_target' => file_exists( $item['target'] ) || is_link( $item['target'] ),
                'status'     => 'planned',
            );
        }
        $state['files']['promotion_plan'] = $records;
        $state['files']['promoted']       = $records;
        wpcm_save_state( $cfg, $state, $token );
    }

    wpcm_install_recovery_bootstrap( $cfg, $state, $token );

    foreach ( $state['files']['promoted'] as $index => &$record ) {
        if ( 'promoted' === ( $record['status'] ?? '' ) ) {
            continue;
        }
        if ( ! is_dir( dirname( $record['backup'] ) ) && ! @mkdir( dirname( $record['backup'] ), 0750, true ) ) {
            throw new RuntimeException( 'Unable to create a rollback path.' );
        }

        // Recover the exact state if the process died between a rename and the
        // subsequent journal write.
        if ( 'planned' === $record['status'] && ! empty( $record['had_target'] ) && ( file_exists( $record['backup'] ) || is_link( $record['backup'] ) ) && ! file_exists( $record['target'] ) && ! is_link( $record['target'] ) ) {
            $record['status'] = 'backed_up';
            wpcm_save_state( $cfg, $state, $token );
        }
        if ( 'backed_up' === $record['status'] && 'replace' === $record['mode'] && ( file_exists( $record['target'] ) || is_link( $record['target'] ) ) && ! file_exists( $record['source'] ) && ! is_link( $record['source'] ) ) {
            $record['status'] = 'promoted';
            wpcm_save_state( $cfg, $state, $token );
            continue;
        }

        if ( 'planned' === $record['status'] ) {
            if ( ! empty( $record['had_target'] ) ) {
                if ( file_exists( $record['backup'] ) || is_link( $record['backup'] ) ) {
                    throw new RuntimeException( 'Rollback target already exists unexpectedly: ' . $record['backup'] );
                }
                if ( ! @rename( $record['target'], $record['backup'] ) ) {
                    throw new RuntimeException( 'Unable to move the existing path into rollback storage: ' . $record['target'] );
                }
            }
            $record['status'] = 'backed_up';
            wpcm_save_state( $cfg, $state, $token );
        }

        if ( 'backed_up' === $record['status'] ) {
            if ( 'replace' === $record['mode'] ) {
                if ( ! is_dir( dirname( $record['target'] ) ) && ! @mkdir( dirname( $record['target'] ), 0755, true ) ) {
                    throw new RuntimeException( 'Unable to create destination directory: ' . dirname( $record['target'] ) );
                }
                if ( file_exists( $record['target'] ) || is_link( $record['target'] ) ) {
                    throw new RuntimeException( 'Destination path appeared during promotion: ' . $record['target'] );
                }
                if ( ! @rename( $record['source'], $record['target'] ) ) {
                    throw new RuntimeException( 'Unable to atomically promote staged path: ' . $record['target'] );
                }
            }
            $record['status'] = 'promoted';
            wpcm_save_state( $cfg, $state, $token );
        }
    }
    unset( $record );

    $state['phase'] = 'files_promoted';
    wpcm_save_state( $cfg, $state, $token );
}

function wpcm_file_promotion_plan( $cfg ) {
    $content  = rtrim( $cfg['staging_dir'], '/\\' ) . '/content/';
    $root     = rtrim( $cfg['staging_dir'], '/\\' ) . '/root/';
    $presence = is_array( $cfg['content_presence'] ?? null ) ? $cfg['content_presence'] : array();
    $plan     = array();

    $whole_directories = array(
        'themes'    => $cfg['theme_root'],
        'uploads'   => $cfg['uploads_dir'],
        'languages' => rtrim( $cfg['wp_content_dir'], '/\\' ) . '/languages',
    );
    foreach ( $whole_directories as $name => $target ) {
        if ( ! empty( $presence[ $name ] ) ) {
            $source = $content . $name;
            if ( ! is_dir( $source ) ) {
                throw new RuntimeException( 'Expected staged directory is missing: ' . $name );
            }
            $plan[] = array( 'mode' => 'replace', 'source' => $source, 'target' => $target );
        } elseif ( file_exists( $target ) || is_link( $target ) ) {
            $plan[] = array( 'mode' => 'remove', 'source' => '', 'target' => $target );
        }
    }

    // Plugins are promoted individually so the currently executing Clone Master
    // directory remains available until the restore completes.
    $plugin_source = $content . 'plugins/';
    $plugin_target = rtrim( $cfg['plugin_dir'], '/\\' ) . '/';
    $source_names  = array();
    if ( is_dir( $plugin_source ) ) {
        foreach ( scandir( $plugin_source ) ?: array() as $name ) {
            if ( '.' === $name || '..' === $name || $name === $cfg['plugin_folder'] ) {
                continue;
            }
            $source_names[ strtolower( $name ) ] = true;
            $plan[] = array(
                'mode'   => 'replace',
                'source' => $plugin_source . $name,
                'target' => $plugin_target . $name,
            );
        }
    }
    if ( is_dir( $plugin_target ) ) {
        foreach ( scandir( $plugin_target ) ?: array() as $name ) {
            if ( '.' === $name || '..' === $name || $name === $cfg['plugin_folder'] ) {
                continue;
            }
            if ( ! isset( $source_names[ strtolower( $name ) ] ) ) {
                $plan[] = array( 'mode' => 'remove', 'source' => '', 'target' => $plugin_target . $name );
            }
        }
    }

    // MU plugins are also promoted individually so the temporary recovery
    // bootstrap can remain loaded throughout the destructive transition.
    $mu_source = $content . 'mu-plugins/';
    $mu_target = rtrim( $cfg['wp_content_dir'], '/\\' ) . '/mu-plugins/';
    $bootstrap_name = '000-wpcm-recovery-bootstrap.php';
    $source_names = array();
    if ( is_dir( $mu_source ) ) {
        foreach ( scandir( $mu_source ) ?: array() as $name ) {
            if ( '.' === $name || '..' === $name || $bootstrap_name === $name ) {
                continue;
            }
            $source_names[ strtolower( $name ) ] = true;
            $plan[] = array( 'mode' => 'replace', 'source' => $mu_source . $name, 'target' => $mu_target . $name );
        }
    }
    if ( is_dir( $mu_target ) ) {
        foreach ( scandir( $mu_target ) ?: array() as $name ) {
            if ( '.' === $name || '..' === $name || $bootstrap_name === $name ) {
                continue;
            }
            if ( ! isset( $source_names[ strtolower( $name ) ] ) ) {
                $plan[] = array( 'mode' => 'remove', 'source' => '', 'target' => $mu_target . $name );
            }
        }
    }

    // Only portable drop-ins are restored automatically. Database and cache
    // drop-ins remain destination-specific by design.
    $dropins = $content . 'dropins/';
    foreach ( array( 'fatal-error-handler.php', 'maintenance.php' ) as $name ) {
        $source = $dropins . $name;
        $target = rtrim( $cfg['wp_content_dir'], '/\\' ) . '/' . $name;
        if ( is_file( $source ) ) {
            $plan[] = array( 'mode' => 'replace', 'source' => $source, 'target' => $target );
        } elseif ( file_exists( $target ) || is_link( $target ) ) {
            $plan[] = array( 'mode' => 'remove', 'source' => '', 'target' => $target );
        }
    }

    $robots_target = rtrim( $cfg['abspath'], '/\\' ) . '/robots.txt';
    if ( in_array( 'robots.txt', $cfg['root_files'] ?? array(), true ) && is_file( $root . 'robots.txt' ) ) {
        $plan[] = array( 'mode' => 'replace', 'source' => $root . 'robots.txt', 'target' => $robots_target );
    } elseif ( file_exists( $robots_target ) || is_link( $robots_target ) ) {
        $plan[] = array( 'mode' => 'remove', 'source' => '', 'target' => $robots_target );
    }

    $targets = array();
    foreach ( $plan as $item ) {
        $key = strtolower( str_replace( '\\', '/', $item['target'] ) );
        if ( isset( $targets[ $key ] ) ) {
            throw new RuntimeException( 'Duplicate file promotion target: ' . $item['target'] );
        }
        $targets[ $key ] = true;
    }
    return $plan;
}

function wpcm_existing_ancestor( $path ) {
    $path = rtrim( $path, '/\\' );
    while ( '' !== $path && ! file_exists( $path ) ) {
        $parent = dirname( $path );
        if ( $parent === $path ) {
            break;
        }
        $path = $parent;
    }
    return $path;
}

function wpcm_paths_share_device( $first, $second ) {
    $first  = wpcm_existing_ancestor( $first );
    $second = wpcm_existing_ancestor( $second );
    $a = $first ? @stat( $first ) : false;
    $b = $second ? @stat( $second ) : false;
    return is_array( $a ) && is_array( $b ) && isset( $a['dev'], $b['dev'] ) && (string) $a['dev'] === (string) $b['dev'];
}

function wpcm_files_identical( $first, $second ) {
    if ( ! is_file( $first ) || ! is_file( $second ) ) {
        return false;
    }
    $first_size  = filesize( $first );
    $second_size = filesize( $second );
    if ( false === $first_size || false === $second_size || $first_size !== $second_size ) {
        return false;
    }
    $first_hash  = hash_file( 'sha256', $first );
    $second_hash = hash_file( 'sha256', $second );
    return is_string( $first_hash ) && is_string( $second_hash ) && hash_equals( $first_hash, $second_hash );
}

function wpcm_install_recovery_bootstrap( $cfg, &$state, $token ) {
    $source = (string) ( $cfg['recovery_bootstrap'] ?? '' );
    if ( ! is_file( $source ) || ! is_readable( $source ) ) {
        throw new RuntimeException( 'The static recovery bootstrap is unavailable.' );
    }

    $mu_dir = rtrim( $cfg['wp_content_dir'], '/\\' ) . '/mu-plugins';
    if ( ! is_dir( $mu_dir ) && ! @mkdir( $mu_dir, 0755, true ) ) {
        throw new RuntimeException( 'Unable to create the MU-plugin directory for automatic recovery.' );
    }
    $target = $mu_dir . '/000-wpcm-recovery-bootstrap.php';
    $backup = rtrim( $cfg['rollback_dir'], '/\\' ) . '/bootstrap/000-wpcm-recovery-bootstrap.php';

    $record = $state['files']['bootstrap'] ?? array();
    if ( empty( $record ) ) {
        $record = array(
            'source'     => $source,
            'target'     => $target,
            'backup'     => $backup,
            'had_target' => file_exists( $target ) || is_link( $target ),
            'status'     => 'planned',
        );
        $state['files']['bootstrap'] = $record;
        wpcm_save_state( $cfg, $state, $token );
    } else {
        foreach ( array( 'source' => $source, 'target' => $target, 'backup' => $backup ) as $key => $expected ) {
            if ( (string) ( $record[ $key ] ?? '' ) !== $expected ) {
                throw new RuntimeException( 'Recovery-bootstrap journal does not match the current restore session.' );
            }
        }
    }

    $status        = (string) ( $record['status'] ?? 'planned' );
    $target_exists = file_exists( $target ) || is_link( $target );
    $backup_exists = file_exists( $backup ) || is_link( $backup );

    if ( 'installed' === $status ) {
        if ( ! wpcm_files_identical( $source, $target ) ) {
            throw new RuntimeException( 'The installed recovery bootstrap no longer matches its source.' );
        }
        return;
    }

    // Recover the window after preserving a previous bootstrap but before saving
    // the corresponding journal transition.
    if ( 'planned' === $status && ! empty( $record['had_target'] ) && ! $target_exists && $backup_exists ) {
        $state['files']['bootstrap']['status'] = 'backed_up';
        wpcm_save_state( $cfg, $state, $token );
        $status = 'backed_up';
    }

    if ( 'planned' === $status && ! empty( $record['had_target'] ) ) {
        if ( ! $target_exists ) {
            throw new RuntimeException( 'The previous recovery bootstrap disappeared before it could be preserved.' );
        }
        if ( $backup_exists ) {
            throw new RuntimeException( 'Recovery-bootstrap rollback storage already exists unexpectedly.' );
        }
        if ( ! is_dir( dirname( $backup ) ) && ! @mkdir( dirname( $backup ), 0750, true ) ) {
            throw new RuntimeException( 'Unable to create recovery-bootstrap rollback storage.' );
        }
        if ( ! @rename( $target, $backup ) ) {
            throw new RuntimeException( 'Unable to preserve an existing recovery bootstrap.' );
        }
        $state['files']['bootstrap']['status'] = 'backed_up';
        wpcm_save_state( $cfg, $state, $token );
        $status = 'backed_up';
        $target_exists = false;
    }

    // Recover the window after publishing the static bootstrap but before saving
    // the final journal status.
    if ( in_array( $status, array( 'planned', 'backed_up' ), true ) && $target_exists && wpcm_files_identical( $source, $target ) ) {
        $state['files']['bootstrap']['status'] = 'installed';
        wpcm_save_state( $cfg, $state, $token );
        return;
    }
    if ( $target_exists ) {
        throw new RuntimeException( 'Recovery-bootstrap target is occupied by an unexpected file.' );
    }

    $data = @file_get_contents( $source );
    if ( false === $data ) {
        throw new RuntimeException( 'Unable to read the recovery bootstrap.' );
    }
    wpcm_atomic_write( $target, $data, 0644 );
    if ( ! wpcm_files_identical( $source, $target ) ) {
        throw new RuntimeException( 'Recovery bootstrap failed post-publication verification.' );
    }
    $state['files']['bootstrap']['status'] = 'installed';
    wpcm_save_state( $cfg, $state, $token );
}


function wpcm_remove_recovery_bootstrap( $cfg, &$state ) {
    $record = $state['files']['bootstrap'] ?? array();
    if ( empty( $record['target'] ) ) {
        return;
    }
    if ( file_exists( $record['target'] ) || is_link( $record['target'] ) ) {
        wpcm_recursive_delete( $record['target'] );
    }
    if ( ! empty( $record['had_target'] ) && ( file_exists( $record['backup'] ) || is_link( $record['backup'] ) ) ) {
        if ( ! @rename( $record['backup'], $record['target'] ) ) {
            throw new RuntimeException( 'Unable to restore the previous recovery bootstrap.' );
        }
    }
    $state['files']['bootstrap']['status'] = 'removed';
}


function wpcm_commit_database( $cfg, &$state, $token ) {
    $mysqli = wpcm_db_connect( $cfg );
    if ( 'committing_database' !== $state['phase'] ) {
        $stage_tables = wpcm_tables_with_prefix( $mysqli, $cfg['staging_prefix'] );
        $current      = wpcm_tables_with_prefix( $mysqli, $cfg['target_prefix'] );
        if ( empty( $stage_tables ) ) {
            $mysqli->close();
            throw new RuntimeException( 'No staged tables are available for promotion.' );
        }

        $mapping = array();
        foreach ( $stage_tables as $stage ) {
            $target = $cfg['target_prefix'] . substr( $stage, strlen( $cfg['staging_prefix'] ) );
            $mapping[] = array( 'stage' => $stage, 'target' => $target );
        }
        $rollback = array();
        foreach ( $current as $index => $table ) {
            if ( 0 === strpos( $table, $cfg['staging_prefix'] ) || 0 === strpos( $table, $cfg['rollback_prefix'] ) ) {
                continue;
            }
            $rollback_name = $cfg['rollback_prefix'] . sprintf( '%04d', $index );
            if ( wpcm_table_exists( $mysqli, $rollback_name ) ) {
                $mysqli->close();
                throw new RuntimeException( 'A rollback table name already exists: ' . $rollback_name );
            }
            $rollback[] = array( 'target' => $table, 'rollback' => $rollback_name );
        }

        $state['database'] = array( 'mapping' => $mapping, 'rollback' => $rollback );
        $state['phase'] = 'committing_database';
        wpcm_save_state( $cfg, $state, $token );
    }

    $status = wpcm_database_transition_status( $mysqli, $state );
    if ( 'committed' === $status ) {
        $state['database_committed'] = true;
        $state['phase'] = 'database_committed';
        wpcm_save_state( $cfg, $state, $token );
        $mysqli->close();
        return;
    }
    if ( 'pending' !== $status ) {
        $mysqli->close();
        throw new RuntimeException( 'Database promotion is in an unexpected partial or conflicting state.' );
    }

    $clauses = array();
    foreach ( $state['database']['rollback'] as $item ) {
        $clauses[] = wpcm_quote_identifier( $item['target'] ) . ' TO ' . wpcm_quote_identifier( $item['rollback'] );
    }
    foreach ( $state['database']['mapping'] as $item ) {
        $clauses[] = wpcm_quote_identifier( $item['stage'] ) . ' TO ' . wpcm_quote_identifier( $item['target'] );
    }
    $statement = 'RENAME TABLE ' . implode( ', ', $clauses );
    $packet = $mysqli->query( 'SELECT @@max_allowed_packet' );
    if ( $packet && $packet->num_rows ) {
        $max_packet = (int) $packet->fetch_row()[0];
        $packet->free();
        if ( $max_packet > 0 && strlen( $statement ) > (int) ( $max_packet * 0.8 ) ) {
            $mysqli->close();
            throw new RuntimeException( 'Atomic database promotion statement exceeds the safe MySQL packet limit.' );
        }
    }

    if ( empty( $clauses ) || ! $mysqli->query( $statement ) ) {
        $error = $mysqli->error;
        $mysqli->close();
        throw new RuntimeException( 'Atomic database promotion failed: ' . $error );
    }
    if ( 'committed' !== wpcm_database_transition_status( $mysqli, $state ) ) {
        $mysqli->close();
        throw new RuntimeException( 'Database promotion completed without reaching the expected atomic state.' );
    }

    $state['database_committed'] = true;
    $state['phase'] = 'database_committed';
    wpcm_save_state( $cfg, $state, $token );
    $mysqli->close();
}

function wpcm_table_exists( $mysqli, $table ) {
    $name = $mysqli->real_escape_string( $table );
    $result = $mysqli->query( "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$name}' AND TABLE_TYPE='BASE TABLE' LIMIT 1" );
    if ( ! $result ) {
        throw new RuntimeException( 'Unable to inspect database transition state: ' . $mysqli->error );
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function wpcm_database_transition_status( $mysqli, $state ) {
    $mapping  = $state['database']['mapping'] ?? array();
    $rollback = $state['database']['rollback'] ?? array();
    if ( empty( $mapping ) ) {
        return 'invalid';
    }

    $rollback_by_target = array();
    foreach ( $rollback as $item ) {
        $rollback_by_target[ $item['target'] ] = $item['rollback'];
    }

    $pending   = true;
    $committed = true;
    foreach ( $mapping as $item ) {
        $stage_exists  = wpcm_table_exists( $mysqli, $item['stage'] );
        $target_exists = wpcm_table_exists( $mysqli, $item['target'] );
        $pending       = $pending && $stage_exists;
        $committed     = $committed && ! $stage_exists && $target_exists;

        if ( isset( $rollback_by_target[ $item['target'] ] ) ) {
            $rollback_exists = wpcm_table_exists( $mysqli, $rollback_by_target[ $item['target'] ] );
            $pending         = $pending && $target_exists && ! $rollback_exists;
            $committed       = $committed && $rollback_exists;
        } else {
            $pending = $pending && ! $target_exists;
        }
    }

    $mapped_targets = array_fill_keys( array_column( $mapping, 'target' ), true );
    foreach ( $rollback as $item ) {
        if ( isset( $mapped_targets[ $item['target'] ] ) ) {
            continue;
        }
        $target_exists   = wpcm_table_exists( $mysqli, $item['target'] );
        $rollback_exists = wpcm_table_exists( $mysqli, $item['rollback'] );
        $pending         = $pending && $target_exists && ! $rollback_exists;
        $committed       = $committed && ! $target_exists && $rollback_exists;
    }

    if ( $committed ) {
        return 'committed';
    }
    if ( $pending ) {
        return 'pending';
    }
    return 'conflict';
}

function wpcm_apply_destination_options( $cfg ) {
    $mysqli = wpcm_db_connect( $cfg );
    $options = $cfg['target_prefix'] . 'options';
    $opts = $cfg['import_opts'] ?? array();

    if ( ! empty( $opts['reset_permalinks'] ) && ! $mysqli->query( 'DELETE FROM ' . wpcm_quote_identifier( $options ) . " WHERE option_name='rewrite_rules'" ) ) {
        $error = $mysqli->error;
        $mysqli->close();
        throw new RuntimeException( 'Unable to reset destination permalink rules: ' . $error );
    }
    if ( ! empty( $opts['block_indexing'] ) ) {
        if ( ! $mysqli->query( 'UPDATE ' . wpcm_quote_identifier( $options ) . " SET option_value='0' WHERE option_name='blog_public'" ) ) {
            $error = $mysqli->error;
            $mysqli->close();
            throw new RuntimeException( 'Unable to update destination indexing settings: ' . $error );
        }
        if ( ! $mysqli->query( 'INSERT INTO ' . wpcm_quote_identifier( $options ) . " (option_name,option_value,autoload) VALUES ('wpcm_noindex','1','yes') ON DUPLICATE KEY UPDATE option_value='1'" ) ) {
            $error = $mysqli->error;
            $mysqli->close();
            throw new RuntimeException( 'Unable to persist destination indexing settings: ' . $error );
        }
    }
    $mysqli->close();
}

function wpcm_health_check( $cfg ) {
    $errors   = array();
    $warnings = array();
    $http_status = null;

    try {
        $mysqli = wpcm_db_connect( $cfg );
        $required = array(
            $cfg['target_prefix'] . 'options',
            $cfg['target_prefix'] . 'posts',
            $cfg['target_prefix'] . 'users',
        );
        $tables = wpcm_tables_with_prefix( $mysqli, $cfg['target_prefix'] );
        foreach ( $required as $table ) {
            if ( ! in_array( $table, $tables, true ) ) {
                $errors[] = 'Required table missing: ' . $table;
            }
        }

        $options_table = $cfg['target_prefix'] . 'options';
        $option_names  = array( 'siteurl', 'home', 'active_plugins', 'stylesheet', 'template' );
        $quoted_names  = array();
        foreach ( $option_names as $name ) {
            $quoted_names[] = "'" . $mysqli->real_escape_string( $name ) . "'";
        }
        $result = $mysqli->query(
            'SELECT option_name, option_value FROM ' . wpcm_quote_identifier( $options_table ) .
            ' WHERE option_name IN (' . implode( ',', $quoted_names ) . ')'
        );
        if ( ! $result ) {
            throw new RuntimeException( 'Unable to read destination WordPress options: ' . $mysqli->error );
        }
        $options = array();
        while ( $row = $result->fetch_assoc() ) {
            $options[ $row['option_name'] ] = $row['option_value'];
        }
        $result->free();
        $mysqli->close();

        if ( rtrim( (string) ( $options['siteurl'] ?? '' ), '/' ) !== rtrim( $cfg['new_url'], '/' ) ) {
            $errors[] = 'siteurl does not match the destination URL.';
        }
        if ( rtrim( (string) ( $options['home'] ?? '' ), '/' ) !== rtrim( $cfg['new_url'], '/' ) ) {
            $errors[] = 'home does not match the destination URL.';
        }

        $active_plugins = @unserialize( (string) ( $options['active_plugins'] ?? '' ), array( 'allowed_classes' => false ) );
        if ( false === $active_plugins && 'b:0;' !== (string) ( $options['active_plugins'] ?? '' ) ) {
            $active_plugins = array();
            $warnings[] = 'The active_plugins option could not be decoded.';
        }
        if ( is_array( $active_plugins ) ) {
            foreach ( $active_plugins as $plugin ) {
                $plugin = ltrim( str_replace( '\\', '/', (string) $plugin ), '/' );
                if ( false !== strpos( $plugin, '../' ) || ! is_file( rtrim( $cfg['plugin_dir'], '/\\' ) . '/' . $plugin ) ) {
                    $errors[] = 'Active plugin file is missing: ' . $plugin;
                }
            }
        }

        foreach ( array( 'stylesheet', 'template' ) as $theme_option ) {
            $theme = basename( (string) ( $options[ $theme_option ] ?? '' ) );
            if ( '' !== $theme && ! is_dir( rtrim( $cfg['theme_root'], '/\\' ) . '/' . $theme ) ) {
                $errors[] = 'Active theme directory is missing: ' . $theme;
            }
        }
    } catch ( Throwable $e ) {
        $errors[] = $e->getMessage();
    }

    foreach ( array( 'wp-load.php', 'wp-config.php', 'wp-settings.php' ) as $file ) {
        if ( ! is_file( rtrim( $cfg['abspath'], '/\\' ) . '/' . $file ) ) {
            $errors[] = 'Required WordPress file missing: ' . $file;
        }
    }

    $health_url     = rtrim( $cfg['new_url'], '/' ) . '/wp-login.php?wpcm-health=' . rawurlencode( $cfg['restore_id'] );
    $health_origin  = wpcm_url_origin( $health_url );
    $allowed_origin = (string) ( $cfg['allowed_origin'] ?? '' );
    $same_origin    = '' !== $health_origin && '' !== $allowed_origin && hash_equals( strtolower( $allowed_origin ), strtolower( $health_origin ) );
    $loopback       = $same_origin ? wpcm_loopback_status( $health_url ) : null;

    if ( ! $same_origin ) {
        $warnings[] = 'Loopback HTTP validation was skipped because the destination origin differs from the authenticated installer origin.';
    } elseif ( null === $loopback ) {
        $warnings[] = 'Loopback HTTP validation is unavailable because cURL is not installed.';
    } elseif ( null === $loopback['status'] ) {
        $warnings[] = 'Loopback HTTP validation was unavailable: ' . $loopback['error'];
    } else {
        $http_status = (int) $loopback['status'];
        $fatal_patterns = array(
            'Fatal error',
            'Parse error',
            'Uncaught Error',
            'There has been a critical error',
            'Error establishing a database connection',
        );
        foreach ( $fatal_patterns as $pattern ) {
            if ( false !== stripos( $loopback['body'], $pattern ) ) {
                $errors[] = 'Loopback response contains a fatal WordPress error: ' . $pattern;
                break;
            }
        }
        if ( $http_status >= 500 ) {
            $errors[] = 'Loopback HTTP health check returned status ' . $http_status . '.';
        }
    }

    return array(
        'ok'          => empty( $errors ),
        'errors'      => $errors,
        'warnings'    => $warnings,
        'http_status' => $http_status,
    );
}

function wpcm_url_origin( $url ) {
    $parts = parse_url( $url );
    if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
        return '';
    }
    $scheme = strtolower( (string) $parts['scheme'] );
    if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
        return '';
    }
    $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
    if ( '' === $host ) {
        return '';
    }
    $origin = $scheme . '://' . $host;
    if ( isset( $parts['port'] ) ) {
        $origin .= ':' . (int) $parts['port'];
    }
    return $origin;
}

function wpcm_loopback_status( $url ) {
    if ( ! function_exists( 'curl_init' ) ) {
        return null;
    }

    $body      = '';
    $body_max  = 262144;
    $ch        = curl_init( $url );
    $protocols = defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' )
        ? CURLPROTO_HTTP | CURLPROTO_HTTPS
        : null;

    $options = array(
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_NOBODY         => false,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => array(
            'Cache-Control: no-cache, no-store',
            'Pragma: no-cache',
            'X-WPCM-Health: 1',
        ),
        CURLOPT_USERAGENT      => 'Clone Master Transaction Health Check',
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_WRITEFUNCTION  => static function ( $handle, $chunk ) use ( &$body, $body_max ) {
            $remaining = $body_max - strlen( $body );
            if ( $remaining > 0 ) {
                $body .= substr( $chunk, 0, $remaining );
            }

            // Returning the complete chunk length tells cURL that the response
            // was consumed even after the diagnostic buffer reaches its limit.
            return strlen( $chunk );
        },
    );
    if ( null !== $protocols ) {
        $options[ CURLOPT_PROTOCOLS ] = $protocols;
    }

    curl_setopt_array( $ch, $options );
    $result = curl_exec( $ch );
    $errno  = curl_errno( $ch );
    $error  = curl_error( $ch );
    $status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
    curl_close( $ch );

    if ( false === $result || 0 !== $errno || $status <= 0 ) {
        return array( 'status' => null, 'body' => $body, 'error' => $error ?: 'Loopback request failed.' );
    }
    return array(
        'status' => $status,
        'body'   => $body,
        'error'  => '',
    );
}

function wpcm_recover_interrupted_transition( $cfg, &$state, $token ) {
    if ( 'promoting_files' === ( $state['phase'] ?? '' ) && empty( $state['database_committed'] ) ) {
        wpcm_rollback_files( $cfg, $state, $token );
        $state['phase'] = 'urls_replaced';
        $state['files']['promotion_plan'] = array();
        $state['files']['promoted'] = array();
        wpcm_save_state( $cfg, $state, $token );
    }

    if ( 'committing_database' === ( $state['phase'] ?? '' ) ) {
        $mysqli = wpcm_db_connect( $cfg );
        $status = wpcm_database_transition_status( $mysqli, $state );
        $mysqli->close();

        if ( 'committed' === $status ) {
            $state['database_committed'] = true;
            $state['phase'] = 'database_committed';
            wpcm_save_state( $cfg, $state, $token );
        } elseif ( 'pending' !== $status ) {
            $state['phase'] = 'rollback_required';
            wpcm_save_state( $cfg, $state, $token );
            wpcm_rollback( $cfg, $state, $token );
            throw new RuntimeException( 'Interrupted database transition is conflicting and was rolled back.' );
        }
    }

    if ( 'rollback_required' === ( $state['phase'] ?? '' ) ) {
        wpcm_rollback( $cfg, $state, $token );
    }
}

function wpcm_rollback( $cfg, &$state, $token ) {
    wpcm_enable_maintenance( $cfg['abspath'] );
    if ( ! empty( $state['database_committed'] ) || in_array( $state['phase'] ?? '', array( 'committing_database', 'database_committed', 'health_check', 'rollback_required' ), true ) ) {
        wpcm_rollback_database( $cfg, $state );
    }
    wpcm_rollback_files( $cfg, $state, $token );
    $state['database_committed'] = false;
    $state['phase'] = 'rolled_back';
    wpcm_save_state( $cfg, $state, $token );
    wpcm_disable_maintenance( $cfg['abspath'] );
}

function wpcm_rollback_database( $cfg, $state ) {
    if ( empty( $state['database']['mapping'] ) ) {
        return;
    }
    $mysqli = wpcm_db_connect( $cfg );
    $status = wpcm_database_transition_status( $mysqli, $state );
    if ( 'pending' === $status ) {
        $mysqli->close();
        return;
    }
    if ( 'committed' !== $status ) {
        $mysqli->close();
        throw new RuntimeException( 'Database rollback refused because the transition state is conflicting.' );
    }

    $clauses = array();
    $failed  = array();
    foreach ( $state['database']['mapping'] as $index => $item ) {
        $failed_name = 'wpcmfail_' . $cfg['restore_id'] . '_' . sprintf( '%04d', $index );
        if ( wpcm_table_exists( $mysqli, $failed_name ) ) {
            $mysqli->query( 'DROP TABLE IF EXISTS ' . wpcm_quote_identifier( $failed_name ) );
        }
        $clauses[] = wpcm_quote_identifier( $item['target'] ) . ' TO ' . wpcm_quote_identifier( $failed_name );
        $failed[]  = $failed_name;
    }
    foreach ( $state['database']['rollback'] ?? array() as $item ) {
        $clauses[] = wpcm_quote_identifier( $item['rollback'] ) . ' TO ' . wpcm_quote_identifier( $item['target'] );
    }
    if ( ! empty( $clauses ) && ! $mysqli->query( 'RENAME TABLE ' . implode( ', ', $clauses ) ) ) {
        $error = $mysqli->error;
        $mysqli->close();
        throw new RuntimeException( 'Database rollback failed: ' . $error );
    }
    foreach ( $failed as $table ) {
        if ( ! $mysqli->query( 'DROP TABLE IF EXISTS ' . wpcm_quote_identifier( $table ) ) ) {
            $error = $mysqli->error;
            $mysqli->close();
            throw new RuntimeException( 'Unable to remove failed promoted table: ' . $error );
        }
    }
    $mysqli->close();
}

function wpcm_rollback_files( $cfg, &$state, $token = '' ) {
    $records = array_reverse( $state['files']['promoted'] ?? array(), true );
    foreach ( $records as $index => $record ) {
        $status = (string) ( $record['status'] ?? 'planned' );
        $target_exists = file_exists( $record['target'] ) || is_link( $record['target'] );
        $source_exists = ! empty( $record['source'] ) && ( file_exists( $record['source'] ) || is_link( $record['source'] ) );
        $backup_exists = file_exists( $record['backup'] ) || is_link( $record['backup'] );

        // A process may die after promotion but before recording it.
        $was_promoted = 'promoted' === $status || ( 'replace' === $record['mode'] && $target_exists && ! $source_exists ) || ( 'remove' === $record['mode'] && ! $target_exists && ( $backup_exists || empty( $record['had_target'] ) ) );
        if ( $was_promoted && $target_exists ) {
            wpcm_recursive_delete( $record['target'] );
        }

        if ( ! empty( $record['had_target'] ) && $backup_exists ) {
            if ( file_exists( $record['target'] ) || is_link( $record['target'] ) ) {
                wpcm_recursive_delete( $record['target'] );
            }
            if ( ! is_dir( dirname( $record['target'] ) ) && ! @mkdir( dirname( $record['target'] ), 0755, true ) ) {
                throw new RuntimeException( 'Unable to recreate a rollback destination directory.' );
            }
            if ( ! @rename( $record['backup'], $record['target'] ) ) {
                throw new RuntimeException( 'Unable to restore rollback path: ' . $record['target'] );
            }
        }

        $state['files']['promoted'][ $index ]['status'] = 'rolled_back';
        if ( $token ) {
            wpcm_save_state( $cfg, $state, $token );
        }
    }
    wpcm_remove_recovery_bootstrap( $cfg, $state );
}

function wpcm_cleanup_rollback_data( $cfg, $state ) {
    $warnings = array();
    try {
        $mysqli = wpcm_db_connect( $cfg );
        foreach ( $state['database']['rollback'] ?? array() as $item ) {
            if ( ! $mysqli->query( 'DROP TABLE IF EXISTS ' . wpcm_quote_identifier( $item['rollback'] ) ) ) {
                $warnings[] = 'Unable to remove rollback table ' . $item['rollback'] . ': ' . $mysqli->error;
            }
        }
        $mysqli->close();
    } catch ( Throwable $e ) {
        $warnings[] = 'Unable to clean database rollback data: ' . $e->getMessage();
    }

    try {
        wpcm_recursive_delete( $cfg['rollback_dir'] );
    } catch ( Throwable $e ) {
        $warnings[] = 'Unable to clean filesystem rollback data: ' . $e->getMessage();
    }
    return $warnings;
}

function wpcm_enable_maintenance( $abspath ) {
    $path = rtrim( $abspath, '/\\' ) . '/.maintenance';
    wpcm_atomic_write( $path, '<?php $upgrading = ' . ( time() + 3600 ) . '; ?>', 0644 );
}

function wpcm_disable_maintenance( $abspath ) {
    @unlink( rtrim( $abspath, '/\\' ) . '/.maintenance' );
}

function wpcm_protect_runtime_directory( $dir ) {
    if ( ! is_dir( $dir ) ) @mkdir( $dir, 0750, true );
    wpcm_atomic_write( rtrim( $dir, '/\\' ) . '/index.php', "<?php\nhttp_response_code(404);\nexit;\n", 0644 );
    wpcm_atomic_write( rtrim( $dir, '/\\' ) . '/.htaccess', "Require all denied\nDeny from all\n", 0640 );
}

// -----------------------------------------------------------------------------
// Generic filesystem helpers.
// -----------------------------------------------------------------------------





function wpcm_recursive_delete( $path ) {
    if ( is_link( $path ) || is_file( $path ) ) {
        @unlink( $path );
        return;
    }
    if ( ! is_dir( $path ) ) return;
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $iterator as $item ) {
        if ( $item->isLink() || $item->isFile() ) @unlink( $item->getPathname() );
        else @rmdir( $item->getPathname() );
    }
    @rmdir( $path );
}
