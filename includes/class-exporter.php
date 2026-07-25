<?php
/**
 * Exporter : Creates a full clone archive of the WordPress site
 *
 * Now with granular sub-steps to avoid timeouts:
 *
 * 1. init           → Create session, detect environment
 * 2. database       → Dump all tables with durable keyset cursors
 * 3. files_scan     → Persist a bounded, resumable file map
 * 4. files_archive  → Append verified WPCM blocks from durable file-map jobs
 * 5. config         → Capture root config files (no wp-config.php), options, meta
 * 6. package        → Finalize and atomically publish the WPCM container
 * 7. cleanup        → Remove temp files
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DEVELOPER NOTE : esc_sql() + remove_placeholder_escape() in SQL dump (step 2)
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
 * directly to .sql files on disk : they never pass through wpdb::query(). As a
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
 * file. remove_placeholder_escape() only undoes the % substitution step : it does
 * not remove the other escaping.
 *
 * WHY NOT THE ALTERNATIVES
 *   $wpdb->dbh->real_escape_string()  : private wpdb property, rejected by the
 *                                        WordPress Plugin Review Team (wp.org).
 *   mysqli_real_escape_string()        : raw mysqli call, bypasses WP abstraction.
 *   addslashes()                       : explicitly forbidden by WP Coding Standards.
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
        if ( 'init' === $step ) {
            return $this->step_init();
        }

        if ( ! is_string( $session_id ) || ! preg_match( '/^export_[a-zA-Z0-9_]{10,100}$/', $session_id ) ) {
            throw new Exception( __( 'Missing or invalid session ID.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $this->session_id  = $session_id;
        $this->session_dir = WPCM_TEMP_DIR . $session_id . '/';

        if ( ! is_dir( $this->session_dir ) ) {
            throw new Exception( __( 'Export session not found.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $lock = WPCM_Reliability::acquire_lock( $this->session_dir . 'export-step.lock' );
        if ( false === $lock ) {
            throw new Exception( __( 'Another request is already processing this export session.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        try {
            $context        = 'export-manifest:' . $this->session_id;
            $signed_manifest = WPCM_Reliability::read_signed_json( $this->session_dir . 'manifest-state.json', $context );
            $this->manifest = is_array( $signed_manifest ) ? $signed_manifest : null;
            if ( ! is_array( $this->manifest ) ) {
                throw new Exception( __( 'Corrupt or unauthenticated export manifest.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            switch ( $step ) {
                case 'database':
                    return $this->step_database();
                case 'files_scan':
                    return $this->step_files_scan();
                case 'files_archive':
                    return $this->step_files_archive();
                case 'config':
                    return $this->step_config();
                case 'package':
                    return $this->step_package();
                case 'cleanup':
                    return $this->step_cleanup();
                default:
                    throw new Exception( __( 'Unknown export step.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        } finally {
            WPCM_Reliability::release_lock( $lock );
        }
    }

    // =========================================================================
    // Step 1: Initialize session
    // =========================================================================
    private function step_init() {
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $this->session_id  = 'export_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 20, false, false );
            $this->session_dir = WPCM_TEMP_DIR . $this->session_id . '/';
            if ( ! file_exists( $this->session_dir ) && ! is_link( $this->session_dir ) ) {
                break;
            }
        }
        if ( file_exists( $this->session_dir ) || is_link( $this->session_dir ) ) {
            throw new Exception( __( 'Unable to allocate a unique export workspace.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( ! wp_mkdir_p( $this->session_dir ) || ! wp_mkdir_p( $this->session_dir . 'sql/' ) || ! wp_mkdir_p( $this->session_dir . 'files/' ) ) {
            throw new Exception( __( 'Unable to create the protected export workspace.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        // Protect session subdirectory immediately : the parent wpcm-temp/.htaccess
        // may not propagate to subdirs on hosts with AllowOverride None.
        WPCM_Plugin::protect_directory( $this->session_dir, false );

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
            'content_presence'=> [],
            'package_filename'=> '',
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
    // Step 2: Export database : chunked + ADAPTIVE throttle
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
    // 1. TIME LOOP   : measures wall-clock duration of the previous call.
    //                  • t > budget×1.0  → ÷1.5  (too slow, risk of timeout)
    //                  • t < budget×0.5  → ×1.3  (spare capacity, go faster)
    //                  Target budget = min(max_execution_time × 0.55, 20s, 25s)
    //                  Falls back to 12s when max_execution_time = 0 (no limit).
    //
    // 2. MEMORY LOOP : checks memory_get_peak_usage() after each call.
    //                  • peak > memory_limit × 0.70 → ÷2  (pressure)
    //                  • peak < memory_limit × 0.35 → ×1.2 (headroom)
    //
    // 3. ROW-SIZE PROBE : on the very first call for each table, samples 10
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

    /** Hard lower bound : prevents infinite loops on near-empty tables. */
    const WPCM_DB_ROWS_MIN = 25;

    /** Hard upper bound : safeguard against max_allowed_packet overflows. */
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
     * When max_execution_time = 0 (unlimited : rare on shared hosting, common
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

        $sql_dir     = $this->session_dir . 'sql/';
        $cursor_file = $this->session_dir . 'db-cursor.json';
        $tables_file = $this->session_dir . 'db-tables.json';
        $info_file   = $this->session_dir . 'db-info.json';
        $context     = 'export-db:' . $this->session_id;

        if ( in_array( 'database', $this->manifest['steps_done'] ?? array(), true ) ) {
            return array(
                'session_id'   => $this->session_id,
                'tables_count' => count( $this->manifest['tables'] ?? array() ),
                'next_step'    => 'files_scan',
                'progress'     => 25,
                'message'      => __( 'The durable database export is already complete.', 'clone-master' ),
            );
        }

        $cursor = WPCM_Reliability::read_signed_json( $cursor_file, $context . ':cursor' );
        if ( ! is_array( $cursor ) ) {
            $prefix      = (string) $wpdb->prefix;
            $prefix_len  = strlen( $prefix );
            $tables      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live export must enumerate the current schema.
                $wpdb->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND LEFT(TABLE_NAME, %d) = %s ORDER BY TABLE_NAME",
                    $prefix_len,
                    $prefix
                )
            );
            $views = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW' AND LEFT(TABLE_NAME, %d) = %s ORDER BY TABLE_NAME",
                    $prefix_len,
                    $prefix
                )
            );
            $triggers = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND LEFT(EVENT_OBJECT_TABLE, %d) = %s ORDER BY TRIGGER_NAME",
                    $prefix_len,
                    $prefix
                )
            );
            if ( ! empty( $views ) || ! empty( $triggers ) ) {
                throw new Exception(
                    sprintf(
                        /* translators: 1: view count, 2: trigger count. */
                        __( 'The database contains %1$d view(s) and %2$d trigger(s). Clone Master refuses to create a partial archive because transactional restore of these objects is not yet supported.', 'clone-master' ),
                        count( $views ),
                        count( $triggers )
                    )
                );
            }

            $header  = "-- Clone Master Database Export\n";
            $header .= '-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
            $header .= '-- Site: ' . site_url() . "\n";
            $header .= '-- Prefix: ' . $prefix . "\n";
            $header .= '-- MySQL: ' . $wpdb->db_version() . "\n";
            $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $header .= "SET NAMES utf8mb4;\n";
            $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            WPCM_Reliability::atomic_write( $sql_dir . '0000_header.sql', $header );

            WPCM_Reliability::atomic_signed_json( $tables_file, array_values( $tables ), $context . ':tables' );
            WPCM_Reliability::atomic_signed_json( $info_file, array(), $context . ':info' );

            $cursor = array(
                'table_idx'      => 0,
                'last_key'       => null,
                'end_key'        => null,
                'key_columns'    => array(),
                'key_index'      => '',
                'current_count'  => 0,
                'rows_exported'  => 0,
                'schema_hash'    => '',
                'chunk_index'    => 0,
                'rows_per_call'  => self::WPCM_DB_ROWS_INITIAL,
                't_prev'         => 0.0,
                'mem_prev'       => 0,
                'avg_row_bytes'  => 0,
                'max_packet'     => 0,
            );
            WPCM_Reliability::atomic_signed_json( $cursor_file, $cursor, $context . ':cursor' );
        }

        $tables     = WPCM_Reliability::read_signed_json( $tables_file, $context . ':tables' );
        $table_info = WPCM_Reliability::read_signed_json( $info_file, $context . ':info' );
        if ( ! is_array( $tables ) || ! is_array( $table_info ) ) {
            throw new Exception( __( 'The durable database export state is missing or has been altered.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $table_idx     = (int) ( $cursor['table_idx'] ?? 0 );
        $last_key      = $cursor['last_key'] ?? null;
        $end_key       = $cursor['end_key'] ?? null;
        $key_columns   = isset( $cursor['key_columns'] ) && is_array( $cursor['key_columns'] ) ? array_values( array_map( 'strval', $cursor['key_columns'] ) ) : array();
        $key_index     = (string) ( $cursor['key_index'] ?? '' );
        $total_tables  = count( $tables );
        $rows_per_call = max( self::WPCM_DB_ROWS_MIN, min( self::WPCM_DB_ROWS_MAX, (int) ( $cursor['rows_per_call'] ?? self::WPCM_DB_ROWS_INITIAL ) ) );
        $t_prev        = (float) ( $cursor['t_prev'] ?? 0 );
        $mem_prev      = (int) ( $cursor['mem_prev'] ?? 0 );
        $avg_row_bytes = (int) ( $cursor['avg_row_bytes'] ?? 0 );
        $max_packet    = (int) ( $cursor['max_packet'] ?? 0 );

        // Migrate an interrupted pre-2.2.3 single-column cursor in memory.
        if ( empty( $key_columns ) && ! empty( $cursor['key_column'] ) ) {
            $key_columns = array( (string) $cursor['key_column'] );
            $key_index   = 'legacy-single-column';
            if ( null !== $last_key && ! is_array( $last_key ) ) {
                $last_key = $this->db_encode_key_values( array( $last_key ) );
            }
            if ( null !== $end_key && ! is_array( $end_key ) ) {
                $end_key = $this->db_encode_key_values( array( $end_key ) );
            }
        }

        if ( 0 === $max_packet ) {
            $max_packet = $this->db_max_allowed_packet();
        }
        if ( $t_prev > 0 ) {
            $rows_per_call = $this->db_adapt_rows( $rows_per_call, $t_prev, $mem_prev, $avg_row_bytes, $max_packet );
        }

        if ( $table_idx >= $total_tables ) {
            return $this->finish_database_step( $table_info, $sql_dir, $total_tables );
        }

        $table      = (string) $tables[ $table_idx ];
        $identifier = $this->quote_identifier( $table );
        $file_stub  = sprintf( '%04d_%s', $table_idx + 1, sanitize_file_name( $table ) );

        if ( null === $last_key ) {
            $create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $identifier, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted.
            if ( ! is_array( $create ) || empty( $create[1] ) ) {
                throw new Exception( sprintf( __( 'Unable to read the schema for table %s.', 'clone-master' ), $table ) );
            }
            $schema_hash = hash( 'sha256', $this->normalize_create_table( (string) $create[1] ) );
            $schema_sql  = 'DROP TABLE IF EXISTS ' . $identifier . ";\n" . $create[1] . ";\n";
            WPCM_Reliability::atomic_write( $sql_dir . $file_stub . '_000000_schema.sql', $schema_sql );

            $count    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $identifier ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted.
            $key_meta = $count > 0 ? $this->db_find_resume_key( $table ) : array( 'index_name' => '', 'columns' => array() );
            if ( $count > 0 && empty( $key_meta['columns'] ) ) {
                throw new Exception(
                    sprintf(
                        /* translators: 1: database table name, 2: row count. */
                        __( 'Table %1$s contains %2$d row(s) but has no PRIMARY or UNIQUE NOT NULL key. A resumable export cannot preserve duplicate rows safely, so the backup was stopped.', 'clone-master' ),
                        $table,
                        $count
                    )
                );
            }

            $key_columns = array_values( $key_meta['columns'] ?? array() );
            $key_index   = (string) ( $key_meta['index_name'] ?? '' );

            if ( $count > 0 ) {
                $key_select = $this->db_key_select_list( $key_columns );
                $key_order  = $this->db_key_order_by( $key_columns, 'DESC' );
                $end_row    = $wpdb->get_row( 'SELECT ' . $key_select . ' FROM ' . $identifier . ' ORDER BY ' . $key_order . ' LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers come from information_schema and are quoted.
                if ( ! is_array( $end_row ) ) {
                    throw new Exception( sprintf( __( 'Unable to establish a durable upper boundary for table %s.', 'clone-master' ), $table ) );
                }
                $end_key = $this->db_extract_key_from_row( $end_row, $key_columns, $table );
                $bound   = $this->db_build_keyset_clause( $key_columns, $end_key, 'at_or_before' );
                $count   = (int) $wpdb->get_var(
                    $this->db_prepare_sql(
                        'SELECT COUNT(*) FROM ' . $identifier . ' WHERE ' . $bound['sql'],
                        $bound['values']
                    )
                ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are quoted and key values are prepared.
            } else {
                $end_key = null;
            }

            $avg_row_bytes = $count > 0 ? $this->db_probe_row_size( esc_sql( $table ) ) : 0;
            if ( $avg_row_bytes > 0 && $max_packet > 0 ) {
                $packet_cap    = (int) ( ( $max_packet * 0.80 ) / max( 1, $avg_row_bytes ) );
                $rows_per_call = max( self::WPCM_DB_ROWS_MIN, min( $rows_per_call, $packet_cap, self::WPCM_DB_ROWS_MAX ) );
            }

            $cursor['current_count'] = $count;
            $cursor['rows_exported'] = 0;
            $cursor['end_key']       = $end_key;
            $cursor['schema_hash']   = $schema_hash;
            $cursor['key_columns']   = $key_columns;
            $cursor['key_index']     = $key_index;
            unset( $cursor['key_column'] );
            $cursor['chunk_index']   = 0;
            WPCM_Reliability::atomic_signed_json( $cursor_file, $cursor, $context . ':cursor' );
        } else {
            $count = (int) ( $cursor['current_count'] ?? 0 );
        }

        $t_start   = microtime( true );
        $mem_start = memory_get_peak_usage( true );
        $rows      = array();

        if ( $count > 0 ) {
            if ( empty( $key_columns ) || ! is_array( $end_key ) ) {
                throw new Exception( sprintf( __( 'The durable composite boundary for table %s is missing.', 'clone-master' ), $table ) );
            }

            $where_values = array();
            $where_parts  = array();
            $bound        = $this->db_build_keyset_clause( $key_columns, $end_key, 'at_or_before' );
            $where_parts[] = $bound['sql'];
            $where_values  = array_merge( $where_values, $bound['values'] );

            if ( null !== $last_key ) {
                if ( ! is_array( $last_key ) ) {
                    throw new Exception( sprintf( __( 'The durable resume key for table %s is invalid.', 'clone-master' ), $table ) );
                }
                $after         = $this->db_build_keyset_clause( $key_columns, $last_key, 'after' );
                $where_parts[] = $after['sql'];
                $where_values  = array_merge( $where_values, $after['values'] );
            }

            $query_sql = 'SELECT * FROM ' . $identifier . ' WHERE ' . implode( ' AND ', $where_parts ) . ' ORDER BY ' . $this->db_key_order_by( $key_columns, 'ASC' ) . ' LIMIT %d';
            $where_values[] = $rows_per_call;
            $query = $this->db_prepare_sql( $query_sql, $where_values );
            $rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are quoted and values are prepared.
            if ( ! is_array( $rows ) ) {
                throw new Exception( sprintf( __( 'Unable to read rows from table %s.', 'clone-master' ), $table ) );
            }
        }

        $chunk_index = (int) ( $cursor['chunk_index'] ?? 0 );
        if ( ! empty( $rows ) ) {
            $chunk_sql  = $this->db_build_insert_sql( $table, $rows );
            $chunk_path = $sql_dir . $file_stub . '_data_' . sprintf( '%08d', $chunk_index ) . '.sql';
            WPCM_Reliability::atomic_write( $chunk_path, $chunk_sql );
            $last_row = $rows[ count( $rows ) - 1 ];
            $last_key = $this->db_extract_key_from_row( $last_row, $key_columns, $table );
            $chunk_index++;
            $cursor['rows_exported'] = (int) ( $cursor['rows_exported'] ?? 0 ) + count( $rows );
        }

        $t_real   = microtime( true ) - $t_start;
        $mem_peak = max( 0, memory_get_peak_usage( true ) - $mem_start );
        $complete = 0 === $count || count( $rows ) < $rows_per_call;

        if ( $complete ) {
            $create_after = $wpdb->get_row( 'SHOW CREATE TABLE ' . $identifier, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted.
            $after_hash   = is_array( $create_after ) && ! empty( $create_after[1] ) ? hash( 'sha256', $this->normalize_create_table( (string) $create_after[1] ) ) : '';
            if ( empty( $cursor['schema_hash'] ) || ! hash_equals( (string) $cursor['schema_hash'], $after_hash ) ) {
                throw new Exception( sprintf( __( 'The schema of table %s changed during export. Retry the backup when database schema changes have finished.', 'clone-master' ), $table ) );
            }

            $bounded_count = 0;
            if ( $count > 0 ) {
                $bound = $this->db_build_keyset_clause( $key_columns, $end_key, 'at_or_before' );
                $bounded_count = (int) $wpdb->get_var(
                    $this->db_prepare_sql(
                        'SELECT COUNT(*) FROM ' . $identifier . ' WHERE ' . $bound['sql'],
                        $bound['values']
                    )
                ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are quoted and key values are prepared.
            }
            $rows_exported = (int) ( $cursor['rows_exported'] ?? 0 );
            if ( $bounded_count !== $count || $rows_exported !== $count ) {
                throw new Exception(
                    sprintf(
                        /* translators: 1: table name, 2: expected rows, 3: current rows, 4: exported rows. */
                        __( 'Table %1$s changed during export (expected %2$d bounded rows, now %3$d, exported %4$d). Retry during a quieter period or maintenance window.', 'clone-master' ),
                        $table,
                        $count,
                        $bounded_count,
                        $rows_exported
                    )
                );
            }

            $table_info[] = array(
                'name'         => $table,
                'rows'         => $count,
                'key_index'    => $key_index,
                'key_columns'  => $key_columns,
                'key_column'   => 1 === count( $key_columns ) ? $key_columns[0] : '',
                'schema_hash'  => $after_hash,
                'chunks'       => $chunk_index,
            );
            WPCM_Reliability::atomic_signed_json( $info_file, $table_info, $context . ':info' );

            $table_idx++;
            $last_key      = null;
            $end_key       = null;
            $key_columns   = array();
            $key_index     = '';
            $avg_row_bytes = 0;
            $chunk_index   = 0;
            $cursor['current_count'] = 0;
            $cursor['rows_exported'] = 0;
            $cursor['end_key']       = null;
            $cursor['schema_hash']   = '';
        }

        $cursor['table_idx']     = $table_idx;
        $cursor['last_key']      = $last_key;
        $cursor['end_key']       = $end_key;
        $cursor['key_columns']   = $key_columns;
        $cursor['key_index']     = $key_index;
        unset( $cursor['key_column'] );
        $cursor['chunk_index']   = $chunk_index;
        $cursor['rows_per_call'] = $rows_per_call;
        $cursor['t_prev']        = round( $t_real, 4 );
        $cursor['mem_prev']      = $mem_peak;
        $cursor['avg_row_bytes'] = $avg_row_bytes;
        $cursor['max_packet']    = $max_packet;
        WPCM_Reliability::atomic_signed_json( $cursor_file, $cursor, $context . ':cursor' );

        if ( $table_idx >= $total_tables ) {
            return $this->finish_database_step( $table_info, $sql_dir, $total_tables );
        }

        $progress     = 10 + ( $total_tables > 0 ? (int) floor( 15 * $table_idx / $total_tables ) : 15 );
        $cursor_token = hash(
            'sha256',
            wp_json_encode(
                array(
                    'table_idx'     => $table_idx,
                    'last_key'      => $last_key,
                    'end_key'       => $end_key,
                    'key_columns'   => $key_columns,
                    'rows_exported' => (int) ( $cursor['rows_exported'] ?? 0 ),
                    'chunk_index'   => $chunk_index,
                ),
                JSON_PRESERVE_ZERO_FRACTION
            )
        );
        return array(
            'session_id'       => $this->session_id,
            'next_step'        => 'database',
            'progress'         => $progress,
            'rows_per_call'    => $rows_per_call,
            'rows_exported'    => (int) ( $cursor['rows_exported'] ?? 0 ),
            'rows_total'       => (int) ( $cursor['current_count'] ?? 0 ),
            'cursor_token'     => $cursor_token,
            'message'          => sprintf(
                /* translators: 1: completed table count, 2: total table count, 3: current table name. */
                __( 'Database: %1$d/%2$d table(s) completed : processing %3$s with a durable keyset cursor.', 'clone-master' ),
                $table_idx,
                $total_tables,
                (string) ( $tables[ $table_idx ] ?? $table )
            ),
        );
    }

    /**
     * Find a PRIMARY or UNIQUE NOT NULL key suitable for deterministic keyset pagination.
     *
     * Composite keys are supported in their declared index order. The primary
     * key is preferred, followed by the shortest eligible unique index.
     *
     * @param string $table Table name.
     * @return array{index_name:string,columns:array<int,string>}
     */
    private function db_find_resume_key( $table ) {
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection must be current.
            $wpdb->prepare(
                "SELECT s.INDEX_NAME,s.COLUMN_NAME,s.SEQ_IN_INDEX,c.IS_NULLABLE
                 FROM information_schema.STATISTICS s
                 INNER JOIN information_schema.COLUMNS c
                    ON c.TABLE_SCHEMA=s.TABLE_SCHEMA AND c.TABLE_NAME=s.TABLE_NAME AND c.COLUMN_NAME=s.COLUMN_NAME
                 WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME=%s AND s.NON_UNIQUE=0
                 ORDER BY (s.INDEX_NAME='PRIMARY') DESC,s.INDEX_NAME ASC,s.SEQ_IN_INDEX ASC",
                $table
            ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            return array( 'index_name' => '', 'columns' => array() );
        }

        $candidates = array();
        foreach ( $rows as $row ) {
            $index_name = (string) ( $row['INDEX_NAME'] ?? '' );
            $column     = (string) ( $row['COLUMN_NAME'] ?? '' );
            if ( '' === $index_name ) {
                continue;
            }
            if ( ! isset( $candidates[ $index_name ] ) ) {
                $candidates[ $index_name ] = array(
                    'index_name' => $index_name,
                    'columns'    => array(),
                    'eligible'   => true,
                );
            }
            if ( '' === $column ) {
                $candidates[ $index_name ]['eligible'] = false;
                continue;
            }
            if ( 'NO' !== (string) ( $row['IS_NULLABLE'] ?? '' ) ) {
                $candidates[ $index_name ]['eligible'] = false;
            }
            $sequence = max( 1, (int) ( $row['SEQ_IN_INDEX'] ?? 1 ) );
            $candidates[ $index_name ]['columns'][ $sequence ] = $column;
        }

        $eligible = array();
        foreach ( $candidates as $candidate ) {
            if ( empty( $candidate['eligible'] ) || empty( $candidate['columns'] ) ) {
                continue;
            }
            ksort( $candidate['columns'], SORT_NUMERIC );
            $candidate['columns'] = array_values( $candidate['columns'] );
            unset( $candidate['eligible'] );
            $eligible[] = $candidate;
        }

        usort(
            $eligible,
            static function ( $left, $right ) {
                $left_primary  = 'PRIMARY' === $left['index_name'];
                $right_primary = 'PRIMARY' === $right['index_name'];
                if ( $left_primary !== $right_primary ) {
                    return $left_primary ? -1 : 1;
                }
                $column_compare = count( $left['columns'] ) <=> count( $right['columns'] );
                if ( 0 !== $column_compare ) {
                    return $column_compare;
                }
                return strcmp( $left['index_name'], $right['index_name'] );
            }
        );

        return $eligible[0] ?? array( 'index_name' => '', 'columns' => array() );
    }

    /**
     * Encode raw key values for type-stable authenticated JSON persistence.
     *
     * @param array $values Raw database values.
     * @return array<int,string>
     */
    private function db_encode_key_values( array $values ) {
        return array_map(
            static function ( $value ) {
                return base64_encode( (string) $value );
            },
            array_values( $values )
        );
    }

    /**
     * Extract and encode a complete traversal key from a database row.
     *
     * @param array  $row         Database row.
     * @param array  $key_columns Ordered key columns.
     * @param string $table       Table name for diagnostics.
     * @return array<int,string>
     */
    private function db_extract_key_from_row( array $row, array $key_columns, $table ) {
        $values = array();
        foreach ( $key_columns as $column ) {
            if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) {
                throw new Exception( sprintf( __( 'The resume key for table %s is incomplete.', 'clone-master' ), $table ) );
            }
            $values[] = $row[ $column ];
        }
        return $this->db_encode_key_values( $values );
    }

    /**
     * Decode persisted traversal key values.
     *
     * @param array $encoded     Base64-encoded values.
     * @param int   $expected    Expected number of columns.
     * @return array<int,string>
     */
    private function db_decode_key_values( array $encoded, $expected ) {
        if ( count( $encoded ) !== (int) $expected ) {
            throw new Exception( __( 'The durable database key has an invalid number of values.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $values = array();
        foreach ( $encoded as $value ) {
            if ( ! is_string( $value ) ) {
                throw new Exception( __( 'The durable database key contains an invalid value.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $decoded = base64_decode( $value, true );
            if ( false === $decoded ) {
                throw new Exception( __( 'The durable database key contains invalid encoding.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $values[] = $decoded;
        }
        return $values;
    }

    /**
     * Build a deterministic lexicographic keyset predicate.
     *
     * @param array  $columns Ordered key columns.
     * @param array  $encoded Persisted encoded key values.
     * @param string $relation Either after or at_or_before.
     * @return array{sql:string,values:array<int,string>}
     */
    private function db_build_keyset_clause( array $columns, array $encoded, $relation ) {
        if ( empty( $columns ) || ! in_array( $relation, array( 'after', 'at_or_before' ), true ) ) {
            throw new Exception( __( 'Unable to build a durable database keyset predicate.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $decoded = $this->db_decode_key_values( $encoded, count( $columns ) );
        $terms   = array();
        $params  = array();
        $last    = count( $columns ) - 1;

        foreach ( $columns as $index => $column ) {
            $parts = array();
            for ( $prefix = 0; $prefix < $index; $prefix++ ) {
                $parts[]  = $this->quote_identifier( $columns[ $prefix ] ) . ' = %s';
                $params[] = $decoded[ $prefix ];
            }
            if ( 'after' === $relation ) {
                $operator = '>';
            } else {
                $operator = $index === $last ? '<=' : '<';
            }
            $parts[]  = $this->quote_identifier( $column ) . ' ' . $operator . ' %s';
            $params[] = $decoded[ $index ];
            $terms[]  = '(' . implode( ' AND ', $parts ) . ')';
        }

        return array(
            'sql'    => '(' . implode( ' OR ', $terms ) . ')',
            'values' => $params,
        );
    }

    /**
     * Build a quoted key-column select list.
     *
     * @param array $columns Ordered key columns.
     * @return string
     */
    private function db_key_select_list( array $columns ) {
        return implode( ',', array_map( array( $this, 'quote_identifier' ), $columns ) );
    }

    /**
     * Build a deterministic key ordering clause.
     *
     * @param array  $columns  Ordered key columns.
     * @param string $direction ASC or DESC.
     * @return string
     */
    private function db_key_order_by( array $columns, $direction ) {
        $direction = 'DESC' === strtoupper( (string) $direction ) ? 'DESC' : 'ASC';
        $parts     = array();
        foreach ( $columns as $column ) {
            $parts[] = $this->quote_identifier( $column ) . ' ' . $direction;
        }
        return implode( ',', $parts );
    }

    /**
     * Prepare a dynamic query using WordPress placeholders.
     *
     * @param string $query  Query containing placeholders.
     * @param array  $values Placeholder values.
     * @return string
     */
    private function db_prepare_sql( $query, array $values ) {
        global $wpdb;
        if ( empty( $values ) ) {
            return $query;
        }
        $prepared = $wpdb->prepare( $query, $values );
        if ( ! is_string( $prepared ) || '' === $prepared ) {
            throw new Exception( __( 'Unable to prepare a durable database query.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $prepared;
    }

    /**
     * Build deterministic batched INSERT statements for one immutable chunk file.
     *
     * @param string $table Table name.
     * @param array  $rows  Rows.
     * @return string
     */
    private function db_build_insert_sql( $table, array $rows ) {
        global $wpdb;
        if ( empty( $rows ) ) {
            return '';
        }

        $columns = array_keys( $rows[0] );
        $quoted  = array_map( array( $this, 'quote_identifier' ), $columns );
        $batches = array();
        $values  = array();

        foreach ( $rows as $row ) {
            $parts = array();
            foreach ( $columns as $column ) {
                $value = array_key_exists( $column, $row ) ? $row[ $column ] : null;
                if ( null === $value ) {
                    $parts[] = 'NULL';
                } else {
                    $parts[] = "'" . $this->db_escape_sql_value( $value ) . "'";
                }
            }
            $values[] = '(' . implode( ',', $parts ) . ')';
            if ( count( $values ) >= self::WPCM_DB_INSERT_CHUNK ) {
                $batches[] = 'INSERT INTO ' . $this->quote_identifier( $table ) . ' (' . implode( ',', $quoted ) . ") VALUES\n  " . implode( ",\n  ", $values ) . ';';
                $values = array();
            }
        }
        if ( ! empty( $values ) ) {
            $batches[] = 'INSERT INTO ' . $this->quote_identifier( $table ) . ' (' . implode( ',', $quoted ) . ") VALUES\n  " . implode( ",\n  ", $values ) . ';';
        }
        return implode( "\n", $batches ) . "\n";
    }

    /**
     * Escape one SQL value while requiring every WordPress percent placeholder
     * to be restored before the dump is persisted.
     *
     * @param mixed $value Raw database value.
     * @return string Escaped SQL literal body.
     */
    private function db_escape_sql_value( $value ) {
        global $wpdb;

        $escaped = $wpdb->remove_placeholder_escape( esc_sql( (string) $value ) );
        if ( ! is_string( $escaped ) ) {
            throw new Exception( __( 'Unable to escape a database value without changing its contents.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( preg_match( '/\{[a-f0-9]{64}\}/i', $escaped ) ) {
            throw new Exception( __( 'A WordPress percent placeholder remained in an exported database value. The backup was stopped before writing corrupted data.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $escaped;
    }

    /**
     * Build a stable, filesystem-safe site-domain prefix for WPCM archives.
     *
     * The leading www label is removed so files from the same site remain
     * grouped together if WordPress changes between www and the apex domain.
     *
     * @return string
     */
    private function backup_site_filename_prefix() {
        $url  = function_exists( 'home_url' ) ? home_url( '/' ) : '';
        $host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST );
        $host = is_string( $host ) ? strtolower( rtrim( $host, '.' ) ) : '';

        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }

        if ( '' !== $host && function_exists( 'idn_to_ascii' ) ) {
            $flags = defined( 'IDNA_DEFAULT' ) ? IDNA_DEFAULT : 0;
            $variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0;
            $ascii = 0 !== $variant ? idn_to_ascii( $host, $flags, $variant ) : idn_to_ascii( $host, $flags );
            if ( is_string( $ascii ) && '' !== $ascii ) {
                $host = strtolower( $ascii );
            }
        }

        $host = preg_replace( '/[^a-z0-9.-]+/', '-', $host );
        $host = trim( (string) $host, '.-_' );
        if ( '' === $host ) {
            $host = 'wordpress-site';
        }

        return substr( $host, 0, 100 );
    }

    /**
     * Normalize non-structural AUTO_INCREMENT drift before schema comparison.
     *
     * @param string $create_sql SHOW CREATE TABLE output.
     * @return string
     */
    private function normalize_create_table( $create_sql ) {
        return preg_replace( '/\s+AUTO_INCREMENT=\d+/i', '', trim( (string) $create_sql ) );
    }

    /**
     * Quote a MySQL identifier.
     *
     * @param string $identifier Identifier.
     * @return string
     */
    private function quote_identifier( $identifier ) {
        return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
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
        WPCM_Reliability::atomic_write( $sql_dir . '9999_footer.sql', "\nSET FOREIGN_KEY_CHECKS = 1;\n" );

        $this->manifest['tables']       = $table_info;
        $this->manifest['steps_done'][] = 'database';
        $this->manifest['steps_done']   = array_values( array_unique( $this->manifest['steps_done'] ) );
        $this->save_manifest();

        foreach ( array( 'db-cursor.json', 'db-cursor.json.bak', 'db-tables.json', 'db-tables.json.bak', 'db-info.json', 'db-info.json.bak' ) as $name ) {
            @unlink( $this->session_dir . $name );
        }

        return array(
            'session_id'   => $this->session_id,
            'tables_count' => $total,
            'next_step'    => 'files_scan',
            'progress'     => 25,
            'message'      => sprintf(
                _n( '%d table exported with durable immutable chunks. Scanning files…', '%d tables exported with durable immutable chunks. Scanning files…', $total, 'clone-master' ),
                $total
            ),
        );
    }

    // =========================================================================
    // Step 3: Scan files : build a durable queue of bounded append jobs
    // =========================================================================
    private function step_files_scan() {
        if ( in_array( 'files_scan', $this->manifest['steps_done'] ?? array(), true ) ) {
            $queue = $this->manifest['file_queue'] ?? array();
            $index = (int) ( $this->manifest['file_queue_idx'] ?? 0 );
            return array(
                'session_id'  => $this->session_id,
                'queue_total' => count( $queue ),
                'next_step'   => $index < count( $queue ) ? 'files_archive' : 'config',
                'progress'    => $index < count( $queue ) ? 30 : 70,
                'message'     => __( 'The durable WPCM append plan is already available.', 'clone-master' ),
            );
        }

        $state_path = $this->session_dir . 'file-scan-state.json';
        $context    = 'export-file-scan:' . $this->session_id;
        $queue_dir  = $this->session_dir . 'queue/';
        if ( ! is_dir( $queue_dir ) && ! wp_mkdir_p( $queue_dir ) ) {
            throw new Exception( __( 'Unable to create the durable file queue directory.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $state = WPCM_Reliability::read_signed_json( $state_path, $context );
        if ( ! is_array( $state ) ) {
            $uploads = wp_upload_dir();
            $roots   = array();
            $presence = array(
                'themes'     => is_dir( get_theme_root() ),
                'plugins'    => is_dir( WP_PLUGIN_DIR ),
                'uploads'    => is_dir( $uploads['basedir'] ),
                'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ),
                'languages'  => is_dir( WP_CONTENT_DIR . '/languages' ),
            );

            if ( $presence['themes'] ) {
                $roots[] = array( 'kind' => 'dir', 'path' => get_theme_root(), 'prefix' => 'themes', 'mode' => 'themes' );
            }
            if ( $presence['plugins'] ) {
                $roots[] = array( 'kind' => 'dir', 'path' => WP_PLUGIN_DIR, 'prefix' => 'plugins', 'mode' => 'plugins' );
            }
            if ( $presence['uploads'] ) {
                $roots[] = array( 'kind' => 'dir', 'path' => $uploads['basedir'], 'prefix' => 'uploads', 'mode' => 'uploads' );
            }
            if ( $presence['mu-plugins'] ) {
                $roots[] = array( 'kind' => 'dir', 'path' => WPMU_PLUGIN_DIR, 'prefix' => 'mu-plugins', 'mode' => 'mu-plugins' );
            }
            if ( $presence['languages'] ) {
                $roots[] = array( 'kind' => 'dir', 'path' => WP_CONTENT_DIR . '/languages', 'prefix' => 'languages', 'mode' => 'languages' );
            }
            foreach ( array( 'fatal-error-handler.php', 'maintenance.php' ) as $dropin ) {
                $path = WP_CONTENT_DIR . '/' . $dropin;
                if ( is_file( $path ) ) {
                    $roots[] = array( 'kind' => 'file', 'path' => $path, 'prefix' => 'dropins/' . $dropin, 'mode' => 'dropins' );
                }
            }

            $state = array(
                'roots'           => $roots,
                'root_index'      => 0,
                'stack'           => array(),
                'current_entries' => array(),
                'current_bytes'   => 0,
                'job_index'       => 0,
                'processed'       => 0,
            );
            $this->manifest['content_presence'] = $presence;
            $this->manifest['file_queue']       = array();
            $this->manifest['file_queue_idx']   = 0;
            WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );
            $this->save_manifest();
        }

        $started   = microtime( true );
        $processed = 0;
        while ( ( microtime( true ) - $started ) < 2.5 && $processed < 2000 ) {
            if ( empty( $state['stack'] ) ) {
                if ( $state['root_index'] >= count( $state['roots'] ) ) {
                    break;
                }
                $root = $state['roots'][ $state['root_index'] ];
                $state['root_index']++;

                if ( 'file' === $root['kind'] ) {
                    $this->scan_add_file_entry( $state, $root['path'], $root['prefix'] );
                    $processed++;
                    $state['processed']++;
                    if ( count( $state['current_entries'] ) >= 500 || $state['current_bytes'] >= 128 * MB_IN_BYTES ) {
                        $this->flush_file_scan_job( $state, $context );
                    }
                    continue;
                }

                $names = @scandir( $root['path'] );
                if ( false === $names ) {
                    throw new Exception( sprintf( __( 'Unable to scan directory %s.', 'clone-master' ), $root['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                $names = array_values( array_diff( $names, array( '.', '..' ) ) );
                sort( $names, SORT_STRING );
                $state['current_entries'][] = array(
                    'kind'     => 'dir',
                    'path'     => $root['path'],
                    'relative' => $root['prefix'],
                    'size'     => 0,
                    'mtime'    => (int) @filemtime( $root['path'] ),
                );
                $state['stack'][] = array(
                    'path'   => $root['path'],
                    'prefix' => $root['prefix'],
                    'mode'   => $root['mode'],
                    'names'  => $names,
                    'index'  => 0,
                );
                continue;
            }

            $top_index = count( $state['stack'] ) - 1;
            if ( $state['stack'][ $top_index ]['index'] >= count( $state['stack'][ $top_index ]['names'] ) ) {
                array_pop( $state['stack'] );
                continue;
            }

            $name = $state['stack'][ $top_index ]['names'][ $state['stack'][ $top_index ]['index'] ];
            $state['stack'][ $top_index ]['index']++;
            $parent   = $state['stack'][ $top_index ];
            $path     = rtrim( $parent['path'], '/\\' ) . '/' . $name;
            $relative = trim( $parent['prefix'] . '/' . $name, '/' );

            if ( $this->should_exclude_scan_entry( $parent['mode'], $relative, $name ) ) {
                continue;
            }
            if ( is_link( $path ) ) {
                throw new Exception( sprintf( __( 'Symbolic links are not supported in reliable backups: %s', 'clone-master' ), $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            if ( is_dir( $path ) ) {
                $names = @scandir( $path );
                if ( false === $names ) {
                    throw new Exception( sprintf( __( 'Unable to scan directory %s.', 'clone-master' ), $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                $names = array_values( array_diff( $names, array( '.', '..' ) ) );
                sort( $names, SORT_STRING );
                $state['current_entries'][] = array(
                    'kind'     => 'dir',
                    'path'     => $path,
                    'relative' => $relative,
                    'size'     => 0,
                    'mtime'    => (int) @filemtime( $path ),
                );
                $state['stack'][] = array(
                    'path'   => $path,
                    'prefix' => $relative,
                    'mode'   => $parent['mode'],
                    'names'  => $names,
                    'index'  => 0,
                );
            } elseif ( is_file( $path ) ) {
                $this->scan_add_file_entry( $state, $path, $relative );
            } else {
                throw new Exception( sprintf( __( 'A scanned path disappeared or is not a regular file: %s', 'clone-master' ), $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            $processed++;
            $state['processed']++;
            if ( count( $state['current_entries'] ) >= 500 || $state['current_bytes'] >= 128 * MB_IN_BYTES ) {
                $this->flush_file_scan_job( $state, $context );
            }
        }

        if ( empty( $state['stack'] ) && $state['root_index'] >= count( $state['roots'] ) ) {
            if ( ! empty( $state['current_entries'] ) ) {
                $this->flush_file_scan_job( $state, $context );
            }
            $this->manifest['steps_done'][] = 'files_scan';
            $this->manifest['steps_done']   = array_values( array_unique( $this->manifest['steps_done'] ) );
            @unlink( $state_path );
            @unlink( $state_path . '.bak' );
            $this->save_manifest();

            return array(
                'session_id'  => $this->session_id,
                'queue_total' => count( $this->manifest['file_queue'] ),
                'next_step'   => count( $this->manifest['file_queue'] ) ? 'files_archive' : 'config',
                'progress'    => 30,
                'message'     => sprintf(
                    __( '%1$d paths scanned into %2$d bounded append jobs.', 'clone-master' ),
                    (int) $state['processed'],
                    count( $this->manifest['file_queue'] )
                ),
            );
        }

        WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );
        $root_progress = count( $state['roots'] ) > 0 ? (int) floor( 100 * $state['root_index'] / count( $state['roots'] ) ) : 100;
        return array(
            'session_id' => $this->session_id,
            'next_step'  => 'files_scan',
            'progress'   => 25 + min( 5, (int) floor( $root_progress / 20 ) ),
            'message'    => sprintf(
                __( 'Scanning files durably: %1$d paths indexed, %2$d bounded jobs written.', 'clone-master' ),
                (int) $state['processed'],
                count( $this->manifest['file_queue'] )
            ),
        );
    }

    // =========================================================================
    // Step 4: Archive ONE item from queue per AJAX call
    // =========================================================================
    private function step_files_archive() {
        $state_path = $this->session_dir . 'archive-state.json';
        $context    = 'export-wpcm-archive:' . $this->session_id;
        $state      = WPCM_Reliability::read_signed_json( $state_path, $context );

        if ( ! is_array( $state ) ) {
            if ( empty( $this->manifest['package_filename'] ) ) {
                $timestamp = gmdate( 'Ymd\\THis' );
                try {
                    $nonce = bin2hex( random_bytes( 8 ) );
                } catch ( Throwable $error ) {
                    $nonce = wp_generate_password( 16, false, false );
                }
                $secret = get_option( 'wpcm_archive_filename_secret' );
                if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
                    $secret = wp_generate_password( 48, false, false );
                    update_option( 'wpcm_archive_filename_secret', $secret, false );
                }
                $suffix = substr( hash_hmac( 'sha256', $timestamp . '|manual|' . $nonce, $secret ), 0, 12 );
                $this->manifest['package_filename'] = $this->backup_site_filename_prefix() . '-backup-manual-' . $timestamp . '-' . $suffix . '.wpcm';
                $this->save_manifest();
            }

            if ( ! wp_mkdir_p( WPCM_BACKUP_DIR ) ) {
                throw new Exception( __( 'Unable to create the backup directory.', 'clone-master' ) );
            }
            WPCM_Plugin::protect_directory( WPCM_BACKUP_DIR );

            $partial = WPCM_BACKUP_DIR . sanitize_file_name( $this->manifest['package_filename'] ) . '.partial';
            if ( is_file( $partial ) || is_link( $partial ) ) {
                throw new Exception( __( 'An untracked partial WPCM container already exists.', 'clone-master' ) );
            }

            $sql_files = glob( $this->session_dir . 'sql/*.sql' ) ?: array();
            sort( $sql_files, SORT_STRING );
            if ( empty( $sql_files ) ) {
                throw new Exception( __( 'The durable SQL export is missing.', 'clone-master' ) );
            }
            $sql_relatives  = array();
            $database_size = 0;
            foreach ( $sql_files as $sql_file ) {
                $size = @filesize( $sql_file );
                if ( false === $size || ! is_readable( $sql_file ) ) {
                    throw new Exception( __( 'A durable SQL chunk is unreadable.', 'clone-master' ) );
                }
                $sql_relatives[] = 'sql/' . basename( $sql_file );
                $database_size += (int) $size;
            }

            $total_files = 0;
            $total_bytes = 0;
            foreach ( $this->manifest['file_queue'] ?? array() as $job ) {
                $descriptor = WPCM_Reliability::read_signed_json(
                    $this->session_dir . $job['descriptor'],
                    'export-file-job:' . $this->session_id . ':' . (int) $job['job_index']
                );
                if ( ! is_array( $descriptor ) || ! is_array( $descriptor['entries'] ?? null ) ) {
                    throw new Exception( __( 'A durable file-map descriptor is missing or altered.', 'clone-master' ) );
                }
                foreach ( $descriptor['entries'] as $entry ) {
                    if ( 'file' === ( $entry['kind'] ?? '' ) ) {
                        $total_files++;
                        $total_bytes += (int) ( $entry['size'] ?? 0 );
                    }
                }
            }

            $offset = WPCM_Archive::create( $partial );
            $handle = WPCM_Archive::open_at_checkpoint( $partial, $offset );
            try {
                WPCM_Archive::append_file_header( $handle, 'database.sql', $database_size, time() );
                WPCM_Archive::flush( $handle );
                $offset = ftell( $handle );
            } finally {
                fclose( $handle );
            }

            $database_copy = $this->session_dir . 'database.sql';
            $copy_handle   = @fopen( $database_copy, 'x+b' );
            if ( ! is_resource( $copy_handle ) ) {
                throw new Exception( __( 'Unable to create the durable database stream.', 'clone-master' ) );
            }
            WPCM_Archive::flush( $copy_handle );
            fclose( $copy_handle );

            $state = array(
                'version'              => 1,
                'phase'                => 'database',
                'partial_relative'     => basename( $partial ),
                'archive_offset'       => (int) $offset,
                'database_size'        => (int) $database_size,
                'database_copy_offset' => 0,
                'sql_files'            => $sql_relatives,
                'sql_index'            => 0,
                'sql_offset'           => 0,
                'queue_index'          => 0,
                'entry_index'          => 0,
                'current_entry'        => null,
                'files_count'          => 0,
                'files_size'           => 0,
                'total_files'          => $total_files,
                'total_bytes'          => $total_bytes,
            );
            WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );
        }

        $partial = WPCM_BACKUP_DIR . basename( (string) $state['partial_relative'] );
        if ( ! WPCM_Reliability::path_is_within( $partial, WPCM_BACKUP_DIR ) ) {
            throw new Exception( __( 'The WPCM partial path is invalid.', 'clone-master' ) );
        }
        $archive = WPCM_Archive::open_at_checkpoint( $partial, (int) $state['archive_offset'] );
        $database_copy = $this->session_dir . 'database.sql';
        $copy = @fopen( $database_copy, 'c+b' );
        if ( ! is_resource( $copy ) ) {
            fclose( $archive );
            throw new Exception( __( 'The durable database stream is unavailable.', 'clone-master' ) );
        }
        if ( ! @ftruncate( $copy, (int) $state['database_copy_offset'] ) || 0 !== @fseek( $copy, (int) $state['database_copy_offset'] ) ) {
            fclose( $copy );
            fclose( $archive );
            throw new Exception( __( 'Unable to restore the database stream checkpoint.', 'clone-master' ) );
        }

        $started      = microtime( true );
        $slice_bytes  = 0;
        $target_bytes = max( 2 * MB_IN_BYTES, min( 32 * MB_IN_BYTES, (int) apply_filters( 'wpcm_archive_slice_bytes', 8 * MB_IN_BYTES ) ) );

        try {
            while ( $slice_bytes < $target_bytes && ( microtime( true ) - $started ) < 8.0 ) {
                if ( 'database' === $state['phase'] ) {
                    $sql_index = (int) $state['sql_index'];
                    if ( $sql_index >= count( $state['sql_files'] ) ) {
                        WPCM_Archive::append_file_end( $archive, (int) $state['database_size'] );
                        $state['phase'] = 'files';
                        continue;
                    }
                    $relative = (string) $state['sql_files'][ $sql_index ];
                    if ( ! $this->is_safe_session_relative_path( $relative ) ) {
                        throw new Exception( __( 'The SQL source path is invalid.', 'clone-master' ) );
                    }
                    $source = $this->session_dir . $relative;
                    $input  = @fopen( $source, 'rb' );
                    if ( ! is_resource( $input ) || 0 !== @fseek( $input, (int) $state['sql_offset'] ) ) {
                        if ( is_resource( $input ) ) fclose( $input );
                        throw new Exception( __( 'Unable to resume a durable SQL chunk.', 'clone-master' ) );
                    }
                    $raw = fread( $input, WPCM_Archive::CHUNK_BYTES );
                    fclose( $input );
                    if ( false === $raw ) {
                        throw new Exception( __( 'Unable to read a durable SQL chunk.', 'clone-master' ) );
                    }
                    if ( '' === $raw ) {
                        $state['sql_index']++;
                        $state['sql_offset'] = 0;
                        continue;
                    }
                    WPCM_Archive::append_chunk( $archive, $raw );
                    WPCM_Archive::write_exact( $copy, $raw );
                    $state['sql_offset'] += strlen( $raw );
                    $state['database_copy_offset'] += strlen( $raw );
                    $slice_bytes += strlen( $raw );
                    continue;
                }

                if ( 'files' !== $state['phase'] ) {
                    break;
                }

                $queue = $this->manifest['file_queue'] ?? array();
                if ( (int) $state['queue_index'] >= count( $queue ) ) {
                    $state['phase'] = 'complete';
                    break;
                }
                $job = $queue[ (int) $state['queue_index'] ];
                $descriptor = WPCM_Reliability::read_signed_json(
                    $this->session_dir . $job['descriptor'],
                    'export-file-job:' . $this->session_id . ':' . (int) $job['job_index']
                );
                if ( ! is_array( $descriptor ) || ! is_array( $descriptor['entries'] ?? null ) ) {
                    throw new Exception( __( 'A durable file-map descriptor is missing or altered.', 'clone-master' ) );
                }
                $entries = $descriptor['entries'];
                if ( null === $state['current_entry'] ) {
                    while ( (int) $state['entry_index'] < count( $entries ) && 'file' !== ( $entries[ (int) $state['entry_index'] ]['kind'] ?? '' ) ) {
                        $state['entry_index']++;
                    }
                    if ( (int) $state['entry_index'] >= count( $entries ) ) {
                        $state['queue_index']++;
                        $state['entry_index'] = 0;
                        continue;
                    }
                    $entry = $entries[ (int) $state['entry_index'] ];
                    $source = (string) ( $entry['path'] ?? '' );
                    $relative = ltrim( str_replace( '\\', '/', (string) ( $entry['relative'] ?? '' ) ), '/' );
                    if ( ! is_file( $source ) || is_link( $source ) || ! is_readable( $source )
                        || (int) @filesize( $source ) !== (int) $entry['size']
                        || (int) @filemtime( $source ) !== (int) $entry['mtime'] ) {
                        throw new Exception( sprintf( __( 'Backup source changed after scanning: %s', 'clone-master' ), $source ) );
                    }
                    $archive_entry = 'wp-content/' . $relative;
                    WPCM_Archive::append_file_header( $archive, $archive_entry, (int) $entry['size'], (int) $entry['mtime'] );
                    $state['current_entry'] = array(
                        'source'        => $source,
                        'relative'      => $relative,
                        'archive_entry' => $archive_entry,
                        'size'          => (int) $entry['size'],
                        'mtime'         => (int) $entry['mtime'],
                        'source_offset' => 0,
                    );
                }

                $current = $state['current_entry'];
                $input   = @fopen( $current['source'], 'rb' );
                if ( ! is_resource( $input ) || 0 !== @fseek( $input, (int) $current['source_offset'] ) ) {
                    if ( is_resource( $input ) ) fclose( $input );
                    throw new Exception( __( 'Unable to resume a source file.', 'clone-master' ) );
                }
                $remaining = (int) $current['size'] - (int) $current['source_offset'];
                $raw       = $remaining > 0 ? fread( $input, min( WPCM_Archive::CHUNK_BYTES, $remaining ) ) : '';
                fclose( $input );
                if ( false === $raw ) {
                    throw new Exception( __( 'Unable to read a source file.', 'clone-master' ) );
                }
                if ( '' === $raw ) {
                    if ( (int) $current['source_offset'] !== (int) $current['size']
                        || (int) @filesize( $current['source'] ) !== (int) $current['size']
                        || (int) @filemtime( $current['source'] ) !== (int) $current['mtime'] ) {
                        throw new Exception( sprintf( __( 'Backup source changed while archiving: %s', 'clone-master' ), $current['source'] ) );
                    }
                    WPCM_Archive::append_file_end( $archive, (int) $current['size'] );
                    $state['files_count']++;
                    $state['files_size'] += (int) $current['size'];
                    $state['entry_index']++;
                    $state['current_entry'] = null;
                    continue;
                }
                WPCM_Archive::append_chunk( $archive, $raw );
                $state['current_entry']['source_offset'] += strlen( $raw );
                $slice_bytes += strlen( $raw );
            }

            WPCM_Archive::flush( $archive );
            WPCM_Archive::flush( $copy );
            $state['archive_offset']       = (int) ftell( $archive );
            $state['database_copy_offset'] = (int) ftell( $copy );
        } finally {
            fclose( $copy );
            fclose( $archive );
        }

        WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );

        if ( 'complete' === $state['phase'] ) {
            if ( (int) $state['files_count'] !== (int) $state['total_files']
                || (int) $state['files_size'] !== (int) $state['total_bytes']
                || (int) $state['database_copy_offset'] !== (int) $state['database_size'] ) {
                throw new Exception( __( 'The WPCM inventory does not match the durable scan plan.', 'clone-master' ) );
            }
            $this->manifest['files_count'] = (int) $state['files_count'];
            $this->manifest['files_size']  = (int) $state['files_size'];
            $this->manifest['steps_done'][] = 'files_archive';
            $this->manifest['steps_done'] = array_values( array_unique( $this->manifest['steps_done'] ) );
            $this->save_manifest();
            return array(
                'session_id' => $this->session_id,
                'next_step'  => 'config',
                'progress'   => 70,
                'message'    => __( 'All database and file blocks were durably appended to the WPCM container.', 'clone-master' ),
            );
        }

        $progress = 30;
        if ( 'database' === $state['phase'] ) {
            $progress += (int) floor( 10 * (int) $state['database_copy_offset'] / max( 1, (int) $state['database_size'] ) );
        } else {
            $progress = 40 + (int) floor( 30 * (int) $state['files_size'] / max( 1, (int) $state['total_bytes'] ) );
        }
        $current_name = is_array( $state['current_entry'] ) ? (string) $state['current_entry']['archive_entry'] : 'database.sql';
        return array(
            'session_id'  => $this->session_id,
            'next_step'   => 'files_archive',
            'progress'    => min( 69, $progress ),
            'queue_done'  => (int) $state['queue_index'],
            'queue_total' => count( $this->manifest['file_queue'] ?? array() ),
            'message'     => sprintf( __( 'Appending verified WPCM blocks: %s', 'clone-master' ), $current_name ),
        );
    }

    /**
     * Add a regular file to the current durable scan chunk.
     *
     * @param array  $state    Scan state.
     * @param string $path     Absolute path.
     * @param string $relative Portable relative path.
     * @return void
     */
    private function scan_add_file_entry( array &$state, $path, $relative ) {
        $size = @filesize( $path );
        $mtime = @filemtime( $path );
        if ( false === $size || false === $mtime || ! is_readable( $path ) ) {
            throw new Exception( sprintf( __( 'Unable to read backup source file: %s', 'clone-master' ), $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $state['current_entries'][] = array(
            'kind'     => 'file',
            'path'     => $path,
            'relative' => ltrim( str_replace( '\\', '/', $relative ), '/' ),
            'size'     => (int) $size,
            'mtime'    => (int) $mtime,
        );
        $state['current_bytes'] += (int) $size;
    }

    /**
     * Persist one bounded file-map job.
     *
     * @param array  $state   Scan state.
     * @param string $context Scan HMAC context.
     * @return void
     */
    private function flush_file_scan_job( array &$state, $context ) {
        if ( empty( $state['current_entries'] ) ) {
            return;
        }
        $job_index  = (int) $state['job_index'];
        $descriptor = 'queue/job-' . sprintf( '%08d', $job_index ) . '.json';
        WPCM_Reliability::atomic_signed_json(
            $this->session_dir . $descriptor,
            array(
                'entries' => array_values( $state['current_entries'] ),
                'bytes'   => (int) $state['current_bytes'],
            ),
            'export-file-job:' . $this->session_id . ':' . $job_index
        );
        $job_key = 'chunk/' . sprintf( '%08d', $job_index );
        $known   = false;
        foreach ( $this->manifest['file_queue'] as $queued_job ) {
            if ( ( $queued_job['key'] ?? '' ) === $job_key ) {
                $known = true;
                break;
            }
        }
        if ( ! $known ) {
            $this->manifest['file_queue'][] = array(
                'key'        => $job_key,
                'descriptor' => $descriptor,
                'job_index'  => $job_index,
                                'label'      => sprintf( __( 'Bounded file component %d', 'clone-master' ), $job_index + 1 ),
                'type'       => 'file_map',
            );
            $this->save_manifest();
        }
        $state['job_index']++;
        $state['current_entries'] = array();
        $state['current_bytes']   = 0;
        WPCM_Reliability::atomic_signed_json( $this->session_dir . 'file-scan-state.json', $state, $context );
    }

    /**
     * Apply conservative, explicit backup exclusions.
     *
     * @param string $mode     Root mode.
     * @param string $relative Relative path.
     * @param string $basename Basename.
     * @return bool
     */
    private function should_exclude_scan_entry( $mode, $relative, $basename ) {
        $parts = explode( '/', str_replace( '\\', '/', $relative ) );
        $current_plugin = basename( rtrim( WPCM_PLUGIN_DIR, '/\\' ) );

        if ( 'plugins' === $mode && isset( $parts[1] ) && $parts[1] === $current_plugin ) {
            return true;
        }
        if ( 'mu-plugins' === $mode && '000-wpcm-recovery-bootstrap.php' === $basename ) {
            return true;
        }
        if ( in_array( $basename, array( '.git', '.svn', 'node_modules', 'wpcm-backups', 'wpcm-temp', 'wpcm-logs' ), true ) ) {
            return true;
        }
        if ( in_array( $basename, array( 'debug.log', 'error_log', 'error.log' ), true ) ) {
            return true;
        }
        return false;
    }

    /**
     * Validate a session-relative descriptor path.
     *
     * @param string $path Relative path.
     * @return bool
     */
    private function is_safe_session_relative_path( $path ) {
        if ( '' === $path || '/' === $path[0] || '\\' === $path[0] || false !== strpos( $path, "\0" ) ) {
            return false;
        }
        foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $part ) {
            if ( '..' === $part ) {
                return false;
            }
        }
        return true;
    }

    // =========================================================================
    // Step 5: Capture configuration
    // =========================================================================
    private function step_config() {
        if ( ! in_array( 'config', $this->manifest['steps_done'] ?? array(), true ) ) {
            $this->manifest['root_files']   = array();
            $this->manifest['steps_done'][] = 'config';
            $this->manifest['steps_done']   = array_values( array_unique( $this->manifest['steps_done'] ) );
            $this->save_manifest();
        }
        return array(
            'session_id' => $this->session_id,
            'next_step'  => 'package',
            'progress'   => 75,
            'message'    => __( 'Portable metadata captured. Finalizing the WPCM container...', 'clone-master' ),
        );
    }

    // =========================================================================
    // Step 6: Package
    // =========================================================================
    private function step_package() {
        $filename = sanitize_file_name( (string) ( $this->manifest['package_filename'] ?? '' ) );
        $domain_first = preg_match( '/^[a-z0-9](?:[a-z0-9.-]{0,99})-backup-manual-[0-9]{8}T[0-9]{6}-[a-f0-9]{12}\.wpcm$/', $filename );
        $legacy_name  = preg_match( '/^backup-manual-[0-9]{8}T[0-9]{6}-[a-f0-9]{12}\.wpcm$/', $filename );
        if ( ! $domain_first && ! $legacy_name ) {
            throw new Exception( __( 'The WPCM package filename is invalid.', 'clone-master' ) );
        }
        $final   = WPCM_BACKUP_DIR . $filename;
        $partial = $final . '.partial';

        if ( is_file( $final ) ) {
            try {
                $inspection = WPCM_Archive::inspect( $final );
            } catch ( Throwable $error ) {
                throw new Exception( __( 'The published WPCM container failed validation.', 'clone-master' ) );
            }
            return array(
                'session_id'   => $this->session_id,
                'next_step'    => 'cleanup',
                'progress'     => 95,
                'filename'     => $filename,
                'size'         => size_format( filesize( $final ) ),
                'sha256'       => (string) $inspection['file_sha256'],
                'download_url' => admin_url( 'admin-ajax.php?action=wpcm_download_backup&backup_name=' . rawurlencode( $filename ) . '&nonce=' . wp_create_nonce( 'wpcm_nonce' ) ),
                'message'      => __( 'The verified WPCM container was already published.', 'clone-master' ),
            );
        }

        $state = WPCM_Reliability::read_signed_json(
            $this->session_dir . 'archive-state.json',
            'export-wpcm-archive:' . $this->session_id
        );
        if ( ! is_array( $state ) || 'complete' !== ( $state['phase'] ?? '' ) || ! is_file( $partial ) ) {
            throw new Exception( __( 'The append-only WPCM payload is incomplete.', 'clone-master' ) );
        }

        $database_path = $this->session_dir . 'database.sql';
        $database_hash = is_file( $database_path ) ? hash_file( 'sha256', $database_path ) : false;
        if ( ! is_string( $database_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $database_hash ) ) {
            throw new Exception( __( 'Unable to verify the database stream before finalization.', 'clone-master' ) );
        }

        $this->manifest['schema_version']       = '2.0';
        $this->manifest['format']               = 'wpcm-append-only';
        $this->manifest['type']                 = 'manual';
        $this->manifest['site_url']             = trailingslashit( home_url() );
        $this->manifest['home_url']             = trailingslashit( home_url() );
        $this->manifest['table_prefix']         = (string) $this->manifest['db_prefix'];
        $this->manifest['database_consistency'] = 'live-sliced-validated';
        $this->manifest['files_count']          = (int) $state['files_count'];
        $this->manifest['files_size']           = (int) $state['files_size'];
        $this->manifest['sha256_database']      = $database_hash;
        $this->manifest['completed_at']         = gmdate( 'c' );
        $this->manifest['steps_done'][]         = 'package';
        $this->manifest['steps_done']           = array_values( array_unique( $this->manifest['steps_done'] ) );
        unset( $this->manifest['file_queue'], $this->manifest['file_queue_idx'], $this->manifest['archives'], $this->manifest['checksums'], $this->manifest['integrity'] );
        $this->save_manifest();

        // A retry may arrive after finalize() but before rename(). Detect that
        // durable state by validating the partial container first.
        $finalized = false;
        try {
            WPCM_Archive::inspect( $partial );
            $finalized = true;
        } catch ( Throwable $ignored ) {
            $finalized = false;
        }
        if ( ! $finalized ) {
            WPCM_Archive::finalize( $partial, $this->manifest );
        }
        $inspection = WPCM_Archive::inspect( $partial );
        if ( ! @rename( $partial, $final ) ) {
            throw new Exception( __( 'Unable to atomically publish the WPCM container.', 'clone-master' ) );
        }
        WPCM_Reliability::atomic_write( $final . '.sha256', (string) $inspection['file_sha256'] . "\n", 0640 );

        return array(
            'session_id'   => $this->session_id,
            'next_step'    => 'cleanup',
            'progress'     => 95,
            'filename'     => $filename,
            'size'         => size_format( filesize( $final ) ),
            'sha256'       => (string) $inspection['file_sha256'],
            'download_url' => admin_url( 'admin-ajax.php?action=wpcm_download_backup&backup_name=' . rawurlencode( $filename ) . '&nonce=' . wp_create_nonce( 'wpcm_nonce' ) ),
            'message'      => sprintf( __( 'WPCM container finalized, fully verified, and atomically published: %s', 'clone-master' ), $filename ),
        );
    }


    // =========================================================================
    // Step 7: Cleanup
    // =========================================================================
    private function step_cleanup() {
        foreach ( array( 'sql', 'files', 'queue' ) as $name ) {
            $path = $this->session_dir . $name . '/';
            if ( is_dir( $path ) ) {
                $this->recursive_delete( $path );
            }
        }
        foreach ( glob( $this->session_dir . '*.partial' ) ?: array() as $partial ) {
            @unlink( $partial );
        }
        foreach ( array( 'file-scan-state.json', 'file-scan-state.json.bak', 'archive-state.json', 'archive-state.json.bak', 'database.sql' ) as $runtime_file ) {
            @unlink( $this->session_dir . $runtime_file );
        }
        WPCM_Reliability::atomic_signed_json(
            $this->session_dir . 'completed.json',
            array(
                'completed_at' => gmdate( 'c' ),
                'filename'     => (string) ( $this->manifest['package_filename'] ?? '' ),
            ),
            'export-completed:' . $this->session_id
        );

        return array(
            'session_id' => $this->session_id,
            'next_step'  => null,
            'progress'   => 100,
            'message'    => __( 'Export complete. Heavy temporary data was removed and a small retry-safe completion marker was retained.', 'clone-master' ),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================



    /**
     * Identify export runtime files that must never be included in a backup package.
     *
     * @param string $relative Relative session path.
     * @return bool
     */
    private function is_internal_runtime_path( $relative ) {
        $relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
        if ( in_array( $relative, array( '.htaccess', 'index.php', 'README.nginx.txt', 'manifest-state.json', 'manifest-state.json.bak', 'completed.json', 'file-scan-state.json', 'file-scan-state.json.bak' ), true ) ) {
            return true;
        }
        if ( 0 === strpos( $relative, 'queue/' ) ) {
            return true;
        }
        return (bool) preg_match( '/(?:^|\/)(?:[^\/]+\.lock|[^\/]+\.bak|[^\/]+\.partial|[^\/]+\.tmp-[^\/]+)$/', $relative );
    }

    private function save_manifest() {
        WPCM_Reliability::atomic_json( $this->session_dir . 'manifest.json', $this->manifest );
        WPCM_Reliability::atomic_signed_json(
            $this->session_dir . 'manifest-state.json',
            $this->manifest,
            'export-manifest:' . $this->session_id
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
