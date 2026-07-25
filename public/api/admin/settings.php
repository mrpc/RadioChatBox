<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use RadioChatBox\CorsHandler;
use RadioChatBox\AdminAuth;
use RadioChatBox\BotService;
use RadioChatBox\Database;
use RadioChatBox\LlmAccount;
use RadioChatBox\LlmPricing;
use RadioChatBox\LlmProviders;
use RadioChatBox\SettingsService;

header('Content-Type: application/json');

CorsHandler::handle();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Authenticate admin
if (!AdminAuth::authenticate()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getPDO();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get all settings
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings ORDER BY setting_key");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // SECURITY: Never send password hash to client
            if ($row['setting_key'] === 'admin_password_hash') {
                continue;
            }
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        // Get PHP's upload_max_filesize limit
        $phpMaxUpload = ini_get('upload_max_filesize');
        $phpMaxUploadMB = $phpMaxUpload;
        if (preg_match('/^(\d+)(K|M|G)$/i', $phpMaxUpload, $matches)) {
            $value = (int)$matches[1];
            $unit = strtoupper($matches[2]);
            $phpMaxUploadMB = $value * ($unit === 'G' ? 1024 : ($unit === 'M' ? 1 : 1/1024));
        }
        $settings['php_max_upload_mb'] = (int)$phpMaxUploadMB;
        
        // Generate embed code
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:98';
        $embedUrl = $protocol . '://' . $host . '/';
        
        $embedCode = '<iframe src="' . htmlspecialchars($embedUrl) . '" width="100%" height="600" frameborder="0" style="border: 1px solid #ccc;"></iframe>';
        
        $settings['embed_code'] = $embedCode;
        $settings['embed_url'] = $embedUrl;

        // Built-in goodbye variants used when bot_farewell_messages is empty.
        // Exposed read-only so the admin panel can show them as the placeholder
        // instead of duplicating the list in the frontend.
        $settings['bot_default_farewell_messages'] = BotService::DEFAULT_FAREWELLS;

        // Providers the bots can use, with their built-in model lists, so the
        // dropdowns can switch provider without a round trip.
        $settingsService = new SettingsService();
        $providers = [];
        foreach (LlmProviders::PROVIDERS as $providerId => $providerConfig) {
            $providers[$providerId] = [
                'label' => $providerConfig['label'],
                'models' => $providerConfig['models'],
                'default_model' => $providerConfig['default_model'],
                // Which setting holds each parameter, so the panel can render one
                // full block per provider without duplicating the key names.
                'settings' => $providerConfig['settings'],
                'api_key_url' => $providerConfig['api_key_url'],
                'default_base_url' => $providerConfig['base_url'],
                'supports_balance' => $providerConfig['balance_path'] !== null,
                // Real spend, where the provider reports it (OpenAI, via an admin key).
                'supports_costs' => $providerConfig['costs_path'] !== null,
            ];
        }
        $settings['bot_providers'] = $providers;
        $settings['bot_default_provider'] = LlmProviders::DEFAULT_PROVIDER;

        // Model list for the settings dropdown, from the active provider's /models
        // endpoint where possible so a retired model (as deepseek-chat was) shows
        // up without a code change; the built-in list is the fallback.
        $account = new LlmAccount($settingsService);
        $models = $account->models();
        $settings['bot_available_models'] = $models['models'];
        $settings['bot_available_models_source'] = $models['source'];
        $settings['bot_active_provider'] = $account->getProvider();

        // Scripts a bot can be told to write in.
        $settings['bot_languages'] = BotService::LANGUAGES;
        $settings['bot_default_context_prompt'] = BotService::DEFAULT_CONTEXT_PROMPT;
        $settings['bot_default_model'] = BotService::defaultModel();

        // The provider has no pricing endpoint, so the unit prices are a setting.
        // Show the built-in table when it is empty, so what is in force is visible.
        $settings['bot_default_llm_prices'] = LlmPricing::seedJson();

        echo json_encode(['success' => true, 'settings' => $settings]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Update settings
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }
        
        // The whitelist, clamping and validation live in SettingsService so they
        // can be tested; this endpoint only translates HTTP to that call.
        $phpMaxUpload = ini_get('upload_max_filesize');
        $phpMaxUploadMb = (float) $phpMaxUpload;
        if (preg_match('/^(\d+)(K|M|G)$/i', $phpMaxUpload, $matches)) {
            $value = (float) $matches[1];
            $unit = strtoupper($matches[2]);
            $phpMaxUploadMb = $value * ($unit === 'G' ? 1024 : ($unit === 'M' ? 1 : 1 / 1024));
        }

        $result = (new SettingsService())->updateFromAdmin($data, $phpMaxUploadMb);

        $response = ['success' => true, 'message' => 'Settings updated successfully'];

        if (!empty($result['ignored'])) {
            $response['ignored'] = $result['ignored'];
            $response['message'] .= ' (ignored unknown keys: ' . implode(', ', $result['ignored']) . ')';
        }

        // A rejected value was NOT saved: say so rather than reporting success.
        if (!empty($result['rejected'])) {
            $response['rejected'] = $result['rejected'];
            $response['message'] = 'Saved, except: ' . implode(' ', $result['rejected']);
        }

        echo json_encode($response);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
