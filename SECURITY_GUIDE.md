# Security Guidelines for RadioChatBox

## 🔒 Security Best Practices

This document outlines security considerations for deploying and maintaining RadioChatBox.

---

## Secrets Management

### ✅ What's Safe in Git

These files are **safe to commit** and are already in the repository:

- ✅ `.env.example` - Template with placeholder values
- ✅ `deploy.sh` - Uses environment variables, no hardcoded secrets
- ✅ `.github/workflows/deploy.yml` - References GitHub Secrets
- ✅ `docker-compose.yml` - Uses `.env` file for actual values
- ✅ All documentation files

### ❌ What Should NEVER be in Git

These files are **already in `.gitignore`** and should never be committed:

- ❌ `.env` - Contains production credentials
- ❌ `backups/*.sql` - Database backups with user data
- ❌ `deploy.log` - May contain sensitive deployment information
- ❌ SSH private keys
- ❌ Any files containing passwords, API keys, or tokens

---

## GitHub Secrets Configuration

All sensitive data is stored in **GitHub Secrets** (not in the repository):

| Secret | Purpose | How to Generate |
|--------|---------|-----------------|
| `SSH_PRIVATE_KEY` | Server access for deployment | `ssh-keygen -t rsa -b 4096` |
| `SERVER_HOST` | Production server IP/domain | Your server address |
| `SERVER_USER` | SSH username | Your server user |
| `DEPLOY_PATH` | Application path | `/var/www/radiochatbox` |
| `HEALTH_CHECK_URL` | Health check endpoint | `https://yourdomain.com` |
| `SLACK_WEBHOOK` | Deployment notifications | Slack webhook URL |

### How to Add Secrets

1. Go to your GitHub repository
2. Navigate to: **Settings → Secrets and variables → Actions**
3. Click **"New repository secret"**
4. Add each secret individually

**Important**: Secrets are encrypted and never exposed in logs or repository.

---

## Production Environment Security

### 1. Database Security

```bash
# Generate a strong database password (32 characters)
openssl rand -base64 32

# Update .env file on production server
DB_PASSWORD=<generated-password>
```

**Never use default passwords in production!**

### 2. Admin Password

```bash
# Change default admin password immediately after deployment
# Visit: https://yourdomain.com/admin.html
# Default: admin/admin123 ⚠️ CHANGE THIS!
```

### 3. CORS Configuration

```bash
# In .env file, specify allowed origins explicitly
ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com

# Never use '*' in production
```

### 4. File Permissions

```bash
# Correct permissions on production server
chmod 755 /var/www/radiochatbox
chmod 644 /var/www/radiochatbox/.env
chmod 755 /var/www/radiochatbox/deploy.sh
chown -R www-data:www-data /var/www/radiochatbox/public/uploads
```

### 5. SSH Key Security

```bash
# Generate deployment key with strong passphrase (optional but recommended)
ssh-keygen -t ed25519 -C "radiochatbox-deploy"

# Use SSH agent forwarding (more secure than storing keys on server)
# Or use separate deployment keys with limited permissions

# Restrict key to specific commands (optional advanced security)
# In ~/.ssh/authorized_keys on server:
# command="/var/www/radiochatbox/deploy.sh" ssh-rsa AAAA...
```

---

## Server Hardening

### Firewall Configuration

```bash
# Allow only necessary ports
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp   # SSH
sudo ufw allow 80/tcp   # HTTP
sudo ufw allow 443/tcp  # HTTPS
sudo ufw enable
```

### SSH Hardening

Edit `/etc/ssh/sshd_config`:

```bash
# Disable root login
PermitRootLogin no

# Disable password authentication (use keys only)
PasswordAuthentication no
PubkeyAuthentication yes

# Change default SSH port (optional)
Port 2222

# Restart SSH
sudo systemctl restart sshd
```

### Fail2Ban for SSH Protection

```bash
# Install fail2ban
sudo apt install fail2ban

# Configure for SSH
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local

# Edit /etc/fail2ban/jail.local
[sshd]
enabled = true
maxretry = 3
bantime = 3600

# Restart fail2ban
sudo systemctl restart fail2ban
```

---

## Application Security

### 1. Rate Limiting

Already configured in the database settings table:
- Default: 10 messages per 60 seconds per IP
- Automatic ban after 3 violations

### 2. XSS Protection

Implemented through:
- `MessageFilter::filterPublicMessage()` - Removes URLs, scripts, dangerous HTML
- `MessageFilter::filterPrivateMessage()` - Blocks blacklisted URLs
- Double-layer filtering (filter + htmlspecialchars on output)

### 3. SQL Injection Prevention

All database queries use **prepared statements**:

```php
// Example from ChatService.php
$stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username');
$stmt->execute(['username' => $username]);
```

### 4. CSRF Protection

For future enhancement (not currently implemented):
- Consider adding CSRF tokens for admin panel

### 5. Session Management

