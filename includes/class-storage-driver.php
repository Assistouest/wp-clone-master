<?php
/**
 * WPCM_Storage_Driver : Abstract base class for backup storage drivers.
 *
 * Every driver must implement:
 *   upload( string $local_path, string $filename ) : array
 *     → Upload a local WPCM container to the remote destination.
 *     → Returns [ 'ok' => bool, 'message' => string, 'remote_path' => string ]
 *
 *   delete( string $filename ) : bool
 *     → Remove a file from the remote destination (used by retention).
 *
 *   test() : array
 *     → Validate credentials / connectivity before saving settings.
 *     → Returns [ 'ok' => bool, 'message' => string ]
 *
 * Static factory:
 *   WPCM_Storage_Driver::make( WPCM_Backup_Settings $s ) : self
 */

if ( ! defined( 'ABSPATH' ) ) exit;

abstract class WPCM_Storage_Driver {

    /**
     * Factory : returns the configured driver instance.
     */
    public static function make( WPCM_Backup_Settings $settings ): self {
        $driver = $settings->storage_driver ?? 'local';

        switch ( $driver ) {
            case 'nextcloud':
                return new WPCM_Storage_Nextcloud( $settings );
            case 'local':
            default:
                return new WPCM_Storage_Local();
        }
    }

    /**
     * Upload a local file to the storage destination.
     *
     * @param  string $local_path  Absolute path to the local WPCM container.
     * @param  string $filename    Basename to use on the remote (may differ from local).
     * @return array  { ok: bool, message: string, remote_path: string }
     */
    abstract public function upload( string $local_path, string $filename ): array;

    /**
     * Delete a file from the remote storage (used by remote retention).
     *
     * @param  string $filename  Basename of the file to delete.
     * @return bool
     */
    abstract public function delete( string $filename ): bool;

    /**
     * Test connectivity and credentials without uploading.
     *
     * @return array  { ok: bool, message: string }
     */
    abstract public function test(): array;

    /**
     * Human-readable driver name.
     */
    abstract public function label(): string;
}


/* ============================================================================
   WPCM_Storage_Local : keeps files in WPCM_BACKUP_DIR (default behaviour)
   ============================================================================ */

class WPCM_Storage_Local extends WPCM_Storage_Driver {

    public function label(): string {
        return __( 'Local storage', 'clone-master' );
    }

    /**
     * Nothing to do : the file is already in WPCM_BACKUP_DIR.
     * We just confirm it exists.
     */
    public function upload( string $local_path, string $filename ): array {
        $exists = file_exists( $local_path );
        return [
            'ok'          => $exists,
            'message'     => $exists ? __( 'File stored locally.', 'clone-master' ) : __( 'File not found: ', 'clone-master' ) . $local_path,
            'remote_path' => $local_path,
        ];
    }

    public function delete( string $filename ): bool {
        $path = WPCM_BACKUP_DIR . $filename;
        return file_exists( $path ) ? wp_delete_file( $path ) : true;
    }

    public function test(): array {
        $writable = is_writable( WPCM_BACKUP_DIR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem requires admin credentials; unsuitable for background check
        return [
            'ok'      => $writable,
            'message' => $writable
                ? __( 'Backup directory is writable.', 'clone-master' )
                : sprintf(
			/* translators: %s: backup directory path */
			__( 'Directory %1$s is not writable.', 'clone-master' ),
			WPCM_BACKUP_DIR
		),
        ];
    }
}


/* ============================================================================
   WPCM_Storage_Nextcloud : uploads via WebDAV (PUT) using $this->safe_remote_request()
   ============================================================================ */

class WPCM_Storage_Nextcloud extends WPCM_Storage_Driver {

    /** @var WPCM_Backup_Settings */
    private $settings;

    public function __construct( WPCM_Backup_Settings $settings ) {
        $this->settings = $settings;
    }

    public function label(): string {
        return __( 'Nextcloud', 'clone-master' );
    }

