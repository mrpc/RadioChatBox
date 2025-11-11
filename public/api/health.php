<?php

/**
 * Health Check Endpoint
 * Returns the status of all services
 */

header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'services' => []
];

// Check Redis
try {
    $redis = new Redis();
    $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int)(getenv('REDIS_PORT') ?: 6379));
    $redis->ping();
    $health['services']['redis'] = [
        'status' => 'up',
        'message' => 'Connected'
    ];
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['services']['redis'] = [
        'status' => 'down',
        'message' => $e->getMessage()
    ];
}

// Check PostgreSQL
try {
    $config = [
        'host' => getenv('DB_HOST') ?: 'postgres',
        'port' => (int)(getenv('DB_PORT') ?: 5432),
        'name' => getenv('DB_NAME') ?: 'radiochatbox',
        'user' => getenv('DB_USER') ?: 'radiochatbox',
        'password' => getenv('DB_PASSWORD') ?: 'radiochatbox_secret',
    ];
    
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['name']);
    $pdo = new PDO($dsn, $config['user'], $config['password']);
    
    // Test query
    $stmt = $pdo->query('SELECT COUNT(*) FROM messages');
    $count = $stmt->fetchColumn();
    
    $health['services']['postgresql'] = [
        'status' => 'up',
        'message' => 'Connected',
        'message_count' => (int)$count
    ];
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['services']['postgresql'] = [
        'status' => 'down',
        'message' => $e->getMessage()
    ];
}

// Check PHP version
$health['services']['php'] = [
    'status' => 'up',
    'version' => PHP_VERSION
];

// Overall status code
if ($health['status'] === 'healthy') {
    http_response_code(200);
} else {
    http_response_code(503);
}

echo json_encode($health, JSON_PRETTY_PRINT);
