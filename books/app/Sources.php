<?php
declare(strict_types=1);

/**
 * Where the Calibre library comes from. Paths passed in are relative to the
 * library root, e.g. "Will Ferguson/Road Trip Rwanda (1414)/cover.jpg".
 */
interface LibrarySource
{
    /** A string that changes whenever metadata.db changes. */
    public function dbVersion(): string;

    /** Save the current metadata.db to $dest. */
    public function downloadDb(string $dest): void;

    /**
     * Fetch cover thumbnails (JPEG, up to 480x640).
     * @param string[] $paths up to 25 relative paths
     * @return array<string, string|null> path => JPEG bytes, or null when that file failed
     */
    public function fetchCovers(array $paths): array;

    /** Human-readable description for the admin page. */
    public function describe(): string;
}

const COVER_MAX_W = 480;
const COVER_MAX_H = 640;

/** Reads a Calibre library folder on this server. Also used for testing. */
final class LocalSource implements LibrarySource
{
    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function describe(): string
    {
        return 'Folder on this server: ' . $this->root;
    }

    public function dbVersion(): string
    {
        $f = $this->root . '/metadata.db';
        clearstatcache(true, $f);
        if (!is_file($f)) {
            throw new RuntimeException("metadata.db not found in {$this->root}");
        }
        return filemtime($f) . '-' . filesize($f);
    }

    public function downloadDb(string $dest): void
    {
        if (!copy($this->root . '/metadata.db', $dest)) {
            throw new RuntimeException('Could not copy metadata.db');
        }
    }

    public function fetchCovers(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $f = $this->root . '/' . $p;
            $out[$p] = is_file($f) ? resize_jpeg((string) file_get_contents($f)) : null;
        }
        return $out;
    }
}

/** Shrink a JPEG to fit COVER_MAX_W x COVER_MAX_H. Returns the input unchanged if GD is missing. */
function resize_jpeg(string $bytes): ?string
{
    if ($bytes === '') {
        return null;
    }
    if (!function_exists('imagecreatefromstring')) {
        return $bytes;
    }
    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(COVER_MAX_W / $w, COVER_MAX_H / $h, 1);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($dst, null, 82);
    $out = (string) ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    return $out;
}

/** Reads the library from Dropbox using the HTTP API and a long-lived refresh token. */
final class DropboxSource implements LibrarySource
{
    private string $apiHost;
    private string $contentHost;
    private ?string $accessToken = null;
    private int $accessExpires = 0;

    public function __construct(
        private string $appKey,
        private string $appSecret,
        private string $refreshToken,
        private string $libraryPath,
        private string $cacheFile,
        array $hosts = []
    ) {
        $this->libraryPath = '/' . trim($libraryPath, '/');
        $this->apiHost = $hosts['api'] ?? 'https://api.dropboxapi.com';
        $this->contentHost = $hosts['content'] ?? 'https://content.dropboxapi.com';
    }

    public function describe(): string
    {
        return 'Dropbox: ' . $this->libraryPath;
    }

    public function dbVersion(): string
    {
        $meta = $this->rpc('/2/files/get_metadata', ['path' => $this->libraryPath . '/metadata.db']);
        if (empty($meta['rev'])) {
            throw new RuntimeException('Dropbox did not return a revision for metadata.db');
        }
        return (string) ($meta['content_hash'] ?? $meta['rev']);
    }

    public function downloadDb(string $dest): void
    {
        $fh = fopen($dest, 'wb');
        if (!$fh) {
            throw new RuntimeException("Cannot write $dest");
        }
        $arg = json_encode(['path' => $this->libraryPath . '/metadata.db']);
        [$status, $body] = $this->request(
            $this->contentHost . '/2/files/download',
            ['Dropbox-API-Arg: ' . $arg],
            '',
            $fh
        );
        fclose($fh);
        if ($status !== 200) {
            @unlink($dest);
            throw new RuntimeException("Downloading metadata.db failed (HTTP $status): " . substr((string) $body, 0, 300));
        }
    }

