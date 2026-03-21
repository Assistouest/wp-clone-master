<?php
/**
 * WPCM_Backup_Settings — Encapsulates all automatic backup configuration.
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

    // ── Simple symmetric encryption for the Nextcloud app-password ───────────
    // Uses AUTH_KEY + AUTH_SALT from wp-config as the key material.
    // Not military-grade, but protects against casual DB reads.

    private static function cipher_key(): string {
        return hash( 'sha256', AUTH_KEY . AUTH_SALT . 'wpcm_nc', true );
    }

    public static function encrypt( string $plain ): string {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( $plain ); // graceful degradation
        }
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $plain, 'AES-256-CBC', self::cipher_key(), OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $cipher );
    }

    public static function decrypt( string $stored ): string {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return (string) base64_decode( $stored );
        }
        $raw    = base64_decode( $stored );
        $iv     = substr( $raw, 0, 16 );
        $cipher = substr( $raw, 16 );
        $plain  = openssl_decrypt( $cipher, 'AES-256-CBC', self::cipher_key(), OPENSSL_RAW_DATA, $iv );
        return $plain !== false ? $plain : '';
    }

    // ── Persistence ──────────────────────────────────────────────────────────

    /**
     * Merge $changes into current settings and persist to the DB.
     */
    public function save( array $changes ): void {
        $allowed = array_keys( self::$defaults );

        foreach ( $changes as $k => $v ) {
            if ( ! in_array( $k, $allowed, true ) ) continue;

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
                    // Empty string = explicit wipe (disconnect). Non-empty = encrypt & store.
                    if ( (string) $v === '' ) {
                        $this->data[ $k ] = '';
                    } else {
                        $this->data[ $k ] = self::encrypt( (string) $v );
                    }
                    break;

                case 'nextcloud_keep_local':
                    $this->data[ $k ] = (bool) $v;
                    break;

                case 'nextcloud_connected':
                    $this->data[ $k ] = (bool) $v;
                    break;
            }
        }

        update_option( self::OPTION_KEY, $this->data, false );
    }

    // ── History — static helpers (no instance required) ──────────────────────

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
     *   filename     string   basename of the ZIP, or ''
     *   size_bytes   int
     *   error        string|null
     *
     * @param array $entry
     */
    public static function add_history_entry( array $entry ): void {
        $history = self::get_history();
        array_unshift( $history, $entry );
        update_option( self::HISTORY_KEY, array_slice( $history, 0, self::HISTORY_MAX ), false );
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
