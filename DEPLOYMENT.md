# RadioChatBox Deployment Guide

This guide explains how to deploy RadioChatBox to production with automatic deployment on every git push.

---

## Deployment Methods

RadioChatBox supports two deployment methods:

1. **Automatic Deployment** (via GitHub Actions) - Recommended
2. **Manual Deployment** (via SSH)

---

## 1. Automatic Deployment Setup

### Prerequisites

- Ubuntu/Debian server with Docker and Docker Compose installed
- SSH access to your server
- GitHub repository

### Step 1: Prepare Your Server

```bash
# SSH into your server
ssh user@your-server.com

# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Install Docker Compose
sudo apt-get update
sudo apt-get install docker-compose-plugin

# Create deployment directory
sudo mkdir -p /var/www/radiochatbox
sudo chown $USER:$USER /var/www/radiochatbox
cd /var/www/radiochatbox

# Clone repository
git clone https://github.com/yourusername/RadioChatBox.git .

# Copy and configure .env
cp .env.example .env
nano .env  # Edit with production values

# Make deployment script executable
chmod +x deploy.sh

# Initial deployment
./deploy.sh
```

### Step 2: Configure GitHub Secrets

**IMPORTANT**: Add these as **Repository Secrets** (NOT environment variables).

Go to your GitHub repository → **Settings** → **Secrets and variables** → **Actions** → Click **"New repository secret"**

Add each of the following secrets individually:

| Secret Name | Description | Example | Required |
|------------|-------------|---------|----------|
| `SSH_PRIVATE_KEY` | Private SSH key for server access | `-----BEGIN RSA PRIVATE KEY-----...` | ✅ Yes |
| `SERVER_HOST` | Your server's IP or domain | `123.45.67.89` or `radio.example.com` | ✅ Yes |
| `SERVER_USER` | SSH username | `deploy` or `ubuntu` | ✅ Yes |
| `DEPLOY_PATH` | Full path to application | `/var/www/radiochatbox` | ✅ Yes |
| `HEALTH_CHECK_URL` | Base URL for health checks | `https://radio.example.com` | ✅ Yes |
| `SLACK_WEBHOOK` | Slack webhook for notifications | `https://hooks.slack.com/...` | ❌ Optional |

**Note**: These are GitHub repository secrets, accessed in workflows as `${{ secrets.SECRET_NAME }}`. Do NOT add them as environment variables or commit them to your repository.

### Step 3: Generate SSH Key for Deployment

On your local machine:

```bash
# Generate deployment key
ssh-keygen -t rsa -b 4096 -C "github-deploy" -f ~/.ssh/github_deploy_radiochatbox

# Copy public key to server
ssh-copy-id -i ~/.ssh/github_deploy_radiochatbox.pub user@your-server.com

# Display private key (copy this to GitHub secret SSH_PRIVATE_KEY)
cat ~/.ssh/github_deploy_radiochatbox
```

### Step 4: Test Automatic Deployment

```bash
# Make a small change
echo "# Test deployment" >> README.md

# Commit and push
git add README.md
git commit -m "Test automatic deployment"
git push origin main

# Watch deployment in GitHub Actions tab
# Check your server logs: docker-compose logs -f
```

---

## 2. Manual Deployment

If you prefer manual deployment or want to test locally:

```bash
# SSH into your server
ssh user@your-server.com
cd /var/www/radiochatbox

# Run deployment script
./deploy.sh
```

---

## Deployment Script Features

The `deploy.sh` script automatically:

1. ✅ Creates database backup before deployment
2. ✅ Pulls latest code from Git
3. ✅ Runs database migrations
4. ✅ Updates Composer dependencies
5. ✅ Rebuilds Docker containers
6. ✅ Runs health checks
7. ✅ Clears Redis cache
8. ✅ Cleans up old Docker images
9. ✅ Sets correct file permissions
10. ✅ Logs all actions to `deploy.log`

---

## Production Configuration

### Environment Variables (.env)

**Critical settings for production:**

```bash
# Database - Use strong passwords!
DB_PASSWORD=CHANGE_THIS_STRONG_PASSWORD

# Security
ALLOWED_ORIGINS=https://yourradio.com,https://www.yourradio.com

# Performance
CHAT_HISTORY_LIMIT=100
CHAT_MESSAGE_TTL=3600
```

### Nginx Reverse Proxy (Recommended)

For production, place Nginx in front of Apache:

```nginx
# /etc/nginx/sites-available/radiochatbox
server {
    listen 80;
    server_name radio.example.com;
    
    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name radio.example.com;
    
    ssl_certificate /etc/letsencrypt/live/radio.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/radio.example.com/privkey.pem;
    
    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    location / {
        proxy_pass http://localhost:98;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    
    # SSE specific settings
    location /api/stream.php {
        proxy_pass http://localhost:98;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # SSE requires these settings
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 24h;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        chunked_transfer_encoding off;
    }
}
```

