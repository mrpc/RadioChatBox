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
     * @return array{active:bool, display:string|null, artist:string|null, title:string|null, listeners:int|null}
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
                'listeners' => null,
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
                'listeners' => null,
            ];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'active' => false,
                'display' => null,
                'artist' => null,
                'title' => null,
                'listeners' => null,
            ];
        }

        // Heuristics:
        // Icecast: { icestats: { source: { title: "Artist - Title" } } } or source[].
        // Shoutcast: fields like songtitle, currentSong, now_playing, or stream title.
        $artist = null;
        $title = null;
        $display = null;
        $listeners = null;

        // Try Icecast structure
        if (isset($data['icestats'])) {
            $ice = $data['icestats'];
            $source = null;
            if (isset($ice['source'])) {
                $sources = $ice['source'];
                // Source may be array or object
                if (!is_array($sources) || !isset($sources[0])) {
                    // Single source object
                    $source = $sources;
                } else {
                    // Multiple sources - prioritize /live if it has listeners
                    $source = $sources[0]; // default to first
                    foreach ($sources as $s) {
                        if (is_array($s) && isset($s['listenurl']) && 
                            str_contains($s['listenurl'], '/live') && 
                            isset($s['listeners']) && $s['listeners'] > 0) {
                            $source = $s;
                            break;
                        }
                    }
                }
            }
            if (is_array($source)) {
                // Extract listener count
                if (isset($source['listeners']) && is_numeric($source['listeners'])) {
                    $listeners = (int)$source['listeners'];
                }
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
                // Fallback: if we have listeners but no display, use server_name
                if (!$display && $listeners !== null && $listeners > 0) {
                    if (isset($source['server_name']) && is_string($source['server_name'])) {
                        $display = trim($source['server_name']);
                    }
                }
            }
        }

        // Try to extract listeners from common Shoutcast fields if not found
        if ($listeners === null) {
            if (isset($data['listeners']) && is_numeric($data['listeners'])) {
                $listeners = (int)$data['listeners'];
            } elseif (isset($data['currentlisteners']) && is_numeric($data['currentlisteners'])) {
                $listeners = (int)$data['currentlisteners'];
            } elseif (isset($data['stream']) && is_array($data['stream']) && isset($data['stream']['listeners'])) {
                $listeners = (int)$data['stream']['listeners'];
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

        // Optional album + cover art, if the feed provides them (many don't).
        // Scans common rich-now-playing shapes (e.g. AzuraCast now_playing.song).
        $album = null;
        $feedCover = null;
        $np = null;
        if (isset($data['now_playing']) && is_array($data['now_playing'])) {
            $np = $data['now_playing']['song'] ?? $data['now_playing'];
        }
        $scan = [];
        if (is_array($np)) {
            $scan[] = $np;
            if (!$artist && !empty($np['artist']) && is_string($np['artist'])) $artist = trim($np['artist']);
            if (!$title && !empty($np['title']) && is_string($np['title'])) $title = trim($np['title']);
        }
        $scan[] = $data;
        if (isset($data['stream']) && is_array($data['stream'])) $scan[] = $data['stream'];
        foreach ($scan as $obj) {
            if (!is_array($obj)) continue;
            if ($album === null && !empty($obj['album']) && is_string($obj['album'])) {
                $album = trim($obj['album']);
            }
            if ($feedCover === null) {
                foreach (['art', 'artwork', 'cover', 'coverart', 'image'] as $k) {
                    if (!empty($obj[$k]) && is_string($obj[$k]) && preg_match('~^https?://~i', $obj[$k])) {
                        $feedCover = trim($obj[$k]);
                        break;
                    }
                }
            }
        }

        $active = $display !== null && $display !== '';
        return [
            'active' => $active,
            'display' => $active ? $display : null,
            'artist' => $artist,
            'title' => $title,
            'listeners' => $listeners,
            'album' => $active ? $album : null,
            'feed_cover' => $active ? $feedCover : null,
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
