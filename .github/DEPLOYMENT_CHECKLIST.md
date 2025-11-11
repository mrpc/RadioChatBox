# RadioChatBox Production Deployment Checklist

Use this checklist to ensure a smooth production deployment.

---

## ☐ Pre-Deployment (One-Time Setup)

### Server Preparation

- [ ] Provision Ubuntu/Debian server (minimum 2GB RAM, 2 CPU cores, 20GB disk)
- [ ] Update system packages: `sudo apt update && sudo apt upgrade -y`
- [ ] Install Docker: `curl -fsSL https://get.docker.com | sh`
- [ ] Install Docker Compose: `sudo apt install docker-compose-plugin`
- [ ] Add user to docker group: `sudo usermod -aG docker $USER`
- [ ] Create deployment user (if needed): `sudo adduser deploy`
- [ ] Configure firewall:
  ```bash
  sudo ufw allow 22/tcp   # SSH
  sudo ufw allow 80/tcp   # HTTP
  sudo ufw allow 443/tcp  # HTTPS
  sudo ufw enable
  ```

### GitHub Configuration

- [ ] Generate SSH key for deployment:
  ```bash
  ssh-keygen -t rsa -b 4096 -C "github-deploy" -f ~/.ssh/github_deploy_radiochatbox
  ```
- [ ] Add public key to server: `ssh-copy-id -i ~/.ssh/github_deploy_radiochatbox.pub user@server`
- [ ] Copy private key to GitHub secret `SSH_PRIVATE_KEY`
- [ ] Add GitHub secret `SERVER_HOST` (your server IP/domain)
- [ ] Add GitHub secret `SERVER_USER` (SSH username)
- [ ] Add GitHub secret `DEPLOY_PATH` (e.g., `/var/www/radiochatbox`)
- [ ] Add GitHub secret `HEALTH_CHECK_URL` (e.g., `https://radio.example.com`)
- [ ] (Optional) Add GitHub secret `SLACK_WEBHOOK` for notifications

### Application Setup

- [ ] Clone repository to deployment directory
- [ ] Copy `.env.example` to `.env`
- [ ] Generate strong database password in `.env`
- [ ] Update `ALLOWED_ORIGINS` in `.env` with your domain(s)
- [ ] Make scripts executable: `chmod +x deploy.sh setup-production.sh`
- [ ] Run initial deployment: `./deploy.sh`
- [ ] Verify services are running: `docker-compose ps`
- [ ] Test health endpoint: `curl http://localhost:98/api/health.php`

---

## ☐ Security Hardening

### Application Security

- [ ] Change default admin password (visit `/admin.html`)
- [ ] Review and update banned nicknames in database
- [ ] Configure URL blacklist patterns
- [ ] Set strong session timeout values
- [ ] Enable rate limiting (already configured)
- [ ] Review CORS settings in `.env`

### Server Security

- [ ] Disable root SSH login: Edit `/etc/ssh/sshd_config` → `PermitRootLogin no`
- [ ] Disable password authentication (use SSH keys only)
- [ ] Configure fail2ban for SSH protection
- [ ] Set up automatic security updates:
  ```bash
  sudo apt install unattended-upgrades
  sudo dpkg-reconfigure -plow unattended-upgrades
  ```
- [ ] Install and configure log monitoring
- [ ] Set up intrusion detection (optional: OSSEC, Snort)

---

## ☐ SSL/HTTPS Setup (Recommended)

### Using Let's Encrypt (Free)

- [ ] Install Certbot: `sudo apt install certbot python3-certbot-nginx`
- [ ] Stop any service using port 80: `docker-compose down`
- [ ] Obtain certificate: `sudo certbot certonly --standalone -d radio.example.com`
- [ ] Configure Nginx reverse proxy (see `DEPLOYMENT.md`)
- [ ] Set up auto-renewal: Certbot creates cron job automatically
- [ ] Test renewal: `sudo certbot renew --dry-run`
- [ ] Restart services: `docker-compose up -d`
- [ ] Verify HTTPS: Visit `https://radio.example.com`

---

## ☐ Nginx Reverse Proxy (Recommended)

- [ ] Install Nginx: `sudo apt install nginx`
- [ ] Create site configuration (see `DEPLOYMENT.md` for template)
- [ ] Enable site: `sudo ln -s /etc/nginx/sites-available/radiochatbox /etc/nginx/sites-enabled/`
- [ ] Test configuration: `sudo nginx -t`
- [ ] Reload Nginx: `sudo systemctl reload nginx`
- [ ] Verify proxy is working: `curl -I https://radio.example.com`

---

## ☐ Monitoring & Backups

### Automated Backups

- [ ] Set up daily database backups (cron job):
  ```bash
  crontab -e
  # Add: 0 2 * * * cd /var/www/radiochatbox && docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox | gzip > backups/daily_$(date +\%Y\%m\%d).sql.gz
  ```
