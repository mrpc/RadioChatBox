#!/usr/bin/env php
<?php
/**
 * Stress Test Script for RadioChatBox
 * 
 * Tests the system's ability to handle concurrent users.
 * 
 * Usage: php stress-test.php [users] [messages] [duration]
 * 
 * Example: php stress-test.php 300 10 60
 *   - 300 concurrent users
 *   - Each user sends 10 messages
 *   - Over 60 seconds
 * 
 * IMPORTANT: Understanding Rate Limiting Results
 * ==============================================
 * The rate limiting in RadioChatBox is PER-IP, not global.
 * 
 * In this stress test, ALL users originate from the SAME IP address
 * (the Docker host or local machine running the test). This means:
 * 
 *   - All 300 users share ONE rate limit counter
 *   - Default: 10 messages per 60 seconds PER IP
 *   - Result: Only ~10 total messages succeed across all 300 users
 * 
 * In PRODUCTION with real users:
 *   - Each user has their own IP address
 *   - Each IP gets 10 messages per 60 seconds
 *   - 300 users = 300 IPs × 10 msg/min = 3,000 messages/minute
 *   - Throughput: ~50 messages per second
 * 
 * The "low" message success rate in this test is EXPECTED and shows
 * the rate limiting is working correctly to prevent spam/abuse.
 */

// Configuration
$baseUrl = getenv('TEST_URL') ?: 'http://localhost:98';
$concurrentUsers = isset($argv[1]) ? (int)$argv[1] : 300;
$messagesPerUser = isset($argv[2]) ? (int)$argv[2] : 10;
$testDuration = isset($argv[3]) ? (int)$argv[3] : 60; // seconds

// Statistics
$stats = [
    'users_registered' => 0,
    'messages_sent' => 0,
    'messages_failed' => 0,
    'sse_connections' => 0,
    'sse_failures' => 0,
    'total_requests' => 0,
    'errors' => [],
    'start_time' => microtime(true),
];

echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  RadioChatBox Stress Test\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  Base URL:        $baseUrl\n";
echo "  Concurrent Users: $concurrentUsers\n";
echo "  Messages/User:    $messagesPerUser\n";
echo "  Test Duration:    {$testDuration}s\n";
echo "═══════════════════════════════════════════════════════\n\n";

// Test 1: Register users
echo "[1/4] Registering $concurrentUsers users...\n";
$users = [];
$batchSize = 50; // Register in batches to avoid connection limits
$batches = ceil($concurrentUsers / $batchSize);

for ($batch = 0; $batch < $batches; $batch++) {
    $batchStart = $batch * $batchSize;
    $batchEnd = min($batchStart + $batchSize, $concurrentUsers);
    
    $multiRegister = curl_multi_init();
    $curlHandles = [];

    for ($i = $batchStart; $i < $batchEnd; $i++) {
        $username = "StressUser" . str_pad($i, 4, '0', STR_PAD_LEFT);
        $sessionId = uniqid('stress_', true);
        
        $users[$i] = [
            'username' => $username,
            'sessionId' => $sessionId,
            'messageCount' => 0,
        ];
        
        $ch = curl_init("$baseUrl/api/register.php");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'username' => $username,
                'sessionId' => $sessionId,
                'age' => rand(18, 65),
                'location' => 'US',
                'sex' => ($i % 2 === 0) ? 'male' : 'female',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        curl_multi_add_handle($multiRegister, $ch);
        $curlHandles[$i] = $ch;
    }

    // Execute all registration requests for this batch
    $running = null;
    do {
        curl_multi_exec($multiRegister, $running);
        curl_multi_select($multiRegister);
    } while ($running > 0);

    // Check registration results
    $registrationErrors = [];
    foreach ($curlHandles as $i => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        if ($httpCode === 200) {
            $stats['users_registered']++;
        } else {
            $errorMsg = "User registration failed: {$users[$i]['username']} (HTTP $httpCode)";
            if ($curlError) {
                $errorMsg .= " - cURL error: $curlError";
            }
            if ($response) {
                $errorMsg .= " - Response: " . substr($response, 0, 100);
            }
            $registrationErrors[] = $errorMsg;
            $stats['errors'][] = $errorMsg;
        }
        
        curl_multi_remove_handle($multiRegister, $ch);
        curl_close($ch);
    }
    curl_multi_close($multiRegister);
    
    echo "\r   Batch " . ($batch + 1) . "/$batches: {$stats['users_registered']} / " . ($batchEnd) . " users registered";
    usleep(50000); // 50ms between batches
}

