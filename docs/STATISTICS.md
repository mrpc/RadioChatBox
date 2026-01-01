# Statistics System

RadioChatBox includes a comprehensive statistics system that tracks and visualizes various metrics over time.

## Features

### Tracked Metrics

The system tracks the following metrics at multiple time granularities (hourly, daily, weekly, monthly, yearly):

- **Active Users**: Unique users who sent messages
- **Guest Users**: Anonymous (non-registered) users
- **Registered Users**: Authenticated users
- **Total Messages**: Public chat messages sent
- **Private Messages**: Private messages sent
- **Photo Uploads**: Number of photos uploaded
- **New Registrations**: New user accounts created
- **Radio Listeners**: Shoutcast/Icecast listener count
- **Peak Concurrent Users**: Maximum simultaneous users online

### Time Granularities

- **Hourly**: Statistics aggregated per hour
- **Daily**: Statistics aggregated per day
- **Weekly**: Statistics aggregated per ISO week (Monday-Sunday)
- **Monthly**: Statistics aggregated per calendar month
- **Yearly**: Statistics aggregated per year

### Real-time Snapshots

The system records snapshots every 5-15 minutes capturing:
- Current concurrent users online
- Current radio listeners
- Active sessions

These snapshots are used to calculate averages and peaks for hourly aggregations.

## Database Schema

The statistics system uses the following tables:

- `stats_hourly` - Hourly aggregated statistics
- `stats_daily` - Daily aggregated statistics
- `stats_weekly` - Weekly aggregated statistics (ISO week numbering)
- `stats_monthly` - Monthly aggregated statistics
- `stats_yearly` - Yearly aggregated statistics
- `stats_snapshots` - Real-time snapshots (kept for 30 days)

See [database/migrations/006_add_statistics_tables.sql](../database/migrations/006_add_statistics_tables.sql) for complete schema.

## Installation

### 1. Run Database Migration

Apply the statistics migration to your database:

```bash
docker exec radiochatbox_postgres psql -U radiochatbox -d radiochatbox \
  -f /docker-entrypoint-initdb.d/migrations/006_add_statistics_tables.sql
```

Or manually:

```bash
psql -U radiochatbox -d radiochatbox < database/migrations/006_add_statistics_tables.sql
```

### 2. Set Up Cron Job (Recommended)

The statistics system works **best** with periodic cron jobs for reliable data collection:

```bash
# Make the cron script executable
chmod +x stats-cron.sh

# Add to crontab (edit with: crontab -e)
*/15 * * * * /path/to/radiochatbox/stats-cron.sh >> /var/log/radiochatbox-stats.log 2>&1
```

**Recommended Schedule**: Every 15 minutes

The cron script automatically:
- Records snapshots every 15 minutes
- Aggregates hourly stats at :05 past each hour
- Aggregates daily stats at 00:10
- Aggregates weekly stats on Mondays at 00:25
- Aggregates monthly stats on 1st of month at 00:40
- Aggregates yearly stats on Jan 1st at 00:40

#### No Cron Available? Don't Worry!

If you **cannot use cron** (shared hosting, serverless, etc.), statistics will still work:

- **Snapshots**: Automatically recorded on user heartbeats (rate-limited to once per 5 minutes)
- **Aggregation**: Automatically triggered when admin views stats (runs if >70 minutes since last aggregation)

⚠️ **Note**: Without cron, aggregation happens on-demand and may lag behind. Cron is still strongly recommended for accurate, timely statistics.

### 3. Configure Environment Variables

Edit your `.env` or environment configuration:

```bash
# Optional: Customize API URL for cron script
API_URL=http://localhost:8080

# Use your admin credentials
ADMIN_USER=admin
ADMIN_PASS=admin123
```

## Usage

### Admin Dashboard

Access the statistics dashboard at:

```
http://your-domain.com/admin/stats.html
```

**Authentication**: Requires admin login (Basic Auth)

### API Endpoints

All endpoints require admin authentication.

#### Get Statistics

