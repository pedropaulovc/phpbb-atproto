# Component: Infrastructure

## Overview
- **Purpose**: Define deployment environment, secrets management, monitoring, and operational procedures
- **Location**: `docker/`, `.env`, deployment configuration files
- **Dependencies**: Docker, Docker Compose
- **Dependents**: All components (phpbb, sync-service, mysql)
- **Task**: phpbb-ko7

## Acceptance Criteria
- [ ] AC-1: All services start successfully with `docker-compose up -d`
- [ ] AC-2: Environment variables are documented with examples
- [ ] AC-3: Health checks pass for all services within 2 minutes of startup
- [ ] AC-4: Secrets are never committed to version control
- [ ] AC-5: Logs are accessible via `docker-compose logs`
- [ ] AC-6: Graceful shutdown completes within 30 seconds
- [ ] AC-7: Container restarts automatically on failure

## File Structure
```
docker/
├── init.sql              # Database initialization
├── my.cnf                # MySQL configuration
├── php.ini               # PHP configuration for phpBB
└── nginx.conf            # (Optional) Nginx reverse proxy

sync-service/
├── Dockerfile            # Sync service container
├── composer.json
├── composer.lock
├── bin/
│   ├── sync-daemon.php   # Main entry point
│   └── healthcheck.php   # Health check script
└── src/
    └── ...

.env.example              # Environment variable template
docker-compose.yml        # Service definitions
docker-compose.override.yml  # Development overrides
```

## Environment Variables

### Required Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `MYSQL_ROOT_PASSWORD` | MySQL root password | `secure_root_pw_123` |
| `MYSQL_PASSWORD` | phpBB database password | `secure_phpbb_pw_456` |
| `FORUM_DID` | Forum PDS account DID | `did:plc:abc123xyz` |
| `FORUM_PDS_URL` | Forum PDS XRPC endpoint | `https://bsky.social` |
| `FORUM_PDS_ACCESS_TOKEN` | Forum PDS access token | `eyJ...` |
| `TOKEN_ENCRYPTION_KEYS` | JSON object of versioned keys | `{"v1":"base64key=="}` |
| `TOKEN_ENCRYPTION_KEY_VERSION` | Current key version | `v1` |

### Optional Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `RELAY_URL` | `wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos` | Firehose endpoint |
| `LABELER_DID` | `${FORUM_DID}` | Ozone labeler DID |
| `LABELER_URL` | `${FORUM_PDS_URL}` | Ozone labeler endpoint |
| `LOG_LEVEL` | `info` | Logging verbosity (debug, info, warn, error) |

### .env.example

```bash
# =============================================================================
# phpBB AT Protocol Integration - Environment Configuration
# =============================================================================
# Copy this file to .env and fill in your values.
# NEVER commit .env to version control.

# -----------------------------------------------------------------------------
# Database Configuration
# -----------------------------------------------------------------------------
MYSQL_ROOT_PASSWORD=change_me_root_password
MYSQL_PASSWORD=change_me_phpbb_password

# -----------------------------------------------------------------------------
# Forum PDS Configuration
# -----------------------------------------------------------------------------
# The forum's AT Protocol identity and PDS access
FORUM_DID=did:plc:your_forum_did_here
FORUM_PDS_URL=https://your-pds.example.com
FORUM_PDS_ACCESS_TOKEN=your_forum_pds_access_token

# -----------------------------------------------------------------------------
# Moderation (Ozone Labeler)
# -----------------------------------------------------------------------------
# Leave empty to use FORUM_DID as the labeler
LABELER_DID=
LABELER_URL=

# -----------------------------------------------------------------------------
# Security - Token Encryption
# -----------------------------------------------------------------------------
# Generate a key: php -r "echo base64_encode(random_bytes(32));"
#
# Format: JSON object mapping version strings to base64-encoded 32-byte keys
# Example: {"v1":"YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY="}
#
# Key Rotation:
#   1. Generate new key
#   2. Add as new version: {"v1":"oldkey","v2":"newkey"}
#   3. Update TOKEN_ENCRYPTION_KEY_VERSION to "v2"
#   4. Old tokens decrypt with v1, new tokens encrypt with v2
#   5. After 30 days, remove v1
TOKEN_ENCRYPTION_KEYS={"v1":"GENERATE_AND_REPLACE_ME"}
TOKEN_ENCRYPTION_KEY_VERSION=v1

# -----------------------------------------------------------------------------
# Relay Configuration
# -----------------------------------------------------------------------------
# Public relay firehose endpoint (default: Bluesky's public relay)
RELAY_URL=wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos

# -----------------------------------------------------------------------------
# Logging
# -----------------------------------------------------------------------------
LOG_LEVEL=info
```

