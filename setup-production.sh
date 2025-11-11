#!/bin/bash

#############################################################################
# Production Setup Script
#
# This script helps you set up RadioChatBox on a production server with
# Apache, PHP, PostgreSQL, and Redis (no Docker required).
#
# Requirements:
#   - Ubuntu/Debian server
#   - sudo access
#   - Git
#
# Usage: ./setup-production.sh
#############################################################################

set -e

echo "========================================="
echo "RadioChatBox Production Setup"
echo "========================================="
echo ""

# Check if running as root
if [ "$EUID" -eq 0 ]; then 
    echo "❌ Please do not run as root. Run as a regular user with sudo access."
    exit 1
fi

# Check for required commands
command -v git >/dev/null 2>&1 || { echo "❌ git is required but not installed. Aborting."; exit 1; }

echo "✅ Prerequisites check passed"
echo ""

# Prompt for installation
read -p "Do you want to install Apache, PHP 8.3, PostgreSQL, and Redis? (y/n): " INSTALL_DEPS
if [ "$INSTALL_DEPS" = "y" ] || [ "$INSTALL_DEPS" = "Y" ]; then
    echo ""
    echo "Installing system dependencies..."
    
    # Update package list
    sudo apt-get update
    
    # Install Apache
    echo "Installing Apache..."
    sudo apt-get install -y apache2
    
    # Install PHP 8.3 and extensions
    echo "Installing PHP 8.3..."
    sudo apt-get install -y software-properties-common
    sudo add-apt-repository -y ppa:ondrej/php
    sudo apt-get update
    sudo apt-get install -y php8.3 php8.3-cli php8.3-fpm php8.3-pgsql php8.3-redis \
        php8.3-gd php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl
    
    # Install PostgreSQL
    echo "Installing PostgreSQL..."
    sudo apt-get install -y postgresql postgresql-contrib
    
    # Install Redis
    echo "Installing Redis..."
    sudo apt-get install -y redis-server
    
    # Install Composer
    echo "Installing Composer..."
    curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
    
    # Enable Apache modules
    echo "Enabling Apache modules..."
    sudo a2enmod rewrite headers deflate expires proxy_fcgi setenvif
    sudo a2enconf php8.3-fpm
    
    echo "✅ System dependencies installed"
fi

echo ""

# Get deployment directory
read -p "Enter deployment directory [/var/www/radiochatbox]: " DEPLOY_DIR
DEPLOY_DIR=${DEPLOY_DIR:-/var/www/radiochatbox}

echo ""
echo "Creating deployment directory: $DEPLOY_DIR"
sudo mkdir -p "$DEPLOY_DIR"
sudo chown $USER:www-data "$DEPLOY_DIR"
sudo chmod 775 "$DEPLOY_DIR"

# Get repository URL
read -p "Enter GitHub repository URL: " REPO_URL
if [ -z "$REPO_URL" ]; then
    echo "❌ Repository URL is required"
    exit 1
fi

echo ""
echo "Cloning repository..."
cd "$DEPLOY_DIR"
git clone "$REPO_URL" .

# Configure database
echo ""
read -p "Configure PostgreSQL database? (y/n): " SETUP_DB
if [ "$SETUP_DB" = "y" ] || [ "$SETUP_DB" = "Y" ]; then
    DB_NAME="radiochatbox"
    DB_USER="radiochatbox"
    DB_PASS=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 24)
    
    echo "Creating PostgreSQL database and user..."
    sudo -u postgres psql <<EOF
CREATE DATABASE $DB_NAME;
CREATE USER $DB_USER WITH ENCRYPTED PASSWORD '$DB_PASS';
GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;
\c $DB_NAME
GRANT ALL ON SCHEMA public TO $DB_USER;
EOF
    
    echo "Importing database schema..."
    sudo -u postgres psql -d "$DB_NAME" -f "$DEPLOY_DIR/database/init.sql"
    
    echo "✅ Database configured"
    echo ""
    echo "⚠️  IMPORTANT: Save these database credentials:"
    echo "   Database: $DB_NAME"
    echo "   User: $DB_USER"
    echo "   Password: $DB_PASS"
    echo ""
fi

# Configure .env
echo ""
echo "Setting up environment configuration..."
if [ ! -f .env ]; then
    cat > .env <<EOF