    public function fetchCovers(array $paths): array
    {
        $paths = array_values($paths);
        $entries = [];
        foreach ($paths as $p) {
            $entries[] = [
                'path'   => $this->libraryPath . '/' . $p,
                'format' => 'jpeg',
                'size'   => 'w640h480',
                'mode'   => 'bestfit',
            ];
        }
        $res = $this->rpc('/2/files/get_thumbnail_batch', ['entries' => $entries], $this->contentHost);
        $out = array_fill_keys($paths, null);
        foreach (($res['entries'] ?? []) as $i => $entry) {
            if (!isset($paths[$i])) {
                continue;
            }
            if (($entry['.tag'] ?? '') === 'success' && !empty($entry['thumbnail'])) {
                $bytes = base64_decode($entry['thumbnail'], true);
                $out[$paths[$i]] = $bytes === false ? null : $bytes;
            }
        }
        return $out;
    }

    /**
     * A short-lived direct download address for one file in the library.
     * Dropbox keeps the link valid for four hours. Needs only the read
     * permission the site already has.
     * @return array{link:string,size:?int}
     */
    public function temporaryLink(string $relPath): array
    {
        $r = $this->rpc('/2/files/get_temporary_link', ['path' => $this->libraryPath . '/' . ltrim($relPath, '/')]);
        if (empty($r['link'])) {
            throw new RuntimeException('Dropbox did not return a download link for ' . $relPath);
        }
        $size = $r['metadata']['size'] ?? null;
        return ['link' => (string) $r['link'], 'size' => $size === null ? null : (int) $size];
    }

    /** Account name, used by the admin page to confirm the connection works. */
    public function accountName(): string
    {
        $r = $this->rpc('/2/users/get_current_account', null);
        return (string) ($r['name']['display_name'] ?? $r['email'] ?? 'unknown');
    }

    // ---- OAuth -----------------------------------------------------------

    public static function authorizeUrl(string $appKey): string
    {
        return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id'         => $appKey,
            'response_type'     => 'code',
            'token_access_type' => 'offline',
        ]);
    }

    /** Swap the one-time code from Dropbox for a refresh token. */
    public static function exchangeCode(string $appKey, string $appSecret, string $code, array $hosts = []): string
    {
        $api = $hosts['api'] ?? 'https://api.dropboxapi.com';
        [$status, $body] = http_post($api . '/oauth2/token', [], http_build_query([
            'code'          => trim($code),
            'grant_type'    => 'authorization_code',
            'client_id'     => $appKey,
            'client_secret' => $appSecret,
        ]), 'application/x-www-form-urlencoded');
        $json = json_decode((string) $body, true);
        if ($status !== 200 || empty($json['refresh_token'])) {
            $msg = $json['error_description'] ?? $json['error'] ?? substr((string) $body, 0, 200);
            throw new RuntimeException("Dropbox rejected the code: $msg");
        }
        return $json['refresh_token'];
    }

    private function token(): string
    {
        if ($this->accessToken && time() < $this->accessExpires - 60) {
            return $this->accessToken;
        }
        if (is_file($this->cacheFile)) {
            $c = json_decode((string) file_get_contents($this->cacheFile), true);
            if (!empty($c['token']) && ($c['expires'] ?? 0) > time() + 60) {
                $this->accessToken = $c['token'];
                $this->accessExpires = (int) $c['expires'];
                return $this->accessToken;
            }
        }
        [$status, $body] = http_post($this->apiHost . '/oauth2/token', [], http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id'     => $this->appKey,
            'client_secret' => $this->appSecret,
        ]), 'application/x-www-form-urlencoded');
        $json = json_decode((string) $body, true);
        if ($status !== 200 || empty($json['access_token'])) {
            $msg = $json['error_description'] ?? $json['error'] ?? substr((string) $body, 0, 200);
            throw new RuntimeException("Could not refresh the Dropbox token: $msg. Reconnect Dropbox on the admin page.");
        }
        $this->accessToken = $json['access_token'];
        $this->accessExpires = time() + (int) ($json['expires_in'] ?? 14400);
        @file_put_contents($this->cacheFile, json_encode(['token' => $this->accessToken, 'expires' => $this->accessExpires]), LOCK_EX);
        @chmod($this->cacheFile, 0600);
        return $this->accessToken;
    }

    private function rpc(string $endpoint, ?array $args, ?string $host = null): array
    {
        $url = ($host ?? $this->apiHost) . $endpoint;
        $payload = $args === null ? 'null' : json_encode($args);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            [$status, $body, $headers] = $this->request($url, ['Content-Type: application/json'], $payload);
            if ($status === 429 || $status >= 500) {
                $wait = (int) ($headers['retry-after'] ?? 0) ?: 2 * $attempt;
                sleep(min($wait, 30));
                continue;
            }
            break;
        }
        $json = json_decode((string) $body, true);
        if ($status !== 200 || !is_array($json)) {
            $summary = is_array($json) ? ($json['error_summary'] ?? '') : substr((string) $body, 0, 300);
            throw new RuntimeException("Dropbox $endpoint failed (HTTP $status): $summary");
        }
        return $json;
    }

    /** @return array{0:int,1:string|bool,2:array} */
    private function request(string $url, array $headers, string $body, $sink = null): array
    {
        $headers[] = 'Authorization: Bearer ' . $this->token();
        return http_post($url, $headers, $body, null, $sink);
    }
}