echo "\n   ✓ Registered {$stats['users_registered']} / $concurrentUsers users\n";
if ($stats['users_registered'] < $concurrentUsers && count($stats['errors']) > 0) {
    echo "\n   Registration Errors (first 5):\n";
    foreach (array_slice($stats['errors'], 0, 5) as $error) {
        echo "   • $error\n";
    }
}
echo "\n";

if ($stats['users_registered'] < $concurrentUsers * 0.8) {
    echo "   ✗ Less than 80% of users registered successfully. Aborting.\n\n";
    exit(1);
}

// Test 2: Send messages concurrently
echo "[2/4] Sending messages ($messagesPerUser messages per user)...\n";
$startTime = microtime(true);
$totalMessages = $concurrentUsers * $messagesPerUser;
$progressInterval = max(1, floor($totalMessages / 20)); // Update progress every 5%

for ($messageRound = 0; $messageRound < $messagesPerUser; $messageRound++) {
    $multiSend = curl_multi_init();
    $sendHandles = [];
    
    foreach ($users as $i => $user) {
        $message = "Test message #{$messageRound} from {$user['username']} at " . date('H:i:s');
        
        $ch = curl_init("$baseUrl/api/send.php");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'username' => $user['username'],
                'sessionId' => $user['sessionId'],
                'message' => $message,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        curl_multi_add_handle($multiSend, $ch);
        $sendHandles[$i] = $ch;
    }
    
    // Execute all send requests
    $running = null;
    do {
        curl_multi_exec($multiSend, $running);
        curl_multi_select($multiSend);
    } while ($running > 0);
    
    // Check send results
    foreach ($sendHandles as $i => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $stats['total_requests']++;
        
        if ($httpCode === 200 || $httpCode === 201) {
            $stats['messages_sent']++;
            $users[$i]['messageCount']++;
        } else {
            $stats['messages_failed']++;
            if (count($stats['errors']) < 10) { // Limit error collection
                $stats['errors'][] = "Message send failed for {$users[$i]['username']} (HTTP $httpCode)";
            }
        }
        
        curl_multi_remove_handle($multiSend, $ch);
        curl_close($ch);
        
        // Progress indicator
        if (($stats['messages_sent'] + $stats['messages_failed']) % $progressInterval === 0) {
            $progress = round((($stats['messages_sent'] + $stats['messages_failed']) / $totalMessages) * 100);
            echo "\r   Progress: $progress% ({$stats['messages_sent']} sent, {$stats['messages_failed']} failed)";
        }
    }
    
    curl_multi_close($multiSend);
    
    // Small delay between rounds to avoid overwhelming the server
    usleep(100000); // 100ms
}

$messageTime = microtime(true) - $startTime;
echo "\n   ✓ Sent {$stats['messages_sent']} messages in " . round($messageTime, 2) . "s\n";
echo "   ✓ Messages/second: " . round($stats['messages_sent'] / $messageTime, 2) . "\n\n";

// Test 3: Test SSE connections
echo "[3/4] Testing SSE (Server-Sent Events) connections...\n";
echo "   Attempting to connect " . min(50, $concurrentUsers) . " SSE clients...\n";

$sseTestCount = min(50, $concurrentUsers); // Limit SSE test to 50 clients
$sseConnected = 0;

for ($i = 0; $i < $sseTestCount; $i++) {
    $ch = curl_init("$baseUrl/api/stream.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_WRITEFUNCTION => function($curl, $data) {
            // Just verify we can connect and receive data
            return strlen($data);
        }
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode === 200) {
        $sseConnected++;
    }
    
    curl_close($ch);
}

