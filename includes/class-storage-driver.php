<?php
/**
 * WPCM_Storage_Driver — Abstract base class for backup storage drivers.
 *
 * Every driver must implement:
 *   upload( string $local_path, string $filename ) : array
 *     → Upload a local ZIP to the remote destination.
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
     * Factory — returns the configured driver instance.
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
     * @param  string $local_path  Absolute path to the local ZIP file.
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
   WPCM_Storage_Local — keeps files in WPCM_BACKUP_DIR (default behaviour)
   ============================================================================ */

class WPCM_Storage_Local extends WPCM_Storage_Driver {

    public function label(): string {
        return 'Stockage local';
    }

    /**
     * Nothing to do — the file is already in WPCM_BACKUP_DIR.
     * We just confirm it exists.
     */
    public function upload( string $local_path, string $filename ): array {
        $exists = file_exists( $local_path );
        return [
            'ok'          => $exists,
            'message'     => $exists ? 'Fichier stocké localement.' : 'Fichier introuvable : ' . $local_path,
            'remote_path' => $local_path,
        ];
    }

    public function delete( string $filename ): bool {
        $path = WPCM_BACKUP_DIR . $filename;
        return file_exists( $path ) ? unlink( $path ) : true;
    }

    public function test(): array {
        $writable = is_writable( WPCM_BACKUP_DIR );
        return [
            'ok'      => $writable,
            'message' => $writable
                ? 'Répertoire de sauvegarde accessible en écriture.'
                : 'Le répertoire ' . WPCM_BACKUP_DIR . ' n\'est pas accessible en écriture.',
        ];
    }
}


/* ============================================================================
   WPCM_Storage_Nextcloud — uploads via WebDAV (PUT) using wp_remote_request()
   ============================================================================ */

class WPCM_Storage_Nextcloud extends WPCM_Storage_Driver {

    /** @var WPCM_Backup_Settings */
    private $settings;

    public function __construct( WPCM_Backup_Settings $settings ) {
        $this->settings = $settings;
    }

    public function label(): string {
        return 'Nextcloud';
    }

    // ── WebDAV base URL ──────────────────────────────────────────────────────

