#!/bin/bash
# Run PHPUnit tests inside Docker container

echo "Running PHPUnit tests in Docker..."

# Fix git ownership warning (safe in dev environment)
docker exec radiochatbox_apache git config --global --add safe.directory /var/www/html 2>/dev/null || true

docker exec radiochatbox_apache composer install
docker exec radiochatbox_apache ./vendor/bin/phpunit

echo ""
echo "Tests completed!"
