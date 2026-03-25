<?php
/**
 * Importer — Generates and manages a standalone installer
 *
 * APPROACH (same as Duplicator/All-in-One WP Migration):
 * Instead of importing through WordPress AJAX (which breaks when we replace
 * the database), we generate a STANDALONE PHP script that:
 * 1. Runs independently of WordPress (no WP bootstrap)
 * 2. Has its own authentication (secret token)
 * 3. Handles DB import, file extraction, URL replacement
 * 4. Deletes itself when done
 *
 * The WordPress plugin handles:
 * - Upload of the archive
 * - Extraction + manifest reading
 * - Generation of the standalone installer
 * - Redirecting the JS to talk to the installer directly
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPCM_Importer {

    /**
     * Called from WP AJAX — only handles upload, extract, and installer generation.
     * The actual import is done by the standalone installer.
     */
    public function run_step( $step, $session_id, $file_path = '', $new_url = '', $import_opts = '' ) {
        switch ( $step ) {
            case 'extract': return $this->step_extract( $file_path );
            case 'prepare': return $this->step_prepare( $session_id, $new_url, $import_opts );
            default:        throw new Exception( __( 'Unknown step: ', 'clone-master' ) . $step ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }
    }

    /**
     * Step 1: Extract archive and read manifest
     */
    private function step_extract( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            throw new Exception( __( 'Archive file not found', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        $session_id  = 'import_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 16, false );
        $session_dir = WPCM_TEMP_DIR . $session_id . '/';
        wp_mkdir_p( $session_dir );
        // Protect immediately — the parent wpcm-temp/.htaccess may not propagate
        // to subdirs on hosts with AllowOverride None.
        WPCM_Plugin::protect_directory( $session_dir );

        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            throw new Exception( __( 'Cannot open ZIP archive', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        // ── ZIP Slip guard ────────────────────────────────────────────────────
        // ZipArchive::extractTo() does NOT prevent entries containing '../' from
        // writing outside $session_dir. A crafted archive could place a PHP file
        // anywhere on the server. Validate every entry before extraction.
        $real_session = realpath( $session_dir );
        if ( ! $real_session ) {
            $zip->close();
            throw new Exception( __( 'Cannot resolve session directory path.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = $zip->getNameIndex( $i );
            // Resolve the prospective destination path; use a fake root so
            // realpath() does not require the path to exist yet.
            $dest_path = $real_session . '/' . $entry_name;
            // Normalise manually: collapse every '..' segment
            $parts  = [];
            foreach ( explode( '/', str_replace( '\\', '/', $dest_path ) ) as $part ) {
                if ( $part === '..' ) {
                    array_pop( $parts );
                } elseif ( $part !== '.' && $part !== '' ) {
                    $parts[] = $part;
                }
            }
            $normalised = '/' . implode( '/', $parts );
            if ( strpos( $normalised, $real_session ) !== 0 ) {
                $zip->close();
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                // Reason: Exception is caught internally by the caller and its message is
                // never sent directly to the browser. The __() return value does not need
                // to be escaped here; output escaping happens at render time, not at the
                // point of string construction. Using esc_html() inside the Exception
                // message would corrupt the string if it were later logged to a file.
                throw new Exception( sprintf(
                    /* translators: %1$s: ZIP entry name */
                    __( 'Invalid archive: path traversal detected in entry "%1$s".', 'clone-master' ),
                    esc_html( $entry_name )
                ) );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $zip->extractTo( $session_dir );
        $zip->close();

        // Find manifest
        $manifest_path = $this->find_manifest( $session_dir );
        if ( ! $manifest_path ) {
            throw new Exception( __( 'Invalid archive: manifest.json not found', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }
        $manifest = json_decode( file_get_contents( $manifest_path ), true );

        return [
            'session_id' => $session_id,
            'manifest'   => [
                'site_url'       => $manifest['site_url'] ?? 'Unknown',
                'wp_version'     => $manifest['wp_version'] ?? 'Unknown',
                'created_at'     => $manifest['created_at'] ?? 'Unknown',
                'db_prefix'      => $manifest['db_prefix'] ?? 'wp_',
                'tables_count'   => count( $manifest['tables'] ?? [] ),
                'active_plugins' => $manifest['active_plugins'] ?? [],
                'active_theme'   => $manifest['active_theme'] ?? 'Unknown',
            ],
            'next_step' => 'prepare',
            'progress'  => 15,
            'message'   => 'Archive extracted. Backup from: ' . ( $manifest['site_url'] ?? 'Unknown' ),
        ];
    }

    /**
     * Step 2: Write installer_config.json and return the URL of the static installer.
     *
     * The static installer.php lives permanently in the plugin directory (SVN-versioned).
     * This method no longer generates PHP code at runtime — it only writes a JSON config
     * file into the already-protected session directory (wpcm-temp/{session_id}/).
     *
     * Session_id is appended as ?sid= to the installer URL so no JS changes are needed.
     */
    private function step_prepare( $session_id, $new_url, $import_opts_json = '' ) {
        $session_dir = WPCM_TEMP_DIR . $session_id . '/';
        if ( ! is_dir( $session_dir ) ) {
            throw new Exception( __( 'Session not found', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        $manifest = json_decode( file_get_contents( $this->find_manifest( $session_dir ) ), true );

        // ── Client-generated token (zero-knowledge pattern) ──────────────────────
        // The browser generates a cryptographically random 256-bit token via
        // crypto.getRandomValues() and sends it in the *request* body only once.
        // We never generate it server-side and never return it in any response,
        // so it never appears in DevTools response tabs, proxy logs, or XSS leaks.
        //
        // The token serves two purposes simultaneously:
        //   1. Authentication  → bcrypt hash baked into the installer PHP file.
        //                        Even if installer.php is read from disk, the hash
        //                        cannot be reversed without the original token.
        //   2. AES-256 key     → SHA-256(token) encrypts DB credentials in
        //                        installer_config.json. Both files + the original
        //                        token are required to recover DB credentials.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // Reason: This is a standalone installer endpoint authenticated via a bcrypt
        // token (password_verify), not a standard WP form. WP nonces are user-session-
        // bound and cannot be generated before the site is fully operational, which is
        // precisely the scenario this installer handles. Security is enforced by the
        // bcrypt token check below (work factor 10, ~80 ms — brute-force infeasible).
        // Token format: 32 random bytes encoded as a 64-char lowercase hex string,
        // produced by crypto.getRandomValues() + Array.map(b => b.toString(16).padStart(2,'0')).join('').
        // We validate the exact expected format with a regex instead of sanitize_text_field():
        //   - sanitize_text_field() would be safe for hex, but it silently strips/transforms
        //     characters, which could corrupt a future token encoding change without any error.
        //   - Explicit regex makes the contract clear and fails loudly if the format drifts.
        // wp_unslash() first (WPCS MissingUnslash), then no further transformation.
        $client_token = isset( $_POST['installer_token'] )
            ? wp_unslash( $_POST['installer_token'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated immediately below via regex; sanitize_text_field() would silently corrupt the hex value
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Strict format check: exactly 64 lowercase hex characters (32 bytes from crypto.getRandomValues()).
        // Rejects empty strings, wrong lengths, non-hex chars, and any injection attempt.
        if ( ! preg_match( '/^[0-9a-f]{64}$/', $client_token ) ) {
            throw new Exception( __( 'Missing or invalid installer token (expected 64-char hex string).', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        // bcrypt hash (work factor 10, ~80 ms) — baked into installer.php.
        // password_verify() is the only way in; brute-force is computationally infeasible.
        $token_hash = password_hash( $client_token, PASSWORD_BCRYPT, [ 'cost' => 10 ] );
        if ( $token_hash === false ) {
            throw new Exception( __( 'bcrypt hashing failed — check PHP bcrypt support.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are caught internally; not output to browser
        }

        // Installer TTL: 15 minutes from now.
        // The installer auto-deletes itself if called after this timestamp.
        $expires_at = time() + ( 15 * MINUTE_IN_SECONDS );

        // Encrypt DB credentials with AES-256-CBC.
        // Key = SHA-256 of the client token (32 bytes). IV = random 16 bytes.
        // installer_config.json holds only the encrypted blob — useless without the token.
        // installer.php holds only the bcrypt hash — useless without the token.
        // An attacker must compromise both files AND intercept the token to recover creds.
        $enc_key  = hash( 'sha256', $client_token, true ); // 32-byte AES key
        // random_bytes() is the preferred CSPRNG since PHP 7.0 (reads from the OS
        // entropy pool: getrandom() on Linux, CryptGenRandom on Windows).
        // openssl_random_pseudo_bytes() is kept as a fallback for PHP < 7.0 only —
        // on some platforms its second $strong parameter can silently be false.
        $enc_iv   = function_exists( 'random_bytes' )
            ? random_bytes( 16 )
            : openssl_random_pseudo_bytes( 16 );
        $db_plain = wp_json_encode( [
            'db_host'    => DB_HOST,
            'db_name'    => DB_NAME,
            'db_user'    => DB_USER,
            'db_pass'    => DB_PASSWORD,
            'db_charset' => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
        ] );
        $db_cipher = function_exists( 'openssl_encrypt' )
            ? openssl_encrypt( $db_plain, 'AES-256-CBC', $enc_key, OPENSSL_RAW_DATA, $enc_iv )
            : false;
        // Graceful degradation: if openssl is absent, store an empty placeholder.
        // db_connect() in the installer will call die_json() when decryption fails.
        $db_credentials_enc = $db_cipher !== false
            ? base64_encode( $enc_iv . $db_cipher )
            : '';

        // Canonical admin origin for CORS whitelist (baked at generation time,
        // never reflected from the request — prevents CORS wildcard exploitation).
        $admin_origin = rtrim( admin_url(), '/' );
        // Strip path component: keep scheme + host only (e.g. https://example.com)
        $parsed_origin = wp_parse_url( $admin_origin );
        $safe_origin   = ( $parsed_origin['scheme'] ?? 'https' ) . '://' . ( $parsed_origin['host'] ?? '' );
        if ( ! empty( $parsed_origin['port'] ) ) {
            $safe_origin .= ':' . $parsed_origin['port'];
        }

        // Collect all info the installer needs.
        // token_hash, expires_at, and allowed_origin are now stored in the JSON config
        // instead of being injected into a generated PHP file. The static installer.php
        // reads these values at startup. Security is unchanged: db_credentials_enc is
        // useless without the client token, and token_hash cannot be reversed.
        $installer_config = [
            'token_hash'         => $token_hash,    // bcrypt — cannot be reversed
            'expires_at'         => $expires_at,    // Unix timestamp — 15 min TTL
            'allowed_origin'     => $safe_origin,   // hardcoded at prepare time (not reflected from request)
            'session_dir'        => $session_dir,
            'old_url'            => $manifest['site_url'] ?? '',
            'old_home'           => $manifest['home_url'] ?? '',
            'new_url'            => $new_url ?: site_url(),
            'old_path'           => $manifest['abspath'] ?? '',
            'new_path'           => ABSPATH,
            'old_prefix'         => $manifest['db_prefix'] ?? 'wp_',
            'db_credentials_enc' => $db_credentials_enc,
            'db_charset'         => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
            'table_prefix'   => $GLOBALS['wpdb']->prefix,
            'wp_content_dir' => WP_CONTENT_DIR,
            'theme_root'     => get_theme_root(),
            'plugin_dir'     => WP_PLUGIN_DIR,
            'uploads_dir'    => wp_upload_dir()['basedir'],
            'abspath'        => ABSPATH,
            // The canonical WordPress slug for this plugin, e.g. "clone-master/clone-master.php".
            // IMPORTANT: __FILE__ here would resolve to class-importer.php (the current file),
            // not the main plugin file. We must use WPCM_PLUGIN_DIR which is defined via __FILE__
            // in clone-master.php — the only place where __FILE__ gives the correct plugin root.
            'plugin_slug'    => plugin_basename( WPCM_PLUGIN_DIR . 'clone-master.php' ),
            'plugin_folder'  => basename( rtrim( WPCM_PLUGIN_DIR, '/\\' ) ),
            // User-chosen import options (locale, reset_permalinks)
            'import_opts'    => json_decode( $import_opts_json ?: '{}', true ) ?: [],
        ];

        // Save config for the static installer.
        // installer_config.json is written into the already-protected session dir.
        // installer.php (static, SVN-versioned) reads it via relative path at runtime.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Background context; WP_Filesystem requires admin credentials
        file_put_contents(
            $session_dir . 'installer_config.json',
            wp_json_encode( $installer_config, JSON_PRETTY_PRINT )
        );

        // Build the installer URL: static file in the plugin directory + session_id as query param.
        // No PHP is generated, no file is written to ABSPATH or anywhere outside wpcm-temp/.
        // The session_id in the URL is not sensitive (security is the bcrypt token in POST).
        $installer_url = plugins_url( 'installer.php', WPCM_PLUGIN_DIR . 'clone-master.php' )
                       . '?sid=' . rawurlencode( $session_id );

        // auth_token is intentionally absent from this response.
        // The browser already holds the token it generated — it never needs to read it back.
        return [
            'session_id'    => $session_id,
            'installer_url' => $installer_url,
            'next_step'     => null,
            'progress'      => 20,
            'message'       => 'Standalone installer ready. Starting import...',
        ];
    }

    /**
     * Previously: generate_installer_code()
     *
     * Removed in v1.1.0. The standalone installer is now a STATIC file
     * (installer.php) versioned in SVN alongside the plugin. All dynamic
     * values (token_hash, expires_at, allowed_origin, session paths) are
     * written to installer_config.json by step_prepare() and read by the
     * static installer at startup.
     *
     * This eliminates:
     *   - Runtime PHP code generation (HEREDOC → WP.org PCP ERROR)
     *   - Writing PHP files to ABSPATH (WP.org review blocker)
     *   - The WP.org review objection to dynamic executable code creation
     */

    private function find_manifest( $dir ) {
        if ( file_exists( $dir . 'manifest.json' ) ) return $dir . 'manifest.json';
        foreach ( glob( $dir . '*/', GLOB_ONLYDIR ) as $sub ) {
            if ( file_exists( $sub . 'manifest.json' ) ) return $sub . 'manifest.json';
        }
        return null;
    }
}
