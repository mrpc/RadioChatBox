<?php

namespace RadioChatBox;

class RadioStatusService
{
    private SettingsService $settings;
    private \Redis $redis;
    private string $prefix;

    private const CACHE_KEY = 'radio:now_playing';
    private const CACHE_TTL = 10; // seconds

    public function __construct()
    {
        $this->settings = new SettingsService();
        $this->redis = Database::getRedis();
        $this->prefix = Database::getRedisPrefix();
    }

    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Fetch now playing info, cached for short TTL.
     * @return array{active:bool, display:string|null, artist:string|null, title:string|null}
     */
    public function getNowPlaying(): array
    {
        $url = trim((string)($this->settings->get('radio_status_url', '')));
        if ($url === '') {
            return [
                'active' => false,
                'display' => null,
                'artist' => null,
                'title' => null,
            ];
        }

        // Check cache first
        $cached = $this->redis->get($this->prefixKey(self::CACHE_KEY));
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data)) {
                return $data;
            }
        }

        $parsed = $this->fetchAndParse($url);
        // Cache parsed result briefly
        $this->redis->setex($this->prefixKey(self::CACHE_KEY), self::CACHE_TTL, json_encode($parsed));
        return $parsed;
    }

    /**
     * Fetch remote JSON and try to parse common Icecast/Shoutcast fields.
     */
    private function fetchAndParse(string $url): array
    {
        $json = $this->httpGet($url);
        if ($json === null) {
            return [
                'active' => false,
                'display' => null,
                'artist' => null,
                'title' => null,
            ];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'active' => false,
                'display' => null,
                'artist' => null,
                'title' => null,
            ];
        }

        // Heuristics:
        // Icecast: { icestats: { source: { title: "Artist - Title" } } } or source[].
        // Shoutcast: fields like songtitle, currentSong, now_playing, or stream title.
        $artist = null;
        $title = null;
        $display = null;

        // Try Icecast structure
        if (isset($data['icestats'])) {
            $ice = $data['icestats'];
            $source = null;
            if (isset($ice['source'])) {
                $source = $ice['source'];
                // Source may be array or object
                if (is_array($source) && isset($source[0])) {
                    $source = $source[0];
                }
            }
            if (is_array($source)) {
                // Common field: 'title' contains "Artist - Title"
                if (!empty($source['title']) && is_string($source['title'])) {
                    $display = $this->stripBitrate(trim($source['title']));
                    [$artistGuess, $titleGuess] = $this->splitArtistTitle($display);
                    $artist = $artistGuess;
                    $title = $titleGuess;
                }
                // Alternate fields
                if (!$display) {
                    $artist = isset($source['artist']) && is_string($source['artist']) ? trim($source['artist']) : $artist;
                    $title = isset($source['song']) && is_string($source['song']) ? trim($source['song']) : $title;
                    if (!$title && isset($source['title']) && is_string($source['title'])) {
                        $title = $this->stripBitrate(trim($source['title']));
                    }
                    if ($artist && $title) {
                        $display = $artist . ' - ' . $title;
                    } elseif ($title) {
                        $display = $title;
                    }
                }
            }
        }

        // Try common Shoutcast-like fields if not found
        if (!$display) {
            $candidates = [];
            // prefer more specific keys
            foreach (['songtitle', 'currentSong', 'now_playing', 'title'] as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $candidates[] = $data[$key];
                }
            }
            if (!$candidates && isset($data['stream']) && is_array($data['stream'])) {
                foreach (['song', 'title', 'now_playing'] as $key) {
                    if (isset($data['stream'][$key]) && is_string($data['stream'][$key])) {
                        $candidates[] = $data['stream'][$key];
                    }
                }
            }
            if (!empty($candidates)) {
                $display = $this->stripBitrate(trim((string)$candidates[0]));
                [$artistGuess, $titleGuess] = $this->splitArtistTitle($display);
                $artist = $artist ?? $artistGuess;
                $title = $title ?? $titleGuess;
            }
        }

        $active = $display !== null && $display !== '';
        return [
            'active' => $active,
            'display' => $active ? $display : null,
            'artist' => $artist,
            'title' => $title,
        ];
    }

    private function stripBitrate(string $s): string
    {
        // Remove bracketed/parenthesized numbers with letters (bitrate, sample rate, etc.)
        // Patterns: [45k], [44T], [128kbps], (192), etc.
        $s = preg_replace('/\s*\[\s*\d+[a-z]*\s*\]/i', '', $s);
        $s = preg_replace('/\s*\(\s*\d+[a-z]*\s*\)/i', '', $s);
        // Also strip trailing digits with letters (e.g. "192k" or "44T" at end)
        $s = preg_replace('/\s+\d+[a-z]*$/i', '', $s);
        return trim($s);
    }

    private function splitArtistTitle(string $s): array
    {
        // Strip bitrate info first
        $s = $this->stripBitrate($s);
        
        // Try splitting by common separators
        foreach ([' - ', ' – ', ' — ', ' — ', ' – '] as $sep) {
            if (str_contains($s, $sep)) {
                $parts = array_map('trim', explode($sep, $s, 2));
                if (count($parts) === 2) {
                    return [$parts[0], $parts[1]];
                }
            }
        }
        return [null, null];
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // seconds
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); // total seconds
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RadioChatBox/1.0');

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            return null;
        }
        return is_string($resp) ? $resp : null;
    }
}
