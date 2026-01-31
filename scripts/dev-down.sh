#!/bin/bash
# scripts/dev-down.sh
# Stop development environment

set -e

cd "$(dirname "$0")/.."

echo "Stopping development environment..."
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml down

echo "Development environment stopped."
