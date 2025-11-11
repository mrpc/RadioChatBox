# Git Webhook Auto-Deployment Guide

This guide explains how to set up automatic deployment using Git webhooks instead of GitHub Actions. This method is **simpler**, has **no dependencies on GitHub Actions**, and works with **GitHub, GitLab, and Gitea**.

---

## Why Webhooks Instead of GitHub Actions?

✅ **Simpler** - No need to manage SSH keys in GitHub Secrets  
✅ **Faster** - Direct server communication, no CI/CD overhead  
✅ **Platform-agnostic** - Works with GitHub, GitLab, Gitea, Bitbucket  
✅ **No quotas** - GitHub Actions has usage limits; webhooks don't  
✅ **Instant** - Deploys immediately on push without queuing  

---

## Setup Instructions

### Step 1: Configure Your Server

The `webhook.php` file is already in your project. During setup, a webhook secret was auto-generated in your `.env` file.

**Check your webhook secret:**
```bash
cd /home/livechats/domains/app.livechats.gr/public_html
grep WEBHOOK_SECRET .env
```

You'll see something like:
```
WEBHOOK_SECRET=a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6
```

**Copy this secret** - you'll need it for the webhook configuration.

### Step 2: Make Webhook Accessible

The webhook handler needs to be accessible via HTTP(S). Add a symlink or make sure it's in your public directory:

**Option A: Already in public_html** (recommended during setup)
```bash
# No action needed - webhook.php is at project root
```

**Option B: Create symlink to public directory**
```bash
cd /home/livechats/domains/app.livechats.gr/public_html/public
ln -s ../webhook.php webhook.php
```

**Test accessibility:**
```bash
curl -X POST https://app.livechats.gr/webhook.php
# Should return: {"error":"Empty payload"}
```

### Step 3: Configure GitHub Webhook

1. Go to your GitHub repository: `https://github.com/mrpc/RadioChatBox`

2. Navigate to: **Settings** → **Webhooks** → **Add webhook**

3. Fill in the form:
   - **Payload URL**: `https://app.livechats.gr/webhook.php`
   - **Content type**: `application/json`
   - **Secret**: Paste your `WEBHOOK_SECRET` from `.env`
   - **Which events?**: Select "Just the push event"
   - **Active**: ✅ Checked

4. Click **Add webhook**

5. GitHub will send a test ping. Check **Recent Deliveries** to verify it succeeded (green checkmark).

### Step 4: Test Deployment

Make a small change and push:

```bash
echo "# Test webhook deployment" >> README.md
git add README.md
git commit -m "Test webhook auto-deployment"
git push origin main
```

**Monitor deployment:**
```bash
# Watch webhook log
tail -f /home/livechats/domains/app.livechats.gr/public_html/webhook.log

# Watch deployment log
tail -f /home/livechats/domains/app.livechats.gr/public_html/deploy.log
```

You should see:
1. Webhook received
2. Branch verified (main)
3. Deployment triggered
4. Deploy script running

---

## Configuration for Other Git Platforms

### GitLab

1. Go to: **Settings** → **Webhooks**
2. **URL**: `https://app.livechats.gr/webhook.php`
3. **Secret token**: Your `WEBHOOK_SECRET`
4. **Trigger**: Check "Push events"
5. **SSL verification**: Enable (if you have HTTPS)

### Gitea

1. Go to: **Settings** → **Webhooks** → **Add Webhook** → **Gitea**
2. **Target URL**: `https://app.livechats.gr/webhook.php`
3. **Secret**: Your `WEBHOOK_SECRET`
4. **Trigger On**: Push events
5. **Active**: ✅

### Bitbucket

1. Go to: **Repository settings** → **Webhooks** → **Add webhook**
2. **URL**: `https://app.livechats.gr/webhook.php`
3. **Triggers**: Repository push
4. **Status**: Active

**Note**: Bitbucket doesn't support secrets in the same way. You may need to disable secret verification by removing `WEBHOOK_SECRET` from `.env` (not recommended) or implement IP whitelist.

---

## Security Best Practices

### 1. Always Use HTTPS
```bash
# Get free SSL certificate
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d app.livechats.gr
```

### 2. Strong Webhook Secret
The setup script auto-generates a 64-character random secret. Never commit this to Git.

### 3. IP Whitelist (Optional)

For extra security, restrict webhook access to Git platform IPs:

**Apache configuration** (add to your VirtualHost):
```apache
<Location /webhook.php>
    # GitHub webhook IPs
    Require ip 192.30.252.0/22
    Require ip 185.199.108.0/22
    Require ip 140.82.112.0/20
    Require ip 143.55.64.0/20
    # Add more as needed
</Location>
```

