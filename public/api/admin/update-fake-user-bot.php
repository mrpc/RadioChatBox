<?php

/**
 * Update the bot (LLM auto-reply) configuration of a fake user.
 *
 * POST {
 *   id: int,
 *   bot_enabled?: bool,
 *   bot_persona?: string,
 *   bot_custom_prompt?: string,
 *   bot_farewell_messages?: string,
 *   bot_max_messages?: int|null,
 *   bot_llm_provider?: string,   // '' = use the global provider
 *   bot_llm_model?: string,      // '' = use the global model
 *   bot_reply_language?: string, // auto|greek|greeklish|english
 *   bot_typing_seconds_per_word?: float|null
 * }
 *
 * Empty strings/nulls reset a field so the global setting applies again.
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

    $allowed = [
        'bot_enabled',
        'bot_persona',
        'bot_custom_prompt',
        'bot_farewell_messages',
        'bot_max_messages',
        'bot_ignore_chance',
        'bot_typing_seconds_per_word',
        'bot_llm_provider',
        'bot_llm_model',
        'bot_reply_language',
    ];

    $options = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $input)) {
            $options[$key] = $input[$key];
        }
    }

    // Keep prompts to a sane size - they are sent on every LLM request.
    foreach (['bot_persona', 'bot_custom_prompt'] as $key) {
        if (isset($options[$key]) && mb_strlen((string) $options[$key]) > 2000) {
            throw new InvalidArgumentException('Prompt fields are limited to 2000 characters');
        }
    }

    if (isset($options['bot_farewell_messages']) && mb_strlen((string) $options['bot_farewell_messages']) > 4000) {
        throw new InvalidArgumentException('Goodbye variants are limited to 4000 characters in total');
    }

    $service = new FakeUserService();
    $fakeUser = $service->updateBotSettings($id, $options);

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
    error_log('update-fake-user-bot error: ' . $e->getMessage());
}
