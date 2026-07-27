<?php

namespace RadioChatBox\Tests\Controllers;

use PHPUnit\Framework\TestCase;
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