## Docker Configuration Files

### Sync Service Dockerfile

```dockerfile
FROM php:8.4-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    libsodium-dev \
    icu-dev \
    && docker-php-ext-install \
        pdo_mysql \
        sodium \
        intl \
        pcntl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first (for layer caching)
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application code
COPY src/ src/
COPY bin/ bin/
COPY config/ config/

# Run post-install scripts
RUN composer dump-autoload --optimize

# Create non-root user
RUN adduser -D -u 1000 syncuser
USER syncuser

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD php /app/bin/healthcheck.php || exit 1

# Graceful shutdown signal
STOPSIGNAL SIGTERM

# Run daemon
CMD ["php", "/app/bin/sync-daemon.php"]
```

### MySQL Configuration (my.cnf)

```ini
[mysqld]
# Character set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Connection limits
max_connections = 200
wait_timeout = 28800
interactive_timeout = 28800

# InnoDB settings
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Query cache (disabled in MySQL 8.0+)
# query_cache_type = 0

# Logging
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
```

### PHP Configuration (php.ini)

```ini
; Memory and execution
memory_limit = 256M
max_execution_time = 60
max_input_time = 60

; Upload limits
upload_max_filesize = 10M
post_max_size = 12M

; Error reporting
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log

; Session
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1

; Timezone
date.timezone = UTC

; OPcache (production)
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
```

### Database Initialization (init.sql)

```sql
-- Initialization script run on first MySQL start
-- phpBB tables are created by the installer, not here

-- Ensure correct character set
ALTER DATABASE phpbb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant permissions (if needed for additional users)
-- GRANT SELECT, INSERT, UPDATE, DELETE ON phpbb.* TO 'phpbb_readonly'@'%';
```

## Health Checks

### Sync Service Health Check

```php
<?php
// bin/healthcheck.php

declare(strict_types=1);

$requiredEnv = ['MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD'];
foreach ($requiredEnv as $var) {
    if (!getenv($var)) {
        fwrite(STDERR, "Missing environment variable: $var\n");
        exit(1);
    }
}

$healthy = true;
$reasons = [];
$stateFile = '/tmp/sync-service-state.json';

// Check 1: State file freshness (daemon is running and updating state)
if (file_exists($stateFile)) {
    $stateAge = time() - filemtime($stateFile);
    if ($stateAge > 60) {
        $healthy = false;
        $reasons[] = "State file stale ({$stateAge}s) - daemon may be dead";
    }

    $state = json_decode(file_get_contents($stateFile), true);
    if ($state) {
        // Check 2: WebSocket connection state
        if (!($state['websocket_connected'] ?? false)) {
            $healthy = false;
            $reasons[] = 'WebSocket disconnected';
        }

        // Check 3: Last message received (allow 5 min gap)
        $lastMsg = $state['last_message_at'] ?? 0;
        $msgAge = time() - $lastMsg;
        if ($msgAge > 300) {
            $healthy = false;
            $reasons[] = "No messages received in {$msgAge}s";
        }
    }
} else {
    echo "No state file found, checking database cursor\n";
}

// Check 4: Database cursor freshness (authoritative check)
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('MYSQL_HOST'),
            getenv('MYSQL_DATABASE')
        ),
        getenv('MYSQL_USER'),
        getenv('MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->query(
        "SELECT cursor_value, updated_at
         FROM phpbb_atproto_cursors
         WHERE service = 'firehose'"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo "No cursor found (first run?)\n";
    } else {
        $staleness = time() - (int)$row['updated_at'];
        $maxStaleness = 300; // 5 minutes

        if ($staleness > $maxStaleness) {
            $healthy = false;
            $reasons[] = "Cursor stale: {$staleness}s (max: {$maxStaleness}s)";
        }
    }
} catch (PDOException $e) {
    $healthy = false;
    $reasons[] = "Database error: " . $e->getMessage();
}

if ($healthy) {
    echo "Healthy: all checks passed\n";
    exit(0);
} else {
    fwrite(STDERR, "Unhealthy: " . implode('; ', $reasons) . "\n");
    exit(1);
}
```

## Monitoring

### Key Metrics

