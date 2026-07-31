<?php

namespace RadioChatBox\Controllers;

use PDO;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Routing\Attributes\Route;
use RadioChatBox\Services\CleanupService;
use Redis;

/**
 * Maintenance / ops endpoints (group "Maintenance", public — no admin auth).
 *
 * Migrated from public/api/health.php and public/api/cron/cleanup.php. Behaviour
 * is preserved exactly: the same JSON keys, status codes, per-service checks and
 * the cron token gate. CorsHandler / Content-Type / http_response_code / echo are
 * dropped per the framework (Response::json + the global CORS middleware handle
 * them); the cron endpoint keeps its own CRON_TOKEN check because it is not an
 * admin route.
 */
final class MaintenanceController
{
    /**
     * GET /api/health — status of Redis, PostgreSQL and PHP.
     *
     * Migrated from public/api/health.php. Connects to Redis and PostgreSQL using
     * the same environment-variable config and defaults, runs a COUNT(*) on
     * messages, and reports php version. Any failed service flips the overall
     * status to "unhealthy" and the HTTP status to 503; otherwise 200. Payload:
     * {status, timestamp, services{redis{status,message}, postgresql{status,
     * message[,message_count]}, php{status,version}}}.
     */
    #[Route('/api/health', methods: 'GET', name: 'maintenance.health')]
    public function health(): Response
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'services' => [],
        ];

        // Check Redis
        try {
            $redis = new Redis();
            $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379));
            $redis->ping();
            $health['services']['redis'] = [
                'status' => 'up',
                'message' => 'Connected',
            ];
        } catch (\Exception $e) {
            $health['status'] = 'unhealthy';
            $health['services']['redis'] = [
                'status' => 'down',
                'message' => $e->getMessage(),
            ];
        }

        // Check PostgreSQL
        try {
            $config = [
                'host' => getenv('DB_HOST') ?: 'postgres',
                'port' => (int) (getenv('DB_PORT') ?: 5432),
                'name' => getenv('DB_NAME') ?: 'radiochatbox',
                'user' => getenv('DB_USER') ?: 'radiochatbox',
                'password' => getenv('DB_PASSWORD') ?: 'radiochatbox_secret',
            ];

            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['name']);
            $pdo = new PDO($dsn, $config['user'], $config['password']);

            // Test query
            $stmt = $pdo->query('SELECT COUNT(*) FROM chat_messages');
            $count = $stmt->fetchColumn();

            $health['services']['postgresql'] = [
                'status' => 'up',
                'message' => 'Connected',
                'message_count' => (int) $count,
            ];
        } catch (\Exception $e) {
            $health['status'] = 'unhealthy';
            $health['services']['postgresql'] = [
                'status' => 'down',
                'message' => $e->getMessage(),
            ];
        }

        // Check PHP version
        $health['services']['php'] = [
            'status' => 'up',
            'version' => PHP_VERSION,
        ];

        $status = $health['status'] === 'healthy' ? 200 : 503;

        // Legacy health.php rendered with JSON_PRETTY_PRINT; keep it byte-identical
        // for any external uptime monitor scraping this endpoint.
        return Response::json($health, $status, JSON_PRETTY_PRINT);
    }

    /**
     * GET /api/cron/cleanup?token=… — periodic cleanup of expired data.
     *
     * Migrated from public/api/cron/cleanup.php. Simple token-based auth: the
     * `token` query param must equal the CRON_TOKEN env var (default
     * "change-me-in-production"); a mismatch returns 401 {"error":"Unauthorized"}.
     * On success runs CleanupService::runAll() and returns {success:true,
     * timestamp, results, message}. On failure logs and returns 500
     * {success:false, error:"Cleanup failed", message}.
     */
    #[Route('/api/cron/cleanup', methods: 'GET', name: 'maintenance.cron.cleanup')]
    public function cronCleanup(): Response
    {
        // Simple token-based authentication for cron jobs
        $cronToken = (string) Request::getInstance()->get('token', '', 'get');
        $expectedToken = getenv('CRON_TOKEN') ?: 'change-me-in-production';

        if ($cronToken !== $expectedToken) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        try {
            $cleanup = new CleanupService();
            $results = $cleanup->runAll();

            return Response::json([
                'success' => true,
                'timestamp' => date('Y-m-d H:i:s'),
                'results' => $results,
                'message' => 'Cleanup completed successfully',
            ]);
        } catch (\Exception $e) {
            \Pramnos\Logs\Logger::log('Cleanup cron error: ' . $e->getMessage(), 'radiochatbox');

            return Response::json([
                'success' => false,
                'error' => 'Cleanup failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
