<?php

/**
 * Bot activity for the admin panel: conversations, the LLM call log and usage.
 *
 * GET ?view=threads                          -> every bot conversation with its state
 * GET ?view=thread&fake_user=X&peer=Y        -> the messages of one conversation
 * GET ?view=log[&problems=1][&limit&offset]  -> paginated LLM calls
 * GET ?view=call&id=N                        -> one call with its full prompt and response
 * GET ?view=summary                          -> token usage per window (also on the dashboard)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\AdminAuth;
use RadioChatBox\BotService;
use RadioChatBox\CorsHandler;
use RadioChatBox\LlmAccount;
use RadioChatBox\LlmLog;
use RadioChatBox\LlmPricing;
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

// The log holds full conversations, so keep it to the roles that may already
// read private messages.
$currentUser = AdminAuth::getCurrentUser();
if (!$currentUser || !in_array($currentUser['role'], ['root', 'owner', 'administrator'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: bot activity includes private conversations']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

/**
 * JSONB columns come back as strings; hand the client real objects.
 *
 * @param array<string,mixed> $row
 *
 * @return array<string,mixed>
 */
function decodeJsonColumns(array $row): array
{
    foreach (['usage', 'messages'] as $column) {
        if (isset($row[$column]) && is_string($row[$column])) {
            $row[$column] = json_decode($row[$column], true);
        }
    }

    return $row;
}

try {
    $settings = new SettingsService();
    $log = new LlmLog($settings);
    $bot = new BotService($settings);
    $view = $_GET['view'] ?? 'threads';

    switch ($view) {
        case 'threads':
            echo json_encode([
                'success' => true,
                'enabled' => $settings->get('bot_replies_enabled', 'false') === 'true',
                'threads' => $bot->listThreads((int) ($_GET['limit'] ?? 100)),
            ]);
            break;

        case 'thread':
            $fakeUser = trim((string) ($_GET['fake_user'] ?? ''));
            $peer = trim((string) ($_GET['peer'] ?? ''));

            if ($fakeUser === '' || $peer === '') {
                http_response_code(400);
                echo json_encode(['error' => 'fake_user and peer are required']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'state' => $bot->getThreadState($fakeUser, $peer),
                'messages' => $bot->threadMessages($fakeUser, $peer),
                'calls' => array_map('decodeJsonColumns', $log->page(20, 0, [
                    'fake_nickname' => $fakeUser,
                    'peer_username' => $peer,
                ])['entries']),
            ]);
            break;

        case 'log':
            $page = $log->page(
                (int) ($_GET['limit'] ?? 25),
                (int) ($_GET['offset'] ?? 0),
                [
                    'problems_only' => !empty($_GET['problems']),
                    'fake_nickname' => $_GET['fake_user'] ?? null,
                    'purpose' => $_GET['purpose'] ?? null,
                ]
            );

            echo json_encode([
                'success' => true,
                'logging' => $log->isEnabled(),
                'total' => $page['total'],
                'entries' => array_map('decodeJsonColumns', $page['entries']),
            ]);
            break;

        case 'call':
            $entry = $log->find((int) ($_GET['id'] ?? 0));

            if ($entry === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Log entry not found']);
                exit;
            }

            echo json_encode(['success' => true, 'call' => decodeJsonColumns($entry)]);
            break;

        case 'summary':
            $account = new LlmAccount($settings);

            $summary = [];
            foreach (['1h' => 1, '24h' => 24, '7d' => 24 * 7] as $label => $hours) {
                $summary[$label] = $log->summary($hours);
                // Real money for the same window, where enough balance readings
                // exist - the estimate above is only as good as the unit prices.
                $summary[$label]['real_spend'] = $account->realSpend($hours);
            }

            echo json_encode([
                'success' => true,
                'logging' => $log->isEnabled(),
                'summary' => $summary,
                'balance' => $account->balance(),
                'currency' => LlmPricing::fromSettings($settings)->getCurrency(),
                'priced_models' => array_keys(LlmPricing::fromSettings($settings)->all()),
            ]);
            break;

        case 'balance':
            // Straight from the provider: the one figure that is not an estimate.
            $account = new LlmAccount($settings);

            echo json_encode([
                'success' => true,
                'configured' => $account->isConfigured(),
                'balance' => $account->balance(!empty($_GET['fresh'])),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown view']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load bot activity']);
    error_log('bot-activity error: ' . $e->getMessage());
}
