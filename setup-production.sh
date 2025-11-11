#!/bin/bash

#############################################################################
# Quick Deployment Setup Script
#
# This script helps you quickly set up RadioChatBox for automatic deployment.
# Run this on your production server.
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
command -v docker >/dev/null 2>&1 || { echo "❌ docker is required but not installed. Aborting."; exit 1; }
command -v docker-compose >/dev/null 2>&1 || command -v docker compose >/dev/null 2>&1 || { echo "❌ docker-compose is required but not installed. Aborting."; exit 1; }

echo "✅ Prerequisites check passed"
echo ""

# Get deployment directory
read -p "Enter deployment directory [/var/www/radiochatbox]: " DEPLOY_DIR
DEPLOY_DIR=${DEPLOY_DIR:-/var/www/radiochatbox}

echo ""
echo "Creating deployment directory: $DEPLOY_DIR"
sudo mkdir -p "$DEPLOY_DIR"
sudo chown $USER:$USER "$DEPLOY_DIR"

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

# Configure .env
echo ""
echo "Setting up environment configuration..."
if [ ! -f .env ]; then
    cp .env.example .env
    echo "✅ Created .env file"
    
    # Generate random passwords
    DB_PASS=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 24)
    
    # Update .env with secure passwords
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env
    
    echo ""
    echo "⚠️  IMPORTANT: Your database password has been set to:"
    echo "   $DB_PASS"
    echo ""
    echo "   Save this password securely!"
    echo ""
fi

# Get domain name
read -p "Enter your domain name (e.g., radio.example.com): " DOMAIN_NAME
if [ ! -z "$DOMAIN_NAME" ]; then
    sed -i "s|ALLOWED_ORIGINS=.*|ALLOWED_ORIGINS=https://$DOMAIN_NAME,https://www.$DOMAIN_NAME|" .env
fi

# Make deploy script executable
chmod +x deploy.sh

echo ""
echo "Running initial deployment..."
./deploy.sh

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
echo "   - HEALTH_CHECK_URL: http://$HOSTNAME or https://$DOMAIN_NAME"
echo ""
echo "2. Generate SSH key for GitHub Actions:"
echo "   ssh-keygen -t rsa -b 4096 -C 'github-deploy' -f ~/.ssh/github_deploy"
echo "   ssh-copy-id -i ~/.ssh/github_deploy.pub $USER@$HOSTNAME"
echo "   cat ~/.ssh/github_deploy  # Copy this to GitHub SSH_PRIVATE_KEY secret"
echo ""
echo "3. Access your application:"
if [ ! -z "$DOMAIN_NAME" ]; then
    echo "   https://$DOMAIN_NAME"
else
    echo "   http://$HOSTNAME:98"
fi
echo ""
echo "4. Change admin password at:"
if [ ! -z "$DOMAIN_NAME" ]; then
    echo "   https://$DOMAIN_NAME/admin.html"
else
    echo "   http://$HOSTNAME:98/admin.html"
fi
echo "   (Default: admin/admin123)"
echo ""
echo "5. See DEPLOYMENT.md for Nginx/SSL setup"
echo ""

exit 0
