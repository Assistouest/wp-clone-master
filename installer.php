<?php
/**
 * Clone Master — Standalone Installer
 *
 * This file is STATIC (versioned in SVN). It never gets generated at runtime.
 * All dynamic values (token hash, TTL, CORS origin, session path) are read from
 * installer_config.json, which is written by class-importer.php::step_prepare()
 * into wp-content/wpcm-temp/{session_id}/ and deleted automatically on completion.
 *
 * This file runs OUTSIDE of WordPress (no WP bootstrap). It is intentionally
 * accessible via HTTP — it becomes fully inert once installer_config.json is deleted.
 *
 * Authentication: bcrypt password_verify() on a token generated client-side via
 * crypto.getRandomValues(). The token is never stored or returned by the WP plugin.
 *
 * Security model:
 *   - No config file  → HTTP 404, exit immediately (inert by default)
 *   - Wrong token     → config deleted, HTTP 410 (one-shot auth)
 *   - TTL expired     → config deleted, HTTP 410
 *   - Wrong origin    → HTTP 403
 *
 * @package Clone_Master
 * @since   1.1.0
 */

// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report
// Reason: $wpdb is unavailable — this file runs outside the WordPress bootstrap.
// Direct mysqli is the only viable approach for a standalone database importer.

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli
// Reason: Same as above — $wpdb and the WordPress DB API are not available here.

// phpcs:disable WordPress.Security.NonceVerification
// Reason: WP nonces are user-session-bound and require WordPress to be loaded.
// Authentication is handled by bcrypt password_verify() on the installer_token.

// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// Reason: WP sanitization functions (sanitize_text_field, wp_unslash) are unavailable.
// All inputs are validated via strict regex and bcrypt before use.

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// Reason: Bootstrap variables live in the standalone script's top-level scope
// which does not conflict with any WordPress global namespace.

// phpcs:disable WordPress.WP.AlternativeFunctions
// Reason: WP_Filesystem and WP file functions are unavailable outside WP bootstrap.

// phpcs:disable WordPress.Security.EscapeOutput
// Reason: All output is json_encode() data — escaping would corrupt JSON.

// phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
// Reason: error_reporting() is required here — PHP notices/warnings in output
// corrupt the JSON responses this installer sends to the browser.

// ── Security model — this file is intentionally HTTP-accessible ──────────────
//
// This installer does NOT use the standard `if ( ! defined('ABSPATH') ) exit`
// pattern because it runs OUTSIDE of WordPress and must respond to HTTP requests.
// Defining ABSPATH just to satisfy a guard that then never fires would be
// misleading and offer zero real protection.
//
// Real security is enforced by the two layers below, applied before any
// sensitive operation:
//
//   1. file_exists( $wpcm_config_path ) — the file is created by the WP plugin
//      only when an import is initiated, and is deleted immediately on success,
//      failure, or TTL expiry. Without it this script returns HTTP 404 and exits.
//      The installer is therefore INERT by default and at all times except during
//      a live import session.
//
//   2. password_verify( $token, $bcrypt_hash ) — the bcrypt hash stored in
//      installer_config.json cannot be reversed. The client token (generated via
//      crypto.getRandomValues()) is required to authenticate. A wrong token
//      triggers wpcm_self_destruct(), which deletes the config and returns HTTP 410,
//      giving an attacker exactly ONE attempt before the installer becomes inert.
//
// phpcs:ignore WordPress.Security.NonceVerification -- WP nonces require WP bootstrap; auth is bcrypt (see above)
// phpcs:ignore WordPress.WP.GlobalVariablesOverride -- ABSPATH not defined in standalone context; see comment above

// ── Silence PHP notices/warnings — they corrupt JSON output ──────────────────
error_reporting( E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.Discouraged -- Standalone context; required before ob_start()

// ── Attempt to extend execution time (best-effort; ignored if disabled) ──────
@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required to prevent timeout on large sites; standalone context

@ini_set( 'memory_limit', '512M' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for large site processing; standalone context

// ── Capture any stray output so it never corrupts the JSON response ───────────
ob_start();

// ============================================================
// Locate installer_config.json via session_id
// ============================================================
// session_id is passed as GET parameter ?sid= in the installer URL,
// built by class-importer.php::step_prepare().
// Strict validation prevents path traversal — only alphanumerics and underscores.
$wpcm_raw_sid = isset( $_GET['sid'] ) ? (string) $_GET['sid'] : ''; // phpcs:ignore -- supressed at file level above
if ( ! preg_match( '/^[a-zA-Z0-9_]{1,80}$/', $wpcm_raw_sid ) ) {
	ob_end_clean();
	http_response_code( 404 );
	exit;
}

// Derive the absolute path to wpcm-temp from this file's location.
// __DIR__ = wp-content/plugins/clone-master
// Two levels up: wp-content/
$wpcm_temp_dir   = dirname( dirname( __DIR__ ) ) . '/wpcm-temp/';
$wpcm_session_dir = $wpcm_temp_dir . $wpcm_raw_sid . '/';
$wpcm_config_path = $wpcm_session_dir . 'installer_config.json';

// If no config exists, the installer is inert — respond 404 silently.
if ( ! file_exists( $wpcm_config_path ) ) {
	ob_end_clean();
	http_response_code( 404 );
	exit;
}

// ============================================================
// Self-destruct helper
// ============================================================
// Deletes installer_config.json (making this file inert) and returns JSON error.
// The static installer.php file itself is never deleted — it lives in SVN.
function wpcm_self_destruct( $wpcm_config_path, $reason = '' ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context; wp_delete_file() unavailable
	@unlink( $wpcm_config_path );
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
	}
	http_response_code( 410 );
	ob_end_clean();
	echo json_encode( array( 'success' => false, 'data' => array( 'message' => $reason ?: 'Installer expired or terminated.' ) ) );
	exit;
}

// ============================================================
// Fatal-error safety net (Duplicator DUPX_Handler pattern)
// ============================================================
// register_shutdown_function fires on any PHP termination: normal exit, die(),
// AND E_ERROR (max_execution_time exceeded, memory exhausted, parse error).
// try/catch does NOT catch E_ERROR — only this shutdown hook can.
// On fatal error: clean JSON response + make installer inert.
register_shutdown_function( function() use ( $wpcm_config_path ) {
	$err = error_get_last();
	if ( $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ), true ) ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		$msg        = $err['message'];
		$is_timeout = stripos( $msg, 'execution time' ) !== false;
		if ( stripos( $msg, 'execution time' ) !== false ) {
			$msg = 'PHP max_execution_time exceeded. Chunk is too large — retrying will resume from here.';
		} elseif ( stripos( $msg, 'memory' ) !== false ) {
			$msg = 'PHP out of memory. Try increasing memory_limit in php.ini or .htaccess.';
		}
		// Only make inert on non-timeout fatal — timeouts are resumable by JS
		if ( ! $is_timeout ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
			@unlink( $wpcm_config_path );
		}
		echo json_encode( array( 'success' => false, 'data' => array( 'message' => '[FATAL] ' . $msg ) ) );
	}
} );

// ============================================================
// Load and validate config
// ============================================================
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone context; WP_Filesystem unavailable
$wpcm_cfg = json_decode( file_get_contents( $wpcm_config_path ), true );
if ( ! $wpcm_cfg || ! is_array( $wpcm_cfg ) ) {
	wpcm_self_destruct( $wpcm_config_path, 'Invalid installer config.' );
}

$wpcm_token_hash     = $wpcm_cfg['token_hash']     ?? '';
$wpcm_expires_at     = isset( $wpcm_cfg['expires_at'] ) ? (int) $wpcm_cfg['expires_at'] : 0;
$wpcm_allowed_origin = $wpcm_cfg['allowed_origin'] ?? '';
// $wpcm_session_dir already set above from ?sid= URL parameter

// ============================================================
// TTL check — before anything else, including CORS headers
// ============================================================
if ( ! $wpcm_expires_at || time() > $wpcm_expires_at ) {
	wpcm_self_destruct( $wpcm_config_path, 'Installer TTL expired (15 min). Please restart the import process.' );
}

