#!/bin/bash
# scripts/dev-up.sh
# Start development environment

set -e

cd "$(dirname "$0")/.."

echo "Starting development environment..."

# Build and start Docker services
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml up -d --build

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
until docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T db mysqladmin ping -h localhost -u root -prootpassword --silent; do
    sleep 1
done

# Install Composer dependencies if needed
if [ ! -d "vendor" ]; then
    echo "Installing Composer dependencies..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb composer install
fi

echo ""
echo "Development environment ready!"
echo "  phpBB:      http://localhost:8080"
echo "  phpMyAdmin: http://localhost:8081"
echo "  MySQL:      localhost:3306"
echo ""
echo "First time? Run: docker cp docker/install-config.yml phpbb-app:/var/www/html/install/install-config.yml"
echo "Then: docker exec phpbb-app bash -c 'cd /var/www/html && php install/phpbbcli.php install install/install-config.yml'"