**Client-side storage** (stateless PHP backend):
- Primary: **localStorage** (works in iframes, not affected by third-party cookie blocking)
- Backup: **Partitioned Cookies (CHIPS)** for Chrome 114+, Edge 114+ with `SameSite=None; Secure; Partitioned`
- Fallback: Traditional cookies with `SameSite=None; Secure` for HTTPS or `SameSite=Lax` for HTTP
- PHP backend does not use cookies or sessions (HTTP Basic Auth for admin only)

---

## Data Protection

### Database Backups

```bash
# Automatic backups on each deployment (last 7 kept)
# Manual backup
docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox > backup.sql

# Encrypt backups for off-site storage
gpg --symmetric --cipher-algo AES256 backup.sql
```

### User Data Privacy

- IP addresses are stored for moderation (can be anonymized if needed)
- Private messages are stored in database (consider encryption for GDPR)
- Photos auto-expire after 48 hours
- No tracking or analytics by default

### GDPR Compliance (If Applicable)

Considerations for EU users:
- Add privacy policy
- Implement data export/deletion functionality
- Cookie consent for tracking (if you add analytics)
- Data retention policies

---

## Monitoring & Alerting

### Security Monitoring

```bash
# Monitor failed login attempts
sudo tail -f /var/log/auth.log | grep "Failed password"

# Monitor application errors
docker-compose logs -f | grep -i "error\|warning\|fatal"

# Monitor unusual activity
docker exec radiochatbox_postgres psql -U radiochatbox -d radiochatbox -c \
  "SELECT ip_address, COUNT(*) as attempts FROM messages WHERE created_at > NOW() - INTERVAL '1 hour' GROUP BY ip_address HAVING COUNT(*) > 100;"
```

### Automated Alerts

Set up alerts for:
- Failed deployments
- Health check failures
- High error rates
- Unusual traffic patterns
- Database connection failures

---

## SSL/TLS Configuration

### Let's Encrypt Setup

```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx

# Obtain certificate
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com

# Auto-renewal is configured automatically
# Test renewal
sudo certbot renew --dry-run
```

### SSL Configuration (Nginx)

```nginx
# Strong SSL configuration
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384';
ssl_prefer_server_ciphers off;

# HSTS (force HTTPS)
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

# Other security headers
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
```

---

## Incident Response

### If Compromised

1. **Immediately**:
   - Revoke GitHub SSH keys
   - Change all passwords (database, admin panel)
   - Review access logs
   - Check for unauthorized changes

2. **Investigation**:
   ```bash
   # Check recent commands
   history
   
   # Check unauthorized files
   find /var/www/radiochatbox -type f -mtime -1
   
   # Check database for suspicious data
   docker exec radiochatbox_postgres psql -U radiochatbox -d radiochatbox -c \
     "SELECT * FROM messages WHERE created_at > NOW() - INTERVAL '1 day' ORDER BY created_at DESC LIMIT 100;"
   ```

3. **Recovery**:
   - Restore from last known good backup
   - Patch vulnerabilities
   - Reset all credentials
   - Update dependencies

### Emergency Contacts

Document these and keep secure:
- Server provider support
- Domain registrar support
- Database administrator
- Security team contact

---

## Security Checklist

Before going live:

- [ ] Changed default admin password
- [ ] Generated strong database password
- [ ] Configured CORS with specific origins (no '*')
- [ ] Enabled HTTPS with valid SSL certificate
- [ ] Configured firewall (UFW)
- [ ] Hardened SSH (key-only, no root)
- [ ] Set up fail2ban
- [ ] Reviewed and updated URL blacklist
- [ ] Reviewed banned nicknames
- [ ] Configured automated backups
- [ ] Set up monitoring/alerting
- [ ] Tested backup restoration
- [ ] Reviewed application logs
- [ ] Added security headers (Nginx)
- [ ] Documented incident response plan

---

## Regular Security Maintenance

### Weekly
- [ ] Review deployment logs
- [ ] Check for failed login attempts
- [ ] Monitor disk space
- [ ] Review application errors

### Monthly
- [ ] Update system packages: `sudo apt update && sudo apt upgrade`
- [ ] Review banned IPs and nicknames
- [ ] Check SSL certificate expiration
- [ ] Review user activity for anomalies

### Quarterly
- [ ] Update PHP/PostgreSQL/Redis versions
- [ ] Review and update dependencies: `composer update`
- [ ] Security audit of custom code
- [ ] Test backup restoration procedure
- [ ] Review and update security policies

---

## Reporting Security Issues

If you discover a security vulnerability:

1. **DO NOT** open a public GitHub issue
2. Email security concerns to: [your-email@example.com]
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if any)

We aim to respond within 48 hours and patch critical issues within 7 days.

---

## Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [Docker Security Best Practices](https://docs.docker.com/engine/security/)
- [PostgreSQL Security](https://www.postgresql.org/docs/current/security.html)
- [Redis Security](https://redis.io/docs/management/security/)

---

**Remember**: Security is an ongoing process, not a one-time setup. Stay vigilant!

**Last Updated**: 2025-11-11
