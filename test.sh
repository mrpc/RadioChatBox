#!/bin/bash
# Run PHPUnit tests inside Docker container

echo "Running PHPUnit tests in Docker..."
docker exec radiochatbox_apache composer install
docker exec radiochatbox_apache ./vendor/bin/phpunit

echo ""
echo "Tests completed!"
