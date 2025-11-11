#!/usr/bin/env php
<?php
/**
 * Git Webhook Handler for RadioChatBox
 * 
 * Receives webhook from GitHub/GitLab/Gitea and triggers deployment
 * 
 * Setup:
 * 1. File is accessible at: https://yoursite.com/webhook.php
 * 2. Configure webhook secret in .env: WEBHOOK_SECRET=your-secret-key
 * 3. Add webhook URL in GitHub: https://yoursite.com/webhook.php
 * 4. Set content type: application/json
 * 5. Set secret to match WEBHOOK_SECRET
 */

// Load configuration
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Configuration
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: '');
define('DEPLOY_SCRIPT', __DIR__ . '/../deploy.sh');
define('LOG_FILE', __DIR__ . '/../webhook.log');
define('ALLOWED_BRANCH', getenv('DEPLOY_BRANCH') ?: 'main');

/**
 * Log message to file
 */
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
    echo $logEntry;
}

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Verify GitHub signature
 */
function verifyGitHubSignature($payload, $signature) {
    if (empty(WEBHOOK_SECRET)) {
        return true; // No secret configured, skip verification (NOT RECOMMENDED)
    }
    
    if (empty($signature)) {
        return false;
    }
    
    list($algo, $hash) = explode('=', $signature, 2);
    $expectedHash = hash_hmac($algo, $payload, WEBHOOK_SECRET);
    
    return hash_equals($expectedHash, $hash);
}

/**
 * Verify GitLab token
 */
function verifyGitLabToken($token) {
    if (empty(WEBHOOK_SECRET)) {
        return true;
    }
    
    return hash_equals(WEBHOOK_SECRET, $token);
}

// Main execution
logMessage('=== Webhook received ===');

// Get request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage('ERROR: Invalid request method: ' . $_SERVER['REQUEST_METHOD']);
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get raw payload
$payload = file_get_contents('php://input');
if (empty($payload)) {
    logMessage('ERROR: Empty payload');
    jsonResponse(['error' => 'Empty payload'], 400);
}

// Determine webhook source
$isGitHub = isset($_SERVER['HTTP_X_GITHUB_EVENT']);
$isGitLab = isset($_SERVER['HTTP_X_GITLAB_EVENT']);
$isGitea = isset($_SERVER['HTTP_X_GITEA_EVENT']);

logMessage('Webhook source: ' . ($isGitHub ? 'GitHub' : ($isGitLab ? 'GitLab' : ($isGitea ? 'Gitea' : 'Unknown'))));

// Verify signature/token
if ($isGitHub) {
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
    if (!verifyGitHubSignature($payload, $signature)) {
        logMessage('ERROR: Invalid GitHub signature');
        jsonResponse(['error' => 'Invalid signature'], 403);
    }
} elseif ($isGitLab) {
    $token = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? '';
    if (!verifyGitLabToken($token)) {
        logMessage('ERROR: Invalid GitLab token');
        jsonResponse(['error' => 'Invalid token'], 403);
    }
} elseif ($isGitea) {
    $signature = $_SERVER['HTTP_X_GITEA_SIGNATURE'] ?? '';
    if (!verifyGitHubSignature($payload, $signature)) {
        logMessage('ERROR: Invalid Gitea signature');
        jsonResponse(['error' => 'Invalid signature'], 403);
    }
}

// Parse payload
$data = json_decode($payload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage('ERROR: Invalid JSON payload');
    jsonResponse(['error' => 'Invalid JSON'], 400);
}

// Extract branch information
$branch = '';
if ($isGitHub) {
    // GitHub push event
    if (isset($data['ref'])) {
        $branch = str_replace('refs/heads/', '', $data['ref']);
    }
} elseif ($isGitLab) {
    // GitLab push event
    if (isset($data['ref'])) {
        $branch = str_replace('refs/heads/', '', $data['ref']);
    }
} elseif ($isGitea) {
    // Gitea push event
    if (isset($data['ref'])) {
        $branch = str_replace('refs/heads/', '', $data['ref']);
    }
}

logMessage('Branch: ' . $branch);
logMessage('Allowed branch: ' . ALLOWED_BRANCH);

// Check if this is the branch we want to deploy
if ($branch !== ALLOWED_BRANCH) {
    logMessage('INFO: Ignoring push to branch: ' . $branch);
    jsonResponse([
        'status' => 'ignored',
        'message' => 'Not deploying branch: ' . $branch
    ]);
}

// Check if deploy script exists
if (!file_exists(DEPLOY_SCRIPT)) {
    logMessage('ERROR: Deploy script not found: ' . DEPLOY_SCRIPT);
    jsonResponse(['error' => 'Deploy script not found'], 500);
}

// Make deploy script executable
chmod(DEPLOY_SCRIPT, 0755);

// Trigger deployment in background
logMessage('INFO: Triggering deployment...');
$deployCommand = DEPLOY_SCRIPT . ' >> ' . LOG_FILE . ' 2>&1 &';
exec($deployCommand);

logMessage('INFO: Deployment triggered successfully');

jsonResponse([
    'status' => 'success',
    'message' => 'Deployment triggered for branch: ' . $branch,
    'timestamp' => date('c')
]);
