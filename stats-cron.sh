#!/bin/bash

# RadioChatBox Statistics Collection Cron Job
# This script should be run periodically to collect and aggregate statistics
#
# Recommended cron schedule:
# */15 * * * * /path/to/radiochatbox/stats-cron.sh >> /var/log/radiochatbox-stats.log 2>&1
#
# This runs every 15 minutes to:
# 1. Record a snapshot of current activity
# 2. Aggregate completed hours/days/weeks/months/years

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_URL="${API_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin123}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting stats collection..."

# 1. Record current snapshot
echo "Recording snapshot..."
SNAPSHOT_RESULT=$(curl -s -X POST \
    -u "$ADMIN_USER:$ADMIN_PASS" \
    "$API_URL/api/admin/record-snapshot.php")

if echo "$SNAPSHOT_RESULT" | grep -q '"success":true'; then
    echo "✓ Snapshot recorded successfully"
else
    echo "✗ Failed to record snapshot: $SNAPSHOT_RESULT"
fi

# 2. Check if we need to run aggregations
CURRENT_MINUTE=$(date +%M)
CURRENT_HOUR=$(date +%H)
CURRENT_DAY=$(date +%d)

# Aggregate hourly stats (run at 5 minutes past the hour)
if [ "$CURRENT_MINUTE" = "05" ]; then
    echo "Aggregating hourly stats..."
    HOURLY_RESULT=$(curl -s -X POST \
        -u "$ADMIN_USER:$ADMIN_PASS" \
        "$API_URL/api/admin/aggregate-stats.php?granularity=hourly")
    
    if echo "$HOURLY_RESULT" | grep -q '"success":true'; then
        echo "✓ Hourly aggregation completed"
    else
        echo "✗ Hourly aggregation failed: $HOURLY_RESULT"
    fi
fi

# Aggregate daily stats (run at 00:10)
if [ "$CURRENT_HOUR" = "00" ] && [ "$CURRENT_MINUTE" = "10" ]; then
    echo "Aggregating daily stats..."
    DAILY_RESULT=$(curl -s -X POST \
        -u "$ADMIN_USER:$ADMIN_PASS" \
        "$API_URL/api/admin/aggregate-stats.php?granularity=daily")
    
    if echo "$DAILY_RESULT" | grep -q '"success":true'; then
        echo "✓ Daily aggregation completed"
    else
        echo "✗ Daily aggregation failed: $DAILY_RESULT"
    fi
fi

# Aggregate weekly stats (run on Mondays at 00:25)
if [ "$(date +%u)" = "1" ] && [ "$CURRENT_HOUR" = "00" ] && [ "$CURRENT_MINUTE" = "25" ]; then
    echo "Aggregating weekly stats..."
    WEEKLY_RESULT=$(curl -s -X POST \
        -u "$ADMIN_USER:$ADMIN_PASS" \
        "$API_URL/api/admin/aggregate-stats.php?granularity=weekly")
    
    if echo "$WEEKLY_RESULT" | grep -q '"success":true'; then
        echo "✓ Weekly aggregation completed"
    else
        echo "✗ Weekly aggregation failed: $WEEKLY_RESULT"
    fi
fi

# Aggregate monthly stats (run on 1st of month at 00:40)
if [ "$CURRENT_DAY" = "01" ] && [ "$CURRENT_HOUR" = "00" ] && [ "$CURRENT_MINUTE" = "40" ]; then
    echo "Aggregating monthly stats..."
    MONTHLY_RESULT=$(curl -s -X POST \
        -u "$ADMIN_USER:$ADMIN_PASS" \
        "$API_URL/api/admin/aggregate-stats.php?granularity=monthly")
    
    if echo "$MONTHLY_RESULT" | grep -q '"success":true'; then
        echo "✓ Monthly aggregation completed"
    else
        echo "✗ Monthly aggregation failed: $MONTHLY_RESULT"
    fi
    
    # Also aggregate yearly stats on Jan 1st
    if [ "$(date +%m)" = "01" ]; then
        echo "Aggregating yearly stats..."
        YEARLY_RESULT=$(curl -s -X POST \
            -u "$ADMIN_USER:$ADMIN_PASS" \
            "$API_URL/api/admin/aggregate-stats.php?granularity=yearly")
        
        if echo "$YEARLY_RESULT" | grep -q '"success":true'; then
            echo "✓ Yearly aggregation completed"
        else
            echo "✗ Yearly aggregation failed: $YEARLY_RESULT"
        fi
    fi
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Stats collection completed"
echo "---"