- [ ] Set up weekly cleanup of old photos:
  ```bash
  # Add to crontab: 0 3 * * 0 cd /var/www/radiochatbox && docker exec radiochatbox_apache php /var/www/html/public/api/cron/cleanup.php
  ```
- [ ] Test backup restoration procedure
- [ ] Store critical backups off-site (S3, Backblaze, etc.)

### Monitoring

- [ ] Set up uptime monitoring (UptimeRobot, Pingdom, etc.)
- [ ] Configure health check endpoint monitoring
- [ ] Set up log aggregation (optional: ELK stack, Graylog)
- [ ] Monitor disk space: `df -h`
- [ ] Monitor Docker container health: `docker-compose ps`
- [ ] Set up alerts for:
  - [ ] Service downtime
  - [ ] High CPU/memory usage
  - [ ] Disk space < 20%
  - [ ] Failed deployments
  - [ ] Database connection errors

---

## ☐ Testing Deployment

### Pre-Launch Testing

- [ ] Test public chat functionality
- [ ] Test private messaging
- [ ] Test photo uploads
- [ ] Test user registration
- [ ] Test admin panel access
- [ ] Test moderation features (ban IP, ban nickname)
- [ ] Test rate limiting (send many messages quickly)
- [ ] Test SSE connection (messages appear in real-time)
- [ ] Test on mobile devices
- [ ] Test with multiple concurrent users
- [ ] Verify auto-cleanup of inactive users works
- [ ] Verify photo expiration (48h) works

### Performance Testing

- [ ] Load test with 100+ concurrent connections
- [ ] Monitor Redis memory usage under load
- [ ] Monitor PostgreSQL connection pool
- [ ] Check response times for API endpoints
- [ ] Verify SSE doesn't drop connections under load
- [ ] Test message throughput (messages/second)

### Auto-Deploy Testing

- [ ] Make a test commit and push to `main`
- [ ] Watch GitHub Actions workflow
- [ ] Verify tests run successfully
- [ ] Verify deployment completes
- [ ] Check deployment logs on server: `cat deploy.log`
- [ ] Verify health check passes
- [ ] Test rollback procedure

---

## ☐ Post-Deployment

### Immediate Tasks

- [ ] Verify application is accessible at production URL
- [ ] Test all major features in production
- [ ] Monitor logs for errors: `docker-compose logs -f`
- [ ] Check database for initial data
- [ ] Verify Redis is caching properly
- [ ] Test embed code on your radio website (if applicable)

### Documentation

- [ ] Document server access credentials (store securely)
- [ ] Document database passwords (store securely)
- [ ] Create runbook for common operations
- [ ] Document emergency contacts
- [ ] Share deployment URLs with team

### Ongoing Maintenance

- [ ] Schedule weekly review of deployment logs
- [ ] Schedule monthly security updates
- [ ] Schedule quarterly performance review
- [ ] Monitor GitHub Actions usage/limits
- [ ] Review and update dependencies regularly
- [ ] Monitor for new PHP/PostgreSQL/Redis versions

---

## ☐ Performance Optimization (Optional)

For high-traffic deployments:

- [ ] Increase Redis memory limit in `docker-compose.yml`
- [ ] Tune PostgreSQL settings for your workload
- [ ] Enable PHP OpCache (already enabled in Dockerfile)
- [ ] Set up CDN for static assets (Cloudflare)
- [ ] Implement horizontal scaling if needed
- [ ] Add read replicas for database (if needed)
- [ ] Configure connection pooling (pgBouncer)

---

## ☐ Compliance & Legal (If Applicable)

- [ ] Add privacy policy
- [ ] Add terms of service
- [ ] Configure GDPR compliance features
- [ ] Set up user data export/deletion
- [ ] Configure cookie consent (if in EU)
- [ ] Add content moderation policies
- [ ] Set up abuse reporting system

---

## 📝 Quick Reference Commands

```bash
# View deployment logs
tail -f /var/www/radiochatbox/deploy.log

# View container logs
docker-compose logs -f

# Check container status
docker-compose ps

# Restart services
docker-compose restart

# View database backups
ls -lh /var/www/radiochatbox/backups/

# Manual backup
docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox > backup.sql

# Test health endpoint
curl http://localhost:98/api/health.php

# Check disk space
df -h

# Monitor Redis memory
docker exec radiochatbox_redis redis-cli INFO memory

# View active connections
docker exec radiochatbox_postgres psql -U radiochatbox -d radiochatbox -c "SELECT * FROM active_users;"
```

---

## 🆘 Emergency Contacts

- **GitHub Issues**: https://github.com/mrpc/RadioChatBox/issues
- **Server Provider Support**: [Your hosting provider]
- **Domain Registrar**: [Your domain provider]
- **SSL Provider**: Let's Encrypt (free, automated)

---

## ✅ Launch Ready!

Once all items are checked, you're ready for production! 🚀

**Last Review Date**: ________________

**Reviewed By**: ________________

**Production URL**: ________________

**Admin Password Changed**: ☐ Yes  ☐ No
