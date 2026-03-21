<?php
/**
 * WPCM_Date — Centralised date/time helpers using the WordPress timezone.
 *
 * Rule: every date/time value visible to the user (filenames, history logs,
 * SQL headers, admin UI) must be expressed in the site's configured timezone,
 * never in the PHP server timezone or raw UTC.
 *
 * Rule: every timestamp used internally for scheduling or comparisons (WP-Cron,
 * filemtime, retention cutoff) stays as a genuine UTC Unix timestamp.
 *
 * WordPress provides wp_date() since 5.6 (required by this plugin) and
 * wp_timezone() since 5.3 — both are safe to call here.
 *
 * All methods are static so callers need no instance.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPCM_Date {

    // ── Formatted strings (WP timezone) ─────────────────────────────────────

    /**
     * Current date+time as "Y-m-d H:i:s" in the site's timezone.
     * Use for: history log entries, manifest timestamps, SQL headers.
     */
    public static function now_str(): string {
        return wp_date( 'Y-m-d H:i:s' );
    }

    /**
     * Current date+time as "Ymd_His" (no separators) in the site's timezone.
     * Use for: session IDs, backup filenames, run IDs.
     */
    public static function now_id(): string {
        return wp_date( 'Ymd_His' );
    }

    /**
     * Format any UTC timestamp as "Y-m-d H:i:s" in the site's timezone.
     * Use for: displaying filemtime(), wp_next_scheduled(), or any stored UTC ts.
     *
     * @param  int    $utc_timestamp  Unix timestamp (UTC).
     * @param  string $format         Date format (default Y-m-d H:i:s).
     * @return string
     */
    public static function format( int $utc_timestamp, string $format = 'Y-m-d H:i:s' ): string {
        return wp_date( $format, $utc_timestamp );
    }

    /**
     * Format a file's modification time using the site's timezone.
     * Safe wrapper around filemtime() + wp_date().
     *
     * @param  string $path  Absolute path to the file.
     * @return string  "Y-m-d H:i:s" in WP timezone, or '—' if file missing.
     */
    public static function filemtime_str( string $path ): string {
        $ts = @filemtime( $path );
        return $ts !== false ? wp_date( 'Y-m-d H:i:s', $ts ) : '—';
    }

    // ── UTC timestamps (for WP-Cron / retention math) ────────────────────────

    /**
     * Next round hour in the site's timezone, returned as a UTC Unix timestamp
     * suitable for wp_schedule_event().
     *
     * Example: if WP timezone is Europe/Paris and it is 14:37 local time,
     * this returns the UTC timestamp corresponding to 15:00 Paris time.
     *
     * @return int  UTC Unix timestamp.
     */
    public static function next_round_hour_utc(): int {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );

        // Add 1 hour then zero the minutes/seconds → next round hour
        $next = $now
            ->modify( '+1 hour' )
            ->setTime( (int) $now->format( 'H' ) + 1, 0, 0 );

        // getTimestamp() always returns UTC epoch regardless of the object's timezone
        return $next->getTimestamp();
    }

    /**
     * Current UTC Unix timestamp (identical to time(), kept here for clarity).
     * Use for: retention cutoff comparisons against filemtime().
     *
     * @return int
     */
    public static function utc_now(): int {
        return time();
    }

    // ── Parsing ──────────────────────────────────────────────────────────────

    /**
     * Parse a "Y-m-d H:i:s" string produced by now_str() back to a UTC timestamp.
     * Uses the site's timezone for the conversion.
     *
     * @param  string $wp_datetime  "Y-m-d H:i:s" in WP timezone.
     * @return int    UTC Unix timestamp, or 0 on failure.
     */
    public static function parse( string $wp_datetime ): int {
        try {
            $dt = new DateTimeImmutable( $wp_datetime, wp_timezone() );
            return $dt->getTimestamp();
        } catch ( \Exception $e ) {
            return 0;
        }
    }

    // ── Admin display helper ─────────────────────────────────────────────────

    /**
     * Human-readable label for a UTC timestamp, using WP date/time formats.
     * Falls back to "D d M Y à H:i" if the site formats are not set.
     *
     * @param  int  $utc_timestamp
     * @return string
     */
    public static function human( int $utc_timestamp ): string {
        $date_fmt = get_option( 'date_format', 'Y-m-d' );
        $time_fmt = get_option( 'time_format', 'H:i' );
        return wp_date( $date_fmt . ' ' . $time_fmt, $utc_timestamp );
    }

    /**
     * Convenience: format wp_next_scheduled() timestamp for display.
     * Returns null if no event is scheduled.
     *
     * @param  int|false $ts  Return value of wp_next_scheduled().
     * @return string|null
     */
    public static function next_run_label( $ts ): ?string {
        return $ts ? self::human( (int) $ts ) : null;
    }
}
