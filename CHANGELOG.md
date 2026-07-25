# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- Bot prompts now start with a conversation-context block: the chat's setting
  plus a glossary of the openers that were being read literally (notably that
  "είσαι ελεύθερη;" asks about relationship status, not about having time to
  chat) and how to turn down photo/camera/phone requests without admitting it
  cannot. The settings field is prefilled with that text so it is visible and
  editable, with a reset link
- Bot model is picked from a dropdown fed by `BotService::availableModels()`
  instead of being typed by hand
- Settings tab folds into collapsible sections (remembered per section), each
  with its own save button, and a feature's options collapse when its master
  switch is off. Related fields are grouped (Connection, Generation,
  Conversation, Timing, Prompts) and laid out in a grid instead of one long
  column
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
- Bot replies arrived cut off mid-word and incoherent. The `deepseek-v4-*` models
  reason internally, so the 300-token budget was spent entirely on reasoning
  (`finish_reason: length`, empty or half-written content) and the fragment was
  delivered as if it were a complete reply. Reasoning is now off by default
  (~175 tokens saved per reply), the budget defaults to 1000, and a truncated
  completion is rejected instead of sent
- Every LLM call is logged (`bot_llm_log`): request, reply, finish reason, token
  usage including reasoning tokens, duration and errors — inspectable with
  `bot-worker.php log` and summarised on the dashboard
- Bot replies used the wrong grammatical gender for the persona; the prompt now
  pins it, and the default temperature drops from 1.3 to 1.0, which stops the
  garbled Greek
- The prompt now says who the bot is talking to (display name, age, sex,
  location) and how long the thread has been idle, so it stops asking what the
  profile already says and stops treating a days-old thread as continuous
- Context caching was being invalidated on every reply: the staleness note sat
  in the system prompt, whose prefix must stay byte-identical. Moved to a
  trailing message — measured cache hits went from 0 to 640 of 771 tokens
- Bot replies all failed with `HTTP 400 ... you passed deepseek-chat`: the
  shipped default was a model name the API no longer accepts. Corrected to
  `deepseek-v4-flash` for new installs and existing ones (migration `024`), and
  the valid names now live in `BotService::MODELS`
- Bot settings could not be saved from the admin panel: the endpoint's whitelist
  never got the `bot_*` keys, so they were dropped while it still answered
  "Settings updated successfully". Unknown keys are now reported back
- The bot no longer replies when it already spoke last in a thread, which could
  double-message the recipient
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
- Multiple LLM providers: DeepSeek and OpenAI, each with a full parameter set of
  its own (key, base URL, model, temperature, token budget, reasoning where it
  exists) so both stay configured at once and neither affects the other, plus
  per-provider request shapes (token parameter name, reasoning switch, balance
  endpoint). A fake user can override the provider and model, so different bots run
  on different LLMs at the same time
- Bots block users who abuse them repeatedly (`bot_insult_block_threshold`,
  default 3) via the same DM block mechanism as a real member, after one short
  brush-off; friendly banter is deliberately not counted, and the strike count and
  block are shown in Bot Activity
- Bots can ignore a conversation from the start (`bot_ignore_chance`, default 30%,
  per-bot overridable): no reply, no LLM call. Decided once on the first message and
  stored, so silence stays consistent — a bot never goes quiet mid-conversation —
  and shown as such in Bot Activity
- The bots never reveal they are software: a guardrail appended to every prompt
  (so a custom prompt or a replaced context cannot drop it), covering prompts,
  technical refusals and assistant manners, plus a check that discards a revealing
  reply, deflects in character and records the incident in Bot Activity
- Bots know when they receive a photo (the image is never sent to the LLM) and
  react to it instead of answering the caption as if nothing were attached
- Per-bot reply language (auto / greek / greeklish / english); greeklish is
  instructed last in the prompt and enforced by transliterating the reply
- The worker can run the periodic jobs itself instead of crontab (`run --schedule`):
  stats snapshots and aggregations, cleanup, LLM log pruning, balance snapshots — and
  the stream is polled every 30s (was every 5 min, so short tracks were missed), with a
  new track enriched the moment it appears. Opt-in; the database dump stays in cron
- Worker health on the dashboard: running / stuck / stopped, uptime, heartbeat age,
  queue depth, and every periodic task's last run, duration and error
- Fixed: asking the stats API for N periods could return N+1 rows once a real
  aggregation existed, because the computed current period was added after the limit
- The bot worker keeps itself current: a settings change is adopted in place, and a
  code change makes it exit between batches so the supervisor restarts it on the new
  code (`--no-reload` to disable)
- Bot Activity shows one account card per provider (balance where published,
  month-to-date spend where not) and splits the per-window cost by provider
- Real spend for providers that publish no balance: OpenAI's `GET /organization/costs`
  (optional admin key in its settings block), shown where the balance would be
- Cost in money, not just tokens: remaining balance and hourly readings from the
  provider's `/user/balance` (real spend per window is the drop between readings),
  plus per-call cost priced at write time from editable unit prices
  (Settings → Bots → Cost) since the provider has no pricing endpoint. Shown in
  Bot Activity, on the dashboard card, in the call detail and in
  `bot-worker.php log`
- Model dropdown fetched live from the provider's `/models`, so a retired model
  disappears without a code change (the built-in list stays as the fallback)
- Bot Activity tab in the admin panel: token usage per hour/day/week, every bot
  conversation with its budget and state (with its messages and the calls behind
  them), and the LLM call log with per-call prompt, reply, token usage and error
- Rolling per-conversation summary: what falls out of the history window is
  summarised once and carried in the prompt, so a bot with a raised message limit
  no longer forgets names and plans from earlier. Batched (half a window,
  minimum 3 messages) with the pending ones kept verbatim, so it costs one extra
  call every few messages rather than one per reply
- "Clear conversations" for a bot (dialog and Fake Users row): removes its
  private messages, per-thread budget, takeover state and queued replies, so it
  can be retested from scratch
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
