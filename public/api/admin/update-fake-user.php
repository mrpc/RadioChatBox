<?php

/**
 * Update a fake user's profile.
 *
 * POST { id: int, nickname?: string, age?: int|null, sex?: string|null, location?: string|null }
 *
 * Renaming also rewrites the nickname in existing conversations (private
 * messages and DM blocks) so the history is not orphaned - see
 * FakeUserService::updateFakeUser().
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
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

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Fake user id is required');
    }

    $fields = [];
    foreach (['nickname', 'age', 'sex', 'location'] as $key) {
        if (array_key_exists($key, $input)) {
            $fields[$key] = $input[$key];
        }
    }

    if (empty($fields)) {
        throw new InvalidArgumentException('Nothing to update');
    }

    $service = new FakeUserService();
    $fakeUser = $service->updateFakeUser($id, $fields);

    if ($fakeUser === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Fake user not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'fake_user' => $fakeUser,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log('update-fake-user error: ' . $e->getMessage());
}
