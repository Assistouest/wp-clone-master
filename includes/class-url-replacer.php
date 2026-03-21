<?php
/**
 * URL Replacer — Serialization-safe search and replace engine
 *
 * This is the HEART of the migration system. It handles:
 * 1. PHP serialized data (recalculates string lengths)
 * 2. JSON-encoded data (Elementor, Gutenberg, etc.)
 * 3. Plain text in all DB tables
 * 4. File paths (server absolute paths)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPCM_URL_Replacer {

    private $search  = [];
    private $replace = [];
    private $log     = [];
    private $stats   = [
        'tables_processed'  => 0,
        'rows_updated'      => 0,
        'cells_changed'     => 0,
        'serialized_fixes'  => 0,
        'files_updated'     => 0,
    ];

    /**
     * Configure the replacements
     *
     * @param string $old_url   e.g. https://old-domain.com
     * @param string $new_url   e.g. https://new-domain.com
     * @param string $old_path  e.g. /home/old/public_html
     * @param string $new_path  e.g. /home/new/www
     */
    public function configure( $old_url, $new_url, $old_path = '', $new_path = '' ) {
        // Normalize: remove trailing slashes
        $old_url = untrailingslashit( $old_url );
        $new_url = untrailingslashit( $new_url );

        // Build replacement pairs (order matters — most specific first)
        $this->search  = [];
        $this->replace = [];

        // With protocol variations
        $old_https = str_replace( 'http://', 'https://', $old_url );
        $old_http  = str_replace( 'https://', 'http://', $old_url );

        // JSON-escaped versions (Elementor, Gutenberg blocks, etc.)
        $old_json = str_replace( '/', '\\/', $old_url );
        $new_json = str_replace( '/', '\\/', $new_url );

        // Protocol-relative
        $old_relative = preg_replace( '#^https?://#', '//', $old_url );
        $new_relative = preg_replace( '#^https?://#', '//', $new_url );

        // HTTPS version
        $this->search[]  = $old_https;
        $this->replace[] = $new_url;

        // HTTP version
        if ( $old_http !== $old_https ) {
            $this->search[]  = $old_http;
            $this->replace[] = $new_url;
        }

        // Protocol-relative
        $this->search[]  = $old_relative;
        $this->replace[] = $new_relative;

        // JSON-escaped
        $this->search[]  = str_replace( '/', '\\/', $old_https );
        $this->replace[] = $new_json;
        if ( $old_http !== $old_https ) {
            $this->search[]  = str_replace( '/', '\\/', $old_http );
            $this->replace[] = $new_json;
        }

        // Server paths
        if ( $old_path && $new_path ) {
            $old_path = untrailingslashit( $old_path );
            $new_path = untrailingslashit( $new_path );
            if ( $old_path !== $new_path ) {
                $this->search[]  = $old_path;
                $this->replace[] = $new_path;
                // JSON-escaped paths (Windows-style backslashes in JSON)
                $this->search[]  = str_replace( '/', '\\/', $old_path );
                $this->replace[] = str_replace( '/', '\\/', $new_path );
            }
        }

        // Also handle @2x encoded URLs (some email plugins)
        $old_encoded = urlencode( $old_url );
        $new_encoded = urlencode( $new_url );
        if ( $old_encoded !== $old_url ) {
            $this->search[]  = $old_encoded;
            $this->replace[] = $new_encoded;
        }
    }

    /**
     * Run replacement across all database tables
     */
    public function replace_in_database() {
        global $wpdb;

        // Get ALL tables with the WP prefix (includes plugin custom tables)
        $tables = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
                DB_NAME,
                $wpdb->prefix . '%'
            )
        );

        foreach ( $tables as $table ) {
            $this->replace_in_table( $table );
        }

        $this->log( "Database replacement complete: {$this->stats['rows_updated']} rows updated, {$this->stats['cells_changed']} cells changed" );
        return $this->stats;
    }

    /**
     * Replace in a single table
     */
    private function replace_in_table( $table ) {
        global $wpdb;

        // Get columns
        $columns = $wpdb->get_results( "DESCRIBE `{$table}`" );
        if ( ! $columns ) return;

        // Find primary key
        $primary_key = null;
        $text_columns = [];
        foreach ( $columns as $col ) {
            if ( $col->Key === 'PRI' ) {
                $primary_key = $col->Field;
            }
            // Only process text-like columns
            if ( preg_match( '/char|text|blob|enum|set/i', $col->Type ) ) {
                $text_columns[] = $col->Field;
            }
        }

        if ( empty( $text_columns ) ) return;

        // Fallback: use first column if no primary key
        if ( ! $primary_key ) {
            $primary_key = $columns[0]->Field;
        }

        // Process in batches
        $batch_size = 500;
        $offset = 0;

        do {
            $rows = $wpdb->get_results(
                "SELECT `{$primary_key}`, " . implode( ', ', array_map( function( $c ) { return "`{$c}`"; }, $text_columns ) ) .
                " FROM `{$table}` LIMIT {$offset}, {$batch_size}",
                ARRAY_A
            );

            if ( ! $rows ) break;

            foreach ( $rows as $row ) {
                $updates = [];
                foreach ( $text_columns as $col ) {
                    if ( empty( $row[ $col ] ) ) continue;

                    $original = $row[ $col ];
                    $new_value = $this->replace_value( $original );

                    if ( $new_value !== $original ) {
                        $updates[ $col ] = $new_value;
                        $this->stats['cells_changed']++;
                    }
                }

                if ( ! empty( $updates ) ) {
                    $wpdb->update(
                        $table,
                        $updates,
                        [ $primary_key => $row[ $primary_key ] ]
                    );
                    $this->stats['rows_updated']++;
                }
            }

            $offset += $batch_size;
        } while ( count( $rows ) === $batch_size );

        $this->stats['tables_processed']++;
    }

    /**
     * Replace in a single value — handles serialized data recursively
     */
    public function replace_value( $value ) {
        // Quick check: does this value even contain any of our search strings?
        $contains = false;
        foreach ( $this->search as $s ) {
            if ( strpos( $value, $s ) !== false ) {
                $contains = true;
                break;
            }
        }
        if ( ! $contains ) return $value;

        // Try to unserialize
        $unserialized = @unserialize( $value );
        if ( $unserialized !== false || $value === 'b:0;' ) {
            // It's serialized data — do recursive replacement then re-serialize
            $replaced = $this->replace_recursive( $unserialized );
            $new_value = serialize( $replaced );
            $this->stats['serialized_fixes']++;
            return $new_value;
        }

        // Try JSON decode
        $json = json_decode( $value, true );
        if ( $json !== null && ( is_array( $json ) || is_object( $json ) ) ) {
            $replaced = $this->replace_recursive( $json );
            $new_value = wp_json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( $new_value !== false ) {
                return $new_value;
            }
        }

        // Plain text replacement
        return str_replace( $this->search, $this->replace, $value );
    }

    /**
     * Recursively replace in arrays/objects (preserves serialization safety)
     */
    private function replace_recursive( $data ) {
        if ( is_string( $data ) ) {
            // Check if string itself is serialized (nested serialization)
            $nested = @unserialize( $data );
            if ( $nested !== false || $data === 'b:0;' ) {
                $replaced = $this->replace_recursive( $nested );
                return serialize( $replaced );
            }

            // Check if string is JSON
            $json = json_decode( $data, true );
            if ( $json !== null && ( is_array( $json ) || is_object( $json ) ) ) {
                $replaced = $this->replace_recursive( $json );
                $encoded = wp_json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                return $encoded !== false ? $encoded : str_replace( $this->search, $this->replace, $data );
            }

            return str_replace( $this->search, $this->replace, $data );
        }

        if ( is_array( $data ) ) {
            $result = [];
            foreach ( $data as $key => $value ) {
                $new_key = is_string( $key ) ? str_replace( $this->search, $this->replace, $key ) : $key;
                $result[ $new_key ] = $this->replace_recursive( $value );
            }
            return $result;
        }

        if ( is_object( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data->$key = $this->replace_recursive( $value );
            }
            return $data;
        }

        // int, float, bool, null — return as-is
        return $data;
    }

    /**
     * Replace in filesystem files
     */
    public function replace_in_files() {
        $files_to_check = [
            ABSPATH . '.htaccess',
            ABSPATH . 'wp-config.php',
        ];

        // Theme files
        $theme_dir = get_stylesheet_directory();
        if ( is_dir( $theme_dir ) ) {
            $files_to_check = array_merge( $files_to_check, $this->glob_recursive( $theme_dir, '*.{php,css,js,json}' ) );
        }

        // Parent theme if child theme
        $parent_dir = get_template_directory();
        if ( $parent_dir !== $theme_dir && is_dir( $parent_dir ) ) {
            $files_to_check = array_merge( $files_to_check, $this->glob_recursive( $parent_dir, '*.{php,css,js,json}' ) );
        }

        foreach ( $files_to_check as $file ) {
            if ( ! file_exists( $file ) || ! is_writable( $file ) ) continue;

            $content = file_get_contents( $file );
            if ( $content === false ) continue;

            $new_content = str_replace( $this->search, $this->replace, $content );
            if ( $new_content !== $content ) {
                file_put_contents( $file, $new_content );
                $this->stats['files_updated']++;
            }
        }

        return $this->stats;
    }

    /**
     * Preview changes without applying (dry run)
     */
    public function dry_run() {
        global $wpdb;
        $preview = [];
        $count = 0;

        $tables = [ $wpdb->options, $wpdb->posts, $wpdb->postmeta ];

        foreach ( $tables as $table ) {
            $columns = $wpdb->get_results( "DESCRIBE `{$table}`" );
            foreach ( $columns as $col ) {
                if ( ! preg_match( '/char|text|blob/i', $col->Type ) ) continue;

                foreach ( $this->search as $idx => $s ) {
                    $found = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$table}` WHERE `{$col->Field}` LIKE %s",
                            '%' . $wpdb->esc_like( $s ) . '%'
                        )
                    );
                    if ( $found > 0 ) {
                        $preview[] = [
                            'table'   => $table,
                            'column'  => $col->Field,
                            'search'  => $s,
                            'replace' => $this->replace[ $idx ],
                            'count'   => (int) $found,
                        ];
                        $count += (int) $found;
                    }
                }
            }
        }

        return [
            'total_replacements' => $count,
            'details'            => $preview,
        ];
    }

    public function get_stats() {
        return $this->stats;
    }

    public function get_log() {
        return $this->log;
    }

    private function log( $msg ) {
        $this->log[] = date( '[Y-m-d H:i:s] ' ) . $msg;
    }

    private function glob_recursive( $dir, $pattern ) {
        $files = [];
        $flags = GLOB_BRACE | GLOB_NOSORT;
        foreach ( glob( $dir . '/' . $pattern, $flags ) as $f ) {
            $files[] = $f;
        }
        foreach ( glob( $dir . '/*', GLOB_ONLYDIR | GLOB_NOSORT ) as $sub ) {
            $basename = basename( $sub );
            if ( in_array( $basename, [ 'node_modules', '.git', 'vendor', 'cache' ] ) ) continue;
            $files = array_merge( $files, $this->glob_recursive( $sub, $pattern ) );
        }
        return $files;
    }
}
