#!/bin/bash

#############################################################################
# RadioChatBox - Production Setup
#
# This script sets up RadioChatBox on an existing LAMP stack.
# Assumes Apache, PHP 8.3+, PostgreSQL, Redis are already installed.
#
# Usage: ./setup-production.sh
#############################################################################

set -e

echo "========================================="
echo "RadioChatBox Production Installation"
echo "========================================="
echo ""

# Check if running as root
if [ "$EUID" -eq 0 ]; then 
    echo "❌ Please do not run as root. Run as a regular user with sudo access."
    exit 1
fi

# Check for required commands
echo "Checking prerequisites..."
command -v git >/dev/null 2>&1 || { echo "❌ git is required but not installed."; exit 1; }
command -v php >/dev/null 2>&1 || { echo "❌ php is required but not installed."; exit 1; }
command -v psql >/dev/null 2>&1 || { echo "❌ PostgreSQL (psql) is required but not installed."; exit 1; }
command -v redis-cli >/dev/null 2>&1 || { echo "❌ Redis is required but not installed."; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "❌ Composer is required but not installed."; exit 1; }

# Check PHP version
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
PHP_MAJOR=$(echo $PHP_VERSION | cut -d. -f1)
PHP_MINOR=$(echo $PHP_VERSION | cut -d. -f2)
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 3 ]; }; then
    echo "❌ PHP 8.3 or higher is required. Found: $PHP_VERSION"
    exit 1
fi

echo "✅ All prerequisites found"
echo "   PHP: $PHP_VERSION"
echo ""

# Get current directory (project should already be cloned here)
PROJECT_DIR=$(pwd)

# Verify we're in the right directory
if [ ! -f "composer.json" ] || [ ! -d "database" ]; then
    echo "❌ This doesn't appear to be the RadioChatBox directory."
    echo "   Please run this script from the project root."
    exit 1
fi

echo "Installing in: $PROJECT_DIR"
echo ""

# Create .env if it doesn't exist
if [ ! -f .env ]; then
    echo "Creating .env configuration file..."
    
    # Prompt for database credentials
    read -p "Database name [radiochatbox]: " DB_NAME_INPUT
    DB_NAME=${DB_NAME_INPUT:-radiochatbox}
    
    read -p "Database user [radiochatbox]: " DB_USER_INPUT
    DB_USER=${DB_USER_INPUT:-radiochatbox}
    
    # Generate random password
    DB_PASS=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 24)
    read -p "Database password [auto-generated]: " DB_PASS_INPUT
    DB_PASS=${DB_PASS_INPUT:-$DB_PASS}
    
    cat > .env <<EOF
# Database Configuration
DB_HOST=localhost
DB_PORT=5432
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASSWORD=$DB_PASS

# Redis Configuration
REDIS_HOST=localhost
REDIS_PORT=6379

# Application Configuration
APP_ENV=production
APP_DEBUG=false

# CORS Configuration
ALLOWED_ORIGINS=*

# Admin Authentication (CHANGE THESE!)
ADMIN_USERNAME=admin
ADMIN_PASSWORD=admin123
EOF
    
    echo "✅ Created .env file"
    echo ""
else
    echo ".env file already exists. Using existing configuration."
    echo ""
fi

# Source .env to get database config
source .env

