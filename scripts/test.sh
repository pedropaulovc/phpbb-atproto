#!/bin/bash
# scripts/test.sh
# Run all tests

set -e

cd "$(dirname "$0")/.."

SUITE="${1:-all}"

run_unit_tests() {
    echo "Running unit tests..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T -e XDEBUG_MODE=coverage phpbb phpunit --testsuite unit
    echo "Checking coverage threshold..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb vendor/bin/coverage-check tests/coverage/clover.xml 70
}

run_integration_tests() {
    echo "Running integration tests..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb phpunit -c phpunit.integration.xml
}

run_e2e_tests() {
    echo "Running E2E tests..."
    cd tests/e2e && npm test
}

run_lint() {
    echo "Running linter..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb php-cs-fixer fix --dry-run --diff
}

run_static_analysis() {
    echo "Running static analysis..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb phpstan analyse
}

case "$SUITE" in
    unit)
        run_unit_tests
        ;;
    integration)
        run_integration_tests
        ;;
    e2e)
        run_e2e_tests
        ;;
    lint)
        run_lint
        ;;
    analyse)
        run_static_analysis
        ;;
    all)
        run_lint
        run_static_analysis
        run_unit_tests
        run_integration_tests
        ;;
    *)
        echo "Usage: $0 {unit|integration|e2e|lint|analyse|all}"
        exit 1
        ;;
esac

echo "Done!"
