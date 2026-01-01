#!/bin/bash

# Stats Collection Cron Script (Wrapper for PHP CLI)
# 
# This script is a simple wrapper that calls the PHP CLI script.
# It's kept for backward compatibility with existing cron jobs.
#
# RECOMMENDED: Update your crontab to call stats-cron.php directly:
#   */5 * * * * cd /path/to/radiochatbox && php stats-cron.php snapshot >> logs/stats-cron.log 2>&1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ACTION="${1:-snapshot}"

# Call the PHP CLI script directly
php "$SCRIPT_DIR/stats-cron.php" "$ACTION"