| Metric | Source | Alert Threshold |
|--------|--------|-----------------|
| Cursor staleness | `phpbb_atproto_cursors.updated_at` | > 5 minutes |
| Queue depth | `COUNT(*) FROM phpbb_atproto_queue WHERE status='pending'` | > 100 items |
| Dead letter count | `COUNT(*) FROM phpbb_atproto_queue WHERE status='dead_letter'` | > 10 items |
| Container restarts | Docker stats | > 3 in 1 hour |
| Memory usage | Docker stats | > 80% of limit |
| MySQL connections | `SHOW STATUS LIKE 'Threads_connected'` | > 80% of max |

### Log Aggregation

```yaml
# Example: Loki/Promtail configuration
scrape_configs:
  - job_name: phpbb
    static_configs:
      - targets:
          - localhost
        labels:
          job: phpbb
          __path__: /var/log/phpbb/*.log
    pipeline_stages:
      - json:
          expressions:
            level: level
            message: message
```

### Alerting Rules (Prometheus)

```yaml
groups:
  - name: phpbb-atproto
    rules:
      - alert: SyncServiceDown
        expr: absent(up{job="sync-service"})
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Sync service is down"

      - alert: FirehoseCursorStale
        expr: time() - phpbb_atproto_cursor_updated_at > 300
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Firehose cursor hasn't updated in 5 minutes"

      - alert: QueueBacklog
        expr: phpbb_atproto_queue_pending > 100
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "PDS write queue has {{ $value }} pending items"
```

## Operational Procedures

### Deployment

```bash
# Initial deployment
git clone https://github.com/your-org/phpbb-atproto.git
cd phpbb-atproto
cp .env.example .env
# Edit .env with your values

# Generate encryption key
php -r "echo base64_encode(random_bytes(32));"
# Add to .env TOKEN_ENCRYPTION_KEYS

# Start services
docker-compose up -d

# Check health
docker-compose ps
docker-compose logs -f sync-service
```

### Key Rotation

```bash
# 1. Generate new key
NEW_KEY=$(php -r "echo base64_encode(random_bytes(32));")
echo "New key: $NEW_KEY"

# 2. Update .env - add new version
# TOKEN_ENCRYPTION_KEYS={"v1":"oldkey","v2":"$NEW_KEY"}
# TOKEN_ENCRYPTION_KEY_VERSION=v2

# 3. Restart services
docker-compose up -d

# 4. After 30 days, remove old key version
```

### Backup

```bash
# Database backup
docker-compose exec mysql mysqldump -u root -p phpbb > backup_$(date +%Y%m%d).sql

# Volume backup
docker run --rm -v phpbb_data:/data -v $(pwd):/backup alpine \
    tar czf /backup/phpbb_data_$(date +%Y%m%d).tar.gz /data
```

### Recovery

```bash
# Restore database
docker-compose exec -T mysql mysql -u root -p phpbb < backup_20240115.sql

# Restore volumes
docker run --rm -v phpbb_data:/data -v $(pwd):/backup alpine \
    tar xzf /backup/phpbb_data_20240115.tar.gz -C /
```

## Error Handling

| Condition | Detection | Recovery |
|-----------|-----------|----------|
| MySQL unavailable | Health check fails | Auto-restart with dependency wait |
| Sync service crash | Container restarts | Auto-restart, resume from cursor |
| Firehose disconnected | WebSocket state check | Exponential backoff reconnect |
| Disk full | Docker daemon alerts | Clear logs, expand volume |
| Memory exhaustion | OOM kill | Increase limits or optimize |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Start all services | All containers healthy within 2 min |
| Stop MySQL | Sync service enters unhealthy state |
| Restart MySQL | Sync service recovers automatically |
| Kill sync-service | Container restarts, resumes from cursor |
| `docker-compose down` | Graceful shutdown < 30s |
| Volume persistence | Data survives `down`/`up` cycle |

## Implementation Notes

### Security Considerations
- Never commit `.env` to version control
- Use Docker secrets for production deployments
- Rotate encryption keys every 90 days
- Restrict network access with firewall rules

### Performance Considerations
- MySQL connection pooling via container limits
- Sync service single connection, batched operations
- Volume mounts for data persistence

### Production Hardening
- Use read-only mounts where possible (`:ro`)
- Run containers as non-root user
- Enable Docker content trust
- Scan images for vulnerabilities

## References
- [Docker Compose Specification](https://docs.docker.com/compose/compose-file/)
- [docs/architecture.md](../../docs/architecture.md) - Deployment topology
- [docs/api-contracts.md](../../docs/api-contracts.md) - Docker configuration examples
