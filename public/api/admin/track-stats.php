<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\TrackStatsService;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!AdminAuth::verify()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $service = new TrackStatsService();

    // ---- POST: edit metadata / trigger enrichment ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? '';

        if ($action === 'update-track') {
            $trackId = (int)($input['track_id'] ?? 0);
            if ($trackId <= 0) {
                throw new InvalidArgumentException('track_id is required');
            }
            $service->updateTrackMeta($trackId, $input);
            echo json_encode(['success' => true]);
        } elseif ($action === 'update-artist') {
            $artistId = (int)($input['artist_id'] ?? 0);
            if ($artistId <= 0) {
                throw new InvalidArgumentException('artist_id is required');
            }
            $service->updateArtistMeta($artistId, $input);
            echo json_encode(['success' => true]);
        } elseif ($action === 'update-album') {
            $albumId = (int)($input['album_id'] ?? 0);
            if ($albumId <= 0) {
                throw new InvalidArgumentException('album_id is required');
            }
            $service->updateAlbumMeta($albumId, $input);
            echo json_encode(['success' => true]);
        } elseif ($action === 'enrich') {
            $trackId = (int)($input['track_id'] ?? 0);
            if ($trackId <= 0) {
                throw new InvalidArgumentException('track_id is required');
            }
            $service->enrichTrack($trackId);
            echo json_encode(['success' => true]);
        } elseif ($action === 'bulk-genre-tracks') {
            $ids = $input['track_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                throw new InvalidArgumentException('track_ids are required');
            }
            $n = $service->bulkSetGenreForTracks($ids, (string)($input['genre'] ?? ''));
            echo json_encode(['success' => true, 'updated' => $n]);
        } elseif ($action === 'bulk-genre-artist') {
            $artist = trim($input['artist'] ?? '');
            if ($artist === '') {
                throw new InvalidArgumentException('artist is required');
            }
            $n = $service->bulkSetGenreByArtist($artist, (string)($input['genre'] ?? ''));
            echo json_encode(['success' => true, 'updated' => $n]);
        } elseif ($action === 'bulk-genre-reassign') {
            $from = trim($input['from'] ?? '');
            if ($from === '') {
                throw new InvalidArgumentException('from genre is required');
            }
            $n = $service->bulkReassignGenre($from, (string)($input['to'] ?? ''));
            echo json_encode(['success' => true, 'updated' => $n]);
        } elseif ($action === 'enrich-artist') {
            $artist = trim($input['artist'] ?? '');
            if ($artist === '') {
                throw new InvalidArgumentException('artist is required');
            }
            $service->ensureArtistImage($artist, true); // force re-fetch
            echo json_encode(['success' => true]);
        } else {
            throw new InvalidArgumentException('Unknown action');
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $mode = $_GET['mode'] ?? 'summary';

    if ($mode === 'log') {
        // Play log for a specific date (default: today).
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('date must be YYYY-MM-DD');
        }
        echo json_encode([
            'success' => true,
            'mode' => 'log',
            'date' => $date,
            'plays' => $service->getLog($date),
        ]);

    } elseif ($mode === 'top') {
        // Most-played tracks in a window (default: last 7 days).
        $from = $_GET['from'] ?? (new DateTimeImmutable('-7 days'))->format('Y-m-d 00:00:00');
        $to = $_GET['to'] ?? (new DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50;
        echo json_encode([
            'success' => true,
            'mode' => 'top',
            'from' => $from,
            'to' => $to,
            'tracks' => $service->getTopTracks($from, $to, $limit),
        ]);

    } elseif ($mode === 'artists') {
        // Most-played artists over the last N days (default 7).
        $days = isset($_GET['days']) ? max(1, min((int)$_GET['days'], 365)) : 7;
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50;
        $from = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');
        $to = (new DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');
        echo json_encode([
            'success' => true,
            'mode' => 'artists',
            'days' => $days,
            'artists' => $service->getTopArtists($from, $to, $limit),
        ]);

    } elseif ($mode === 'genres') {
        $days = isset($_GET['days']) ? max(1, min((int)$_GET['days'], 365)) : 7;
        $from = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');
        $to = (new DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');
        echo json_encode([
            'success' => true,
            'mode' => 'genres',
            'days' => $days,
            'genres' => $service->getTopGenres($from, $to, isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50),
        ]);

    } elseif ($mode === 'albums') {
        $days = isset($_GET['days']) ? max(1, min((int)$_GET['days'], 365)) : 7;
        $from = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');
        $to = (new DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');
        echo json_encode([
            'success' => true,
            'mode' => 'albums',
            'days' => $days,
            'albums' => $service->getTopAlbums($from, $to, isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50),
        ]);

    } elseif ($mode === 'artist') {
        // Drill-down for one artist: summary + their tracks + editable row.
        $artist = trim($_GET['artist'] ?? '');
        if ($artist === '') {
            throw new InvalidArgumentException('artist is required');
        }
        $summary = $service->getArtistSummary($artist);
        if (!$summary) {
            http_response_code(404);
            echo json_encode(['error' => 'Artist not found']);
            exit;
        }
        // Persist the artist image if missing, so it also shows up in the lists.
        $service->ensureArtistImage($artist);
        echo json_encode([
            'success' => true,
            'mode' => 'artist',
            'artist' => $artist,
            'summary' => $summary,
            'artist_row' => $service->getArtistRowByName($artist),
            'albums' => $service->getAlbumsByArtist($artist),
            'tracks' => $service->getArtistTracks($artist),
        ]);

    } elseif ($mode === 'genre-list') {
        echo json_encode(['success' => true, 'mode' => 'genre-list', 'genres' => $service->getGenreList()]);

    } elseif ($mode === 'all-artists') {
        echo json_encode([
            'success' => true,
            'mode' => 'all-artists',
            'artists' => $service->getAllArtists(),
        ]);

    } elseif ($mode === 'search') {
        $q = trim($_GET['q'] ?? '');
        echo json_encode([
            'success' => true,
            'mode' => 'search',
            'q' => $q,
            'tracks' => $q === '' ? [] : $service->searchTracks($q),
        ]);

    } elseif ($mode === 'genre') {
        $genre = trim($_GET['genre'] ?? '');
        if ($genre === '') {
            throw new InvalidArgumentException('genre is required');
        }
        echo json_encode([
            'success' => true,
            'mode' => 'genre',
            'genre' => $genre,
            'tracks' => $service->getTracksByGenre($genre),
        ]);

    } elseif ($mode === 'album') {
        $albumId = (int)($_GET['album_id'] ?? 0);
        if ($albumId <= 0) {
            throw new InvalidArgumentException('album_id is required');
        }
        $detail = $service->getAlbumDetail($albumId);
        if (!$detail) {
            http_response_code(404);
            echo json_encode(['error' => 'Album not found']);
            exit;
        }
        echo json_encode(['success' => true, 'mode' => 'album'] + $detail);

    } elseif ($mode === 'track') {
        // Reverse log: all play times for one track.
        $trackId = (int)($_GET['track_id'] ?? 0);
        if ($trackId <= 0) {
            throw new InvalidArgumentException('track_id is required');
        }
        $track = $service->getTrackById($trackId);
        if (!$track) {
            http_response_code(404);
            echo json_encode(['error' => 'Track not found']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'mode' => 'track',
            'track' => $track,
            'plays' => $service->getTrackPlays($trackId),
        ]);

    } else {
        // Summary over the last N days (default 7).
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
        echo json_encode([
            'success' => true,
            'mode' => 'summary',
            'summary' => $service->getSummary($days),
        ]);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
