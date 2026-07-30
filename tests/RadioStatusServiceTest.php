<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\RadioStatusService;
use ReflectionMethod;

/**
 * Covers the now-playing feed parser. The HTTP fetch is driven through a
 * file:// URL pointing at a temp JSON fixture (curl reads it directly), so the
 * full Icecast/Shoutcast heuristic in fetchAndParse runs without a live radio
 * server. The pure title helpers are exercised via reflection. No shared state
 * (settings/cache) is touched.
 */
class RadioStatusServiceTest extends TestCase
{
    /** @var string[] temp fixture files to remove */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmp = [];
    }

    /** Parse a payload by writing it to a temp file and passing a file:// URL. */
    private function parse(array $payload): array
    {
        $file = tempnam(sys_get_temp_dir(), 'radio') . '.json';
        file_put_contents($file, json_encode($payload));
        $this->tmp[] = $file;

        $m = new ReflectionMethod(RadioStatusService::class, 'fetchAndParse');
        $m->setAccessible(true);

        return $m->invoke(new RadioStatusService(), 'file://' . $file);
    }

    /**
     * An Icecast feed ({icestats:{source:{title,listeners,album,art}}}) is parsed:
     * the bracketed bitrate is stripped, the "Artist - Title" is split, and the
     * listener count, album and cover art are surfaced.
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
     * An AzuraCast-style now_playing.song object supplies artist/title/album
     * directly.
     */
    public function testNowPlayingObjectSuppliesAlbumAndCover(): void
    {
        // songtitle drives display/active; the now_playing.song object supplies the
        // album and cover art picked up by the rich-feed scan.
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
     * Non-JSON content also yields the inactive envelope (json_decode miss).
     */
    public function testNonJsonIsInactive(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'radio') . '.json';
        file_put_contents($file, 'this is not json');
        $this->tmp[] = $file;

        $m = new ReflectionMethod(RadioStatusService::class, 'fetchAndParse');
        $m->setAccessible(true);
        $res = $m->invoke(new RadioStatusService(), 'file://' . $file);

        $this->assertFalse($res['active']);
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