    // ── WebDAV base URL ──────────────────────────────────────────────────────

    /**
     * Encode a slash-separated WebDAV path without encoding the separators.
     *
     * @param string $path Relative remote path.
     * @return string
     */
    private static function encode_remote_path( string $path ): string {
        $segments = array_filter( explode( '/', trim( str_replace( '\\', '/', $path ), '/' ) ), 'strlen' );
        return implode( '/', array_map( 'rawurlencode', $segments ) );
    }

    /**
     * Returns the fully qualified WebDAV URL for a given remote filename.
     *
     * Nextcloud DAV endpoint:
     *   {server_url}/remote.php/dav/files/{user}/{remote_path}/{filename}
     */
    private function dav_url( string $filename = '' ): string {
        $base     = rtrim( (string) $this->settings->nextcloud_url, '/' );
        $user     = rawurlencode( (string) $this->settings->nextcloud_user );
        $path     = self::encode_remote_path( (string) $this->settings->nextcloud_path );
        $dav_root = $base . '/remote.php/dav/files/' . $user . '/' . ( $path ? $path . '/' : '' );

        return $filename ? $dav_root . rawurlencode( $filename ) : $dav_root;
    }

    /**
     * Shared request args (auth + timeout).
     */
    private function base_args( array $extra = [] ): array {
        return array_merge( [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(
                    $this->settings->nextcloud_user . ':' . $this->settings->nextcloud_pass
                ),
            ],
            'timeout'   => 30,
            'sslverify' => true,
        ], $extra );
    }

    // ── SSRF guard ───────────────────────────────────────────────────────────

    /**
     * Resolve an outbound URL to a public endpoint.
     *
     * Every returned address must be public. A hostname resolving to a mixture
     * of public and private addresses is rejected to prevent DNS rebinding and
     * split-horizon bypasses.
     *
     * @param string $url Outbound URL.
     * @return array|WP_Error
     */
    public static function resolve_public_endpoint( string $url ) {
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return new WP_Error( 'wpcm_invalid_url', __( 'Invalid Nextcloud URL.', 'clone-master' ) );
        }

