<?php
/**
 * Exporter — Creates a full clone archive of the WordPress site
 *
 * Now with granular sub-steps to avoid timeouts:
 *
 * 1. init           → Create session, detect environment
 * 2. database       → Dump all tables in chunks
 * 3. files_scan     → Scan directories, build file list, plan sub-steps
 * 4. files_archive  → Archive ONE directory per AJAX call (each theme, each plugin,
 *                      each uploads/YYYY/MM subfolder). Called repeatedly.
 * 5. config         → Capture root config files (no wp-config.php), options, meta
 * 6. package        → Bundle everything into final ZIP
 * 7. cleanup        → Remove temp files
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DEVELOPER NOTE — esc_sql() + remove_placeholder_escape() in SQL dump (step 2)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * CONTEXT
 * Since WordPress 4.8.3, esc_sql() replaces every bare '%' character with an
 * internal sha256-based placeholder token to prevent a class of SQLi attacks via
 * wpdb::prepare(). WordPress strips that placeholder automatically just before
 * calling mysqli_query(), so normal in-memory queries are unaffected.
 *
 * THE BUG THIS CAUSED
 * In step_database(), row values are escaped with esc_sql() and then written
 * directly to .sql files on disk — they never pass through wpdb::query(). As a
 * result the placeholder was persisted as-is in the dump:
 *
 *   DB value         : /%postname%/
 *   Buggy dump       : '/{b760cf9cc981…}postname{b760cf9cc981…}/'   ← corrupt
 *   Expected dump    : '/%postname%/'                                 ← correct
 *
 * This broke WordPress permalinks on import, and also corrupted CSS stored in
 * the database (e.g. Elementor / Bricks inline styles with `width:100%`).
 *
 * THE FIX
 * Wrap esc_sql() with $wpdb->remove_placeholder_escape() before writing to file.
 * This is the official, public WP Core API for this exact scenario, documented at:
 * https://make.wordpress.org/core/2017/10/31/changed-behaviour-of-esc_sql/
 *
 *   return "'" . $wpdb->remove_placeholder_escape( esc_sql( $v ) ) . "'";
 *
 * esc_sql() is still needed (and must stay): it handles backslash-escaping of
 * single quotes, backslashes, and NUL bytes, which are mandatory for a valid SQL
 * file. remove_placeholder_escape() only undoes the % substitution step — it does
 * not remove the other escaping.
 *
 * WHY NOT THE ALTERNATIVES
 *   $wpdb->dbh->real_escape_string()  — private wpdb property, rejected by the
 *                                        WordPress Plugin Review Team (wp.org).
 *   mysqli_real_escape_string()        — raw mysqli call, bypasses WP abstraction.
 *   addslashes()                       — explicitly forbidden by WP Coding Standards.
 *
 * DO NOT "SIMPLIFY" THIS IN FUTURE
 * If you are tempted to replace the remove_placeholder_escape( esc_sql( $v ) )
 * pattern with a bare esc_sql( $v ), the % corruption bug WILL return. The two
 * calls are not interchangeable when writing to a file instead of a query.
 * ─────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPCM_Exporter {

    private $session_dir;
    private $session_id;
    private $manifest;

    public function run_step( $step, $session_id = '' ) {
        if ( $step === 'init' ) {
            return $this->step_init();
        }

        if ( ! $session_id ) throw new Exception( __( 'Missing session ID', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser

        $this->session_id  = $session_id;
        $this->session_dir = WPCM_TEMP_DIR . $session_id . '/';

        if ( ! is_dir( $this->session_dir ) ) {
            throw new Exception( __( 'Session not found: ', 'clone-master' ) . $session_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        $this->manifest = json_decode( file_get_contents( $this->session_dir . 'manifest.json' ), true );
        if ( ! $this->manifest ) {
            throw new Exception( __( 'Corrupt manifest file', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        switch ( $step ) {
            case 'database':      return $this->step_database();
            case 'files_scan':    return $this->step_files_scan();
            case 'files_archive': return $this->step_files_archive();
            case 'config':        return $this->step_config();
            case 'package':       return $this->step_package();
            case 'cleanup':       return $this->step_cleanup();
            default:              throw new Exception( __( 'Unknown step: ', 'clone-master' ) . $step ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }
    }

    // =========================================================================
    // Step 1: Initialize session
    // =========================================================================
    private function step_init() {
        $this->session_id  = 'export_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 16, false );
        $this->session_dir = WPCM_TEMP_DIR . $this->session_id . '/';

        wp_mkdir_p( $this->session_dir );
        wp_mkdir_p( $this->session_dir . 'sql/' );
        wp_mkdir_p( $this->session_dir . 'files/' );
        // Protect session subdirectory immediately — the parent wpcm-temp/.htaccess
        // may not propagate to subdirs on hosts with AllowOverride None.
        WPCM_Plugin::protect_directory( $this->session_dir );

        $detector = new WPCM_Server_Detector();
        $server_info = $detector->get_info();

        $this->manifest = [
            'wpcm_version'   => WPCM_VERSION,
            'created_at'     => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
            'site_url'       => site_url(),
            'home_url'       => home_url(),
            'abspath'        => ABSPATH,
            'content_dir'    => WP_CONTENT_DIR,
            'uploads_dir'    => wp_upload_dir()['basedir'],
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => phpversion(),
            'db_prefix'      => $GLOBALS['wpdb']->prefix,
            'db_charset'     => $GLOBALS['wpdb']->charset,
            'db_collate'     => $GLOBALS['wpdb']->collate,
            'multisite'      => is_multisite(),
            'server_info'    => $server_info,
            'active_plugins' => get_option( 'active_plugins', [] ),
            'active_theme'   => get_stylesheet(),
            'template'       => get_template(),
            'permalink'      => get_option( 'permalink_structure' ),
            'steps_done'     => [],
            'checksums'      => [],
            'file_queue'     => [],
            'file_queue_idx' => 0,
            'archives'       => [],
            'root_files'     => [],
        ];

        $this->save_manifest();

        return [
            'session_id'  => $this->session_id,
            'server_info' => $server_info,
            'next_step'   => 'database',
            'progress'    => 5,
            'message'     => __( 'Session initialized. Exporting database...', 'clone-master' ),
        ];
    }

    // =========================================================================
    // Step 2: Export database — chunked + ADAPTIVE throttle
    //
    // ARCHITECTURE
    // ─────────────────────────────────────────────────────────────────────────
    // Each AJAX call processes exactly `rows_per_call` rows, then returns
    // next_step=>'database' so the JS loop fires immediately. A cursor stored
    // in a transient carries table_idx, offset, rows_per_call, and timing data
    // across calls.
    //
    // ADAPTIVE THROTTLE (3 independent feedback loops per call)
    // ─────────────────────────────────────────────────────────────────────────
    // 1. TIME LOOP   — measures wall-clock duration of the previous call.
    //                  • t > budget×1.0  → ÷1.5  (too slow, risk of timeout)
    //                  • t < budget×0.5  → ×1.3  (spare capacity, go faster)
    //                  Target budget = min(max_execution_time × 0.55, 20s, 25s)
    //                  Falls back to 12s when max_execution_time = 0 (no limit).
    //
    // 2. MEMORY LOOP — checks memory_get_peak_usage() after each call.
    //                  • peak > memory_limit × 0.70 → ÷2  (pressure)
    //                  • peak < memory_limit × 0.35 → ×1.2 (headroom)
    //
    // 3. ROW-SIZE PROBE — on the very first call for each table, samples 10
    //                  rows to estimate avg bytes/row (post-escaping). Used to
    //                  upper-bound rows_per_call so a single INSERT batch never
    //                  approaches max_allowed_packet (capped at 90% of it).
    //
    // CLAMP: rows_per_call is always kept in [ROWS_MIN, ROWS_MAX].
    //
    // IMPORT COMPATIBILITY
    // ─────────────────────────────────────────────────────────────────────────
    // Output format is identical to the previous fixed-chunk version:
    // one .sql file per table, same INSERT IGNORE syntax, same header/footer.
    // The standalone installer.php is completely unaffected.
    // =========================================================================

    /** Hard lower bound — prevents infinite loops on near-empty tables. */
    const WPCM_DB_ROWS_MIN = 25;

    /** Hard upper bound — safeguard against max_allowed_packet overflows. */
    const WPCM_DB_ROWS_MAX = 2000;

    /** Starting value before the first timing measurement is available. */
    const WPCM_DB_ROWS_INITIAL = 200;

    /** Rows per INSERT INTO … VALUES (…),(…),… statement (unchanged). */
    const WPCM_DB_INSERT_CHUNK = 100;

    // ── Adaptive throttle helpers ─────────────────────────────────────────────

    /**
     * Compute the time budget (seconds) for one AJAX call on this server.
     *
     * We target 55% of max_execution_time so the call finishes well before
     * PHP's hard limit. Apache/nginx proxy timeouts (typically 30-60 s) are
     * an additional ceiling, so we cap at 20 s regardless.
     * When max_execution_time = 0 (unlimited — rare on shared hosting, common
     * on CLI/VPS), we default to 12 s as a conservative baseline.
     *
     * @return float Target seconds per AJAX call.
     */
    private function db_time_budget() {
        $max = (int) ini_get( 'max_execution_time' );
        if ( $max <= 0 ) return 12.0;          // unlimited → conservative default
        return min( $max * 0.55, 20.0 );
    }

    /**
     * Probe avg bytes-per-row for a table by sampling up to 10 rows.
     * Returns 0 when the table is empty (caller must guard against division).
     *
     * @param string $safe_table Escaped table name (via esc_sql).
     * @return int Estimated average row size in bytes, post-escaping overhead.
     */
    private function db_probe_row_size( $safe_table ) {
        global $wpdb;
        $sample = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- sampling; escaped via esc_sql()
            "SELECT * FROM `{$safe_table}` LIMIT 10",
            ARRAY_A
        );
        if ( ! $sample ) return 0;
        $total = 0;
        foreach ( $sample as $row ) {
            // Rough estimate of INSERT text size: key names + values + overhead
            $total += array_sum( array_map( 'strlen', array_keys( $row ) ) )
                    + array_sum( array_map( function( $v ) { return $v === null ? 4 : strlen( (string) $v ) * 2 + 3; }, $row ) )
                    + 30; // per-value punctuation overhead
        }
        return (int) ( $total / count( $sample ) );
    }

    /**
     * Compute max_allowed_packet from MySQL (bytes).
     * Returns a safe fallback of 1 MB when the query fails.
     *
     * @return int max_allowed_packet in bytes.
     */
    private function db_max_allowed_packet() {
        global $wpdb;
        $val = $wpdb->get_var( "SELECT @@max_allowed_packet" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live server variable, caching would miss runtime changes
        return $val ? (int) $val : 1 * 1024 * 1024;
    }

    /**
     * Apply the three adaptive-throttle feedback loops and return the new
     * rows_per_call value (clamped to [ROWS_MIN, ROWS_MAX]).
     *
     * @param int   $rows_per_call   Current value.
     * @param float $t_real          Measured duration of the previous call (seconds).
     * @param int   $mem_peak        Peak memory usage of the previous call (bytes).
     * @param int   $avg_row_bytes   Estimated row size from the probe (bytes; 0 = unknown).
     * @param int   $max_packet      MySQL max_allowed_packet (bytes).
     * @return int Adjusted rows_per_call.
     */
    private function db_adapt_rows( $rows_per_call, $t_real, $mem_peak, $avg_row_bytes, $max_packet ) {
        $budget = $this->db_time_budget();

        // ── Loop 1: TIME ─────────────────────────────────────────────────────
        if ( $t_real > $budget ) {
            $rows_per_call = (int) ( $rows_per_call / 1.5 );
        } elseif ( $t_real < $budget * 0.5 ) {
            $rows_per_call = (int) ( $rows_per_call * 1.3 );
        }

        // ── Loop 2: MEMORY ───────────────────────────────────────────────────
        $mem_limit = $this->return_bytes_local( ini_get( 'memory_limit' ) );
        if ( $mem_limit > 0 ) {
            if ( $mem_peak > $mem_limit * 0.70 ) {
                $rows_per_call = (int) ( $rows_per_call / 2 );
            } elseif ( $mem_peak < $mem_limit * 0.35 && $t_real < $budget * 0.5 ) {
                // Only grow on memory headroom when time also has room.
                $rows_per_call = (int) ( $rows_per_call * 1.2 );
            }
        }

        // ── Loop 3: ROW-SIZE vs max_allowed_packet ────────────────────────────
        // Ensure one INSERT batch (INSERT_CHUNK rows) never exceeds 90% of max_packet.
        if ( $avg_row_bytes > 0 && $max_packet > 0 ) {
            $packet_cap = (int) ( ( $max_packet * 0.90 ) / $avg_row_bytes );
            if ( $packet_cap < $rows_per_call ) {
                $rows_per_call = $packet_cap;
            }
        }

        // ── Clamp ─────────────────────────────────────────────────────────────
        return max( self::WPCM_DB_ROWS_MIN, min( self::WPCM_DB_ROWS_MAX, $rows_per_call ) );
    }

    /**
     * Tiny local byte-string parser (mirrors WPCM_Server_Detector::return_bytes).
     * Avoids instantiating the detector just to parse memory_limit.
     *
     * @param string $val PHP ini string e.g. "256M", "1G", "-1".
     * @return int Bytes, or 0 for "-1" (unlimited).
     */
    private function return_bytes_local( $val ) {
        $val  = trim( (string) $val );
        $last = strtolower( $val[ strlen( $val ) - 1 ] ?? '' );
        $int  = (int) $val;
        if ( $int < 0 ) return 0; // -1 = unlimited → treat as "no limit"
        switch ( $last ) {
            case 'g': $int *= 1024; // fall through
            case 'm': $int *= 1024; // fall through
            case 'k': $int *= 1024;
        }
        return $int;
    }

    // ── Main step ─────────────────────────────────────────────────────────────

    private function step_database() {
        global $wpdb;

        $sql_dir    = $this->session_dir . 'sql/';
        $cursor_key = 'wpcm_db_cursor_' . $this->session_id;

        // ── Restore or initialise the cursor ──────────────────────────────────
        $state = get_transient( $cursor_key );

        if ( ! $state ) {
            // First call: build table list, write SQL header, init adaptive state.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full export requires live table list; caching would miss recently created tables
            $tables = $wpdb->get_col(
                $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix ) . '%' )
            );

            $header  = "-- Clone Master Database Export\n";
            $header .= "-- Date: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
            $header .= "-- Site: " . site_url() . "\n";
            $header .= "-- Prefix: {$wpdb->prefix}\n";
            $header .= "-- MySQL: " . $wpdb->db_version() . "\n";
            $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $header .= "SET NAMES utf8mb4;\n";
            $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            file_put_contents( $sql_dir . '00_header.sql', $header, LOCK_EX );

            $state = [
                'tables'         => $tables,
                'table_idx'      => 0,
                'offset'         => 0,
                'current_count'  => 0,
                'table_info'     => [],
                // Adaptive throttle state
                'rows_per_call'  => self::WPCM_DB_ROWS_INITIAL,
                't_prev'         => 0.0,   // duration of previous call (seconds)
                'mem_prev'       => 0,     // peak memory of previous call (bytes)
                'avg_row_bytes'  => 0,     // estimated row size for current table
                'max_packet'     => 0,     // MySQL max_allowed_packet (fetched once)
            ];
        }

        // ── Unpack cursor ─────────────────────────────────────────────────────
        $tables        = $state['tables'];
        $table_idx     = (int)   $state['table_idx'];
        $offset        = (int)   $state['offset'];
        $table_info    = $state['table_info'];
        $total_tables  = count( $tables );
        $rows_per_call = (int)   $state['rows_per_call'];
        $t_prev        = (float) $state['t_prev'];
        $mem_prev      = (int)   $state['mem_prev'];
        $avg_row_bytes = (int)   $state['avg_row_bytes'];
        $max_packet    = (int)   $state['max_packet'];

        // ── Fetch max_allowed_packet once per session ─────────────────────────
        if ( $max_packet === 0 ) {
            $max_packet = $this->db_max_allowed_packet();
        }

        // ── Apply adaptive throttle from previous call's measurements ─────────
        if ( $t_prev > 0 ) {
            $rows_per_call = $this->db_adapt_rows(
                $rows_per_call, $t_prev, $mem_prev, $avg_row_bytes, $max_packet
            );
        }

        // ── All tables done? Finalise immediately. ─────────────────────────────
        if ( $table_idx >= $total_tables ) {
            delete_transient( $cursor_key );
            return $this->finish_database_step( $table_info, $sql_dir, $total_tables );
        }

        // ── Process current table ─────────────────────────────────────────────
        $table      = $tables[ $table_idx ];
        $safe_table = esc_sql( $table );
        $filename   = sprintf( '%02d_%s.sql', $table_idx + 1, $table );
        $filepath   = $sql_dir . $filename;

        // On first visit to this table: write CREATE TABLE DDL, count rows,
        // and run the row-size probe so Loop 3 can cap rows_per_call correctly.
        if ( $offset === 0 ) {
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$safe_table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from SHOW TABLES, escaped via esc_sql()
            $ddl    = '';
            if ( $create ) {
                $ddl .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $ddl .= $create[1] . ";\n\n";
            }
            file_put_contents( $filepath, $ddl, LOCK_EX );

            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from SHOW TABLES, escaped via esc_sql()
            $state['current_count'] = $count;

            // Row-size probe: sample up to 10 rows and re-apply Loop 3 cap now.
            $avg_row_bytes = $this->db_probe_row_size( $safe_table );
            if ( $avg_row_bytes > 0 && $max_packet > 0 ) {
                $packet_cap    = (int) ( ( $max_packet * 0.90 ) / $avg_row_bytes );
                $rows_per_call = max( self::WPCM_DB_ROWS_MIN, min( $rows_per_call, $packet_cap, self::WPCM_DB_ROWS_MAX ) );
            }
        } else {
            $count = (int) $state['current_count'];
        }

        // ── Start timing this call ─────────────────────────────────────────────
        $t_start  = microtime( true );
        $mem_before = memory_get_peak_usage( true );

        // ── Fetch and write rows ──────────────────────────────────────────────
        if ( $count > 0 ) {
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from SHOW TABLES, escaped via esc_sql()
                "SELECT * FROM `{$safe_table}` LIMIT {$offset}, {$rows_per_call}",
                ARRAY_A
            );

            if ( $rows ) {
                $cols       = array_map( function( $c ) { return "`{$c}`"; }, array_keys( $rows[0] ) );
                $cols_str   = implode( ',', $cols );
                $sql        = '';
                $row_values = [];

                foreach ( $rows as $row ) {
                    $values = array_map( function( $v ) use ( $wpdb ) {
                        if ( $v === null ) return 'NULL';
                        /*
                         * WHY remove_placeholder_escape() IS REQUIRED HERE
                         * ─────────────────────────────────────────────────
                         * Since WordPress 4.8.3 (security hardening against SQLi via
                         * wpdb::prepare), esc_sql() no longer returns a plain escaped
                         * string. Instead, every bare '%' in the value is silently
                         * replaced by an internal sha256-based placeholder token:
                         *
                         *   esc_sql('/%postname%/') → '/{b760…}postname{b760…}/'
                         *
                         * WordPress removes that placeholder automatically right before
                         * calling mysqli_query() — so for normal in-memory queries the
                         * round-trip is invisible. But here we are writing the escaped
                         * value straight to a .sql FILE. The placeholder is therefore
                         * never restored, and the dump ends up with corrupt data:
                         *
                         *   permalinks : /%postname%/  →  /{b760…}postname{b760…}/
                         *   CSS        : width:100%    →  width:100{b760…}
                         *   LIKE masks : location_%    →  location_{b760…}
                         *
                         * $wpdb->remove_placeholder_escape() is the official, public
                         * WP Core API for exactly this situation (documented on
                         * make.wordpress.org/core/2017/10/31/changed-behaviour-of-esc_sql).
                         * It must wrap esc_sql(), NOT replace it: esc_sql() still
                         * handles backslash-escaping of quotes, backslashes, and NUL
                         * bytes, which we need for a valid SQL file.
                         *
                         * phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                         * -- value is escaped via esc_sql(); placeholder removed before
                         *    file write, not before a wpdb query. This is the correct
                         *    pattern for SQL dump generation outside of wpdb::query().
                         */
                        return "'" . $wpdb->remove_placeholder_escape( esc_sql( $v ) ) . "'";
                    }, array_values( $row ) );
                    $row_values[] = '(' . implode( ',', $values ) . ')';

                    if ( count( $row_values ) >= self::WPCM_DB_INSERT_CHUNK ) {
                        $sql .= "INSERT IGNORE INTO `{$safe_table}` ({$cols_str}) VALUES\n  "
                              . implode( ",\n  ", $row_values ) . ";\n";
                        $row_values = [];
                    }
                }
                if ( ! empty( $row_values ) ) {
                    $sql .= "INSERT IGNORE INTO `{$safe_table}` ({$cols_str}) VALUES\n  "
                          . implode( ",\n  ", $row_values ) . ";\n";
                }

                file_put_contents( $filepath, $sql, FILE_APPEND | LOCK_EX );
            }
        }

        // ── Measure this call ─────────────────────────────────────────────────
        $t_real   = microtime( true ) - $t_start;
        $mem_peak = max( 0, memory_get_peak_usage( true ) - $mem_before );

        $offset += $rows_per_call;

        // ── Table finished? Advance to the next one. ──────────────────────────
        if ( $offset >= $count ) {
            $table_info[] = [
                'name' => $table,
                'rows' => $count,
                'file' => $filename,
            ];
            $table_idx++;
            $offset        = 0;
            $avg_row_bytes = 0; // Will be re-probed on next table's first call.
            unset( $state['current_count'] );
        }

        // ── Persist cursor with updated adaptive state ────────────────────────
        $state['table_idx']     = $table_idx;
        $state['offset']        = $offset;
        $state['table_info']    = $table_info;
        $state['rows_per_call'] = $rows_per_call;
        $state['t_prev']        = round( $t_real, 4 );
        $state['mem_prev']      = $mem_peak;
        $state['avg_row_bytes'] = $avg_row_bytes;
        $state['max_packet']    = $max_packet;

        // Check again: did we just finish the last table?
        if ( $table_idx >= $total_tables ) {
            delete_transient( $cursor_key );
            return $this->finish_database_step( $table_info, $sql_dir, $total_tables );
        }

        // Transient TTL: 2 hours — enough for any realistic export.
        set_transient( $cursor_key, $state, 2 * HOUR_IN_SECONDS );

        // Progress: database export occupies 10 %→25 % of the total bar.
        $db_progress         = $total_tables > 0 ? (int) ( 15 * $table_idx / $total_tables ) : 15;
        $current_table_label = $tables[ $table_idx ] ?? '';

        return [
            'session_id'   => $this->session_id,
            'next_step'    => 'database',
            'progress'     => 10 + $db_progress,
            'rows_per_call' => $rows_per_call,  // exposed for the UI detail panel
            /* translators: 1: completed table count, 2: total table count, 3: current table name */
            'message'      => sprintf(
                __( 'Database: table %1$d/%2$d — exporting %3$s…', 'clone-master' ),
                $table_idx,
                $total_tables,
                $current_table_label
            ),
        ];
    }

    /**
     * Finalise the database export step: write the SQL footer, update the
     * manifest, and hand off to the files_scan step.
     *
     * Called by step_database() when all tables have been processed.
     *
     * @param array  $table_info Accumulated table metadata.
     * @param string $sql_dir    Path to the sql/ subdirectory.
     * @param int    $total      Total number of tables exported.
     * @return array Next-step payload for the AJAX response.
     */
    private function finish_database_step( $table_info, $sql_dir, $total ) {
        file_put_contents( $sql_dir . '99_footer.sql', "\nSET FOREIGN_KEY_CHECKS = 1;\n", LOCK_EX );

        $this->manifest['tables']       = $table_info;
        $this->manifest['steps_done'][] = 'database';
        $this->save_manifest();

        return [
            'session_id'   => $this->session_id,
            'tables_count' => $total,
            'next_step'    => 'files_scan',
            'progress'     => 25,
            /* translators: %d = number of tables */
            'message'      => sprintf( _n( '%d table exported. Scanning files…', '%d tables exported. Scanning files…', $total, 'clone-master' ), $total ),
        ];
    }

    // =========================================================================
    // Step 3: Scan files — build a QUEUE of small archive jobs
    // =========================================================================
    private function step_files_scan() {
        $queue = [];
        $single_plugins = [];

        // --- Themes: one job per theme ---
        $theme_root = get_theme_root();
        if ( is_dir( $theme_root ) ) {
            foreach ( glob( $theme_root . '/*', GLOB_ONLYDIR ) as $theme_dir ) {
                $name = basename( $theme_dir );
                $queue[] = [
                    'key'      => 'themes/' . $name,
                    'source'   => $theme_dir,
                    'zip_name' => 'themes_' . sanitize_file_name( $name ) . '.zip',
                    'label'    => 'Theme: ' . $name,
                    'type'     => 'dir',
                ];
            }
        }

        // --- Plugins: one job per plugin folder ---
        if ( is_dir( WP_PLUGIN_DIR ) ) {
            $items = @scandir( WP_PLUGIN_DIR );
            if ( $items ) {
                foreach ( $items as $item ) {
                    if ( $item === '.' || $item === '..' ) continue;
                    if ( in_array( $item, [ 'clone-master', 'wpcm-backups', 'wpcm-temp', 'wpcm-logs' ], true ) ) continue;

                    $path = WP_PLUGIN_DIR . '/' . $item;
                    if ( is_dir( $path ) ) {
                        $queue[] = [
                            'key'      => 'plugins/' . $item,
                            'source'   => $path,
                            'zip_name' => 'plugins_' . sanitize_file_name( $item ) . '.zip',
                            'label'    => 'Plugin: ' . $item,
                            'type'     => 'dir',
                        ];
                    } else {
                        $single_plugins[] = $path;
                    }
                }
            }
            if ( ! empty( $single_plugins ) ) {
                $queue[] = [
                    'key'      => 'plugins/_single_files',
                    'source'   => $single_plugins,
                    'zip_name' => 'plugins__single_files.zip',
                    'label'    => 'Plugin single files (' . count( $single_plugins ) . ')',
                    'type'     => 'file_list',
                ];
            }
        }

        // --- Uploads: split by YEAR/MONTH for safety ---
        $uploads_base = wp_upload_dir()['basedir'];
        if ( is_dir( $uploads_base ) ) {
            $items = @scandir( $uploads_base );
            $misc_files = [];

            if ( $items ) {
                foreach ( $items as $item ) {
                    if ( $item === '.' || $item === '..' ) continue;
                    $path = $uploads_base . '/' . $item;

                    if ( is_dir( $path ) ) {
                        // Year folders → split by month
                        if ( preg_match( '/^\d{4}$/', $item ) ) {
                            $months = glob( $path . '/*', GLOB_ONLYDIR );
                            if ( ! empty( $months ) ) {
                                foreach ( $months as $month_dir ) {
                                    $month = basename( $month_dir );
                                    $queue[] = [
                                        'key'      => 'uploads/' . $item . '/' . $month,
                                        'source'   => $month_dir,
                                        'zip_name' => 'uploads_' . $item . '_' . $month . '.zip',
                                        'label'    => 'Uploads: ' . $item . '/' . $month,
                                        'type'     => 'dir',
                                    ];
                                }
                                // Also get files directly in the year folder (not in month subdir)
                                $year_files = glob( $path . '/*.*' );
                                if ( ! empty( $year_files ) ) {
                                    $queue[] = [
                                        'key'      => 'uploads/' . $item . '/_files',
                                        'source'   => $year_files,
                                        'zip_name' => 'uploads_' . $item . '__files.zip',
                                        'label'    => 'Uploads: ' . $item . ' (files)',
                                        'type'     => 'file_list',
                                    ];
                                }
                            } else {
                                // Year folder with no month subdirs
                                $queue[] = [
                                    'key'      => 'uploads/' . $item,
                                    'source'   => $path,
                                    'zip_name' => 'uploads_' . $item . '.zip',
                                    'label'    => 'Uploads: ' . $item,
                                    'type'     => 'dir',
                                ];
                            }
                        } else {
                            // Non-year folders (woocommerce, elementor, etc.)
                            $queue[] = [
                                'key'      => 'uploads/' . $item,
                                'source'   => $path,
                                'zip_name' => 'uploads_' . sanitize_file_name( $item ) . '.zip',
                                'label'    => 'Uploads: ' . $item,
                                'type'     => 'dir',
                            ];
                        }
                    } else {
                        $misc_files[] = $path;
                    }
                }
            }

            if ( ! empty( $misc_files ) ) {
                $queue[] = [
                    'key'      => 'uploads/_root_files',
                    'source'   => $misc_files,
                    'zip_name' => 'uploads__root_files.zip',
                    'label'    => 'Uploads: root files',
                    'type'     => 'file_list',
                ];
            }
        }

        // --- MU Plugins ---
        if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
            $queue[] = [
                'key'      => 'mu-plugins',
                'source'   => WPMU_PLUGIN_DIR,
                'zip_name' => 'mu-plugins.zip',
                'label'    => 'Must-Use Plugins',
                'type'     => 'dir',
            ];
        }

        // --- Languages ---
        // wp-content/languages/ contains translation files (.mo/.po/.json).
        // Required for a pixel-perfect clone — missing translations break
        // admin UI and front-end strings for non-English sites.
        $languages_dir = WP_CONTENT_DIR . '/languages';
        if ( is_dir( $languages_dir ) ) {
            $queue[] = [
                'key'      => 'languages',
                'source'   => $languages_dir,
                'zip_name' => 'languages.zip',
                'label'    => 'Languages',
                'type'     => 'dir',
            ];
        }

        // --- wp-content drop-ins ---
        // Drop-ins are single PHP files dropped directly in wp-content/:
        //   object-cache.php  — persistent object cache (Redis, Memcached, SQLite)
        //   advanced-cache.php — page cache (WP Rocket, W3TC, WP Super Cache)
        //   db.php             — custom DB driver (HyperDB, SQLite integration)
        //   db-error.php       — custom DB error page
        //   fatal-error-handler.php — custom fatal error handler
        // IMPORTANT: object-cache.php and advanced-cache.php are EXCLUDED from
        // backups because they are dynamically generated by cache plugins with
        // hardcoded absolute server paths (e.g. LSCWP_DIR, W3TC_DIR).
        // After migration these paths are wrong, causing fatal errors like
        // "Can NOT find LSCWP path for object cache initialization".
        // The cache plugin will regenerate them on first admin visit.
        // This is the same approach used by Duplicator and AI1WM.
        $dropin_names = [
            'db.php', 'db-error.php', 'fatal-error-handler.php', 'maintenance.php',
        ];
        $dropin_files = [];
        foreach ( $dropin_names as $dn ) {
            $dp = WP_CONTENT_DIR . '/' . $dn;
            if ( file_exists( $dp ) ) {
                $dropin_files[] = $dp;
            }
        }
        if ( ! empty( $dropin_files ) ) {
            $queue[] = [
                'key'      => 'dropins',
                'source'   => $dropin_files,
                'zip_name' => 'dropins.zip',
                'label'    => 'WP Content drop-ins (' . count( $dropin_files ) . ' files)',
                'type'     => 'file_list',
            ];
        }

        $this->manifest['file_queue']     = $queue;
        $this->manifest['file_queue_idx'] = 0;
        $this->manifest['steps_done'][]   = 'files_scan';
        $this->save_manifest();

        return [
            'session_id'  => $this->session_id,
            'queue_total' => count( $queue ),
            'next_step'   => count( $queue ) > 0 ? 'files_archive' : 'config',
            'progress'    => 30,
            'message'     => count( $queue ) . /* translators: %d = number of jobs */ ' ' . __( 'archive jobs planned. Starting file archiving...', 'clone-master' ),
        ];
    }

    // =========================================================================
    // Step 4: Archive ONE item from queue per AJAX call
    // =========================================================================
    private function step_files_archive() {
        $queue = $this->manifest['file_queue'] ?? [];
        $idx   = $this->manifest['file_queue_idx'] ?? 0;

        if ( $idx >= count( $queue ) ) {
            return [
                'session_id' => $this->session_id,
                'next_step'  => 'config',
                'progress'   => 70,
                'message'    => __( 'All files archived. Capturing config...', 'clone-master' ),
            ];
        }

        $job       = $queue[ $idx ];
        $files_dir = $this->session_dir . 'files/';
        $zip_path  = $files_dir . $job['zip_name'];
        $count     = 0;

        if ( $job['type'] === 'file_list' ) {
            $count = $this->create_zip_from_files( $job['source'], $zip_path, $job['key'] );
        } elseif ( $job['type'] === 'dir' && is_dir( $job['source'] ) ) {
            $result = $this->create_zip_from_dir( $job['source'], $zip_path, $job['key'] );
            $count  = $result['count'];
        }

        $size = file_exists( $zip_path ) ? filesize( $zip_path ) : 0;

        $this->manifest['archives'][ $job['key'] ] = [
            'archive'  => $job['zip_name'],
            'size'     => $size,
            'files'    => $count,
        ];

        $this->manifest['file_queue_idx'] = $idx + 1;
        $this->save_manifest();

        $total    = count( $queue );
        $done     = $idx + 1;
        $progress = 30 + ( $done / $total ) * 40; // 30% → 70%
        $has_more = ( $done < $total );
        $size_str = $size > 0 ? ' (' . size_format( $size ) . ')' : '';

        return [
            'session_id'  => $this->session_id,
            'next_step'   => $has_more ? 'files_archive' : 'config',
            'progress'    => round( $progress, 1 ),
            'queue_done'  => $done,
            'queue_total' => $total,
            'message'     => "[{$done}/{$total}] " . $job['label'] . $size_str . ( $has_more ? '' : ' — ' . __( 'All files archived.', 'clone-master' ) ),
        ];
    }

    // =========================================================================
    // Step 5: Capture configuration
    // =========================================================================
    private function step_config() {
        // Root files
        $root_files = [];
        // wp-config.php is intentionally excluded — it contains sensitive credentials
        // and is never used during import (the destination site keeps its own config).
        foreach ( [ '.htaccess', 'robots.txt', '.user.ini', 'php.ini' ] as $pattern ) {
            $path = ABSPATH . $pattern;
            if ( file_exists( $path ) ) {
                copy( $path, $this->session_dir . 'files/root_' . $pattern );
                $root_files[] = $pattern;
            }
        }

        $this->manifest['root_files']    = $root_files;
        $this->manifest['steps_done'][]  = 'config';
        $this->save_manifest();

        return [
            'session_id' => $this->session_id,
            'next_step'  => 'package',
            'progress'   => 75,
            'message'    => __( 'Configuration captured. Creating final package...', 'clone-master' ),
        ];
    }

    // =========================================================================
    // Step 6: Package
    // =========================================================================
    private function step_package() {
        $this->manifest['steps_done'][] = 'package';
        $this->manifest['completed_at'] = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        $this->save_manifest();

        $site_name = sanitize_title( wp_parse_url( site_url(), PHP_URL_HOST ) );
        $filename  = 'wpcm_' . $site_name . '_' . gmdate( 'Ymd_His' ) . '.zip';
        $zip_path  = WPCM_BACKUP_DIR . $filename;

        wp_mkdir_p( WPCM_BACKUP_DIR );

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new Exception( __( 'Cannot create ZIP archive at: ', 'clone-master' ) . $zip_path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        $this->add_dir_to_zip( $zip, $this->session_dir, '' );
        $zip->close();

        if ( ! file_exists( $zip_path ) ) {
            throw new Exception( __( 'ZIP file was not created', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        return [
            'session_id'   => $this->session_id,
            'next_step'    => 'cleanup',
            'progress'     => 95,
            'filename'     => $filename,
            'size'         => size_format( filesize( $zip_path ) ),
            // 'path' intentionally omitted — absolute server path not needed by the
            // frontend and leaks directory layout. Use 'filename' for all operations.
            'download_url' => admin_url( 'admin-ajax.php?action=wpcm_download_backup&backup_name=' . urlencode( $filename ) . '&nonce=' . wp_create_nonce( 'wpcm_nonce' ) ),
            'message'      => 'Package created: ' . $filename . ' (' . size_format( filesize( $zip_path ) ) . ')',
        ];
    }

    // =========================================================================
    // Step 7: Cleanup
    // =========================================================================
    private function step_cleanup() {
        $this->recursive_delete( $this->session_dir );

        return [
            'session_id' => $this->session_id,
            'next_step'  => null,
            'progress'   => 100,
            'message'    => __( 'Export complete! Temporary files cleaned up.', 'clone-master' ),
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function create_zip_from_dir( $source_dir, $zip_path, $prefix ) {
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new Exception( __( 'Cannot create ZIP: ', 'clone-master' ) . $zip_path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        $count = 0;
        // Exclude patterns — matched against the BASENAME only (not the full path).
        // Using strpos on the full relative path caused false positives:
        // e.g., 'cache' excluded polylang/src/integrations/cache/ which is plugin source code.
        // Duplicator/AI1WM approach: only skip by exact basename match at top level,
        // never kill legitimate plugin subdirectories.
        $exclude_basenames = [
            'node_modules', '.git', '.svn',
            'clone-master', 'wpcm-backups', 'wpcm-temp', 'wpcm-logs',
        ];
        // These are excluded only when they are the DIRECT child of wp-content or uploads
        // (i.e., root-level cache directories, not plugin internals named cache)
        $exclude_root_basenames = [
            'cache',       // wp-content/cache/ (WP Super Cache, W3TC, etc.)
            'et-cache',    // Divi cache
            'wpo-cache',   // WP Optimize cache
        ];

        $real_source = realpath( $source_dir );
        if ( ! $real_source ) {
            $zip->close();
            return [ 'count' => 0 ];
        }
        $source_len = strlen( $real_source );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $real = $item->getRealPath();
            if ( ! $real ) continue;
            $relative = substr( $real, $source_len + 1 );
            $basename = $item->getBasename();
            $depth    = $iterator->getDepth(); // 0 = direct child of source_dir

            $skip = false;

            // Always skip these basenames at any depth
            foreach ( $exclude_basenames as $ex ) {
                if ( $basename === $ex ) { $skip = true; break; }
            }

            // Skip root-level cache dirs only (depth 0) — never skip plugin internals
            if ( ! $skip && $depth === 0 ) {
                foreach ( $exclude_root_basenames as $ex ) {
                    if ( $basename === $ex ) { $skip = true; break; }
                }
            }

            // Always skip debug/log files anywhere
            if ( ! $skip && in_array( $basename, [ 'debug.log', 'error_log', 'error.log' ], true ) ) {
                $skip = true;
            }

            if ( $skip ) continue;

            $zip_name = $prefix . '/' . $relative;

            if ( $item->isDir() ) {
                $zip->addEmptyDir( $zip_name );
            } else {
                if ( $item->getSize() > 256 * 1024 * 1024 ) continue; // Skip files > 256MB
                $zip->addFile( $real, $zip_name );
                $count++;
            }
        }

        $zip->close();
        return [ 'count' => $count ];
    }

    private function create_zip_from_files( $files, $zip_path, $prefix ) {
        if ( ! is_array( $files ) ) return 0;

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new Exception( __( 'Cannot create ZIP: ', 'clone-master' ) . $zip_path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        $count = 0;
        foreach ( $files as $file ) {
            if ( ! file_exists( $file ) || ! is_file( $file ) ) continue;
            if ( filesize( $file ) > 256 * 1024 * 1024 ) continue;
            $zip->addFile( $file, $prefix . '/' . basename( $file ) );
            $count++;
        }

        $zip->close();
        return $count;
    }

    private function add_dir_to_zip( $zip, $dir, $prefix ) {
        $real_dir = realpath( $dir );
        if ( ! $real_dir ) return;
        $base_len = strlen( $real_dir );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $real = $item->getRealPath();
            if ( ! $real ) continue;

            // Hard block: never package wp-config.php regardless of how it got here.
            if ( ! $item->isDir() && basename( $real ) === 'root_wp-config.php' ) {
                continue;
            }

            $relative = $prefix . substr( $real, $base_len );

            if ( $item->isDir() ) {
                $zip->addEmptyDir( $relative );
            } else {
                $zip->addFile( $real, $relative );
            }
        }
    }

    private function save_manifest() {
        file_put_contents(
            $this->session_dir . 'manifest.json',
            wp_json_encode( $this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        );
    }

    private function recursive_delete( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            $item->isDir() ? rmdir( $item->getRealPath() ) : wp_delete_file( $item->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem::rmdir() requires auth credentials, unsuitable for background processing
        }
        rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem::rmdir() requires auth credentials, unsuitable for background processing
    }
}