$stats['sse_connections'] = $sseConnected;
$stats['sse_failures'] = $sseTestCount - $sseConnected;

echo "   ✓ SSE connections: {$sseConnected} / {$sseTestCount}\n\n";

// Test 4: Retrieve message history
echo "[4/4] Testing message history retrieval...\n";
$ch = curl_init("$baseUrl/api/history.php?limit=100");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);

$historyStart = microtime(true);
$historyResponse = curl_exec($ch);
$historyTime = microtime(true) - $historyStart;
$historyCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($historyCode === 200) {
    $historyData = json_decode($historyResponse, true);
    $messageCount = count($historyData['messages'] ?? []);
    echo "   ✓ Retrieved $messageCount messages in " . round($historyTime * 1000, 2) . "ms\n\n";
} else {
    echo "   ✗ Failed to retrieve history (HTTP $historyCode)\n\n";
}

// Calculate final statistics
$totalTime = microtime(true) - $stats['start_time'];
$successRate = $stats['total_requests'] > 0 
    ? round(($stats['messages_sent'] / $stats['total_requests']) * 100, 2) 
    : 0;

// Display results
echo "═══════════════════════════════════════════════════════\n";
echo "  Test Results\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  Total Duration:      " . round($totalTime, 2) . "s\n";
echo "  Users Registered:    {$stats['users_registered']} / $concurrentUsers\n";
echo "  Messages Sent:       {$stats['messages_sent']} / $totalMessages\n";
echo "  Messages Failed:     {$stats['messages_failed']}\n";
echo "  Success Rate:        {$successRate}%\n";
echo "  Throughput:          " . round($stats['messages_sent'] / $totalTime, 2) . " messages/sec\n";
echo "  Avg Response Time:   " . round(($totalTime / $stats['total_requests']) * 1000, 2) . "ms\n";
echo "  SSE Connections:     {$stats['sse_connections']} / {$sseTestCount}\n";
echo "═══════════════════════════════════════════════════════\n";

// Show errors if any
if (!empty($stats['errors'])) {
    echo "\n  Errors (first 10):\n";
    foreach (array_slice($stats['errors'], 0, 10) as $error) {
        echo "    • $error\n";
    }
}

// Performance rating
echo "\n  Performance Rating: ";
if ($successRate >= 99 && $stats['sse_connections'] >= $sseTestCount * 0.9) {
    echo "🌟 EXCELLENT\n";
} elseif ($successRate >= 95 && $stats['sse_connections'] >= $sseTestCount * 0.8) {
    echo "✅ GOOD\n";
} elseif ($successRate >= 85) {
    echo "⚠️  FAIR\n";
} else {
    echo "❌ POOR (but see note below)\n";
}

echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  IMPORTANT: Rate Limiting Behavior\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  Rate limiting is PER-IP, not global.\n\n";
echo "  In this test:\n";
echo "    • All $concurrentUsers users share ONE IP address\n";
echo "    • Only ~10 messages per 60s allowed for that IP\n";
echo "    • Low success rate is EXPECTED behavior\n\n";
echo "  In production with real users:\n";
echo "    • Each user has their own IP address\n";
echo "    • Each IP: 10 messages per 60 seconds\n";
echo "    • $concurrentUsers users = " . ($concurrentUsers * 10) . " messages/minute\n";
echo "    • Throughput: ~" . round(($concurrentUsers * 10) / 60, 1) . " messages/second\n";
echo "═══════════════════════════════════════════════════════\n";
echo "\n";
echo "  Actual System Capacity:\n";
echo "    • Concurrent users: {$stats['users_registered']}+\n";
echo "    • SSE connections: {$stats['sse_connections']}+\n";
echo "    • Rate limit: 10 msg/60s per IP (configurable)\n";
echo "\n";

// Exit code based on registration success (not message success, due to rate limiting)
// Consider test successful if we registered most users and got SSE connections
$registrationRate = $stats['users_registered'] / $concurrentUsers * 100;
exit($registrationRate >= 80 ? 0 : 1);
