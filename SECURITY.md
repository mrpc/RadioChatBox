# Security Policy# Security Considerations



## Supported Versions## Overview

This document outlines security best practices for RadioChatBox in production environments.

| Version | Supported          |

| ------- | ------------------ |## Authentication & Authorization

| 1.0.x   | :white_check_mark: |

### Current Status

## Reporting a Vulnerability- No user authentication (anonymous chat)

- IP-based rate limiting

**Please do not report security vulnerabilities through public GitHub issues.**- Basic input sanitization



Instead, please report them via email to: **security@radiochatbox.org** (or your email)### Recommended Implementations



Include:1. **User Registration (Optional)**

- Description of the vulnerability   - Add user accounts for moderation

- Steps to reproduce   - OAuth integration (Google, Facebook)

- Potential impact   - Session management

- Suggested fix (if any)

2. **Moderator Access**

We will respond within 48 hours and work with you to resolve the issue.   - Protected admin endpoints

   - JWT tokens for API authentication

## Security Best Practices   - Role-based access control



### For Production Deployment## Input Validation



1. **Change default admin password immediately**### Current Protections

   - Default: `admin` / `admin123`- XSS prevention via `htmlspecialchars()`

   - Change via admin panel → Settings- Message length limits

- Username length limits

2. **Use HTTPS/SSL**

   - Get free SSL certificate from Let's Encrypt### Additional Recommendations

   - Configure reverse proxy (nginx/Apache) with SSL- SQL injection protection (using PDO prepared statements ✓)

- Profanity filter

3. **Update database credentials**- Link/spam detection

   - Change `POSTGRES_PASSWORD` in `.env`- Image/file upload validation (if added)

   - Use strong, unique passwords

## Rate Limiting

4. **Enable firewall**

   - Only expose necessary ports (80, 443)### Current Implementation

   - Block direct access to PostgreSQL (5432) and Redis (6379)- Redis-based rate limiting

- Configurable timeout per IP

5. **Regular updates**- Default: 2 seconds between messages

   - Keep Docker images updated

   - Monitor security advisories### Production Settings

   - Apply patches promptly```env

CHAT_RATE_LIMIT_SECONDS=3  # Increase for high traffic

6. **Configure rate limiting**```

   - Adjust based on your audience size

   - Monitor for abuse patterns### Advanced Rate Limiting

Consider implementing:

7. **Regular backups**- Sliding window algorithm

   - Automate PostgreSQL backups- Different limits for registered vs. anonymous users

   - Store backups securely off-site- Progressive penalties for repeat offenders



## Known Security Considerations## Database Security



### Current Implementation### PostgreSQL Hardening



✅ **Implemented:**1. **Strong Passwords**

- XSS protection (input sanitization)   ```env

- SQL injection prevention (PDO prepared statements)   DB_PASSWORD=use-a-strong-random-password-here

- CSRF protection for admin panel   ```

- Rate limiting (IP-based)

- URL filtering in public chat2. **Limited Permissions**

- Auto-ban for violations   ```sql

   GRANT SELECT, INSERT ON messages TO radiochatbox;

⚠️ **Limitations:**   REVOKE ALL ON pg_catalog FROM radiochatbox;

- No user authentication (anonymous chat by design)   ```

- Basic rate limiting (IP-based only)

- No email verification3. **Network Isolation**

- Photos not scanned for malware   - Keep PostgreSQL on private network

   - Use Docker networks (already configured ✓)

### Recommended for Production   - Don't expose port 5432 externally



- Place behind reverse proxy (nginx)4. **Regular Backups**

- Use WAF (Web Application Firewall) like Cloudflare   ```bash

- Enable DDoS protection   docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox > backup.sql

- Monitor logs for suspicious activity   ```

- Implement backups automation

- Use strong database passwords## Redis Security



## Security Updates1. **Password Protection**

   Add to `docker-compose.yml`:

Security updates will be released as patch versions (e.g., 1.0.1, 1.0.2).   ```yaml

   redis:

Subscribe to releases on GitHub to be notified of security updates.     command: redis-server --requirepass your_redis_password

   ```

## Credits

2. **Disable Dangerous Commands**

We thank the security researchers who responsibly disclose vulnerabilities to us.   ```yaml

   command: redis-server --rename-command FLUSHALL "" --rename-command FLUSHDB ""
   ```

3. **Network Isolation**
   - Don't expose Redis port externally
   - Use Docker networks only

## SSL/TLS

### Enable HTTPS

1. **Get SSL Certificate**
   - Use Let's Encrypt (free)
   - Or purchase from CA

