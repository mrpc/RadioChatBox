<?php
/**
 * Link Preview API
 * Fetches Open Graph metadata for a given URL to display rich link previews in chat.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RadioChatBox\Database;
use RadioChatBox\CorsHandler;

header('Content-Type: application/json');
CorsHandler::handle();

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$url = $_GET['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL is required']);
    exit;
}

// Validate URL format
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

// Only allow http and https schemes
$parsed = parse_url($url);
if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL scheme']);
    exit;
}

$host = $parsed['host'] ?? '';

if (empty($host)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL host']);
    exit;
}

// SSRF protection: resolve hostname and block private/loopback IP ranges
$resolvedIp = gethostbyname($host);
if (isPrivateOrReservedIp($resolvedIp)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden URL']);
    exit;
}

// Check Redis cache first
try {
    $redis = Database::getRedis();
    $prefix = Database::getRedisPrefix();
    $cacheKey = $prefix . 'link_preview:' . md5($url);

    $cached = $redis->get($cacheKey);
    if ($cached !== false) {
        echo $cached;
        exit;
    }
} catch (Exception $e) {
    // Redis unavailable — proceed without cache
    $redis = null;
    $cacheKey = null;
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
    http_response_code(422);
    echo json_encode(['error' => 'Could not fetch URL']);
    exit;
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
    http_response_code(422);
    echo json_encode(['error' => 'URL is not an HTML page']);
    exit;
}

// Parse Open Graph metadata (limit to first 100KB to avoid huge documents)
$preview = parseOpenGraph(substr($html, 0, 102400), $url);

if (empty($preview['title'])) {
    // Nothing useful to show
    http_response_code(422);
    echo json_encode(['error' => 'No preview data available']);
    exit;
}

$result = json_encode($preview);

// Cache the result for 1 hour
if ($redis !== null && $cacheKey !== null) {
    $redis->setex($cacheKey, 3600, $result);
}

echo $result;

// ---------------------------------------------------------------------------

/**
 * Returns true if the given IP is private, loopback, or reserved (SSRF guard).
 */
function isPrivateOrReservedIp(string $ip): bool
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
 */
function parseOpenGraph(string $html, string $originalUrl): array
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
                if (!isPrivateOrReservedIp($imgIp)) {
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
