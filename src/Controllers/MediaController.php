<?php

namespace RadioChatBox\Controllers;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\ArtworkService;
use RadioChatBox\Cache;
use RadioChatBox\Database;
use RadioChatBox\RadioStatusService;
use RadioChatBox\TrackStatsService;

/**
 * Media resource controller: cover/artist artwork, rich link previews and the
 * radio now-playing feed.
 *
 * Migrates three legacy file-per-endpoint APIs into one controller, preserving
 * behaviour EXACTLY (same JSON keys, status codes, error mapping and headers):
 *   - public/api/artwork.php       -> GET /api/artwork
 *   - public/api/link-preview.php  -> GET /api/link-preview
 *   - public/api/now-playing.php   -> GET /api/now-playing
 *
 * The framework Request/Response model replaces the legacy header()/echo/exit
 * flow: query input is read from $_GET (via the Request), responses are returned
 * as Pramnos\Http\Response, and CORS/OPTIONS/405 are handled by the router and
 * global middleware (so the legacy CorsHandler::handle()/OPTIONS/405 guards are
 * dropped here per the migration guide).
 */
final class MediaController
{
    /**
     * GET /api/artwork — cover art for a track, or an artist image.
     *
     * Replaces public/api/artwork.php. mode=artist returns {success:true} merged
     * with ArtworkService::getArtistImage() (requires artist); otherwise returns
     * {success:true} merged with getArtwork() (requires artist or title). Missing
     * required input -> 400 {error}; any other failure -> 500
     * {error:'Internal server error'}. Response carries a 1h public cache header.
     */
    #[Route('/api/artwork', methods: 'GET', name: 'media.artwork')]
    public function artwork(): Response
    {
        try {
            $request = Request::getInstance();
            $service = new ArtworkService();
            $mode   = (string) $request->get('mode', 'track', 'get');
            $artist = trim((string) $request->get('artist', '', 'get'));
            $title  = trim((string) $request->get('title', '', 'get'));

            if ($mode === 'artist') {
                if ($artist === '') {
                    throw new InvalidArgumentException('artist is required');
                }
                $payload = ['success' => true] + $service->getArtistImage($artist);
            } else {
                if ($artist === '' && $title === '') {
                    throw new InvalidArgumentException('artist or title is required');
                }
                $payload = ['success' => true] + $service->getArtwork($artist, $title);
            }

            return Response::json($payload)
                ->withHeader('Cache-Control', 'public, max-age=3600');
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400)
                ->withHeader('Cache-Control', 'public, max-age=3600');
        } catch (\Throwable $e) {
            \RadioChatBox\Log::write($e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500)
                ->withHeader('Cache-Control', 'public, max-age=3600');
        }
    }

    /**
     * GET /api/link-preview — Open Graph metadata for a URL (rich chat previews).
     *
     * Replaces public/api/link-preview.php. Validates the url query param
     * (required/valid/http(s)/host), applies SSRF protection (403 for
     * private/reserved IPs), serves a 1h Redis cache when present, fetches the
     * page and parses OG/Twitter/<title> meta. Returns
     * {title, description, image, domain, url}. Error mapping preserved:
     * 400 (missing/invalid url, bad scheme, bad host), 403 (forbidden url),
     * 422 (fetch failed / non-HTML / no preview data).
     */
    #[Route('/api/link-preview', methods: 'GET', name: 'media.link-preview')]
    public function linkPreview(): Response
    {
        $url = (string) Request::getInstance()->get('url', '', 'get');

        if (empty($url)) {
            return Response::json(['error' => 'URL is required'], 400);
        }

        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return Response::json(['error' => 'Invalid URL'], 400);
        }

        // Only allow http and https schemes
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
            return Response::json(['error' => 'Invalid URL scheme'], 400);
        }

        $host = $parsed['host'] ?? '';

        if (empty($host)) {
            return Response::json(['error' => 'Invalid URL host'], 400);
        }

        // SSRF protection: resolve hostname and block private/loopback IP ranges
        $resolvedIp = gethostbyname($host);
        if ($this->isPrivateOrReservedIp($resolvedIp)) {
            return Response::json(['error' => 'Forbidden URL'], 403);
        }

        // Check cache first
        $cacheKey = 'link_preview:' . md5($url);
        try {
            $cached = Cache::store()->get($cacheKey);
            if ($cached !== null) {
                return Response::json($cached);
            }
        } catch (\Exception $e) {
            // Cache backend unavailable — proceed without cache
        }

        // Fetch the page content
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 5,
                'follow_location' => true,
                'max_redirects'   => 3,
                'header'          => implode("\r\n", [
                    'User-Agent: RadioChatBox Link Preview Bot/1.0',
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: en',
                ]),
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $html = @file_get_contents($url, false, $context);

        if ($html === false || strlen($html) === 0) {
            return Response::json(['error' => 'Could not fetch URL'], 422);
        }

        // Check Content-Type from response headers — only parse HTML
        $responseHeaders = $http_response_header ?? [];
        $contentType = '';
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = $header;
                break;
            }
        }
        if (!empty($contentType) && stripos($contentType, 'text/html') === false) {
            return Response::json(['error' => 'URL is not an HTML page'], 422);
        }

        // Parse Open Graph metadata (limit to first 100KB to avoid huge documents)
        $preview = $this->parseOpenGraph(substr($html, 0, 102400), $url);

        if (empty($preview['title'])) {
            // Nothing useful to show
            return Response::json(['error' => 'No preview data available'], 422);
        }

        // Cache the result for 1 hour
        try {
            Cache::store()->set($cacheKey, $preview, 3600);
        } catch (\Exception $e) {
            // Cache backend unavailable — return uncached
        }

        return Response::json($preview);
    }

    /**
     * GET /api/now-playing — current radio track, with de-duplicated play
     * recording and stored track metadata for the hover card.
     *
     * Replaces public/api/now-playing.php. Returns {success:true, nowPlaying:{…}}
     * where nowPlaying gets a `meta` key when a track is active. recordPlay() is
     * de-duplicated (inserts only on a real track change) and its failures are
     * swallowed with an error_log; when the track just changed the new track is
     * enriched (album/genre/release/art). The legacy file flushed the response
     * (fastcgi_finish_request) BEFORE enriching so the client poll wasn't blocked;
     * under the Response model the enrichment runs inline before returning — the
     * payload is identical, only the timing differs. Failure -> 500
     * {success:false, error:'Server error: …'}. No-cache headers preserved.
     */
    #[Route('/api/now-playing', methods: 'GET', name: 'media.now-playing')]
    public function nowPlaying(): Response
    {
        try {
            $svc = new RadioStatusService();
            $now = $svc->getNowPlaying();

            // Record the track for play statistics (de-duplicated: only inserts on
            // an actual track change, guarded against concurrent pollers).
            $newTrackId = null;
            try {
                $newTrackId = (new TrackStatsService())->recordPlay($now);
            } catch (\Exception $e) {
                \RadioChatBox\Log::write('Track play recording failed: ' . $e->getMessage());
            }

            // Attach stored metadata for the current track (for the hover card).
            if (!empty($now['active']) && !empty($now['display'])) {
                try {
                    $now['meta'] = (new TrackStatsService())->getCurrentTrackMeta($now['display']);
                } catch (\Exception $e) {
                    $now['meta'] = null;
                }
            }

            // When the track just changed, enrich it (album/genre/release/art).
            // The legacy endpoint flushed the response first; here it runs inline.
            if ($newTrackId) {
                try {
                    // Pass the feed so any feed-provided album/cover art is used.
                    (new TrackStatsService())->enrichTrack($newTrackId, $now);
                } catch (\Exception $e) {
                    \RadioChatBox\Log::write('Inline track enrichment failed: ' . $e->getMessage());
                }
            }

            return Response::json([
                'success'    => true,
                'nowPlaying' => $now,
            ])
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'error'   => 'Server error: ' . $e->getMessage(),
            ], 500)
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        }
    }

    /**
     * Returns true if the given IP is private, loopback, or reserved (SSRF guard).
     * Ported verbatim from public/api/link-preview.php.
     */
    private function isPrivateOrReservedIp(string $ip): bool
    {
        // If gethostbyname couldn't resolve, it returns the original string (not an IP)
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true; // treat unresolvable as forbidden
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Parse Open Graph / Twitter Card / standard meta tags from HTML.
     * Ported verbatim from public/api/link-preview.php.
     */
    private function parseOpenGraph(string $html, string $originalUrl): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($doc);

        $og = [];

        // Open Graph properties (og:title, og:description, og:image, og:site_name …)
        foreach ($xpath->query("//meta[@property]") as $meta) {
            $property = strtolower($meta->getAttribute('property'));
            $content  = $meta->getAttribute('content');
            if (str_starts_with($property, 'og:')) {
                $key = substr($property, 3);
                if (!isset($og[$key]) && $content !== '') {
                    $og[$key] = $content;
                }
            }
        }

        // Twitter Card fallbacks
        foreach ($xpath->query("//meta[@name]") as $meta) {
            $name    = strtolower($meta->getAttribute('name'));
            $content = $meta->getAttribute('content');
            if ($content === '') {
                continue;
            }
            if (str_starts_with($name, 'twitter:')) {
                $key = substr($name, 8);
                if (!isset($og[$key])) {
                    $og[$key] = $content;
                }
            }
            if ($name === 'description' && !isset($og['description'])) {
                $og['description'] = $content;
            }
        }

        // <title> fallback
        if (empty($og['title'])) {
            $titles = $xpath->query('//title');
            if ($titles->length > 0) {
                $og['title'] = $titles->item(0)->textContent;
            }
        }

        // Validate image URL (must be absolute http/https, must not be a private IP)
        $image = null;
        if (!empty($og['image'])) {
            $imgUrl = $og['image'];
            // Make relative URLs absolute
            if (str_starts_with($imgUrl, '//')) {
                $parsedOrig = parse_url($originalUrl);
                $imgUrl = ($parsedOrig['scheme'] ?? 'https') . ':' . $imgUrl;
            }
            if (filter_var($imgUrl, FILTER_VALIDATE_URL)) {
                $imgParsed = parse_url($imgUrl);
                $imgScheme = $imgParsed['scheme'] ?? '';
                $imgHost   = $imgParsed['host'] ?? '';
                if (in_array($imgScheme, ['http', 'https'], true) && !empty($imgHost)) {
                    $imgIp = gethostbyname($imgHost);
                    if (!$this->isPrivateOrReservedIp($imgIp)) {
                        $image = $imgUrl;
                    }
                }
            }
        }

        $parsedOrig = parse_url($originalUrl);
        $domain = $parsedOrig['host'] ?? '';

        $title       = htmlspecialchars_decode(trim($og['title'] ?? ''), ENT_QUOTES);
        $description = htmlspecialchars_decode(trim($og['description'] ?? ''), ENT_QUOTES);
        $siteName    = htmlspecialchars_decode(trim($og['site_name'] ?? ''), ENT_QUOTES);

        return [
            'title'       => $title,
            'description' => $description,
            'image'       => $image,
            'domain'      => $siteName ?: $domain,
            'url'         => $originalUrl,
        ];
    }
}