2. **Update Apache Config**
   Add to `apache/site.conf`:
   ```apache
   <VirtualHost *:443>
       ServerName yourradiosite.com
       SSLEngine on
       SSLCertificateFile /path/to/fullchain.pem
       SSLCertificateKeyFile /path/to/privkey.pem
       SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
       SSLCipherSuite HIGH:!aNULL:!MD5
       # ... rest of configuration
   </VirtualHost>
   ```

3. **Force HTTPS Redirect**
   ```apache
   <VirtualHost *:80>
       ServerName yourradiosite.com
       Redirect permanent / https://yourradiosite.com/
   </VirtualHost>
   ```

## CORS Configuration

### Secure CORS Setup
```env
# Only allow your domains
ALLOWED_ORIGINS=https://yourradiosite.com,https://www.yourradiosite.com
```

### Apache CORS Headers
Already configured in `apache/site.conf` ✓

## DDoS Protection

### Application Level
1. **Apache Rate Limiting**
   Enable mod_ratelimit and mod_evasive:
   ```apache
   <IfModule mod_ratelimit.c>
       SetOutputFilter RATE_LIMIT
       SetEnv rate-limit 400
   </IfModule>
   
   <IfModule mod_evasive20.c>
       DOSHashTableSize 3097
       DOSPageCount 10
       DOSSiteCount 100
       DOSPageInterval 1
       DOSSiteInterval 1
       DOSBlockingPeriod 10
   </IfModule>
   ```

2. **Connection Limits**
   Add to `apache/site.conf`:
   ```apache
   MaxConnectionsPerChild 1000
   MaxRequestWorkers 150
   ```

### Infrastructure Level
- Use Cloudflare or similar CDN
- Enable DDoS protection
- Implement WAF rules

## Logging & Monitoring

### What to Log
- Failed authentication attempts
- Rate limit violations
- Banned IP attempts
- Error messages

### Log Rotation
```yaml
logging:
  driver: "json-file"
  options:
    max-size: "10m"
    max-file: "3"
```

### Monitoring Tools
- Prometheus + Grafana
- ELK Stack (Elasticsearch, Logstash, Kibana)
- Sentry for error tracking

## Content Moderation

### Recommended Features
1. **Profanity Filter**
   - Block offensive words
   - Auto-replace with asterisks

2. **Spam Detection**
   - Detect repeated messages
   - Block URL spam
   - CAPTCHA for suspicious activity

3. **User Banning**
   - IP-based bans (implemented ✓)
   - Username bans
   - Temporary vs. permanent bans

4. **Message Deletion**
   - Soft delete in database (implemented ✓)
   - Remove from Redis cache
   - Moderator tools

## File Permissions

### Docker Volumes
```bash
# Set appropriate ownership
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod -R 644 /var/www/html/public
```

## Environment Variables

### Never Commit Secrets
- Keep `.env` in `.gitignore` ✓
- Use environment-specific configs
- Rotate credentials regularly

### Secrets Management
For production:
- Docker Secrets
- HashiCorp Vault
- AWS Secrets Manager
- Azure Key Vault

## Regular Updates

### Maintenance Checklist
- [ ] Update PHP to latest version
- [ ] Update Redis to latest version
- [ ] Update PostgreSQL to latest version
- [ ] Update Apache to latest version
- [ ] Review and update dependencies
- [ ] Apply security patches
- [ ] Review access logs
- [ ] Test backup restoration

## Security Headers

### Already Implemented ✓
```apache
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
```

### Additional Headers
Add to `apache/site.conf`:
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
Header always set Content-Security-Policy "default-src 'self'"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
```

## Incident Response

### If Compromised
1. **Immediate Actions**
   - Take affected services offline
   - Change all passwords
   - Review access logs
   - Notify users if data exposed

2. **Investigation**
   - Identify breach source
   - Check for backdoors
   - Review all logs
   - Document findings

3. **Recovery**
   - Patch vulnerabilities
   - Restore from clean backup
   - Implement additional security
   - Monitor for suspicious activity

## Compliance

### GDPR Considerations
- Log user consent for data collection
- Provide data export functionality
- Implement data deletion on request
- Privacy policy and terms of service

### Chat Logging
- Inform users that chat is logged
- Implement data retention policies
- Allow users to request their data

## Security Checklist

- [ ] Change all default passwords
- [ ] Enable HTTPS/SSL
- [ ] Configure CORS properly
- [ ] Implement rate limiting
- [ ] Set up logging and monitoring
- [ ] Regular backups automated
- [ ] Security headers configured
- [ ] Database access restricted
- [ ] Redis password protected
- [ ] Firewall rules in place
- [ ] DDoS protection enabled
- [ ] Regular security updates
- [ ] Incident response plan
- [ ] Privacy policy published
- [ ] Content moderation tools

---

**Remember**: Security is an ongoing process, not a one-time setup. Review and update regularly.
