<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestDatabase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;
use Pramnos\Http\ClientResponse;
use Pramnos\Http\Response;
use RadioChatBox\Controllers\MediaController;

/**
 * Golden-contract tests for the migrated Media endpoints (replaced
 * public/api/{artwork,link-preview,now-playing}.php).
 *
 * The deterministic validation/SSRF/error branches are asserted directly (they
 * need no network or track changes). The read shapes for artwork and now-playing
 * are asserted for their success payload keys. Destructive/network-heavy work is
 * avoided: link-preview is only exercised on its deterministic guard paths.
 */
class MediaControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        Client::resetFakes();
    }

    /**
     * link-preview happy path: for a public URL whose fetch is faked as HTML with
     * Open Graph tags, it returns 200 with the parsed preview (title/description/
     * image). The SSRF guard runs for real (example.com resolves public), only the
     * HTTP fetch is faked.
     */
    public function testLinkPreviewReturnsOpenGraphPreview(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="Fixture Title">'
            . '<meta property="og:description" content="Fixture description">'
            . '<meta property="og:image" content="https://example.com/img.png">'
            . '</head><body>x</body></html>';
        Client::fake(['*example.com*' => ClientResponse::make($html, 200, ['content-type' => 'text/html; charset=utf-8'])]);

        $_GET = ['url' => 'https://example.com/article?probe=' . uniqid()];
        $response = (new MediaController())->linkPreview();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Fixture Title', $body['title']);
        $this->assertSame('Fixture description', $body['description']);
    }

    /**
     * A YouTube link previews via the oEmbed API (not HTML scraping, which YouTube
     * blocks behind a consent wall): a youtu.be URL returns a card built from the
     * oEmbed title/author/thumbnail, mapped to the standard preview shape.
     */
    public function testLinkPreviewUsesYouTubeOEmbed(): void
    {
        $oembed = json_encode([
            'title'         => 'Some Song - Official Video',
            'author_name'   => 'The Band',
            'thumbnail_url' => 'https://i.ytimg.com/vi/Nt6lpvnGt4A/hqdefault.jpg',
            'provider_name' => 'YouTube',
        ]);
        Client::fake(['*youtube.com/oembed*' => ClientResponse::make($oembed, 200, ['content-type' => 'application/json'])]);

        $_GET = ['url' => 'https://youtu.be/Nt6lpvnGt4A?probe=' . uniqid()];
        $response = (new MediaController())->linkPreview();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Some Song - Official Video', $body['title']);
        $this->assertSame('The Band', $body['description']);
        $this->assertSame('https://i.ytimg.com/vi/Nt6lpvnGt4A/hqdefault.jpg', $body['image']);
        $this->assertSame('youtube.com', $body['domain']);
    }

    /**
     * The oEmbed path is not YouTube-specific — a different registered provider
     * (Vimeo, via its own endpoint) previews the same way, proving the registry
     * generalises across whitelisted providers.
     */
    public function testLinkPreviewUsesOEmbedForOtherProviders(): void
    {
        $oembed = json_encode([
            'title'         => 'A Short Film',
            'author_name'   => 'Director',
            'thumbnail_url' => 'https://i.vimeocdn.com/video/123_640.jpg',
        ]);
        Client::fake(['*vimeo.com/api/oembed*' => ClientResponse::make($oembed, 200, ['content-type' => 'application/json'])]);

        $_GET = ['url' => 'https://vimeo.com/76979871?probe=' . uniqid()];
        $response = (new MediaController())->linkPreview();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('A Short Film', $body['title']);
        $this->assertSame('Director', $body['description']);
        $this->assertSame('vimeo.com', $body['domain']);
    }

    /**
     * A scrape whose only "title" is the URL itself (what a consent/JS-wall page
     * hands a scraper) is treated as no preview — otherwise a useless "title = the
     * link" card would be cached for an hour.
     */
    public function testLinkPreviewRejectsTitleThatIsJustTheUrl(): void
    {
        $probe = 'https://example.com/x?probe=' . uniqid();
        $html  = '<html><head><meta property="og:title" content="' . htmlspecialchars($probe, ENT_QUOTES) . '">'
            . '</head><body>x</body></html>';
        Client::fake(['*example.com*' => ClientResponse::make($html, 200, ['content-type' => 'text/html'])]);

        $_GET = ['url' => $probe];
        $response = (new MediaController())->linkPreview();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('No preview data available', json_decode($response->getBody(), true)['error']);
    }

    /**
     * UTF-8 titles (Greek etc.) survive the scrape: DOMDocument would otherwise
     * assume ISO-8859-1 and mangle them into mojibake ("Îœ…"). The preview must
     * carry the original characters.
     */
    public function testLinkPreviewDecodesUtf8Titles(): void
    {
        $title = 'Metallica: Νέα ελληνική διάκριση στον παγκόσμιο διαγωνισμό';
        $desc  = 'Μια περήφανη στιγμή για την ελληνική σκηνή';
        $html  = '<html><head><meta charset="utf-8">'
            . '<meta property="og:title" content="' . $title . '">'
            . '<meta property="og:description" content="' . $desc . '">'
            . '</head><body>x</body></html>';
        Client::fake(['*example.com*' => ClientResponse::make($html, 200, ['content-type' => 'text/html; charset=utf-8'])]);

        $_GET = ['url' => 'https://example.com/gr?probe=' . uniqid()];
        $response = (new MediaController())->linkPreview();

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame($title, $body['title'], 'Greek title must not be mojibake');
        $this->assertSame($desc, $body['description']);
    }

    /**
     * link-preview rejects a declared non-HTML Content-Type with 422
     * {error:'URL is not an HTML page'}.
     */
    public function testLinkPreviewRejectsNonHtmlContentType(): void
    {
        Client::fake(['*example.com*' => ClientResponse::make('{"x":1}', 200, ['content-type' => 'application/json'])]);

        $_GET = ['url' => 'https://example.com/data.json?probe=' . uniqid()];
        $response = (new MediaController())->linkPreview();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('URL is not an HTML page', json_decode($response->getBody(), true)['error']);
    }

    /**
     * A transport error during the fetch (ClientException) maps to 422
     * {error:'Could not fetch URL'}.
     */
    public function testLinkPreviewFetchFailureReturns422(): void
    {
        Client::fake(['*example.com*' => static function (): ClientResponse {
            throw new ClientException('connection refused', 7);
        }]);

        $_GET = ['url' => 'https://example.com/down?probe=' . uniqid()];
        $response = (new MediaController())->linkPreview();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Could not fetch URL', json_decode($response->getBody(), true)['error']);
    }

    /**
     * link-preview parses a Twitter Card + <title> fallback when Open Graph tags
     * are absent (the twitter: and <title> branches of parseOpenGraph).
     */
    public function testLinkPreviewParsesTwitterAndTitleFallback(): void
    {
        $html = '<html><head><title>Title Fallback</title>'
            . '<meta name="twitter:description" content="Tw desc">'
            . '<meta name="twitter:image" content="https://example.com/tw.png">'
            . '</head><body>x</body></html>';
        Client::fake(['*example.com*' => ClientResponse::make($html, 200, ['content-type' => 'text/html'])]);

        $_GET = ['url' => 'https://example.com/tw?probe=' . uniqid()];
        $body = json_decode((new MediaController())->linkPreview()->getBody(), true);

        $this->assertSame('Title Fallback', $body['title']);
        $this->assertSame('Tw desc', $body['description']);
    }

    /**
     * now-playing active path: with a configured radio URL and a faked active feed
     * it returns nowPlaying.active=true, records the play and attaches metadata
     * (recordPlay + getCurrentTrackMeta + enrichTrack, with the external providers
     * faked to no match). Settings/caches restored and the recorded track removed.
     */
    public function testNowPlayingActiveRecordsPlay(): void
    {
        $suffix   = substr(bin2hex(random_bytes(4)), 0, 8);
        $settings = new \RadioChatBox\Services\SettingsService();
        $prevUrl  = (string) $settings->get('radio_status_url', '');
        $display  = 'NP Artist ' . $suffix . ' - NP Song';

        // Reset the radio cache (a FlatCache key — must be cleared through the same
        // API, not a raw del, or a stale feed cached by another test leaks in) and
        // the raw last-track dedup pointer, so the play records against our feed.
        \Pramnos\Cache\FlatCache::default()->delete('radio:now_playing');
        $cm = \Pramnos\Redis\ConnectionManager::getInstance();
        $cm->connection()->del($cm->prefix() . 'radio:last_track');

        Client::fake([
            '*radio.test*'       => ClientResponse::make(['icestats' => ['source' => [
                'title'     => 'NP Artist ' . $suffix . ' - NP Song',
                'listeners' => 8,
            ]]]),
            '*api.deezer.com*'   => ClientResponse::make(['data' => []]),
            '*itunes.apple.com*' => ClientResponse::make(['results' => []]),
        ]);

        try {
            $settings->setMultiple(['radio_status_url' => 'https://radio.test/status-json.xsl']);

            $response = (new MediaController())->nowPlaying();
            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertTrue($body['success']);
            $this->assertTrue($body['nowPlaying']['active']);
            $this->assertSame(8, $body['nowPlaying']['listeners']);

            $pdo  = TestDatabase::connection();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM tracks WHERE display = ?');
            $stmt->execute([$display]);
            $this->assertGreaterThanOrEqual(1, (int) $stmt->fetchColumn(), 'the play must be recorded');
        } finally {
            $settings->setMultiple(['radio_status_url' => $prevUrl]);
            \Pramnos\Cache\FlatCache::default()->delete('radio:now_playing');
            $pdo = TestDatabase::connection();
            $pdo->prepare('DELETE FROM track_plays WHERE track_id IN (SELECT id FROM tracks WHERE display = ?)')->execute([$display]);
            $pdo->prepare('DELETE FROM tracks WHERE display = ?')->execute([$display]);
            $pdo->prepare('DELETE FROM artists WHERE name = ?')->execute(['NP Artist ' . $suffix]);
        }
    }

    /**
     * artwork in the default track mode with neither artist nor title returns a
     * 400 {error:'artist or title is required'}.
     */
    public function testArtworkTrackModeRequiresArtistOrTitle(): void
    {
        $_GET = [];

        $response = (new MediaController())->artwork();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('artist or title is required', $body['error']);
    }

    /**
     * artwork in artist mode with an empty artist returns a
     * 400 {error:'artist is required'}.
     */
    public function testArtworkArtistModeRequiresArtist(): void
    {
        $_GET = ['mode' => 'artist'];

        $response = (new MediaController())->artwork();

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('artist is required', $body['error']);
    }

    /**
     * A track-mode lookup with a title returns 200 {success:true, …} merged with
     * the ArtworkService result, and carries the 1h public cache header.
     */
    public function testArtworkTrackModeReturnsSuccessShape(): void
    {
        $_GET = ['title' => 'probe_track_' . substr(md5(__METHOD__), 0, 8)];

        $response = (new MediaController())->artwork();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        // getArtwork always returns these keys (positive or negative cache).
        $this->assertArrayHasKey('cover', $body);
        $this->assertArrayHasKey('source', $body);
    }

    /**
     * link-preview with no url query param returns 400 {error:'URL is required'}.
     */
    public function testLinkPreviewRequiresUrl(): void
    {
        $_GET = [];

        $response = (new MediaController())->linkPreview();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('URL is required', json_decode($response->getBody(), true)['error']);
    }

    /**
     * link-preview with a malformed url returns 400 {error:'Invalid URL'}.
     */
    public function testLinkPreviewRejectsMalformedUrl(): void
    {
        $_GET = ['url' => 'not a url'];

        $response = (new MediaController())->linkPreview();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid URL', json_decode($response->getBody(), true)['error']);
    }

    /**
     * link-preview with a non-http(s) scheme returns 400
     * {error:'Invalid URL scheme'}.
     */
    public function testLinkPreviewRejectsNonHttpScheme(): void
    {
        $_GET = ['url' => 'ftp://example.com/file'];

        $response = (new MediaController())->linkPreview();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid URL scheme', json_decode($response->getBody(), true)['error']);
    }

    /**
     * link-preview applies SSRF protection: a URL whose host resolves to a
     * loopback/private address returns 403 {error:'Forbidden URL'}.
     */
    public function testLinkPreviewBlocksPrivateIpForSsrf(): void
    {
        $_GET = ['url' => 'http://127.0.0.1/admin'];

        $response = (new MediaController())->linkPreview();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden URL', json_decode($response->getBody(), true)['error']);
    }

    /**
     * now-playing returns 200 {success:true, nowPlaying:{…}} with the no-cache
     * headers the frontend poller relies on.
     */
    public function testNowPlayingReturnsSuccessShape(): void
    {
        $response = (new MediaController())->nowPlaying();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-cache, no-store, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('nowPlaying', $body);
        $this->assertIsArray($body['nowPlaying']);
    }
}
