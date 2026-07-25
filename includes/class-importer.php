<?php
/**
 * Importer preparation and archive validation.
 *
 * The destructive restore work is delegated to the static standalone installer,
 * but no installer is activated until the uploaded archive has passed structural
 * and cryptographic integrity checks.
 *
 * @package Clone_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPCM_Importer {

    /**
     * Run an importer preparation step.
     *
     * @param string $step             Step name.
     * @param string $session_id       Existing session ID.
     * @param string $file_path        Uploaded or local backup path.
     * @param string $new_url          Destination URL.
     * @param string $import_opts_json JSON options.
     * @return array
     * @throws Exception On validation or preparation failure.
     */
    public function run_step( $step, $session_id, $file_path = '', $new_url = '', $import_opts_json = '' ) {
        switch ( $step ) {
            case 'extract':
                return $this->step_extract( $file_path );
            case 'prepare':
                return $this->step_prepare( $session_id, $new_url, $import_opts_json );
            default:
                throw new Exception( __( 'Unknown import step.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    /**
     * Safely extract and validate a backup archive.
     *
     * @param string $file_path Archive path.
     * @return array
     * @throws Exception On invalid or unsafe archives.
     */
    private function step_extract( $file_path ) {
        if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
            throw new Exception( __( 'WPCM archive not found or unreadable.', 'clone-master' ) );
        }
        if ( 'wpcm' !== strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) ) {
            throw new Exception( __( 'Only .wpcm backup containers are accepted.', 'clone-master' ) );
        }

        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $session_id  = 'import_' . gmdate( 'Ymd_His' ) . '_' . strtolower( wp_generate_password( 24, false, false ) );
            $session_dir = WPCM_TEMP_DIR . $session_id . '/';
            if ( ! file_exists( $session_dir ) && ! is_link( $session_dir ) ) break;
        }
        if ( file_exists( $session_dir ) || is_link( $session_dir ) || ! wp_mkdir_p( $session_dir ) ) {
            throw new Exception( __( 'Unable to allocate a protected import workspace.', 'clone-master' ) );
        }
        WPCM_Plugin::protect_directory( $session_dir, false );

        $lock = WPCM_Reliability::acquire_lock( $session_dir . '.extract.lock' );
        if ( false === $lock ) {
            throw new Exception( __( 'The WPCM container is already being analysed.', 'clone-master' ) );
        }
        try {
            $inspection = WPCM_Archive::inspect( $file_path, $session_dir );
            $manifest   = $this->normalize_wpcm_manifest( $inspection['manifest'], $session_dir );
            $manifest_path = $session_dir . 'manifest.json';
            WPCM_Reliability::atomic_json( $manifest_path, $manifest );
            $this->validate_manifest( $manifest, $session_dir );

            WPCM_Reliability::atomic_signed_json(
                $session_dir . 'archive-validation.json',
                array(
                    'validated_at'               => gmdate( 'c' ),
                    'archive'                    => basename( $file_path ),
                    'archive_sha256'             => (string) $inspection['file_sha256'],
                    'payload_sha256'             => (string) $inspection['sha256'],
                    'database_sha256'            => (string) $manifest['sha256_database'],
                    'files_count'                => (int) $manifest['files_count'],
                    'files_size'                 => (int) $manifest['files_size'],
                    'normalized_manifest_sha256' => WPCM_Reliability::checksum( $manifest_path ),
                    'format'                     => 'wpcm-append-only',
                    'schema_version'             => '2.0',
                ),
                'import-validation:' . $session_id
            );

            return array(
                'session_id' => $session_id,
                'manifest'   => array(
                    'site_url'       => $manifest['site_url'],
                    'wp_version'     => $manifest['wp_version'] ?? 'Unknown',
                    'created_at'     => $manifest['created_at'] ?? 'Unknown',
                    'db_prefix'      => $manifest['db_prefix'],
                    'tables_count'   => count( $manifest['tables'] ),
                    'active_plugins' => $manifest['active_plugins'],
                    'active_theme'   => $manifest['active_theme'],
                    'integrity'      => 'verified-wpcm',
                ),
                'next_step' => 'prepare',
                'progress'  => 15,
                'message'   => __( 'WPCM container fully validated block by block and extracted atomically.', 'clone-master' ),
            );
        } catch ( Throwable $error ) {
            $this->recursive_delete( $session_dir );
            throw $error;
        } finally {
            WPCM_Reliability::release_lock( $lock );
        }
    }

    /**
     * Serialize preparation requests for one validated import session.
     *
     * @param string $session_id       Import session ID.
     * @param string $new_url          Destination URL.
     * @param string $import_opts_json JSON options.
     * @return array
     * @throws Exception On lock or preparation failure.
     */
    private function step_prepare( $session_id, $new_url, $import_opts_json = '' ) {
        if ( ! preg_match( '/^[a-zA-Z0-9_]{1,100}$/', $session_id ) ) {
            throw new Exception( __( 'Invalid import session.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $session_dir = WPCM_TEMP_DIR . $session_id . '/';
        $lock        = WPCM_Reliability::acquire_lock( $session_dir . 'prepare.lock' );
        if ( false === $lock ) {
            throw new Exception( __( 'Another request is preparing this restore session.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        try {
            return $this->step_prepare_locked( $session_id, $new_url, $import_opts_json );
        } finally {
            WPCM_Reliability::release_lock( $lock );
        }
    }

    /**
     * Prepare the authenticated standalone installer.
     *
     * @param string $session_id       Import session ID.
     * @param string $new_url          Destination URL.
     * @param string $import_opts_json JSON options.
     * @return array
     * @throws Exception On preparation failure.
     */
    private function step_prepare_locked( $session_id, $new_url, $import_opts_json = '' ) {
        global $wpdb;

        if ( ! preg_match( '/^[a-zA-Z0-9_]{1,100}$/', $session_id ) ) {
            throw new Exception( __( 'Invalid import session.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $session_dir = WPCM_TEMP_DIR . $session_id . '/';
        if ( ! is_dir( $session_dir ) ) {
            throw new Exception( __( 'Import session not found.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $manifest_path = $this->find_manifest( $session_dir );
        $manifest      = $manifest_path ? json_decode( (string) file_get_contents( $manifest_path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local validated file.
        if ( ! is_array( $manifest ) ) {
            throw new Exception( __( 'Validated manifest is missing.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $validation = WPCM_Reliability::read_signed_json(
            $session_dir . 'archive-validation.json',
            'import-validation:' . $session_id
        );
        if ( ! is_array( $validation ) || empty( $validation['normalized_manifest_sha256'] ) ) {
            throw new Exception( __( 'The authenticated archive validation record is missing or invalid.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $current_manifest_hash = WPCM_Reliability::checksum( $manifest_path );
        if ( ! hash_equals( (string) $validation['normalized_manifest_sha256'], $current_manifest_hash ) ) {
            throw new Exception( __( 'The archive manifest changed after validation.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $this->validate_manifest( $manifest, $session_dir );
        WPCM_Debug_Log::write( 'info', 'installer_prepare_manifest_validated', array( 'session_id' => $session_id, 'tables' => count( $manifest['tables'] ?? array() ), 'files' => (int) ( $manifest['files_count'] ?? 0 ) ) );

        $destination_url = esc_url_raw( $new_url ?: site_url(), array( 'http', 'https' ) );
        $destination     = wp_parse_url( $destination_url );
        if ( empty( $destination_url ) || empty( $destination['scheme'] ) || empty( $destination['host'] ) || ! in_array( strtolower( $destination['scheme'] ), array( 'http', 'https' ), true ) ) {
            throw new Exception( __( 'The destination URL must be a valid HTTP or HTTPS URL.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $destination_url = untrailingslashit( $destination_url );

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $client_token = isset( $_POST['installer_token'] ) ? wp_unslash( $_POST['installer_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict regex validation follows.
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( ! is_string( $client_token ) || ! preg_match( '/^[a-f0-9]{64}$/', $client_token ) ) {
            throw new Exception( __( 'Invalid installer token.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            throw new Exception( __( 'The PHP OpenSSL extension is required for transactional restoration.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        WPCM_Debug_Log::write( 'info', 'installer_prepare_crypto_started', array( 'session_id' => $session_id ) );
        $token_hash = password_hash( $client_token, PASSWORD_BCRYPT, array( 'cost' => 11 ) );
        if ( false === $token_hash ) {
            throw new Exception( __( 'Unable to secure the installer token.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $enc_key  = hash( 'sha256', $client_token, true );
        $enc_iv   = random_bytes( 12 );
        $enc_tag  = '';
        $db_plain = wp_json_encode(
            array(
                'db_host'    => DB_HOST,
                'db_name'    => DB_NAME,
                'db_user'    => DB_USER,
                'db_pass'    => DB_PASSWORD,
                'db_charset' => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
            )
        );
        if ( ! is_string( $db_plain ) ) {
            throw new RuntimeException( __( 'Unable to encode database credentials for encrypted installer preparation.', 'clone-master' ) );
        }
        $db_cipher = openssl_encrypt( $db_plain, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $enc_iv, $enc_tag, $session_id );
        if ( false === $db_cipher ) {
            throw new Exception( __( 'Unable to encrypt database credentials.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $admin_parts = wp_parse_url( admin_url() );
        if ( ! is_array( $admin_parts ) || empty( $admin_parts['host'] ) ) {
            throw new RuntimeException( __( 'WordPress could not determine the authenticated admin origin.', 'clone-master' ) );
        }
        $safe_origin = ( $admin_parts['scheme'] ?? 'https' ) . '://' . $admin_parts['host'];
        if ( ! empty( $admin_parts['port'] ) ) {
            $safe_origin .= ':' . (int) $admin_parts['port'];
        }

        $restore_id       = substr( hash( 'sha256', $session_id . $client_token ), 0, 10 );
        $recovery_key     = bin2hex( random_bytes( 32 ) );
        $staging_prefix   = 'wpcmstg_' . $restore_id . '_';
        $rollback_prefix  = 'wpcmrb_' . $restore_id . '_';
        $rollback_dir     = trailingslashit( WP_CONTENT_DIR ) . 'wpcm-rollback-' . $restore_id . '/';
        $staging_dir      = $session_dir . 'staging/';
        $import_opts      = json_decode( $import_opts_json ?: '{}', true );
        if ( ! is_array( $import_opts ) ) {
            $import_opts = array();
        }
        $import_opts = array(
            'reset_permalinks' => ! empty( $import_opts['reset_permalinks'] ),
            'block_indexing'   => ! empty( $import_opts['block_indexing'] ),
        );

        $uploads = wp_upload_dir();
        if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            throw new RuntimeException( __( 'WordPress could not resolve the uploads directory for the restore plan.', 'clone-master' ) );
        }
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
            throw new RuntimeException( __( 'WordPress database context is unavailable during installer preparation.', 'clone-master' ) );
        }

        $config = array(
            'format_version'     => 2,
            'token_hash'         => $token_hash,
            'expires_at'         => time() + ( 6 * HOUR_IN_SECONDS ),
            'allowed_origin'     => $safe_origin,
            'session_id'         => $session_id,
            'session_dir'        => $session_dir,
            'manifest_path'      => $manifest_path,
            'restore_id'         => $restore_id,
            'recovery_key'       => $recovery_key,
            'old_url'            => $manifest['site_url'] ?? '',
            'old_home'           => $manifest['home_url'] ?? '',
            'new_url'            => $destination_url,
            'old_path'           => $manifest['abspath'] ?? '',
            'new_path'           => ABSPATH,
            'old_prefix'         => $manifest['db_prefix'] ?? 'wp_',
            'staging_rewrite_version' => 2,
            'staging_prefix'     => $staging_prefix,
            'rollback_prefix'    => $rollback_prefix,
            'target_prefix'      => $wpdb->prefix,
            'db_credentials'     => base64_encode( $enc_iv . $enc_tag . $db_cipher ),
            'db_aad'             => $session_id,
            'staging_dir'        => $staging_dir,
            'rollback_dir'       => $rollback_dir,
            'wp_content_dir'     => WP_CONTENT_DIR,
            'theme_root'         => get_theme_root(),
            'plugin_dir'         => WP_PLUGIN_DIR,
            'uploads_dir'        => (string) $uploads['basedir'],
            'abspath'            => ABSPATH,
            'plugin_slug'        => plugin_basename( WPCM_PLUGIN_DIR . 'clone-master.php' ),
            'plugin_folder'      => basename( rtrim( WPCM_PLUGIN_DIR, '/\\' ) ),
            'recovery_bootstrap' => WPCM_PLUGIN_DIR . 'includes/recovery-bootstrap.php',
            'expected_tables'    => array_values( $manifest['tables'] ?? array() ),
            'expected_archives'  => $manifest['archives'] ?? array(),
            'content_presence'   => $manifest['content_presence'] ?? array(),
            'files_count'        => (int) ( $manifest['files_count'] ?? 0 ),
            'files_size'         => (int) ( $manifest['files_size'] ?? 0 ),
            'root_files'         => array_values( $manifest['root_files'] ?? array() ),
            'active_plugins'     => array_values( $manifest['active_plugins'] ?? array() ),
            'active_theme'       => (string) ( $manifest['active_theme'] ?? '' ),
            'import_opts'        => $import_opts,
            'debug_log_path'     => WPCM_Debug_Log::path(),
        );

        $config_path = $session_dir . 'installer-config.php';
        $config_json = wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        if ( ! is_string( $config_json ) ) {
            throw new RuntimeException( __( 'Unable to encode the transactional installer configuration.', 'clone-master' ) );
        }
        $payload = "<?php\nhttp_response_code(404);\nexit;\n__halt_compiler();\n" . $config_json;
        WPCM_Reliability::atomic_write( $config_path, $payload, 0600 );
        WPCM_Debug_Log::write( 'info', 'installer_prepare_config_written', array( 'session_id' => $session_id, 'config_path' => basename( $config_path ) ) );

        // Store the recovery key separately, encrypted with the destination site's
        // WordPress salts. This lets Clone Master roll back an abandoned destructive
        // transition after WordPress can boot again, without storing the installer token.
        $site_key = WPCM_Reliability::recovery_encryption_key();
        $recovery_iv = random_bytes( 12 );
        $recovery_tag = '';
        $recovery_cipher = openssl_encrypt( $recovery_key, 'aes-256-gcm', $site_key, OPENSSL_RAW_DATA, $recovery_iv, $recovery_tag, $session_id );
        if ( false === $recovery_cipher ) {
            throw new Exception( __( 'Unable to encrypt the automatic recovery key.', 'clone-master' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $recovery_json = wp_json_encode(
            array(
                'session_id' => $session_id,
                'payload'    => base64_encode( $recovery_iv . $recovery_tag . $recovery_cipher ),
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ( ! is_string( $recovery_json ) ) {
            throw new RuntimeException( __( 'Unable to encode the automatic recovery key envelope.', 'clone-master' ) );
        }
        $recovery_payload = "<?php
http_response_code(404);
exit;
__halt_compiler();
" . $recovery_json;
        WPCM_Reliability::atomic_write( $session_dir . 'recovery-key.php', $recovery_payload, 0600 );

        $initial_state = array(
            'version'            => 2,
            'restore_id'         => $restore_id,
            'phase'              => 'prepared',
            'updated_at'         => gmdate( 'c' ),
            'db'                 => array( 'file_index' => 0, 'byte_offset' => 0, 'queries' => 0, 'errors' => 0 ),
            'files'              => array(
                'staged'         => false,
                'promotion_plan' => array(),
                'promoted'       => array(),
                'bootstrap'      => array(),
            ),
            'replace'            => array( 'table_index' => 0, 'last_key' => null, 'rows' => 0, 'cells' => 0, 'serialized' => 0, 'skipped' => array() ),
            'database_committed' => false,
            'rollback_required'  => false,
            'health'             => array(),
        );
        $this->write_signed_state( $session_dir, $initial_state, $client_token, $recovery_key, $config );
        WPCM_Reliability::atomic_write( trailingslashit( $rollback_dir ) . 'recovery-key.php', $recovery_payload, 0600 );
        WPCM_Debug_Log::write( 'info', 'installer_prepare_journal_written', array( 'session_id' => $session_id, 'restore_id' => $restore_id ) );

        $locator       = rtrim( strtr( base64_encode( WP_CONTENT_DIR ), '+/', '-_' ), '=' );
        $locator_hmac  = hash_hmac( 'sha256', $session_id . '|' . $locator, hash( 'sha256', $client_token, true ) );
        $installer_url = add_query_arg(
            array(
                'sid'  => $session_id,
                'loc'  => $locator,
                'lsig' => $locator_hmac,
            ),
            WPCM_PLUGIN_URL . 'installer.php'
        );

        WPCM_Debug_Log::write( 'info', 'installer_prepare_completed', array( 'session_id' => $session_id, 'restore_id' => $restore_id ) );

        return array(
            'session_id'    => $session_id,
            'installer_url' => $installer_url,
            'next_step'     => null,
            'progress'      => 20,
            'message'       => __( 'Transactional installer prepared. Existing data will remain untouched until final promotion.', 'clone-master' ),
        );
    }

    /**
     * Write two alternating HMAC-protected state copies.
     *
     * @param string $session_dir Session directory.
     * @param array  $state       Restore state.
     * @param string $token       Installer token.
     * @return void
     */
    private function write_signed_state( $session_dir, array $state, $token, $recovery_key, array $config ) {
        $state['sequence']   = max( 1, (int) ( $state['sequence'] ?? 1 ) );
        $state['updated_at'] = gmdate( 'c' );
        $json                = wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        if ( ! is_string( $json ) ) {
            throw new RuntimeException( 'Unable to encode the signed restore state.' );
        }
        $envelope            = array(
            'state' => $state,
            'hmac'  => hash_hmac( 'sha256', $json, hash( 'sha256', $token, true ) ),
        );
        WPCM_Reliability::atomic_json( $session_dir . 'restore-state-a.json', $envelope );
        WPCM_Reliability::atomic_json( $session_dir . 'restore-state-b.json', $envelope );

        $plan = array(
            'state'  => $state,
            'config' => array(
                'session_id'      => $config['session_id'],
                'restore_id'      => $config['restore_id'],
                'staging_prefix'  => $config['staging_prefix'],
                'rollback_prefix' => $config['rollback_prefix'],
                'target_prefix'   => $config['target_prefix'],
                'rollback_dir'    => $config['rollback_dir'],
                'staging_dir'     => $config['staging_dir'],
                'abspath'            => $config['abspath'],
                'wp_content_dir'      => $config['wp_content_dir'],
                'plugin_dir'          => $config['plugin_dir'],
                'plugin_folder'       => $config['plugin_folder'],
                'recovery_bootstrap'  => $config['recovery_bootstrap'],
            ),
        );
        $plan_json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        $recovery_binary = hex2bin( $recovery_key );
        if ( ! is_string( $plan_json ) || false === $recovery_binary ) {
            throw new RuntimeException( 'Unable to encode the redundant recovery plan.' );
        }
        $plan['hmac'] = hash_hmac( 'sha256', $plan_json, $recovery_binary );
        $signed_plan_json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        if ( ! is_string( $signed_plan_json ) ) {
            throw new RuntimeException( 'Unable to encode the signed redundant recovery plan.' );
        }
        $payload = "<?php
http_response_code(404);
exit;
__halt_compiler();
" . $signed_plan_json;
        WPCM_Reliability::atomic_write( $session_dir . 'recovery-plan.php', $payload, 0600 );

        if ( ! is_dir( $config['rollback_dir'] ) && ! wp_mkdir_p( $config['rollback_dir'] ) ) {
            throw new RuntimeException( 'Unable to create the redundant recovery directory.' );
        }
        WPCM_Plugin::protect_directory( $config['rollback_dir'], false );
        WPCM_Reliability::atomic_write( trailingslashit( $config['rollback_dir'] ) . 'recovery-plan.php', $payload, 0600 );
    }

    /**
     * Validate a manifest and all listed checksums.
     *
     * @param array  $manifest Manifest data.
     * @param string $base_dir Extracted archive root.
     * @return void
     * @throws Exception On integrity failure.
     */
    private function validate_manifest( array $manifest, $base_dir ) {
        foreach ( array( 'schema_version', 'format', 'site_url', 'table_prefix', 'sha256_database', 'files_count', 'files_size', 'tables' ) as $required ) {
            if ( ! array_key_exists( $required, $manifest ) ) {
                throw new Exception( sprintf( 'Invalid WPCM manifest: field "%s" is missing.', $required ) );
            }
        }
        if ( '2.0' !== (string) $manifest['schema_version'] || 'wpcm-append-only' !== (string) $manifest['format'] ) {
            throw new Exception( __( 'Unsupported WPCM schema.', 'clone-master' ) );
        }
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $manifest['table_prefix'] ) ) {
            throw new Exception( __( 'Invalid WPCM table prefix.', 'clone-master' ) );
        }
        $database = trailingslashit( $base_dir ) . 'database.sql';
        if ( ! is_file( $database ) || ! is_readable( $database ) ) {
            throw new Exception( __( 'The validated WPCM database stream is missing.', 'clone-master' ) );
        }
        $database_hash = hash_file( 'sha256', $database );
        if ( ! is_string( $database_hash ) || ! hash_equals( (string) $manifest['sha256_database'], $database_hash ) ) {
            throw new Exception( __( 'The extracted database stream failed SHA-256 validation.', 'clone-master' ) );
        }

        $files_root = trailingslashit( $base_dir ) . 'wp-content/';
        $count = 0;
        $bytes = 0;
        if ( is_dir( $files_root ) ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $files_root, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $item ) {
                if ( $item->isLink() ) {
                    throw new Exception( __( 'A symbolic link appeared after WPCM extraction.', 'clone-master' ) );
                }
                if ( $item->isFile() ) {
                    $size = $item->getSize();
                    $count++;
                    $bytes += (int) $size;
                }
            }
        }
        if ( $count !== (int) $manifest['files_count'] || $bytes !== (int) $manifest['files_size'] ) {
            throw new Exception( __( 'The extracted WPCM file inventory no longer matches the manifest.', 'clone-master' ) );
        }
        if ( empty( $manifest['tables'] ) ) {
            throw new Exception( __( 'The database stream does not contain a verifiable table inventory.', 'clone-master' ) );
        }
    }

    /**
     * Resolve the normalized manifest created by the validated extraction step.
     *
     * @param string $session_dir Protected import workspace.
     * @return string|null
     */
    private function find_manifest( $session_dir ) {
        $session_real  = realpath( $session_dir );
        $manifest_path = trailingslashit( $session_dir ) . 'manifest.json';
        $manifest_real = realpath( $manifest_path );
        if ( false === $session_real || false === $manifest_real || ! is_file( $manifest_real ) || ! is_readable( $manifest_real ) || is_link( $manifest_path ) ) {
            return null;
        }
        $prefix = rtrim( $session_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        if ( 0 !== strpos( $manifest_real, $prefix ) ) {
            return null;
        }
        return $manifest_real;
    }

    /**
     * Normalize the agent manifest and derive optional transactional metadata.
     *
     * @param array  $manifest   Raw WPCM manifest.
     * @param string $session_dir Extracted session directory.
     * @return array
     */
    private function normalize_wpcm_manifest( array $manifest, $session_dir ) {
        if ( '2.0' !== (string) ( $manifest['schema_version'] ?? '' )
            || 'wpcm-append-only' !== (string) ( $manifest['format'] ?? '' ) ) {
            throw new Exception( __( 'This file is not a WP Commander compatible WPCMARCHIVE2 container.', 'clone-master' ) );
        }
        $prefix = (string) ( $manifest['table_prefix'] ?? '' );
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
            throw new Exception( __( 'The WPCM table prefix is invalid.', 'clone-master' ) );
        }
        $manifest['db_prefix']      = $prefix;
        $manifest['home_url']       = (string) ( $manifest['home_url'] ?? $manifest['site_url'] ?? '' );
        $manifest['site_url']       = (string) ( $manifest['site_url'] ?? $manifest['home_url'] );
        $manifest['abspath']        = (string) ( $manifest['abspath'] ?? '' );
        $manifest['active_plugins'] = is_array( $manifest['active_plugins'] ?? null ) ? array_values( $manifest['active_plugins'] ) : array();
        $manifest['active_theme']   = (string) ( $manifest['active_theme'] ?? '' );
        $manifest['archives']       = array();
        $manifest['root_files']     = array();
        $manifest['content_presence'] = array();
        foreach ( array( 'themes', 'plugins', 'uploads', 'mu-plugins', 'languages' ) as $name ) {
            $manifest['content_presence'][ $name ] = is_dir( trailingslashit( $session_dir ) . 'wp-content/' . $name );
        }
        if ( ! is_array( $manifest['tables'] ?? null ) || empty( $manifest['tables'] ) ) {
            $manifest['tables'] = $this->derive_table_manifest_from_sql(
                trailingslashit( $session_dir ) . 'database.sql',
                $prefix
            );
        }
        return $manifest;
    }

    /**
     * Derive table names and schema hashes without modifying SQL payload bytes.
     *
     * @param string $path   database.sql path.
     * @param string $prefix Archived WordPress table prefix.
     * @return array
     */
    private function derive_table_manifest_from_sql( $path, $prefix ) {
        $handle = @fopen( $path, 'rb' );
        if ( ! is_resource( $handle ) ) {
            throw new Exception( __( 'Unable to inspect the WPCM database stream.', 'clone-master' ) );
        }
        $tables = array();
        $buffer = '';
        $collecting = false;
        try {
            while ( false !== ( $line = fgets( $handle, 1048576 ) ) ) {
                if ( ! $collecting ) {
                    if ( ! preg_match( '/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([^`]+)`/i', $line, $match ) ) {
                        continue;
                    }
                    $name = (string) $match[1];
                    if ( 0 !== strpos( $name, $prefix ) ) {
                        throw new Exception( 'Archived table does not match the declared prefix: ' . $name );
                    }
                    $buffer = $line;
                    $collecting = true;
                } else {
                    $buffer .= $line;
                }
                if ( $this->sql_statement_complete( $buffer ) ) {
                    $normalized = preg_replace( '/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i', 'CREATE TABLE', trim( $buffer ) );
                    $normalized = preg_replace( '/\s+AUTO_INCREMENT=\d+/i', '', $normalized );
                    $normalized = rtrim( trim( (string) $normalized ), ";\r\n\t " );
                    $tables[] = array(
                        'name'        => $name,
                        'schema_hash' => hash( 'sha256', $normalized ),
                    );
                    $buffer = '';
                    $collecting = false;
                }
            }
        } finally {
            fclose( $handle );
        }
        if ( $collecting || empty( $tables ) ) {
            throw new Exception( __( 'Unable to derive a complete table manifest from database.sql.', 'clone-master' ) );
        }
        return $tables;
    }

    /**
     * Detect a terminating semicolon outside SQL strings, comments, and backticks.
     *
     * @param string $statement SQL buffer.
     * @return bool
     */
    private function sql_statement_complete( $statement ) {
        $quote = '';
        $escaped = false;
        $length = strlen( $statement );
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $statement[ $i ];
            if ( $escaped ) {
                $escaped = false;
                continue;
            }
            if ( '\\' === $char && "'" === $quote ) {
                $escaped = true;
                continue;
            }
            if ( '' === $quote && ( "'" === $char || '"' === $char || '`' === $char ) ) {
                $quote = $char;
                continue;
            }
            if ( '' !== $quote && $char === $quote ) {
                if ( $i + 1 < $length && $statement[ $i + 1 ] === $quote ) {
                    $i++;
                    continue;
                }
                $quote = '';
                continue;
            }
            if ( '' === $quote && ';' === $char ) {
                return true;
            }
        }
        return false;
    }

    private function recursive_delete( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getRealPath() );
            } else {
                @unlink( $item->getRealPath() );
            }
        }
        @rmdir( $dir );
    }
}
