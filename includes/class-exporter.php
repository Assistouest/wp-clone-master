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
 * 5. config         → Capture wp-config, options, meta
 * 6. package        → Bundle everything into final ZIP
 * 7. cleanup        → Remove temp files
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

        if ( ! $session_id ) throw new Exception( 'Missing session ID' );

        $this->session_id  = $session_id;
        $this->session_dir = WPCM_TEMP_DIR . $session_id . '/';

        if ( ! is_dir( $this->session_dir ) ) {
            throw new Exception( 'Session not found: ' . $session_id );
        }

        $this->manifest = json_decode( file_get_contents( $this->session_dir . 'manifest.json' ), true );
        if ( ! $this->manifest ) {
            throw new Exception( 'Corrupt manifest file' );
        }

        switch ( $step ) {
            case 'database':      return $this->step_database();
            case 'files_scan':    return $this->step_files_scan();
            case 'files_archive': return $this->step_files_archive();
            case 'config':        return $this->step_config();
            case 'package':       return $this->step_package();
            case 'cleanup':       return $this->step_cleanup();
            default:              throw new Exception( 'Unknown step: ' . $step );
        }
    }

    // =========================================================================
    // Step 1: Initialize session
    // =========================================================================
    private function step_init() {
        $this->session_id  = 'export_' . WPCM_Date::now_id() . '_' . wp_generate_password( 6, false );
        $this->session_dir = WPCM_TEMP_DIR . $this->session_id . '/';

        wp_mkdir_p( $this->session_dir );
        wp_mkdir_p( $this->session_dir . 'sql/' );
        wp_mkdir_p( $this->session_dir . 'files/' );

        $detector = new WPCM_Server_Detector();
        $server_info = $detector->get_info();

        $this->manifest = [
            'wpcm_version'   => WPCM_VERSION,
            'created_at'     => WPCM_Date::now_str(),
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
            'message'     => 'Session initialized. Exporting database...',
        ];
    }

    // =========================================================================
    // Step 2: Export database
    // =========================================================================
    private function step_database() {
        global $wpdb;

        $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
        $sql_dir = $this->session_dir . 'sql/';
        $table_info = [];

        $header  = "-- WP Clone Master Database Export\n";
        $header .= "-- Date: " . WPCM_Date::now_str() . "\n";
        $header .= "-- Site: " . site_url() . "\n";
        $header .= "-- Prefix: {$wpdb->prefix}\n";
        $header .= "-- MySQL: " . $wpdb->db_version() . "\n";
        $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        file_put_contents( $sql_dir . '00_header.sql', $header );

        foreach ( $tables as $idx => $table ) {
            $filename  = sprintf( '%02d_%s.sql', $idx + 1, $table );
            $filepath  = $sql_dir . $filename;
            $sql       = '';

            // Use real table names — prefix replacement happens at import time.
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( $create ) {
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $create[1] . ";\n\n";
            }

            $count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
            // Use multi-row INSERTs (100 rows per statement) — like mysqldump default.
            // This reduces query count from N to N/100, dramatically faster on import.
            $batch       = 100;
            $offset      = 0;
            $insert_rows = 100; // rows per INSERT INTO ... VALUES (...),(...),...

            while ( $offset < $count ) {
                $rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$batch}", ARRAY_A );
                if ( ! $rows ) break;

                $cols        = array_map( function( $c ) { return "`{$c}`"; }, array_keys( $rows[0] ) );
                $cols_str    = implode( ',', $cols );
                $row_values  = [];

                // Get the raw mysqli connection handle — we need mysqli_real_escape_string()
                // directly, NOT $wpdb->_real_escape() which since WP 4.8.3 calls
                // add_placeholder_escape() and replaces % with a SHA256 hash.
                // That destroys WordPress permalink tokens (%postname%, %event%, etc.)
                // stored in wp_options. Direct mysqli escaping has no such side-effect.
                $db_handle = $wpdb->dbh;

                foreach ( $rows as $row ) {
                    $values = array_map( function( $v ) use ( $db_handle ) {
                        if ( $v === null ) return 'NULL';
                        return "'" . mysqli_real_escape_string( $db_handle, $v ) . "'";
                    }, array_values( $row ) );
                    $row_values[] = '(' . implode( ',', $values ) . ')';

                    // Flush every $insert_rows rows to keep statement size manageable
                    if ( count( $row_values ) >= $insert_rows ) {
                        $sql .= "INSERT IGNORE INTO `{$table}` ({$cols_str}) VALUES\n  "
                              . implode( ",\n  ", $row_values ) . ";\n";
                        $row_values = [];
                    }
                }
                // Flush remaining rows
                if ( ! empty( $row_values ) ) {
                    $sql .= "INSERT IGNORE INTO `{$table}` ({$cols_str}) VALUES\n  "
                          . implode( ",\n  ", $row_values ) . ";\n";
                }

                $offset += $batch;

                // Write to disk every 2MB to avoid memory spikes
                if ( strlen( $sql ) > 2 * 1024 * 1024 ) {
                    file_put_contents( $filepath, $sql, FILE_APPEND );
                    $sql = '';
                }
            }

            file_put_contents( $filepath, $sql, FILE_APPEND );

            $table_info[] = [
                'name' => $table,
                'rows' => $count,
                'file' => $filename,
            ];
        }

        file_put_contents( $sql_dir . '99_footer.sql', "\nSET FOREIGN_KEY_CHECKS = 1;\n" );

        $this->manifest['tables']       = $table_info;
        $this->manifest['steps_done'][] = 'database';
        $this->save_manifest();

        return [
            'session_id'   => $this->session_id,
            'tables_count' => count( $tables ),
            'next_step'    => 'files_scan',
            'progress'     => 25,
            'message'      => count( $tables ) . ' tables exported. Scanning files...',
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
                    if ( in_array( $item, [ 'wp-clone-master', 'wpcm-backups', 'wpcm-temp', 'wpcm-logs' ], true ) ) continue;

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
        // Without them the clone behaves differently from the source.
        // We bundle them in a dedicated zip so step_files can restore them.
        $dropin_names = [
            'object-cache.php', 'advanced-cache.php', 'db.php',
            'db-error.php', 'fatal-error-handler.php', 'maintenance.php',
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
            'message'     => count( $queue ) . ' archive jobs planned. Starting file archiving...',
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
                'message'    => 'All files archived. Capturing config...',
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
            'message'     => "[{$done}/{$total}] " . $job['label'] . $size_str . ( $has_more ? '' : ' — All files archived.' ),
        ];
    }

    // =========================================================================
    // Step 5: Capture configuration
    // =========================================================================
    private function step_config() {
        $config = [];
        $config['DB_HOST']      = '{{DB_HOST}}';
        $config['DB_NAME']      = '{{DB_NAME}}';
        $config['DB_USER']      = '{{DB_USER}}';
        $config['DB_PASSWORD']  = '{{DB_PASSWORD}}';
        $config['DB_CHARSET']   = defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4';
        $config['DB_COLLATE']   = defined( 'DB_COLLATE' ) ? DB_COLLATE : '';
        $config['table_prefix'] = $GLOBALS['wpdb']->prefix;

        $constants = [
            'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY',
            'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS',
            'WP_AUTO_UPDATE_CORE', 'WP_MEMORY_LIMIT',
            'WP_MAX_MEMORY_LIMIT', 'EMPTY_TRASH_DAYS',
            'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL',
            'WP_CACHE', 'COMPRESS_CSS', 'COMPRESS_SCRIPTS',
            'CONCATENATE_SCRIPTS', 'ENFORCE_GZIP',
            'MULTISITE', 'WP_ALLOW_MULTISITE',
        ];
        foreach ( $constants as $const ) {
            if ( defined( $const ) ) {
                $config[ $const ] = constant( $const );
            }
        }
        file_put_contents( $this->session_dir . 'config.json', wp_json_encode( $config, JSON_PRETTY_PRINT ) );

        $plugins_data = [];
        foreach ( get_plugins() as $file => $data ) {
            $plugins_data[ $file ] = [
                'name'    => $data['Name'],
                'version' => $data['Version'],
                'active'  => is_plugin_active( $file ),
            ];
        }
        file_put_contents( $this->session_dir . 'plugins.json', wp_json_encode( $plugins_data, JSON_PRETTY_PRINT ) );

        $themes_data = [];
        foreach ( wp_get_themes() as $slug => $theme ) {
            $themes_data[ $slug ] = [
                'name'    => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
                'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            ];
        }
        file_put_contents( $this->session_dir . 'themes.json', wp_json_encode( $themes_data, JSON_PRETTY_PRINT ) );

        $key_options = [
            'siteurl', 'home', 'blogname', 'blogdescription',
            'permalink_structure', 'default_role', 'timezone_string',
            'gmt_offset', 'date_format', 'time_format',
            'WPLANG', 'stylesheet', 'template',
        ];
        $options = [];
        foreach ( $key_options as $opt ) {
            $options[ $opt ] = get_option( $opt );
        }
        file_put_contents( $this->session_dir . 'options.json', wp_json_encode( $options, JSON_PRETTY_PRINT ) );

        // Root files
        $root_files = [];
        foreach ( [ '.htaccess', 'robots.txt', 'wp-config.php', '.user.ini', 'php.ini' ] as $pattern ) {
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
            'message'    => 'Configuration captured. Creating final package...',
        ];
    }

    // =========================================================================
    // Step 6: Package
    // =========================================================================
    private function step_package() {
        $this->manifest['steps_done'][] = 'package';
        $this->manifest['completed_at'] = WPCM_Date::now_str();
        $this->save_manifest();

        $site_name = sanitize_title( parse_url( site_url(), PHP_URL_HOST ) );
        $filename  = 'wpcm_' . $site_name . '_' . WPCM_Date::now_id() . '.zip';
        $zip_path  = WPCM_BACKUP_DIR . $filename;

        wp_mkdir_p( WPCM_BACKUP_DIR );

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new Exception( 'Cannot create ZIP archive at: ' . $zip_path );
        }

        $this->add_dir_to_zip( $zip, $this->session_dir, '' );
        $zip->close();

        if ( ! file_exists( $zip_path ) ) {
            throw new Exception( 'ZIP file was not created' );
        }

        return [
            'session_id'   => $this->session_id,
            'next_step'    => 'cleanup',
            'progress'     => 95,
            'filename'     => $filename,
            'size'         => size_format( filesize( $zip_path ) ),
            'path'         => $zip_path,
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
            'message'    => 'Export complete! Temporary files cleaned up.',
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function create_zip_from_dir( $source_dir, $zip_path, $prefix ) {
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new Exception( "Cannot create ZIP: {$zip_path}" );
        }

        $count = 0;
        // Exclude patterns — matched against the BASENAME only (not the full path).
        // Using strpos on the full relative path caused false positives:
        // e.g., 'cache' excluded polylang/src/integrations/cache/ which is plugin source code.
        // Duplicator/AI1WM approach: only skip by exact basename match at top level,
        // never kill legitimate plugin subdirectories.
        $exclude_basenames = [
            'node_modules', '.git', '.svn',
            'wp-clone-master', 'wpcm-backups', 'wpcm-temp', 'wpcm-logs',
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
            throw new Exception( "Cannot create ZIP: {$zip_path}" );
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
            $item->isDir() ? rmdir( $item->getRealPath() ) : unlink( $item->getRealPath() );
        }
        rmdir( $dir );
    }
}
