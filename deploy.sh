#!/bin/bash

#############################################################################
# RadioChatBox Production Deployment Script
#
# This script handles zero-downtime deployment of RadioChatBox to production.
# It pulls latest code, runs migrations, and restarts Apache.
#
# Requirements:
#   - Apache 2.4+ with PHP 8.3+
#   - PostgreSQL 16+
#   - Redis 7+
#   - Git
#   - Composer
#
# Usage: ./deploy.sh
#############################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

LOG_FILE="${PROJECT_DIR}/deploy.log"
WEB_ROOT="${PROJECT_DIR}/public"

# Database configuration (can be overridden by .env)
DB_NAME="${DB_NAME:-radiochatbox}"
DB_USER="${DB_USER:-radiochatbox}"
DB_HOST="${DB_HOST:-localhost}"

# Functions
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a "$LOG_FILE"
    exit 1
}

warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a "$LOG_FILE"
}

# Start deployment
log "========================================="
log "Starting RadioChatBox deployment"
log "========================================="

# Change to project directory
cd "$PROJECT_DIR" || error "Failed to change to project directory"

# Check if .env file exists
if [ ! -f .env ]; then
    error ".env file not found! Please create it from .env.example"
fi

# Load environment variables
source .env 2>/dev/null || warning "Could not load .env file"


# Control whether to run database migrations (default: skip)
# Set RUN_MIGRATIONS=true in the environment or .env to enable.
RUN_MIGRATIONS="${RUN_MIGRATIONS:-false}"

# Pull latest code
log "Pulling latest code from repository..."
git fetch origin
git reset --hard origin/main || error "Failed to pull latest code"

# Check for migrations (skipped by default)
if [ "$RUN_MIGRATIONS" = "true" ]; then
    log "Checking for database migrations..."
    if [ -d "database/migrations" ] && [ "$(ls -A database/migrations 2>/dev/null)" ]; then
        log "Running database migrations..."
        for migration in database/migrations/*.sql; do
            if [ -f "$migration" ]; then
                log "  Applying: $(basename $migration)"
                # Copy to /tmp so postgres user can access it
                TMP_MIGRATION="/tmp/migration_$(basename $migration)_$$"
                cp "$migration" "$TMP_MIGRATION"
                chmod 644 "$TMP_MIGRATION"

                sudo -u postgres psql -d "$DB_NAME" -f "$TMP_MIGRATION" || warning "Migration $(basename $migration) failed or already applied"

                # Clean up
                rm -f "$TMP_MIGRATION"
            fi
        done
    else
        log "No migrations found"
    fi
else
    log "Skipping database migrations (RUN_MIGRATIONS=false)"
fi

# Install/update composer dependencies
log "Installing composer dependencies..."
composer install --no-dev --optimize-autoloader || error "Composer install failed"

# Clear Redis cache
log "Clearing Redis cache..."
redis-cli FLUSHDB || warning "Redis cache clear failed"


]
# Health check
log "Running health check..."
HEALTH_URL="http://localhost/api/health.php"
for i in {1..30}; do
    if curl -s -f "$HEALTH_URL" > /dev/null 2>&1; then
        log "✅ Health check passed!"
        break
    fi
    
    if [ $i -eq 30 ]; then
        error "Health check failed after 30 attempts"
    fi
    
    sleep 2
done

# Deployment complete
log "========================================="
log "✅ Deployment completed successfully!"
log "========================================="
log ""
log "Summary:"
log "  - Code updated from Git"
log "  - Migrations applied (if any)"
log "  - Dependencies updated"
log "  - Health check passed"
log ""
log "Next steps:"
log "  1. Monitor Apache logs: sudo tail -f /var/log/apache2/error.log"
log "  2. Check application in browser"
log "  3. Verify admin panel access"
log ""

exit 0
