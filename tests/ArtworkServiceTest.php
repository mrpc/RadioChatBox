<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use RadioChatBox\Services\ArtworkService;

/**
 * Covers ArtworkService's fetch → decode → resize → store pipeline. Every remote
 * call (Deezer/iTunes JSON and the image download) is faked through the framework
 * HTTP client, so the GD image handling runs against a real in-memory JPEG
 * without touching the network. Files written under public/uploads/artwork and
 * the Redis lookup cache are cleaned up per test.
 */
class ArtworkServiceTest extends TestCase
{
    private string $diskDir;
    /** @var string[] web paths whose disk files (and thumbs) to remove */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskDir = dirname(__DIR__) . '/public/uploads/artwork';
    }

    protected function tearDown(): void
    {
        Client::resetFakes();
        foreach ($this->written as $web) {
            foreach ([$web, preg_replace('/\.jpg$/i', '_thumb.jpg', $web)] as $path) {
                $disk = $this->diskDir . '/' . ltrim(str_replace('/uploads/artwork', '', (string) $path), '/');
                if (is_file($disk)) {
                    @unlink($disk);
                }
            }
        }
        $this->written = [];
        // Lookup cache keys are md5-hashed per (unique) query and the test Redis
        // keyspace is flushed at bootstrap, so no explicit cache reset is needed.
        parent::tearDown();
    }

    /** A tiny but valid JPEG byte string GD's imagecreatefromstring can decode. */
    private function jpegBytes(): string
    {
        $img = imagecreatetruecolor(120, 120);
        imagefilledrectangle($img, 0, 0, 120, 120, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    /** Fake the Deezer/iTunes endpoints and the image CDN. */
    private function fakeProviders(array $overrides = []): void
    {
        $jpeg = $this->jpegBytes();
        Client::fake($overrides + [
            // Most specific first (first matching pattern wins).
            '*api.deezer.com/search/artist*' => ClientResponse::make(['data' => [
                ['picture_xl' => 'https://img.test/artist.jpg'],
            ]]),
            '*api.deezer.com/search*' => ClientResponse::make(['data' => [[
                'album'  => ['cover_xl' => 'https://img.test/cover.jpg'],
                'artist' => ['picture_xl' => 'https://img.test/artist.jpg'],
            ]]]),
            '*img.test*' => ClientResponse::make($jpeg),
        ]);
    }

    /**
     * getArtwork downloads the Deezer cover + artist image, stores both locally
     * (with thumbnails) and returns their web paths tagged source=deezer.
     */
    public function testGetArtworkDownloadsAndStoresCoverAndArtist(): void
    {
        $this->fakeProviders();

        $art = (new ArtworkService())->getArtwork('Fixture Artist ' . uniqid(), 'Fixture Song');

        $this->assertNotNull($art['cover'], 'a cover must be stored');
        $this->assertSame('deezer', $art['source']);
        $this->written[] = $art['cover'];
        if (!empty($art['artist_image'])) {
            $this->written[] = $art['artist_image'];
        }

        // The stored cover file really exists on disk.
        $disk = $this->diskDir . '/' . ltrim(str_replace('/uploads/artwork', '', $art['cover']), '/');
        $this->assertFileExists($disk);
    }

    /**
     * getArtistImage uses the Deezer artist search + downloads the picture,
     * returning a stored artist_image path with source=deezer.
     */
    public function testGetArtistImageDownloadsPicture(): void
    {
        $this->fakeProviders();

        $res = (new ArtworkService())->getArtistImage('Solo Artist ' . uniqid(), true);

        $this->assertNotNull($res['artist_image']);
        $this->assertSame('deezer', $res['source']);
        $this->written[] = $res['artist_image'];

        $disk = $this->diskDir . '/' . ltrim(str_replace('/uploads/artwork', '', $res['artist_image']), '/');
        $this->assertFileExists($disk);
    }

    /**
     * When every provider returns no match, getArtwork reports null images and a
     * null source (the negative path), and does not write any file.
     */
    public function testGetArtworkNoMatchReturnsNulls(): void
    {
        Client::fake([
            '*api.deezer.com*'    => ClientResponse::make(['data' => []]),
            '*itunes.apple.com*'  => ClientResponse::make(['results' => []]),
        ]);

        $res = (new ArtworkService())->getArtwork('Nonexistent ' . uniqid(), 'No Such Song');

        $this->assertNull($res['cover']);
        $this->assertNull($res['artist_image']);
        $this->assertNull($res['source']);
    }

    /**
     * getArtwork falls back to iTunes for the cover when Deezer has no match,
     * upscaling the 100x100 artwork URL and storing it.
     */
    public function testGetArtworkFallsBackToItunesCover(): void
    {
        $jpeg = $this->jpegBytes();
        Client::fake([
            '*api.deezer.com*'   => ClientResponse::make(['data' => []]),
            '*itunes.apple.com*' => ClientResponse::make(['results' => [
                ['artworkUrl100' => 'https://img.test/itunes/100x100bb.jpg'],
            ]]),
            '*img.test*' => ClientResponse::make($jpeg),
        ]);

        $res = (new ArtworkService())->getArtwork('Itunes Artist ' . uniqid(), 'Itunes Song');

        $this->assertNotNull($res['cover'], 'the iTunes cover must be stored');
        $this->assertSame('itunes', $res['source']);
        $this->written[] = $res['cover'];
    }

    /**
     * storeImageFromUrl downloads raw bytes from a feed cover URL and stores a
     * local JPEG + thumbnail.
     */
    public function testStoreImageFromUrlSavesLocalCopy(): void
    {
        Client::fake(['*feed.test*' => ClientResponse::make($this->jpegBytes())]);

        $res = (new ArtworkService())->storeImageFromUrl('https://feed.test/nowplaying.jpg');

        $this->assertNotNull($res['full']);
        $this->written[] = $res['full'];
        $disk = $this->diskDir . '/' . ltrim(str_replace('/uploads/artwork', '', $res['full']), '/');
        $this->assertFileExists($disk);
    }
}
