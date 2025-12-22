# RadioChatBox - AI Coding Agent Instructions

## AI Coding Agent Guidelines

**IMPORTANT**: Do NOT create new markdown (.md) documentation files unless explicitly requested by the user. Instead:
- Update existing documentation (README.md, CHANGELOG.md, etc.)
- Add inline code comments for complex logic
- Update relevant existing docs in `docs/` directory

## Project Overview

RadioChatBox is a real-time chat application for radio shows built with PHP 8.3, PostgreSQL, Redis, and Server-Sent Events (SSE). The architecture emphasizes **stateless PHP with Redis pub/sub** for real-time messaging instead of WebSockets.

**Key Design Choice**: Uses SSE over WebSockets for simplicity - no special server configuration needed, works through standard HTTP/HTTPS proxies, automatic reconnection handling.

## Architecture

### Three-Layer Stack
1. **Frontend**: Vanilla JavaScript (`public/js/chat.js`) using EventSource API for SSE
2. **API Layer**: PHP endpoints in `public/api/` (stateless, CORS-enabled)
3. **Services**: `src/` contains business logic classes with singleton database connections

### Data Flow Pattern
```
User sends message → POST /api/send.php
  → ChatService validates/filters → Store in PostgreSQL
  → Publish to Redis channel → SSE clients receive via EventSource
```

**Critical**: Messages flow through Redis pub/sub (`chat:updates` channel). PostgreSQL is for persistence/auditing, Redis cache (`chat:messages` list) serves history.

### Database Singletons
- `Database::getPDO()` - PostgreSQL connection (singleton per request)
- `Database::getRedis()` - Redis connection (singleton per request)
- Never instantiate PDO/Redis directly - always use Database class

### Configuration Priority
1. Environment variables (docker-compose.yml sets these)
2. `src/Config.php` reads from getenv() with fallback defaults
3. Database `settings` table for runtime configuration (admin panel)

## Development Workflows

### Local Development (Docker-based)
```bash
docker-compose up -d                    # Start all services
docker exec radiochatbox_apache bash    # Access container
docker exec radiochatbox_apache composer install  # Install deps
```

### Running Tests
```bash
./test.sh                               # Run PHPUnit inside container
docker exec radiochatbox_apache ./vendor/bin/phpunit
docker exec radiochatbox_apache composer test-coverage  # With HTML coverage
```

**Test Pattern**: Use Mockery for mocking Redis/PDO. See `tests/MessageFilterTest.php` for examples.

### Production Deployment
```bash
./deploy.sh                             # Deploy to production (on server)
```

**Automatic deployment**: GitHub Actions workflow (`.github/workflows/deploy.yml`) runs on every push to `main`:
1. Runs PHPUnit tests with PostgreSQL + Redis services
2. SSH to production server and runs `deploy.sh`
3. Performs health check verification
4. Sends Slack notification (optional)

See `DEPLOYMENT.md` for complete setup guide.

### Database Changes
1. Add migration SQL to `database/migrations/` (numbered: `001_description.sql`)
2. Run: `docker exec radiochatbox_postgres psql -U radiochatbox -d radiochatbox -f /docker-entrypoint-initdb.d/migrations/001_description.sql`
3. Document in schema comments - see `database/init.sql` for pattern

## Critical Patterns & Conventions

### Redis Caching Strategy
**Always cache database queries** that are hit frequently:
```php
// Pattern used throughout ChatService
$cacheKey = 'settings:rate_limit';
$cached = $this->redis->get($cacheKey);
if ($cached !== false) {
    return json_decode($cached, true);
}
// Cache miss - fetch from DB, then cache for 5 minutes
$this->redis->setex($cacheKey, 300, json_encode($data));
```

**Cache invalidation**: Delete cache key when data changes (e.g., `$this->redis->del('banned_ips')` after ban)

### Message Filtering Pipeline
1. **Public messages**: `MessageFilter::filterPublicMessage()` - removes ALL URLs, phone numbers, dangerous HTML
2. **Private messages**: `MessageFilter::filterPrivateMessage()` - allows URLs but blocks blacklisted patterns, dangerous HTML
3. **Always filter** before storing - see `public/api/send.php` for pattern

