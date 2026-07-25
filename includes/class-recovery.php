<?php
/**
 * Automatic recovery for abandoned transactional restores.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WPCM_Recovery {

    /**
     * Roll back a stale destructive transition before normal plugins load.
     *
     * @return void
     */
    public static function maybe_recover() {
        if ( ! function_exists( 'openssl_decrypt' ) || ! class_exists( 'mysqli' ) ) {
            return;
        }

        $candidates = array();
        if ( defined( 'WPCM_TEMP_DIR' ) && is_dir( WPCM_TEMP_DIR ) ) {
            $candidates = array_merge( $candidates, glob( trailingslashit( WPCM_TEMP_DIR ) . 'import_*/recovery-plan.php' ) ?: array() );
        }
        if ( defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR ) ) {
            $candidates = array_merge( $candidates, glob( trailingslashit( WP_CONTENT_DIR ) . 'wpcm-rollback-*/recovery-plan.php' ) ?: array() );
        }

        $processed = array();
        foreach ( array_values( array_unique( $candidates ) ) as $candidate_path ) {
            $candidate = self::trusted_candidate( $candidate_path );
            if ( ! is_array( $candidate ) ) {
                continue;
            }

            // The lock path is derived only from the already-trusted candidate
            // directory. No unsigned journal value is used for filesystem access.
            $lock = WPCM_Reliability::acquire_lock( $candidate['directory'] . 'restore.lock' );
            if ( false === $lock ) {
                continue;
            }

            try {
                // Re-read after acquiring the lock so validation and recovery use
                // one stable journal generation.
                $plan = self::read_inert_json( $candidate['path'] );
                if ( ! is_array( $plan ) || ! is_array( $plan['state'] ?? null ) || ! is_array( $plan['config'] ?? null ) ) {
                    continue;
                }

                $session_id = (string) ( $plan['config']['session_id'] ?? '' );
                if ( ! preg_match( '/^import_[A-Za-z0-9_-]{16,80}$/', $session_id ) || isset( $processed[ $session_id ] ) ) {
                    continue;
                }

                // The recovery key is read from the same trusted directory as the
                // candidate. Cross-directory paths are considered only after HMAC
                // authentication and strict path validation below.
                $recovery_key = self::decrypt_recovery_key( $candidate['directory'] . 'recovery-key.php', $session_id );
                if ( ! $recovery_key || ! self::verify_plan( $plan, $recovery_key ) ) {
                    throw new RuntimeException( 'The recovery journal signature is invalid.' );
                }

                $paths = self::validated_plan_paths( $plan, $candidate );
                if ( ! is_array( $paths ) ) {
                    throw new RuntimeException( 'The recovery journal contains invalid filesystem paths.' );
                }
                $processed[ $session_id ] = true;

                $phase = (string) ( $plan['state']['phase'] ?? '' );
                if ( in_array( $phase, array( 'completed', 'rolled_back' ), true ) ) {
                    self::cleanup_completed_session( $paths['session_dir'], $paths['rollback_dir'], $plan['state']['updated_at'] ?? '', $plan );
                    continue;
                }
                if ( ! in_array( $phase, array( 'promoting_files', 'files_promoted', 'committing_database', 'database_committed', 'health_check', 'rollback_required' ), true ) ) {
                    continue;
                }

                $updated     = strtotime( (string) ( $plan['state']['updated_at'] ?? '' ) );
                $stale_after = (int) apply_filters( 'wpcm_recovery_stale_seconds', 10 * MINUTE_IN_SECONDS );
                if ( ! $updated || ( time() - $updated ) < max( 60, $stale_after ) ) {
                    continue;
                }

                self::enable_maintenance( $paths['abspath'] );
                self::rollback_database( $plan );
                self::rollback_files( $plan );
                self::restore_bootstrap( $plan );
                self::disable_maintenance( $paths['abspath'] );

                self::recursive_delete( $paths['rollback_dir'] );
                self::recursive_delete( $paths['session_dir'] );

                update_option(
                    'wpcm_last_recovery_notice',
                    array(
                        'type'    => 'success',
                        'message' => __( 'Clone Master automatically restored the previous site state after an interrupted migration.', 'clone-master' ),
                        'time'    => time(),
                    ),
                    false
                );
            } catch ( Throwable $e ) {
                update_option(
                    'wpcm_last_recovery_notice',
                    array(
                        'type'    => 'error',
                        'message' => __( 'Clone Master detected an interrupted migration but automatic rollback failed: ', 'clone-master' ) . $e->getMessage(),
                        'time'    => time(),
                    ),
                    false
                );
            } finally {
                WPCM_Reliability::release_lock( $lock );
            }
        }
    }

    /**
     * Resolve a recovery journal only when its real path belongs to one of the
     * two fixed runtime locations controlled by Clone Master.
     *
     * @param string $path Candidate recovery-plan.php path.
     * @return array|null
     */
    private static function trusted_candidate( $path ) {
        $real = realpath( $path );
        if ( false === $real || ! is_file( $real ) || 'recovery-plan.php' !== basename( $real ) ) {
            return null;
        }

        $directory = trailingslashit( dirname( $real ) );
        if ( defined( 'WPCM_TEMP_DIR' ) && WPCM_Reliability::path_is_within( $real, WPCM_TEMP_DIR ) ) {
            if ( ! preg_match( '/^import_[A-Za-z0-9_-]{16,80}$/', basename( untrailingslashit( $directory ) ) ) ) {
                return null;
            }
            return array(
                'path'      => $real,
                'directory' => $directory,
                'kind'      => 'session',
            );
        }

        if ( defined( 'WP_CONTENT_DIR' ) && WPCM_Reliability::path_is_within( $real, WP_CONTENT_DIR ) ) {
            if ( ! preg_match( '/^wpcm-rollback-[a-f0-9]{10}$/', basename( untrailingslashit( $directory ) ) ) ) {
                return null;
            }
            return array(
                'path'      => $real,
                'directory' => $directory,
                'kind'      => 'rollback',
            );
        }

        return null;
    }

    /**
     * Normalize a directory and resolve symlinks when the path exists.
     *
     * @param string $path Directory path.
     * @return string
     */
    private static function canonical_directory( $path ) {
        $real = realpath( $path );
        return trailingslashit( wp_normalize_path( false !== $real ? $real : $path ) );
    }

    /**
     * Validate all signed recovery paths against deterministic local locations.
     *
     * @param array $plan      Authenticated recovery plan.
     * @param array $candidate Trusted candidate metadata.
     * @return array|null
     */
    private static function validated_plan_paths( array $plan, array $candidate ) {
        $config     = $plan['config'];
        $session_id = (string) ( $config['session_id'] ?? '' );
        $restore_id = (string) ( $config['restore_id'] ?? '' );
        if ( ! preg_match( '/^import_[A-Za-z0-9_-]{16,80}$/', $session_id ) || ! preg_match( '/^[a-f0-9]{10}$/', $restore_id ) ) {
            return null;
        }

        $expected_session  = trailingslashit( trailingslashit( WPCM_TEMP_DIR ) . $session_id );
        $expected_staging  = trailingslashit( $expected_session . 'staging' );
        $expected_rollback = trailingslashit( trailingslashit( WP_CONTENT_DIR ) . 'wpcm-rollback-' . $restore_id );
        $expected_content  = self::canonical_directory( WP_CONTENT_DIR );
        $expected_abspath  = self::canonical_directory( ABSPATH );

        $actual_session  = trailingslashit( wp_normalize_path( dirname( untrailingslashit( (string) ( $config['staging_dir'] ?? '' ) ) ) ) );
        $actual_staging  = trailingslashit( wp_normalize_path( (string) ( $config['staging_dir'] ?? '' ) ) );
        $actual_rollback = trailingslashit( wp_normalize_path( (string) ( $config['rollback_dir'] ?? '' ) ) );
        $actual_content  = self::canonical_directory( (string) ( $config['wp_content_dir'] ?? '' ) );
        $actual_abspath  = self::canonical_directory( (string) ( $config['abspath'] ?? '' ) );

        if ( ! hash_equals( wp_normalize_path( $expected_session ), $actual_session )
            || ! hash_equals( wp_normalize_path( $expected_staging ), $actual_staging )
            || ! hash_equals( wp_normalize_path( $expected_rollback ), $actual_rollback )
            || ! hash_equals( $expected_content, $actual_content )
            || ! hash_equals( $expected_abspath, $actual_abspath ) ) {
            return null;
        }

        $candidate_directory = self::canonical_directory( $candidate['directory'] );
        $allowed_candidate   = 'session' === $candidate['kind']
            ? self::canonical_directory( $expected_session )
            : self::canonical_directory( $expected_rollback );
        if ( ! hash_equals( $allowed_candidate, $candidate_directory ) ) {
            return null;
        }

        foreach ( array( 'staging_prefix', 'rollback_prefix', 'target_prefix' ) as $prefix_key ) {
            if ( ! preg_match( '/^[A-Za-z0-9_]{1,50}$/', (string) ( $config[ $prefix_key ] ?? '' ) ) ) {
                return null;
            }
        }

        return array(
            'session_dir'  => $expected_session,
            'rollback_dir' => $expected_rollback,
            'abspath'      => $expected_abspath,
        );
    }

    /**
     * Remove old terminal restore journals.
     *
     * @param string $session_dir  Session directory.
     * @param string $rollback_dir Rollback directory.
     * @param string $updated_at   Last update timestamp.
     * @return void
     */
    private static function cleanup_completed_session( $session_dir, $rollback_dir, $updated_at, array $plan ) {
        // A process may die after writing the durable completed phase but before
        // removing the temporary MU bootstrap. Restore that path immediately;
        // completed journals are never rolled back.
        try {
            self::restore_bootstrap( $plan );
        } catch ( Throwable $ignored ) {
            return;
        }

        $updated = strtotime( (string) $updated_at );
        if ( $updated && ( time() - $updated ) > DAY_IN_SECONDS ) {
            self::recursive_delete( $rollback_dir );
            self::recursive_delete( $session_dir );
        }
    }

    /**
     * Decrypt the recovery HMAC key using the destination WordPress salts.
     *
     * @param string $path       Recovery key file.
     * @param string $session_id Session identifier used as AEAD associated data.
     * @return string|false
     */
    private static function decrypt_recovery_key( $path, $session_id ) {
        $data = self::read_inert_json( $path );
        if ( ! is_array( $data ) || empty( $data['payload'] ) || ( $data['session_id'] ?? '' ) !== $session_id ) {
            return false;
        }
        $raw = base64_decode( $data['payload'], true );
        if ( false === $raw || strlen( $raw ) < 29 ) {
            return false;
        }
        $iv     = substr( $raw, 0, 12 );
        $tag    = substr( $raw, 12, 16 );
        $cipher = substr( $raw, 28 );
        $key    = WPCM_Reliability::recovery_encryption_key();
        $plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $session_id );
        return is_string( $plain ) && preg_match( '/^[a-f0-9]{64}$/', $plain ) ? $plain : false;
    }

    /**
     * Verify the recovery plan HMAC.
     *
     * @param array  $plan Recovery plan.
     * @param string $key  Hexadecimal recovery key.
     * @return bool
     */
    private static function verify_plan( array $plan, $key ) {
        $expected = (string) ( $plan['hmac'] ?? '' );
        unset( $plan['hmac'] );
        $json   = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        $actual = hash_hmac( 'sha256', $json, hex2bin( $key ) );
        return $expected && hash_equals( $expected, $actual );
    }

    /**
     * Determine the database transition state.
     *
     * @param mysqli $mysqli Database connection.
     * @param array  $state  Restore state.
     * @return string pending|committed|conflict|invalid
     */
    private static function database_status( $mysqli, array $state ) {
        $mapping  = $state['database']['mapping'] ?? array();
        $rollback = $state['database']['rollback'] ?? array();
        if ( empty( $mapping ) ) {
            return 'invalid';
        }

        $rollback_by_target = array();
        foreach ( $rollback as $item ) {
            $rollback_by_target[ $item['target'] ] = $item['rollback'];
        }
        $mapped_targets = array_fill_keys( array_column( $mapping, 'target' ), true );
        $pending = true;
        $committed = true;

        foreach ( $mapping as $item ) {
            $stage_exists  = self::table_exists( $mysqli, $item['stage'] );
            $target_exists = self::table_exists( $mysqli, $item['target'] );
            $pending       = $pending && $stage_exists;
            $committed     = $committed && ! $stage_exists && $target_exists;
            if ( isset( $rollback_by_target[ $item['target'] ] ) ) {
                $rollback_exists = self::table_exists( $mysqli, $rollback_by_target[ $item['target'] ] );
                $pending         = $pending && $target_exists && ! $rollback_exists;
                $committed       = $committed && $rollback_exists;
            } else {
                $pending = $pending && ! $target_exists;
            }
        }
        foreach ( $rollback as $item ) {
            if ( isset( $mapped_targets[ $item['target'] ] ) ) {
                continue;
            }
            $target_exists   = self::table_exists( $mysqli, $item['target'] );
            $rollback_exists = self::table_exists( $mysqli, $item['rollback'] );
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

    /**
     * Reverse an atomic database table promotion.
     *
     * @param array $plan Recovery plan.
     * @return void
     */
    private static function rollback_database( array $plan ) {
        $state = $plan['state'];
        if ( empty( $state['database']['mapping'] ) ) {
            return;
        }

        mysqli_report( MYSQLI_REPORT_OFF );
        $mysqli = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
        if ( $mysqli->connect_errno ) {
            throw new RuntimeException( 'Database recovery connection failed: ' . $mysqli->connect_error );
        }
        $status = self::database_status( $mysqli, $state );
        if ( 'pending' === $status ) {
            $mysqli->close();
            return;
        }
        if ( 'committed' !== $status ) {
            $mysqli->close();
            throw new RuntimeException( 'Database recovery refused because the transition state is conflicting.' );
        }

        $clauses = array();
        $failed  = array();
        foreach ( $state['database']['mapping'] as $index => $item ) {
            $failed_name = 'wpcmfail_' . ( $plan['config']['restore_id'] ?? 'restore' ) . '_' . sprintf( '%04d', $index );
            if ( self::table_exists( $mysqli, $failed_name ) ) {
                $mysqli->query( 'DROP TABLE IF EXISTS ' . self::quote_identifier( $failed_name ) );
            }
            $clauses[] = self::quote_identifier( $item['target'] ) . ' TO ' . self::quote_identifier( $failed_name );
            $failed[]  = $failed_name;
        }
        foreach ( $state['database']['rollback'] ?? array() as $item ) {
            $clauses[] = self::quote_identifier( $item['rollback'] ) . ' TO ' . self::quote_identifier( $item['target'] );
        }

        if ( ! empty( $clauses ) && ! $mysqli->query( 'RENAME TABLE ' . implode( ', ', $clauses ) ) ) {
            $error = $mysqli->error;
            $mysqli->close();
            throw new RuntimeException( 'Database recovery rename failed: ' . $error );
        }
        foreach ( $failed as $table ) {
            if ( ! $mysqli->query( 'DROP TABLE IF EXISTS ' . self::quote_identifier( $table ) ) ) {
                $error = $mysqli->error;
                $mysqli->close();
                throw new RuntimeException( 'Unable to remove failed promoted table: ' . $error );
            }
        }
        $mysqli->close();
    }

    /**
     * Reverse promoted filesystem paths using the write-ahead records.
     *
     * @param array $plan Recovery plan.
     * @return void
     */
    private static function rollback_files( array $plan ) {
        $records = array_reverse( $plan['state']['files']['promoted'] ?? array() );
        foreach ( $records as $record ) {
            $target_exists = file_exists( $record['target'] ) || is_link( $record['target'] );
            $source_exists = ! empty( $record['source'] ) && ( file_exists( $record['source'] ) || is_link( $record['source'] ) );
            $backup_exists = file_exists( $record['backup'] ) || is_link( $record['backup'] );
            $status        = (string) ( $record['status'] ?? 'planned' );
            $was_promoted  = 'promoted' === $status
                || ( 'replace' === ( $record['mode'] ?? '' ) && $target_exists && ! $source_exists )
                || ( 'remove' === ( $record['mode'] ?? '' ) && ! $target_exists && ( $backup_exists || empty( $record['had_target'] ) ) );

            if ( $was_promoted && $target_exists ) {
                self::recursive_delete( $record['target'] );
            }
            if ( ! empty( $record['had_target'] ) && $backup_exists ) {
                if ( file_exists( $record['target'] ) || is_link( $record['target'] ) ) {
                    self::recursive_delete( $record['target'] );
                }
                if ( ! is_dir( dirname( $record['target'] ) ) && ! wp_mkdir_p( dirname( $record['target'] ) ) ) {
                    throw new RuntimeException( 'Unable to recreate a recovery destination directory.' );
                }
                if ( ! @rename( $record['backup'], $record['target'] ) ) {
                    throw new RuntimeException( 'Unable to restore filesystem path: ' . $record['target'] );
                }
            }
        }
    }

    /**
     * Restore a pre-existing MU recovery bootstrap or remove the temporary one.
     *
     * @param array $plan Recovery plan.
     * @return void
     */
    private static function restore_bootstrap( array $plan ) {
        $record = $plan['state']['files']['bootstrap'] ?? array();
        if ( empty( $record['target'] ) ) {
            return;
        }
        $target_exists = file_exists( $record['target'] ) || is_link( $record['target'] );
        $backup_exists = file_exists( $record['backup'] ) || is_link( $record['backup'] );

        if ( ! empty( $record['had_target'] ) ) {
            // When the backup no longer exists, a previous cleanup already
            // restored it. Never delete that restored third-party MU-plugin.
            if ( ! $backup_exists ) {
                return;
            }
            if ( $target_exists ) {
                self::recursive_delete( $record['target'] );
            }
            if ( ! @rename( $record['backup'], $record['target'] ) ) {
                throw new RuntimeException( 'Unable to restore the previous recovery bootstrap.' );
            }
            return;
        }

        if ( $target_exists ) {
            self::recursive_delete( $record['target'] );
        }
    }

    /**
     * Test whether a base table exists.
     *
     * @param mysqli $mysqli Connection.
     * @param string $table  Table name.
     * @return bool
     */
    private static function table_exists( $mysqli, $table ) {
        $escaped = $mysqli->real_escape_string( $table );
        $result  = $mysqli->query( "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$escaped}' AND TABLE_TYPE='BASE TABLE' LIMIT 1" );
        if ( ! $result ) {
            throw new RuntimeException( 'Unable to inspect recovery table state: ' . $mysqli->error );
        }
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }

    /**
     * Quote a MySQL identifier.
     *
     * @param string $name Identifier.
     * @return string
     */
    private static function quote_identifier( $name ) {
        return '`' . str_replace( '`', '``', $name ) . '`';
    }

    /**
     * Write WordPress maintenance mode atomically.
     *
     * @param string $abspath WordPress root.
     * @return void
     */
    private static function enable_maintenance( $abspath ) {
        $path = trailingslashit( $abspath ) . '.maintenance';
        WPCM_Reliability::atomic_write( $path, '<?php $upgrading = ' . ( time() + HOUR_IN_SECONDS ) . '; ?>', 0644 );
    }

    /**
     * Disable WordPress maintenance mode.
     *
     * @param string $abspath WordPress root.
     * @return void
     */
    private static function disable_maintenance( $abspath ) {
        @unlink( trailingslashit( $abspath ) . '.maintenance' );
    }

    /**
     * Read JSON appended after an inert PHP guard.
     *
     * @param string $path File path.
     * @return array|null
     */
    private static function read_inert_json( $path ) {
        if ( ! is_readable( $path ) ) {
            return null;
        }
        $raw    = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local recovery file.
        $marker = "__halt_compiler();\n";
        $offset = false !== $raw ? strpos( $raw, $marker ) : false;
        return false === $offset ? null : json_decode( substr( $raw, $offset + strlen( $marker ) ), true );
    }

    /**
     * Remove a file or directory tree without following symlinks.
     *
     * @param string $path Path.
     * @return void
     */
    private static function recursive_delete( $path ) {
        if ( ! $path ) {
            return;
        }
        if ( is_link( $path ) || is_file( $path ) ) {
            @unlink( $path );
            return;
        }
        if ( ! is_dir( $path ) ) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isLink() || $item->isFile() ) {
                @unlink( $item->getPathname() );
            } else {
                @rmdir( $item->getPathname() );
            }
        }
        @rmdir( $path );
    }
}
