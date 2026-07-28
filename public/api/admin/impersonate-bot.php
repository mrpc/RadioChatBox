<?php

/**
 * Bot control for an impersonated conversation.
 *
 * GET  ?fake_user=X&peer=Y            -> bot state for the conversation
 * POST {fake_user, peer, action}      -> take | release | reset
 *
 * "take" is called when an admin opens a conversation in the Impersonate tab:
 * from that moment the bot stops replying in that thread. "release" hands the
 * conversation back to the bot, "reset" also clears its message budget.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\BlockService;
use RadioChatBox\BotService;
use RadioChatBox\CorsHandler;

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

// Same restriction as the rest of the impersonation endpoints.
$currentUser = AdminAuth::getCurrentUser();
if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Only root/owner can access impersonation']);
    exit;
}

try {
    $bot = new BotService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $fakeUser = trim((string) ($_GET['fake_user'] ?? ''));
        $peer = trim((string) ($_GET['peer'] ?? ''));

        if ($fakeUser === '' || $peer === '') {
            http_response_code(400);
            echo json_encode(['error' => 'fake_user and peer are required']);
            exit;
        }

        $state = $bot->getThreadState($fakeUser, $peer);

        if ($state === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Fake user not found']);
            exit;
        }

        echo json_encode(['success' => true, 'state' => $state]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $fakeUser = trim((string) ($input['fake_user'] ?? ''));
    $peer = trim((string) ($input['peer'] ?? ''));
    $action = (string) ($input['action'] ?? 'take');

    if ($fakeUser === '' || $peer === '') {
        http_response_code(400);
        echo json_encode(['error' => 'fake_user and peer are required']);
        exit;
    }

    $ok = match ($action) {
        'take' => $bot->takeOverThread($fakeUser, $peer, (string) ($currentUser['username'] ?? '')),
        'release' => $bot->releaseThread($fakeUser, $peer, false),
        'reset' => $bot->releaseThread($fakeUser, $peer, true),
        'force' => $bot->forceReply($fakeUser, $peer),
        // Force-stop: silence the bot in this conversation only (reversible).
        'stop' => $bot->stopThread($fakeUser, $peer),
        // Block: the fake user blocks the peer (mutual DM block). forcePermanent
        // because a fake user is not a registered account but its block must stick.
        // Stop the bot here too, so no reply is in flight.
        'block' => (new BlockService())->blockUser($fakeUser, $peer, true) && $bot->stopThread($fakeUser, $peer),
        default => null,
    };

    if ($ok === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action (expected take, release, reset, force, stop or block)']);
        exit;
    }

    if (!$ok) {
        http_response_code(404);
        echo json_encode(['error' => 'Fake user not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'action' => $action,
        'state' => $bot->getThreadState($fakeUser, $peer),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update bot state']);
    error_log('impersonate-bot error: ' . $e->getMessage());
}
