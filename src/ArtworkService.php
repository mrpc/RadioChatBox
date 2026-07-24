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
    private const NEGATIVE_TTL = 600;     // 10 min — short, so transient failures self-heal
    private const HTTP_TIMEOUT = 5;
    private const THUMB_MAX = 100;        // max thumbnail dimension (px)

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
                    return $data + ['cover' => null, 'cover_thumb' => null, 'artist_image' => null, 'artist_image_thumb' => null, 'source' => null];
                }
            } else {
                // Negative cache marker
                return ['cover' => null, 'cover_thumb' => null, 'artist_image' => null, 'artist_image_thumb' => null, 'source' => null];
            }
        }

        $result = $this->lookupAndStore($artist, $title, $query);

        if ($result['cover'] !== null || $result['artist_image'] !== null) {
            $this->redis->setex($cacheKey, self::POSITIVE_TTL, json_encode($result));
        } elseif (($result['source'] ?? null) === null) {
            // Genuine no-match from every provider → cache negative briefly.
            // If a provider DID match but the image failed to download (e.g. the
            // uploads dir isn't writable), don't cache — so it retries once fixed.
            $this->redis->setex($cacheKey, self::NEGATIVE_TTL, '0');
        }

        return $result;
    }

    /** Artist image only (for the artist drill-down view). */
    public function getArtistImage(string $artist): array
    {
        $artist = trim($artist);
        if ($artist === '') {
            return ['artist_image' => null, 'artist_image_thumb' => null, 'source' => null];
        }
        $cacheKey = $this->prefix . 'artwork:artist:' . md5(mb_strtolower($artist));
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data) && (empty($data['artist_image']) || $this->webPathExists($data['artist_image']))) {
                return $data + ['artist_image' => null, 'artist_image_thumb' => null, 'source' => null];
            }
            if (!is_array($data)) {
                return ['artist_image' => null, 'artist_image_thumb' => null, 'source' => null];
            }
        }

        $image = null;
        $thumb = null;
        $source = null;
        $deezer = $this->deezerArtistSearch($artist);
        if ($deezer && !empty($deezer['artist_picture'])) {
            $arr = $this->downloadArtistImage($artist, $deezer['artist_picture']);
            $image = $arr['full'];
            $thumb = $arr['thumb'];
            $source = 'deezer';
        }

        $result = ['artist_image' => $image, 'artist_image_thumb' => $thumb, 'source' => $source];
        if ($image === null) {
            $this->redis->setex($cacheKey, self::NEGATIVE_TTL, '0');
        } else {
            $this->redis->setex($cacheKey, self::POSITIVE_TTL, json_encode($result));
        }
        return $result;
    }

    /**
     * Deep metadata for enrichment: album, genre, release date, external ids/url
     * plus downloaded cover + artist images. Makes up to two Deezer calls
     * (search + album) and falls back to iTunes for the cover only.
     *
     * @return array<string, mixed>
     */
    public function getDeepMeta(string $artist, string $title): array
    {
        $artist = trim($artist);
        $title = trim($title);
        $query = trim($artist . ' ' . $title);

        $out = [
            'cover' => null, 'cover_thumb' => null,
            'artist_image' => null, 'artist_image_thumb' => null,
            'album_title' => null, 'genre' => null, 'release_date' => null,
            'source' => null, 'track_external_id' => null, 'album_external_id' => null,
            'artist_external_id' => null, 'track_url' => null, 'artist_name' => null,
        ];
        if ($query === '') {
            return $out;
        }

        $json = $this->httpGet('https://api.deezer.com/search?limit=1&q=' . rawurlencode($query));
        $item = null;
        if ($json !== null) {
            $d = json_decode($json, true);
            $item = $d['data'][0] ?? null;
        }

        if (!is_array($item)) {
            // No Deezer match: still try to get a cover (iTunes) via getArtwork.
            $art = $this->getArtwork($artist, $title);
            return array_merge($out, [
                'cover' => $art['cover'], 'cover_thumb' => $art['cover_thumb'],
                'artist_image' => $art['artist_image'], 'artist_image_thumb' => $art['artist_image_thumb'],
                'source' => $art['source'],
            ]);
        }

        $out['source'] = 'deezer';
        $out['artist_name'] = $item['artist']['name'] ?? null;
        $out['track_external_id'] = isset($item['id']) ? (string)$item['id'] : null;
        $out['track_url'] = $item['link'] ?? null;
        $out['album_title'] = $item['album']['title'] ?? null;
        $out['album_external_id'] = isset($item['album']['id']) ? (string)$item['album']['id'] : null;
        $out['artist_external_id'] = isset($item['artist']['id']) ? (string)$item['artist']['id'] : null;

        $coverUrl = $item['album']['cover_xl'] ?? $item['album']['cover_big'] ?? null;
        if ($coverUrl) {
            $c = $this->downloadImage($coverUrl, md5(mb_strtolower($query)));
            $out['cover'] = $c['full'];
            $out['cover_thumb'] = $c['thumb'];
        }
        $picUrl = $item['artist']['picture_xl'] ?? $item['artist']['picture_big'] ?? null;
        if ($picUrl && $artist !== '') {
            $a = $this->downloadArtistImage($artist, $picUrl);
            $out['artist_image'] = $a['full'];
            $out['artist_image_thumb'] = $a['thumb'];
        }

        // Deep: album details for genre + release date.
        $albumId = $item['album']['id'] ?? null;
        if ($albumId) {
            $aj = $this->httpGet('https://api.deezer.com/album/' . rawurlencode((string)$albumId));
            if ($aj !== null) {
                $ad = json_decode($aj, true);
                if (is_array($ad)) {
                    $rd = $ad['release_date'] ?? null;
                    if (is_string($rd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd) && strpos($rd, '0000') !== 0) {
                        $out['release_date'] = $rd;
                    }
                    $out['genre'] = $ad['genres']['data'][0]['name'] ?? null;
                }
            }
        }

        return $out;
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

        $coverArr = $coverUrl ? $this->downloadImage($coverUrl, md5(mb_strtolower($query))) : ['full' => null, 'thumb' => null];
        $artistArr = ($artistPicUrl && $artist !== '') ? $this->downloadArtistImage($artist, $artistPicUrl) : ['full' => null, 'thumb' => null];

        return [
            'cover' => $coverArr['full'],
            'cover_thumb' => $coverArr['thumb'],
            'artist_image' => $artistArr['full'],
            'artist_image_thumb' => $artistArr['thumb'],
            'source' => $source,
        ];
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

    /** @return array{full:?string, thumb:?string} */
    private function downloadImage(string $url, string $basename): array
    {
        return $this->store($url, $basename . '.jpg');
    }

    /** @return array{full:?string, thumb:?string} */
    private function downloadArtistImage(string $artist, string $url): array
    {
        return $this->store($url, 'artist/' . md5(mb_strtolower($artist)) . '.jpg');
    }

    /**
     * Ensure a local copy AND a thumbnail of $url exist at $file (relative to
     * the artwork dir). Returns web paths for both.
     *
     * @return array{full:?string, thumb:?string}
     */
    private function store(string $url, string $file): array
    {
        $disk = $this->diskDir . '/' . $file;
        $web = $this->webBase . '/' . $file;
        $thumbFile = $this->thumbName($file);
        $thumbDisk = $this->diskDir . '/' . $thumbFile;
        $thumbWeb = $this->webBase . '/' . $thumbFile;

        if (!is_file($disk) && !$this->saveImage($url, $disk)) {
            return ['full' => null, 'thumb' => null];
        }
        if (!is_file($thumbDisk)) {
            $this->makeThumbnail($disk, $thumbDisk);
        }

        return [
            'full' => $web,
            // Fall back to the full image if thumbnail generation failed.
            'thumb' => is_file($thumbDisk) ? $thumbWeb : $web,
        ];
    }

    private function thumbName(string $file): string
    {
        return preg_replace('/\.jpg$/i', '_thumb.jpg', $file);
    }

    /**
     * Save an admin-uploaded image (raw bytes) into the artwork dir as JPEG,
     * generating a thumbnail. Returns web paths.
     *
     * @return array{full:?string, thumb:?string}
     */
    public function saveUploadedImage(string $bytes, string $subdir = 'uploads'): array
    {
        if (@getimagesizefromstring($bytes) === false || !function_exists('imagecreatefromstring')) {
            return ['full' => null, 'thumb' => null];
        }
        $dir = $this->diskDir . '/' . $subdir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        try {
            $img = @imagecreatefromstring($bytes);
            if ($img === false) {
                return ['full' => null, 'thumb' => null];
            }
            $file = $subdir . '/' . bin2hex(random_bytes(8)) . '.jpg';
            $disk = $this->diskDir . '/' . $file;
            @imagejpeg($img, $disk, 90);
            imagedestroy($img);
            @chmod($disk, 0644);

            $thumbDisk = $this->diskDir . '/' . $this->thumbName($file);
            $this->makeThumbnail($disk, $thumbDisk);

            return [
                'full' => $this->webBase . '/' . $file,
                'thumb' => is_file($thumbDisk) ? $this->webBase . '/' . $this->thumbName($file) : $this->webBase . '/' . $file,
            ];
        } catch (\Throwable $e) {
            error_log('ArtworkService::saveUploadedImage failed: ' . $e->getMessage());
            return ['full' => null, 'thumb' => null];
        }
    }

    private function saveImage(string $url, string $disk): bool
    {
        $bytes = $this->httpGet($url, true);
        if ($bytes === null || strlen($bytes) < 100) {
            return false;
        }
        // Validate it is actually an image.
        if (@getimagesizefromstring($bytes) === false) {
            return false;
        }
        if (@file_put_contents($disk, $bytes) === false) {
            return false;
        }
        @chmod($disk, 0644);
        return true;
    }

    /** Generate a square-ish thumbnail (best-effort; needs GD). */
    private function makeThumbnail(string $srcDisk, string $dstDisk): void
    {
        if (!function_exists('imagecreatefromstring')) {
            return;
        }
        try {
            $info = @getimagesize($srcDisk);
            if ($info === false) {
                return;
            }
            [$w, $h] = $info;
            if ($w <= 0 || $h <= 0) {
                return;
            }
            $src = @imagecreatefromstring((string)@file_get_contents($srcDisk));
            if ($src === false) {
                return;
            }
            $scale = min(1.0, self::THUMB_MAX / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            @imagejpeg($dst, $dstDisk, 85);
            @chmod($dstDisk, 0644);
            imagedestroy($src);
            imagedestroy($dst);
        } catch (\Throwable $e) {
            // Best-effort: the full image is used as a fallback.
        }
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
