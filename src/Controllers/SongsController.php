<?php

namespace RadioChatBox\Controllers;

use Pramnos\Cache\FlatCache;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\TrackStatsService;

/**
 * Public "Top charts" feed for the front-end charts panel: the most-played
 * artists or tracks over a rolling day / week / month window.
 *
 * Gated by the `charts_enabled` setting so a station opts in — when off, the
 * endpoint 404s (the button is hidden client-side too). The aggregates come
 * from the existing track-play history (TrackStatsService) and are cached in
 * Redis for a short TTL so the button stays cheap under load.
 */
final class SongsController
{
    /** How far back each period reaches. */
    private const PERIOD_INTERVALS = [
        'day'   => '-1 day',
        'week'  => '-7 days',
        'month' => '-30 days',
    ];

    /** Cache the aggregate for this long (seconds) — charts move slowly. */
    private const CACHE_TTL = 120;

    /**
     * GET /api/songs/top?type=artists|tracks&period=day|week|month&limit=10
     *
     * Returns {success:true, type, period, items:[…]}. `type` defaults to
     * tracks, `period` to the configured charts_default_period (else day), and
     * `limit` is clamped to 1..50 (default 10). When charts are disabled ->
     * 404 {success:false, error:'Charts are disabled'}.
     */
    #[Route('/api/songs/top', methods: 'GET', name: 'songs.top')]
    public function top(): Response
    {
        $settings = new SettingsService();

        if (!$this->isEnabled($settings->get('charts_enabled'))) {
            return Response::json(
                ['success' => false, 'error' => 'Charts are disabled'],
                404
            );
        }

        $request = Request::getInstance();

        $type = (string) $request->get('type', 'tracks', 'get');
        if ($type !== 'artists' && $type !== 'tracks') {
            $type = 'tracks';
        }

        $period = (string) $request->get('period', '', 'get');
        if (!isset(self::PERIOD_INTERVALS[$period])) {
            $default = (string) $settings->get('charts_default_period', 'day');
            $period  = isset(self::PERIOD_INTERVALS[$default]) ? $default : 'day';
        }

        $limit = (int) $request->get('limit', 10, 'get');
        $limit = max(1, min(50, $limit));

        $items = $this->aggregate($type, $period, $limit);

        return Response::json([
            'success' => true,
            'type'    => $type,
            'period'  => $period,
            'items'   => $items,
        ])->withHeader('Cache-Control', 'public, max-age=' . self::CACHE_TTL);
    }

    /**
     * GET /api/songs/artists — the list of known artist names, for the song-
     * request form's autocomplete. Gated by song_requests_enabled (its only
     * consumer) and cached in Redis. 200 {success, artists:[name,…]}; feature
     * off -> 404.
     */
    #[Route('/api/songs/artists', methods: 'GET', name: 'songs.artists')]
    public function artists(): Response
    {
        if (!$this->isEnabled((new SettingsService())->get('song_requests_enabled'))) {
            return Response::json(['success' => false, 'error' => 'Not available'], 404);
        }

        $names = [];
        try {
            $cached = FlatCache::default()->get('songs:artist_names');
            if (is_array($cached)) {
                $names = $cached;
            }
        } catch (\Throwable $e) {
            // fall through to compute
        }

        if ($names === []) {
            foreach ((new TrackStatsService())->getAllArtists() as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $names = array_slice($names, 0, 1000);
            try {
                FlatCache::default()->set('songs:artist_names', $names, 300);
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        return Response::json(['success' => true, 'artists' => $names])
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /**
     * The top artists/tracks for a period, served from Redis when warm.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aggregate(string $type, string $period, int $limit): array
    {
        $cacheKey = "charts:top:{$type}:{$period}:{$limit}";

        try {
            $cached = FlatCache::default()->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // Cache backend unavailable — compute uncached below.
        }

        $from = (new \DateTimeImmutable(self::PERIOD_INTERVALS[$period]))->format('Y-m-d H:i:s');
        $to   = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

        $stats = new TrackStatsService();
        $items = $type === 'artists'
            ? $stats->getTopArtists($from, $to, $limit)
            : $stats->getTopTracks($from, $to, $limit);

        try {
            FlatCache::default()->set($cacheKey, $items, self::CACHE_TTL);
        } catch (\Throwable $e) {
            // Non-fatal: return the freshly-computed value uncached.
        }

        return $items;
    }

    /** A setting stored as a string is "on" for true/1/on/yes. */
    private function isEnabled(mixed $value): bool
    {
        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'on', 'yes'],
            true
        );
    }
}
