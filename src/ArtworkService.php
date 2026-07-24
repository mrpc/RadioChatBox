<?php

namespace RadioChatBox;

/**
 * Fetches album cover art and artist images for tracks and stores them locally.
 *
 * Sources (both keyless): Deezer first (cover + artist photo), iTunes Search as
 * a fallback (cover only). Images are downloaded once into public/uploads/artwork
 * and served from there, so we never hotlink an external URL, don't leak every
 * view to the provider, and keep serving fast. Lookups are cached in Redis
 * (positive + negative) so we don't re-hit the APIs.
 */
class ArtworkService
{
    private \Redis $redis;
    private string $prefix;
    private string $diskDir;
    private string $webBase = '/uploads/artwork';

    private const POSITIVE_TTL = 2592000; // 30 days
    private const NEGATIVE_TTL = 86400;   // 1 day
    private const HTTP_TIMEOUT = 5;

    public function __construct()
    {
        $this->redis = Database::getRedis();
        $this->prefix = Database::getRedisPrefix();
        $this->diskDir = __DIR__ . '/../public/uploads/artwork';
        if (!is_dir($this->diskDir)) {
            @mkdir($this->diskDir, 0755, true);
        }
        if (!is_dir($this->diskDir . '/artist')) {
            @mkdir($this->diskDir . '/artist', 0755, true);
        }
    }

