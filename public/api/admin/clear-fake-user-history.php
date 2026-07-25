<?php

/**
 * Wipe a fake user's conversations so its bot can be tested from scratch.
 *
 * POST { id: int } or { nickname: string }
 *   -> { success, cleared: { messages, threads, epochs } }
 *
 * Deletes the private messages in both directions, the per-thread bot state
 * (message budget, takeover, farewell flag) and the queued-reply epochs.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\BotService;
use RadioChatBox\CorsHandler;
use RadioChatBox\FakeUserService;

CorsHandler::handle();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!AdminAuth::verify()) {
    AdminAuth::unauthorized();
}

// Destructive, so hold it to the same bar as impersonation.
$currentUser = AdminAuth::getCurrentUser();
if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner', 'administrator'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: not allowed to delete conversations']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON');
    }

    $nickname = trim((string) ($input['nickname'] ?? ''));

    if ($nickname === '') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Fake user id or nickname is required');
        }

        $fakeUser = (new FakeUserService())->getFakeUserById($id);
        if ($fakeUser === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Fake user not found']);
            exit;
        }

        $nickname = (string) $fakeUser['nickname'];
    }

    $cleared = (new BotService())->clearHistoryFor($nickname);

    error_log(sprintf(
        'Admin %s cleared bot history for %s (%d messages, %d threads)',
        $currentUser['username'] ?? '?',
        $nickname,
        $cleared['messages'],
        $cleared['threads']
    ));

    echo json_encode([
        'success' => true,
        'nickname' => $nickname,
        'cleared' => $cleared,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to clear the history']);
    error_log('clear-fake-user-history error: ' . $e->getMessage());
}
