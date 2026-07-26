<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\FakeUserService;

// Handle CORS
CorsHandler::handle();

header('Content-Type: application/json');

// Check authentication
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

    // Accept both an export file ({"fake_users": [...]}) and a bare array.
    $rows = $input['fake_users'] ?? $input;
    if (!is_array($rows) || $rows === []) {
        throw new InvalidArgumentException('No fake users to import');
    }

    // Opt-in: overwrite the settings of fake users that already exist.
    $updateExisting = !empty($input['update_existing']);

    $fakeUserService = new FakeUserService();
    $result = $fakeUserService->importFakeUsers(array_values($rows), $updateExisting);

    echo json_encode([
        'success' => true,
        'imported' => count($result['imported']),
        'updated' => count($result['updated']),
        'skipped' => count($result['skipped']),
        'invalid' => count($result['invalid']),
        'imported_nicknames' => $result['imported'],
        'updated_nicknames' => $result['updated'],
        'skipped_nicknames' => $result['skipped'],
        'invalid_rows' => $result['invalid'],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e->getMessage());
}
