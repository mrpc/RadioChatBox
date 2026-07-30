<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\FlatCache;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use RadioChatBox\Services\RadioStatusService;
use RadioChatBox\Services\SettingsService;
use ReflectionMethod;

/**
 * Covers the now-playing feed parser. The HTTP fetch is faked through the
 * framework HTTP client ({@see Client::fake()}) so the full Icecast/Shoutcast
 * heuristic in fetchAndParse — and getNowPlaying end to end — runs
 * deterministically without a live radio server. No shared state (cache) is left
 * behind: fakes are reset and the now-playing cache is cleared in tearDown.
 */
class RadioStatusServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::resetFakes();
        try {
            FlatCache::default()->delete('radio:now_playing');
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    /** Parse a payload by faking the HTTP response and driving fetchAndParse. */
    private function parse(array $payload): array
    {
        Client::fake(['*' => ClientResponse::make($payload)]);

        $m = new ReflectionMethod(RadioStatusService::class, 'fetchAndParse');
        $m->setAccessible(true);

        return $m->invoke(new RadioStatusService(), 'https://radio.test/status-json.xsl');
    }

    /**
     * An Icecast feed ({icestats:{source:{title,listeners}}}) is parsed: the
     * bracketed bitrate is stripped and the "Artist - Title" is split.
     */
    public function testIcecastSourceIsParsed(): void
    {
        $res = $this->parse(['icestats' => ['source' => [
            'title'     => 'The Beatles - Hey Jude [128kbps]',
            'listeners' => 42,
        ]]]);

        $this->assertTrue($res['active']);
        $this->assertSame('The Beatles - Hey Jude', $res['display']);
        $this->assertSame('The Beatles', $res['artist']);
        $this->assertSame('Hey Jude', $res['title']);
        $this->assertSame(42, $res['listeners']);
    }

    /**
     * Icecast with multiple sources prefers a /live source that has listeners.
     */
    public function testIcecastPrefersLiveSourceWithListeners(): void
    {
        $res = $this->parse(['icestats' => ['source' => [
            ['listenurl' => 'http://x/fallback', 'title' => 'Filler - Nope', 'listeners' => 0],
            ['listenurl' => 'http://x/live', 'title' => 'Real - Song', 'listeners' => 9],
        ]]]);

        $this->assertSame('Real - Song', $res['display']);
        $this->assertSame(9, $res['listeners']);
    }

    /**
     * A Shoutcast-style flat feed (songtitle + currentlisteners) is parsed via
     * the fallback candidate scan.
     */
    public function testShoutcastFlatFeedIsParsed(): void
    {
        $res = $this->parse(['songtitle' => 'Queen - Bohemian Rhapsody', 'currentlisteners' => 7]);

        $this->assertTrue($res['active']);
        $this->assertSame('Queen - Bohemian Rhapsody', $res['display']);
        $this->assertSame('Queen', $res['artist']);
        $this->assertSame('Bohemian Rhapsody', $res['title']);
        $this->assertSame(7, $res['listeners']);
    }

    /**
     * An AzuraCast-style now_playing.song object supplies the album and cover art
     * picked up by the rich-feed scan (songtitle drives display/active).
     */
    public function testNowPlayingObjectSuppliesAlbumAndCover(): void
    {
        $res = $this->parse([
            'songtitle'   => 'Daft Punk - One More Time',
            'listeners'   => 3,
            'now_playing' => ['song' => [
                'album' => 'Discovery',
                'art'   => 'https://example.com/discovery.jpg',
            ]],
        ]);

        $this->assertTrue($res['active']);
        $this->assertSame('Daft Punk', $res['artist']);
        $this->assertSame('One More Time', $res['title']);
        $this->assertSame('Discovery', $res['album']);
        $this->assertSame('https://example.com/discovery.jpg', $res['feed_cover']);
    }

    /**
     * A payload with no recognisable now-playing data yields the inactive
     * envelope (active:false, everything null).
     */
    public function testEmptyPayloadIsInactive(): void
    {
        $res = $this->parse(['something' => 'unrelated']);

        $this->assertFalse($res['active']);
        $this->assertNull($res['display']);
        $this->assertNull($res['listeners']);
    }

    /**
     * A non-2xx response (server error) is treated as no data — httpGet returns
     * null and fetchAndParse yields the inactive envelope.
     */
    public function testHttpErrorIsInactive(): void
    {
        Client::fake(['*' => ClientResponse::make('nope', 503)]);
        $m = new ReflectionMethod(RadioStatusService::class, 'fetchAndParse');
        $m->setAccessible(true);
        $res = $m->invoke(new RadioStatusService(), 'https://radio.test/down');

        $this->assertFalse($res['active']);
    }

    /**
     * Non-JSON content also yields the inactive envelope (json_decode miss).
     */
    public function testNonJsonIsInactive(): void
    {
        Client::fake(['*' => ClientResponse::make('this is not json')]);
        $m = new ReflectionMethod(RadioStatusService::class, 'fetchAndParse');
        $m->setAccessible(true);
        $res = $m->invoke(new RadioStatusService(), 'https://radio.test/html');

        $this->assertFalse($res['active']);
    }

    /**
     * getNowPlaying end to end: with a configured status URL and a faked feed it
     * returns the parsed now-playing and caches it (the second call returns the
     * cached array without re-fetching). The setting is snapshotted/restored.
     */
    public function testGetNowPlayingEndToEndWithConfiguredUrl(): void
    {
        $settings = new SettingsService();
        $previous = (string) $settings->get('radio_status_url', '');

        try {
            $settings->setMultiple(['radio_status_url' => 'https://radio.test/status-json.xsl']);
            FlatCache::default()->delete('radio:now_playing');

            Client::fake(['*' => ClientResponse::make(['icestats' => ['source' => [
                'title'     => 'Pink Floyd - Time',
                'listeners' => 5,
            ]]])]);

            $result = (new RadioStatusService())->getNowPlaying();
            $this->assertTrue($result['active']);
            $this->assertSame('Pink Floyd - Time', $result['display']);
            $this->assertSame(5, $result['listeners']);

            // Second call is served from cache even with the fake removed.
            Client::resetFakes();
            $cached = (new RadioStatusService())->getNowPlaying();
            $this->assertSame('Pink Floyd - Time', $cached['display']);
        } finally {
            $settings->setMultiple(['radio_status_url' => $previous]);
        }
    }

    /**
     * splitArtistTitle strips bitrate noise and splits on the common separators;
     * a string with no separator returns [null, null].
     */
    public function testSplitArtistTitleHelper(): void
    {
        $m = new ReflectionMethod(RadioStatusService::class, 'splitArtistTitle');
        $m->setAccessible(true);
        $svc = new RadioStatusService();

        $this->assertSame(['Artist', 'Title'], $m->invoke($svc, 'Artist - Title [192k]'));
        $this->assertSame([null, null], $m->invoke($svc, 'JustAStationName'));
    }
}
