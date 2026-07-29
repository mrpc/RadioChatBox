<?php

namespace RadioChatBox\Controllers;

use InvalidArgumentException;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\AdminAuth;
use RadioChatBox\Http\Validate;
use RadioChatBox\Services\BotService;
use RadioChatBox\Services\LlmAccount;
use RadioChatBox\Services\LlmLog;
use RadioChatBox\Services\LlmPricing;
use RadioChatBox\Services\LlmProviders;
use RadioChatBox\Middleware\AdminAuthMiddleware;
use RadioChatBox\Services\SettingsService;
use RadioChatBox\Services\StatsService;
use RadioChatBox\Services\TrackStatsService;

/**
 * Admin statistics endpoints (resource group "AdminStats").
 *
 * Consolidates the legacy per-file admin stats API into one attribute-routed
 * controller. Every route carries AdminAuthMiddleware (which returns
 * 401 {"error":"Unauthorized"} for unauthenticated requests, replacing the
 * legacy AdminAuth::verify()/unauthorized() gate); extra RBAC checks that a
 * legacy file performed beyond mere authentication are preserved inside the
 * action. JSON payloads, status codes and error mapping are reproduced exactly.
 */
final class AdminStatsController
{
    /**
     * GET /api/admin/stats — dashboard statistics at a chosen granularity.
     *
     * Migrated from public/api/admin/stats.php. Query: granularity
     * (summary|hourly|daily|weekly|monthly|yearly, default summary),
     * start_date, end_date, year, limit. When granularity=summary it force-records
     * a snapshot (ignoring the rate limit) and always triggers on-demand
     * aggregation. Success 200:
     * {success, granularity, data, radio_enabled, chat_mode, photos_enabled}.
     * Invalid granularity -> 400 {error:'Invalid granularity'}; failure -> 500
     * {error:'Failed to retrieve stats'}.
     */
    #[Route('/api/admin/stats', methods: 'GET', name: 'admin.stats.index', middleware: [AdminAuthMiddleware::class])]
    public function stats(): Response
    {
        try {
            $req = Request::getInstance();
            $statsService = new StatsService();

            $granularity = (string) $req->get('granularity', 'summary', 'get');

            // Force record a snapshot when viewing summary (ensures real-time data
            // exists), especially on first load or when cron isn't running.
            if ($granularity === 'summary') {
                $statsService->recordSnapshot(true); // Ignore rate limit for admin dashboard
            }

            // If cron isn't running, trigger aggregation on-demand.
            $statsService->triggerAggregationIfNeeded();

            $startDate = $req->get('start_date', null, 'get');
            $endDate   = $req->get('end_date', null, 'get');
            $yearRaw   = $req->get('year', null, 'get');
            $year      = $yearRaw === null ? null : (int) $yearRaw;
            $limitRaw  = $req->get('limit', null, 'get');
            $limit     = $limitRaw === null ? null : (int) $limitRaw;

            $data = [];

            switch ($granularity) {
                case 'summary':
                    $data = $statsService->getSummary();
                    break;

                case 'hourly':
                    $limit = $limit ?? 168; // Default: 7 days of hourly data
                    $data = $statsService->getHourlyStats($startDate, $endDate, $limit);
                    break;

                case 'daily':
                    $limit = $limit ?? 90; // Default: 90 days
                    $data = $statsService->getDailyStats($startDate, $endDate, $limit);
                    break;

                case 'weekly':
                    $limit = $limit ?? 52; // Default: 52 weeks
                    $data = $statsService->getWeeklyStats($year, $limit);
                    break;

                case 'monthly':
                    $limit = $limit ?? 24; // Default: 24 months
                    $data = $statsService->getMonthlyStats($year, $limit);
                    break;

                case 'yearly':
                    $limit = $limit ?? 10; // Default: 10 years
                    $data = $statsService->getYearlyStats($limit);
                    break;

                default:
                    return Response::json(['error' => 'Invalid granularity'], 400);
            }

            // Radio status URL setting decides whether radio stats should be shown.
            $settingsService   = new SettingsService();
            $radioStatusUrl    = $settingsService->get('radio_status_url', '');
            $chatMode          = $settingsService->get('chat_mode', 'both');
            $allowPhotoUploads = $settingsService->get('allow_photo_uploads', 'true') === 'true';

            return Response::json([
                'success'        => true,
                'granularity'    => $granularity,
                'data'           => $data,
                'radio_enabled'  => !empty($radioStatusUrl),
                'chat_mode'      => $chatMode,
                'photos_enabled' => $allowPhotoUploads,
            ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stats retrieval error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to retrieve stats'], 500);
        }
    }

    /**
     * POST /api/admin/aggregate-stats — trigger stats aggregation (cron target).
     *
     * Migrated from public/api/admin/aggregate-stats.php. Reads query params
     * (not the body): granularity (hourly|daily|weekly|monthly|yearly|all,
     * default all) and date. Success 200: {success, results}. Invalid
     * granularity -> 400 {error:'Invalid granularity'}; failure -> 500
     * {error:'Failed to aggregate stats'}.
     */
    #[Route('/api/admin/aggregate-stats', methods: 'POST', name: 'admin.stats.aggregate', middleware: [AdminAuthMiddleware::class])]
    public function aggregate(): Response
    {
        try {
            $req = Request::getInstance();
            $statsService = new StatsService();
            $granularity = (string) $req->get('granularity', 'all', 'get');
            $date = $req->get('date', null, 'get');

            $results = [];

            switch ($granularity) {
                case 'hourly':
                    $results['hourly'] = $statsService->aggregateHourlyStats($date);
                    break;
                case 'daily':
                    $results['daily'] = $statsService->aggregateDailyStats($date);
                    break;
                case 'weekly':
                    $results['weekly'] = $statsService->aggregateWeeklyStats($date);
                    break;
                case 'monthly':
                    $results['monthly'] = $statsService->aggregateMonthlyStats($date);
                    break;
                case 'yearly':
                    $year = $date ? (int) date('Y', strtotime($date)) : null;
                    $results['yearly'] = $statsService->aggregateYearlyStats($year);
                    break;
                case 'all':
                    $results = $statsService->runMaintenanceAggregations();
                    break;
                default:
                    return Response::json(['error' => 'Invalid granularity'], 400);
            }

            return Response::json([
                'success' => true,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stats aggregation error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to aggregate stats'], 500);
        }
    }

    /**
     * POST /api/admin/record-snapshot — record a real-time activity snapshot.
     *
     * Migrated from public/api/admin/record-snapshot.php. No inputs. Success
     * 200: {success, snapshot}; failure -> 500 {error:'Failed to record snapshot'}.
     */
    #[Route('/api/admin/record-snapshot', methods: 'POST', name: 'admin.stats.record-snapshot', middleware: [AdminAuthMiddleware::class])]
    public function recordSnapshot(): Response
    {
        try {
            $statsService = new StatsService();
            $snapshot = $statsService->recordSnapshot();

            return Response::json([
                'success'  => true,
                'snapshot' => $snapshot,
            ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stats snapshot error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to record snapshot'], 500);
        }
    }

    /**
     * GET|POST /api/admin/track-stats — radio play statistics and metadata editing.
     *
     * Migrated from public/api/admin/track-stats.php. POST is an action dispatcher
     * (update-track|update-artist|update-album|enrich|enrich-album|
     * bulk-genre-tracks|bulk-genre-artist|bulk-genre-reassign|enrich-artist) reading
     * the JSON body; GET dispatches on ?mode
     * (summary|log|top|artists|genres|albums|artist|genre-list|all-artists|search|
     * genre|album|track). Success payloads carry {success, ...} shaped per branch.
     * InvalidArgumentException -> 400 {error:msg}; not-found -> 404 {error:...};
     * failure -> 500 {error:'Internal server error'}.
     */
    #[Route('/api/admin/track-stats', methods: ['GET', 'POST'], name: 'admin.stats.track-stats', middleware: [AdminAuthMiddleware::class])]
    public function trackStats(): Response
    {
        try {
            $service = new TrackStatsService();

            // ---- POST: edit metadata / trigger enrichment ----
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                // The framework Request has already decoded the JSON body into $_POST.
                $input = $_POST ?: [];
                $action = $input['action'] ?? '';

                if ($action === 'update-track') {
                    $trackId = (int) ($input['track_id'] ?? 0);
                    if ($trackId <= 0) {
                        throw new InvalidArgumentException('track_id is required');
                    }
                    $service->updateTrackMeta($trackId, $input);
                    return Response::json(['success' => true]);
                } elseif ($action === 'update-artist') {
                    $artistId = (int) ($input['artist_id'] ?? 0);
                    if ($artistId <= 0) {
                        throw new InvalidArgumentException('artist_id is required');
                    }
                    $service->updateArtistMeta($artistId, $input);
                    return Response::json(['success' => true]);
                } elseif ($action === 'update-album') {
                    $albumId = (int) ($input['album_id'] ?? 0);
                    if ($albumId <= 0) {
                        throw new InvalidArgumentException('album_id is required');
                    }
                    $service->updateAlbumMeta($albumId, $input);
                    return Response::json(['success' => true]);
                } elseif ($action === 'enrich') {
                    $trackId = (int) ($input['track_id'] ?? 0);
                    if ($trackId <= 0) {
                        throw new InvalidArgumentException('track_id is required');
                    }
                    $service->enrichTrack($trackId);
                    return Response::json(['success' => true]);
                } elseif ($action === 'enrich-album') {
                    $albumId = (int) ($input['album_id'] ?? 0);
                    if ($albumId <= 0) {
                        throw new InvalidArgumentException('album_id is required');
                    }
                    $service->enrichAlbum($albumId);
                    return Response::json(['success' => true]);
                } elseif ($action === 'bulk-genre-tracks') {
                    $ids = $input['track_ids'] ?? [];
                    if (!is_array($ids) || empty($ids)) {
                        throw new InvalidArgumentException('track_ids are required');
                    }
                    $n = $service->bulkSetGenreForTracks($ids, (string) ($input['genre'] ?? ''));
                    return Response::json(['success' => true, 'updated' => $n]);
                } elseif ($action === 'bulk-genre-artist') {
                    $artist = trim($input['artist'] ?? '');
                    if ($artist === '') {
                        throw new InvalidArgumentException('artist is required');
                    }
                    $n = $service->bulkSetGenreByArtist($artist, (string) ($input['genre'] ?? ''));
                    return Response::json(['success' => true, 'updated' => $n]);
                } elseif ($action === 'bulk-genre-reassign') {
                    $from = trim($input['from'] ?? '');
                    if ($from === '') {
                        throw new InvalidArgumentException('from genre is required');
                    }
                    $n = $service->bulkReassignGenre($from, (string) ($input['to'] ?? ''));
                    return Response::json(['success' => true, 'updated' => $n]);
                } elseif ($action === 'enrich-artist') {
                    $artist = trim($input['artist'] ?? '');
                    if ($artist === '') {
                        throw new InvalidArgumentException('artist is required');
                    }
                    $service->ensureArtistImage($artist, true); // force re-fetch
                    return Response::json(['success' => true]);
                } else {
                    throw new InvalidArgumentException('Unknown action');
                }
            }

            // ---- GET: read-only reports ----
            $req  = Request::getInstance();
            $mode = $req->get('mode', 'summary', 'get');

            if ($mode === 'log') {
                // Play log for a specific date (default: today).
                $date = $req->get('date', date('Y-m-d'), 'get');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    throw new InvalidArgumentException('date must be YYYY-MM-DD');
                }
                return Response::json([
                    'success' => true,
                    'mode'    => 'log',
                    'date'    => $date,
                    'plays'   => $service->getLog($date),
                ]);
            } elseif ($mode === 'top') {
                // Most-played tracks in a window (default: last 7 days).
                $from = $req->get('from', (new \DateTimeImmutable('-7 days'))->format('Y-m-d 00:00:00'), 'get');
                $to   = $req->get('to', (new \DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00'), 'get');
                $limitRaw = $req->get('limit', null, 'get');
                $limit = $limitRaw === null ? 50 : min((int) $limitRaw, 200);
                return Response::json([
                    'success' => true,
                    'mode'    => 'top',
                    'from'    => $from,
                    'to'      => $to,
                    'tracks'  => $service->getTopTracks($from, $to, $limit),
                ]);
            } elseif ($mode === 'artists') {
                // Most-played artists over the last N days (default 7).
                $daysRaw = $req->get('days', null, 'get');
                $days = $daysRaw === null ? 7 : max(1, min((int) $daysRaw, 365));
                $limitRaw = $req->get('limit', null, 'get');
                $limit = $limitRaw === null ? 50 : min((int) $limitRaw, 200);
                $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');
                $to   = (new \DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');
                return Response::json([
                    'success' => true,
                    'mode'    => 'artists',
                    'days'    => $days,
                    'artists' => $service->getTopArtists($from, $to, $limit),
                ]);
            } elseif ($mode === 'genres') {
                $daysRaw = $req->get('days', null, 'get');
                $days = $daysRaw === null ? 7 : max(1, min((int) $daysRaw, 365));
                $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');
                $to   = (new \DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');
                $limitRaw = $req->get('limit', null, 'get');
                return Response::json([
                    'success' => true,
                    'mode'    => 'genres',
                    'days'    => $days,
                    'genres'  => $service->getTopGenres($from, $to, $limitRaw === null ? 50 : min((int) $limitRaw, 200)),
                ]);
            } elseif ($mode === 'albums') {
                $daysRaw = $req->get('days', null, 'get');
                $days = $daysRaw === null ? 7 : max(1, min((int) $daysRaw, 365));
                $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');
                $to   = (new \DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');
                $limitRaw = $req->get('limit', null, 'get');
                return Response::json([
                    'success' => true,
                    'mode'    => 'albums',
                    'days'    => $days,
                    'albums'  => $service->getTopAlbums($from, $to, $limitRaw === null ? 50 : min((int) $limitRaw, 200)),
                ]);
            } elseif ($mode === 'artist') {
                // Drill-down for one artist: summary + their tracks + editable row.
                $artist = trim($req->get('artist', '', 'get'));
                if ($artist === '') {
                    throw new InvalidArgumentException('artist is required');
                }
                $summary = $service->getArtistSummary($artist);
                if (!$summary) {
                    return Response::json(['error' => 'Artist not found'], 404);
                }
                // Persist the artist image if missing, so it also shows up in the lists.
                $service->ensureArtistImage($artist);
                return Response::json([
                    'success'    => true,
                    'mode'       => 'artist',
                    'artist'     => $artist,
                    'summary'    => $summary,
                    'artist_row' => $service->getArtistRowByName($artist),
                    'albums'     => $service->getAlbumsByArtist($artist),
                    'tracks'     => $service->getArtistTracks($artist),
                ]);
            } elseif ($mode === 'genre-list') {
                return Response::json(['success' => true, 'mode' => 'genre-list', 'genres' => $service->getGenreList()]);
            } elseif ($mode === 'all-artists') {
                return Response::json([
                    'success' => true,
                    'mode'    => 'all-artists',
                    'artists' => $service->getAllArtists(),
                ]);
            } elseif ($mode === 'search') {
                $q = trim($req->get('q', '', 'get'));
                return Response::json([
                    'success' => true,
                    'mode'    => 'search',
                    'q'       => $q,
                    'tracks'  => $q === '' ? [] : $service->searchTracks($q),
                ]);
            } elseif ($mode === 'genre') {
                $genre = trim($req->get('genre', '', 'get'));
                if ($genre === '') {
                    throw new InvalidArgumentException('genre is required');
                }
                return Response::json([
                    'success' => true,
                    'mode'    => 'genre',
                    'genre'   => $genre,
                    'tracks'  => $service->getTracksByGenre($genre),
                ]);
            } elseif ($mode === 'album') {
                $albumId = (int) $req->get('album_id', 0, 'get');
                if ($albumId <= 0) {
                    throw new InvalidArgumentException('album_id is required');
                }
                $detail = $service->getAlbumDetail($albumId);
                if (!$detail) {
                    return Response::json(['error' => 'Album not found'], 404);
                }
                return Response::json(['success' => true, 'mode' => 'album'] + $detail);
            } elseif ($mode === 'track') {
                // Reverse log: all play times for one track.
                $trackId = (int) $req->get('track_id', 0, 'get');
                if ($trackId <= 0) {
                    throw new InvalidArgumentException('track_id is required');
                }
                $track = $service->getTrackById($trackId);
                if (!$track) {
                    return Response::json(['error' => 'Track not found'], 404);
                }
                return Response::json([
                    'success' => true,
                    'mode'    => 'track',
                    'track'   => $track,
                    'plays'   => $service->getTrackPlays($trackId),
                ]);
            } else {
                // Summary over the last N days (default 7).
                $daysRaw = $req->get('days', null, 'get');
                $days = $daysRaw === null ? 7 : (int) $daysRaw;
                return Response::json([
                    'success' => true,
                    'mode'    => 'summary',
                    'summary' => $service->getSummary($days),
                ]);
            }
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log($e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * GET /api/admin/bot-activity — bot conversations, LLM call log and usage.
     *
     * Migrated from public/api/admin/bot-activity.php. Because the log holds full
     * private conversations, this keeps the legacy RBAC check on top of admin auth:
     * only root/owner/administrator may read it (otherwise 403). Dispatches on
     * ?view (threads|thread|log|call|summary|balance). Success payloads carry
     * {success, ...} per branch; missing thread params -> 400; unknown call id ->
     * 404; unknown view -> 400; failure -> 500 {error:'Failed to load bot activity'}.
     */
    #[Route('/api/admin/bot-activity', methods: 'GET', name: 'admin.stats.bot-activity', middleware: [AdminAuthMiddleware::class])]
    public function botActivity(): Response
    {
        // The log holds full conversations, so keep it to the roles that may already
        // read private messages.
        $currentUser = AdminAuth::getCurrentUser();
        if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner', 'administrator'], true)) {
            return Response::json(['error' => 'Forbidden: bot activity includes private conversations'], 403);
        }

        try {
            $req      = Request::getInstance();
            $settings = new SettingsService();
            $log      = new LlmLog($settings);
            $bot      = new BotService($settings);
            $view     = $req->get('view', 'threads', 'get');

            switch ($view) {
                case 'threads':
                    return Response::json([
                        'success' => true,
                        'enabled' => $settings->get('bot_replies_enabled', 'false') === 'true',
                        // So the panel can show strikes as "2/3" rather than a bare count.
                        'insult_threshold' => $bot->insultBlockThreshold(),
                        'total' => $bot->countThreads(),
                        'threads' => $bot->listThreads(
                            (int) $req->get('limit', 100, 'get'),
                            (int) $req->get('offset', 0, 'get')
                        ),
                    ]);

                case 'thread':
                    $fakeUser = trim((string) $req->get('fake_user', '', 'get'));
                    $peer     = trim((string) $req->get('peer', '', 'get'));

                    $required = 'fake_user and peer are required';
                    if ($error = Validate::check(
                        ['fake_user' => $fakeUser, 'peer' => $peer],
                        ['fake_user' => 'required', 'peer' => 'required'],
                        ['fake_user.required' => $required, 'peer.required' => $required]
                    )) {
                        return $error;
                    }

                    return Response::json([
                        'success'          => true,
                        'insult_threshold' => $bot->insultBlockThreshold(),
                        'state'            => $bot->getThreadState($fakeUser, $peer),
                        'messages'         => $bot->threadMessages($fakeUser, $peer),
                        'calls'            => array_map([$this, 'decodeJsonColumns'], $log->page(20, 0, [
                            'fake_nickname' => $fakeUser,
                            'peer_username' => $peer,
                        ])['entries']),
                    ]);

                case 'log':
                    $page = $log->page(
                        (int) $req->get('limit', 25, 'get'),
                        (int) $req->get('offset', 0, 'get'),
                        [
                            'problems_only' => !empty($_GET['problems']),
                            'fake_nickname' => $req->get('fake_user', null, 'get'),
                            'purpose'       => $req->get('purpose', null, 'get'),
                        ]
                    );

                    return Response::json([
                        'success' => true,
                        'logging' => $log->isEnabled(),
                        'total'   => $page['total'],
                        'entries' => array_map([$this, 'decodeJsonColumns'], $page['entries']),
                    ]);

                case 'call':
                    $entry = $log->find((int) $req->get('id', 0, 'get'));

                    if ($entry === null) {
                        return Response::json(['error' => 'Log entry not found'], 404);
                    }

                    return Response::json(['success' => true, 'call' => $this->decodeJsonColumns($entry)]);

                case 'summary':
                    $account = new LlmAccount($settings);

                    $summary = [];
                    foreach (['1h' => 1, '24h' => 24, '7d' => 24 * 7] as $label => $hours) {
                        $summary[$label] = $log->summary($hours);
                        // Real money for the same window, where enough balance readings
                        // exist - the estimate above is only as good as the unit prices.
                        $summary[$label]['real_spend'] = $account->realSpend($hours);
                        // Split by provider: with two configured at once, "what did it cost"
                        // is not one number.
                        $summary[$label]['by_provider'] = $log->summaryByProvider($hours);
                    }

                    // Every configured provider with its own account figure: a balance where
                    // there is one, otherwise what the provider says was spent.
                    $accounts = [];
                    foreach (array_keys(LlmProviders::PROVIDERS) as $providerId) {
                        $providerAccount = new LlmAccount($settings, null, $providerId);

                        $accounts[$providerId] = [
                            'label'            => LlmProviders::config($providerId)['label'],
                            'is_default'       => $providerId === $account->getProvider(),
                            'configured'       => $providerAccount->isConfigured(),
                            'supports_balance' => $providerAccount->supportsBalance(),
                            'supports_costs'   => $providerAccount->supportsCosts(),
                            'has_admin_key'    => $providerAccount->hasAdminKey(),
                            'balance'          => $providerAccount->balance(),
                            'costs'            => $providerAccount->providerCosts(7 * 24),
                            // No provider publishes a remaining balance except DeepSeek, so for
                            // the others this is the figure that maps to the bill.
                            'costs_month'      => $providerAccount->monthToDateCosts(),
                        ];
                    }

                    return Response::json([
                        'success'       => true,
                        'logging'       => $log->isEnabled(),
                        'summary'       => $summary,
                        'accounts'      => $accounts,
                        'provider'      => $account->getProvider(),
                        'currency'      => LlmPricing::fromSettings($settings)->getCurrency(),
                        'priced_models' => array_keys(LlmPricing::fromSettings($settings)->all()),
                    ]);

                case 'balance':
                    // Straight from the provider: the one figure that is not an estimate.
                    // Providers are configured independently, so each has its own balance.
                    $account = new LlmAccount($settings, null, $req->get('provider', null, 'get'));

                    return Response::json([
                        'success'    => true,
                        'configured' => $account->isConfigured(),
                        'provider'   => $account->getProvider(),
                        // Not every provider has a balance endpoint (OpenAI does not), and
                        // that is different from a failed read.
                        'supports_balance' => $account->supportsBalance(),
                        'balance'          => $account->balance(!empty($_GET['fresh'])),
                        // OpenAI reports no balance but does report real spend, to an admin key.
                        'supports_costs' => $account->supportsCosts(),
                        'has_admin_key'  => $account->hasAdminKey(),
                        'costs'          => $account->providerCosts(7 * 24),
                    ]);

                default:
                    return Response::json(['error' => 'Unknown view'], 400);
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('bot-activity error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to load bot activity'], 500);
        }
    }

    /**
     * GET /api/admin/bot-llm-stats — token usage and health of the auto-reply bots.
     *
     * Migrated from public/api/admin/bot-llm-stats.php. No inputs. Success 200:
     * {success, enabled, logging, model, reasoning, max_tokens, summary:{1h,24h,7d},
     * currency, balance, problems}. Failure -> 500 {error:'Failed to load bot LLM stats'}.
     */
    #[Route('/api/admin/bot-llm-stats', methods: 'GET', name: 'admin.stats.bot-llm-stats', middleware: [AdminAuthMiddleware::class])]
    public function botLlmStats(): Response
    {
        try {
            $settings = new SettingsService();
            $log      = new LlmLog($settings);

            $windows = ['1h' => 1, '24h' => 24, '7d' => 24 * 7];
            $summary = [];

            foreach ($windows as $label => $hours) {
                $row = $log->summary($hours);

                $summary[$label] = [
                    'calls'            => (int) ($row['calls'] ?? 0),
                    'errors'           => (int) ($row['errors'] ?? 0),
                    'truncated'        => (int) ($row['truncated'] ?? 0),
                    'total_tokens'     => (int) ($row['total_tokens'] ?? 0),
                    'reasoning_tokens' => (int) ($row['reasoning_tokens'] ?? 0),
                    'avg_duration_ms'  => $row['avg_duration_ms'] === null ? null : (int) $row['avg_duration_ms'],
                    // Estimated from the configured unit prices; see LlmPricing.
                    'cost'             => (float) ($row['cost'] ?? 0),
                    'uncosted_calls'   => (int) ($row['uncosted_calls'] ?? 0),
                ];
            }

            $problems = array_map(
                static fn (array $entry): array => [
                    'created_at'    => $entry['created_at'],
                    'fake_nickname' => $entry['fake_nickname'],
                    'peer_username' => $entry['peer_username'],
                    'finish_reason' => $entry['finish_reason'],
                    'error'         => $entry['error'],
                ],
                $log->recent(5, true)
            );

            return Response::json([
                'success'    => true,
                'enabled'    => $settings->get('bot_replies_enabled', 'false') === 'true',
                'logging'    => $log->isEnabled(),
                'model'      => $settings->get('bot_llm_model', ''),
                'reasoning'  => $settings->get('bot_llm_reasoning', 'false') === 'true',
                'max_tokens' => (int) $settings->get('bot_llm_max_tokens', 0),
                'summary'    => $summary,
                'currency'   => LlmPricing::fromSettings($settings)->getCurrency(),
                // Real money, from the provider - the cost figures above are an estimate.
                'balance'    => (new LlmAccount($settings))->balance(),
                'problems'   => $problems,
            ]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('bot-llm-stats error: ' . $e->getMessage(), 'radiochatbox');
            return Response::json(['error' => 'Failed to load bot LLM stats'], 500);
        }
    }

    /**
     * JSONB columns come back as strings; hand the client real objects.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function decodeJsonColumns(array $row): array
    {
        foreach (['usage', 'messages'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $row[$column] = json_decode($row[$column], true);
            }
        }

        return $row;
    }
}
