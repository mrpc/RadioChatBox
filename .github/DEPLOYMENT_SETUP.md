# Auto-Deployment Setup Summary

## ✅ What Was Created

I've set up a complete **automatic deployment system** for RadioChatBox that deploys on every git push to the `main` branch.

### Files Created

1. **`.github/workflows/deploy.yml`** - GitHub Actions workflow
   - Runs PHPUnit tests on every push
   - Automatically deploys to production server
   - Performs health checks
   - Sends notifications (optional)

2. **`deploy.sh`** - Production deployment script
   - Creates database backups
   - Pulls latest code
   - Runs migrations
   - Updates dependencies
   - Restarts containers
   - Health check verification
   - Detailed logging

3. **`DEPLOYMENT.md`** - Complete deployment documentation
   - Automatic vs manual deployment
   - Server setup instructions
   - GitHub secrets configuration
   - Nginx reverse proxy setup
   - SSL certificate setup
   - Monitoring & maintenance
   - Troubleshooting guide
   - Security best practices

4. **`setup-production.sh`** - Quick production setup script
   - Interactive server setup
   - Generates secure passwords
   - Configures environment
   - Runs initial deployment

5. **Updated `.github/copilot-instructions.md`** - Added deployment info
6. **Updated `README.md`** - Added production deployment section

---

## 🚀 How to Use It

### Option 1: Quick Setup (Recommended for first-time deployment)

On your production server, run:

```bash
git clone https://github.com/mrpc/RadioChatBox.git
cd RadioChatBox
./setup-production.sh
```

Follow the prompts to configure your server.

### Option 2: Manual Setup

Follow the detailed instructions in `DEPLOYMENT.md`.

---

## 📝 GitHub Configuration

After setting up your server, configure these **GitHub Secrets**:

| Secret | Description | Example |
|--------|-------------|---------|
| `SSH_PRIVATE_KEY` | SSH private key for server access | `-----BEGIN RSA PRIVATE KEY-----...` |
| `SERVER_HOST` | Your server IP or domain | `123.45.67.89` |
| `SERVER_USER` | SSH username on server | `ubuntu` |
| `DEPLOY_PATH` | Full path to application | `/var/www/radiochatbox` |
| `HEALTH_CHECK_URL` | Base URL for health checks | `https://radio.example.com` |
| `SLACK_WEBHOOK` | (Optional) Slack webhook URL | `https://hooks.slack.com/...` |

**How to add secrets:**
1. Go to your GitHub repository
2. Settings → Secrets and variables → Actions
3. Click "New repository secret"
4. Add each secret above

---

## 🔐 SSH Key Generation

Generate a dedicated SSH key for GitHub Actions:

```bash
# On your local machine
ssh-keygen -t rsa -b 4096 -C "github-deploy" -f ~/.ssh/github_deploy_radiochatbox

# Copy public key to your server
ssh-copy-id -i ~/.ssh/github_deploy_radiochatbox.pub user@your-server.com

# Display private key (copy to GitHub secret SSH_PRIVATE_KEY)
cat ~/.ssh/github_deploy_radiochatbox
```

---

## ✨ Deployment Workflow

Once configured, here's what happens on every push to `main`:

```
1. You push code → GitHub
         ↓
2. GitHub Actions starts
         ↓
3. Runs PHPUnit tests (with PostgreSQL + Redis)
         ↓
4. If tests pass → SSH to production server
         ↓
5. Server runs deploy.sh:
   - Backs up database
   - Pulls latest code
   - Runs migrations
   - Updates dependencies
   - Restarts containers
         ↓
6. Health check verification
         ↓
7. Notification sent (optional)
         ↓
8. ✅ Deployment complete!
```

---

## 🛡️ What the Deployment Script Does

The `deploy.sh` script automatically:

- ✅ Creates timestamped database backup
- ✅ Keeps last 7 backups (auto-cleanup old ones)
- ✅ Pulls latest code from Git
- ✅ Applies database migrations (if any)
- ✅ Updates Composer dependencies
- ✅ Rebuilds Docker containers (no cache)
- ✅ Waits for services to start
- ✅ Runs health check (30 attempts)
- ✅ Clears Redis cache
- ✅ Cleans up old Docker images
- ✅ Sets correct file permissions
- ✅ Logs everything to `deploy.log`

---

## 📊 Monitoring

After deployment, monitor your application:

```bash
# View deployment logs
tail -f /var/www/radiochatbox/deploy.log

# View container logs
docker-compose logs -f

# View specific container
docker-compose logs -f apache
docker-compose logs -f postgres
docker-compose logs -f redis

# Check container status
docker-compose ps

# Check health endpoint
curl http://localhost:98/api/health.php
```

---

## 🔄 Rollback Procedure

If something goes wrong:

```bash
# SSH to server
ssh user@your-server.com
cd /var/www/radiochatbox

# View recent commits
git log --oneline -10

# Revert to previous commit
git reset --hard <commit-hash>

# Restore database from backup
docker exec -i radiochatbox_postgres psql -U radiochatbox radiochatbox < backups/db_backup_YYYYMMDD_HHMMSS.sql

# Restart containers
docker-compose up -d --force-recreate

# Verify
curl http://localhost:98/api/health.php
```

---

## 🔒 Security Checklist

Before going live:

- [ ] Change default admin password (`admin.html`)
- [ ] Update `.env` with strong database password
- [ ] Configure `ALLOWED_ORIGINS` in `.env`
- [ ] Set up Nginx reverse proxy (see `DEPLOYMENT.md`)
- [ ] Enable HTTPS with Let's Encrypt
- [ ] Configure firewall (UFW)
- [ ] Set up automated backups (cron)
- [ ] Configure monitoring alerts
- [ ] Review and update banned nicknames
- [ ] Configure URL blacklist

---

## 📚 Documentation

- **Quick Start**: See `README.md`
- **Full Deployment Guide**: See `DEPLOYMENT.md`
- **API Documentation**: See `docs/openapi.yaml`
- **Contributing**: See `CONTRIBUTING.md`
- **AI Agent Guide**: See `.github/copilot-instructions.md`

---

## 🆘 Troubleshooting

### Tests fail in GitHub Actions
- Check test logs in Actions tab
- Verify database schema is compatible
- Ensure all migrations are committed

### Deployment fails with SSH error
- Verify SSH key is added to GitHub secrets
- Check server is accessible: `ssh user@server`
- Verify `deploy.sh` is executable

### Health check fails after deployment
- Check container logs: `docker-compose logs`
- Verify `.env` configuration
- Test manually: `curl http://localhost:98/api/health.php`
- Check ports: `sudo netstat -tlnp | grep 98`

### Database migration fails
- Check migration file syntax
- View PostgreSQL logs: `docker-compose logs postgres`
- Apply manually and mark as done

---

## 📞 Next Steps

1. **Test locally**: `./deploy.sh` on your development machine
2. **Set up production server**: Run `./setup-production.sh`
3. **Configure GitHub secrets**: Follow the table above
4. **Test deployment**: Make a small change and push to `main`
5. **Monitor**: Watch GitHub Actions tab for deployment status
6. **Configure Nginx + SSL**: Follow `DEPLOYMENT.md` guide
7. **Set up monitoring**: Uptime Robot, Pingdom, etc.
8. **Configure backups**: Daily database backups via cron

---

**Ready to deploy!** 🚀

Push to `main` branch and watch the magic happen in your GitHub Actions tab.

For issues or questions, check `DEPLOYMENT.md` or open a GitHub issue.
