# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- Test tooling: `dockertest`, `dockerbash`, `radiochatbox` and `dockertest.bat`
  run the suite and project commands inside the container, where `pdo_pgsql`,
  `redis` and `gd` exist (on the host most of the suite errors out)
- Upgraded PHPUnit 10.5 → 12.5 (also clears CVE-2026-24765) and migrated
  `phpunit.xml` to the `<source>` element; `composer.json` now requires PHP
  ^8.3, matching the Docker image and the documented deployment requirement
- `CorsHandler`: the origin decision and preflight check moved into
  `resolveHeaders()` / `isPreflight()`, so they can be tested without observing
  `header()` or hitting `exit`. Same behaviour, plus `REQUEST_METHOD` is no
  longer read without a null coalesce (it warned under CLI)
- Removed two unconditional debug `error_log()` calls from `StatsService`'s
  today-stats path, which logged on every stats request

### Fixed
- The four `CorsHandlerTest` tests were unimplemented placeholders; they now
  cover wildcard, explicit allow list, exact-match rejection and preflight
- Tests no longer report as risky under PHPUnit 12 (which attributes
  `error_log()` output to the test): `UserServiceTest`'s Redis mocks now cover
  the full cache-invalidation set the code busts, and tests that deliberately
  trigger a logged rejection assert the log instead of leaking it

### Added
- Automatic LLM replies for fake users in private messages (DeepSeek)
  - Per-fake-user bot with a prompt built from its own profile (name, age, sex,
    location) plus optional personality text or a full custom system prompt
  - Conversation budget per `(fake user, real user)` thread (default 4 messages);
    the bot's last message is an LLM-written closing line that fits the
    conversation, with 50+ built-in Greek/greeklish fallback variants used only
    if that request fails
  - Realistic pacing: random reading delay before replying, then a typing delay
    of ~1.5s per word — the message is inserted and published only after it
  - Impersonating a fake user stops its bot in that conversation, including
    replies already queued or generated; "Back to bot" hands it back
  - Redis-backed delayed job queue (`src/JobQueue.php`) plus a `bot-worker.php`
    CLI worker (`run` / `once` / `status` / `flush`, systemd or cron)
  - Single-instance lock with heartbeat for the worker (`src/WorkerLock.php`),
    implemented in plain PHP via atomic file creation — no `flock` (which is
    silently ineffective on Docker bind mounts) and no `flock(1)` wrapper in the
    crontab. `status` reports pid, uptime, heartbeat age and jobs handled, tells
    a healthy worker from one that is alive but wedged (exit code 2), and a lock
    left by a crashed or wedged worker is taken over automatically; optional
    systemd `sd_notify`/watchdog integration
- Edit button for fake users in the admin panel (nickname, age, sex, country).
  Renaming rewrites the nickname across existing private messages and DM blocks
  in one transaction, so ongoing conversations are not orphaned, and rejects
  nicknames already used by another fake user or a real account
  - Admin panel: global settings section (API key, endpoint, model, limits,
    delays) and a per-fake-user bot dialog; all `bot_*` settings are stripped
    from the public settings payload
  - New tables/columns via migration `023_add_bot_replies.sql` (`bot_threads`,
    `fake_users.bot_*`); documented in `docs/BOT_REPLIES.md`
- Link preview cards for URLs in chat messages
  - Fetches Open Graph / Twitter Card metadata (title, description, image, domain)
  - Preview card rendered below the message bubble, styled like Facebook/WhatsApp
  - Client-side in-memory cache to avoid duplicate requests
  - Server-side Redis cache (1 hour TTL) via new `/api/link-preview.php` endpoint
  - SSRF protection: private and reserved IP ranges are blocked
  - Graceful fallback: no card shown when the URL returns no usable metadata
  - Theme-aware styles for dark, light, and metal themes
- Message editing for public chat (own messages within 10 minutes)
  - Edit button (✏️) appears on hover for own messages within the 10-minute window
  - Inline editing directly inside the message bubble (no modal)
  - Keyboard shortcuts: Ctrl+Enter to save, Escape to cancel
  - Server-side ownership and timing validation via `/api/edit-message.php`
  - Same `MessageFilter` pipeline applied to edited text
  - Real-time SSE event (`message_edited`) updates all connected clients instantly
  - `(edited)` badge shown next to timestamp for all users
  - `edited_at` column added to `messages` table (migration `016_add_edited_at_to_messages.sql`)
  - History loaded from database correctly reflects edited content and badge
- Comprehensive statistics system with multi-level time granularity
  - Real-time snapshots of concurrent users, radio listeners, and active sessions
  - Hourly, daily, weekly, monthly, and yearly aggregated statistics
  - Tracks: active users, guest users, registered users, messages, private messages, photo uploads, new registrations, radio listeners, peak concurrent users
  - PostgreSQL-based aggregation functions for efficient data processing
  - Redis caching for fast statistics retrieval
  - Admin statistics dashboard with interactive charts (Chart.js)
  - Multiple visualization types: line charts, bar charts, doughnut charts
  - Tabbed interface for different time granularities
  - API endpoints for stats recording, aggregation, and retrieval
  - Automated data collection via cron script (stats-cron.sh)
  - Snapshot retention (30 days) with automatic cleanup
  - Complete documentation in docs/STATISTICS.md
  - Database migration: 006_add_statistics_tables.sql
- Message reply feature for public chat
  - Reply button on all messages to quote and reference previous messages
  - Visual quote display showing original username and truncated message
  - Reply preview above input field when composing a reply
  - Database support for message threading with reply_to column
  - Styled reply quotes with theme-specific colors (dark, light, metal)
  - Mobile-responsive reply UI
  - Reply data included in message history from database
- Emoji support for older Windows versions (Windows 7, 8, early Windows 10)
  - Integrated Twemoji library for consistent emoji rendering across all platforms
  - Automatic conversion of Unicode emojis to SVG images
  - Applied to all UI elements: messages, emoji picker, conversation previews, buttons
  - Comprehensive CSS styling for inline emoji display
  - Documentation in docs/EMOJI_SUPPORT.md and docs/EMOJI_TESTING.md
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