// ============================================================
// CORS — origin whitelist (NOT reflected from request)
// ============================================================
$wpcm_request_origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
// Allow only the exact WP admin origin baked at generation time.
// Non-browser clients (curl, wp-cron) send no Origin header — allow those through.
if ( $wpcm_request_origin !== '' && $wpcm_allowed_origin !== '' && $wpcm_request_origin !== $wpcm_allowed_origin ) {
	http_response_code( 403 );
	ob_end_clean();
	echo json_encode( array( 'success' => false, 'data' => array( 'message' => 'Origin not allowed.' ) ) );
	exit;
}
if ( $wpcm_allowed_origin ) {
	header( 'Access-Control-Allow-Origin: ' . $wpcm_allowed_origin );
	header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
	header( 'Access-Control-Allow-Headers: Content-Type' );
	header( 'Access-Control-Allow-Credentials: true' );
}
header( 'Content-Type: application/json; charset=utf-8' );
header( 'X-Content-Type-Options: nosniff' );
header( 'X-Frame-Options: DENY' );
header( 'Referrer-Policy: no-referrer' );

// Handle CORS preflight
if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
	http_response_code( 204 );
	exit;
}

// ============================================================
// Authentication — bcrypt password_verify()
// ============================================================
// The browser generated this token with crypto.getRandomValues().
// It was sent once in the *request* to step_prepare (never in a response).
// The WP plugin stored only bcrypt_hash(token) in installer_config.json.
// password_verify() is the only valid path in; the hash cannot be reversed.
$wpcm_input_token = isset( $_POST['installer_token'] ) ? (string) $_POST['installer_token'] : '';
if ( ! $wpcm_input_token || ! $wpcm_token_hash || ! password_verify( $wpcm_input_token, $wpcm_token_hash ) ) {
	// Wrong token → make inert immediately.
	// An attacker who finds the URL gets exactly one attempt before the config disappears.
	wpcm_self_destruct( $wpcm_config_path, 'Unauthorized.' );
}

// ============================================================
// Decrypt DB credentials
// ============================================================
// Credentials were encrypted in class-importer.php with AES-256-CBC.
// Key = SHA-256 of the client token — same token the browser just sent.
// The token is the only thing that connects the bcrypt hash (installer_config.json)
// and the AES-encrypted blob (also in installer_config.json).
if ( empty( $wpcm_cfg['db_credentials_enc'] ) ) {
	wpcm_self_destruct( $wpcm_config_path, 'Database credentials missing or not encrypted. Regenerate the installer.' );
}
if ( ! function_exists( 'openssl_decrypt' ) ) {
	wpcm_self_destruct( $wpcm_config_path, 'PHP openssl extension is required to decrypt database credentials.' );
}
$wpcm_enc_raw = base64_decode( $wpcm_cfg['db_credentials_enc'] );
$wpcm_enc_iv  = substr( $wpcm_enc_raw, 0, 16 );
$wpcm_enc_ct  = substr( $wpcm_enc_raw, 16 );
$wpcm_enc_key = hash( 'sha256', $wpcm_input_token, true ); // derive AES key from the verified token
$wpcm_plain   = openssl_decrypt( $wpcm_enc_ct, 'AES-256-CBC', $wpcm_enc_key, OPENSSL_RAW_DATA, $wpcm_enc_iv );
if ( $wpcm_plain === false ) {
	wpcm_self_destruct( $wpcm_config_path, 'Failed to decrypt database credentials. The installer may be corrupted.' );
}
$wpcm_db_creds = json_decode( $wpcm_plain, true );
if ( ! $wpcm_db_creds || empty( $wpcm_db_creds['db_host'] ) ) {
	wpcm_self_destruct( $wpcm_config_path, 'Invalid decrypted database credentials format.' );
}
$wpcm_cfg['db_host'] = $wpcm_db_creds['db_host'];
$wpcm_cfg['db_name'] = $wpcm_db_creds['db_name'];
$wpcm_cfg['db_user'] = $wpcm_db_creds['db_user'];
$wpcm_cfg['db_pass'] = $wpcm_db_creds['db_pass'];
unset( $wpcm_cfg['db_credentials_enc'], $wpcm_enc_raw, $wpcm_enc_iv, $wpcm_enc_ct, $wpcm_enc_key, $wpcm_plain, $wpcm_db_creds );

// ============================================================
// Route to the requested step
// ============================================================
$wpcm_step = isset( $_POST['step'] ) ? (string) $_POST['step'] : 'database';

// Inject resume state for the time-sliced database step
if ( $wpcm_step === 'database' ) {
	$wpcm_cfg['_db_file_index']    = isset( $_POST['file_index'] )    ? (int) $_POST['file_index']    : 0;
	$wpcm_cfg['_db_byte_offset']   = isset( $_POST['byte_offset'] )   ? (int) $_POST['byte_offset']   : 0;
	$wpcm_cfg['_db_queries_total'] = isset( $_POST['queries_total'] ) ? (int) $_POST['queries_total'] : 0;
	$wpcm_cfg['_db_errors_total']  = isset( $_POST['errors_total'] )  ? (int) $_POST['errors_total']  : 0;
}
// Inject resume state for the time-sliced replace_urls step
if ( $wpcm_step === 'replace_urls' ) {
	$wpcm_cfg['_sr_table_index'] = isset( $_POST['table_index'] ) ? (int) $_POST['table_index'] : 0;
	$wpcm_cfg['_sr_row_offset']  = isset( $_POST['row_offset'] )  ? (int) $_POST['row_offset']  : 0;
	$wpcm_cfg['_sr_rows']        = isset( $_POST['sr_rows'] )     ? (int) $_POST['sr_rows']     : 0;
	$wpcm_cfg['_sr_cells']       = isset( $_POST['sr_cells'] )    ? (int) $_POST['sr_cells']    : 0;
	$wpcm_cfg['_sr_serial']      = isset( $_POST['sr_serial'] )   ? (int) $_POST['sr_serial']   : 0;
}

try {
	switch ( $wpcm_step ) {
		case 'database':
			$wpcm_result = wpcm_step_database( $wpcm_cfg, $wpcm_session_dir );
			break;
		case 'files':
			$wpcm_result = wpcm_step_files( $wpcm_cfg, $wpcm_session_dir );
			break;
		case 'replace_urls':
			$wpcm_result = wpcm_step_replace_urls( $wpcm_cfg );
			break;
		case 'finalize':
			$wpcm_result = wpcm_step_finalize( $wpcm_cfg, $wpcm_session_dir );
			break;
		default:
			wpcm_self_destruct( $wpcm_config_path, 'Unknown step: ' . htmlspecialchars( $wpcm_step, ENT_QUOTES ) );
	}
	ob_end_clean();
	echo json_encode( array( 'success' => true, 'data' => $wpcm_result ) );
} catch ( Exception $e ) {
	// Unrecoverable exception → clean JSON response + make installer inert.
	ob_end_clean();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
	@unlink( $wpcm_config_path );
	echo json_encode( array( 'success' => false, 'data' => array( 'message' => $e->getMessage() ) ) );
}
exit;

// ============================================================
// Maintenance mode helpers
// ============================================================

/**
 * Enable WordPress maintenance mode by writing a .maintenance file to ABSPATH.
 *
 * @param string $abspath Absolute server path to the WP root.
 */
function wpcm_enable_maintenance_mode( $abspath ) {
	$file = rtrim( $abspath, '/\\' ) . DIRECTORY_SEPARATOR . '.maintenance';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone context
	@file_put_contents( $file, '<?php $upgrading = ' . time() . '; ?>' );
}

/**
 * Disable WordPress maintenance mode by removing the .maintenance file.
 *
 * @param string $abspath Absolute server path to the WP root.
 */
