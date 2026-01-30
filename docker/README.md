# phpBB Docker Environment

Development environment for exploring phpBB's database schema and testing AT Protocol integration.

## Services

| Service | URL | Credentials |
|---------|-----|-------------|
| phpBB Forum | http://localhost:8080 | admin / adminpassword |
| phpMyAdmin | http://localhost:8081 | root / rootpassword |
| MySQL | localhost:3306 | phpbb / phpbbpassword |

## Quick Start

```bash
# Build and start all services
docker compose up -d --build

# Copy install config and run CLI installer
docker cp install-config.yml phpbb-app:/var/www/html/install/install-config.yml
docker exec phpbb-app bash -c 'cd /var/www/html && php install/phpbbcli.php install install/install-config.yml'

# Check service health
docker compose ps

# Access phpBB at http://localhost:8080
```

## Database Access

### Via phpMyAdmin
Open http://localhost:8081 in your browser.

### Via MySQL CLI
```bash
docker compose exec db mysql -u phpbb -pphpbbpassword phpbb
```

### Via External Tool
Connect to `localhost:3306` with:
- Database: `phpbb`
- User: `phpbb`
- Password: `phpbbpassword`

## Useful Commands

```bash
# View phpBB logs
docker compose logs -f phpbb

# Export database schema
docker compose exec db mysqldump -u phpbb -pphpbbpassword --no-data phpbb > schema.sql

# Export full database
docker compose exec db mysqldump -u phpbb -pphpbbpassword phpbb > backup.sql

# Stop services
docker compose down

# Stop and remove volumes (clean slate)
docker compose down -v
```

## Schema Exploration

Once running, use phpMyAdmin or MySQL CLI to explore:

1. **Core tables**: `phpbb_posts`, `phpbb_topics`, `phpbb_forums`, `phpbb_users`
2. **Config tables**: `phpbb_config`, `phpbb_config_text`
3. **Permission tables**: `phpbb_acl_*`
4. **Session/Auth tables**: `phpbb_sessions`, `phpbb_sessions_keys`