**Endpoint**: `GET /api/admin/stats.php`

**Parameters**:
- `granularity`: `summary|hourly|daily|weekly|monthly|yearly`
- `start_date`: Optional start date filter (ISO format)
- `end_date`: Optional end date filter (ISO format)
- `year`: Optional year filter (for weekly/monthly)
- `limit`: Optional row limit

**Examples**:

```bash
# Get summary overview
curl -u admin:admin123 "http://localhost/api/admin/stats.php?granularity=summary"

# Get last 7 days (168 hours)
curl -u admin:admin123 "http://localhost/api/admin/stats.php?granularity=hourly&limit=168"

# Get daily stats for specific date range
curl -u admin:admin123 "http://localhost/api/admin/stats.php?granularity=daily&start_date=2024-01-01&end_date=2024-01-31"

# Get weekly stats for 2024
curl -u admin:admin123 "http://localhost/api/admin/stats.php?granularity=weekly&year=2024"
```

#### Record Snapshot (Manual)

**Endpoint**: `POST /api/admin/record-snapshot.php`

```bash
curl -X POST -u admin:admin123 "http://localhost/api/admin/record-snapshot.php"
```

#### Aggregate Statistics (Manual)

**Endpoint**: `POST /api/admin/aggregate-stats.php`

**Parameters**:
- `granularity`: `hourly|daily|weekly|monthly|yearly|all`
- `date`: Optional specific date to aggregate

**Examples**:

```bash
# Aggregate all (runs all aggregations)
curl -X POST -u admin:admin123 "http://localhost/api/admin/aggregate-stats.php?granularity=all"

# Aggregate specific hour
curl -X POST -u admin:admin123 "http://localhost/api/admin/aggregate-stats.php?granularity=hourly&date=2024-01-15T14:00:00"

# Aggregate yesterday
curl -X POST -u admin:admin123 "http://localhost/api/admin/aggregate-stats.php?granularity=daily&date=2024-01-14"
```

## Architecture

### Data Flow

```
1. Real-time Activity
   ↓
2. Snapshots (every 15 min) → stats_snapshots table
   ↓
3. Hourly Aggregation (raw data + snapshots) → stats_hourly
   ↓
4. Daily Aggregation (from hourly) → stats_daily
   ↓
5. Weekly/Monthly/Yearly (from daily) → stats_weekly/monthly/yearly
```

### Aggregation Functions

PostgreSQL functions handle aggregation logic:

- `aggregate_hourly_stats(timestamp)` - Aggregates one hour from raw data
- `aggregate_daily_stats(date)` - Aggregates one day from hourly stats
- `aggregate_weekly_stats(date)` - Aggregates one week from daily stats
- `aggregate_monthly_stats(date)` - Aggregates one month from daily stats
- `aggregate_yearly_stats(year)` - Aggregates one year from daily stats
- `cleanup_old_snapshots()` - Removes snapshots older than 30 days

### Caching Strategy

All statistics queries are cached in Redis:

- **Summary**: 5 minutes
- **Hourly stats**: 10 minutes
- **Daily/weekly/monthly/yearly**: 1 hour

Cache is automatically invalidated when new data is aggregated.

## Performance Considerations

### Database Indexes

All statistics tables have appropriate indexes for fast queries:
- Time-based columns (stat_hour, stat_date, etc.)
- Year/week/month combination indexes

### Snapshot Retention

Snapshots are automatically cleaned up after 30 days to prevent table bloat. The `cleanup_old_snapshots()` function is called during aggregation.

### Query Optimization

- Higher-level aggregations (daily, weekly, monthly, yearly) are computed from lower-level aggregations, not raw data
- This makes queries fast even with years of historical data

## Troubleshooting

### No Data Showing

1. **If using cron, check it's running**:
   ```bash
   tail -f /var/log/radiochatbox-stats.log
   ```

2. **Manually trigger snapshot**:
   ```bash
   curl -X POST -u admin:admin123 "http://localhost/api/admin/record-snapshot.php"
   ```

