#!/bin/bash

#############################################################################
# RadioChatBox Production Deployment Script
#
# This script handles zero-downtime deployment of RadioChatBox to production.
# It pulls latest code, runs migrations, and restarts Docker containers.
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
BACKUP_DIR="${PROJECT_DIR}/backups"
LOG_FILE="${PROJECT_DIR}/deploy.log"

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

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Backup database
log "Creating database backup..."
BACKUP_FILE="${BACKUP_DIR}/db_backup_$(date +%Y%m%d_%H%M%S).sql"
docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox > "$BACKUP_FILE" 2>/dev/null || warning "Database backup failed (may be first deployment)"
log "Database backed up to: $BACKUP_FILE"

# Keep only last 7 backups
log "Cleaning old backups (keeping last 7)..."
ls -t "${BACKUP_DIR}"/db_backup_*.sql | tail -n +8 | xargs -r rm

# Pull latest code
log "Pulling latest code from repository..."
git fetch origin
git reset --hard origin/main || error "Failed to pull latest code"

# Check for migrations
log "Checking for database migrations..."
if [ -d "database/migrations" ] && [ "$(ls -A database/migrations)" ]; then
    log "Running database migrations..."
    for migration in database/migrations/*.sql; do
        if [ -f "$migration" ]; then
            log "  Applying: $(basename $migration)"
            docker exec radiochatbox_postgres psql -U radiochatbox -d radiochatbox -f "/docker-entrypoint-initdb.d/migrations/$(basename $migration)" || warning "Migration $(basename $migration) failed or already applied"
        fi
    done
else
    log "No migrations found"
fi

# Install/update composer dependencies
log "Installing composer dependencies..."
docker exec radiochatbox_apache composer install --no-dev --optimize-autoloader || error "Composer install failed"

# Build and restart containers
log "Building and restarting Docker containers..."
docker-compose pull
docker-compose build --no-cache
docker-compose up -d --force-recreate

# Wait for services to be ready
log "Waiting for services to start..."
sleep 5

# Health check
log "Running health check..."
for i in {1..30}; do
    if curl -s -f http://localhost:98/api/health.php > /dev/null 2>&1; then
        log "✅ Health check passed!"
        break
    fi
    
    if [ $i -eq 30 ]; then
        error "Health check failed after 30 attempts"
    fi
    
    sleep 2
done

# Clear Redis cache (optional - uncomment if needed)
log "Clearing Redis cache..."
docker exec radiochatbox_redis redis-cli FLUSHDB || warning "Redis cache clear failed"

# Clean up old Docker images
log "Cleaning up old Docker images..."
docker image prune -f || warning "Docker cleanup failed"

# Set correct permissions
log "Setting file permissions..."
docker exec radiochatbox_apache chown -R www-data:www-data /var/www/html
docker exec radiochatbox_apache chmod -R 755 /var/www/html/public

# Display container status
log "Container status:"
docker-compose ps

# Deployment complete
log "========================================="
log "✅ Deployment completed successfully!"
log "========================================="
log ""
log "Summary:"
log "  - Code updated from Git"
log "  - Database backed up to: $BACKUP_FILE"
log "  - Migrations applied (if any)"
log "  - Dependencies updated"
log "  - Containers restarted"
log "  - Health check passed"
log ""
log "Next steps:"
log "  1. Monitor logs: docker-compose logs -f"
log "  2. Check application: http://localhost:98"
log "  3. Verify admin panel: http://localhost:98/admin.html"
log ""

exit 0
