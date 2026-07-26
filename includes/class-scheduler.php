<?php
/**
 * Resumable automatic backup scheduler.
 *
 * Every export transition is persisted in an HMAC-authenticated state file.
 * A cron request, an administrator status poll, or a later WordPress request can
 * continue the same job without repeating completed destructive side effects.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPCM_Scheduler {

    const CRON_HOOK     = 'wpcm_scheduled_backup';
    const CONTINUE_HOOK = 'wpcm_continue_backup';
    const LOCK_KEY      = 'wpcm_backup_lock';
    const LOCK_TTL      = 3600;
    const JOB_CONTEXT   = 'scheduled-backup-job:v2';

    /** @var WPCM_Backup_Settings */
    private $settings;

    public function __construct() {
        $this->settings = new WPCM_Backup_Settings();
    }

    /**
     * Register custom intervals used by the settings screen.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function register_intervals( array $schedules ): array {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'clone-master' ),
            );
        }
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'clone-master' ),
            );
        }
        return $schedules;
    }

    /**
     * Recreate the recurring trigger without touching an active resumable job.
     *
     * @return void
     */
    public function schedule_backup(): void {
        $this->clear_hook( self::CRON_HOOK );

        if ( ! $this->settings->enabled ) {
            return;
        }

        $frequency = $this->settings->frequency;
        $schedules = wp_get_schedules();
        if ( ! isset( $schedules[ $frequency ] ) ) {
            $frequency = 'daily';
        }

        $scheduled = wp_schedule_event( strtotime( 'next hour' ), $frequency, self::CRON_HOOK );
        if ( is_wp_error( $scheduled ) || false === $scheduled ) {
            $message = is_wp_error( $scheduled )
                ? $scheduled->get_error_message()
                : __( 'WordPress could not schedule the recurring backup event.', 'clone-master' );
            throw new RuntimeException( $message );
        }
    }

    /**
     * Cancel recurring and continuation events.
     *
     * The persisted job is intentionally left untouched. Plugin deactivation
     * removes the temporary directory after calling this method.
     *
     * @return void
     */
    public function cancel_backup(): void {
        $this->clear_hook( self::CRON_HOOK );
        $this->clear_hook( self::CONTINUE_HOOK );
    }

    /**
     * Return the next recurring backup timestamp.
     *
     * @return int|null
     */
    public function get_next_run(): ?int {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        return $timestamp ? (int) $timestamp : null;
    }

    /**
     * Queue a new manual or automatic job with a durable run ID.
     *
     * @param string $trigger Job origin.
     * @return array|WP_Error
     */
    public function queue_backup( string $trigger = 'manual' ) {
        $trigger = 'auto' === $trigger ? 'auto' : 'manual';
        $lock    = WPCM_Reliability::acquire_lock( $this->job_lock_path() );
        if ( false === $lock ) {
            return new WP_Error( 'wpcm_job_lock', __( 'The backup queue is currently busy.', 'clone-master' ) );
        }

        try {
            $existing = $this->read_job();
            if ( is_array( $existing ) ) {
                return new WP_Error(
                    'wpcm_backup_running',
                    __( 'A backup is already running. Please wait for it to finish.', 'clone-master' ),
                    array( 'run_id' => (string) ( $existing['run_id'] ?? '' ) )
                );
            }

            $run_id = gmdate( 'Ymd_His' ) . '_' . strtolower( wp_generate_password( 8, false, false ) );
            $job    = array(
                'format'             => 2,
                'run_id'             => $run_id,
                'trigger'            => $trigger,
                'stage'              => 'export',
                'step'               => 'init',
                'session_id'         => '',
                'package'            => array(),
                'progress'           => 0,
                'message'            => __( 'Backup queued.', 'clone-master' ),
                'created_at'         => time(),
                'updated_at'         => time(),
                'finalization_stage' => '',
                'entry'              => array(
                    'id'           => $run_id,
                    'trigger'      => $trigger,
                    'started_at'   => gmdate( 'Y-m-d H:i:s' ),
                    'finished_at'  => '',
                    'duration_sec' => 0,
                    'status'       => 'running',
                    'filename'     => '',
                    'size_bytes'   => 0,
                    'error'        => null,
                ),
            );
            $this->write_job( $job );
            $this->schedule_continuation( 1 );
            return $job;
        } finally {
            WPCM_Reliability::release_lock( $lock );
        }
    }

    /**
     * Cron callback and synchronous continuation entry point.
     *
     * A recurring cron event starts a job when none exists. Continuation hooks
     * only resume the already persisted job.
     *
     * @param string $trigger auto, manual, or resume.
     * @return array
     */
    public function run_backup( string $trigger = 'auto' ): array {
        $job = $this->read_job();
        if ( ! is_array( $job ) ) {
            if ( 'resume' === $trigger ) {
                return array( 'status' => 'idle' );
            }
            $queued = $this->queue_backup( 'manual' === $trigger ? 'manual' : 'auto' );
            if ( is_wp_error( $queued ) ) {
                return array(
                    'status'  => 'error',
                    'message' => $queued->get_error_message(),
                );
            }
        }

        return $this->process_slice();
    }

    /**
     * Resume an existing job from the continuation cron hook.
     *
     * @return array
     */
    public function continue_backup(): array {
        return $this->run_backup( 'resume' );
    }

    /**
     * Resume one bounded slice while an administrator is polling the status.
     *
     * This browser-assisted fallback is important on hosts where loopback
     * requests or WP-Cron spawning are blocked. The same signed job is used, so
     * cron and browser requests cannot process a transition concurrently.
     *
     * @return array
     */
    public function resume_from_status_poll(): array {
        if ( ! is_array( $this->read_job() ) ) {
            return array( 'status' => 'idle' );
        }
        return $this->process_slice( 8.0, 2 );
    }

    /**
     * Return the authenticated active job state.
     *
     * @return array|null
     */
    public function get_active_job(): ?array {
        $job = $this->read_job();
        return is_array( $job ) ? $job : null;
    }

    /**
     * Process bounded export/finalization transitions.
     *
     * @param float $budget_seconds Maximum wall-clock budget.
     * @param int   $max_transitions Maximum state transitions.
     * @return array
     */
    private function process_slice( float $budget_seconds = 18.0, int $max_transitions = 8 ): array {
        $operation_lock = WPCM_Reliability::acquire_lock( WPCM_TEMP_DIR . 'backup-operation.lock' );
        if ( false === $operation_lock ) {
            $job = $this->read_job();
            return array(
                'status'   => 'running',
                'run_id'   => (string) ( $job['run_id'] ?? '' ),
                'progress' => (int) ( $job['progress'] ?? 0 ),
                'message'  => __( 'Another request is processing this backup.', 'clone-master' ),
            );
        }

        set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
        $started     = microtime( true );
        $transitions = 0;

        try {
            $job = $this->read_job();
            if ( ! is_array( $job ) ) {
                return array( 'status' => 'idle' );
            }

            if ( time() - (int) ( $job['created_at'] ?? time() ) > DAY_IN_SECONDS ) {
                throw new RuntimeException( __( 'The backup job exceeded the 24-hour safety limit.', 'clone-master' ) );
            }

            while ( $transitions < $max_transitions && ( microtime( true ) - $started ) < $budget_seconds ) {
                if ( 'export' === $job['stage'] ) {
                    $job = $this->process_export_transition( $job );
                } elseif ( 'finalize' === $job['stage'] ) {
                    $job = $this->process_finalization_transition( $job );
                } else {
                    throw new RuntimeException( __( 'The persisted backup state has an unknown stage.', 'clone-master' ) );
                }

                ++$transitions;
                if ( 'complete' === ( $job['stage'] ?? '' ) ) {
                    return $this->complete_job( $job );
                }
            }

            $this->schedule_continuation( 30 );
            return array(
                'status'   => 'running',
                'run_id'   => (string) $job['run_id'],
                'progress' => (int) ( $job['progress'] ?? 0 ),
                'message'  => (string) ( $job['message'] ?? __( 'Backup in progress.', 'clone-master' ) ),
            );
        } catch ( Throwable $error ) {
            try {
                return $this->fail_job( $error );
            } catch ( Throwable $secondary ) {
                error_log( 'Clone Master scheduler failure: ' . $error->getMessage() . '; secondary failure: ' . $secondary->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Terminal background failure must remain diagnosable.
                return array(
                    'status'  => 'error',
                    'message' => $error->getMessage(),
                );
            }
        } finally {
            delete_transient( self::LOCK_KEY );
            WPCM_Reliability::release_lock( $operation_lock );
        }
    }

    /**
     * Execute one idempotent exporter transition and persist its result.
     *
     * @param array $job Job state.
     * @return array
     */
    private function process_export_transition( array $job ): array {
        $step       = (string) ( $job['step'] ?? 'init' );
        $session_id = (string) ( $job['session_id'] ?? '' );
        $exporter   = new WPCM_Exporter();
        $result     = 'init' === $step
            ? $exporter->run_step( 'init' )
            : $exporter->run_step( $step, $session_id );

        if ( ! is_array( $result ) ) {
            throw new RuntimeException( __( 'The exporter returned an invalid response.', 'clone-master' ) );
        }
        if ( 'error' === ( $result['next_step'] ?? '' ) ) {
            throw new RuntimeException( (string) ( $result['message'] ?? __( 'The export step failed.', 'clone-master' ) ) );
        }

        if ( ! empty( $result['session_id'] ) ) {
            $job['session_id'] = (string) $result['session_id'];
        }
        if ( 'package' === $step ) {
            $job['package'] = array(
                'filename' => (string) ( $result['filename'] ?? '' ),
                'sha256'   => sanitize_text_field( (string) ( $result['sha256'] ?? '' ) ),
            );
        }

        $job['progress']   = max( 0, min( 100, (int) ( $result['progress'] ?? $job['progress'] ) ) );
        $job['message']    = sanitize_text_field( (string) ( $result['message'] ?? '' ) );
        $job['updated_at'] = time();

        if ( null === ( $result['next_step'] ?? null ) ) {
            $job['stage']              = 'finalize';
            $job['step']               = '';
            $job['finalization_stage'] = 'label';
            $job['progress']            = 96;
            $job['message']             = __( 'Export verified. Finalizing backup storage.', 'clone-master' );
        } else {
            $job['step'] = (string) $result['next_step'];
        }

        $this->write_job( $job );
        return $job;
    }

    /**
     * Execute one retry-safe finalization stage.
     *
     * @param array $job Job state.
     * @return array
     */
    private function process_finalization_transition( array $job ): array {
        $stage = (string) ( $job['finalization_stage'] ?? 'label' );

        switch ( $stage ) {
            case 'label':
                $job = $this->finalize_local_archive( $job );
                $job['finalization_stage'] = 'upload';
                $job['message']            = __( 'Local archive verified. Applying configured storage.', 'clone-master' );
                break;

            case 'upload':
                $path   = WPCM_BACKUP_DIR . (string) $job['entry']['filename'];
                $driver = WPCM_Storage_Driver::make( $this->settings );
                $result = $driver->upload( $path, (string) $job['entry']['filename'] );
                $job['entry']['storage_driver']  = $driver->label();
                $job['entry']['storage_ok']      = ! empty( $result['ok'] );
                $job['entry']['storage_message'] = sanitize_text_field( (string) ( $result['message'] ?? '' ) );
                $job['entry']['remote_path']     = sanitize_text_field( (string) ( $result['remote_path'] ?? '' ) );
                if ( empty( $result['ok'] ) ) {
                    $job['entry']['storage_error'] = $job['entry']['storage_message'];
                    throw new RuntimeException(
                        $job['entry']['storage_error'] ?: __( 'The configured backup destination rejected the archive.', 'clone-master' )
                    );
                }
                $job['finalization_stage'] = 'local_cleanup';
                $job['message']            = __( 'Storage result persisted. Applying the local-copy policy.', 'clone-master' );
                break;

            case 'local_cleanup':
                if ( 'nextcloud' === $this->settings->storage_driver && false === $this->settings->nextcloud_keep_local ) {
                    $path = WPCM_BACKUP_DIR . (string) $job['entry']['filename'];
                    if ( is_file( $path ) ) {
                        $expected = strtolower( (string) ( $job['entry']['archive_sha256'] ?? '' ) );
                        $actual   = WPCM_Reliability::checksum( $path );
                        if ( $expected && ! hash_equals( $expected, strtolower( $actual ) ) ) {
                            throw new RuntimeException( __( 'The local archive changed before the post-upload cleanup step.', 'clone-master' ) );
                        }
                        if ( ! wp_delete_file( $path ) && is_file( $path ) ) {
                            throw new RuntimeException( __( 'The verified remote backup was stored, but the local copy could not be removed.', 'clone-master' ) );
                        }
                    }
                    $job['entry']['local_copy_kept'] = false;
                } else {
                    $job['entry']['local_copy_kept'] = true;
                }
                $job['finalization_stage'] = 'retention';
                $job['message']            = __( 'Local-copy policy complete. Applying retention.', 'clone-master' );
                break;

            case 'retention':
                $this->apply_retention();
                $job['finalization_stage'] = 'history';
                $job['message']            = __( 'Retention complete. Recording the result.', 'clone-master' );
                break;

            case 'history':
                $job['entry']['status']       = 'success';
                $job['entry']['finished_at']  = gmdate( 'Y-m-d H:i:s' );
                $job['entry']['duration_sec'] = max( 0, time() - (int) $job['created_at'] );
                WPCM_Backup_Settings::add_history_entry( $job['entry'] );
                $job['finalization_stage'] = 'notification';
                $job['message']            = __( 'Backup recorded. Sending notification if configured.', 'clone-master' );
                break;

            case 'notification':
                // Mark the notification as claimed before sending. This prevents
                // duplicate email after process death, at the cost of a very small
                // at-most-once window between this durable write and wp_mail().
                if ( empty( $job['notification_claimed'] ) ) {
                    $job['notification_claimed'] = true;
                    $job['updated_at']            = time();
                    $this->write_job( $job );
                    $this->send_notification( $job['entry'] );
                }
                $job['stage']    = 'complete';
                $job['progress'] = 100;
                $job['message']  = __( 'Backup complete.', 'clone-master' );
                break;

            default:
                throw new RuntimeException( __( 'The persisted finalization state is invalid.', 'clone-master' ) );
        }

        $job['updated_at'] = time();
        $this->write_job( $job );
        return $job;
    }

    /**
     * Verify and atomically apply the automatic filename prefix.
     *
     * @param array $job Job state.
     * @return array
     */
    private function finalize_local_archive( array $job ): array {
        $filename = (string) ( $job['package']['filename'] ?? '' );
        $expected = strtolower( (string) ( $job['package']['sha256'] ?? '' ) );
        if ( ! WPCM_Reliability::is_safe_archive_filename( $filename ) ) {
            throw new RuntimeException( __( 'The exporter did not provide a valid package filename.', 'clone-master' ) );
        }

        $target_name = $filename;
        if ( 'auto' === $job['trigger'] ) {
            if ( preg_match( '/^(.+)-backup-manual-(.+)\.wpcm$/', $filename, $matches ) ) {
                $target_name = $matches[1] . '-backup-auto-' . $matches[2] . '.wpcm';
            } elseif ( 0 !== strpos( $filename, 'auto_' ) ) {
                // Backward compatibility for a legacy package resumed after update.
                $target_name = 'auto_' . $filename;
            }
        }
        if ( ! WPCM_Reliability::is_safe_archive_filename( $target_name ) ) {
            throw new RuntimeException( __( 'The exporter did not provide a valid package filename.', 'clone-master' ) );
        }
        $source_path = WPCM_BACKUP_DIR . $filename;
        $target_path = WPCM_BACKUP_DIR . $target_name;

        if ( $source_path !== $target_path ) {
            if ( is_file( $target_path ) && ! is_file( $source_path ) ) {
                // A previous request completed the rename before persisting state.
            } elseif ( is_file( $source_path ) && ! is_file( $target_path ) ) {
                if ( ! @rename( $source_path, $target_path ) ) {
                    throw new RuntimeException( __( 'Unable to atomically label the automatic backup.', 'clone-master' ) );
                }
            } elseif ( is_file( $source_path ) && is_file( $target_path ) ) {
                throw new RuntimeException( __( 'Both source and target backup filenames exist; refusing an ambiguous publication.', 'clone-master' ) );
            } else {
                throw new RuntimeException( __( 'The published backup archive is missing.', 'clone-master' ) );
            }
        }

        if ( ! is_file( $target_path ) || ! WPCM_Reliability::path_is_within( $target_path, WPCM_BACKUP_DIR ) ) {
            throw new RuntimeException( __( 'The final archive path failed containment validation.', 'clone-master' ) );
        }

        $actual = WPCM_Reliability::checksum( $target_path );
        if ( $expected && ! hash_equals( $expected, strtolower( $actual ) ) ) {
            throw new RuntimeException( __( 'The final archive checksum no longer matches the verified package.', 'clone-master' ) );
        }

        if ( null === WPCM_Archive::footer_payload_hash( $target_path ) ) {
            throw new RuntimeException( __( 'The final WPCM container has an invalid authentication footer.', 'clone-master' ) );
        }

        WPCM_Reliability::atomic_write( $target_path . '.sha256', strtolower( $actual ) . "\n", 0640 );
        if ( $source_path !== $target_path ) {
            foreach ( array( $source_path . '.sha256', $source_path . '.sha256.bak' ) as $stale_sidecar ) {
                if ( is_file( $stale_sidecar ) && WPCM_Reliability::path_is_within( $stale_sidecar, WPCM_BACKUP_DIR ) ) {
                    wp_delete_file( $stale_sidecar );
                }
            }
        }

        $job['package']['filename']       = $target_name;
        $job['package']['sha256']         = $actual;
        $job['entry']['filename']         = $target_name;
        $job['entry']['size_bytes']       = (int) filesize( $target_path );
        $job['entry']['archive_sha256']   = $actual;
        $job['progress']                  = 97;
        return $job;
    }

    /**
     * Finish a successful job and remove only its small scheduler state.
     *
     * @param array $job Job state.
     * @return array
     */
    private function complete_job( array $job ): array {
        $this->clear_hook( self::CONTINUE_HOOK );
        $this->delete_job();
        return array(
            'status'   => 'success',
            'run_id'   => (string) $job['run_id'],
            'filename' => (string) ( $job['entry']['filename'] ?? '' ),
            'size'     => size_format( (int) ( $job['entry']['size_bytes'] ?? 0 ) ),
            'duration' => (int) ( $job['entry']['duration_sec'] ?? 0 ),
            'progress' => 100,
        );
    }

    /**
     * Record a failed job idempotently and clear its scheduler state.
     *
     * @param Throwable $error Failure.
     * @return array
     */
    private function fail_job( Throwable $error ): array {
        $job = $this->read_job();
        if ( ! is_array( $job ) ) {
            return array(
                'status'  => 'error',
                'message' => $error->getMessage(),
            );
        }

        $entry                 = is_array( $job['entry'] ?? null ) ? $job['entry'] : array();
        $entry['id']           = (string) ( $job['run_id'] ?? gmdate( 'Ymd_His' ) );
        $entry['trigger']      = (string) ( $job['trigger'] ?? 'auto' );
        $entry['status']       = 'error';
        $entry['error']        = sanitize_text_field( $error->getMessage() );
        $entry['finished_at']  = gmdate( 'Y-m-d H:i:s' );
        $entry['duration_sec'] = max( 0, time() - (int) ( $job['created_at'] ?? time() ) );
        $entry['filename']     = (string) ( $entry['filename'] ?? '' );
        $entry['size_bytes']   = (int) ( $entry['size_bytes'] ?? 0 );

        WPCM_Backup_Settings::add_history_entry( $entry );
        if ( empty( $job['failure_notification_claimed'] ) ) {
            $job['failure_notification_claimed'] = true;
            $job['entry']                        = $entry;
            $job['updated_at']                   = time();
            $this->write_job( $job );
            $this->send_notification( $entry );
        }

        $this->clear_hook( self::CONTINUE_HOOK );
        $this->delete_job();
        return array(
            'status'  => 'error',
            'run_id'  => (string) $entry['id'],
            'message' => $entry['error'],
        );
    }

    /**
     * Apply automatic-backup retention. Manual archives are never removed.
     *
     * @return void
     */
    public function apply_retention(): void {
        if ( ! is_dir( WPCM_BACKUP_DIR ) ) {
            return;
        }

        $domain_first = glob( WPCM_BACKUP_DIR . '*-backup-auto-*.wpcm' );
        $legacy       = glob( WPCM_BACKUP_DIR . 'auto_*.wpcm' );
        $files        = array_values(
            array_unique(
                array_merge(
                    is_array( $domain_first ) ? $domain_first : array(),
                    is_array( $legacy ) ? $legacy : array()
                )
            )
        );
        if ( empty( $files ) ) {
            return;
        }

        usort(
            $files,
            static function ( $a, $b ) {
                return (int) filemtime( $b ) <=> (int) filemtime( $a );
            }
        );

        if ( 'count' === $this->settings->retention_mode ) {
            $to_delete = array_slice( $files, max( 1, (int) $this->settings->retention_count ) );
        } elseif ( 'days' === $this->settings->retention_mode ) {
            $cutoff    = time() - ( max( 1, (int) $this->settings->retention_days ) * DAY_IN_SECONDS );
            $to_delete = array_filter(
                $files,
                static function ( $file ) use ( $cutoff ) {
                    return filemtime( $file ) < $cutoff;
                }
            );
        } else {
            return;
        }

        foreach ( $to_delete as $file ) {
            if ( is_file( $file ) && WPCM_Reliability::path_is_within( $file, WPCM_BACKUP_DIR ) ) {
                wp_delete_file( $file );
                foreach ( array( $file . '.sha256', $file . '.sha256.bak' ) as $sidecar ) {
                    if ( is_file( $sidecar ) && WPCM_Reliability::path_is_within( $sidecar, WPCM_BACKUP_DIR ) ) {
                        wp_delete_file( $sidecar );
                    }
                }
            }
        }
    }

    /**
     * Send the configured completion email.
     *
     * @param array $entry History entry.
     * @return void
     */
    public function send_notification( array $entry ): void {
        $notify_on = $this->settings->notify_on;
        if ( 'never' === $notify_on || ( 'error' === $notify_on && 'error' !== $entry['status'] ) ) {
            return;
        }

        $recipient = $this->settings->notify_email ?: get_option( 'admin_email' );
        $site      = get_bloginfo( 'name' );
        $trigger   = 'auto' === $entry['trigger'] ? __( 'automatic', 'clone-master' ) : __( 'manual', 'clone-master' );

        if ( 'success' === $entry['status'] ) {
            $subject = sprintf(
                /* translators: 1: site name, 2: backup type. */
                __( '[%1$s] %2$s backup successful', 'clone-master' ),
                $site,
                $trigger
            );
            $body = sprintf(
                /* translators: 1: backup type, 2: site name, 3: filename, 4: size, 5: duration, 6: started, 7: finished. */
                __( "Good news!\n\nThe %1\$s backup of \"%2\$s\" completed successfully.\n\nFile: %3\$s\nSize: %4\$s\nDuration: %5\$d seconds\nStarted: %6\$s\nFinished: %7\$s\n\n: Clone Master", 'clone-master' ),
                $trigger,
                $site,
                (string) $entry['filename'],
                size_format( (int) $entry['size_bytes'] ),
                (int) $entry['duration_sec'],
                (string) $entry['started_at'],
                (string) $entry['finished_at']
            );
        } else {
            $subject = sprintf(
                /* translators: 1: site name, 2: backup type. */
                __( '[%1$s] %2$s backup FAILED', 'clone-master' ),
                $site,
                $trigger
            );
            $body = sprintf(
                /* translators: 1: backup type, 2: site name, 3: error, 4: started, 5: finished. */
                __( "The %1\$s backup of \"%2\$s\" failed.\n\nError: %3\$s\nStarted: %4\$s\nFinished: %5\$s\n\n: Clone Master", 'clone-master' ),
                $trigger,
                $site,
                (string) ( $entry['error'] ?? __( 'Unknown error', 'clone-master' ) ),
                (string) ( $entry['started_at'] ?? '' ),
                (string) ( $entry['finished_at'] ?? '' )
            );
        }

        wp_mail( $recipient, $subject, $body );
    }

    /**
     * Reload settings after an administrator save.
     *
     * @return void
     */
    public function reload_settings(): void {
        $this->settings = new WPCM_Backup_Settings();
    }

    /**
     * Return current settings.
     *
     * @return WPCM_Backup_Settings
     */
    public function get_settings(): WPCM_Backup_Settings {
        return $this->settings;
    }

    /**
     * Persist the job state atomically with HMAC authentication.
     *
     * @param array $job Job state.
     * @return void
     */
    private function write_job( array $job ): void {
        WPCM_Reliability::atomic_signed_json( $this->job_path(), $job, self::JOB_CONTEXT );
    }

    /**
     * Read the job state with authenticated backup-file fallback.
     *
     * @return array|null
     */
    private function read_job(): ?array {
        $job = WPCM_Reliability::read_signed_json( $this->job_path(), self::JOB_CONTEXT );
        return is_array( $job ) ? $job : null;
    }

    /**
     * Remove scheduler state after terminal completion.
     *
     * @return void
     */
    private function delete_job(): void {
        foreach ( array( $this->job_path(), $this->job_path() . '.bak' ) as $path ) {
            if ( is_file( $path ) ) {
                wp_delete_file( $path );
            }
        }
    }

    /**
     * Schedule a continuation unless one is already pending.
     *
     * @param int $delay Delay in seconds.
     * @return void
     */
    private function schedule_continuation( int $delay = 30 ): void {
        if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
            wp_schedule_single_event( time() + max( 1, $delay ), self::CONTINUE_HOOK );
        }
    }

    /**
     * Clear every event for a hook, including duplicate scheduled rows.
     *
     * @param string $hook Hook name.
     * @return void
     */
    private function clear_hook( string $hook ): void {
        wp_clear_scheduled_hook( $hook );
    }

    /** @return string */
    private function job_path(): string {
        return WPCM_TEMP_DIR . 'scheduled-backup-state.json';
    }

    /** @return string */
    private function job_lock_path(): string {
        return WPCM_TEMP_DIR . 'scheduled-backup-state.lock';
    }
}