function wpcm_disable_maintenance_mode( $abspath ) {
	$file = rtrim( $abspath, '/\\' ) . DIRECTORY_SEPARATOR . '.maintenance';
	if ( file_exists( $file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
		@unlink( $file );
	}
}

// ============================================================
// Step: Import Database
// ============================================================
function wpcm_step_database( $CFG, $wpcm_session_dir ) {
	// Enable maintenance mode at the start of the first destructive step.
	// Refreshed on every call so long chunked imports never expire the 600s window.
	wpcm_enable_maintenance_mode( $CFG['abspath'] ?? '' );

	// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
	@set_time_limit( 0 );

	$time_budget = 8; // 8s — safe under any FastCGI/Apache shared-hosting timeout (30s)
	$start_time  = microtime( true );

	$file_index           = isset( $CFG['_db_file_index'] )    ? (int) $CFG['_db_file_index']    : 0;
	$byte_offset          = isset( $CFG['_db_byte_offset'] )   ? (int) $CFG['_db_byte_offset']   : 0;
	$total_queries_so_far = isset( $CFG['_db_queries_total'] ) ? (int) $CFG['_db_queries_total'] : 0;
	$total_errors_so_far  = isset( $CFG['_db_errors_total'] )  ? (int) $CFG['_db_errors_total']  : 0;

	$sql_dir = wpcm_find_subdir( $wpcm_session_dir, 'sql' );
	if ( ! $sql_dir ) {
		throw new Exception( 'SQL directory not found' );
	}

	$sql_files = glob( $sql_dir . '*.sql' );
	if ( ! $sql_files ) {
		throw new Exception( 'No SQL files found in archive' );
	}
	sort( $sql_files );
	$total_files = count( $sql_files );

	// All files already processed
	if ( $file_index >= $total_files ) {
		return array(
			'file_index'  => $file_index,
			'byte_offset' => 0,
			'total_files' => $total_files,
			'queries'     => 0,
			'errors'      => 0,
			'next_step'   => 'files',
			'progress'    => 45,
			'message'     => 'Database import complete. Restoring files...',
		);
	}

	$mysqli = wpcm_db_connect( $CFG );

	// Pre-drop all destination tables (first call only) — Duplicator/AI1WM strategy.
	// Batch DROP avoids N round-trips on shared hosting.
	if ( $file_index === 0 && $byte_offset === 0 ) {
		$mysqli->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$existing = $mysqli->query(
			"SHOW TABLES LIKE '" . $mysqli->real_escape_string( $CFG['table_prefix'] ) . "%'"
		);
		if ( $existing ) {
			$tables_to_drop = array();
			while ( $trow = $existing->fetch_row() ) {
				$tables_to_drop[] = '`' . $trow[0] . '`';
			}
			$existing->free();
			if ( ! empty( $tables_to_drop ) ) {
				$mysqli->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $tables_to_drop ) );
			}
		}
		$mysqli->query( 'SET FOREIGN_KEY_CHECKS = 1' );
	}

	$mp_r       = $mysqli->query( 'SELECT @@session.max_allowed_packet' );
	$max_packet = $mp_r ? (int) $mp_r->fetch_row()[0] : 1048576;
	if ( $mp_r ) {
		$mp_r->free();
	}
	$max_packet = max( $max_packet - 256, 65536 );

	foreach ( array(
		'SET SESSION wait_timeout = 600',
		'SET SESSION net_read_timeout = 600',
		'SET SESSION net_write_timeout = 600',
		'SET SESSION interactive_timeout = 600',
		"SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'",
		'SET FOREIGN_KEY_CHECKS = 0',
	) as $_q ) {
		$mysqli->query( $_q );
	}

	$old_prefix   = $CFG['old_prefix'];
	$new_prefix   = $CFG['table_prefix'];
	$call_queries = 0;
	$call_errors  = 0;
	$errors_log   = array();
	$completed    = true;

	$sql_file  = $sql_files[ $file_index ];
	$fh        = @fopen( $sql_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary read; WP_Filesystem unavailable
	if ( ! $fh ) {
		throw new Exception( 'Cannot open SQL file: ' . basename( $sql_file ) );
	}

	if ( $byte_offset > 0 ) {
		fseek( $fh, $byte_offset );
	}

	$buffer = '';
	$in_str = false;
	$escape = false;

	// ── Statement-boundary resume offset ─────────────────────────────────────
	// Root cause of the infinite-loop bug:
	//   mysqldump writes many statements on the SAME line:
	//   "DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (…);\nINSERT INTO `t` VALUES (1),(2),(3);\n"
	//   …but large sites often have ALL values in ONE line:
	//   "INSERT INTO `t` VALUES (1),(2),…,(50000);\n"
	//
	//   The previous fix saved $line_start_offset (before fgets). If timeout
	//   fired after N statements on the SAME line, we returned that line's
	//   start → next call re-read the same line → same N statements → loop.
	//
	// Fix: save $stmt_end_offset = $line_start_offset + $i + 1 (the byte
	// immediately after the ';' just executed). On timeout we return THIS offset.
	// fseek() to a mid-line position + fgets() correctly reads the remainder
	// of the line, so the parser picks up with an empty buffer from that point.
	//
	// Re-executing statements from the previous call on overlap would cause
	// duplicate-key errors — but these are idempotent:
	//   - CREATE TABLE is preceded by DROP TABLE IF EXISTS (already in code)
	//   - Duplicate INSERTs are retried as REPLACE INTO (errno 1062 handler)
	// So the overlap is safe.
	$stmt_end_offset   = $byte_offset; // will be updated after every executed stmt
	$line_start_offset = $byte_offset; // for the fseek guard below

	while ( ! feof( $fh ) ) {
		$line_start_offset = ftell( $fh );
		$line = fgets( $fh, 1048576 );
		if ( $line === false ) {
			break;
		}

		if ( $old_prefix !== $new_prefix ) {
			$line = str_replace( '`' . $old_prefix, '`' . $new_prefix, $line );
		}

		$trimmed = ltrim( $line );
		if ( $trimmed === '' ) {
			continue;
		}
		if ( $trimmed[0] === '#' ) {
			continue;
		}
		if ( strlen( $trimmed ) >= 2 && $trimmed[0] === '-' && $trimmed[1] === '-' ) {
			continue;
		}

		for ( $i = 0, $len = strlen( $line ); $i < $len; $i++ ) {
			$c = $line[ $i ];
			if ( $escape )              { $buffer .= $c; $escape = false; continue; }
			if ( $c === '\\' )          { $buffer .= $c; $escape = true;  continue; }
			if ( $c === "'" && ! $in_str ) { $in_str = true;  $buffer .= $c; continue; }
			if ( $c === "'" && $in_str )   { $in_str = false; $buffer .= $c; continue; }

			if ( $c === ';' && ! $in_str ) {
				$stmt   = trim( $buffer );
				$buffer = '';
				if ( $stmt === '' ) {
					continue;
				}

				$to_run = array();
				if ( preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`[^`]+`|\S+)/i', $stmt, $ct_m ) ) {
					$to_run[] = 'DROP TABLE IF EXISTS ' . $ct_m[1];
				}
				if ( strlen( $stmt ) > $max_packet &&
					preg_match( '/^(INSERT[\s\S]{1,60}?(?:VALUES|VALUE))\s*([\s\S]*)/i', $stmt, $m ) ) {
					$ins_pfx = $m[1];
					$rp      = $m[2];
					$rows    = array(); $rb = ''; $dep = 0; $rs = false; $re = false;
					for ( $j = 0, $rl = strlen( $rp ); $j < $rl; $j++ ) {
						$rc = $rp[ $j ];
						if ( $re )              { $rb .= $rc; $re = false; continue; }
						if ( $rc === '\\' )     { $rb .= $rc; $re = true;  continue; }
						if ( $rc === "'" && ! $rs ) { $rs = true;  $rb .= $rc; continue; }
						if ( $rc === "'" && $rs  )  { $rs = false; $rb .= $rc; continue; }
						if ( ! $rs ) {
							if ( $rc === '(' ) { $dep++; $rb .= $rc; continue; }
							if ( $rc === ')' ) {
								$dep--; $rb .= $rc;
								if ( $dep === 0 ) { $rows[] = $rb; $rb = ''; continue; }
								continue;
							}
							if ( $rc === ',' && $dep === 0 ) {
								continue;
							}
						}
						$rb .= $rc;
					}
					if ( $rb !== '' ) {
						$rows[] = $rb;
					}
					$chunk = array(); $csz = strlen( $ins_pfx ) + 10;
					foreach ( $rows as $row ) {
						$rl2 = strlen( $row ) + 1;
						if ( ! empty( $chunk ) && ( $csz + $rl2 ) > $max_packet ) {
							$to_run[] = $ins_pfx . ' ' . implode( ',', $chunk );
							$chunk = array(); $csz = strlen( $ins_pfx ) + 10;
						}
						$chunk[] = $row; $csz += $rl2;
					}
					if ( ! empty( $chunk ) ) {
						$to_run[] = $ins_pfx . ' ' . implode( ',', $chunk );
					}
				} else {
					$to_run[] = $stmt;
				}

				if ( ! @$mysqli->ping() ) {
					$mysqli->close();
					$mysqli = wpcm_db_connect( $CFG );
					$mysqli->query( 'SET FOREIGN_KEY_CHECKS = 0' );
					$mysqli->query( "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'" );
				}

				foreach ( $to_run as $q ) {
					$res = $mysqli->query( $q );
					if ( $res === false ) {
						$errno = $mysqli->errno;
						$err   = $mysqli->error;

						if ( stripos( $err, 'gone away' ) !== false || stripos( $err, 'lost connection' ) !== false ) {
							$mysqli->close();
							$mysqli = wpcm_db_connect( $CFG );
							$mysqli->query( 'SET FOREIGN_KEY_CHECKS = 0' );
							$res   = $mysqli->query( $q );
							$errno = $res === false ? $mysqli->errno : 0;
							$err   = $res === false ? $mysqli->error : '';
						}

						if ( $res === false ) {
							if ( $errno === 1062 && preg_match( '/^INSERT\s+INTO\s/i', $q ) ) {
								$q_replace = preg_replace( '/^INSERT\s+INTO\s/i', 'REPLACE INTO ', $q );
								$res       = $mysqli->query( $q_replace );
								if ( $res !== false ) {
									if ( $res instanceof mysqli_result ) {
										$res->free();
									}
									$call_queries++;
									continue;
								}
							}
							$call_errors++;
							if ( count( $errors_log ) < 10 ) {
								$errors_log[] = '[' . $errno . '] ' . substr( $q, 0, 80 ) . ': ' . $mysqli->error;
							}
						}
					}
					if ( $res instanceof mysqli_result ) {
						$res->free();
					}
					$call_queries++;
				}

				if ( ( microtime( true ) - $start_time ) > $time_budget ) {
					// Save the byte position immediately AFTER this ';'.
					// $i is the index of ';' within $line.
					// $line_start_offset is the file position before fgets().
					// So $line_start_offset + $i + 1 = first byte after ';'.
					// fseek() there on next call + fgets() reads the rest of
					// the line correctly, avoiding re-execution of this stmt.
					$stmt_end_offset = $line_start_offset + $i + 1;
					$completed = false;
					break 2;
				}

				// Update resume marker after each successfully executed statement.
				// This is the fallback if timeout fires on a later statement.
				$stmt_end_offset = $line_start_offset + $i + 1;

				continue;
			}
			$buffer .= $c;
		}
	}

	// Resume from the byte position AFTER the last executed statement.
	// On completion, return 0 so the next file starts from the beginning.
	$final_offset    = $completed ? 0 : $stmt_end_offset;
	$next_file_index  = $file_index;
	$next_byte_offset = $final_offset;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone context
	fclose( $fh );
	$mysqli->query( 'SET FOREIGN_KEY_CHECKS = 1' );

	if ( $completed ) {
		$next_file_index  = $file_index + 1;
		$next_byte_offset = 0;

		if ( $next_file_index >= $total_files ) {
			$opts = $new_prefix . 'options';
			$esc  = $mysqli->real_escape_string( $CFG['new_url'] );
			$mysqli->query( "UPDATE `{$opts}` SET option_value='{$esc}' WHERE option_name='siteurl'" );
			$mysqli->query( "UPDATE `{$opts}` SET option_value='{$esc}' WHERE option_name='home'" );
		}
	}
	$mysqli->close();

	$all_queries = $total_queries_so_far + $call_queries;
	$all_errors  = $total_errors_so_far + $call_errors;
	$is_done     = ( $next_file_index >= $total_files );
	$pct         = $is_done ? 45 : ( 20 + (int) ( ( $next_file_index / $total_files ) * 25 ) );

	return array(
		'file_index'  => $next_file_index,
		'byte_offset' => $next_byte_offset,
		'total_files' => $total_files,
		'queries'     => $all_queries,
		'errors'      => $all_errors,
		'errors_log'  => $errors_log,
		'completed'   => $completed,
		'next_step'   => $is_done ? 'files' : 'database',
		'progress'    => $pct,
		'message'     => $is_done
			? 'Database import complete. ' . $all_queries . ' queries.'
			: 'DB ' . $next_file_index . '/' . $total_files . ' — ' . $all_queries . ' queries'
			  . ( $all_errors ? ", {$all_errors} errors" : '' ),
	);
}

// ============================================================
// DB connection helper
// ============================================================
function wpcm_db_connect( $CFG ) {
	// PHP 8.1 changed MySQLi default error mode to MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT.
	// Our code checks $res === false and $mysqli->errno manually, so disable exception mode —
	// exactly as WordPress core did in Trac #52825 and as Duplicator/AI1WM do.
	mysqli_report( MYSQLI_REPORT_OFF );

	$mysqli = new mysqli( $CFG['db_host'], $CFG['db_user'], $CFG['db_pass'], $CFG['db_name'] );
	if ( $mysqli->connect_errno ) {
		throw new Exception( 'DB connection failed: ' . $mysqli->connect_error );
	}
	$mysqli->set_charset( $CFG['db_charset'] ?: 'utf8mb4' );
	$mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 600 );
	return $mysqli;
}

// ============================================================
// Step: Restore Files
// ============================================================
function wpcm_step_files( $CFG, $wpcm_session_dir ) {
	$files_dir = wpcm_find_subdir( $wpcm_session_dir, 'files' );
	if ( ! $files_dir ) {
		return array(
			'next_step' => 'replace_urls',
			'progress'  => 65,
			'message'   => 'No file archives found. Running URL replacement...',
		);
	}

	// Full wp-content wipe before restore (Duplicator "clean install" approach).
	// Keeps: clone-master plugin folder, wpcm-temp (active session), wpcm-backups, wpcm-logs.
	$wc_dir          = rtrim( $CFG['wp_content_dir'], '/' );
	$session_wc_rel  = basename( rtrim( dirname( rtrim( $wpcm_session_dir, '/' ) ), '/' ) );
	$wc_keep         = array( '.', '..', $session_wc_rel, 'wpcm-backups', 'wpcm-logs' );

	if ( is_dir( $wc_dir ) ) {
		foreach ( scandir( $wc_dir ) ?: array() as $item ) {
			if ( in_array( $item, $wc_keep, true ) ) {
				continue;
			}
			$path = $wc_dir . '/' . $item;

			if ( $item === 'plugins' && is_dir( $path ) ) {
				$own_folder = $CFG['plugin_folder'] ?? 'clone-master';
				foreach ( scandir( $path ) ?: array() as $plugin_item ) {
					if ( $plugin_item === '.' || $plugin_item === '..' ) {
						continue;
					}
					if ( $plugin_item === $own_folder ) {
						continue;
					}
					$pp = $path . '/' . $plugin_item;
					if ( is_dir( $pp ) ) {
						wpcm_recursive_delete( $pp );
					} elseif ( is_file( $pp ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
						@unlink( $pp );
					}
				}
				continue;
			}

			if ( is_dir( $path ) ) {
				wpcm_recursive_delete( $path );
			} elseif ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
				@unlink( $path );
			}
		}
	}

	$zip_files = glob( $files_dir . '*.zip' );
	$restored  = 0;
	$errors    = array();

	foreach ( $zip_files as $zip_path ) {
		$zip = new ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			$errors[] = basename( $zip_path );
			continue;
		}

		$first  = $zip->getNameIndex( 0 );
		$top    = explode( '/', $first )[0];
		$target = null;
		if ( strpos( $top, 'themes' ) === 0 )      { $target = $CFG['theme_root']; }
		elseif ( strpos( $top, 'plugins' ) === 0 ) { $target = $CFG['plugin_dir']; }
		elseif ( strpos( $top, 'uploads' ) === 0 ) { $target = $CFG['uploads_dir']; }
		elseif ( $top === 'mu-plugins' )            { $target = $CFG['wp_content_dir'] . '/mu-plugins'; }
		elseif ( $top === 'languages' )             { $target = $CFG['wp_content_dir'] . '/languages'; }
		elseif ( $top === 'dropins' )               { $target = $CFG['wp_content_dir']; }

		if ( ! $target ) {
			$zip->close();
			continue;
		}

		$temp      = $wpcm_session_dir . 'ext_' . md5( $zip_path ) . '/';
		@mkdir( $temp, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone context

		// ZIP Slip guard — validate every entry before extraction
		$real_temp = $temp;
		for ( $zi = 0; $zi < $zip->numFiles; $zi++ ) {
			$ze_name  = $zip->getNameIndex( $zi );
			$ze_dest  = $real_temp . $ze_name;
			$ze_parts = array();
			foreach ( explode( '/', str_replace( '\\', '/', $ze_dest ) ) as $zp ) {
				if ( $zp === '..' ) {
					array_pop( $ze_parts );
				} elseif ( $zp !== '.' && $zp !== '' ) {
					$ze_parts[] = $zp;
				}
			}
			$ze_norm        = '/' . implode( '/', $ze_parts );
			$real_temp_check = '/' . implode( '/', array_filter( explode( '/', str_replace( '\\', '/', $real_temp ) ) ) );
			if ( strpos( $ze_norm, $real_temp_check ) !== 0 ) {
				$zip->close();
				continue 2;
			}
		}

		$zip->extractTo( $temp );
		$zip->close();

		$source = is_dir( $temp . $top ) ? $temp . $top . '/' : $temp;

		$category_prefixes = array( 'themes/', 'plugins/', 'uploads/', 'mu-plugins/', 'languages/', 'dropins/' );
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
			@mkdir( $dest, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone context
		}

		wpcm_copy_recursive( $source, $dest );
		wpcm_recursive_delete( $temp );
		$restored++;
	}

	// Root files
	foreach ( array( '.htaccess', 'robots.txt', '.user.ini', 'php.ini' ) as $f ) {
		$src = $files_dir . 'root_' . $f;
		if ( file_exists( $src ) ) {
			copy( $src, $CFG['abspath'] . $f );
		}
	}

	return array(
		'restored'  => $restored,
		'errors'    => $errors,
		'next_step' => 'replace_urls',
		'progress'  => 65,
		'message'   => $restored . ' archives restored. Running URL replacement...',
	);
}

// ============================================================
// Step: Replace URLs (serialization-safe, time-sliced)
// ============================================================
function wpcm_step_replace_urls( $CFG ) {
	// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
	@set_time_limit( 0 );
	$time_budget = 3;
	$start_time  = microtime( true );

	$old_url     = $CFG['old_url'];
	$new_url     = rtrim( $CFG['new_url'], '/' );
	$prefix      = $CFG['table_prefix'];
	$table_index = isset( $CFG['_sr_table_index'] ) ? (int) $CFG['_sr_table_index'] : 0;
	$rows_so_far = isset( $CFG['_sr_rows'] )        ? (int) $CFG['_sr_rows']        : 0;
	$cells_so_far= isset( $CFG['_sr_cells'] )       ? (int) $CFG['_sr_cells']       : 0;
	$serial_so_far=isset( $CFG['_sr_serial'] )      ? (int) $CFG['_sr_serial']      : 0;

	mysqli_report( MYSQLI_REPORT_OFF );
	$mysqli = new mysqli( $CFG['db_host'], $CFG['db_user'], $CFG['db_pass'], $CFG['db_name'] );
	if ( $mysqli->connect_errno ) {
		throw new Exception( 'DB connection failed: ' . $mysqli->connect_error );
	}
	$mysqli->set_charset( $CFG['db_charset'] ?: 'utf8mb4' );

	$esc_new = $mysqli->real_escape_string( $new_url );

	// Always force siteurl + home on first call (Duplicator pattern)
	if ( $table_index === 0 ) {
		$mysqli->query( "UPDATE `{$prefix}options` SET option_value = '{$esc_new}' WHERE option_name = 'siteurl'" );
		$mysqli->query( "UPDATE `{$prefix}options` SET option_value = '{$esc_new}' WHERE option_name = 'home'" );
	}

	$same_url  = ( ! $old_url || rtrim( $old_url, '/' ) === $new_url );
	$same_path = ( ! $CFG['old_path'] || $CFG['old_path'] === $CFG['new_path'] );

	if ( $same_url && $same_path ) {
		$mysqli->close();
		return array(
			'table_index'  => 0,
			'total_tables' => 0,
			'rows'         => 0,
			'cells'        => 0,
			'serial'       => 0,
			'next_step'    => 'finalize',
			'progress'     => 85,
			'message'      => 'Same URL — siteurl/home forced to new domain.',
		);
	}

	$pairs    = wpcm_build_url_pairs( $old_url, $new_url, $CFG['old_path'], $CFG['new_path'] );
	$old_home = $CFG['old_home'] ?? '';
	if ( $old_home && rtrim( $old_home, '/' ) !== $new_url ) {
		$pairs = array_merge( $pairs, wpcm_build_url_pairs( $old_home, $new_url, $CFG['old_path'], $CFG['new_path'] ) );
	}
	$seen  = array();
	$pairs = array_values( array_filter( $pairs, function ( $p ) use ( &$seen ) {
		if ( $p[0] === $p[1] ) {
			return false;
		}
		if ( isset( $seen[ $p[0] ] ) ) {
			return false;
		}
		$seen[ $p[0] ] = true;
		return true;
	} ) );

	$mysqli->query( "SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode,'STRICT_TRANS_TABLES','')" );
	$mysqli->query( "SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode,'STRICT_ALL_TABLES','')" );

	$db_name   = $CFG['db_name'];
	$pfx_esc   = $mysqli->real_escape_string( $prefix );
	$schema_sql = "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY
	               FROM information_schema.COLUMNS
	               WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string( $db_name ) . "'
	                 AND TABLE_NAME LIKE '{$pfx_esc}%'
	                 AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','blob','enum','set')
	               ORDER BY TABLE_NAME, ORDINAL_POSITION";
	$schema_r  = $mysqli->query( $schema_sql );

	$table_cols    = array();
	$table_primary = array();
	$table_maxlen  = array();
	if ( $schema_r ) {
		while ( $sc = $schema_r->fetch_assoc() ) {
			$t = $sc['TABLE_NAME'];
			$c = $sc['COLUMN_NAME'];
			$table_cols[ $t ][] = $c;
			if ( $sc['COLUMN_KEY'] === 'PRI' && ! isset( $table_primary[ $t ] ) ) {
				$table_primary[ $t ] = $c;
			}
			$type = $sc['COLUMN_TYPE'];
			if ( preg_match( '/(?:var)?char\s*\((\d+)\)/i', $type, $lm ) ) {
				$table_maxlen[ $t ][ $c ] = (int) $lm[1];
			} elseif ( stripos( $type, 'longtext' ) !== false ) {
				$table_maxlen[ $t ][ $c ] = PHP_INT_MAX;
			} elseif ( stripos( $type, 'mediumtext' ) !== false ) {
				$table_maxlen[ $t ][ $c ] = 16777215;
			} elseif ( stripos( $type, 'text' ) !== false ) {
				$table_maxlen[ $t ][ $c ] = 65535;
			} else {
				$table_maxlen[ $t ][ $c ] = PHP_INT_MAX;
			}
		}
		$schema_r->free();
	}

	$tables       = array_keys( $table_cols );
	sort( $tables );
	$total_tables = count( $tables );

	// Tables with UNIQUE URL indexes: TRUNCATE instead of search-replace (Yoast, Redirection).
	$skip_table_patterns = array(
		$prefix . 'yoast_indexable',
		$prefix . 'yoast_',
		$prefix . 'redirection_items',
	);
	$should_skip = function ( $t ) use ( $skip_table_patterns ) {
		foreach ( $skip_table_patterns as $pat ) {
			if ( strpos( $t, $pat ) === 0 ) {
				return true;
			}
		}
		return false;
	};

	if ( $table_index === 0 ) {
		foreach ( $tables as $t ) {
			if ( $should_skip( $t ) ) {
				$mysqli->query( "TRUNCATE TABLE `{$t}`" );
			}
		}
	}

	$tables       = array_values( array_filter( $tables, function ( $t ) use ( $should_skip ) {
		return ! $should_skip( $t );
	} ) );
	$total_tables = count( $tables );
	$row_offset   = isset( $CFG['_sr_row_offset'] ) ? (int) $CFG['_sr_row_offset'] : 0;

	$call_rows   = 0;
	$call_cells  = 0;
	$call_serial = 0;
	$completed   = true;

	for ( $i = $table_index; $i < $total_tables; $i++ ) {
		$t_name    = $tables[ $i ];
		$t_cols    = $table_cols[ $t_name ]    ?? array();
		$t_primary = $table_primary[ $t_name ] ?? null;
		$t_maxlen  = $table_maxlen[ $t_name ]  ?? array();

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
			$t_primary = $t_cols[0];
		}
		if ( ! $t_primary ) {
			continue;
		}

		$ts = wpcm_replace_in_table_sliced(
			$mysqli, $t_name, $t_cols, $t_primary, $t_maxlen, $pairs,
			$row_offset, $time_budget, $start_time
		);
		$call_rows   += $ts['rows'];
		$call_cells  += $ts['cells'];
		$call_serial += $ts['serialized'];

		if ( ! $ts['table_done'] ) {
			$completed   = false;
			$table_index = $i;
			$row_offset  = $ts['next_offset'];
			break;
		}

		$row_offset = 0;

		if ( ( microtime( true ) - $start_time ) > $time_budget ) {
			$completed   = false;
			$table_index = $i + 1;
			break;
		}
	}

	$all_rows   = $rows_so_far   + $call_rows;
	$all_cells  = $cells_so_far  + $call_cells;
	$all_serial = $serial_so_far + $call_serial;

	if ( $completed ) {
		$mysqli->query( "UPDATE `{$prefix}options` SET option_value = '{$esc_new}' WHERE option_name = 'siteurl'" );
		$mysqli->query( "UPDATE `{$prefix}options` SET option_value = '{$esc_new}' WHERE option_name = 'home'" );
		$mysqli->close();
		return array(
			'table_index'  => $total_tables,
			'total_tables' => $total_tables,
			'row_offset'   => 0,
			'rows'         => $all_rows,
			'cells'        => $all_cells,
			'serial'       => $all_serial,
			'next_step'    => 'finalize',
			'progress'     => 85,
			'message'      => "URLs replaced: {$all_rows} rows, {$all_cells} cells, {$all_serial} serialized fixes",
		);
	}

	$mysqli->close();
	$pct = 65 + (int) ( ( $table_index / max( $total_tables, 1 ) ) * 20 );
	return array(
		'table_index'  => $table_index,
		'total_tables' => $total_tables,
		'row_offset'   => $row_offset,
		'rows'         => $all_rows,
		'cells'        => $all_cells,
		'serial'       => $all_serial,
		'next_step'    => 'replace_urls',
		'progress'     => $pct,
		'message'      => "URL replace: table {$table_index}/{$total_tables}, row {$row_offset} — {$all_rows} rows updated",
	);
}

// ============================================================
// Step: Finalize & cleanup
// ============================================================
function wpcm_step_finalize( $CFG, $wpcm_session_dir ) {
	mysqli_report( MYSQLI_REPORT_OFF );
	$mysqli = new mysqli( $CFG['db_host'], $CFG['db_user'], $CFG['db_pass'], $CFG['db_name'] );

	$deactivated_list = array();

	if ( ! $mysqli->connect_errno ) {
		$prefix = $CFG['table_prefix'];

		// Standard WordPress cleanup
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE '\\_transient\\_%'" );
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE '\\_site\\_transient\\_%'" );
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'" );
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'auth_key'" );

		// Elementor cleanup
		$mysqli->query( "DELETE FROM `{$prefix}postmeta` WHERE meta_key = '_elementor_css'" );
		$mysqli->query( "DELETE FROM `{$prefix}postmeta` WHERE meta_key = '_elementor_page_assets'" );
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'elementor_css'" );
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE 'elementor\\_css\\_%'" );
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'elementor_global_css'" );
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = '_elementor_global_css'" );

		// Cache plugin cleanup
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE 'rocket\\_%'" );
		$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name LIKE 'w3tc\\_%'" );

		// Delete cache drop-in files (regenerated by cache plugins on next admin load)
		$wc = rtrim( $CFG['wp_content_dir'], '/' );
		foreach ( array( 'object-cache.php', 'advanced-cache.php' ) as $dropin_name ) {
			$dropin_path = $wc . '/' . $dropin_name;
			if ( file_exists( $dropin_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
				@unlink( $dropin_path );
			}
		}

		// Apply user-selected import options
		$import_opts = $CFG['import_opts'] ?? array();

		if ( ! empty( $import_opts['locale'] ) ) {
			$esc_locale = $mysqli->real_escape_string( $import_opts['locale'] );
			$mysqli->query( "UPDATE `{$prefix}options` SET option_value='{$esc_locale}' WHERE option_name='WPLANG'" );
			if ( $mysqli->affected_rows === 0 ) {
				$mysqli->query( "INSERT IGNORE INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('WPLANG', '{$esc_locale}', 'yes')" );
			}
			$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'alloptions'" );
			$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = '_site_transient_theme_roots'" );
		}

		if ( ! empty( $import_opts['reset_permalinks'] ) ) {
			$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'" );
		}

		// Block indexing — 3-layer strategy (SQL + wpcm_noindex flag + MU-plugin)
		if ( ! empty( $import_opts['block_indexing'] ) ) {
			$mysqli->query( "UPDATE `{$prefix}options` SET option_value = '0' WHERE option_name = 'blog_public'" );
			if ( $mysqli->affected_rows === 0 ) {
				$mysqli->query( "INSERT IGNORE INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('blog_public', '0', 'yes')" );
			}
			$mysqli->query( "INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('wpcm_noindex', '1', 'yes') ON DUPLICATE KEY UPDATE option_value = '1'" );

			$mu_dir = rtrim( $CFG['wp_content_dir'] ?? '', '/' ) . '/mu-plugins/';
			if ( ! is_dir( $mu_dir ) ) {
				@mkdir( $mu_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone context
			}
			if ( is_dir( $mu_dir ) && is_writable( $mu_dir ) ) {
				$nl       = "\n";
				$mu_code  = '<?php' . $nl;
				$mu_code .= '/**' . $nl;
				$mu_code .= ' * Clone Master — Force blog_public=0 (noindex)' . $nl;
				$mu_code .= ' * Auto-deleted on first WordPress load.' . $nl;
				$mu_code .= ' */' . $nl . $nl;
				$mu_code .= 'add_filter( \'pre_option_blog_public\', function() { return \'0\'; }, PHP_INT_MAX );' . $nl . $nl;
				$mu_code .= 'if ( function_exists( \'wp_robots_no_robots\' ) ) {' . $nl;
				$mu_code .= '    add_filter( \'wp_robots\', \'wp_robots_no_robots\', PHP_INT_MAX );' . $nl;
				$mu_code .= '} else {' . $nl;
				$mu_code .= '    add_action( \'wp_head\', function() {' . $nl;
				$mu_code .= '        echo \'<meta name="robots" content="noindex,nofollow" />\' . "\n";' . $nl;
				$mu_code .= '    }, 1 );' . $nl;
				$mu_code .= '}' . $nl . $nl;
				$mu_code .= 'add_action( \'init\', function() {' . $nl;
				$mu_code .= '    global $wpdb;' . $nl;
				$mu_code .= '    $wpdb->update( $wpdb->options, array( \'option_value\' => \'0\' ), array( \'option_name\' => \'blog_public\' ) );' . $nl;
				$mu_code .= '    wp_cache_delete( \'alloptions\', \'options\' );' . $nl;
				$mu_code .= '    @unlink( __FILE__ ); // phpcs:ignore' . $nl;
				$mu_code .= '}, 1 );' . $nl;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone context
				file_put_contents( $mu_dir . 'wpcm-block-indexing.php', $mu_code );
			}
		}

		// Deactivate security/WAF plugins known to break after migration
		$security_folders = array(
			'wordfence', 'wordfence-login-security', 'better-wp-security', 'solid-security',
			'sucuri-scanner', 'all-in-one-wp-security-and-firewall', 'wp-cerber', 'ninja-firewall',
			'shield-security', 'wp-defender', 'defender-security', 'bbq-firewall', 'bbq-pro',
			'malcare-security', 'security-ninja', 'altcha-spam-protection', 'cleantalk-spam-protect',
			'cleantalk', 'anti-spam', 'wp-captcha', 'google-captcha', 'advanced-google-recaptcha',
			'really-simple-captcha', 'cf7-honeypot', 'zero-spam', 'wpbrainy-spam-protection',
			'jetpack', 'jetpack-boost',
		);

		$ap_r2 = $mysqli->query( "SELECT option_value FROM `{$prefix}options` WHERE option_name='active_plugins' LIMIT 1" );
		if ( $ap_r2 ) {
			$ap2_data   = $ap_r2->fetch_assoc();
			$ap_r2->free();
			$active_now = is_array( @unserialize( $ap2_data['option_value'] ?? '', array( 'allowed_classes' => false ) ) )
				? @unserialize( $ap2_data['option_value'], array( 'allowed_classes' => false ) )
				: array();

			$active_filtered = array_values( array_filter( $active_now, function ( $slug ) use ( $security_folders, &$deactivated_list ) {
				$folder = explode( '/', $slug )[0];
				foreach ( $security_folders as $blocked ) {
					if ( strtolower( $folder ) === strtolower( $blocked ) ) {
						$deactivated_list[] = $slug;
						return false;
					}
				}
				return true;
			} ) );

			if ( ! empty( $deactivated_list ) ) {
				$ser2 = $mysqli->real_escape_string( serialize( $active_filtered ) );
				$mysqli->query( "UPDATE `{$prefix}options` SET option_value='{$ser2}' WHERE option_name='active_plugins'" );
				$deactivated_ser = $mysqli->real_escape_string( serialize( $deactivated_list ) );
				$mysqli->query( "DELETE FROM `{$prefix}options` WHERE option_name='wpcm_deactivated_plugins'" );
				$mysqli->query( "INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('wpcm_deactivated_plugins', '{$deactivated_ser}', 'no')" );
			}
		}

		// Neutralise Wordfence WAF auto_prepend_file entries
		$abspath = rtrim( $CFG['abspath'] ?? '', '/' );
		if ( $abspath ) {
			$ini_candidates = array(
				$abspath . '/.user.ini',
				$abspath . '/php.ini',
				$abspath . '/.htaccess',
				$abspath . '/wp-admin/.user.ini',
				$abspath . '/wp-admin/php.ini',
			);
			foreach ( $ini_candidates as $ini_file ) {
				if ( ! file_exists( $ini_file ) || ! is_readable( $ini_file ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone context
				$content = @file_get_contents( $ini_file );
				if ( $content === false ) {
					continue;
				}
				$modified = preg_replace(
					'/^(\s*auto_prepend_file\s*=\s*[\'"]?.*(?:wordfence|waf|firewall|security)[\'"]?\s*)$/im',
					'# WPCM_DISABLED: $1',
					$content
				);
				if ( basename( $ini_file ) === '.htaccess' ) {
					$modified = preg_replace(
						'/^(\s*php_value\s+auto_prepend_file\s+.*(?:wordfence|waf|firewall|security).*)$/im',
						'# WPCM_DISABLED: $1',
						$modified
					);
				}
				if ( $modified !== $content ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone context
					@file_put_contents( $ini_file, $modified );
				}
			}
			$wf_file = $abspath . '/wordfence-waf.php';
			if ( file_exists( $wf_file ) ) {
				@rename( $wf_file, $wf_file . '.migration-bak' );
			}
		}

		// Ensure clone-master stays active after import (AI1WM/Duplicator strategy)
		$wpcm_slug  = $CFG['plugin_slug'] ?? 'clone-master/clone-master.php';
		$own_folder = ( $CFG['plugin_folder'] ?? 'clone-master' ) . '/';
		$ap_r = $mysqli->query( "SELECT option_value FROM `{$prefix}options` WHERE option_name='active_plugins' LIMIT 1" );
		if ( $ap_r ) {
			$ap_data = $ap_r->fetch_assoc();
			$ap_r->free();
			$active  = is_array( @unserialize( $ap_data['option_value'] ?? '', array( 'allowed_classes' => false ) ) )
				? @unserialize( $ap_data['option_value'], array( 'allowed_classes' => false ) )
				: array();
			$active  = array_values( array_filter( $active, function ( $p ) use ( $own_folder ) {
				return strpos( $p, $own_folder ) !== 0;
			} ) );
			$active[] = $wpcm_slug;
			$ser      = $mysqli->real_escape_string( serialize( $active ) );
			$mysqli->query( "UPDATE `{$prefix}options` SET option_value='{$ser}' WHERE option_name='active_plugins'" );
		}

		$mysqli->close();
	}

	// Delete Elementor CSS cache files from uploads
	$uploads_dir = $CFG['uploads_dir'] ?? '';
	if ( $uploads_dir ) {
		$elementor_css = rtrim( $uploads_dir, '/' ) . '/elementor/css/';
		if ( is_dir( $elementor_css ) ) {
			foreach ( glob( $elementor_css . '*.css' ) ?: array() as $css_file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
				@unlink( $css_file );
			}
			foreach ( glob( $elementor_css . '*.min.css' ) ?: array() as $css_file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
				@unlink( $css_file );
			}
		}
		$elementor_tmp = rtrim( $uploads_dir, '/' ) . '/elementor/tmp/';
		if ( is_dir( $elementor_tmp ) ) {
			wpcm_recursive_delete( $elementor_tmp );
		}
		$rocket_cache = rtrim( $CFG['wp_content_dir'] ?? '', '/' ) . '/cache/wp-rocket/';
		if ( is_dir( $rocket_cache ) ) {
			wpcm_recursive_delete( $rocket_cache );
		}
		$w3_cache = rtrim( $CFG['wp_content_dir'] ?? '', '/' ) . '/cache/page_enhanced/';
		if ( is_dir( $w3_cache ) ) {
			wpcm_recursive_delete( $w3_cache );
		}
	}

	// Cleanup session directory (also deletes installer_config.json → installer becomes inert)
	if ( is_dir( $wpcm_session_dir ) ) {
		wpcm_recursive_delete( $wpcm_session_dir );
	}

	// Lift maintenance mode — all data is in place
	wpcm_disable_maintenance_mode( $CFG['abspath'] ?? '' );

	return array(
		'next_step'           => null,
		'progress'            => 100,
		'message'             => 'Migration completed successfully.',
		'deactivated_plugins' => $deactivated_list,
	);
}

// ============================================================
// URL replacement helpers
// ============================================================
function wpcm_build_url_pairs( $old_url, $new_url, $old_path, $new_path ) {
	$old_url   = rtrim( $old_url, '/' );
	$new_url   = rtrim( $new_url, '/' );
	$pairs     = array();
	$old_https = str_replace( 'http://', 'https://', $old_url );
	$old_http  = str_replace( 'https://', 'http://', $old_url );

	$pairs[] = array( $old_https, $new_url );
	if ( $old_http !== $old_https ) {
		$pairs[] = array( $old_http, $new_url );
	}

	$old_rel = preg_replace( '#^https?://#', '//', $old_https );
	$new_rel = preg_replace( '#^https?://#', '//', $new_url );
	if ( $old_rel !== $new_rel ) {
		$pairs[] = array( $old_rel, $new_rel );
	}

	$slash_esc = function ( $u ) {
		return str_replace( '/', '\\/', $u );
	};
	$pairs[] = array( $slash_esc( $old_https ), $slash_esc( $new_url ) );
	if ( $old_http !== $old_https ) {
		$pairs[] = array( $slash_esc( $old_http ), $slash_esc( $new_url ) );
	}

	$old_enc = rawurlencode( $old_https );
	$new_enc = rawurlencode( $new_url );
	if ( $old_enc !== $new_enc ) {
		$pairs[] = array( $old_enc, $new_enc );
	}

	if ( $old_path && $new_path ) {
		$old_path = rtrim( $old_path, '/' );
		$new_path = rtrim( $new_path, '/' );
		if ( $old_path !== $new_path ) {
			$pairs[] = array( $old_path, $new_path );
			$pairs[] = array( $slash_esc( $old_path ), $slash_esc( $new_path ) );
		}
	}

	$seen  = array();
	$clean = array();
	foreach ( $pairs as $p ) {
		if ( $p[0] === $p[1] || isset( $seen[ $p[0] ] ) ) {
			continue;
		}
		$seen[ $p[0] ] = true;
		$clean[]       = $p;
	}
	return $clean;
}

function wpcm_replace_in_table_sliced( $mysqli, $table, $text_cols, $primary, $col_maxlen, $pairs, $start_offset, $time_budget, $start_time ) {
	$stats = array( 'rows' => 0, 'cells' => 0, 'serialized' => 0, 'table_done' => true, 'next_offset' => 0 );
	if ( empty( $text_cols ) || ! $primary ) {
		return $stats;
	}

	$batch          = 20;
	$offset         = $start_offset;
	$search_strings = array_column( $pairs, 0 );

	do {
		$select_cols = '`' . $primary . '`, `' . implode( '`, `', $text_cols ) . '`';
		$result      = $mysqli->query( "SELECT {$select_cols} FROM `{$table}` LIMIT {$offset}, {$batch}" );
		if ( ! $result ) {
			break;
		}
		$row_count = $result->num_rows;

		while ( $row = $result->fetch_assoc() ) {
			$updates = array();
			foreach ( $text_cols as $col ) {
				if ( empty( $row[ $col ] ) ) {
					continue;
				}
				$val   = $row[ $col ];
				$found = false;
				foreach ( $search_strings as $s ) {
					if ( strpos( $val, $s ) !== false ) { $found = true; break; }
				}
				if ( ! $found ) {
					continue;
				}
				$new_val = wpcm_replace_value( $val, $pairs, $stats );
				if ( $new_val === $val ) {
					continue;
				}
				$max = $col_maxlen[ $col ] ?? PHP_INT_MAX;
				if ( $max < PHP_INT_MAX && strlen( $new_val ) > $max ) {
					continue;
				}
				$updates[ $col ] = $new_val;
				$stats['cells']++;
			}

			if ( ! empty( $updates ) ) {
				$set_parts = array();
				foreach ( $updates as $col => $val ) {
					$set_parts[] = "`{$col}` = '" . $mysqli->real_escape_string( $val ) . "'";
				}
				$pk_val = $mysqli->real_escape_string( $row[ $primary ] );
				$res    = $mysqli->query(
					'UPDATE `' . $table . '` SET ' . implode( ', ', $set_parts )
					. " WHERE `{$primary}` = '{$pk_val}'"
				);
				if ( $res === false ) {
					$up_errno = $mysqli->errno;
					if ( $up_errno === 1406 && count( $updates ) > 1 ) {
						foreach ( $updates as $col => $val ) {
							$mysqli->query( "UPDATE `{$table}` SET `{$col}` = '" . $mysqli->real_escape_string( $val ) . "' WHERE `{$primary}` = '{$pk_val}'" );
						}
					} elseif ( $up_errno === 1062 ) {
						foreach ( $updates as $col => $new_val ) {
							$esc_nv     = $mysqli->real_escape_string( $new_val );
							$conflict_r = $mysqli->query( "SELECT `{$primary}` FROM `{$table}` WHERE `{$col}` = '{$esc_nv}' AND `{$primary}` != '{$pk_val}' LIMIT 1" );
							if ( $conflict_r && $conflict_r->num_rows > 0 ) {
								$crow = $conflict_r->fetch_row();
								$c_pk = $mysqli->real_escape_string( $crow[0] );
								$mysqli->query( "DELETE FROM `{$table}` WHERE `{$primary}` = '{$c_pk}'" );
								$conflict_r->free();
								$mysqli->query( 'UPDATE `' . $table . '` SET ' . implode( ', ', $set_parts ) . " WHERE `{$primary}` = '{$pk_val}'" );
								break;
							}
							if ( $conflict_r ) {
								$conflict_r->free();
							}
						}
					}
				}
				$stats['rows']++;
			}
		}

		$result->free();
		$offset += $batch;

		if ( ( microtime( true ) - $start_time ) > $time_budget ) {
			if ( $row_count === $batch ) {
				$stats['table_done']  = false;
				$stats['next_offset'] = $offset;
			}
			break;
		}
	} while ( $row_count === $batch );

	return $stats;
}

function wpcm_replace_value( $val, $pairs, &$stats ) {
	if ( preg_match( '/^[aObids]:/', $val ) ) {
		$unserialized = @unserialize( $val, array( 'allowed_classes' => false ) );
		if ( $unserialized !== false || $val === 'b:0;' ) {
			$stats['serialized']++;
			if ( wpcm_has_incomplete_class( $unserialized ) ) {
				return wpcm_replace_serialized_string( $val, $pairs );
			}
			$replaced = wpcm_replace_recursive( $unserialized, $pairs );
			return serialize( $replaced );
		}
	}

	if ( $val !== '' && ( $val[0] === '{' || $val[0] === '[' ) ) {
		$json = json_decode( $val, true );
		if ( $json !== null ) {
			$replaced = wpcm_replace_recursive( $json, $pairs );
			$encoded  = json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return $encoded !== false ? $encoded : wpcm_str_replace_pairs( $val, $pairs );
		}
	}

	return wpcm_str_replace_pairs( $val, $pairs );
}

function wpcm_has_incomplete_class( $data ) {
	if ( $data instanceof __PHP_Incomplete_Class ) {
		return true;
	}
	if ( is_array( $data ) ) {
		foreach ( $data as $v ) {
			if ( wpcm_has_incomplete_class( $v ) ) {
				return true;
			}
		}
	}
	return false;
}

function wpcm_replace_serialized_string( $val, $pairs ) {
	foreach ( $pairs as $pair ) {
		if ( strpos( $val, $pair[0] ) !== false ) {
			$val = str_replace( $pair[0], $pair[1], $val );
		}
	}

	$out   = '';
	$pos   = 0;
	$total = strlen( $val );

	while ( $pos < $total ) {
		if ( $val[ $pos ] === 's' && $pos + 3 < $total && $val[ $pos + 1 ] === ':' ) {
			$ni = $pos + 2;
			$ne = $ni;
			while ( $ne < $total && $val[ $ne ] >= '0' && $val[ $ne ] <= '9' ) {
				$ne++;
			}
			if ( $ne > $ni && $ne + 1 < $total && $val[ $ne ] === ':' && $val[ $ne + 1 ] === '"' ) {
				$old_n     = (int) substr( $val, $ni, $ne - $ni );
				$str_start = $ne + 2;
				$exp_end   = $str_start + $old_n;

				if ( $exp_end + 2 <= $total && $val[ $exp_end ] === '"' && $val[ $exp_end + 1 ] === ';' ) {
					$out .= substr( $val, $pos, $exp_end + 2 - $pos );
					$pos  = $exp_end + 2;
					continue;
				}

				$found = false;
				$lo    = max( 0, $old_n - 500 );
				$hi    = $old_n + 500;
				for ( $delta = 0; $delta <= ( $hi - $lo ); $delta++ ) {
					foreach ( array( $old_n + $delta, $old_n - $delta ) as $try_n ) {
						if ( $try_n < 0 ) {
							continue;
						}
						$try_end = $str_start + $try_n;
						if ( $try_end + 2 <= $total && $val[ $try_end ] === '"' && $val[ $try_end + 1 ] === ';' ) {
							$content = substr( $val, $str_start, $try_n );
							$out    .= 's:' . $try_n . ':"' . $content . '";';
							$pos     = $try_end + 2;
							$found   = true;
							break 2;
						}
					}
					if ( $delta === 0 ) {
						break;
					}
				}
				if ( $found ) {
					continue;
				}
			}
		}
		$out .= $val[ $pos ];
		$pos++;
	}

	return $out;
}

function wpcm_replace_recursive( $data, $pairs ) {
	if ( is_string( $data ) ) {
		if ( preg_match( '/^[aObids]:/', $data ) ) {
			$nested = @unserialize( $data, array( 'allowed_classes' => false ) );
			if ( $nested !== false || $data === 'b:0;' ) {
				if ( wpcm_has_incomplete_class( $nested ) ) {
					return wpcm_replace_serialized_string( $data, $pairs );
				}
				return serialize( wpcm_replace_recursive( $nested, $pairs ) );
			}
		}
		if ( $data !== '' && ( $data[0] === '{' || $data[0] === '[' ) ) {
			$json = json_decode( $data, true );
			if ( $json !== null ) {
				$replaced = wpcm_replace_recursive( $json, $pairs );
				$encoded  = json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				return $encoded !== false ? $encoded : wpcm_str_replace_pairs( $data, $pairs );
			}
		}
		return wpcm_str_replace_pairs( $data, $pairs );
	}
	if ( is_array( $data ) ) {
		$result = array();
		foreach ( $data as $k => $v ) {
			$new_k            = is_string( $k ) ? wpcm_str_replace_pairs( $k, $pairs ) : $k;
			$result[ $new_k ] = wpcm_replace_recursive( $v, $pairs );
		}
		return $result;
	}
	if ( is_object( $data ) ) {
		foreach ( get_object_vars( $data ) as $k => $v ) {
			$data->$k = wpcm_replace_recursive( $v, $pairs );
		}
		return $data;
	}
	return $data;
}

function wpcm_str_replace_pairs( $str, $pairs ) {
	foreach ( $pairs as $pair ) {
		$str = str_replace( $pair[0], $pair[1], $str );
	}
	return $str;
}

// ============================================================
// Filesystem helpers
// ============================================================
function wpcm_find_subdir( $dir, $name ) {
	if ( is_dir( $dir . $name . '/' ) ) {
		return $dir . $name . '/';
	}
	foreach ( glob( $dir . '*/', GLOB_ONLYDIR ) as $sub ) {
		if ( is_dir( $sub . $name . '/' ) ) {
			return $sub . $name . '/';
		}
	}
	return null;
}

function wpcm_copy_recursive( $src, $dst ) {
	if ( ! is_dir( $src ) ) {
		return;
	}
	@mkdir( $dst, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone context
	$real_src = realpath( $src );
	if ( ! $real_src ) {
		return;
	}
	$len = strlen( $real_src );
	$it  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $it as $item ) {
		$real = $item->getRealPath();
		if ( ! $real ) {
			continue;
		}
		$rel    = substr( $real, $len );
		$target = $dst . $rel;
		if ( $item->isDir() ) {
			@mkdir( $target, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone context
		} else {
			$dir_t = dirname( $target );
			if ( ! is_dir( $dir_t ) ) {
				@mkdir( $dir_t, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone context
			}
			copy( $real, $target );
		}
	}
}

function wpcm_recursive_delete( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $item ) {
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone context
			@rmdir( $item->getRealPath() );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone context
			@unlink( $item->getRealPath() );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone context
	@rmdir( $dir );
}