        $scheme = strtolower( (string) $parsed['scheme'] );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return new WP_Error( 'wpcm_invalid_scheme', __( 'Only HTTP and HTTPS Nextcloud URLs are accepted.', 'clone-master' ) );
        }
        if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
            return new WP_Error( 'wpcm_url_credentials', __( 'Credentials must not be embedded in the Nextcloud URL.', 'clone-master' ) );
        }

        $host = trim( strtolower( (string) $parsed['host'] ), '[]' );
        if ( '' === $host || 'localhost' === $host || substr( $host, -6 ) === '.local' ) {
            return new WP_Error( 'wpcm_private_host', __( 'Local Nextcloud hostnames are not accepted.', 'clone-master' ) );
        }

        $addresses = array();
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $addresses[] = $host;
        } else {
            if ( function_exists( 'dns_get_record' ) ) {
                $dns_flags = 0;
                if ( defined( 'DNS_A' ) ) {
                    $dns_flags |= DNS_A;
                }
                if ( defined( 'DNS_AAAA' ) ) {
                    $dns_flags |= DNS_AAAA;
                }
                $records = $dns_flags ? @dns_get_record( $host, $dns_flags ) : false;
                if ( is_array( $records ) ) {
                    foreach ( $records as $record ) {
                        if ( ! empty( $record['ip'] ) ) {
                            $addresses[] = (string) $record['ip'];
                        } elseif ( ! empty( $record['ipv6'] ) ) {
                            $addresses[] = (string) $record['ipv6'];
                        }
                    }
                }
            }
            if ( empty( $addresses ) ) {
                $fallback = @gethostbynamel( $host );
                if ( is_array( $fallback ) ) {
                    $addresses = array_merge( $addresses, $fallback );
                }
            }
        }

        $addresses = array_values( array_unique( array_filter( $addresses ) ) );
        if ( empty( $addresses ) ) {
            return new WP_Error( 'wpcm_dns_failure', __( 'The Nextcloud hostname could not be resolved.', 'clone-master' ) );
        }

        foreach ( $addresses as $address ) {
            if ( ! self::is_public_ip_address( $address ) ) {
                return new WP_Error( 'wpcm_private_address', __( 'The Nextcloud hostname resolves to an internal or reserved address.', 'clone-master' ) );
            }
        }

        $port = isset( $parsed['port'] ) ? (int) $parsed['port'] : ( 'https' === $scheme ? 443 : 80 );
        if ( $port < 1 || $port > 65535 ) {
            return new WP_Error( 'wpcm_invalid_port', __( 'The Nextcloud URL contains an invalid port.', 'clone-master' ) );
        }

        return array(
            'host' => $host,
            'ip'   => (string) $addresses[0],
            'port' => $port,
        );
    }

    /**
     * Reject private, loopback, link-local, carrier-grade NAT, benchmark,
     * documentation, multicast, transition, and otherwise non-global ranges.
     *
     * @param string $address IPv4 or IPv6 address.
     * @return bool
     */
    private static function is_public_ip_address( string $address ): bool {
        $packed = @inet_pton( $address );
        if ( false === $packed ) {
            return false;
        }

        $blocked = 4 === strlen( $packed )
            ? array(
                '0.0.0.0/8',
                '10.0.0.0/8',
                '100.64.0.0/10',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.0.0.0/24',
                '192.0.2.0/24',
                '192.88.99.0/24',
                '192.168.0.0/16',
                '198.18.0.0/15',
                '198.51.100.0/24',
                '203.0.113.0/24',
                '224.0.0.0/4',
                '240.0.0.0/4',
            )
            : array(
                '::/128',
                '::1/128',
                '::ffff:0:0/96',
                '64:ff9b::/96',
                '100::/64',
                '2001::/23',
                '2001:db8::/32',
                '2002::/16',
                'fc00::/7',
                'fe80::/10',
                'ff00::/8',
            );

        foreach ( $blocked as $cidr ) {
            if ( self::ip_matches_cidr( $packed, $cidr ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compare a packed IP address against a CIDR range of the same family.
     *
     * @param string $packed Packed address from inet_pton().
     * @param string $cidr   CIDR range.
     * @return bool
     */
    private static function ip_matches_cidr( string $packed, string $cidr ): bool {
        list( $network, $prefix ) = explode( '/', $cidr, 2 );
        $network_packed = @inet_pton( $network );
        if ( false === $network_packed || strlen( $network_packed ) !== strlen( $packed ) ) {
            return false;
        }

        $bits       = (int) $prefix;
        $full_bytes = intdiv( $bits, 8 );
        $remaining  = $bits % 8;
        if ( $full_bytes > 0 && substr( $packed, 0, $full_bytes ) !== substr( $network_packed, 0, $full_bytes ) ) {
            return false;
        }
        if ( 0 === $remaining ) {
            return true;
        }
        $mask = ( 0xFF << ( 8 - $remaining ) ) & 0xFF;
        return ( ord( $packed[ $full_bytes ] ) & $mask ) === ( ord( $network_packed[ $full_bytes ] ) & $mask );
    }

    /**
     * Return a translated SSRF validation error, or null when safe.
     *
     * @param string $url URL to validate.
     * @return string|null
     */
    private static function ssrf_check( string $url ): ?string {
        $endpoint = self::resolve_public_endpoint( $url );
        return is_wp_error( $endpoint ) ? $endpoint->get_error_message() : null;
    }

    /**
     * Perform a WordPress HTTP request with unsafe URL rejection enabled.
     *
     * @param string $url  Request URL.
     * @param array  $args Request arguments.
     * @return array|WP_Error
     */
    private function safe_remote_request( string $url, array $args ) {
        $endpoint = self::resolve_public_endpoint( $url );
        if ( is_wp_error( $endpoint ) ) {
            return $endpoint;
        }
        $args['reject_unsafe_urls'] = true;
        $args['redirection']        = 0;
        $args['sslverify']          = true;

        $callback = null;
        if ( function_exists( 'curl_setopt' ) && defined( 'CURLOPT_RESOLVE' ) ) {
            $ip      = false !== strpos( (string) $endpoint['ip'], ':' ) ? '[' . $endpoint['ip'] . ']' : (string) $endpoint['ip'];
            $resolve = (string) $endpoint['host'] . ':' . (int) $endpoint['port'] . ':' . $ip;
            $callback = static function ( $handle, $request_args, $request_url ) use ( $url, $resolve ) {
                unset( $request_args );
                if ( $request_url !== $url ) {
                    return;
                }
                curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve ) );
                curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
                if ( defined( 'CURLOPT_PROXY' ) ) {
                    curl_setopt( $handle, CURLOPT_PROXY, '' );
                }
                if ( defined( 'CURLOPT_NOPROXY' ) ) {
                    curl_setopt( $handle, CURLOPT_NOPROXY, '*' );
                }
                if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
                    curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
                }
            };
            add_action( 'http_api_curl', $callback, 10, 3 );
        }

        try {
            return wp_safe_remote_request( $url, $args );
        } finally {
            if ( null !== $callback ) {
                remove_action( 'http_api_curl', $callback, 10 );
            }
        }
    }

    /**
     * Build cURL DNS pinning options for a validated public endpoint.
     *
     * @param string $url Request URL.
     * @return array|WP_Error
     */
    private static function curl_endpoint_options( string $url ) {
        $endpoint = self::resolve_public_endpoint( $url );
        if ( is_wp_error( $endpoint ) ) {
            return $endpoint;
        }

        $ip = false !== strpos( $endpoint['ip'], ':' ) ? '[' . $endpoint['ip'] . ']' : $endpoint['ip'];
        $options = array(
            CURLOPT_RESOLVE        => array( $endpoint['host'] . ':' . $endpoint['port'] . ':' . $ip ),
            CURLOPT_FOLLOWLOCATION => false,
        );
        if ( defined( 'CURLOPT_PROXY' ) ) {
            $options[ CURLOPT_PROXY ] = '';
        }
        if ( defined( 'CURLOPT_NOPROXY' ) ) {
            $options[ CURLOPT_NOPROXY ] = '*';
        }
        if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
            $options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        return $options;
    }

    // ── Public interface ─────────────────────────────────────────────────────

    /**
     * Uploads $local_path to Nextcloud.
     *
     * Files ≤ CHUNK_THRESHOLD are sent as a single PUT (fast, low overhead).
     * Files > CHUNK_THRESHOLD use Nextcloud's chunked upload API so that:
     *   - No single request exceeds CHUNK_SIZE bytes in the request body
     *   - Any CDN / reverse-proxy timeout (Cloudflare 524, nginx 60 s…) is
     *     irrelevant : each chunk finishes in seconds
     *   - Memory usage stays constant regardless of file size
     *
     * Nextcloud chunked upload protocol (identical to the desktop client):
     *   1. MKCOL  /remote.php/dav/uploads/{user}/{upload_id}
     *   2. PUT    /remote.php/dav/uploads/{user}/{upload_id}/{byte_offset}
     *      (repeated for every chunk)
     *   3. MOVE   /remote.php/dav/uploads/{user}/{upload_id}/.file
     *             Destination: /remote.php/dav/files/{user}/{path}/{filename}
     */
    public function upload( string $local_path, string $filename ): array {
        // ── SSRF guard ────────────────────────────────────────────────────────
        // Re-validate the stored Nextcloud URL before every outbound connection.
        // The AJAX handlers validate at save time, but this driver is also called
        // from WP-Cron (background context) where the URL comes straight from DB.
        // A DB value modified by a compromised admin account must not be able to
        // reach internal network resources via the scheduler.
        $ssrf_error = self::ssrf_check( (string) $this->settings->nextcloud_url );
        if ( $ssrf_error !== null ) {
            return [ 'ok' => false, 'message' => $ssrf_error, 'remote_path' => '' ];
        }
        // ─────────────────────────────────────────────────────────────────────

        if ( ! file_exists( $local_path ) ) {
            return [ 'ok' => false, 'message' => __( 'Local file not found.', 'clone-master' ), 'remote_path' => '' ];
        }

        $size = filesize( $local_path );
        if ( false === $size || $size < 0 ) {
            return [ 'ok' => false, 'message' => __( 'Unable to determine the local file size.', 'clone-master' ), 'remote_path' => '' ];
        }
        $size = (int) $size;

        // Ensure the destination directory exists before transferring data.
        if ( ! $this->ensure_remote_dir() ) {
            return [
                'ok'          => false,
                'message'     => __( 'Unable to create or access the configured Nextcloud directory.', 'clone-master' ),
                'remote_path' => '',
            ];
        }

        if ( $size > self::CHUNK_THRESHOLD ) {
            $result = $this->chunked_upload( $local_path, $filename, $size );
        } else {
            $result = $this->single_put( $local_path, $filename, $size );
        }

        if ( ! $result['ok'] ) {
            return $result;
        }

        // Local retention is intentionally handled by the scheduler only after
        // the successful remote result has been persisted. Deleting here would
        // make a process crash between upload and state persistence unrecoverable.
        return [
            'ok'          => true,
            'message'     => sprintf(
                /* translators: 1: file size, 2: transfer mode. */
                __( 'File uploaded to Nextcloud (%1$s, %2$s).', 'clone-master' ),
                size_format( $size ),
                $size > self::CHUNK_THRESHOLD
                    ? sprintf(
                        /* translators: %d: number of upload parts. */
                        _n( '%d part', '%d parts', (int) ceil( $size / self::CHUNK_SIZE ), 'clone-master' ),
                        (int) ceil( $size / self::CHUNK_SIZE )
                    )
                    : __( 'single request', 'clone-master' )
            ),
            'remote_path' => $this->dav_url( $filename ),
        ];
    }

    /**
     * Deletes a file from Nextcloud via WebDAV DELETE.
     */
    public function delete( string $filename ): bool {
        $response = $this->safe_remote_request(
            $this->dav_url( $filename ),
            array_merge( $this->base_args(), [ 'method' => 'DELETE' ] )
        );

        if ( is_wp_error( $response ) ) return false;

        $code = wp_remote_retrieve_response_code( $response );
        return in_array( $code, [ 200, 204, 404 ], true );
    }

    /**
     * Tests connectivity via PROPFIND on the remote directory.
     */
    public function test(): array {
        if ( empty( $this->settings->nextcloud_url ) ) {
            return [ 'ok' => false, 'message' => __( 'Nextcloud URL is missing.', 'clone-master' ) ];
        }
        if ( empty( $this->settings->nextcloud_user ) ) {
            return [ 'ok' => false, 'message' => __( 'Username is missing.', 'clone-master' ) ];
        }
        if ( empty( $this->settings->nextcloud_pass ) ) {
            return [ 'ok' => false, 'message' => __( 'Password / token is missing.', 'clone-master' ) ];
        }

        $response = $this->safe_remote_request(
            $this->dav_url(),
            array_merge( $this->base_args(), [
                'method'  => 'PROPFIND',
                'headers' => array_merge( $this->base_args()['headers'], [
                    'Depth'        => '0',
                    'Content-Type' => 'application/xml',
                ] ),
                'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
            ] )
        );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'message' => __( 'Network error: ', 'clone-master' ) . $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 207 ) {
            return [ 'ok' => true, 'message' => __( 'Nextcloud connection successful. Folder is accessible.', 'clone-master' ) ];
        }
        if ( $code === 401 ) {
            return [ 'ok' => false, 'message' => __( 'Authentication refused : check your application token.', 'clone-master' ) ];
        }
        if ( $code === 404 ) {
            $created = $this->ensure_remote_dir();
            return $created
                ? [ 'ok' => true,  'message' => __( 'Connection successful. Folder created on Nextcloud.', 'clone-master' ) ]
                : [ 'ok' => false, 'message' => __( 'Remote folder not found and could not be created (HTTP 404).', 'clone-master' ) ];
        }

        return [ 'ok' => false, 'message' => sprintf(
		/* translators: %s: HTTP status code */
		__( 'Unexpected response from Nextcloud server (HTTP %1$s).', 'clone-master' ),
		$code
	) ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /** Files below this threshold are uploaded in a single PUT (bytes). */
    const CHUNK_THRESHOLD = 50 * 1024 * 1024;   // 50 MB

    /** Size of each chunk for large files (bytes). */
    const CHUNK_SIZE      = 10 * 1024 * 1024;   // 10 MB per chunk

    /**
     * Single-PUT upload for files ≤ CHUNK_THRESHOLD.
     * Uses cURL streaming so the file is never loaded into PHP memory.
     */
    // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cURL required for Nextcloud WebDAV upload; $this->safe_remote_request() cannot stream binary files
    private function single_put( string $local_path, string $filename, int $size ): array {
        if ( ! function_exists( 'curl_init' ) ) {
            return $this->wp_put_small( $local_path, $filename );
        }

        $handle = fopen( $local_path, 'rb' );
        if ( ! $handle ) {
            return [ 'ok' => false, 'message' => __( 'Cannot open local file.', 'clone-master' ), 'remote_path' => '' ];
        }

        $url      = $this->dav_url( $filename );
        $security = self::curl_endpoint_options( $url );
        if ( is_wp_error( $security ) ) {
            fclose( $handle );
            return array( 'ok' => false, 'message' => $security->get_error_message(), 'remote_path' => '' );
        }
        $ch = curl_init( $url );
        curl_setopt_array( $ch, array_merge( [
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $this->curl_auth_headers(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ], $security ) );

        curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error     = curl_error( $ch );
        curl_close( $ch );
        fclose( $handle );

        if ( $error ) {
            return [ 'ok' => false, 'message' => sprintf( __( 'cURL error: %s', 'clone-master' ), $error ), 'remote_path' => '' ];
        }

        $ok = in_array( $http_code, [ 200, 201, 204 ], true );
        return [
            'ok'          => $ok,
            'message'     => $ok ? __( 'Upload successful.', 'clone-master' ) : sprintf(
				/* translators: %s: HTTP status code */
				__( 'HTTP %1$s on PUT request.', 'clone-master' ),
				$http_code
			),
            'remote_path' => $url,
        ];
    }

    /**
     * Nextcloud chunked upload for files > CHUNK_THRESHOLD.
     *
     * Protocol: https://docs.nextcloud.com/server/latest/developer_manual/
     *           client_apis/WebDAV/chunkedupload.html
     *
     * Step 1 : MKCOL /remote.php/dav/uploads/{user}/{upload_id}
     * Step 2 : PUT   /remote.php/dav/uploads/{user}/{upload_id}/{offset}  (× N)
     * Step 3 : MOVE  /remote.php/dav/uploads/{user}/{upload_id}/.file
     *          Destination: /remote.php/dav/files/{user}/{path}/{filename}
     */
    private function chunked_upload( string $local_path, string $filename, int $size ): array {
        if ( ! function_exists( 'curl_init' ) ) {
            // cURL is mandatory for chunked upload : no safe fallback for 3 GB files
            return [
                'ok'          => false,
                'message'     => __( 'The PHP cURL extension is required for large file uploads.', 'clone-master' ),
                'remote_path' => '',
            ];
        }

        $base      = rtrim( (string) $this->settings->nextcloud_url, '/' );
        $user      = rawurlencode( (string) $this->settings->nextcloud_user );
        $upload_id = 'wpcm_' . wp_generate_password( 16, false ) . '_' . time();

        $uploads_base = $base . '/remote.php/dav/uploads/' . $user . '/';
        $session_url  = $uploads_base . rawurlencode( $upload_id ) . '/';

        // ── Step 1: Create upload session (MKCOL) ───────────────────────────
        $mkcol = $this->curl_request( 'MKCOL', $session_url, null, 0, [], 30 );
        if ( ! in_array( $mkcol['code'], [ 201, 405 ], true ) ) {
            return [
                'ok'          => false,
                'message'     => sprintf(
				/* translators: %s: HTTP status code */
				__( 'Cannot create upload session (HTTP %1$s).', 'clone-master' ),
				$mkcol['code']
			),
                'remote_path' => '',
            ];
        }

        // ── Step 2: Upload chunks ────────────────────────────────────────────
        $handle = fopen( $local_path, 'rb' );
        if ( ! $handle ) {
            return [ 'ok' => false, 'message' => __( 'Unable to open the file.', 'clone-master' ), 'remote_path' => '' ];
        }

        $offset     = 0;
        $chunk_num  = 0;
        $total_chunks = (int) ceil( $size / self::CHUNK_SIZE );

        while ( ! feof( $handle ) ) {
            $chunk = fread( $handle, self::CHUNK_SIZE );
            if ( $chunk === false || strlen( $chunk ) === 0 ) break;

            $chunk_size   = strlen( $chunk );
            $chunk_url    = $session_url . $offset;

            $result = $this->curl_request( 'PUT', $chunk_url, $chunk, $chunk_size, [], 120 );

            if ( ! in_array( $result['code'], [ 200, 201, 204 ], true ) ) {
                fclose( $handle );
                // Clean up the incomplete upload session
                $this->curl_request( 'DELETE', $session_url, null, 0, [], 15 );
                return [
                    'ok'          => false,
                    'message'     => sprintf(
                        'Chunk %d/%d failed (HTTP %d). Upload cancelled.',
                        $chunk_num + 1, $total_chunks, $result['code']
                    ),
                    'remote_path' => '',
                ];
            }

            $offset    += $chunk_size;
            $chunk_num += 1;
        }

        fclose( $handle );

        // ── Step 3: Assemble : MOVE .file to final destination ───────────────
        $path         = self::encode_remote_path( (string) $this->settings->nextcloud_path );
        $dest_path    = '/remote.php/dav/files/' . $user . '/'
                      . ( $path ? $path . '/' : '' )
                      . rawurlencode( $filename );
        $dest_url     = $base . $dest_path;

        $move = $this->curl_request( 'MOVE', $session_url . '.file', null, 0, [
            'Destination'   => $dest_url,
            'Overwrite'     => 'T',
        ], 60 );

        if ( ! in_array( $move['code'], [ 200, 201, 204 ], true ) ) {
            return [
                'ok'          => false,
                'message'     => sprintf(
                    /* translators: %d: HTTP response status. */
                    __( 'Assembly failed (HTTP %d). The uploaded parts remain on Nextcloud; retry the operation.', 'clone-master' ),
                    (int) $move['code']
                ),
                'remote_path' => '',
            ];
        }

        return [
            'ok'          => true,
            'message'     => sprintf(
                /* translators: 1: number of parts, 2: file size. */
                __( 'Chunked upload completed (%1$d parts, %2$s).', 'clone-master' ),
                $total_chunks,
                size_format( $size )
            ),
            'remote_path' => $dest_url,
        ];
    }

    /**
     * Small-file fallback using $this->safe_remote_request() when cURL is absent.
     * Only for files below CHUNK_THRESHOLD (50 MB).
     */
    private function wp_put_small( string $local_path, string $filename ): array {
        $body = file_get_contents( $local_path );
        if ( $body === false ) {
            return [ 'ok' => false, 'message' => __( 'Unable to read the file.', 'clone-master' ), 'remote_path' => '' ];
        }

        $url      = $this->dav_url( $filename );
        $response = $this->safe_remote_request( $url, array_merge( $this->base_args(), [
            'method'  => 'PUT',
            'timeout' => 120,
            'headers' => array_merge( $this->base_args()['headers'], [
                'Content-Type'   => 'application/octet-stream',
                'Content-Length' => strlen( $body ),
            ] ),
            'body' => $body,
        ] ) );
        unset( $body );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'message' => $response->get_error_message(), 'remote_path' => '' ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $ok   = in_array( $code, [ 200, 201, 204 ], true );
        return [
            'ok'          => $ok,
            'message'     => $ok ? 'Upload successful.' : 'HTTP ' . $code . ' on PUT request.',
            'remote_path' => $url,
        ];
    }

    /**
     * Generic cURL helper used by chunked upload.
     * Sends a request with the given method, optional body, and extra headers.
     *
     * @param string      $method        HTTP method (PUT, MOVE, MKCOL, DELETE…)
     * @param string      $url           Full URL
     * @param string|null $body          Request body (null = no body)
     * @param int         $content_length Body length (0 when $body is null)
     * @param array       $extra_headers  Additional headers as [ name => value ]
     * @param int         $timeout        cURL timeout in seconds
     * @return array { code: int, error: string }
     */
    private function curl_request(
        string $method,
        string $url,
        ?string $body,
        int $content_length,
        array $extra_headers,
        int $timeout
    ): array {
        $security = self::curl_endpoint_options( $url );
        if ( is_wp_error( $security ) ) {
            return array( 'code' => 0, 'error' => $security->get_error_message() );
        }
        $ch = curl_init( $url );

        $headers = array_merge( $this->curl_auth_headers(), [
            'Content-Type: application/octet-stream',
        ] );
        foreach ( $extra_headers as $k => $v ) {
            $headers[] = $k . ': ' . $v;
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        $opts = array_replace( $opts, $security );

        if ( $body !== null ) {
            $opts[ CURLOPT_POSTFIELDS ]    = $body;
            $opts[ CURLOPT_POSTREDIR ]     = 0;
        } else {
            $opts[ CURLOPT_POSTFIELDS ]    = '';
        }

        curl_setopt_array( $ch, $opts );

        curl_exec( $ch );
        $code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );

        return [ 'code' => $code, 'error' => $error ];
    }
    // phpcs:enable

    /**
     * Returns the Authorization header array for cURL.
     */
    private function curl_auth_headers(): array {
        return [
            'Authorization: Basic ' . base64_encode(
                $this->settings->nextcloud_user . ':' . $this->settings->nextcloud_pass
            ),
        ];
    }

    /**
     * Creates the remote directory recursively via WebDAV MKCOL.
     */
    private function ensure_remote_dir(): bool {
        $base   = rtrim( (string) $this->settings->nextcloud_url, '/' );
        $user   = rawurlencode( (string) $this->settings->nextcloud_user );
        $path   = trim( (string) $this->settings->nextcloud_path, '/' );

        if ( ! $path ) return true;

        $dav_base = $base . '/remote.php/dav/files/' . $user . '/';
        $current  = '';

        foreach ( explode( '/', $path ) as $segment ) {
            $current .= rawurlencode( $segment ) . '/';
            $url      = $dav_base . $current;

            $check = $this->safe_remote_request( $url, array_merge( $this->base_args(), [
                'method'  => 'PROPFIND',
                'headers' => array_merge( $this->base_args()['headers'], [ 'Depth' => '0' ] ),
            ] ) );

            if ( ! is_wp_error( $check ) && wp_remote_retrieve_response_code( $check ) === 207 ) {
                continue;
            }

            $mkcol = $this->safe_remote_request( $url, array_merge( $this->base_args(), [ 'method' => 'MKCOL' ] ) );
            if ( is_wp_error( $mkcol ) ) return false;

            $code = wp_remote_retrieve_response_code( $mkcol );
            if ( ! in_array( $code, [ 201, 301, 405 ], true ) ) return false;
        }

        return true;
    }
}