Enable site:
```bash
sudo ln -s /etc/nginx/sites-available/radiochatbox /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL Certificate (Let's Encrypt)

```bash
# Install Certbot
sudo apt-get install certbot python3-certbot-nginx

# Get certificate
sudo certbot --nginx -d radio.example.com

# Auto-renewal is configured automatically
```

---

## Monitoring & Maintenance

### View Logs

```bash
# All containers
docker-compose logs -f

# Specific container
docker-compose logs -f apache
docker-compose logs -f postgres
docker-compose logs -f redis

# Deployment logs
tail -f /var/www/radiochatbox/deploy.log
```

### Database Backups

Automatic backups are created on each deployment in `backups/` directory.

**Manual backup:**
```bash
docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox > backup.sql
```

**Restore backup:**
```bash
docker exec -i radiochatbox_postgres psql -U radiochatbox radiochatbox < backup.sql
```

### Monitoring Checklist

- [ ] Set up monitoring (Uptime Robot, Pingdom, or similar)
- [ ] Configure log rotation for `deploy.log`
- [ ] Set up daily database backups (cron job)
- [ ] Monitor disk space for uploaded photos
- [ ] Monitor Redis memory usage
- [ ] Set up alerts for container crashes

### Automated Backup Cron Job

Add to crontab (`crontab -e`):

```bash
# Daily backup at 2 AM
0 2 * * * cd /var/www/radiochatbox && docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox | gzip > backups/daily_$(date +\%Y\%m\%d).sql.gz

# Weekly cleanup of old photos (48h+ old)
0 3 * * 0 cd /var/www/radiochatbox && docker exec radiochatbox_apache php /var/www/html/public/api/cron/cleanup.php
```

---

## Rollback Procedure

If deployment fails:

```bash
# SSH into server
ssh user@your-server.com
cd /var/www/radiochatbox

# Revert to previous commit
git log --oneline -10  # Find commit hash
git reset --hard <previous-commit-hash>

# Restore database if needed
docker exec -i radiochatbox_postgres psql -U radiochatbox radiochatbox < backups/db_backup_YYYYMMDD_HHMMSS.sql

# Restart containers
docker-compose up -d --force-recreate

# Verify
curl http://localhost:98/api/health.php
```

---

## Troubleshooting

### Deployment fails with "Permission denied"

```bash
# Fix file permissions
sudo chown -R $USER:$USER /var/www/radiochatbox
chmod +x deploy.sh
```

### Health check fails

```bash
# Check container status
docker-compose ps

# Check logs
docker-compose logs apache

# Verify ports
sudo netstat -tlnp | grep 98

# Test manually
curl -v http://localhost:98/api/health.php
```

### Database migration fails

```bash
# Check PostgreSQL logs
docker-compose logs postgres

# Manually apply migration
docker exec radiochatbox_postgres psql -U radiochatbox -d radiochatbox -f /docker-entrypoint-initdb.d/migrations/001_migration.sql
```

### Redis connection issues

```bash
# Check Redis status
docker exec radiochatbox_redis redis-cli ping

# Should return PONG
```

---

## Security Best Practices

1. **Change default admin password** immediately after first deployment
2. **Use strong database passwords** in production `.env`
3. **Enable HTTPS** with Let's Encrypt
4. **Restrict SSH access** (use SSH keys, disable password auth)
5. **Keep Docker images updated** regularly
6. **Set up firewall** (UFW):
   ```bash
   sudo ufw allow 22/tcp   # SSH
   sudo ufw allow 80/tcp   # HTTP
   sudo ufw allow 443/tcp  # HTTPS
   sudo ufw enable
   ```
7. **Regular backups** of database and `.env` file
8. **Monitor logs** for suspicious activity

---

## Performance Optimization

For high-traffic deployments:

1. **Increase Redis memory**: Edit `docker-compose.yml`
   ```yaml
   redis:
     command: redis-server --maxmemory 512mb --maxmemory-policy allkeys-lru
   ```

2. **PostgreSQL tuning**: Add to PostgreSQL config
   ```
   shared_buffers = 256MB
   effective_cache_size = 1GB
   max_connections = 200
   ```

3. **Enable PHP OpCache**: Already enabled in Dockerfile

4. **CDN for static assets**: Use Cloudflare or similar

---

## Support

For issues with deployment:

- Check logs: `docker-compose logs -f`
- Review deployment log: `cat deploy.log`
- GitHub Issues: https://github.com/mrpc/RadioChatBox/issues
- Check health endpoint: `curl http://localhost:98/api/health.php`

---

**Last Updated**: 2025-11-11