### Auto-ban Violation Tracking
Redis tracks violations with TTL keys: `violations:{type}:{ip}` expires in 1 hour. After threshold (3 for most types), auto-ban for 24h via `ChatService::trackViolation()`. See implementation in `ChatService.php:checkRateLimit()`.

### SSE Subscription Pattern
```php
// public/api/stream.php pattern
$redis->subscribe(['chat:updates', 'chat:user_updates'], function($redis, $channel, $message) {
    echo "event: message\n";
    echo "data: " . $message . "\n\n";
    flush();
});
```

**Important**: Set headers for SSE (`text/event-stream`, `no-cache`), disable output buffering, set `X-Accel-Buffering: no` for nginx.

### Error Handling Standards
- **API endpoints**: Return proper HTTP codes (400 for validation, 429 for rate limit, 500 for server errors)
- **Services**: Throw typed exceptions (InvalidArgumentException, RuntimeException)
- **Database failures**: Log errors but don't expose details to clients - see `ChatService::storeMessageInDB()`

### Namespace & Autoloading
- Namespace: `RadioChatBox\` (PSR-4)
- Classes in `src/`: `RadioChatBox\ClassName`
- Tests in `tests/`: `RadioChatBox\Tests\ClassNameTest`
- Autoload: `require_once __DIR__ . '/../../vendor/autoload.php'` from API endpoints

## File Locations & Responsibilities

- `public/api/*.php` - REST endpoints (stateless, handle CORS, return JSON)
- `public/api/admin/*.php` - Admin endpoints (check BasicAuth via `AdminAuth::check()`)
- `src/ChatService.php` - Core chat logic (messages, users, moderation)
- `src/MessageFilter.php` - XSS/spam filtering (static methods)
- `src/PhotoService.php` - Photo uploads with auto-expiration (48h)
- `src/SettingsService.php` - Runtime config from database
- `database/init.sql` - Complete schema with indexes, views, functions

## Testing Approach

- **Unit tests** for business logic (Config, MessageFilter, SettingsService)
- **Mock external dependencies** (Redis, PDO) using Mockery
- **No integration tests** currently - manual testing via Docker environment
- Coverage target: Core services and filters (not API endpoints)

## Common Tasks

### Adding a New API Endpoint
1. Create `public/api/new-endpoint.php`
2. Add `require_once __DIR__ . '/../../vendor/autoload.php'`
3. Handle CORS: `CorsHandler::handle();`
4. Validate inputs, call service methods, return JSON
5. Document in `docs/openapi.yaml`

### Adding New Settings
1. Insert default in `database/init.sql` settings table
2. Access via `SettingsService::get('key', 'default')` or `ChatService::getSetting()`
3. Add admin UI form field in `public/admin.html`
4. Update `public/api/admin/update-settings.php` to handle new setting

### Implementing Auto-cleanup
Use PostgreSQL functions - see `cleanup_inactive_users()` in `init.sql`. Called automatically via `ChatService::cleanupInactiveUsers()` before queries that need fresh data.

## Security Notes

- **XSS Prevention**: Double-layer (MessageFilter + htmlspecialchars on output)
- **SQL Injection**: Always use prepared statements with PDO
- **CORS**: Configure `ALLOWED_ORIGINS` in .env for embedding
- **Admin Auth**: Basic Auth via `AdminAuth::check()` - default admin/admin123 (CHANGE IN PRODUCTION)
- **Rate Limiting**: Per-IP Redis counters with auto-ban escalation
- **Photo Expiration**: Auto-delete after 48h via PostgreSQL expires_at column + cron cleanup

## External Dependencies

- **PHP Extensions Required**: redis, pdo, pdo_pgsql, gd (for image processing)
- **Composer Packages**: phpunit/phpunit, mockery/mockery (dev-only)
- **Frontend**: No build step - vanilla JS, no npm/webpack
- **Embed Mode**: iframe or direct integration (see README.md examples)