/**
 * Minimal POST helper built on curl.
 * @return array{0:int,1:string|bool,2:array<string,string>} status, body, lower-cased response headers
 */
function http_post(string $url, array $headers, string $body, ?string $contentType = null, $sink = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP curl extension is not available on this server.');
    }
    $respHeaders = [];
    $ch = curl_init($url);
    if ($contentType) {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    $hasType = false;
    foreach ($headers as $h) {
        if (stripos($h, 'content-type:') === 0) {
            $hasType = true;
        }
    }
    if (!$hasType) {
        // Dropbox content endpoints want an empty body with this type.
        $headers[] = 'Content-Type: application/octet-stream';
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($sink) {
        curl_setopt($ch, CURLOPT_FILE, $sink);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }
    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Network error contacting $url: $err");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $sink ? '' : $result, $respHeaders];
}

/**
 * Pass a remote file straight through to the visitor without holding it in
 * memory. $begin is called once, just before the first byte goes out, so the
 * caller can send its own headers then. Returns false if the remote server
 * failed before anything was sent, in which case nothing has been output.
 */
function stream_url_to_output(string $url, callable $begin): bool
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP curl extension is not available on this server.');
    }
    $started = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 900,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$started, $begin) {
            if (!$started) {
                if ((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) {
                    return 0; // stop: an error page from the remote end is not the book
                }
                $begin();
                $started = true;
            }
            echo $data;
            flush();
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
    return $started;
}

/** Build the configured source, or explain why it can't be built. */
function make_source(): LibrarySource
{
    $c = $GLOBALS['CONFIG'];
    if (!empty($c['local_library_path'])) {
        return new LocalSource($c['local_library_path']);
    }
    if (empty($c['dropbox_app_key']) || empty($c['dropbox_app_secret'])) {
        throw new RuntimeException('Add your Dropbox app key and secret to app/config.php.');
    }
    $refresh = dropbox_refresh_token();
    if ($refresh === null) {
        throw new RuntimeException('Dropbox is not connected yet. Open admin.php and follow the "Connect Dropbox" steps.');
    }
    return new DropboxSource(
        $c['dropbox_app_key'],
        $c['dropbox_app_secret'],
        $refresh,
        $c['dropbox_library_path'],
        DATA_DIR . '/dropbox-access.json',
        $c['_dropbox_hosts'] ?? []
    );
}

function dropbox_refresh_token(): ?string
{
    $f = DATA_DIR . '/dropbox-token.json';
    if (!is_file($f)) {
        return null;
    }
    $j = json_decode((string) file_get_contents($f), true);
    return $j['refresh_token'] ?? null;
}

function save_dropbox_refresh_token(?string $token): void
{
    ensure_data_dir();
    $f = DATA_DIR . '/dropbox-token.json';
    @unlink(DATA_DIR . '/dropbox-access.json');
    if ($token === null) {
        @unlink($f);
        return;
    }
    file_put_contents($f, json_encode(['refresh_token' => $token, 'saved' => date('c')]), LOCK_EX);
    @chmod($f, 0600);
}