    /**
     * Returns the fully qualified WebDAV URL for a given remote filename.
     *
     * Nextcloud DAV endpoint:
     *   {server_url}/remote.php/dav/files/{user}/{remote_path}/{filename}
     */
    private function dav_url( string $filename = '' ): string {
        $base     = rtrim( (string) $this->settings->nextcloud_url, '/' );
        $user     = rawurlencode( (string) $this->settings->nextcloud_user );
        $path     = trim( (string) $this->settings->nextcloud_path, '/' );
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

    // ── Public interface ─────────────────────────────────────────────────────

    /**
     * Uploads $local_path to Nextcloud.
     *
     * Files ≤ CHUNK_THRESHOLD are sent as a single PUT (fast, low overhead).
     * Files > CHUNK_THRESHOLD use Nextcloud's chunked upload API so that:
     *   - No single request exceeds CHUNK_SIZE bytes in the request body
     *   - Any CDN / reverse-proxy timeout (Cloudflare 524, nginx 60 s…) is
     *     irrelevant — each chunk finishes in seconds
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
        if ( ! file_exists( $local_path ) ) {
            return [ 'ok' => false, 'message' => 'Fichier local introuvable.', 'remote_path' => '' ];
        }

        $size = filesize( $local_path );

        // Ensure the destination directory exists
        $this->ensure_remote_dir();

        if ( $size > self::CHUNK_THRESHOLD ) {
            $result = $this->chunked_upload( $local_path, $filename, $size );
        } else {
            $result = $this->single_put( $local_path, $filename, $size );
        }

        if ( ! $result['ok'] ) {
            return $result;
        }

        // Optionally delete local copy
        if ( $this->settings->nextcloud_keep_local === false ) {
            @unlink( $local_path );
        }

        return [
            'ok'          => true,
            'message'     => sprintf(
                'Fichier uploadé sur Nextcloud (%s, %s).',
                size_format( $size ),
                $size > self::CHUNK_THRESHOLD
                    ? ceil( $size / self::CHUNK_SIZE ) . ' morceaux'
                    : 'envoi unique'
            ),
            'remote_path' => $this->dav_url( $filename ),
        ];
    }

    /**
     * Deletes a file from Nextcloud via WebDAV DELETE.
     */
    public function delete( string $filename ): bool {
        $response = wp_remote_request(
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
            return [ 'ok' => false, 'message' => 'URL Nextcloud manquante.' ];
        }
        if ( empty( $this->settings->nextcloud_user ) ) {
            return [ 'ok' => false, 'message' => 'Nom d\'utilisateur manquant.' ];
        }
        if ( empty( $this->settings->nextcloud_pass ) ) {
            return [ 'ok' => false, 'message' => 'Mot de passe / token manquant.' ];
        }

        $response = wp_remote_request(
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
            return [ 'ok' => false, 'message' => 'Erreur réseau : ' . $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 207 ) {
            return [ 'ok' => true, 'message' => 'Connexion Nextcloud réussie. Dossier accessible.' ];
        }
        if ( $code === 401 ) {
            return [ 'ok' => false, 'message' => 'Authentification refusée — vérifiez le token d\'application.' ];
        }
        if ( $code === 404 ) {
            $created = $this->ensure_remote_dir();
            return $created
                ? [ 'ok' => true,  'message' => 'Connexion réussie. Dossier créé sur Nextcloud.' ]
                : [ 'ok' => false, 'message' => 'Dossier distant introuvable et impossible à créer (HTTP 404).' ];
        }

        return [ 'ok' => false, 'message' => 'Réponse inattendue du serveur Nextcloud (HTTP ' . $code . ').' ];
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
    private function single_put( string $local_path, string $filename, int $size ): array {
        if ( ! function_exists( 'curl_init' ) ) {
            return $this->wp_put_small( $local_path, $filename );
        }

        $handle = fopen( $local_path, 'rb' );
        if ( ! $handle ) {
            return [ 'ok' => false, 'message' => 'Impossible d\'ouvrir le fichier local.', 'remote_path' => '' ];
        }

        $url = $this->dav_url( $filename );
        $ch  = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $this->curl_auth_headers(),
            CURLOPT_SSL_VERIFYPEER => true,
        ] );

        curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error     = curl_error( $ch );
        curl_close( $ch );
        fclose( $handle );

        if ( $error ) {
            return [ 'ok' => false, 'message' => 'Erreur cURL : ' . $error, 'remote_path' => '' ];
        }

        $ok = in_array( $http_code, [ 200, 201, 204 ], true );
        return [
            'ok'          => $ok,
            'message'     => $ok ? 'Upload réussi.' : 'HTTP ' . $http_code . ' lors du PUT.',
            'remote_path' => $url,
        ];
    }

    /**
     * Nextcloud chunked upload for files > CHUNK_THRESHOLD.
     *
     * Protocol: https://docs.nextcloud.com/server/latest/developer_manual/
     *           client_apis/WebDAV/chunkedupload.html
     *
     * Step 1 — MKCOL /remote.php/dav/uploads/{user}/{upload_id}
     * Step 2 — PUT   /remote.php/dav/uploads/{user}/{upload_id}/{offset}  (× N)
     * Step 3 — MOVE  /remote.php/dav/uploads/{user}/{upload_id}/.file
     *          Destination: /remote.php/dav/files/{user}/{path}/{filename}
     */
    private function chunked_upload( string $local_path, string $filename, int $size ): array {
        if ( ! function_exists( 'curl_init' ) ) {
            // cURL is mandatory for chunked upload — no safe fallback for 3 GB files
            return [
                'ok'          => false,
                'message'     => 'L\'extension PHP cURL est requise pour l\'upload de fichiers volumineux.',
                'remote_path' => '',
            ];
        }

        $base      = rtrim( (string) $this->settings->nextcloud_url, '/' );
        $user      = rawurlencode( (string) $this->settings->nextcloud_user );
        $upload_id = 'wpcm_' . wp_generate_password( 16, false ) . '_' . WPCM_Date::utc_now();

        $uploads_base = $base . '/remote.php/dav/uploads/' . $user . '/';
        $session_url  = $uploads_base . rawurlencode( $upload_id ) . '/';

        // ── Step 1: Create upload session (MKCOL) ───────────────────────────
        $mkcol = $this->curl_request( 'MKCOL', $session_url, null, 0, [], 30 );
        if ( ! in_array( $mkcol['code'], [ 201, 405 ], true ) ) {
            return [
                'ok'          => false,
                'message'     => 'Impossible de créer la session d\'upload (HTTP ' . $mkcol['code'] . ').',
                'remote_path' => '',
            ];
        }

        // ── Step 2: Upload chunks ────────────────────────────────────────────
        $handle = fopen( $local_path, 'rb' );
        if ( ! $handle ) {
            return [ 'ok' => false, 'message' => 'Impossible d\'ouvrir le fichier.', 'remote_path' => '' ];
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
                        'Échec sur le morceau %d/%d (HTTP %d). Upload annulé.',
                        $chunk_num + 1, $total_chunks, $result['code']
                    ),
                    'remote_path' => '',
                ];
            }

