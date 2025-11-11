#!/bin/bash

# RadioChatBox Startup Script
# This script helps you quickly start, stop, and manage RadioChatBox

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Banner
echo -e "${BLUE}"
echo "  ____            _ _        ____ _           _   ____             "
echo " |  _ \ __ _  __| (_) ___  / ___| |__   __ _| |_| __ )  _____  __ "
echo " | |_) / _\` |/ _\` | |/ _ \| |   | '_ \ / _\` | __|  _ \ / _ \ \/ / "
echo " |  _ < (_| | (_| | | (_) | |___| | | | (_| | |_| |_) | (_) >  <  "
echo " |_| \_\__,_|\__,_|_|\___/ \____|_| |_|\__,_|\__|____/ \___/_/\_\ "
echo -e "${NC}"
echo ""

# Function to check if Docker is running
check_docker() {
    if ! docker info > /dev/null 2>&1; then
        echo -e "${RED}Error: Docker is not running. Please start Docker Desktop.${NC}"
        exit 1
    fi
}

# Function to check if .env exists
check_env() {
    if [ ! -f .env ]; then
        echo -e "${YELLOW}Warning: .env file not found. Creating from .env.example...${NC}"
        cp .env.example .env
        echo -e "${GREEN}.env file created. You may want to edit it before continuing.${NC}"
        echo ""
    fi
}

# Start function
start() {
    echo -e "${GREEN}Starting RadioChatBox...${NC}"
    check_docker
    check_env
    
    docker-compose up -d
    
    echo ""
    echo -e "${GREEN}✓ RadioChatBox is starting up!${NC}"
    echo ""
    echo "Please wait 30-60 seconds for all services to be ready."
    echo ""
    echo -e "Then open your browser to: ${BLUE}http://localhost:98${NC}"
    echo ""
    echo "To view logs: ./start.sh logs"
    echo "To stop: ./start.sh stop"
}

# Stop function
stop() {
    echo -e "${YELLOW}Stopping RadioChatBox...${NC}"
    docker-compose down
    echo -e "${GREEN}✓ RadioChatBox stopped.${NC}"
}

# Restart function
restart() {
    echo -e "${YELLOW}Restarting RadioChatBox...${NC}"
    docker-compose restart
    echo -e "${GREEN}✓ RadioChatBox restarted.${NC}"
}

# Status function
status() {
    echo -e "${BLUE}RadioChatBox Status:${NC}"
    docker-compose ps
}

# Logs function
logs() {
    docker-compose logs -f --tail=100
}

# Build function
build() {
    echo -e "${YELLOW}Rebuilding RadioChatBox...${NC}"
    docker-compose down
    docker-compose build --no-cache
    docker-compose up -d
    echo -e "${GREEN}✓ RadioChatBox rebuilt and started.${NC}"
}

# Update function
update() {
    echo -e "${YELLOW}Updating RadioChatBox...${NC}"
    git pull
    docker-compose down
    docker-compose up -d --build
    echo -e "${GREEN}✓ RadioChatBox updated.${NC}"
}

# Backup function
backup() {
    BACKUP_DIR="backups"
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    
    mkdir -p $BACKUP_DIR
    
    echo -e "${YELLOW}Creating backup...${NC}"
    
    # Backup PostgreSQL
    docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox > "$BACKUP_DIR/database_$TIMESTAMP.sql"
    
    # Backup Redis
    docker exec radiochatbox_redis redis-cli --rdb /data/dump.rdb > /dev/null 2>&1
    docker cp radiochatbox_redis:/data/dump.rdb "$BACKUP_DIR/redis_$TIMESTAMP.rdb"
    
    echo -e "${GREEN}✓ Backup created in $BACKUP_DIR/${NC}"
    echo "  - database_$TIMESTAMP.sql"
    echo "  - redis_$TIMESTAMP.rdb"
}

# Clean function
clean() {
    echo -e "${RED}WARNING: This will remove all containers, volumes, and data!${NC}"
    read -p "Are you sure? (yes/no): " confirm
    
    if [ "$confirm" = "yes" ]; then
        echo -e "${YELLOW}Cleaning RadioChatBox...${NC}"
        docker-compose down -v
        echo -e "${GREEN}✓ RadioChatBox cleaned.${NC}"
    else
        echo "Cancelled."
    fi
}

# Help function
help() {
    echo "RadioChatBox Management Script"
    echo ""
    echo "Usage: ./start.sh [command]"
    echo ""
    echo "Commands:"
    echo "  start     - Start RadioChatBox (default)"
    echo "  stop      - Stop RadioChatBox"
    echo "  restart   - Restart RadioChatBox"
    echo "  status    - Show status of all services"
    echo "  logs      - View logs (Ctrl+C to exit)"
    echo "  build     - Rebuild all containers"
    echo "  update    - Pull latest changes and rebuild"
    echo "  backup    - Backup database and Redis data"
    echo "  clean     - Remove all containers and data (WARNING: destructive)"
    echo "  help      - Show this help message"
    echo ""
}

# Main script logic
case "${1:-start}" in
    start)
        start
        ;;
    stop)
        stop
        ;;
    restart)
        restart
        ;;
    status)
        status
        ;;
    logs)
        logs
        ;;
    build)
        build
        ;;
    update)
        update
        ;;
    backup)
        backup
        ;;
    clean)
        clean
        ;;
    help|--help|-h)
        help
        ;;
    *)
        echo -e "${RED}Unknown command: $1${NC}"
        echo ""
        help
        exit 1
        ;;
esac
