# Security Policy

## Supported Versions

We release patches for security vulnerabilities. Which versions are eligible for receiving such patches depends on the CVSS v3.0 Rating:

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please report them via email to: **security@radiochatbox.org** (or open a private security advisory on GitHub)

Include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)
- Your contact information

We will respond within 48 hours and work with you to resolve the issue.

## Security Disclosure Process

1. Security report received and assigned to a handler
2. Issue confirmed and affected versions determined
3. Fix prepared and tested
4. Release published with security advisory
5. Public disclosure after users have had time to update

## Security Best Practices

For detailed security implementation guidelines, see [SECURITY_GUIDE.md](SECURITY_GUIDE.md)

### Quick Checklist

**Essential (Before Going Live):**
- [ ] Change default admin password (`admin`/`admin123`)
- [ ] Enable HTTPS/SSL
- [ ] Use strong database passwords
- [ ] Configure CORS for your domain only
- [ ] Enable firewall (ports 80, 443 only)

**Recommended:**
- [ ] Review rate limiting settings
- [ ] Set up automated backups
- [ ] Configure Redis password protection
- [ ] Implement logging and monitoring
- [ ] Review [SECURITY_GUIDE.md](SECURITY_GUIDE.md) for complete hardening

## Known Security Considerations

### Current Implementation

✅ **Implemented:**
- XSS protection (input sanitization)
- SQL injection prevention (PDO prepared statements)
- Rate limiting (IP-based, configurable)
- URL filtering in public chat
- Auto-ban for repeated violations
- CSRF protection for admin panel

⚠️ **Limitations:**
- No user authentication (anonymous chat by design)
- IP-based rate limiting only
- Photos not scanned for malware
- Basic spam detection

### Recommended for Production

- Place behind reverse proxy (nginx/Apache)
- Use WAF (Web Application Firewall)
- Enable DDoS protection (Cloudflare, etc.)
- Implement comprehensive logging
- Set up automated backups
- Monitor for suspicious activity

## Security Updates

Security updates will be released as patch versions (e.g., 1.0.1, 1.0.2).

Subscribe to releases on GitHub to be notified of security updates.

## Credits

We thank the security researchers who responsibly disclose vulnerabilities to us.