            $offset    += $chunk_size;
            $chunk_num += 1;
        }

        fclose( $handle );

        // ── Step 3: Assemble — MOVE .file to final destination ───────────────
        $path         = trim( (string) $this->settings->nextcloud_path, '/' );
        $dest_path    = '/remote.php/dav/files/' . $user . '/'
                      . ( $path ? rawurlencode( $path ) . '/' : '' )
                      . rawurlencode( $filename );
        $dest_url     = $base . $dest_path;

        $move = $this->curl_request( 'MOVE', $session_url . '.file', null, 0, [
            'Destination'   => $dest_url,
            'Overwrite'     => 'T',
        ], 60 );

        if ( ! in_array( $move['code'], [ 200, 201, 204 ], true ) ) {
            return [
                'ok'          => false,
                'message'     => 'Assemblage échoué (HTTP ' . $move['code'] . '). '
                               . 'Les morceaux sont intacts sur Nextcloud — réessayez.',
                'remote_path' => '',
            ];
        }

        return [
            'ok'          => true,
            'message'     => sprintf(
                'Upload en %d morceaux réussi (%s).',
                $total_chunks,
                size_format( $size )
            ),
            'remote_path' => $dest_url,
        ];
    }

    /**
     * Small-file fallback using wp_remote_request() when cURL is absent.
     * Only for files below CHUNK_THRESHOLD (50 MB).
     */
    private function wp_put_small( string $local_path, string $filename ): array {
        $body = file_get_contents( $local_path );
        if ( $body === false ) {
            return [ 'ok' => false, 'message' => 'Impossible de lire le fichier.', 'remote_path' => '' ];
        }

        $url      = $this->dav_url( $filename );
        $response = wp_remote_request( $url, array_merge( $this->base_args(), [
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
            'message'     => $ok ? 'Upload réussi.' : 'HTTP ' . $code . ' lors du PUT.',
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
            CURLOPT_FOLLOWLOCATION => false, // never follow redirects on DAV
        ];

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

            $check = wp_remote_request( $url, array_merge( $this->base_args(), [
                'method'  => 'PROPFIND',
                'headers' => array_merge( $this->base_args()['headers'], [ 'Depth' => '0' ] ),
            ] ) );

            if ( ! is_wp_error( $check ) && wp_remote_retrieve_response_code( $check ) === 207 ) {
                continue;
            }

            $mkcol = wp_remote_request( $url, array_merge( $this->base_args(), [ 'method' => 'MKCOL' ] ) );
            if ( is_wp_error( $mkcol ) ) return false;

            $code = wp_remote_retrieve_response_code( $mkcol );
            if ( ! in_array( $code, [ 201, 301, 405 ], true ) ) return false;
        }

        return true;
    }
}
