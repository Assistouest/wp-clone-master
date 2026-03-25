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
    // Step 2: Export database
    // =========================================================================
    private function step_database() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full export requires live table list; caching would miss recently created tables
        $tables = $wpdb->get_col(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix ) . '%' )
        );
        $sql_dir = $this->session_dir . 'sql/';
        $table_info = [];

        $header  = "-- Clone Master Database Export\n";
        $header .= "-- Date: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
        $header .= "-- Site: " . site_url() . "\n";
        $header .= "-- Prefix: {$wpdb->prefix}\n";
        $header .= "-- MySQL: " . $wpdb->db_version() . "\n";
        $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        file_put_contents( $sql_dir . '00_header.sql', $header, LOCK_EX );

        foreach ( $tables as $idx => $table ) {
            $filename  = sprintf( '%02d_%s.sql', $idx + 1, $table );
            $filepath  = $sql_dir . $filename;
            $sql       = '';

            // Use real table names — prefix replacement happens at import time.
            $safe_table = esc_sql( $table );
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$safe_table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from SHOW TABLES, escaped via esc_sql()
            if ( $create ) {
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $create[1] . ";\n\n";
            }

            $count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from SHOW TABLES, escaped via esc_sql()
            // Use multi-row INSERTs (100 rows per statement) — like mysqldump default.
            // This reduces query count from N to N/100, dramatically faster on import.
            $batch       = 100;
            $offset      = 0;
            $insert_rows = 100; // rows per INSERT INTO ... VALUES (...),(...),...

            while ( $offset < $count ) {
                $rows = $wpdb->get_results( "SELECT * FROM `{$safe_table}` LIMIT {$offset}, {$batch}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from SHOW TABLES, escaped via esc_sql()
                if ( ! $rows ) break;

                $cols        = array_map( function( $c ) { return "`{$c}`"; }, array_keys( $rows[0] ) );
                $cols_str    = implode( ',', $cols );
                $row_values  = [];

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
                         * Alternatives that are NOT wp.org-compatible:
                         *   - $wpdb->dbh->real_escape_string() — accesses a private
                         *     wpdb property; rejected by Plugin Review Team.
                         *   - mysqli_real_escape_string()       — raw mysqli, no WP layer.
                         *   - addslashes()                      — forbidden by WP coding standards.
                         *
                         * phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                         * -- value is escaped via esc_sql(); placeholder removed before
                         *    file write, not before a wpdb query. This is the correct
                         *    pattern for SQL dump generation outside of wpdb::query().
                         */
                        return "'" . $wpdb->remove_placeholder_escape( esc_sql( $v ) ) . "'";
                    }, array_values( $row ) );
                    $row_values[] = '(' . implode( ',', $values ) . ')';

                    // Flush every $insert_rows rows to keep statement size manageable
                    if ( count( $row_values ) >= $insert_rows ) {
                        $sql .= "INSERT IGNORE INTO `{$safe_table}` ({$cols_str}) VALUES\n  "
                              . implode( ",\n  ", $row_values ) . ";\n";
                        $row_values = [];
                    }
                }
                // Flush remaining rows
                if ( ! empty( $row_values ) ) {
                    $sql .= "INSERT IGNORE INTO `{$safe_table}` ({$cols_str}) VALUES\n  "
                          . implode( ",\n  ", $row_values ) . ";\n";
                }

                $offset += $batch;

                // Write to disk every 2MB to avoid memory spikes
                if ( strlen( $sql ) > 2 * 1024 * 1024 ) {
                    file_put_contents( $filepath, $sql, FILE_APPEND | LOCK_EX );
                    $sql = '';
                }
            }

            file_put_contents( $filepath, $sql, FILE_APPEND | LOCK_EX );

            $table_info[] = [
                'name' => $table,
                'rows' => $count,
                'file' => $filename,
            ];
        }

        file_put_contents( $sql_dir . '99_footer.sql', "\nSET FOREIGN_KEY_CHECKS = 1;\n", LOCK_EX );

        $this->manifest['tables']       = $table_info;
        $this->manifest['steps_done'][] = 'database';
        $this->save_manifest();

        return [
            'session_id'   => $this->session_id,
            'tables_count' => count( $tables ),
            'next_step'    => 'files_scan',
            'progress'     => 25,
            'message'      => count( $tables ) . /* translators: %d = number of tables */ ' ' . __( 'tables exported. Scanning files...', 'clone-master' ),
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
