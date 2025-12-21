# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Admin user management system with role-based access control (RBAC)
  - Four user roles: Root, Administrator, Moderator, Simple User
  - Username/password authentication replacing legacy password-only system
  - CRUD operations for admin users via UI and API
  - Granular permission system (8 permissions mapped to roles)
  - 24-hour Redis session caching
  - User list caching with 5-minute TTL
- localStorage-first session management for iframe embedding
  - Works even when third-party cookies are blocked
  - Partitioned Cookies (CHIPS) support for Chrome 114+, Edge 114+
  - Automatic fallback to traditional cookies for older browsers
  - Stateless PHP backend with client-side session management
- Unit test suite for UserService (23 tests)
- Test infrastructure with Database mocking support

### Changed
- Admin authentication now requires username:password format (Bearer header)
- User list API responses cached for 5 minutes

### Removed
- Legacy admin password-only authentication
- Admin password field from Settings UI

## [1.0.0] - 2025-11-11

Initial release of RadioChatBox - a modern, real-time chat application with comprehensive moderation and customization features.

### Features

- Real-time messaging using Server-Sent Events (SSE)
- Public and private chat modes
- Photo sharing in private conversations (auto-expires after 48 hours)
- User profiles with age, sex, and location
- Emoji picker with categorized emojis
- Responsive mobile-friendly design
- Embeddable widget for external websites
- Comprehensive admin panel with moderation tools
- IP and nickname banning system
- URL blacklist filtering
- Rate limiting and spam prevention
- SEO customization (meta tags, Open Graph, branding)
- Analytics integration (GA4, GTM, Matomo, custom)
- Advertisement system with auto-refresh
- Custom script injection for third-party integrations
- Docker deployment with compose configuration

### Technical Stack

- PHP 8.1+ with PSR-4 autoloading
- PostgreSQL 14+ for data persistence
- Redis 6.0+ for caching and message distribution
- Vanilla JavaScript frontend (no frameworks)
- Apache web server
- PHPUnit testing infrastructure

#### Security
- XSS protection
- SQL injection prevention
- CSRF protection for admin
- Rate limiting (IP-based)
- URL filtering in public chat
- Auto-ban system for violations

#### Performance
- Redis caching throughout
- Optimized database queries
- Indexed tables for large datasets
- Photo auto-cleanup
- Inactive user cleanup

#### Developer Tools
- PHPUnit testing framework
- OpenAPI 3.0 documentation
- Example embed code
- Comprehensive README

### Technical Stack
- PHP 8.1+
- PostgreSQL 14+
- Redis 6.0+
- Apache 2.4
- Docker & Docker Compose

[1.0.0]: https://github.com/mrpc/RadioChatBox/releases/tag/v1.0.0