    /**
     * Get artwork for a track (album cover + artist image), downloading and
     * caching locally on first request.
     *
     * @return array{cover:?string, artist_image:?string, source:?string}
     */
    public function getArtwork(string $artist, string $title): array
    {
        $artist = trim($artist);
        $title = trim($title);
        $query = trim($artist . ' ' . $title);
        if ($query === '') {
            return ['cover' => null, 'artist_image' => null, 'source' => null];
        }

        $cacheKey = $this->prefix . 'artwork:' . md5(mb_strtolower($query));

        // Positive cache
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data)) {
                // Verify the cover file still exists; if deleted, fall through.
                if (empty($data['cover']) || $this->webPathExists($data['cover'])) {
                    return $data + ['cover' => null, 'artist_image' => null, 'source' => null];
                }
            } else {
                // Negative cache marker
                return ['cover' => null, 'artist_image' => null, 'source' => null];
            }
        }

        $result = $this->lookupAndStore($artist, $title, $query);

        if ($result['cover'] === null && $result['artist_image'] === null) {
            $this->redis->setex($cacheKey, self::NEGATIVE_TTL, '0'); // negative
        } else {
            $this->redis->setex($cacheKey, self::POSITIVE_TTL, json_encode($result));
        }

        return $result;
    }

    /** Artist image only (for the artist drill-down view). */
    public function getArtistImage(string $artist): array
    {
        $artist = trim($artist);
        if ($artist === '') {
            return ['artist_image' => null, 'source' => null];
        }
        $cacheKey = $this->prefix . 'artwork:artist:' . md5(mb_strtolower($artist));
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data) && (empty($data['artist_image']) || $this->webPathExists($data['artist_image']))) {
                return $data + ['artist_image' => null, 'source' => null];
            }
            if (!is_array($data)) {
                return ['artist_image' => null, 'source' => null];
            }
        }

        $image = null;
        $source = null;
        $deezer = $this->deezerArtistSearch($artist);
        if ($deezer && !empty($deezer['artist_picture'])) {
            $image = $this->downloadArtistImage($artist, $deezer['artist_picture']);
            $source = 'deezer';
        }

        $result = ['artist_image' => $image, 'source' => $source];
        if ($image === null) {
            $this->redis->setex($cacheKey, self::NEGATIVE_TTL, '0');
        } else {
            $this->redis->setex($cacheKey, self::POSITIVE_TTL, json_encode($result));
        }
        return $result;
    }

    private function lookupAndStore(string $artist, string $title, string $query): array
    {
        $coverUrl = null;
        $artistPicUrl = null;
        $source = null;

        // 1) Deezer (cover + artist photo)
        $deezer = $this->deezerTrackSearch($query);
        if ($deezer) {
            $coverUrl = $deezer['cover'] ?? null;
            $artistPicUrl = $deezer['artist_picture'] ?? null;
            if ($coverUrl) {
                $source = 'deezer';
            }
        }

        // 2) iTunes fallback (cover only)
        if ($coverUrl === null) {
            $itunes = $this->itunesSearch($query);
            if ($itunes && !empty($itunes['cover'])) {
                $coverUrl = $itunes['cover'];
                $source = 'itunes';
            }
        }

        $coverLocal = $coverUrl ? $this->downloadImage($coverUrl, md5(mb_strtolower($query))) : null;
        $artistLocal = ($artistPicUrl && $artist !== '') ? $this->downloadArtistImage($artist, $artistPicUrl) : null;

        return ['cover' => $coverLocal, 'artist_image' => $artistLocal, 'source' => $source];
    }

    // ---- external searches ----

    private function deezerTrackSearch(string $query): ?array
    {
        $json = $this->httpGet('https://api.deezer.com/search?limit=1&q=' . rawurlencode($query));
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        $item = $data['data'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }
        return [
            'cover' => $item['album']['cover_xl'] ?? $item['album']['cover_big'] ?? null,
            'artist_picture' => $item['artist']['picture_xl'] ?? $item['artist']['picture_big'] ?? null,
        ];
    }

    private function deezerArtistSearch(string $artist): ?array
    {
        $json = $this->httpGet('https://api.deezer.com/search/artist?limit=1&q=' . rawurlencode($artist));
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        $item = $data['data'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }
        return ['artist_picture' => $item['picture_xl'] ?? $item['picture_big'] ?? null];
    }

    private function itunesSearch(string $query): ?array
    {
        $json = $this->httpGet('https://itunes.apple.com/search?entity=song&limit=1&term=' . rawurlencode($query));
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        $item = $data['results'][0] ?? null;
        if (!is_array($item) || empty($item['artworkUrl100'])) {
            return null;
        }
        // Upscale the 100x100 thumbnail to a larger version.
        $cover = str_replace('100x100bb', '600x600bb', $item['artworkUrl100']);
        return ['cover' => $cover];
    }

    // ---- download / storage ----

    private function downloadImage(string $url, string $basename): ?string
    {
        $file = $basename . '.jpg';
        $disk = $this->diskDir . '/' . $file;
        $web = $this->webBase . '/' . $file;
        if (is_file($disk)) {
            return $web;
        }
        return $this->saveImage($url, $disk, $web);
    }

    private function downloadArtistImage(string $artist, string $url): ?string
    {
        $file = 'artist/' . md5(mb_strtolower($artist)) . '.jpg';
        $disk = $this->diskDir . '/' . $file;
        $web = $this->webBase . '/' . $file;
        if (is_file($disk)) {
            return $web;
        }
        return $this->saveImage($url, $disk, $web);
    }

    private function saveImage(string $url, string $disk, string $web): ?string
    {
        $bytes = $this->httpGet($url, true);
        if ($bytes === null || strlen($bytes) < 100) {
            return null;
        }
        // Validate it is actually an image.
        if (@getimagesizefromstring($bytes) === false) {
            return null;
        }
        if (@file_put_contents($disk, $bytes) === false) {
            return null;
        }
        @chmod($disk, 0644);
        return $web;
    }

    private function webPathExists(string $webPath): bool
    {
        $rel = ltrim(str_replace($this->webBase, '', $webPath), '/');
        return is_file($this->diskDir . '/' . $rel);
    }

    /**
     * Minimal HTTP GET with timeout. Returns body string or null on failure.
     */
    private function httpGet(string $url, bool $binary = false): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_USERAGENT => 'RadioChatBox/1.0 (+artwork)',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                return null;
            }
            return $body;
        }

        $ctx = stream_context_create(['http' => [
            'timeout' => self::HTTP_TIMEOUT,
            'header' => "User-Agent: RadioChatBox/1.0 (+artwork)\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
