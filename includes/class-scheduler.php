<?php
/**
 * WPCM_Scheduler — Automatic backup engine.
 *
 * Responsibilities:
 *   • Register custom WP-Cron intervals (weekly, monthly)
 *   • Schedule / reschedule / cancel the recurring cron event
 *   • Execute a full backup synchronously when the hook fires
 *   • Apply retention policy (count-based or age-based) after every backup
 *   • Write a structured history log via WPCM_Backup_Settings
 *   • Send email notifications on success / failure
 *
 * Automatic backups produce ZIPs prefixed with "auto_" so that retention
 * never touches manually-created backups.
 *
 * Anti-concurrency: a transient lock ('wpcm_backup_lock') prevents two
 * overlapping runs (e.g. if WP-Cron fires twice quickly).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPCM_Scheduler {

    const CRON_HOOK = 'wpcm_scheduled_backup';
    const LOCK_KEY  = 'wpcm_backup_lock';
    const LOCK_TTL  = 3600; // 1 hour max lock lifetime

    /** @var WPCM_Backup_Settings */
    private $settings;

    public function __construct() {
        $this->settings = new WPCM_Backup_Settings();
    }

    // =========================================================================
    // Cron interval registration
    // =========================================================================

    /**
     * Hooked on 'cron_schedules'. Adds 'weekly' and 'monthly'.
     */
    public function register_intervals( array $schedules ): array {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'wp-clone-master' ),
            ];
        }
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'wp-clone-master' ),
            ];
        }
        return $schedules;
    }

    // =========================================================================
    // Schedule management
    // =========================================================================

    /**
     * (Re)schedules the cron event according to current settings.
     * Safe to call multiple times — clears the old event first.
     */
    public function schedule_backup(): void {
        $this->cancel_backup();

        if ( ! $this->settings->enabled ) {
            return;
        }

        $frequency = $this->settings->frequency;

        // Validate interval is registered
        $schedules = wp_get_schedules();
        if ( ! isset( $schedules[ $frequency ] ) ) {
            $frequency = 'daily';
        }

        // Start time: next round hour in the future
        $start = strtotime( 'next hour' );

        wp_schedule_event( $start, $frequency, self::CRON_HOOK );
    }

    /**
     * Cancels all pending scheduled events for this hook.
     */
    public function cancel_backup(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
        }
    }

    /**
     * Returns the Unix timestamp of the next scheduled run, or null.
     */
    public function get_next_run(): ?int {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        return $ts ? (int) $ts : null;
    }

    // =========================================================================
    // Backup execution
    // =========================================================================

    /**
     * Main cron callback — hooked on 'wpcm_scheduled_backup'.
     * Also callable directly for manual "run now" requests.
     *
     * @param string $trigger 'auto' | 'manual'
     */
    public function run_backup( string $trigger = 'auto' ): array {
        // ── Anti-concurrency lock ────────────────────────────────────────────
        if ( get_transient( self::LOCK_KEY ) ) {
            return [
                'status'  => 'skipped',
                'message' => 'A backup is already running. Try again later.',
            ];
        }
        set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

        // ── Increase resource limits ─────────────────────────────────────────
        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );

        $run_id     = date( 'Ymd_His' );
        $started_at = date( 'Y-m-d H:i:s' );
        $start_ts   = microtime( true );

        $entry = [
            'id'           => $run_id,
            'trigger'      => $trigger,
            'started_at'   => $started_at,
            'finished_at'  => '',
            'duration_sec' => 0,
            'status'       => 'error',
            'filename'     => '',
            'size_bytes'   => 0,
            'error'        => null,
        ];

        try {
            // ── Run export steps ─────────────────────────────────────────────
            $exporter   = new WPCM_Exporter();
            $init       = $exporter->run_step( 'init' );
            $session_id = $init['session_id'];

            $exporter->run_step( 'database',    $session_id );
            $exporter->run_step( 'files_scan',  $session_id );

            // files_archive loops until the queue is exhausted
            do {
                $fa = $exporter->run_step( 'files_archive', $session_id );
            } while ( isset( $fa['next_step'] ) && $fa['next_step'] === 'files_archive' );

            $exporter->run_step( 'config',  $session_id );
            $package = $exporter->run_step( 'package', $session_id );
            $exporter->run_step( 'cleanup', $session_id );

            // ── Rename with 'auto_' prefix so retention can identify it ──────
            $original_path = $package['path'];
            $original_name = basename( $original_path );

            if ( $trigger === 'auto' && strpos( $original_name, 'auto_' ) !== 0 ) {
                $auto_name = 'auto_' . $original_name;
                $auto_path = WPCM_BACKUP_DIR . $auto_name;
                if ( rename( $original_path, $auto_path ) ) {
                    $original_path = $auto_path;
                    $original_name = $auto_name;
                }
            }

            $size_bytes = file_exists( $original_path ) ? filesize( $original_path ) : 0;

            $entry['status']       = 'success';
            $entry['filename']     = $original_name;
            $entry['size_bytes']   = $size_bytes;
            $entry['finished_at']  = date( 'Y-m-d H:i:s' );
            $entry['duration_sec'] = (int) ( microtime( true ) - $start_ts );

            // ── Storage driver upload ─────────────────────────────────────────
            $driver        = WPCM_Storage_Driver::make( $this->settings );
            $upload_result = $driver->upload( $original_path, $original_name );

            $entry['storage_driver']  = $driver->label();
            $entry['storage_ok']      = $upload_result['ok'];
            $entry['storage_message'] = $upload_result['message'];
            $entry['remote_path']     = $upload_result['remote_path'] ?? '';

            // If upload failed, flag it (but don't abort — local copy is safe)
            if ( ! $upload_result['ok'] ) {
                $entry['storage_error'] = $upload_result['message'];
            }

            // ── Retention ────────────────────────────────────────────────────
            $this->apply_retention();

            // ── Notification ─────────────────────────────────────────────────
            $this->send_notification( $entry );

            delete_transient( self::LOCK_KEY );

            return [
                'status'   => 'success',
                'filename' => $original_name,
                'size'     => size_format( $size_bytes ),
                'duration' => $entry['duration_sec'],
            ];

        } catch ( \Throwable $e ) {
            $entry['status']       = 'error';
            $entry['error']        = $e->getMessage();
            $entry['finished_at']  = date( 'Y-m-d H:i:s' );
            $entry['duration_sec'] = (int) ( microtime( true ) - $start_ts );

            $this->send_notification( $entry );
            delete_transient( self::LOCK_KEY );

            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];

        } finally {
            // Always write history, even if send_notification threw
            WPCM_Backup_Settings::add_history_entry( $entry );
        }
    }

    // =========================================================================
    // Retention
    // =========================================================================

    /**
     * Deletes old automatic backups (auto_*.zip) according to the configured
     * retention policy. Manual backups are never touched.
     */
    public function apply_retention(): void {
        if ( ! is_dir( WPCM_BACKUP_DIR ) ) return;

        $auto_files = glob( WPCM_BACKUP_DIR . 'auto_*.zip' );
        if ( empty( $auto_files ) ) return;

        // Sort by modification time, oldest last
        usort( $auto_files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );

        $s    = $this->settings;
        $mode = $s->retention_mode;

        if ( $mode === 'count' ) {
            $keep = max( 1, (int) $s->retention_count );
            $to_delete = array_slice( $auto_files, $keep );

        } elseif ( $mode === 'days' ) {
            $days     = max( 1, (int) $s->retention_days );
            $cutoff   = time() - ( $days * DAY_IN_SECONDS );
            $to_delete = array_filter( $auto_files, fn( $f ) => filemtime( $f ) < $cutoff );

        } else {
            return;
        }

        $real_base = realpath( WPCM_BACKUP_DIR );
        foreach ( $to_delete as $file ) {
            $real = realpath( $file );
            // Security: ensure the file is strictly inside the backup dir
            if ( $real && strpos( $real, $real_base ) === 0 ) {
                @unlink( $file );
            }
        }
    }

    // =========================================================================
    // Notifications
    // =========================================================================

    /**
     * Sends an email notification based on the 'notify_on' setting.
     *
     * @param array $entry History entry (see WPCM_Backup_Settings::add_history_entry)
     */
    public function send_notification( array $entry ): void {
        $notify_on = $this->settings->notify_on;

        if ( $notify_on === 'never' ) return;
        if ( $notify_on === 'error' && $entry['status'] !== 'error' ) return;

        $to      = $this->settings->notify_email ?: get_option( 'admin_email' );
        $site    = get_bloginfo( 'name' );
        $trigger = $entry['trigger'] === 'auto' ? 'automatique' : 'manuelle';

        if ( $entry['status'] === 'success' ) {
            $subject = sprintf( '[%s] Sauvegarde %s réussie', $site, $trigger );
            $body    = sprintf(
                "Bonne nouvelle !\n\nLa sauvegarde %s de \"%s\" s'est terminée avec succès.\n\n" .
                "  Fichier   : %s\n" .
                "  Taille    : %s\n" .
                "  Durée     : %d secondes\n" .
                "  Démarrée  : %s\n" .
                "  Terminée  : %s\n\n" .
                "Vous pouvez télécharger ou gérer vos sauvegardes depuis l'interface WordPress.\n\n" .
                "— WP Clone Master",
                $trigger,
                $site,
                $entry['filename'],
                size_format( (int) $entry['size_bytes'] ),
                (int) $entry['duration_sec'],
                $entry['started_at'],
                $entry['finished_at']
            );
        } else {
            $subject = sprintf( '[%s] ÉCHEC sauvegarde %s', $site, $trigger );
            $body    = sprintf(
                "La sauvegarde %s de \"%s\" a échoué.\n\n" .
                "  Erreur    : %s\n" .
                "  Démarrée  : %s\n" .
                "  Terminée  : %s\n\n" .
                "Vérifiez les journaux WP-Cron et les droits d'écriture sur le répertoire de sauvegardes.\n\n" .
                "— WP Clone Master",
                $trigger,
                $site,
                $entry['error'] ?? 'Erreur inconnue',
                $entry['started_at'],
                $entry['finished_at']
            );
        }

        wp_mail( $to, $subject, $body );
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Reloads settings from DB — useful after a save() call.
     */
    public function reload_settings(): void {
        $this->settings = new WPCM_Backup_Settings();
    }

    public function get_settings(): WPCM_Backup_Settings {
        return $this->settings;
    }
}
