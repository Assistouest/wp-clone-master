<?php
/**
 * Exporter : Creates a full clone archive of the WordPress site
 *
 * Now with granular sub-steps to avoid timeouts:
 *
 * 1. init           → Create session, detect environment
 * 2. database       → Dump all tables with durable keyset or snapshot cursors
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
        $this->db_cleanup_stale_snapshots();

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
            'next_step'   => 'files_scan',
            'phase'       => 'inventory',
            'progress'    => 3,
            'message'     => __( 'Session initialized. Building the exact site inventory...', 'clone-master' ),
        ];
    }

    // =========================================================================
    // Step 2: Export database : durable chunks + resource-aware adaptive batches
    //
    // Each AJAX request exports a bounded set of rows and persists an authenticated
    // cursor. The next batch is calculated from the last complete sample using:
    //
    // - wall-clock duration measured by Clone Master rather than PHP CPU time alone;
    // - absolute PHP peak memory against the effective memory limit;
    // - escaped SQL bytes generated by the request;
    // - observed bytes per exported row;
    // - MySQL max_allowed_packet for each individual INSERT statement.
    //
    // Reductions are immediate. Increases require two consecutive healthy,
    // full-sized samples and are limited to 50% at a time. This hysteresis avoids
    // oscillation on shared hosting while allowing capable servers to climb from
    // 2,000 rows to as many as 50,000 rows per request for lightweight tables.
    // =========================================================================

    /** Normal lower bound. Resource caps may still reduce a batch below this. */
    const WPCM_DB_ROWS_MIN = 25;

    /** Absolute upper bound for lightweight rows on capable hosting. */
    const WPCM_DB_ROWS_MAX = 50000;

    /** Conservative first request before server-specific measurements exist. */
    const WPCM_DB_ROWS_INITIAL = 2000;

    /** Maximum rows in one INSERT statement. Byte limits can lower this further. */
    const WPCM_DB_INSERT_CHUNK = 100;

    /** Preferred escaped SQL volume generated by one AJAX request. */
    const WPCM_DB_SQL_TARGET_BYTES = 8388608; // 8 MiB.

    /** Absolute escaped SQL volume ceiling used to derive a safe row cap. */
    const WPCM_DB_SQL_HARD_BYTES = 16777216; // 16 MiB.

    /** Preferred maximum size of one INSERT statement for portable restores. */
    const WPCM_DB_INSERT_TARGET_BYTES = 1048576; // 1 MiB.

    /**
     * Return the target wall-clock duration for one database AJAX request.
     *
     * PHP's max_execution_time does not consistently include database and stream
     * time on every platform. Clone Master therefore measures wall-clock time and
     * targets a short request that also remains below common proxy timeouts.
     *
     * @return float Seconds.
     */
    private function db_time_budget() {
        $configured = (int) ini_get( 'max_execution_time' );
        $reference  = $configured > 0 ? $configured : 30;
        $budget     = max( 2.0, min( 5.0, $reference * 0.15 ) );
        $filtered   = (float) apply_filters( 'wpcm_db_adaptive_time_budget', $budget, $configured );

        return max( 1.0, min( 10.0, $filtered ) );
    }

    /**
     * Return the effective PHP memory limit in bytes, or zero when unlimited.
     *
     * @return int
     */
    private function db_memory_limit() {
        $value = (string) ini_get( 'memory_limit' );
        if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
            $bytes = (int) wp_convert_hr_to_bytes( $value );
            return $bytes > 0 ? $bytes : 0;
        }

        return $this->return_bytes_local( $value );
    }

    /**
     * Probe average escaped SQL bytes per row.
     *
     * The estimate combines a bounded 32-row sample with MySQL's table-level
     * AVG_ROW_LENGTH metadata. Taking the larger estimate protects the first
     * 2,000-row request when the opening rows are unusually small.
     *
     * @param string $table Table name.
     * @return int
     */
    private function db_probe_row_size( $table ) {
        global $wpdb;
        $identifier = $this->quote_identifier( $table );
        $sample     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted.
            'SELECT * FROM ' . $identifier . ' LIMIT 32',
            ARRAY_A
        );
        $sample_average = 0;

        if ( is_array( $sample ) && ! empty( $sample ) ) {
            $total = 0;
            foreach ( $sample as $row ) {
                $total += array_sum( array_map( 'strlen', array_keys( $row ) ) )
                    + array_sum(
                        array_map(
                            static function ( $value ) {
                                return null === $value ? 4 : strlen( (string) $value ) * 2 + 3;
                            },
                            $row
                        )
                    )
                    + 30;
            }
            $sample_average = (int) ceil( $total / count( $sample ) );
        }

        $storage_average = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live table metadata.
            $wpdb->prepare(
                'SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s LIMIT 1',
                $table
            )
        );
        $storage_estimate = $storage_average > 0 ? (int) ceil( $storage_average * 1.35 ) : 0;

        return max( 1, $sample_average, $storage_estimate );
    }

    /**
     * Return MySQL max_allowed_packet in bytes.
     *
     * @return int
     */
    private function db_max_allowed_packet() {
        global $wpdb;
        $value = $wpdb->get_var( 'SELECT @@max_allowed_packet' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live server variable.

        return $value ? (int) $value : 1024 * 1024;
    }

    /**
     * Calculate SQL-byte targets and the resulting row ceiling.
     *
     * Smaller memory limits receive smaller SQL targets because wpdb result sets
     * and associative PHP arrays require substantially more memory than raw SQL.
     *
     * @param int $avg_row_bytes Estimated escaped SQL bytes per row.
     * @param int $memory_limit  Effective memory limit in bytes, zero if unlimited.
     * @param int $memory_used   Current or peak request memory in bytes.
     * @return array{target_sql_bytes:int,hard_sql_bytes:int,row_cap:int}
     */
    private function db_resource_limits( $avg_row_bytes, $memory_limit, $memory_used ) {
        $target_sql = self::WPCM_DB_SQL_TARGET_BYTES;
        $hard_sql   = self::WPCM_DB_SQL_HARD_BYTES;

        if ( $memory_limit > 0 ) {
            $headroom   = max( 0, $memory_limit - max( 0, $memory_used ) );
            $target_sql = min( $target_sql, max( 1024 * 1024, (int) floor( $headroom * 0.08 ) ) );
            $hard_sql   = min( $hard_sql, max( 2 * 1024 * 1024, (int) floor( $headroom * 0.16 ) ) );
            $hard_sql   = max( $target_sql, $hard_sql );
        }

        $filtered = apply_filters(
            'wpcm_db_adaptive_limits',
            array(
                'target_sql_bytes' => $target_sql,
                'hard_sql_bytes'   => $hard_sql,
                'max_rows'         => self::WPCM_DB_ROWS_MAX,
            ),
            array(
                'avg_row_bytes' => (int) $avg_row_bytes,
                'memory_limit'  => (int) $memory_limit,
                'memory_used'   => (int) $memory_used,
            )
        );

        if ( is_array( $filtered ) ) {
            $target_sql = max( 262144, min( 64 * 1024 * 1024, (int) ( $filtered['target_sql_bytes'] ?? $target_sql ) ) );
            $hard_sql   = max( $target_sql, min( 128 * 1024 * 1024, (int) ( $filtered['hard_sql_bytes'] ?? $hard_sql ) ) );
            $max_rows   = max( 1, min( self::WPCM_DB_ROWS_MAX, (int) ( $filtered['max_rows'] ?? self::WPCM_DB_ROWS_MAX ) ) );
        } else {
            $max_rows = self::WPCM_DB_ROWS_MAX;
        }

        $row_cap = $max_rows;
        if ( $avg_row_bytes > 0 ) {
            $row_cap = min( $row_cap, max( 1, (int) floor( $hard_sql / $avg_row_bytes ) ) );
        }

        return array(
            'target_sql_bytes' => $target_sql,
            'hard_sql_bytes'   => $hard_sql,
            'row_cap'          => max( 1, $row_cap ),
        );
    }

    /**
     * Clamp and round a batch to a readable, stable value.
     *
     * @param int $rows    Requested rows.
     * @param int $row_cap Resource-derived ceiling.
     * @return int
     */
    private function db_clamp_rows( $rows, $row_cap ) {
        $row_cap = max( 1, min( self::WPCM_DB_ROWS_MAX, (int) $row_cap ) );
        $minimum = min( self::WPCM_DB_ROWS_MIN, $row_cap );
        $rows    = max( $minimum, min( $row_cap, (int) $rows ) );

        if ( $rows <= 100 ) {
            $step = 5;
        } elseif ( $rows <= 1000 ) {
            $step = 25;
        } elseif ( $rows <= 10000 ) {
            $step = 100;
        } else {
            $step = 500;
        }

        if ( $rows >= $step ) {
            $rows = (int) floor( $rows / $step ) * $step;
        }

        return max( $minimum, min( $row_cap, $rows ) );
    }

    /**
     * Calculate the next batch from one measured request.
     *
     * @param int   $rows_per_call Current requested rows.
     * @param float $duration      Wall-clock seconds.
     * @param int   $memory_peak   Absolute PHP peak memory in bytes.
     * @param int   $memory_limit  Effective PHP memory limit in bytes.
     * @param int   $sql_bytes     Escaped SQL bytes generated.
     * @param int   $rows_done     Rows actually exported.
     * @param int   $avg_row_bytes Previous average escaped bytes per row.
     * @param int   $growth_streak Consecutive healthy full samples.
     * @param bool  $full_sample   Whether the request filled its requested batch.
     * @return array<string,int|float|string>
     */
    private function db_adapt_rows( $rows_per_call, $duration, $memory_peak, $memory_limit, $sql_bytes, $rows_done, $avg_row_bytes, $growth_streak, $full_sample ) {
        $observed_row_bytes = $rows_done > 0 && $sql_bytes > 0 ? (int) ceil( $sql_bytes / $rows_done ) : 0;
        if ( $observed_row_bytes > 0 ) {
            $avg_row_bytes = $avg_row_bytes > 0
                ? (int) ceil( $avg_row_bytes * 0.65 + $observed_row_bytes * 0.35 )
                : $observed_row_bytes;
        }

        $limits       = $this->db_resource_limits( $avg_row_bytes, $memory_limit, $memory_peak );
        $time_budget  = $this->db_time_budget();
        $memory_ratio = $memory_limit > 0 ? $memory_peak / $memory_limit : 0.0;
        $factor       = 1.0;
        $reason       = 'stable';

        // Immediate reductions use the most restrictive active safety signal.
        if ( $memory_limit > 0 && $memory_ratio >= 0.82 ) {
            $factor = 0.35;
            $reason = 'reduced_memory';
        } elseif ( $memory_limit > 0 && $memory_ratio >= 0.72 ) {
            $factor = 0.55;
            $reason = 'reduced_memory';
        }

        if ( $duration > $time_budget ) {
            $time_factor = $duration >= $time_budget * 1.5
                ? 0.50
                : max( 0.45, min( 0.85, ( $time_budget / max( 0.001, $duration ) ) * 0.85 ) );
            if ( $time_factor < $factor ) {
                $factor = $time_factor;
                $reason = 'reduced_time';
            }
        }

        if ( $sql_bytes > $limits['hard_sql_bytes'] ) {
            $sql_factor = max( 0.25, min( 0.80, ( $limits['hard_sql_bytes'] / max( 1, $sql_bytes ) ) * 0.80 ) );
            if ( $sql_factor < $factor ) {
                $factor = $sql_factor;
                $reason = 'reduced_sql';
            }
        } elseif ( $sql_bytes > (int) floor( $limits['target_sql_bytes'] * 1.15 ) ) {
            $sql_factor = max( 0.50, min( 0.95, $limits['target_sql_bytes'] / max( 1, $sql_bytes ) ) );
            if ( $sql_factor < $factor ) {
                $factor = $sql_factor;
                $reason = 'reduced_sql';
            }
        }

        $next_rows = $rows_per_call;
        if ( $factor < 0.99 ) {
            $next_rows     = (int) floor( $rows_per_call * $factor );
            $growth_streak = 0;
        } else {
            $healthy_memory = 0.0 === $memory_ratio || $memory_ratio < 0.55;
            $healthy_time   = $duration > 0 && $duration < $time_budget * 0.65;
            $healthy_sql    = 0 === $sql_bytes || $sql_bytes < $limits['target_sql_bytes'] * 0.70;

            if ( $full_sample && $healthy_memory && $healthy_time && $healthy_sql ) {
                $growth_streak++;
                if ( $growth_streak >= 2 ) {
                    $time_growth = min( 1.50, ( $time_budget * 0.85 ) / max( 0.001, $duration ) );
                    $sql_growth  = $sql_bytes > 0
                        ? min( 1.50, ( $limits['target_sql_bytes'] * 0.85 ) / $sql_bytes )
                        : 1.50;
                    $growth      = max( 1.10, min( 1.50, $time_growth, $sql_growth ) );
                    $next_rows   = (int) ceil( $rows_per_call * $growth );
                    $growth_streak = 0;
                    $reason      = 'increased';
                } else {
                    $reason = 'learning';
                }
            } else {
                $growth_streak = 0;
            }
        }

        $before_cap = $next_rows;
        $next_rows  = $this->db_clamp_rows( $next_rows, $limits['row_cap'] );
        if ( $next_rows < $before_cap && 'reduced_memory' !== $reason && 'reduced_time' !== $reason && 'reduced_sql' !== $reason ) {
            $reason = 'limited_row_size';
        }

        return array(
            'rows_per_call'    => $next_rows,
            'growth_streak'    => $growth_streak,
            'avg_row_bytes'    => $avg_row_bytes,
            'reason'           => $reason,
            'time_budget'      => $time_budget,
            'target_sql_bytes' => $limits['target_sql_bytes'],
            'hard_sql_bytes'   => $limits['hard_sql_bytes'],
            'memory_ratio'     => $memory_ratio,
            'row_cap'          => $limits['row_cap'],
        );
    }

    /**
     * Tiny local byte-string parser used only as a pre-WordPress fallback.
     *
     * @param string $val PHP ini string such as 256M, 1G, or -1.
     * @return int Bytes, or zero when unlimited.
     */
    private function return_bytes_local( $val ) {
        $val = trim( (string) $val );
        if ( '' === $val ) {
            return 0;
        }

        $last = strtolower( substr( $val, -1 ) );
        $int  = (int) $val;
        if ( $int < 0 ) {
            return 0;
        }

        switch ( $last ) {
            case 'g':
                $int *= 1024;
                // Fall through.
            case 'm':
                $int *= 1024;
                // Fall through.
            case 'k':
                $int *= 1024;
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
                'next_step'    => in_array( 'files_scan', $this->manifest['steps_done'] ?? array(), true ) ? ( ! empty( $this->manifest['file_queue'] ) ? 'files_archive' : 'config' ) : 'files_scan',
                'phase'        => 'database',
                'inventory_ready' => true,
                'inventory_files' => (int) ( $this->manifest['inventory']['files'] ?? 0 ),
                'inventory_directories' => (int) ( $this->manifest['inventory']['directories'] ?? 0 ),
                'inventory_files_bytes' => (int) ( $this->manifest['inventory']['files_bytes'] ?? 0 ),
                'inventory_database_bytes' => (int) ( $this->manifest['inventory']['database_bytes'] ?? 0 ),
                'inventory_source_bytes' => (int) ( $this->manifest['inventory']['source_bytes'] ?? 0 ),
                'progress'     => 35,
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
            $header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
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
                'export_mode'    => '',
                'snapshot_table' => '',
                'snapshot_row_id'=> '',
                'source_columns' => array(),
                'current_count'  => 0,
                'rows_exported'  => 0,
                'schema_hash'    => '',
                'schema_portable_hash' => '',
                'chunk_index'    => 0,
                'rows_per_call'  => self::WPCM_DB_ROWS_INITIAL,
                't_prev'         => 0.0,
                'mem_prev'       => 0,
                'avg_row_bytes'  => 0,
                'max_packet'     => 0,
                'growth_streak'  => 0,
                'last_sql_bytes' => 0,
                'last_rows_done' => 0,
                'last_reason'    => 'stable',
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
        $export_mode   = (string) ( $cursor['export_mode'] ?? '' );
        $snapshot_table = (string) ( $cursor['snapshot_table'] ?? '' );
        $snapshot_row_id = (string) ( $cursor['snapshot_row_id'] ?? '' );
        $source_columns  = isset( $cursor['source_columns'] ) && is_array( $cursor['source_columns'] ) ? array_values( array_map( 'strval', $cursor['source_columns'] ) ) : array();
        $total_tables  = count( $tables );
        $rows_per_call = max( self::WPCM_DB_ROWS_MIN, min( self::WPCM_DB_ROWS_MAX, (int) ( $cursor['rows_per_call'] ?? self::WPCM_DB_ROWS_INITIAL ) ) );
        $t_prev        = (float) ( $cursor['t_prev'] ?? 0 );
        $mem_prev       = (int) ( $cursor['mem_prev'] ?? 0 );
        $avg_row_bytes  = (int) ( $cursor['avg_row_bytes'] ?? 0 );
        $max_packet     = (int) ( $cursor['max_packet'] ?? 0 );
        $growth_streak  = max( 0, (int) ( $cursor['growth_streak'] ?? 0 ) );

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
        if ( '' === $export_mode && ! empty( $key_columns ) ) {
            $export_mode = 'keyset';
        }

        if ( 0 === $max_packet ) {
            $max_packet = $this->db_max_allowed_packet();
        }

        if ( $table_idx >= $total_tables ) {
            return $this->finish_database_step( $table_info, $sql_dir, $total_tables );
        }

        $table               = (string) $tables[ $table_idx ];
        $identifier          = $this->quote_identifier( $table );
        $initial_cap_applied = false;
        $file_stub  = sprintf( '%04d_%s', $table_idx + 1, sanitize_file_name( $table ) );

        if ( empty( $cursor['schema_hash'] ) ) {
            $create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $identifier, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted.
            if ( ! is_array( $create ) || empty( $create[1] ) ) {
                throw new Exception( sprintf( __( 'Unable to read the schema for table %s.', 'clone-master' ), $table ) );
            }
            $schema_hash          = hash( 'sha256', $this->normalize_create_table( (string) $create[1] ) );
            $schema_portable_hash = hash( 'sha256', $this->normalize_create_table_portable( (string) $create[1] ) );
            $schema_sql           = 'DROP TABLE IF EXISTS ' . $identifier . ";\n" . $create[1] . ";\n";
            WPCM_Reliability::atomic_write( $sql_dir . $file_stub . '_000000_schema.sql', $schema_sql );

            $count         = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $identifier ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted.
            $avg_row_bytes = $count > 0 ? $this->db_probe_row_size( $table ) : 0;
            $key_meta      = $count > 0 ? $this->db_find_resume_key( $table ) : array( 'index_name' => '', 'columns' => array() );
            $key_columns   = array_values( $key_meta['columns'] ?? array() );
            $key_index     = (string) ( $key_meta['index_name'] ?? '' );
            $export_mode   = 'keyset';

            if ( $count > 0 && empty( $key_columns ) ) {
                try {
                    $snapshot       = $this->db_create_durable_snapshot( $table, $identifier );
                    $export_mode    = 'snapshot';
                    $snapshot_table = (string) $snapshot['table'];
                    $snapshot_row_id = (string) $snapshot['row_id'];
                    $source_columns = $snapshot['columns'];
                    $key_columns    = array( $snapshot_row_id );
                    $key_index      = 'WPCM_SYNTHETIC_SNAPSHOT';
                    $count          = (int) $snapshot['count'];
                    $end_key        = $count > 0 ? $this->db_encode_key_values( array( (string) $snapshot['max_row_id'] ) ) : null;
                } catch ( Exception $snapshot_error ) {
                    if ( ! $this->db_can_export_atomically( $table, $count, $avg_row_bytes ) ) {
                        throw new Exception(
                            sprintf(
                                /* translators: 1: database table name, 2: row count, 3: snapshot creation error. */
                                __( 'Table %1$s contains %2$d row(s) and has no PRIMARY or UNIQUE NOT NULL key. Clone Master could not create a temporary durable snapshot, and the table is too large for the memory-safe atomic fallback. Add a suitable unique key or grant the CREATE, ALTER, and DROP privileges required for the snapshot, then retry. Technical detail: %3$s', 'clone-master' ),
                                $table,
                                $count,
                                $snapshot_error->getMessage()
                            )
                        );
                    }

                    $export_mode    = 'atomic';
                    $snapshot_table = '';
                    $snapshot_row_id = '';
                    $source_columns = $this->db_get_table_columns( $identifier, $table );
                    $key_columns    = array();
                    $key_index      = 'WPCM_ATOMIC_FALLBACK';
                    $end_key        = null;
                }
            } elseif ( $count > 0 ) {
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

            $initial_memory_limit = $this->db_memory_limit();
            $initial_limits       = $this->db_resource_limits( $avg_row_bytes, $initial_memory_limit, memory_get_usage( true ) );
            $rows_before_cap      = $rows_per_call;
            $rows_per_call        = $this->db_clamp_rows( $rows_per_call, $initial_limits['row_cap'] );
            $initial_cap_applied  = $rows_per_call < $rows_before_cap;

            $cursor['current_count'] = $count;
            $cursor['rows_exported'] = 0;
            $cursor['end_key']       = $end_key;
            $cursor['schema_hash']          = $schema_hash;
            $cursor['schema_portable_hash'] = $schema_portable_hash;
            $cursor['key_columns']           = $key_columns;
            $cursor['key_index']     = $key_index;
            $cursor['export_mode']   = $export_mode;
            $cursor['snapshot_table'] = $snapshot_table;
            $cursor['snapshot_row_id']= $snapshot_row_id;
            $cursor['source_columns'] = $source_columns;
            unset( $cursor['key_column'] );
            $cursor['chunk_index']   = 0;
            WPCM_Reliability::atomic_signed_json( $cursor_file, $cursor, $context . ':cursor' );
        } else {
            $count = (int) ( $cursor['current_count'] ?? 0 );
        }

        $requested_rows_per_call = $rows_per_call;
        if ( function_exists( 'memory_reset_peak_usage' ) ) {
            memory_reset_peak_usage();
        }
        $t_start = microtime( true );
        $rows    = array();
        $sql_bytes = 0;

        if ( $count > 0 && 'atomic' !== $export_mode ) {
            if ( empty( $key_columns ) || ! is_array( $end_key ) ) {
                throw new Exception( sprintf( __( 'The durable composite boundary for table %s is missing.', 'clone-master' ), $table ) );
            }

            $read_identifier = $identifier;
            if ( 'snapshot' === $export_mode ) {
                if ( '' === $snapshot_table || '' === $snapshot_row_id || empty( $source_columns ) ) {
                    throw new Exception( sprintf( __( 'The durable snapshot state for table %s is incomplete.', 'clone-master' ), $table ) );
                }
                $read_identifier = $this->quote_identifier( $snapshot_table );
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

            $query_sql = 'SELECT * FROM ' . $read_identifier . ' WHERE ' . implode( ' AND ', $where_parts ) . ' ORDER BY ' . $this->db_key_order_by( $key_columns, 'ASC' ) . ' LIMIT %d';
            $where_values[] = $rows_per_call;
            $query = $this->db_prepare_sql( $query_sql, $where_values );
            $rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are quoted and values are prepared.
            if ( ! is_array( $rows ) ) {
                throw new Exception( sprintf( __( 'Unable to read rows from table %s.', 'clone-master' ), $table ) );
            }
        } elseif ( $count > 0 && 'atomic' === $export_mode ) {
            $rows = $wpdb->get_results( 'SELECT * FROM ' . $identifier, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted. One statement preserves duplicate rows as one atomic read set.
            if ( ! is_array( $rows ) ) {
                throw new Exception( sprintf( __( 'Unable to read rows atomically from table %s.', 'clone-master' ), $table ) );
            }
            $count                   = count( $rows );
            $cursor['current_count'] = $count;
        }

        $chunk_index = (int) ( $cursor['chunk_index'] ?? 0 );
        if ( ! empty( $rows ) ) {
            $last_row = $rows[ count( $rows ) - 1 ];
            if ( 'snapshot' === $export_mode ) {
                $last_key = $this->db_extract_key_from_row( $last_row, $key_columns, $table );
                foreach ( $rows as &$snapshot_row ) {
                    unset( $snapshot_row[ $snapshot_row_id ] );
                }
                unset( $snapshot_row );
            }

            $chunk_sql  = $this->db_build_insert_sql( $table, $rows, $max_packet );
            $sql_bytes   = strlen( $chunk_sql );
            $chunk_path  = $sql_dir . $file_stub . '_data_' . sprintf( '%08d', $chunk_index ) . '.sql';
            WPCM_Reliability::atomic_write( $chunk_path, $chunk_sql );
            if ( 'keyset' === $export_mode ) {
                $last_key = $this->db_extract_key_from_row( $last_row, $key_columns, $table );
            }
            $chunk_index++;
            $cursor['rows_exported'] = (int) ( $cursor['rows_exported'] ?? 0 ) + count( $rows );
        }

        $t_real       = microtime( true ) - $t_start;
        $mem_peak      = memory_get_peak_usage( true );
        $memory_limit  = $this->db_memory_limit();
        $rows_done     = count( $rows );
        $full_sample   = 'atomic' !== $export_mode && $rows_done >= $requested_rows_per_call;
        $adaptive      = $this->db_adapt_rows(
            $requested_rows_per_call,
            $t_real,
            $mem_peak,
            $memory_limit,
            $sql_bytes,
            $rows_done,
            $avg_row_bytes,
            $growth_streak,
            $full_sample
        );
        $rows_per_call = (int) $adaptive['rows_per_call'];
        $growth_streak = (int) $adaptive['growth_streak'];
        $avg_row_bytes = (int) $adaptive['avg_row_bytes'];
        $complete      = 0 === $count || 'atomic' === $export_mode || $rows_done < $requested_rows_per_call;

        $adaptive_reason = (string) $adaptive['reason'];
        if ( $initial_cap_applied && in_array( $adaptive_reason, array( 'stable', 'learning' ), true ) ) {
            $adaptive_reason = 'limited_row_size';
        }
        $adaptive_time      = (float) $adaptive['time_budget'];
        $adaptive_sql_target = (int) $adaptive['target_sql_bytes'];
        $adaptive_row_cap   = (int) $adaptive['row_cap'];

        $snapshot_to_drop = '';

        if ( $complete ) {
            $create_after        = $wpdb->get_row( 'SHOW CREATE TABLE ' . $identifier, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted.
            $after_hash          = is_array( $create_after ) && ! empty( $create_after[1] ) ? hash( 'sha256', $this->normalize_create_table( (string) $create_after[1] ) ) : '';
            $after_portable_hash = is_array( $create_after ) && ! empty( $create_after[1] ) ? hash( 'sha256', $this->normalize_create_table_portable( (string) $create_after[1] ) ) : '';
            if (
                empty( $cursor['schema_hash'] )
                || ! hash_equals( (string) $cursor['schema_hash'], $after_hash )
                || empty( $cursor['schema_portable_hash'] )
                || ! hash_equals( (string) $cursor['schema_portable_hash'], $after_portable_hash )
            ) {
                throw new Exception( sprintf( __( 'The schema of table %s changed during export. Retry the backup when database schema changes have finished.', 'clone-master' ), $table ) );
            }

            $bounded_count = 0;
            if ( $count > 0 ) {
                if ( 'atomic' === $export_mode ) {
                    $bounded_count = $count;
                } else {
                    $count_identifier = 'snapshot' === $export_mode ? $this->quote_identifier( $snapshot_table ) : $identifier;
                    $bound = $this->db_build_keyset_clause( $key_columns, $end_key, 'at_or_before' );
                    $bounded_count = (int) $wpdb->get_var(
                        $this->db_prepare_sql(
                            'SELECT COUNT(*) FROM ' . $count_identifier . ' WHERE ' . $bound['sql'],
                            $bound['values']
                        )
                    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are quoted and key values are prepared.
                }
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

            $snapshot_to_drop = 'snapshot' === $export_mode ? $snapshot_table : '';

            $table_info[] = array(
                'name'         => $table,
                'rows'         => $count,
                'export_mode'  => $export_mode,
                'key_index'    => 'snapshot' === $export_mode ? 'WPCM_SYNTHETIC_SNAPSHOT' : $key_index,
                'key_columns'  => 'snapshot' === $export_mode ? array() : $key_columns,
                'key_column'   => 'snapshot' !== $export_mode && 1 === count( $key_columns ) ? $key_columns[0] : '',
                'schema_hash'          => $after_hash,
                'schema_portable_hash' => $after_portable_hash,
                'chunks'               => $chunk_index,
            );
            WPCM_Reliability::atomic_signed_json( $info_file, $table_info, $context . ':info' );

            $table_idx++;
            $last_key      = null;
            $end_key       = null;
            $key_columns   = array();
            $key_index     = '';
            $export_mode   = '';
            $snapshot_table = '';
            $snapshot_row_id = '';
            $source_columns  = array();
            $avg_row_bytes = 0;
            $chunk_index   = 0;
            $cursor['current_count'] = 0;
            $cursor['rows_exported'] = 0;
            $cursor['end_key']       = null;
            $cursor['schema_hash']          = '';
            $cursor['schema_portable_hash'] = '';
            $cursor['export_mode']           = '';
            $cursor['snapshot_table'] = '';
            $cursor['snapshot_row_id']= '';
            $cursor['source_columns'] = array();
        }

        $cursor['table_idx']     = $table_idx;
        $cursor['last_key']      = $last_key;
        $cursor['end_key']       = $end_key;
        $cursor['key_columns']   = $key_columns;
        $cursor['key_index']     = $key_index;
        $cursor['export_mode']   = $export_mode;
        $cursor['snapshot_table'] = $snapshot_table;
        $cursor['snapshot_row_id']= $snapshot_row_id;
        $cursor['source_columns'] = $source_columns;
        unset( $cursor['key_column'] );
        $cursor['chunk_index']   = $chunk_index;
        $cursor['rows_per_call'] = $rows_per_call;
        $cursor['t_prev']         = round( $t_real, 4 );
        $cursor['mem_prev']        = $mem_peak;
        $cursor['avg_row_bytes']   = $avg_row_bytes;
        $cursor['max_packet']      = $max_packet;
        $cursor['growth_streak']   = $growth_streak;
        $cursor['last_sql_bytes']  = $sql_bytes;
        $cursor['last_rows_done']  = $rows_done;
        $cursor['last_reason']     = $adaptive_reason;
        WPCM_Reliability::atomic_signed_json( $cursor_file, $cursor, $context . ':cursor' );

        if ( '' !== $snapshot_to_drop ) {
            $this->db_drop_snapshot( $snapshot_to_drop, false );
        }

        if ( $table_idx >= $total_tables ) {
            return $this->finish_database_step( $table_info, $sql_dir, $total_tables );
        }

        $progress     = 15 + ( $total_tables > 0 ? (int) floor( 20 * $table_idx / $total_tables ) : 20 );
        $cursor_token = hash(
            'sha256',
            wp_json_encode(
                array(
                    'table_idx'     => $table_idx,
                    'last_key'      => $last_key,
                    'end_key'       => $end_key,
                    'key_columns'   => $key_columns,
                    'export_mode'   => $export_mode,
                    'snapshot_table'=> $snapshot_table,
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
            'rows_per_call'         => $rows_per_call,
            'rows_processed'        => $rows_done,
            'rows_exported'         => (int) ( $cursor['rows_exported'] ?? 0 ),
            'rows_total'            => (int) ( $cursor['current_count'] ?? 0 ),
            'batch_seconds'         => round( $t_real, 3 ),
            'batch_sql_bytes'       => $sql_bytes,
            'batch_memory_peak'     => $mem_peak,
            'batch_memory_limit'    => $memory_limit,
            'batch_reason'          => $adaptive_reason,
            'batch_target_seconds'  => $adaptive_time,
            'batch_target_sql_bytes'=> $adaptive_sql_target,
            'batch_row_cap'         => $adaptive_row_cap,
            'table_index'           => $table_idx,
            'table_count'           => $total_tables,
            'current_table'         => (string) ( $tables[ $table_idx ] ?? $table ),
            'cursor_token'          => $cursor_token,
            'message'          => sprintf(
                /* translators: 1: completed table count, 2: total table count, 3: current table name. */
                __( 'Database: %1$d/%2$d table(s) completed: processing %3$s with a durable database cursor.', 'clone-master' ),
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
     * Return the declared source columns in their physical order.
     *
     * @param string $identifier Quoted source table identifier.
     * @param string $table      Source table name for diagnostics.
     * @return array<int,string>
     */
    private function db_get_table_columns( $identifier, $table ) {
        global $wpdb;

        $columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $identifier, 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Identifier comes from information_schema and is quoted.
        if ( ! is_array( $columns ) || empty( $columns ) ) {
            throw new Exception( sprintf( __( 'Unable to read the columns for table %s.', 'clone-master' ), $table ) );
        }

        return array_values( array_map( 'strval', $columns ) );
    }

    /**
     * Materialize a no-key table into a private helper table and add a synthetic
     * AUTO_INCREMENT primary key. This preserves every duplicate row while making
     * deterministic keyset resume possible across separate AJAX requests.
     *
     * The source table is never altered. CREATE TABLE ... SELECT captures one
     * server-side read set, and the helper table is removed after verification.
     *
     * @param string $table      Source table name.
     * @param string $identifier Quoted source table identifier.
     * @return array{table:string,row_id:string,columns:array<int,string>,count:int,max_row_id:int}
     */
    private function db_create_durable_snapshot( $table, $identifier ) {
        global $wpdb;

        $columns = $this->db_get_table_columns( $identifier, $table );
        $prefix  = preg_replace( '/[^A-Za-z0-9_]/', '_', (string) $wpdb->prefix );
        $prefix  = substr( (string) $prefix, 0, 20 );
        $base    = $prefix . 'wpcm_snapshot_' . substr( hash( 'sha256', $this->session_id . '|' . $table ), 0, 24 );
        $row_id   = '__wpcm_row_id_' . substr( hash( 'sha256', $this->session_id . '|row|' . $table ), 0, 12 );

        while ( in_array( $row_id, $columns, true ) ) {
            $row_id .= 'x';
        }

        $snapshot_table = substr( $base, 0, 64 );
        $snapshot_id    = $this->quote_identifier( $snapshot_table );
        $marker         = 'WPCM temporary export snapshot:' . $this->session_id;

        $existing_comment = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $snapshot_table
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Schema inspection must be current.

        if ( null !== $existing_comment && 0 !== strpos( (string) $existing_comment, 'WPCM temporary export snapshot:' ) ) {
            throw new Exception( __( 'A database table conflicts with the internal snapshot name.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $previous_suppression = $wpdb->suppress_errors( true );

        try {
            if ( null !== $existing_comment ) {
                $wpdb->query( 'DROP TABLE IF EXISTS ' . $snapshot_id ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Internal identifier is generated and quoted.
            }

            $create_sql = 'CREATE TABLE ' . $snapshot_id . " COMMENT='" . $this->db_escape_sql_value( $marker ) . "' AS SELECT * FROM " . $identifier;
            if ( false === $wpdb->query( $create_sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Internal and source identifiers are generated/quoted.
                throw new Exception( '' !== $wpdb->last_error ? $wpdb->last_error : __( 'The database refused to create the temporary snapshot.', 'clone-master' ) );
            }

            $alter_sql = 'ALTER TABLE ' . $snapshot_id . ' ADD COLUMN ' . $this->quote_identifier( $row_id ) . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST';
            if ( false === $wpdb->query( $alter_sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Internal identifiers are generated and quoted.
                throw new Exception( '' !== $wpdb->last_error ? $wpdb->last_error : __( 'The database refused to add the synthetic snapshot key.', 'clone-master' ) );
            }

            $count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $snapshot_id ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Internal identifier is generated and quoted.
            $max   = $count > 0 ? (int) $wpdb->get_var( 'SELECT MAX(' . $this->quote_identifier( $row_id ) . ') FROM ' . $snapshot_id ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Internal identifiers are generated and quoted.

            if ( $count > 0 && $max < 1 ) {
                throw new Exception( __( 'The synthetic snapshot key could not be verified.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            return array(
                'table'      => $snapshot_table,
                'row_id'     => $row_id,
                'columns'    => $columns,
                'count'      => $count,
                'max_row_id' => $max,
            );
        } catch ( Exception $error ) {
            $wpdb->query( 'DROP TABLE IF EXISTS ' . $snapshot_id ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Internal identifier is generated and quoted.
            throw $error;
        } finally {
            $wpdb->suppress_errors( $previous_suppression );
        }
    }

    /**
     * Remove a durable snapshot table.
     *
     * @param string $snapshot_table Snapshot table name.
     * @param bool   $required       Whether failure must stop the export.
     * @return void
     */
    private function db_drop_snapshot( $snapshot_table, $required = false ) {
        global $wpdb;

        if ( ! is_string( $snapshot_table ) || ! preg_match( '/^[A-Za-z0-9_]{0,20}wpcm_snapshot_[a-f0-9]{24}$/', $snapshot_table ) ) {
            if ( $required ) {
                throw new Exception( __( 'The temporary database snapshot name is invalid.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            return;
        }

        $previous_suppression = $wpdb->suppress_errors( true );
        $result               = $wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $snapshot_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Internal identifier is validated and quoted.
        $last_error           = (string) $wpdb->last_error;
        $wpdb->suppress_errors( $previous_suppression );

        if ( false === $result && $required ) {
            throw new Exception(
                sprintf(
                    /* translators: %s: database error. */
                    __( 'The temporary database snapshot could not be removed safely: %s', 'clone-master' ),
                    '' !== $last_error ? $last_error : __( 'unknown database error', 'clone-master' )
                )
            );
        }
    }

    /**
     * Decide whether a no-key table can be read in one buffered WordPress query
     * when the database account cannot create the durable helper snapshot.
     *
     * @param string $table         Table name.
     * @param int    $count         Row count.
     * @param int    $avg_row_bytes Estimated escaped row size.
     * @return bool
     */
    private function db_can_export_atomically( $table, $count, $avg_row_bytes ) {
        global $wpdb;

        $data_length = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(DATA_LENGTH,0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Live size estimate is required for memory safety.

        $raw_estimate = max( $data_length, max( 1, (int) $count ) * max( 256, (int) $avg_row_bytes ) );
        $php_estimate = $raw_estimate * 6 + ( 4 * 1024 * 1024 );
        $hard_cap     = 64 * 1024 * 1024;
        $memory_limit = $this->return_bytes_local( ini_get( 'memory_limit' ) );

        if ( $php_estimate > $hard_cap ) {
            return false;
        }
        if ( $memory_limit <= 0 ) {
            return true;
        }

        $available = max( 0, $memory_limit - memory_get_usage( true ) );
        return $php_estimate <= (int) floor( $available * 0.40 );
    }

    /**
     * Remove abandoned helper snapshots older than seven days. Only tables with
     * Clone Master's exact marker are eligible, so normal WordPress tables cannot
     * be selected by this cleanup.
     *
     * @return void
     */
    private function db_cleanup_stale_snapshots() {
        global $wpdb;

        $tables = $wpdb->get_col(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
               AND INSTR(TABLE_NAME,'wpcm_snapshot_') > 0
               AND TABLE_COMMENT LIKE 'WPCM temporary export snapshot:%'
               AND CREATE_TIME IS NOT NULL
               AND CREATE_TIME < (UTC_TIMESTAMP() - INTERVAL 7 DAY)"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stale helper cleanup must inspect live schema state.

        if ( ! is_array( $tables ) ) {
            return;
        }

        foreach ( $tables as $table ) {
            $this->db_drop_snapshot( (string) $table, false );
        }
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
     * @param array  $rows       Rows.
     * @param int    $max_packet MySQL max_allowed_packet in bytes.
     * @return string
     */
    private function db_build_insert_sql( $table, array $rows, $max_packet = 0 ) {
        if ( empty( $rows ) ) {
            return '';
        }

        $columns          = array_keys( $rows[0] );
        $quoted           = array_map( array( $this, 'quote_identifier' ), $columns );
        $statement_prefix = 'INSERT INTO ' . $this->quote_identifier( $table ) . ' (' . implode( ',', $quoted ) . ") VALUES
  ";
        $packet_target    = $max_packet > 0 ? (int) floor( $max_packet * 0.50 ) : self::WPCM_DB_INSERT_TARGET_BYTES;
        $packet_hard      = $max_packet > 0 ? (int) floor( $max_packet * 0.80 ) : 8 * 1024 * 1024;
        $statement_target = max( 65536, min( self::WPCM_DB_INSERT_TARGET_BYTES, $packet_target ) );
        $statement_hard   = max( $statement_target, $packet_hard );
        $batches          = array();
        $values           = array();
        $statement_bytes  = strlen( $statement_prefix ) + 2;

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

            $tuple       = '(' . implode( ',', $parts ) . ')';
            $tuple_bytes = strlen( $tuple );
            if ( strlen( $statement_prefix ) + $tuple_bytes + 1 > $statement_hard ) {
                throw new Exception(
                    sprintf(
                        /* translators: 1: table name, 2: SQL row size, 3: safe MySQL packet size. */
                        __( 'A single exported row from table %1$s requires %2$s of SQL, above the safe MySQL packet allowance of %3$s. Increase max_allowed_packet before creating a portable backup.', 'clone-master' ),
                        $table,
                        size_format( $tuple_bytes, 1 ),
                        size_format( $statement_hard, 1 )
                    )
                );
            }

            $would_exceed_rows = count( $values ) >= self::WPCM_DB_INSERT_CHUNK;
            $would_exceed_size = ! empty( $values ) && $statement_bytes + $tuple_bytes + 2 > $statement_target;
            if ( $would_exceed_rows || $would_exceed_size ) {
                $batches[]       = $statement_prefix . implode( ",
  ", $values ) . ';';
                $values          = array();
                $statement_bytes = strlen( $statement_prefix ) + 2;
            }

            $values[] = $tuple;
            $statement_bytes += $tuple_bytes + 2;
        }

        if ( ! empty( $values ) ) {
            $batches[] = $statement_prefix . implode( ",
  ", $values ) . ';';
        }

        return implode( "
", $batches ) . "
";
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
     * Normalize binary defaults to a portable hexadecimal representation.
     *
     * MySQL and MariaDB may render the same BINARY default either as escaped
     * zero bytes (for example '\0\0') or as a hexadecimal literal (x'0000').
     * The portable hash keeps the strict schema hash while allowing these
     * equivalent server representations to compare safely.
     *
     * @param string $create_sql SHOW CREATE TABLE output.
     * @return string
     */
    private function normalize_create_table_portable( $create_sql ) {
        $normalized = $this->normalize_create_table( $create_sql );

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
                    $bytes = $this->decode_mysql_string_literal_for_hash( (string) ( $matches[4] ?? '' ), $safe );
                    if ( ! $safe ) {
                        return $matches[0];
                    }
                    $hex = bin2hex( $bytes );
                }

                return $matches[1] . "x'" . $hex . "'";
            },
            $normalized
        );
    }

    /**
     * Decode the portable subset of MySQL string escapes used by SHOW CREATE.
     *
     * @param string $literal Escaped literal body without quote delimiters.
     * @param bool   $safe    Set to true only when every escape was understood.
     * @return string Decoded bytes.
     */
    private function decode_mysql_string_literal_for_hash( $literal, &$safe ) {
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
     * manifest, and hand off to the archive step.
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

        $database_bytes = 0;
        foreach ( glob( $sql_dir . '*.sql' ) ?: array() as $sql_file ) {
            $size = @filesize( $sql_file );
            if ( false !== $size ) {
                $database_bytes += (int) $size;
            }
        }
        $inventory = is_array( $this->manifest['inventory'] ?? null ) ? $this->manifest['inventory'] : array();
        $inventory['database_bytes'] = $database_bytes;
        $inventory['source_bytes']   = $database_bytes + (int) ( $inventory['files_bytes'] ?? 0 );
        $inventory['completed_at']   = gmdate( 'c' );

        $this->manifest['tables']       = $table_info;
        $this->manifest['inventory']    = $inventory;
        $this->manifest['steps_done'][] = 'database';
        $this->manifest['steps_done']   = array_values( array_unique( $this->manifest['steps_done'] ) );
        $this->save_manifest();

        foreach ( array( 'db-cursor.json', 'db-cursor.json.bak', 'db-tables.json', 'db-tables.json.bak', 'db-info.json', 'db-info.json.bak' ) as $name ) {
            @unlink( $this->session_dir . $name );
        }

        return array(
            'session_id'   => $this->session_id,
            'tables_count' => $total,
            'next_step'    => in_array( 'files_scan', $this->manifest['steps_done'] ?? array(), true ) ? ( ! empty( $this->manifest['file_queue'] ) ? 'files_archive' : 'config' ) : 'files_scan',
            'phase'        => 'database',
            'inventory_ready'          => true,
            'inventory_files'          => (int) ( $inventory['files'] ?? 0 ),
            'inventory_directories'    => (int) ( $inventory['directories'] ?? 0 ),
            'inventory_files_bytes'    => (int) ( $inventory['files_bytes'] ?? 0 ),
            'inventory_database_bytes' => (int) $database_bytes,
            'inventory_source_bytes'   => (int) ( $inventory['source_bytes'] ?? 0 ),
            'progress'     => 35,
            'message'      => sprintf(
                _n( '%d table exported with durable immutable chunks. The exact source total is ready.', '%d tables exported with durable immutable chunks. The exact source total is ready.', $total, 'clone-master' ),
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
                'queue_total'              => count( $queue ),
                'next_step'                => in_array( 'database', $this->manifest['steps_done'] ?? array(), true ) ? ( $index < count( $queue ) ? 'files_archive' : 'config' ) : 'database',
                'phase'                    => 'inventory',
                'inventory_ready'          => in_array( 'database', $this->manifest['steps_done'] ?? array(), true ),
                'inventory_files'          => (int) ( $this->manifest['inventory']['files'] ?? 0 ),
                'inventory_directories'    => (int) ( $this->manifest['inventory']['directories'] ?? 0 ),
                'inventory_files_bytes'    => (int) ( $this->manifest['inventory']['files_bytes'] ?? 0 ),
                'inventory_database_bytes' => (int) ( $this->manifest['inventory']['database_bytes'] ?? 0 ),
                'inventory_source_bytes'   => (int) ( $this->manifest['inventory']['source_bytes'] ?? 0 ),
                'progress'                 => in_array( 'database', $this->manifest['steps_done'] ?? array(), true ) ? 35 : 15,
                'message'                  => __( 'The exact source inventory is already available.', 'clone-master' ),
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
                'job_index'                 => 0,
                'processed'                 => 0,
                'excluded_count'            => 0,
                'excluded_files'            => 0,
                'excluded_directories'      => 0,
                'excluded_bytes'            => 0,
                'excluded_backup_count'     => 0,
                'excluded_backup_files'     => 0,
                'excluded_backup_directories' => 0,
                'excluded_backup_bytes'     => 0,
                'excluded_reasons'          => array(),
                'excluded_samples'          => array(),
                'last_excluded_backup'      => null,
                'included_files'           => 0,
                'included_directories'     => 0,
                'included_bytes'           => 0,
                'scan_started_at'          => microtime( true ),
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
                $state['included_directories'] = (int) ( $state['included_directories'] ?? 0 ) + 1;
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

            $exclusion = $this->scan_exclusion( $parent['mode'], $relative, $name, $path );
            if ( is_array( $exclusion ) ) {
                $this->record_scan_exclusion( $state, $path, $relative, $exclusion );
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
                $state['included_directories'] = (int) ( $state['included_directories'] ?? 0 ) + 1;
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
            $this->manifest['backup_exclusions'] = array(
                'count'       => (int) ( $state['excluded_backup_count'] ?? 0 ),
                'files'       => (int) ( $state['excluded_backup_files'] ?? 0 ),
                'directories' => (int) ( $state['excluded_backup_directories'] ?? 0 ),
                'known_bytes' => (int) ( $state['excluded_backup_bytes'] ?? 0 ),
                'reasons'     => is_array( $state['excluded_reasons'] ?? null ) ? $state['excluded_reasons'] : array(),
                'samples'     => is_array( $state['excluded_samples'] ?? null ) ? $state['excluded_samples'] : array(),
            );
            $database_bytes = 0;
            foreach ( glob( $this->session_dir . 'sql/*.sql' ) ?: array() as $sql_file ) {
                $sql_size = @filesize( $sql_file );
                if ( false !== $sql_size ) {
                    $database_bytes += (int) $sql_size;
                }
            }
            $this->manifest['inventory'] = array(
                'files'          => (int) ( $state['included_files'] ?? 0 ),
                'directories'    => (int) ( $state['included_directories'] ?? 0 ),
                'files_bytes'    => (int) ( $state['included_bytes'] ?? 0 ),
                'database_bytes' => (int) $database_bytes,
                'source_bytes'   => (int) $database_bytes + (int) ( $state['included_bytes'] ?? 0 ),
                'indexed_at'     => gmdate( 'c' ),
            );
            $this->manifest['steps_done'][] = 'files_scan';
            $this->manifest['steps_done']   = array_values( array_unique( $this->manifest['steps_done'] ) );
            @unlink( $state_path );
            @unlink( $state_path . '.bak' );
            $this->save_manifest();

            return array(
                'session_id'  => $this->session_id,
                'queue_total' => count( $this->manifest['file_queue'] ),
                'next_step'   => in_array( 'database', $this->manifest['steps_done'] ?? array(), true ) ? ( count( $this->manifest['file_queue'] ) ? 'files_archive' : 'config' ) : 'database',
                'progress'                    => in_array( 'database', $this->manifest['steps_done'] ?? array(), true ) ? 35 : 15,
                'excluded_backup_count'       => (int) ( $state['excluded_backup_count'] ?? 0 ),
                'excluded_backup_files'       => (int) ( $state['excluded_backup_files'] ?? 0 ),
                'excluded_backup_directories' => (int) ( $state['excluded_backup_directories'] ?? 0 ),
                'phase'                       => 'inventory',
                'inventory_ready'             => in_array( 'database', $this->manifest['steps_done'] ?? array(), true ),
                'inventory_files'             => (int) ( $state['included_files'] ?? 0 ),
                'inventory_directories'       => (int) ( $state['included_directories'] ?? 0 ),
                'inventory_files_bytes'       => (int) ( $state['included_bytes'] ?? 0 ),
                'inventory_database_bytes'    => (int) ( $this->manifest['inventory']['database_bytes'] ?? 0 ),
                'inventory_source_bytes'      => (int) ( $this->manifest['inventory']['source_bytes'] ?? 0 ),
                'excluded_backup_bytes'       => (int) ( $state['excluded_backup_bytes'] ?? 0 ),
                'message'                     => sprintf(
                    __( '%1$d paths scanned into %2$d bounded append jobs. %3$d backup location(s) were excluded to prevent nested backups.', 'clone-master' ),
                    (int) $state['processed'],
                    count( $this->manifest['file_queue'] ),
                    (int) ( $state['excluded_backup_count'] ?? 0 )
                ),
            );
        }

        WPCM_Reliability::atomic_signed_json( $state_path, $state, $context );
        $root_progress = count( $state['roots'] ) > 0 ? (int) floor( 100 * $state['root_index'] / count( $state['roots'] ) ) : 100;
        $last_excluded_backup = is_array( $state['last_excluded_backup'] ?? null )
            ? $state['last_excluded_backup']
            : null;
        $scan_message = sprintf(
            __( 'Scanning files: %1$d paths indexed, %2$d jobs prepared, %3$d backup location(s) excluded.', 'clone-master' ),
            (int) $state['processed'],
            count( $this->manifest['file_queue'] ),
            (int) ( $state['excluded_backup_count'] ?? 0 )
        );
        if ( is_array( $last_excluded_backup ) && ! empty( $last_excluded_backup['path'] ) ) {
            $scan_message .= ' ' . sprintf(
                __( 'Last excluded backup location: %s.', 'clone-master' ),
                (string) $last_excluded_backup['path']
            );
        }
        return array(
            'session_id'                    => $this->session_id,
            'next_step'                     => 'files_scan',
            'phase'                         => 'inventory',
            'inventory_ready'               => false,
            'inventory_files'               => (int) ( $state['included_files'] ?? 0 ),
            'inventory_directories'         => (int) ( $state['included_directories'] ?? 0 ),
            'inventory_files_bytes'         => (int) ( $state['included_bytes'] ?? 0 ),
            'progress'                      => 5 + min( 10, (int) floor( $root_progress / 10 ) ),
            'excluded_backup_count'         => (int) ( $state['excluded_backup_count'] ?? 0 ),
            'excluded_backup_files'         => (int) ( $state['excluded_backup_files'] ?? 0 ),
            'excluded_backup_directories'   => (int) ( $state['excluded_backup_directories'] ?? 0 ),
            'excluded_backup_bytes'         => (int) ( $state['excluded_backup_bytes'] ?? 0 ),
            'last_excluded_backup'          => $last_excluded_backup,
            'message'                       => $scan_message,
        );
    }

    /**
     * Return the wall-clock budget for one archive worker request.
     *
     * @return float Seconds.
     */
    private function archive_time_budget() {
        $configured = (int) ini_get( 'max_execution_time' );
        $reference  = $configured > 0 ? $configured : 30;
        $budget     = max( 3.0, min( 7.0, $reference * 0.20 ) );
        return max( 2.0, min( 12.0, (float) apply_filters( 'wpcm_archive_time_budget', $budget, $configured ) ) );
    }

    /**
     * Calculate the initial archive slice from available PHP memory.
     *
     * @return int Bytes.
     */
    private function archive_initial_slice_bytes() {
        $memory_limit = $this->db_memory_limit();
        $memory_used  = memory_get_usage( true );
        $headroom     = $memory_limit > 0 ? max( 0, $memory_limit - $memory_used ) : 512 * MB_IN_BYTES;
        $slice        = 32 * MB_IN_BYTES;
        if ( $headroom < 64 * MB_IN_BYTES ) {
            $slice = 8 * MB_IN_BYTES;
        } elseif ( $headroom < 128 * MB_IN_BYTES ) {
            $slice = 16 * MB_IN_BYTES;
        } elseif ( $headroom >= 512 * MB_IN_BYTES ) {
            $slice = 64 * MB_IN_BYTES;
        }
        $slice = (int) apply_filters( 'wpcm_archive_initial_slice_bytes', $slice, $memory_limit, $memory_used );
        return max( 4 * MB_IN_BYTES, min( 128 * MB_IN_BYTES, $slice ) );
    }

    /**
     * Adapt the next archive slice to measured throughput and resource pressure.
     *
     * @return array{bytes:int,reason:string,throughput:float}
     */
    private function archive_adapt_slice_bytes( $current, $duration, $processed, $memory_peak, $memory_limit, $time_budget ) {
        $current     = max( 4 * MB_IN_BYTES, (int) $current );
        $duration    = max( 0.001, (float) $duration );
        $processed   = max( 0, (int) $processed );
        $throughput  = $processed / $duration;
        $memory_ratio = $memory_limit > 0 ? $memory_peak / $memory_limit : 0.0;
        $reason      = 'stable';
        $next        = $current;

        if ( $memory_limit > 0 && $memory_ratio >= 0.82 ) {
            $next   = (int) floor( $current * 0.50 );
            $reason = 'reduced_memory';
        } elseif ( $duration >= $time_budget * 0.92 ) {
            $next   = (int) floor( $current * 0.70 );
            $reason = 'reduced_time';
        } elseif ( $processed >= (int) floor( $current * 0.90 ) && $duration < $time_budget * 0.65 && $memory_ratio < 0.70 ) {
            $ideal  = (int) floor( $throughput * max( 2.0, $time_budget * 0.72 ) );
            $next   = min( (int) ceil( $current * 1.50 ), max( $current, $ideal ) );
            $reason = $next > $current ? 'increased' : 'stable';
        }

        $next = (int) apply_filters(
            'wpcm_archive_adaptive_slice_bytes',
            $next,
            array(
                'current'       => $current,
                'duration'      => $duration,
                'processed'     => $processed,
                'memory_peak'   => $memory_peak,
                'memory_limit'  => $memory_limit,
                'time_budget'   => $time_budget,
                'throughput'    => $throughput,
                'reason'        => $reason,
            )
        );

        return array(
            'bytes'      => max( 4 * MB_IN_BYTES, min( 128 * MB_IN_BYTES, $next ) ),
            'reason'     => $reason,
            'throughput' => $throughput,
        );
    }

    /**
     * Skip CPU-expensive deflate attempts for formats that are already compressed.
     *
     * @param string $entry Portable archive entry.
     * @return bool
     */
    private function archive_entry_allows_compression( $entry ) {
        $extension = strtolower( (string) pathinfo( (string) $entry, PATHINFO_EXTENSION ) );
        $packed = array(
            '7z', 'aac', 'avi', 'avif', 'bz2', 'daf', 'docx', 'eot', 'exe', 'flac', 'gif', 'gz', 'heic', 'heif',
            'iso', 'jar', 'jpeg', 'jpg', 'm4a', 'mkv', 'mov', 'mp3', 'mp4', 'ogg', 'opus', 'pdf', 'png', 'pptx',
            'rar', 'tar', 'tgz', 'webm', 'webp', 'woff', 'woff2', 'wpcm', 'wpress', 'xlsx', 'xz', 'zip',
        );
        return ! in_array( $extension, $packed, true );
    }

    // =========================================================================
    // Step 4: Archive files with an adaptive, resumable worker
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

            $total_files = (int) ( $this->manifest['inventory']['files'] ?? 0 );
            $total_bytes = (int) ( $this->manifest['inventory']['files_bytes'] ?? 0 );
            if ( $total_files < 1 && ! empty( $this->manifest['file_queue'] ) ) {
                // Backward-compatible recovery for a session started before the
                // exact inventory counters were introduced.
                foreach ( $this->manifest['file_queue'] as $job ) {
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
                'source_bytes_total'   => (int) $database_size + (int) $total_bytes,
                'worker_slice_bytes'   => $this->archive_initial_slice_bytes(),
                'worker_calls'         => 0,
                'worker_seconds'       => 0.0,
                'worker_input_bytes'   => 0,
                'worker_throughput'    => 0.0,
                'worker_reason'        => 'learning',
                'started_at'           => microtime( true ),
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

        $started          = microtime( true );
        $slice_bytes      = 0;
        $time_budget      = $this->archive_time_budget();
        $target_bytes     = max( 4 * MB_IN_BYTES, min( 128 * MB_IN_BYTES, (int) ( $state['worker_slice_bytes'] ?? $this->archive_initial_slice_bytes() ) ) );
        $descriptor_cache = array();

        try {
            while ( $slice_bytes < $target_bytes && ( microtime( true ) - $started ) < $time_budget ) {
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
                    $source_size = @filesize( $source );
                    $input  = @fopen( $source, 'rb' );
                    if ( false === $source_size || ! is_resource( $input ) || 0 !== @fseek( $input, (int) $state['sql_offset'] ) ) {
                        if ( is_resource( $input ) ) fclose( $input );
                        throw new Exception( __( 'Unable to resume a durable SQL chunk.', 'clone-master' ) );
                    }
                    try {
                        while ( (int) $state['sql_offset'] < (int) $source_size
                            && $slice_bytes < $target_bytes
                            && ( microtime( true ) - $started ) < $time_budget ) {
                            $remaining = (int) $source_size - (int) $state['sql_offset'];
                            $raw = fread( $input, min( WPCM_Archive::CHUNK_BYTES, $remaining ) );
                            if ( false === $raw || '' === $raw ) {
                                throw new Exception( __( 'Unable to read a durable SQL chunk.', 'clone-master' ) );
                            }
                            WPCM_Archive::append_chunk( $archive, $raw, true );
                            WPCM_Archive::write_exact( $copy, $raw );
                            $length = strlen( $raw );
                            $state['sql_offset'] += $length;
                            $state['database_copy_offset'] += $length;
                            $slice_bytes += $length;
                        }
                    } finally {
                        fclose( $input );
                    }
                    if ( (int) $state['sql_offset'] >= (int) $source_size ) {
                        $state['sql_index']++;
                        $state['sql_offset'] = 0;
                    }
                    continue;
                }

                if ( 'files' !== $state['phase'] ) {
                    break;
                }

                $queue = $this->manifest['file_queue'] ?? array();
                $queue_index = (int) $state['queue_index'];
                if ( $queue_index >= count( $queue ) ) {
                    $state['phase'] = 'complete';
                    break;
                }
                $job = $queue[ $queue_index ];
                if ( ! isset( $descriptor_cache[ $queue_index ] ) ) {
                    $descriptor_cache[ $queue_index ] = WPCM_Reliability::read_signed_json(
                        $this->session_dir . $job['descriptor'],
                        'export-file-job:' . $this->session_id . ':' . (int) $job['job_index']
                    );
                }
                $descriptor = $descriptor_cache[ $queue_index ];
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
                        'source'            => $source,
                        'relative'          => $relative,
                        'archive_entry'     => $archive_entry,
                        'size'              => (int) $entry['size'],
                        'mtime'             => (int) $entry['mtime'],
                        'source_offset'     => 0,
                        'allow_compression' => $this->archive_entry_allows_compression( $archive_entry ),
                    );
                }

                $current = $state['current_entry'];
                $input   = @fopen( $current['source'], 'rb' );
                if ( ! is_resource( $input ) || 0 !== @fseek( $input, (int) $current['source_offset'] ) ) {
                    if ( is_resource( $input ) ) fclose( $input );
                    throw new Exception( __( 'Unable to resume a source file.', 'clone-master' ) );
                }
                try {
                    while ( (int) $state['current_entry']['source_offset'] < (int) $current['size']
                        && $slice_bytes < $target_bytes
                        && ( microtime( true ) - $started ) < $time_budget ) {
                        $remaining = (int) $current['size'] - (int) $state['current_entry']['source_offset'];
                        $raw = fread( $input, min( WPCM_Archive::CHUNK_BYTES, $remaining ) );
                        if ( false === $raw || '' === $raw ) {
                            throw new Exception( __( 'Unable to read a source file.', 'clone-master' ) );
                        }
                        WPCM_Archive::append_chunk( $archive, $raw, ! empty( $current['allow_compression'] ) );
                        $length = strlen( $raw );
                        $state['current_entry']['source_offset'] += $length;
                        $slice_bytes += $length;
                    }
                } finally {
                    fclose( $input );
                }

                if ( (int) $state['current_entry']['source_offset'] >= (int) $current['size'] ) {
                    if ( (int) @filesize( $current['source'] ) !== (int) $current['size']
                        || (int) @filemtime( $current['source'] ) !== (int) $current['mtime'] ) {
                        throw new Exception( sprintf( __( 'Backup source changed while archiving: %s', 'clone-master' ), $current['source'] ) );
                    }
                    WPCM_Archive::append_file_end( $archive, (int) $current['size'] );
                    $state['files_count']++;
                    $state['files_size'] += (int) $current['size'];
                    $state['entry_index']++;
                    $state['current_entry'] = null;
                }
            }

            WPCM_Archive::flush( $archive );
            WPCM_Archive::flush( $copy );
            $state['archive_offset']       = (int) ftell( $archive );
            $state['database_copy_offset'] = (int) ftell( $copy );
        } finally {
            fclose( $copy );
            fclose( $archive );
        }

        $worker_duration = max( 0.001, microtime( true ) - $started );
        $memory_peak     = memory_get_peak_usage( true );
        $memory_limit    = $this->db_memory_limit();
        $adapted         = $this->archive_adapt_slice_bytes(
            $target_bytes,
            $worker_duration,
            $slice_bytes,
            $memory_peak,
            $memory_limit,
            $time_budget
        );
        $previous_throughput = (float) ( $state['worker_throughput'] ?? 0.0 );
        $measured_throughput = (float) $adapted['throughput'];
        $state['worker_slice_bytes'] = (int) $adapted['bytes'];
        $state['worker_calls']       = (int) ( $state['worker_calls'] ?? 0 ) + 1;
        $state['worker_seconds']     = (float) ( $state['worker_seconds'] ?? 0.0 ) + $worker_duration;
        $state['worker_input_bytes'] = (int) ( $state['worker_input_bytes'] ?? 0 ) + $slice_bytes;
        $state['worker_throughput']  = $previous_throughput > 0
            ? ( $previous_throughput * 0.70 + $measured_throughput * 0.30 )
            : $measured_throughput;
        $state['worker_reason']      = (string) $adapted['reason'];
        $state['worker_last_seconds'] = $worker_duration;
        $state['worker_last_bytes']   = $slice_bytes;
        $state['worker_memory_peak']  = $memory_peak;
        $state['worker_memory_limit'] = $memory_limit;

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
                'phase'      => 'archive',
                'progress'   => 82,
                'inventory_files' => (int) $state['total_files'],
                'inventory_source_bytes' => (int) ( $state['source_bytes_total'] ?? 0 ),
                'message'    => __( 'Archive data written successfully. Preparing final metadata.', 'clone-master' ),
            );
        }

        $current             = is_array( $state['current_entry'] ) ? $state['current_entry'] : null;
        $current_name        = is_array( $current ) ? (string) $current['archive_entry'] : 'database.sql';
        $current_file_size   = is_array( $current ) ? (int) $current['size'] : (int) $state['database_size'];
        $current_file_done   = is_array( $current ) ? (int) $current['source_offset'] : (int) $state['database_copy_offset'];
        $archive_bytes_done  = (int) $state['database_copy_offset'] + (int) $state['files_size'] + ( is_array( $current ) ? (int) $current['source_offset'] : 0 );
        $archive_bytes_total = max( 1, (int) ( $state['source_bytes_total'] ?? ( (int) $state['database_size'] + (int) $state['total_bytes'] ) ) );
        $archive_ratio       = min( 1.0, $archive_bytes_done / $archive_bytes_total );
        $progress            = 35 + round( 47 * $archive_ratio, 2 );
        $throughput          = max( 0.0, (float) ( $state['worker_throughput'] ?? 0.0 ) );
        $remaining_bytes     = max( 0, $archive_bytes_total - $archive_bytes_done );
        $eta_seconds         = $throughput > 0 ? (int) ceil( $remaining_bytes / $throughput ) : 0;
        $message             = 'database' === $state['phase']
            ? __( 'Creating the archive from the exact inventory: database stream in progress.', 'clone-master' )
            : __( 'Creating the archive from the exact inventory: site files in progress.', 'clone-master' );
        return array(
            'session_id'          => $this->session_id,
            'next_step'           => 'files_archive',
            'phase'               => 'archive',
            'progress'            => min( 82, $progress ),
            'queue_done'          => (int) $state['queue_index'],
            'queue_total'         => count( $this->manifest['file_queue'] ?? array() ),
            'current_file'        => $current_name,
            'current_file_size'   => $current_file_size,
            'current_file_done'   => $current_file_done,
            'archive_bytes_done'  => $archive_bytes_done,
            'archive_bytes_total' => $archive_bytes_total,
            'archive_files_done'  => (int) $state['files_count'],
            'archive_files_total' => (int) $state['total_files'],
            'archive_speed_bps'   => (float) $throughput,
            'archive_eta_seconds' => (int) $eta_seconds,
            'archive_worker_bytes'=> (int) ( $state['worker_slice_bytes'] ?? 0 ),
            'archive_worker_time' => (float) ( $state['worker_last_seconds'] ?? 0.0 ),
            'archive_worker_reason' => (string) ( $state['worker_reason'] ?? 'stable' ),
            'archive_memory_peak' => (int) ( $state['worker_memory_peak'] ?? 0 ),
            'archive_memory_limit'=> (int) ( $state['worker_memory_limit'] ?? 0 ),
            'inventory_files'     => (int) $state['total_files'],
            'inventory_source_bytes' => (int) $archive_bytes_total,
            'message'             => $message,
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
        $state['included_files'] = (int) ( $state['included_files'] ?? 0 ) + 1;
        $state['included_bytes'] = (int) ( $state['included_bytes'] ?? 0 ) + (int) $size;
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
     * Return exclusion metadata for one scanned path, or false when it must be
     * included. Backup rules are path-specific so normal plugin code and normal
     * media archives are never excluded merely because they use .zip or .sql.
     *
     * @param string $mode     Root mode.
     * @param string $relative Relative path.
     * @param string $basename Basename.
     * @param string $path     Absolute path when available.
     * @return array|false
     */
    private function scan_exclusion( $mode, $relative, $basename, $path = '' ) {
        $normalized     = trim( str_replace( '\\', '/', (string) $relative ), '/' );
        $normalized_lc  = strtolower( $normalized );
        $basename_lc    = strtolower( (string) $basename );
        $parts          = explode( '/', $normalized );
        $current_plugin = basename( rtrim( WPCM_PLUGIN_DIR, '/\\' ) );
        $exclusion      = false;

        if ( 'plugins' === $mode && isset( $parts[1] ) && $parts[1] === $current_plugin ) {
            $exclusion = array( 'code' => 'current-plugin', 'backup' => false );
        } elseif ( 'mu-plugins' === $mode && '000-wpcm-recovery-bootstrap.php' === $basename ) {
            $exclusion = array( 'code' => 'recovery-bootstrap', 'backup' => false );
        } elseif ( in_array( $basename_lc, array( '.git', '.svn' ), true ) ) {
            $exclusion = array( 'code' => 'version-control', 'backup' => false );
        } elseif ( 'node_modules' === $basename_lc ) {
            $exclusion = array( 'code' => 'development-dependencies', 'backup' => false );
        } elseif ( in_array( $basename_lc, array( 'wpcm-backups', 'wpcm-temp', 'wpcm-logs', 'wpcm-downloads' ), true ) ) {
            $exclusion = array( 'code' => 'clone-master-runtime', 'backup' => true );
        } elseif ( in_array( $basename_lc, array( 'debug.log', 'error_log', 'error.log' ), true ) ) {
            $exclusion = array( 'code' => 'runtime-log', 'backup' => false );
        }

        // All-in-One WP Migration keeps temporary export payloads inside the
        // plugin directory. Match vendor and development folder suffixes too,
        // e.g. all-in-one-wp-migration-unlimited-main/storage/.
        if ( false === $exclusion && preg_match( '#^plugins/all-in-one-wp-migration[^/]*/storage(?:/|$)#i', $normalized ) ) {
            $exclusion = array( 'code' => 'all-in-one-wp-migration-storage', 'backup' => true );
        }

        // Known local backup locations used by popular migration and backup
        // plugins. Some are outside the roots currently scanned, but retaining
        // the rules here keeps future content-root expansion safe.
        $known_backup_patterns = array(
            '#^(?:ai1wm-backups|updraft|wpvividbackups|backups-dup-lite|backups-dup-pro|backupbuddy_backups|backup-migration|xcloner-backups|everest-backup|wp-time-capsule)(?:/|$)#i' => 'known-backup-directory',
            '#^boldgrid_backup[^/]*(?:/|$)#i'                                                   => 'total-upkeep-backup',
            '#^uploads/wp-staging(?:-pro)?/backups(?:/|$)#i'                                    => 'wp-staging-backup',
            '#^uploads/backupbuddy_backups(?:/|$)#i'                                            => 'backupbuddy-backup',
            '#^uploads/backwpup(?:[-_/]|$)#i'                                                   => 'backwpup-backup',
            '#^uploads/(?:wpvividbackups|ai1wm-backups|updraft)(?:/|$)#i'                        => 'known-backup-directory',
            '#^uploads/(?:backup-guard|wp-clone|backup-migration|solid-backups)(?:/|$)#i'         => 'known-backup-directory',
        );
        if ( false === $exclusion ) {
            foreach ( $known_backup_patterns as $pattern => $code ) {
                if ( preg_match( $pattern, $normalized ) ) {
                    $exclusion = array( 'code' => $code, 'backup' => true );
                    break;
                }
            }
        }

        // Exclude conservatively named backup/migration directories under
        // uploads, including custom local destinations configured by plugins.
        if ( false === $exclusion && 'uploads' === $mode && is_dir( $path )
            && preg_match( '/^(?:backups?|backup[-_].+|.+[-_]backups?|wp[-_]?staging(?:-pro)?|wpvividbackups?|ai1wm-backups?|updraft|backupbuddy_backups?|backwpup(?:[-_].*)?|duplicator(?:[-_].*)?|solid-backups?|blogvault(?:[-_].*)?|xcloner(?:[-_].*)?|everest-backup(?:[-_].*)?|wp-time-capsule(?:[-_].*)?)$/i', $basename_lc ) ) {
            $exclusion = array( 'code' => 'named-backup-directory', 'backup' => true );
        }

        // Catch clearly named backup archives placed directly in uploads while
        // leaving ordinary ZIP, SQL and media files untouched.
        if ( false === $exclusion && 'uploads' === $mode && $this->is_known_backup_archive_name( $basename_lc ) ) {
            $path_hint = $normalized_lc . '/' . $basename_lc;
            if ( preg_match( '/(?:^|[\\/_-])(?:backup|backups|migration|migrate|clone|snapshot|restore|duplicator|updraft|wpvivid|ai1wm|wpstg|backupbuddy|backwpup)(?:[\\/_.-]|$)/i', $path_hint ) ) {
                $exclusion = array( 'code' => 'named-backup-archive', 'backup' => true );
            }
        }

        /**
         * Filter one scanner exclusion.
         *
         * Return false to include the path, or an array containing a stable
         * `code` and boolean `backup` flag to exclude it.
         *
         * @param array|false $exclusion Exclusion metadata.
         * @param string      $mode      Root mode.
         * @param string      $relative  Normalized relative path.
         * @param string      $basename  Basename.
         * @param string      $path      Absolute path when available.
         */
        $filtered = apply_filters( 'wpcm_scan_exclusion', $exclusion, $mode, $normalized, $basename, $path );
        if ( ! is_array( $filtered ) || empty( $filtered['code'] ) ) {
            return false;
        }
        return array(
            'code'   => sanitize_key( (string) $filtered['code'] ),
            'backup' => ! empty( $filtered['backup'] ),
        );
    }

    /**
     * Determine whether a filename is a known WordPress backup container.
     *
     * @param string $basename Lowercase basename.
     * @return bool
     */
    private function is_known_backup_archive_name( $basename ) {
        return (bool) preg_match( '/\\.(?:wpress|wpcm|wpstg|daf|dup|zip|tar|tgz|gz|bz2|7z|sql|sql\\.gz)$/i', (string) $basename );
    }

    /**
     * Persist bounded exclusion telemetry without traversing excluded folders.
     * Directory sizes remain intentionally unknown: calculating them would
     * defeat the purpose of skipping multi-gigabyte backup trees quickly.
     *
     * @param array  $state     Mutable scan state.
     * @param string $path      Absolute path.
     * @param string $relative  Relative path.
     * @param array  $exclusion Exclusion metadata.
     * @return void
     */
    private function record_scan_exclusion( array &$state, $path, $relative, array $exclusion ) {
        $is_file = is_file( $path );
        $is_dir  = is_dir( $path );
        $size    = $is_file ? max( 0, (int) @filesize( $path ) ) : 0;
        $code    = sanitize_key( (string) ( $exclusion['code'] ?? 'excluded' ) );

        $state['excluded_count']       = (int) ( $state['excluded_count'] ?? 0 ) + 1;
        $state['excluded_files']       = (int) ( $state['excluded_files'] ?? 0 ) + ( $is_file ? 1 : 0 );
        $state['excluded_directories'] = (int) ( $state['excluded_directories'] ?? 0 ) + ( $is_dir ? 1 : 0 );
        $state['excluded_bytes']       = (int) ( $state['excluded_bytes'] ?? 0 ) + $size;

        if ( ! isset( $state['excluded_reasons'] ) || ! is_array( $state['excluded_reasons'] ) ) {
            $state['excluded_reasons'] = array();
        }
        $state['excluded_reasons'][ $code ] = (int) ( $state['excluded_reasons'][ $code ] ?? 0 ) + 1;

        $sample = array(
            'path'   => ltrim( str_replace( '\\', '/', (string) $relative ), '/' ),
            'code'   => $code,
            'type'   => $is_dir ? 'directory' : 'file',
            'bytes'  => $size,
            'backup' => ! empty( $exclusion['backup'] ),
        );
        if ( ! isset( $state['excluded_samples'] ) || ! is_array( $state['excluded_samples'] ) ) {
            $state['excluded_samples'] = array();
        }
        if ( count( $state['excluded_samples'] ) < 20 ) {
            $state['excluded_samples'][] = $sample;
        }

        if ( ! empty( $exclusion['backup'] ) ) {
            $state['excluded_backup_count']       = (int) ( $state['excluded_backup_count'] ?? 0 ) + 1;
            $state['excluded_backup_files']       = (int) ( $state['excluded_backup_files'] ?? 0 ) + ( $is_file ? 1 : 0 );
            $state['excluded_backup_directories'] = (int) ( $state['excluded_backup_directories'] ?? 0 ) + ( $is_dir ? 1 : 0 );
            $state['excluded_backup_bytes']       = (int) ( $state['excluded_backup_bytes'] ?? 0 ) + $size;
            $state['last_excluded_backup']        = $sample;
        }
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
            'phase'      => 'finalizing',
            'progress'   => 86,
            'message'    => __( 'Portable metadata captured. Final verification is starting.', 'clone-master' ),
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
            $footer_hash = WPCM_Archive::footer_payload_hash( $final );
            if ( null === $footer_hash ) {
                throw new Exception( __( 'The published WPCM container has an invalid footer.', 'clone-master' ) );
            }
            $file_hash = $this->read_or_create_archive_sidecar( $final );
            return array(
                'session_id'   => $this->session_id,
                'next_step'    => 'cleanup',
                'phase'        => 'finalizing',
                'progress'     => 98,
                'filename'     => $filename,
                'size'         => size_format( filesize( $final ) ),
                'sha256'       => $file_hash,
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
        $this->manifest['verification_mode']    = 'block-sha256-and-footer-sha256';
        $this->manifest['steps_done'][]         = 'package';
        $this->manifest['steps_done']           = array_values( array_unique( $this->manifest['steps_done'] ) );
        unset( $this->manifest['file_queue'], $this->manifest['file_queue_idx'], $this->manifest['archives'], $this->manifest['checksums'], $this->manifest['integrity'] );
        $this->save_manifest();

        // A retry can arrive after the footer was durably appended but before
        // the atomic rename. Full decompression is reserved for that uncommon
        // recovery path; normal publication avoids rereading every block.
        $payload_hash = WPCM_Archive::footer_payload_hash( $partial );
        if ( null === $payload_hash ) {
            $payload_hash = WPCM_Archive::finalize( $partial, $this->manifest );
            if ( WPCM_Archive::footer_payload_hash( $partial ) !== $payload_hash ) {
                throw new Exception( __( 'The WPCM footer could not be verified after finalization.', 'clone-master' ) );
            }
        } else {
            $inspection = WPCM_Archive::inspect( $partial );
            $payload_hash = (string) $inspection['sha256'];
        }

        $file_hash = hash_file( 'sha256', $partial );
        if ( ! is_string( $file_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $file_hash ) ) {
            throw new Exception( __( 'Unable to calculate the final archive checksum.', 'clone-master' ) );
        }
        if ( ! @rename( $partial, $final ) ) {
            throw new Exception( __( 'Unable to atomically publish the WPCM container.', 'clone-master' ) );
        }
        WPCM_Reliability::atomic_write( $final . '.sha256', $file_hash . "\n", 0640 );

        return array(
            'session_id'   => $this->session_id,
            'next_step'    => 'cleanup',
            'phase'        => 'finalizing',
            'progress'     => 98,
            'filename'     => $filename,
            'size'         => size_format( filesize( $final ) ),
            'sha256'       => $file_hash,
            'payload_sha256' => $payload_hash,
            'download_url' => admin_url( 'admin-ajax.php?action=wpcm_download_backup&backup_name=' . rawurlencode( $filename ) . '&nonce=' . wp_create_nonce( 'wpcm_nonce' ) ),
            'message'      => sprintf( __( 'WPCM container finalized, checksummed, and atomically published: %s', 'clone-master' ), $filename ),
        );
    }

    /**
     * Read a valid archive sidecar or create it with one bounded sequential pass.
     *
     * @param string $archive Absolute archive path.
     * @return string
     */
    private function read_or_create_archive_sidecar( $archive ) {
        $sidecar = $archive . '.sha256';
        if ( is_file( $sidecar ) ) {
            $stored = strtolower( trim( (string) @file_get_contents( $sidecar ) ) );
            if ( preg_match( '/^[a-f0-9]{64}$/', $stored ) ) {
                return $stored;
            }
        }
        $hash = hash_file( 'sha256', $archive );
        if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            throw new Exception( __( 'Unable to calculate the archive checksum.', 'clone-master' ) );
        }
        WPCM_Reliability::atomic_write( $sidecar, $hash . "\n", 0640 );
        return $hash;
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
