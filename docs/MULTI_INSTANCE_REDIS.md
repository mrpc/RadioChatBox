# Multi-Instance Redis Isolation

## Overview

RadioChatBox now supports running multiple instances on the same server with a shared Redis instance. Each instance is isolated by prefixing all Redis keys with the database name.

## Problem Statement

When running multiple RadioChatBox instances (e.g., for different radio stations) on the same server:
- All instances shared the same Redis server
- Each instance had a different PostgreSQL database
- Redis keys were not namespaced, causing data cross-contamination:
  - Shared message history
  - Mixed rate limits
  - Combined banned user lists
  - Overlapping cache entries

## Solution

All Redis keys are now automatically prefixed with `radiochatbox:{database_name}:` where `database_name` comes from the PostgreSQL database configuration.

### Example

**Instance 1** (database: `radiochat_station1`):
- Key: `radiochatbox:radiochat_station1:chat:updates`
- Key: `radiochatbox:radiochat_station1:banned_ips`
- Key: `radiochatbox:radiochat_station1:settings:all`

**Instance 2** (database: `radiochat_station2`):
- Key: `radiochatbox:radiochat_station2:chat:updates`
- Key: `radiochatbox:radiochat_station2:banned_ips`
- Key: `radiochatbox:radiochat_station2:settings:all`

## Implementation Details

### Database.php

Added central prefix generator:

```php
public static function getRedisPrefix(): string {
    $config = Config::get('database');
    $dbName = $config['name'];
    return "radiochatbox:{$dbName}:";
}
```

### Service Classes Pattern

All service classes follow this pattern:

```php
class SomeService
{
    private Redis $redis;
    private string $prefix;

    public function __construct()
    {
        $this->redis = Database::getRedis();
        $this->prefix = Database::getRedisPrefix();
    }

    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    // Usage:
    public function someMethod() {
        $this->redis->get($this->prefixKey('settings:all'));
    }
}
```

### Updated Files

**Service Classes:**
1. `src/ChatService.php` - Messages, rate limiting, bans
2. `src/SettingsService.php` - Settings cache
3. `src/PhotoService.php` - Attachment cache
4. `src/MessageFilter.php` - Blacklist cache, spam tracking
5. `src/AdminAuth.php` - Admin login attempts
6. `src/CleanupService.php` - Cache invalidation

**API Endpoints:**
1. `public/api/stream.php` - SSE pub/sub channels
2. `public/api/private-message.php` - Private message pub/sub

### Redis Operations Updated

**Caching:**
- `settings:all` - Global settings cache
- `settings:rate_limit` - Rate limit settings
- `banned_ips` - Banned IP addresses cache
- `banned_nicknames` - Banned nicknames cache
- `url_blacklist_patterns` - URL blacklist cache
- `attachment:{id}` - Photo attachment metadata
- `user_attachments:{username}` - User attachment list
- `setting:{key}` - Individual setting values

**Pub/Sub Channels:**
- `chat:updates` - Public message broadcasts
- `chat:user_updates` - Active users updates
- `chat:private_messages` - Private messages

**Rate Limiting:**
- `rate_limit:{ip}` - Message rate limits per IP
- `violations:{type}:{ip}` - Violation tracking counters
- `admin_auth_attempts:{ip}` - Admin login attempts

**Message Storage:**
- `chat:messages` - Message history list

## Configuration

No configuration changes needed! The prefix is automatically derived from your database name in the environment:

```bash
# Instance 1
DB_NAME=radiochat_station1

# Instance 2
DB_NAME=radiochat_station2
```

## Testing Multi-Instance Setup

### Setup Two Instances

1. Create two database instances:
```sql
CREATE DATABASE radiochat_station1;
CREATE DATABASE radiochat_station2;
```

2. Run init.sql in both databases

3. Create two .env configurations with different:
   - `DB_NAME` (different database)
   - `APACHE_PORT` (different port for testing)
   - Same `REDIS_HOST` and `REDIS_PORT`

4. Start both instances and verify Redis isolation:
```bash
# Connect to Redis
redis-cli

# See keys from both instances
KEYS radiochatbox:*
```

You should see prefixed keys for each database instance.

## Backward Compatibility

**Breaking Change**: Existing instances will lose their Redis cache on first load after this update because keys are now prefixed.

**Impact**: 
- Cache misses on first request (will rebuild from database)
- No message history in Redis (will reload from PostgreSQL)
- No data loss - PostgreSQL data is preserved

**Recovery**: Automatic - caches rebuild on first access.

## Benefits

1. **True Multi-Tenancy**: Run unlimited instances on same infrastructure
2. **Cost Efficiency**: Single Redis server for all instances
3. **Data Isolation**: Complete separation between instances
4. **Easy Scaling**: Add new instances without Redis conflicts
5. **Clean Separation**: Each station's data is namespaced

## Redis Memory Considerations

With multiple instances sharing Redis:
- Monitor memory usage: `redis-cli INFO memory`
- Consider setting `maxmemory-policy allkeys-lru` for automatic eviction
- Adjust cache TTLs if memory pressure increases
- Each instance adds its own message history (default 50 messages cached)

## Monitoring

Check which instances are active:

```bash
# List all database prefixes
redis-cli KEYS "radiochatbox:*" | grep -oP "radiochatbox:[^:]*" | sort -u

# Count keys per instance
redis-cli KEYS "radiochatbox:radiochat_station1:*" | wc -l
redis-cli KEYS "radiochatbox:radiochat_station2:*" | wc -l
```

## Troubleshooting

### Messages Not Appearing

Check pub/sub channels are correctly subscribed:
```bash
redis-cli PUBSUB CHANNELS "radiochatbox:*:chat:updates"
```

### Cache Not Invalidating

Ensure `DB_NAME` environment variable is correctly set and consistent across instance restarts.

### Mixed Data

If you see mixed data, verify each instance has a unique `DB_NAME` in its configuration.