Reload Apache:
```bash
sudo systemctl reload apache2
```

### 4. File Permissions
```bash
chmod 755 webhook.php
chmod 755 deploy.sh
chmod 600 .env  # Secret file
```

### 5. Monitor Logs
```bash
# Set up log rotation
sudo tee /etc/logrotate.d/radiochatbox <<EOF
/home/livechats/domains/app.livechats.gr/public_html/*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
}
EOF
```

---

## Troubleshooting

### Webhook Returns 403 "Invalid signature"

**Check secret matches:**
```bash
# On server
grep WEBHOOK_SECRET .env

# Compare with GitHub webhook secret
```

### Webhook Returns 500 "Deploy script not found"

**Verify deploy.sh exists:**
```bash
ls -la /home/livechats/domains/app.livechats.gr/public_html/deploy.sh
chmod +x deploy.sh
```

### Deployment Not Triggering

**Check webhook.log:**
```bash
tail -f webhook.log
```

**Check Apache error logs:**
```bash
sudo tail -f /var/log/apache2/error.log
```

**Verify PHP execution:**
```bash
php webhook.php
# Should show error about REQUEST_METHOD
```

### GitHub Shows Red X on Webhook

Click the webhook → **Recent Deliveries** → Click the failed delivery to see:
- Request headers
- Payload
- Response

Common issues:
- Wrong URL (404)
- SSL certificate invalid
- Secret mismatch (403)
- Webhook.php not executable

### Deployment Runs But Fails

**Check deploy.log:**
```bash
tail -50 deploy.log
```

Common issues:
- Git authentication (set up SSH key or HTTPS token)
- File permissions
- Composer dependencies
- Database connection

---

## Advanced Configuration

### Custom Deploy Branch

Edit `.env` to deploy from a different branch:
```bash
DEPLOY_BRANCH=production
```

### Multiple Webhooks

You can configure webhooks for different branches:

1. Create `webhook-staging.php` (copy of webhook.php)
2. Set `DEPLOY_BRANCH=staging` in that file
3. Add separate webhook in GitHub pointing to `webhook-staging.php`

### Webhook Notifications

Add Slack/Discord notifications to `deploy.sh`:

```bash
# In deploy.sh, add at the end:
curl -X POST -H 'Content-type: application/json' \
  --data '{"text":"RadioChatBox deployed successfully!"}' \
  $SLACK_WEBHOOK_URL
```

### Prevent Concurrent Deployments

Add a lock file check to `deploy.sh`:

```bash
# At the start of deploy.sh
LOCK_FILE="/tmp/radiochatbox_deploy.lock"

if [ -f "$LOCK_FILE" ]; then
    echo "Deployment already in progress"
    exit 1
fi

touch "$LOCK_FILE"
trap "rm -f $LOCK_FILE" EXIT
```

---

## Comparison: Webhooks vs GitHub Actions

| Feature | Webhooks | GitHub Actions |
|---------|----------|----------------|
| Setup complexity | ⭐ Simple | ⭐⭐⭐ Complex |
| Platform support | ✅ All Git hosts | ❌ GitHub only |
| Speed | ⚡ Instant | 🐌 Queue + build time |
| Testing | ❌ Must test on server | ✅ Runs tests before deploy |
| Secrets management | Server .env | GitHub Secrets |
| Usage limits | ✅ Unlimited | ❌ 2000 min/month (free) |
| Rollback | Manual | Can automate |
| Best for | Small teams, simple deploys | Large teams, complex CI/CD |

**Recommendation**: Use **webhooks** for RadioChatBox unless you need:
- Automated testing before deployment
- Multi-stage deployments
- Matrix testing across PHP versions

---

## Migration from GitHub Actions

If you previously set up GitHub Actions deployment:

1. **Disable GitHub Actions workflow:**
   ```bash
   # Rename the workflow file
   mv .github/workflows/deploy.yml .github/workflows/deploy.yml.disabled
   ```

2. **Remove GitHub Secrets** (optional):
   - Go to repository Settings → Secrets → Actions
   - Delete: SSH_PRIVATE_KEY, SERVER_HOST, SERVER_USER, etc.

3. **Set up webhook** (follow instructions above)

4. **Test webhook deployment**

5. **Keep Actions for testing only** (optional):
   - Re-enable workflow
   - Remove deployment step, keep only testing

---

## Support

**Webhook not working?**

1. Check logs: `tail -f webhook.log`
2. Test manually: `curl -X POST https://app.livechats.gr/webhook.php`
3. Verify secret: `grep WEBHOOK_SECRET .env`
4. Check GitHub Recent Deliveries for error details

**GitHub Issues**: https://github.com/mrpc/RadioChatBox/issues

---

**Last Updated**: 2025-11-11