3. **Manually trigger aggregation**:
   ```bash
   curl -X POST -u admin:admin123 "http://localhost/api/admin/aggregate-stats.php?granularity=all"
   ```

4. **Without cron**: Stats will auto-populate when:
   - Users are active (heartbeat triggers snapshots)
   - Admin views the statistics dashboard (triggers aggregation if needed)

5. **Check database**:
   ```sql
   SELECT COUNT(*) FROM stats_snapshots;
   SELECT COUNT(*) FROM stats_hourly;
   SELECT * FROM stats_snapshots ORDER BY snapshot_time DESC LIMIT 5;
   ```

### Aggregation Failures

Check PostgreSQL logs for function errors:
```bash
docker logs radiochatbox_postgres | grep ERROR
```

### Redis Cache Issues

Clear stats cache:
```bash
docker exec radiochatbox_redis redis-cli KEYS "stats:*" | xargs docker exec radiochatbox_redis redis-cli DEL
```

## Without Cron: Auto-Population Guide

Even **without cron**, statistics will still work through automatic triggers:

### How It Works

1. **Snapshots** are recorded automatically when:
   - Users send heartbeat pings (every few seconds)
   - Rate-limited to max once per 5 minutes to avoid DB overload
   
2. **Aggregation** is triggered automatically when:
   - Admin views the statistics dashboard
   - Checks if >70 minutes have passed since last hourly aggregation
   - Checks if >25 hours have passed since last daily aggregation

### Performance Impact

- **Snapshot recording**: Minimal - rate-limited and async, doesn't block requests
- **Aggregation**: Very minimal - only runs when needed, cached in Redis
- **No cron overhead**: Saves CPU and database connections

### When to Use Cron vs Auto-Trigger

| Scenario | Recommendation |
|----------|---|
| Production server with cron | ✅ Use cron (reliable, scheduled) |
| Shared hosting (no cron) | ✅ Use auto-triggers (zero setup) |
| Serverless/Vercel | ✅ Use auto-triggers (stateless) |
| High traffic (>1K concurrent) | ✅ Use cron (predictable load) |
| Low traffic (<100 concurrent) | ✅ Either works fine |

### Monitoring Auto-Population

Check when last aggregation ran:
```bash
# View last snapshot time
redis-cli GET stats:last_snapshot_time

# View last hourly aggregation time  
redis-cli GET stats:last_hourly_aggregation

# View last daily aggregation time
redis-cli GET stats:last_daily_aggregation
```

## Backfilling Historical Data

To populate statistics for past dates:

```bash
# Backfill hourly stats for a specific date range
for hour in {0..23}; do
  curl -X POST -u admin:admin123 \
    "http://localhost/api/admin/aggregate-stats.php?granularity=hourly&date=2024-01-15T${hour}:00:00"
done

# Then aggregate higher levels
curl -X POST -u admin:admin123 "http://localhost/api/admin/aggregate-stats.php?granularity=daily&date=2024-01-15"
curl -X POST -u admin:admin123 "http://localhost/api/admin/aggregate-stats.php?granularity=weekly&date=2024-01-15"
curl -X POST -u admin:admin123 "http://localhost/api/admin/aggregate-stats.php?granularity=monthly&date=2024-01-15"
```

## Future Enhancements

Potential improvements:

- **Export functionality**: CSV/PDF export of statistics
- **Alerting**: Email/Slack notifications for anomalies
- **Real-time dashboard**: WebSocket-based live updates
- **Custom date ranges**: More flexible date filtering in UI
- **Comparison views**: Compare different time periods
- **User retention metrics**: Track returning vs new users
- **Peak time analysis**: Identify busiest hours/days
- **Geo-analytics**: If location data is tracked

## Security

- All statistics endpoints require admin authentication
- Uses existing AdminAuth system (Basic Auth)
- No sensitive user data exposed (only aggregated counts)
- Redis cache keys are namespaced (`stats:*`)

## API Reference

See [docs/openapi.yaml](openapi.yaml) for complete API specification (to be updated with stats endpoints).
