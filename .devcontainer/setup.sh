#!/bin/bash
# setup.sh - phpBB AT Protocol Extension Development Environment
set -e

echo "[Setup] Starting phpBB AT Protocol extension development environment..."

# Start MySQL
echo "[Setup] Starting MySQL..."
sudo service mysql start

# Wait for MySQL to be ready
echo "[Setup] Waiting for MySQL to be ready..."
for i in {1..30}; do
    if sudo mysqladmin ping -u root --silent 2>/dev/null; then
        break
    fi
    sleep 1
done

# Fix MySQL socket directory permissions (needed for www-data access)
sudo chmod 755 /var/run/mysqld/

# Create MySQL user and database
echo "[Setup] Creating MySQL database and user..."
sudo mysql -u root <<EOFMYSQL
    CREATE USER IF NOT EXISTS 'phpbb'@'localhost' IDENTIFIED BY 'phpbb';
    GRANT ALL PRIVILEGES ON *.* TO 'phpbb'@'localhost' WITH GRANT OPTION;
    CREATE DATABASE IF NOT EXISTS phpbb;
    FLUSH PRIVILEGES;
EOFMYSQL

# Clone phpBB if not already present
PHPBB_DIR="/workspaces/phpbb-core"
if [ ! -d "$PHPBB_DIR" ]; then
    echo "[Setup] Cloning phpBB 3.3.x..."
    sudo git clone --depth 1 --branch 3.3.x https://github.com/phpbb/phpbb.git "$PHPBB_DIR"
    sudo chown -R vscode:vscode "$PHPBB_DIR"
fi

# Install phpBB composer dependencies
echo "[Setup] Installing phpBB dependencies..."
cd "$PHPBB_DIR/phpBB"
composer install --no-interaction --prefer-dist

# Create symlink for our extension
echo "[Setup] Linking AT Protocol extension..."
mkdir -p "$PHPBB_DIR/phpBB/ext/phpbb"
rm -rf "$PHPBB_DIR/phpBB/ext/phpbb/atproto"
ln -s /workspaces/phpbb/ext/phpbb/atproto "$PHPBB_DIR/phpBB/ext/phpbb/atproto"

# Configure Apache to serve phpBB
echo "[Setup] Configuring Apache..."
sudo rm -rf /var/www/html
sudo ln -s "$PHPBB_DIR/phpBB" /var/www/html

# Create Apache virtual host for port 8080
sudo tee /etc/apache2/sites-available/phpbb.conf > /dev/null <<EOF
<VirtualHost *:8080>
    DocumentRoot /var/www/html
    <Directory /var/www/html>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

sudo a2dissite 000-default.conf 2>/dev/null || true
sudo a2ensite phpbb.conf

# Start Apache
echo "[Setup] Starting Apache..."
sudo apache2ctl start

# Create phpBB install config
echo "[Setup] Creating phpBB installation config..."
INSTALL_CONFIG="/tmp/phpbb-install-config.yml"

# Handle Codespaces URL
if [ "$CODESPACES" = "true" ]; then
    SERVER_NAME="${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
    SERVER_PORT="443"
    SERVER_PROTOCOL="https://"
else
    SERVER_NAME="localhost"
    SERVER_PORT="8080"
    SERVER_PROTOCOL="http://"
fi

cat > "$INSTALL_CONFIG" <<EOF
installer:
    admin:
        name: admin
        password: adminadmin
        email: admin@localhost.local

    board:
        lang: en
        name: AT Protocol Test Forum
        description: Development environment for phpBB AT Protocol extension

    database:
        dbms: mysqli
        dbhost: localhost
        dbport: ~
        dbuser: phpbb
        dbpasswd: phpbb
        dbname: phpbb
        table_prefix: phpbb_

    email:
        enabled: false

    server:
        cookie_secure: false
        server_protocol: ${SERVER_PROTOCOL}
        force_server_vars: false
        server_name: ${SERVER_NAME}
        server_port: ${SERVER_PORT}
        script_path: /

    extensions: []
EOF

# Install phpBB
echo "[Setup] Installing phpBB via CLI..."
cd "$PHPBB_DIR/phpBB"
if php install/phpbbcli.php install "$INSTALL_CONFIG"; then
    # Remove install directory (security) - only if install succeeded
    rm -rf "$PHPBB_DIR/phpBB/install"
else
    echo "[Setup] WARNING: phpBB installation failed. Install directory kept for debugging."
    echo "[Setup] You can manually install via web at http://localhost:8080/install/"
fi

# Set permissions
echo "[Setup] Setting permissions..."
sudo chown -R www-data:www-data "$PHPBB_DIR/phpBB/cache"
sudo chown -R www-data:www-data "$PHPBB_DIR/phpBB/store"
sudo chown -R www-data:www-data "$PHPBB_DIR/phpBB/files"
sudo chown -R www-data:www-data "$PHPBB_DIR/phpBB/images/avatars/upload"
sudo chmod -R 775 "$PHPBB_DIR/phpBB/cache"
sudo chmod -R 775 "$PHPBB_DIR/phpBB/store"
sudo chmod -R 775 "$PHPBB_DIR/phpBB/files"

echo "[Setup] ================================================"
echo "[Setup] phpBB AT Protocol Extension environment ready!"
echo "[Setup] ================================================"
if [ "$CODESPACES" = "true" ]; then
    echo "[Setup] Forum URL: ${SERVER_PROTOCOL}${SERVER_NAME}/"
else
    echo "[Setup] Forum URL: ${SERVER_PROTOCOL}${SERVER_NAME}:${SERVER_PORT}/"
fi
echo "[Setup] Admin login: admin / adminadmin"
echo "[Setup] MySQL: phpbb / phpbb / phpbb"
echo "[Setup] Extension path: ext/phpbb/atproto"
echo "[Setup] ================================================"
echo "[Setup] To enable the extension, go to ACP > Customise > Extensions"
echo "[Setup] ================================================"
