<?php
/**
 * WPCM_Backup_Settings : Encapsulates all automatic backup configuration.
 *
 * Settings are stored as a single serialised array in wp_options under
 * 'wpcm_schedule_settings'. History (up to HISTORY_MAX entries, newest first)
 * is stored separately under 'wpcm_backup_history'.
 *
 * Usage:
 *   $s = new WPCM_Backup_Settings();
 *   echo $s->frequency;                       // 'daily'
 *   $s->save( [ 'frequency' => 'weekly' ] );
 *   WPCM_Backup_Settings::add_history_entry( $entry );
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPCM_Backup_Settings {

    const OPTION_KEY  = 'wpcm_schedule_settings';
    const HISTORY_KEY = 'wpcm_backup_history';
    const HISTORY_MAX = 50;

    private static $defaults = [
        'enabled'              => false,
        'frequency'            => 'daily',    // hourly | twicedaily | daily | weekly | monthly
        'retention_mode'       => 'count',    // count | days
        'retention_count'      => 7,
        'retention_days'       => 30,
        'notify_email'         => '',         // empty = uses admin_email
        'notify_on'            => 'error',    // always | error | never
        // ── Storage driver ───────────────────────────────────────────────────
        'storage_driver'       => 'local',    // local | nextcloud
        'nextcloud_url'        => '',         // https://cloud.example.com
        'nextcloud_user'       => '',
        'nextcloud_pass'       => '',         // app password (stored encrypted via WPCM_Backup_Settings::encrypt)
        'nextcloud_path'       => 'Backups/WordPress',
        'nextcloud_keep_local' => true,       // keep local copy after upload?
        'nextcloud_connected'  => false,      // set to true after successful Login Flow v2
    ];

    /** @var array Raw settings data */
    private $data;

    public function __construct() {
        $saved      = get_option( self::OPTION_KEY, [] );
        $this->data = wp_parse_args( is_array( $saved ) ? $saved : [], self::$defaults );

        if ( empty( $this->data['notify_email'] ) ) {
            $this->data['notify_email'] = get_option( 'admin_email', '' );
        }
    }

    // ── Magic getters ────────────────────────────────────────────────────────

    public function __get( $key ) {
        // Auto-decrypt the password on read
        if ( $key === 'nextcloud_pass' && ! empty( $this->data['nextcloud_pass'] ) ) {
            return self::decrypt( $this->data['nextcloud_pass'] );
        }
        return $this->data[ $key ] ?? null;
    }

    public function __isset( $key ) {
        return isset( $this->data[ $key ] );
    }

    public function to_array(): array {
        $out = $this->data;
        // Never expose the raw encrypted password to the frontend
        $out['nextcloud_pass']      = ! empty( $this->data['nextcloud_pass'] ) ? '__stored__' : '';
        $out['nextcloud_connected'] = (bool) ( $this->data['nextcloud_connected'] ?? false );
        return $out;
    }

    // ── Authenticated encryption for the Nextcloud app-password ────────────
    // New values use AES-256-GCM and include an authentication tag so corrupted
    // or modified ciphertext is rejected. Legacy AES-256-CBC values remain
    // readable and are transparently re-encrypted the next time settings save.

    private static function cipher_key(): string {
        return hash( 'sha256', AUTH_KEY . AUTH_SALT . 'wpcm_nc', true );
    }

    public static function encrypt( string $plain ): string {
        if ( ! function_exists( 'openssl_encrypt' ) || '' === $plain ) {
            return '';
        }

        $iv  = random_bytes( 12 ); // 96-bit nonce recommended for GCM.
        $tag = '';
        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            self::cipher_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'clone-master-nextcloud',
            16
        );

        if ( false === $cipher || 16 !== strlen( $tag ) ) {
            return '';
        }

        return 'gcm:' . base64_encode( $iv . $tag . $cipher );
    }

    public static function decrypt( string $stored ): string {
        if ( ! function_exists( 'openssl_decrypt' ) || '' === $stored ) {
            return '';
        }

        if ( 0 === strpos( $stored, 'gcm:' ) ) {
            $raw = base64_decode( substr( $stored, 4 ), true );
            if ( false === $raw || strlen( $raw ) <= 28 ) {
                return '';
            }

            $iv     = substr( $raw, 0, 12 );
            $tag    = substr( $raw, 12, 16 );
            $cipher = substr( $raw, 28 );
            $plain  = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                self::cipher_key(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                'clone-master-nextcloud'
            );

            return false !== $plain ? $plain : '';
        }

        // Backward compatibility with values written by Clone Master 1.x.
        $raw = base64_decode( $stored, true );
        if ( false === $raw || strlen( $raw ) <= 16 ) {
            return '';
        }

        $iv     = substr( $raw, 0, 16 );
        $cipher = substr( $raw, 16 );
        $plain  = openssl_decrypt( $cipher, 'AES-256-CBC', self::cipher_key(), OPENSSL_RAW_DATA, $iv );
        return false !== $plain ? $plain : '';
    }

    // ── Persistence ──────────────────────────────────────────────────────────

    /**
     * Build a validated in-memory settings object without changing wp_options.
     *
     * @param array $changes Temporary overrides.
     * @return self
     */
    public static function temporary( array $changes ): self {
        $instance = new self();
        $instance->apply_changes( $changes );
        return $instance;
    }

    /**
     * Merge changes into current settings and persist them.
     *
     * @param array $changes Settings changes.
     * @return void
     */
    public function save( array $changes ): void {
        $this->apply_changes( $changes );
        update_option( self::OPTION_KEY, $this->data, false );
    }

    /**
     * Validate and apply settings changes to this object.
     *
     * @param array $changes Settings changes.
     * @return void
     */
    private function apply_changes( array $changes ): void {
        $allowed = array_keys( self::$defaults );

        foreach ( $changes as $k => $v ) {
            if ( ! in_array( $k, $allowed, true ) ) {
                continue;
            }

            switch ( $k ) {
                case 'enabled':
                    $this->data[ $k ] = (bool) $v;
                    break;

                case 'retention_count':
                case 'retention_days':
                    $this->data[ $k ] = max( 1, (int) $v );
                    break;

                case 'frequency':
                    $valid = [ 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly' ];
                    if ( in_array( $v, $valid, true ) ) {
                        $this->data[ $k ] = $v;
                    }
                    break;

                case 'retention_mode':
                    if ( in_array( $v, [ 'count', 'days' ], true ) ) {
                        $this->data[ $k ] = $v;
                    }
                    break;

                case 'notify_on':
                    if ( in_array( $v, [ 'always', 'error', 'never' ], true ) ) {
                        $this->data[ $k ] = $v;
                    }
                    break;

                case 'notify_email':
                    $this->data[ $k ] = sanitize_email( (string) $v );
                    break;

                case 'storage_driver':
                    if ( in_array( $v, [ 'local', 'nextcloud' ], true ) ) {
                        $this->data[ $k ] = $v;
                    }
                    break;

                case 'nextcloud_url':
                    $this->data[ $k ] = esc_url_raw( (string) $v );
                    break;

                case 'nextcloud_user':
                case 'nextcloud_path':
                    $this->data[ $k ] = sanitize_text_field( (string) $v );
                    break;

                case 'nextcloud_pass':
                    // Empty string explicitly clears the stored credential.
                    $this->data[ $k ] = '' === (string) $v ? '' : self::encrypt( (string) $v );
                    break;

                case 'nextcloud_keep_local':
                case 'nextcloud_connected':
                    $this->data[ $k ] = (bool) $v;
                    break;
            }
        }
    }

    // ── History : static helpers (no instance required) ──────────────────────

    /**
     * Prepend a new history entry (newest first) and cap at HISTORY_MAX.
     *
     * Expected entry keys:
     *   id           string   Ymd_His unique run ID
     *   trigger      string   'auto' | 'manual'
     *   started_at   string   'Y-m-d H:i:s'
     *   finished_at  string   'Y-m-d H:i:s'
     *   duration_sec int
     *   status       string   'success' | 'error'
     *   filename     string   basename of the WPCM container, or ''
     *   size_bytes   int
     *   error        string|null
     *
     * @param array $entry
     */
    public static function add_history_entry( array $entry ): void {
        $lock = WPCM_Reliability::acquire_lock( WPCM_TEMP_DIR . 'backup-history.lock' );
        if ( false === $lock ) {
            throw new RuntimeException( __( 'Unable to acquire the backup history lock.', 'clone-master' ) );
        }

        try {
            $history = self::get_history();
            $id      = (string) ( $entry['id'] ?? '' );
            if ( '' !== $id ) {
                $history = array_values( array_filter( $history, static function ( $existing ) use ( $id ) {
                    return ! is_array( $existing ) || (string) ( $existing['id'] ?? '' ) !== $id;
                } ) );
            }
            array_unshift( $history, $entry );
            update_option( self::HISTORY_KEY, array_slice( $history, 0, self::HISTORY_MAX ), false );
        } finally {
            WPCM_Reliability::release_lock( $lock );
        }
    }

    /**
     * Returns all history entries, newest first.
     *
     * @return array
     */
    public static function get_history(): array {
        $h = get_option( self::HISTORY_KEY, [] );
        return is_array( $h ) ? $h : [];
    }

    /**
     * Clears the entire history log.
     */
    public static function clear_history(): void {
        delete_option( self::HISTORY_KEY );
    }
}
