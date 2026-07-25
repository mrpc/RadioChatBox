<?php

/**
 * Token usage and health of the fake user auto-reply bots.
 *
 * GET -> { enabled, logging, summary: { '1h': {...}, '24h': {...}, '7d': {...} },
 *          problems: [ last few failed or truncated calls ] }
 *
 * Powers the dashboard card: without it a wrong token budget is invisible until
 * users start receiving replies cut off mid-sentence.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\CorsHandler;
use RadioChatBox\LlmLog;
use RadioChatBox\SettingsService;

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $settings = new SettingsService();
    $log = new LlmLog($settings);

    $windows = ['1h' => 1, '24h' => 24, '7d' => 24 * 7];
    $summary = [];

    foreach ($windows as $label => $hours) {
        $row = $log->summary($hours);

        $summary[$label] = [
            'calls' => (int) ($row['calls'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
            'truncated' => (int) ($row['truncated'] ?? 0),
            'total_tokens' => (int) ($row['total_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($row['reasoning_tokens'] ?? 0),
            'avg_duration_ms' => $row['avg_duration_ms'] === null ? null : (int) $row['avg_duration_ms'],
        ];
    }

    $problems = array_map(
        static fn (array $entry): array => [
            'created_at' => $entry['created_at'],
            'fake_nickname' => $entry['fake_nickname'],
            'peer_username' => $entry['peer_username'],
            'finish_reason' => $entry['finish_reason'],
            'error' => $entry['error'],
        ],
        $log->recent(5, true)
    );

    echo json_encode([
        'success' => true,
        'enabled' => $settings->get('bot_replies_enabled', 'false') === 'true',
        'logging' => $log->isEnabled(),
        'model' => $settings->get('bot_llm_model', ''),
        'reasoning' => $settings->get('bot_llm_reasoning', 'false') === 'true',
        'max_tokens' => (int) $settings->get('bot_llm_max_tokens', 0),
        'summary' => $summary,
        'problems' => $problems,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load bot LLM stats']);
    error_log('bot-llm-stats error: ' . $e->getMessage());
}