# Database Configuration
DB_HOST=localhost
DB_PORT=5432
DB_NAME=${DB_NAME:-radiochatbox}
DB_USER=${DB_USER:-radiochatbox}
DB_PASSWORD=${DB_PASS:-change_me}

# Redis Configuration
REDIS_HOST=localhost
REDIS_PORT=6379

# Application Configuration
APP_ENV=production
APP_DEBUG=false

# CORS Configuration
ALLOWED_ORIGINS=*

# Admin Authentication
ADMIN_USERNAME=admin
ADMIN_PASSWORD=admin123
EOF
    
    echo "✅ Created .env file"
fi

# Get domain name
read -p "Enter your domain name (e.g., radio.example.com) [optional]: " DOMAIN_NAME
if [ ! -z "$DOMAIN_NAME" ]; then
    sed -i "s|ALLOWED_ORIGINS=.*|ALLOWED_ORIGINS=https://$DOMAIN_NAME,https://www.$DOMAIN_NAME|" .env
fi

# Configure Apache virtual host
echo ""
read -p "Configure Apache virtual host? (y/n): " SETUP_APACHE
if [ "$SETUP_APACHE" = "y" ] || [ "$SETUP_APACHE" = "Y" ]; then
    SITE_NAME="${DOMAIN_NAME:-radiochatbox}"
    VHOST_FILE="/etc/apache2/sites-available/${SITE_NAME}.conf"
    
    echo "Creating Apache virtual host..."
    sudo tee "$VHOST_FILE" > /dev/null <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN_NAME:-localhost}
    DocumentRoot $DEPLOY_DIR/public

    <Directory $DEPLOY_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # PHP-FPM Configuration
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Logging
    ErrorLog \${APACHE_LOG_DIR}/${SITE_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_NAME}-access.log combined
</VirtualHost>
EOF
    
    # Enable site and restart Apache
    sudo a2ensite "${SITE_NAME}.conf"
    sudo systemctl restart apache2
    
    echo "✅ Apache virtual host configured"
fi

# Install composer dependencies
echo ""
echo "Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

# Set permissions
echo ""
echo "Setting file permissions..."
sudo chown -R www-data:www-data "$DEPLOY_DIR"
sudo chmod -R 755 "$DEPLOY_DIR/public"
sudo chmod -R 775 "$DEPLOY_DIR/public/uploads"

# Make deploy script executable
chmod +x deploy.sh

echo ""
echo "========================================="
echo "✅ Setup Complete!"
echo "========================================="
echo ""
echo "Next steps:"
echo ""
echo "1. Configure GitHub Secrets (in your repository settings):"
echo "   - SSH_PRIVATE_KEY: Your SSH private key"
echo "   - SERVER_HOST: $HOSTNAME"
echo "   - SERVER_USER: $USER"
echo "   - DEPLOY_PATH: $DEPLOY_DIR"
if [ ! -z "$DOMAIN_NAME" ]; then
    echo "   - HEALTH_CHECK_URL: https://$DOMAIN_NAME/api/health.php"
else
    echo "   - HEALTH_CHECK_URL: http://$HOSTNAME/api/health.php"
fi
echo ""
echo "2. Generate SSH key for GitHub Actions:"
echo "   ssh-keygen -t rsa -b 4096 -C 'github-deploy' -f ~/.ssh/github_deploy"
echo "   ssh-copy-id -i ~/.ssh/github_deploy.pub $USER@$HOSTNAME"
echo "   cat ~/.ssh/github_deploy  # Copy this to GitHub SSH_PRIVATE_KEY secret"
echo ""
echo "3. Change admin password (default: admin/admin123)"
echo "   Edit .env and update ADMIN_USERNAME and ADMIN_PASSWORD"
echo ""
if [ ! -z "$DOMAIN_NAME" ]; then
    echo "4. Set up SSL with Let's Encrypt:"
    echo "   sudo apt-get install certbot python3-certbot-apache"
    echo "   sudo certbot --apache -d $DOMAIN_NAME"
    echo ""
    echo "5. Access your application:"
    echo "   https://$DOMAIN_NAME"
    echo "   Admin: https://$DOMAIN_NAME/admin.html"
else
    echo "4. Access your application:"
    echo "   http://$HOSTNAME"
    echo "   Admin: http://$HOSTNAME/admin.html"
fi
echo ""
echo "6. Test deployment:"
echo "   cd $DEPLOY_DIR && ./deploy.sh"
echo ""

exit 0