# Configure database
echo "Setting up PostgreSQL database..."
echo "Database: $DB_NAME"
echo "User: $DB_USER"
echo ""
read -p "Set up this database? (y/n): " SETUP_DB
if [ "$SETUP_DB" = "y" ] || [ "$SETUP_DB" = "Y" ]; then
    
    # Check if database exists
    DB_EXISTS=$(sudo -u postgres psql -lqt | cut -d \| -f 1 | grep -qw "$DB_NAME" && echo "yes" || echo "no")
    
    if [ "$DB_EXISTS" = "yes" ]; then
        echo "⚠️  Database '$DB_NAME' already exists."
        
        # Check if database is empty
        TABLE_COUNT=$(sudo -u postgres psql -d "$DB_NAME" -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public';" 2>/dev/null | xargs)
        
        if [ "$TABLE_COUNT" -gt 0 ]; then
            echo "⚠️  Database contains $TABLE_COUNT tables."
            read -p "Re-import schema? This will DROP existing tables! (y/n): " REIMPORT
            if [ "$REIMPORT" = "y" ] || [ "$REIMPORT" = "Y" ]; then
                echo "Dropping existing tables and re-importing schema..."
                # Copy SQL file to /tmp so postgres user can access it
                TMP_SQL="/tmp/radiochatbox_init_$$.sql"
                cp "$PROJECT_DIR/database/init.sql" "$TMP_SQL"
                chmod 644 "$TMP_SQL"
                
                # Import as database owner (not postgres superuser)
                sudo -u postgres psql -U $DB_USER -d "$DB_NAME" -f "$TMP_SQL"
                
                # Clean up temp file
                rm -f "$TMP_SQL"
                echo "✅ Schema re-imported"
            else
                echo "Skipping schema import."
            fi
        else
            echo "Database is empty. Importing schema..."
            # Copy SQL file to /tmp so postgres user can access it
            TMP_SQL="/tmp/radiochatbox_init_$$.sql"
            cp "$PROJECT_DIR/database/init.sql" "$TMP_SQL"
            chmod 644 "$TMP_SQL"
            
            # Import as database owner (not postgres superuser)
            sudo -u postgres psql -U $DB_USER -d "$DB_NAME" -f "$TMP_SQL"
            
            # Clean up temp file
            rm -f "$TMP_SQL"
            echo "✅ Schema imported"
        fi
    else
        echo "Creating database and user..."
        
        # Check if user exists
        USER_EXISTS=$(sudo -u postgres psql -t -c "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | xargs)
        
        if [ "$USER_EXISTS" = "1" ]; then
            echo "User '$DB_USER' already exists. Updating password and creating database..."
            sudo -u postgres psql <<EOF
ALTER USER $DB_USER WITH ENCRYPTED PASSWORD '$DB_PASSWORD';
CREATE DATABASE $DB_NAME;
ALTER DATABASE $DB_NAME OWNER TO $DB_USER;
GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;
EOF
        else
            sudo -u postgres psql <<EOF
CREATE USER $DB_USER WITH ENCRYPTED PASSWORD '$DB_PASSWORD';
CREATE DATABASE $DB_NAME;
ALTER DATABASE $DB_NAME OWNER TO $DB_USER;
GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;
EOF
        fi
        
        echo "Importing database schema..."
        # Copy SQL file to /tmp so postgres user can access it
        TMP_SQL="/tmp/radiochatbox_init_$$.sql"
        cp "$PROJECT_DIR/database/init.sql" "$TMP_SQL"
        chmod 644 "$TMP_SQL"
        
        # Import schema as the database owner (not postgres superuser)
        sudo -u postgres psql -U $DB_USER -d "$DB_NAME" -f "$TMP_SQL"
        
        # Clean up temp file
        rm -f "$TMP_SQL"
        
        echo "✅ Database configured"
    fi
    
    echo ""
    echo "⚠️  Database credentials (saved in .env):"
    echo "   Name: $DB_NAME"
    echo "   User: $DB_USER"
    echo "   Password: $DB_PASSWORD"
    echo ""
fi

# Install composer dependencies
echo "Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

echo "✅ Dependencies installed"
echo ""

# Get domain name for CORS (BEFORE changing permissions)
read -p "Enter your domain name (e.g., radio.example.com) [press Enter to skip]: " DOMAIN_NAME
if [ ! -z "$DOMAIN_NAME" ]; then
    sed -i "s|ALLOWED_ORIGINS=.*|ALLOWED_ORIGINS=https://$DOMAIN_NAME,https://www.$DOMAIN_NAME|" .env
    echo "✅ Updated CORS origins for $DOMAIN_NAME"
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
    DocumentRoot $PROJECT_DIR/public

    <Directory $PROJECT_DIR/public>
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
    
    # Enable site
    sudo a2ensite "${SITE_NAME}.conf"
    
    # Enable required Apache modules if not already enabled
    sudo a2enmod rewrite headers deflate expires proxy_fcgi setenvif 2>/dev/null || true
    
    # Restart Apache
    sudo systemctl restart apache2
    
    echo "✅ Apache virtual host configured and enabled"
fi

# Set permissions (DO THIS LAST)
echo ""
echo "Setting file permissions..."
# With PHP-FPM, files should be owned by current user, not www-data
sudo chown -R $USER:$USER "$PROJECT_DIR"
sudo chmod -R 755 "$PROJECT_DIR/public"
sudo mkdir -p "$PROJECT_DIR/public/uploads/photos"
# Only uploads directory needs to be writable by PHP-FPM (current user)
sudo chmod -R 775 "$PROJECT_DIR/public/uploads"

echo "✅ Permissions set"
echo ""

# Make deploy script executable
chmod +x deploy.sh

echo ""
echo "========================================="
echo "✅ Installation Complete!"
echo "========================================="
echo ""
echo "What's next?"
echo ""
echo "1. Change admin password:"
echo "   Edit .env and update ADMIN_USERNAME and ADMIN_PASSWORD"
echo ""
if [ ! -z "$DOMAIN_NAME" ]; then
    echo "2. Set up SSL with Let's Encrypt:"
    echo "   sudo apt-get install certbot python3-certbot-apache"
    echo "   sudo certbot --apache -d $DOMAIN_NAME"
    echo ""
    echo "3. Access your application:"
    echo "   https://$DOMAIN_NAME"
    echo "   Admin: https://$DOMAIN_NAME/admin.html"
else
    echo "2. Access your application:"
    echo "   http://$(hostname -I | awk '{print $1}')"
    echo "   Admin: http://$(hostname -I | awk '{print $1}')/admin.html"
fi
echo ""
echo "4. Set up automatic deployment (optional):"
echo "   See .github/workflows/deploy.yml for GitHub Actions setup"
echo ""
echo "5. Test the deployment script:"
echo "   ./deploy.sh"
echo ""

exit 0
