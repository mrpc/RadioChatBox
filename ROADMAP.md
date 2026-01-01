# RadioChatBox Roadmap

This document outlines the planned features and enhancements for RadioChatBox.

---

## Phase 1: User Management & Reporting (v1.1.0)

**Target:** Q1 2026
**Priority:** HIGH - Critical for community safety

### Architecture Improvements
**Priority:** HIGH - Foundation for future development

- [ ] Integrate Pramnos Framework (https://github.com/mrpc/PramnosFramework)
- [ ] Refactor existing code to use Pramnos structure
- [ ] Implement automated database migration system
- [ ] Migration version tracking
- [ ] Automatic migration execution on deployment
- [ ] Rollback capability for failed migrations
- [ ] Migration status dashboard in admin panel
- [ ] Database schema versioning
- [ ] Migration testing in staging environment

**Technical Requirements:**
- Pramnos Framework integration
- Database migration manager (included in Pramnos)
- Migration file structure and naming convention
- Migration execution hooks in deployment pipeline

**Benefits:**
- Cleaner, more maintainable codebase structure
- Safe database schema updates in production
- Version-controlled database changes
- Automatic migration on deployment
- Rollback capability for safety
- Better separation of concerns

**Implementation Notes:**
- Migrations tracked in `migrations` table (included in Pramnos)
- Sequential migration execution
- Transaction-wrapped migrations for safety
- Pre and post-deployment migration hooks

### User Reporting System
**Priority:** CRITICAL - Most important missing feature

- [ ] Report user/message/photo functionality
- [ ] Report submission form with reason selection
- [ ] Report categories (spam, harassment, inappropriate content, offensive language, impersonation, other)
- [ ] Reporter anonymity option
- [ ] Dedicated admin reports panel
- [ ] Pending reports queue with filtering
- [ ] Report details view (reporter, reported user, content, history)
- [ ] Quick actions from reports (dismiss, warn, timeout, ban)
- [ ] Bulk report actions
- [ ] Report history and statistics
- [ ] Export reports to CSV
- [ ] Email notifications for new reports

**Database Changes:**
- New `reports` table (id, reporter_id, reported_user_id, reported_content_type, reported_content_id, reason_id, description, status, created_at, resolved_at, resolved_by)
- New `report_reasons` table (id, reason_text, category, is_active, display_order)

**API Endpoints:**
- `POST /api/reports/submit` - Submit a report
- `GET /api/admin/reports` - List all reports with filtering
- `GET /api/admin/reports/:id` - Get report details
- `PUT /api/admin/reports/:id` - Update report status
- `POST /api/admin/reports/:id/action` - Take action on report
- `GET /api/admin/report-reasons` - List report reasons
- `POST /api/admin/report-reasons` - Create report reason
- `PUT /api/admin/report-reasons/:id` - Update report reason
- `DELETE /api/admin/report-reasons/:id` - Delete report reason

**UI Components:**
- Report button in message/user context menus
- Report submission modal
- Admin reports dashboard tab
- Reports table with search/filter
- Report detail modal with action buttons

### Enhanced User Authentication
- [ ] User self-registration (public signup form)
- [ ] Email/password authentication system
- [ ] Password reset via email
- [ ] Email verification for new accounts
- [ ] "Remember Me" functionality
- [ ] Facebook OAuth integration
- [ ] Account settings page
- [ ] Two-factor authentication (2FA)

**Database Changes:**
- Add to `users` table: `email`, `password_hash`, `facebook_id`, `email_verified`, `two_factor_enabled`, `remember_token`
- New `password_resets` table (id, user_id, token, expires_at, created_at)
- New `two_factor_codes` table (id, user_id, code, expires_at, created_at)

### Data Export & Backup Tools
**Priority:** MEDIUM - Important for GDPR compliance and data safety

- [ ] Export statistics to CSV
- [ ] Export statistics to PDF
- [ ] Export user data (GDPR data download)
- [ ] Export chat history
- [ ] Settings export (JSON/backup file)
- [ ] Settings import (restore from backup)
- [ ] Automated daily database backups
- [ ] Backup rotation and retention policy
- [ ] Manual backup trigger from admin panel
- [ ] Restore from backup functionality

**API Endpoints:**
- `GET /api/admin/export-stats?format=csv` - Export stats to CSV
- `GET /api/admin/export-stats?format=pdf` - Export stats to PDF
- `GET /api/export-my-data` - User exports their own data (GDPR)
- `GET /api/admin/export-settings` - Export settings as JSON
- `POST /api/admin/import-settings` - Import settings from JSON
- `POST /api/admin/backup-database` - Trigger manual backup
- `GET /api/admin/list-backups` - List available backups
- `POST /api/admin/restore-backup` - Restore from backup

### User Engagement Features
**Priority:** MEDIUM - Improve user experience and interaction

- [ ] User blocking/ignoring system
- [ ] Blocked users' messages hidden from view
- [ ] Prevent private messages from blocked users
- [ ] Message pinning (moderators/admins)
- [ ] Pinned messages display at top of chat
- [ ] Pin expiration time
- [ ] Chat commands system (/help, /rules, /tip, etc.)
- [ ] Custom command creation in admin panel
- [ ] Slow mode (configurable message delay)
- [ ] Different rate limits for new vs trusted users

**Database Changes:**
- New `user_blocks` table (id, blocker_id, blocked_id, created_at)
- New `pinned_messages` table (id, message_id, pinned_by, expires_at, created_at)
- New `chat_commands` table (id, command, response, is_active, created_at)
- Add `slow_mode_delay` to `settings` table

**API Endpoints:**
- `POST /api/block-user` - Block a user
- `DELETE /api/unblock-user/:id` - Unblock a user
- `GET /api/blocked-users` - List blocked users
- `POST /api/admin/pin-message` - Pin a message
- `DELETE /api/admin/unpin-message/:id` - Unpin a message
- `GET /api/admin/commands` - List chat commands
- `POST /api/admin/commands` - Create chat command
- `PUT /api/admin/commands/:id` - Update chat command

### Advertisement System Completion
**Priority:** LOW-MEDIUM - Monetization support

- [ ] Complete existing ad system implementation
- [ ] Ad zone management (header, sidebar, footer, etc.)
- [ ] Rotating banner ads
- [ ] Scheduled ads (show at specific times)
- [ ] Click tracking and analytics
- [ ] Ad performance reports
- [ ] External ad network integration (Google AdSense, etc.)
- [ ] Sponsor management interface

**Database Changes:**
- Extend existing ads-related settings
- New `ads` table (id, zone, image_url, link_url, impressions, clicks, start_date, end_date, is_active)
- New `ad_clicks` table (id, ad_id, user_id, clicked_at, ip_address)

---

## Phase 2: Advanced Moderation (v1.2.0)

**Target:** Q2 2026
**Priority:** HIGH - Enhances existing moderation tools

### Enhanced Moderator Controls
- [ ] Mute user (temporary silence with duration)
- [ ] Timeout user (5min, 1hr, 24hr, custom duration options)
- [ ] Warning system (escalating warnings with auto-timeout after 3 warnings)
- [ ] In-chat moderator action buttons
- [ ] Moderator activity audit logs
- [ ] Moderator commands (/mute @user, /timeout @user 1h, /warn @user, /ban @user)
- [ ] Moderator performance dashboard

**Database Changes:**
- New `moderator_actions` table (id, moderator_id, action_type, target_user_id, target_content_id, reason, duration, created_at)
- New `user_warnings` table (id, user_id, moderator_id, reason, created_at, expires_at)
- New `user_timeouts` table (id, user_id, moderator_id, timeout_until, reason, created_at)
- Add `muted_until` column to `sessions` table

### Auto-Moderation System
- [ ] Configurable auto-ban thresholds
- [ ] Track reports per user
- [ ] Automatic escalation rules (e.g., 5 reports = auto-ban)
- [ ] Reputation score system
- [ ] Trust levels based on user behavior
- [ ] Automatic shadow ban for repeat offenders
- [ ] Configurable automation rules in admin panel
- [ ] Auto-mod whitelist (exempt trusted users)
- [ ] Auto-mod activity logs

**Database Changes:**
- New `auto_mod_rules` table (id, rule_type, threshold, action, duration, is_active, created_at)
- New `user_reputation` table (id, user_id, score, trust_level, reports_count, warnings_count, bans_count, last_updated)
- Add `auto_banned` flag to `banned_ips` table
- Add `shadow_banned` column to `sessions` table

### Enhanced User Profiles
- [ ] Profile pictures/avatars (upload & resize)
- [ ] Custom status messages
- [ ] User bio/description
- [ ] Online/away/busy status indicators
- [ ] Message count statistics display
- [ ] User profile modal/page
- [ ] Badge system (verified, moderator, admin, VIP)
- [ ] User activity levels/ranks
- [ ] Automatic rank progression based on participation
- [ ] Visual rank indicators (colors, icons, titles)

**Database Changes:**
- Add `avatar_url`, `bio`, `status`, `status_message` to `user_profiles` table
- New `user_stats` table (id, user_id, messages_sent, private_messages_sent, photos_uploaded, warnings_received, reports_received, reports_submitted)
- New `user_badges` table (id, user_id, badge_type, awarded_at)
- Add `activity_level`, `rank` to `user_profiles` table

### Intelligent Fake Users
**Priority:** LOW - Enhanced realism for fake users

- [ ] Bot-driven realistic behavior for fake users
- [ ] Configurable message templates and responses
- [ ] Random message timing (appear more human)
- [ ] Contextual responses based on chat activity
- [ ] Personality profiles for different fake users
- [ ] Fake users react to events (new song, etc.)
- [ ] Configurable activity patterns (time-based)

**Database Changes:**
- Add `personality_type`, `message_templates` to `fake_users` table
- New `fake_user_behaviors` table (id, fake_user_id, behavior_type, config, is_active)

**API Endpoints:**
- `POST /api/moderate/mute` - Mute user
- `POST /api/moderate/timeout` - Timeout user
- `POST /api/moderate/warn` - Warn user
- `POST /api/moderate/shadow-ban` - Shadow ban user
- `GET /api/admin/moderator-logs` - View moderation activity
- `GET /api/admin/auto-mod-rules` - List auto-mod rules
- `POST /api/admin/auto-mod-rules` - Create auto-mod rule
- `PUT /api/admin/auto-mod-rules/:id` - Update auto-mod rule
- `DELETE /api/admin/auto-mod-rules/:id` - Delete auto-mod rule
- `POST /api/upload-avatar` - Upload profile picture
- `PUT /api/update-profile-extended` - Update bio, status, etc.

---

## Phase 3: Enhanced Communication (v1.3.0)

**Target:** Q3 2026
**Priority:** MEDIUM - User experience improvements

### Message Reactions
**Priority:** HIGH - Highly requested feature

- [ ] React to messages with emojis
- [ ] Multiple reactions per message
- [ ] Emoji picker UI for reactions
- [ ] View who reacted (reaction details)
- [ ] Remove own reactions
- [ ] Reaction notifications
- [ ] Most popular reactions tracking

**Database Changes:**
- New `message_reactions` table (id, message_id, user_id, emoji, created_at)
- Add `reaction_count` to `messages` table (cached count)

### Advanced Messaging Features
- [ ] Message editing (within 5-minute window)
- [ ] Edit history tracking
- [ ] @mentions with autocomplete
- [ ] Mention notifications
- [ ] Typing indicators ("User is typing...")
- [ ] Message read receipts (for private messages)
- [ ] Link previews (title, description, thumbnail)
- [ ] Message search functionality

**Database Changes:**
- New `message_edits` table (id, message_id, old_content, new_content, edited_at)
- New `message_mentions` table (id, message_id, mentioned_user_id, created_at)
- New `message_reads` table (id, message_id, user_id, read_at)
- Add `edited_at` to `messages` table
- Add `link_preview_data` JSON column to `messages` table

### Notifications System
- [ ] Browser push notifications
- [ ] Email notifications for mentions
- [ ] Notification preferences per user
- [ ] Sound/visual notification settings
- [ ] Do Not Disturb mode
- [ ] Notification history
- [ ] Mark notifications as read/unread
- [ ] Notification grouping
- [ ] Custom notification sounds

**Database Changes:**
- New `notifications` table (id, user_id, type, title, message, link, is_read, created_at)
- New `notification_preferences` table (id, user_id, push_enabled, email_enabled, sound_enabled, dnd_enabled, mention_notifications, reply_notifications)
- New `push_subscriptions` table (id, user_id, endpoint, p256dh_key, auth_key, created_at)

### Radio Interaction Features
**Priority:** MEDIUM - Radio-specific engagement

- [ ] Song request system
- [ ] Song voting/rating
- [ ] Dedication messages
- [ ] Shout-outs display
- [ ] Request queue management (admin)
- [ ] Request approval workflow
- [ ] Integration with radio automation software (if applicable)
- [ ] Now-playing voting (thumbs up/down)
- [ ] Request history tracking

**Database Changes:**
- New `song_requests` table (id, user_id, song_title, artist, message, status, requested_at, played_at)
- New `song_votes` table (id, song_id, user_id, vote_type, created_at)
- New `dedications` table (id, user_id, recipient, message, song_id, created_at)

### Song Tracking & Analytics
**Priority:** MEDIUM - Radio station analytics

- [ ] Automatic song play tracking from Shoutcast/Icecast
- [ ] Record song title, artist, album, duration
- [ ] Track listener count per song
- [ ] Timestamp when song started/ended
- [ ] Song play history
- [ ] Song popularity rankings (most played, most listeners)
- [ ] Peak listeners during specific songs
- [ ] Song performance reports
- [ ] Export song statistics to CSV
- [ ] Top songs by time period (daily/weekly/monthly)
- [ ] Song genre tracking (optional)
- [ ] Average listener retention per song

**Database Changes:**
- New `song_plays` table (id, title, artist, album, genre, played_at, duration, listener_count_start, listener_count_end, listener_count_peak, created_at)
- New `song_catalog` table (id, title, artist, album, genre, total_plays, total_listeners, avg_listeners, last_played_at)
- Add index on `played_at` for performance
- Add index on `listener_count_peak` for ranking queries

**Analytics Queries:**
- Most played songs (by play count)
- Songs with highest listener engagement
- Songs that gain/lose listeners
- Best time slots for specific songs
- Song rotation analysis
- Listener preferences by hour/day

**Integration:**
- Poll Shoutcast/Icecast metadata every 10-30 seconds
- Detect song changes
- Record play data automatically
- Background process or cron job for data collection

### Polls & Voting
**Priority:** LOW-MEDIUM - Audience engagement

- [ ] Create quick polls in chat
- [ ] Multiple choice questions
- [ ] Live results display
- [ ] Poll expiration time
- [ ] Anonymous vs named voting
- [ ] Poll history and archives
- [ ] Export poll results

**Database Changes:**
- New `polls` table (id, creator_id, question, options, expires_at, is_active, created_at)
- New `poll_votes` table (id, poll_id, user_id, option_index, created_at)

### Radio Show Schedule
**Priority:** LOW - Nice to have for show planning

- [ ] Display upcoming shows/events
- [ ] Calendar view of scheduled broadcasts
- [ ] Show details (host, description, time)
- [ ] Recurring show support (weekly, daily)
- [ ] Reminders/notifications for favorite shows
- [ ] Links to past show archives
- [ ] Time zone display support
- [ ] Subscribe to show notifications
- [ ] iCal/Google Calendar export

**Database Changes:**
- New `shows` table (id, title, description, host, start_time, end_time, recurrence_rule, is_active, created_at)
- New `show_reminders` table (id, show_id, user_id, reminder_time, created_at)
- New `show_archives` table (id, show_id, recording_url, air_date, created_at)

**API Endpoints:**
- `POST /api/messages/:id/react` - Add reaction
- `DELETE /api/messages/:id/react/:emoji` - Remove reaction
- `PUT /api/messages/:id` - Edit message
- `GET /api/messages/:id/edits` - Get edit history
- `GET /api/notifications` - Get user notifications
- `PUT /api/notifications/:id/read` - Mark notification as read
- `POST /api/notifications/subscribe` - Subscribe to push notifications
- `PUT /api/notifications/preferences` - Update notification preferences
- `POST /api/radio/request-song` - Request a song
- `GET /api/radio/requests` - Get song request queue
- `POST /api/radio/vote-song/:id` - Vote on song request
- `POST /api/polls/create` - Create a poll
- `POST /api/polls/:id/vote` - Vote on a poll
- `GET /api/polls/:id/results` - Get poll results
- `GET /api/schedule` - Get upcoming shows
- `GET /api/schedule/:id` - Get show details
- `POST /api/schedule/:id/reminder` - Set show reminder
- `GET /api/schedule/archives` - Get past show archives
- `GET /api/songs/history` - Get song play history
- `GET /api/songs/top` - Get top songs by plays or listeners
- `GET /api/songs/stats/:id` - Get detailed statistics for a specific song
- `GET /api/songs/catalog` - Get full song catalog with aggregated stats
- `GET /api/admin/songs/export` - Export song statistics to CSV

---

## Phase 4: Content Safety & Multi-Room Support (v2.0.0)

**Target:** Q4 2026
**Priority:** MEDIUM-HIGH

### Content Filtering & Safety
**Priority:** HIGH for production environments

- [ ] Profanity filter with custom word lists
- [ ] AI-powered content moderation (OpenAI Moderation API)
- [ ] NSFW image detection for uploaded photos
- [ ] Spam detection algorithms
- [ ] Link safety checking (phishing/malware detection)
- [ ] CAPTCHA for suspicious activity
- [ ] Configurable filter strictness levels
- [ ] User-level filter preferences (hide profanity vs see warnings)

**Database Changes:**
- New `filtered_words` table (id, word, regex_pattern, severity, replacement, is_active)
- New `content_flags` table (id, content_type, content_id, flag_type, flag_reason, ai_confidence, auto_actioned, created_at)
- Add `nsfw_score` column to `attachments` table

### Multiple Chat Rooms/Channels
**Priority:** MEDIUM - Architectural change

- [ ] Create multiple public chat rooms
- [ ] Room-specific settings and rules
- [ ] Private group chats (invite-only)
- [ ] User-created rooms (with admin approval)
- [ ] Room discovery page
- [ ] Room categories/tags
- [ ] Room member management
- [ ] Room-specific moderators
- [ ] Room join/leave notifications
- [ ] Room capacity limits
- [ ] Room activity indicators

**Database Changes:**
- New `rooms` table (id, name, description, type, creator_id, max_users, is_public, is_active, created_at)
- New `room_members` table (id, room_id, user_id, role, joined_at, last_read_at)
- New `room_settings` table (id, room_id, setting_key, setting_value)
- Add `room_id` column to `messages` table (nullable for backward compatibility)
- Add `room_id` column to `private_messages` table

### Enhanced Analytics
- [ ] Peak usage times heatmap visualization
- [ ] User retention metrics (daily/weekly/monthly active users)
- [ ] Moderator performance metrics
- [ ] Report resolution time tracking
- [ ] Room-specific analytics (if multi-room implemented)
- [ ] Export analytics to PDF
- [ ] Customizable dashboard widgets
- [ ] Funnel analysis (registration → active user)

**API Endpoints:**
- `POST /api/admin/profanity-filter` - Add filtered word
- `GET /api/admin/content-flags` - View flagged content
- `POST /api/admin/review-content/:id` - Review and action flagged content
- `GET /api/rooms` - List all rooms
- `POST /api/rooms` - Create room
- `GET /api/rooms/:id` - Get room details
- `POST /api/rooms/:id/join` - Join room
- `POST /api/rooms/:id/leave` - Leave room
- `GET /api/rooms/:id/members` - List room members
- `GET /api/analytics/retention` - Get retention metrics
- `GET /api/analytics/heatmap` - Get usage heatmap data

---

## Phase 5: Public API & Developer Platform (v2.1.0)

**Target:** Q1 2027
**Priority:** LOW-MEDIUM - For ecosystem growth

### RESTful Public API
- [ ] API key generation and management
- [ ] Rate limiting per API key
- [ ] API usage statistics and quotas
- [ ] Developer dashboard
- [ ] API documentation (OpenAPI/Swagger)
- [ ] API playground/testing interface
- [ ] Webhook system for events
- [ ] OAuth 2.0 for third-party apps
- [ ] Scoped permissions per API key
- [ ] API versioning (v1, v2, etc.)

**Database Changes:**
- New `api_keys` table (id, user_id, key_hash, name, permissions, rate_limit, quota_daily, created_at, last_used_at)
- New `api_requests` table (id, api_key_id, endpoint, method, status_code, response_time, created_at)
- New `webhooks` table (id, user_id, url, events, secret, is_active, last_triggered_at, created_at)
- New `oauth_clients` table (id, client_id, client_secret, name, redirect_uris, created_at)

**API Endpoints:**
- `GET /api/v1/messages` - List messages (public API)
- `POST /api/v1/messages` - Send message (public API)
- `GET /api/v1/users` - List active users (public API)
- `GET /api/v1/rooms` - List rooms (public API)
- `POST /api/developer/keys` - Create API key
- `GET /api/developer/keys` - List API keys
- `DELETE /api/developer/keys/:id` - Revoke API key
- `POST /api/developer/webhooks` - Create webhook
- `GET /api/developer/stats` - API usage statistics

---

## Phase 6: Progressive Web App (v2.2.0)

**Target:** Q2 2027
**Priority:** MEDIUM - Better mobile experience

### PWA Features
- [ ] Service worker implementation
- [ ] Offline message queue
- [ ] Install as mobile app (Add to Home Screen)
- [ ] App-like navigation
- [ ] Offline indicator
- [ ] Background sync for queued messages
- [ ] Push notifications support (via service worker)
- [ ] App manifest configuration
- [ ] Splash screen
- [ ] App icons (all sizes)

**Technical Requirements:**
- `manifest.json` configuration
- Service worker for caching strategies
- IndexedDB for offline message storage
- Background Sync API
- Push API integration

### Mobile Optimizations
- [ ] Touch gesture support (swipe actions)
- [ ] Mobile-optimized image uploads
- [ ] Adaptive image quality based on connection
- [ ] Battery-efficient SSE reconnection strategy
- [ ] Mobile-specific UI adjustments
- [ ] Virtual keyboard handling improvements

---

## Phase 7: Native Mobile Apps (v3.0.0)

**Target:** Q4 2027
**Priority:** LOW - Depends on user demand

### Mobile App Development
- [ ] Choose platform: Native (iOS/Android separate) OR Cross-platform (React Native/Flutter)
- [ ] Native iOS app (Swift/SwiftUI) OR
- [ ] Native Android app (Kotlin) OR
- [ ] Cross-platform app (React Native/Flutter)
- [ ] Push notifications (FCM/APNs)
- [ ] Biometric authentication (Face ID, Touch ID, Fingerprint)
- [ ] Camera integration for photos/videos
- [ ] Optimized mobile UI/UX
- [ ] Offline mode with sync
- [ ] Dark mode support
- [ ] Gesture controls
- [ ] Deep linking

### Mobile-Specific Features
- [ ] Voice messages
- [ ] Image editing before sending
- [ ] Gallery multi-photo sharing
- [ ] Video recording and sharing
- [ ] Share extension (share from other apps)
- [ ] Widget for quick access
- [ ] Haptic feedback

**Database Changes:**
- New `devices` table (id, user_id, device_type, device_token, os_version, app_version, last_active, created_at)
- New `push_tokens` table (id, user_id, device_id, token, platform, created_at)

**API Endpoints:**
- `POST /api/mobile/register-device` - Register device
- `POST /api/mobile/upload-voice` - Upload voice message
- `DELETE /api/mobile/unregister-device` - Unregister device

---

## Phase 8: WebRTC Video Calls (v3.1.0)

**Target:** Q1 2028
**Priority:** LOW - Complex, resource-intensive

### Video Call Features
- [ ] 1-on-1 video calls in private chats
- [ ] Call initiation and acceptance UI
- [ ] Audio-only call option
- [ ] Screen sharing capability
- [ ] Call controls (mute, camera off, hang up)
- [ ] Call quality indicators
- [ ] Call history and duration tracking
- [ ] STUN/TURN server configuration

**Database Changes:**
- New `calls` table (id, caller_id, callee_id, call_type, duration, status, started_at, ended_at)

**Technical Requirements:**
- WebRTC implementation (PeerJS or native)
- STUN/TURN server (Coturn)
- ICE candidate exchange via Redis pub/sub

---

## Phase 9: IRC Protocol Support (v4.0.0)

**Target:** Q3 2028
**Priority:** LOW - After feature complete

### IRC Protocol Compatibility Layer
**Priority:** LOW - Requires feature-complete platform first

- [ ] IRC server/gateway implementation
- [ ] IRC protocol parser and handler
- [ ] Map chat rooms to IRC channels
- [ ] Map private messages to IRC DMs
- [ ] Map user roles to IRC operators (@, ~)
- [ ] IRC authentication (SASL, NickServ)
- [ ] IRC commands support (/join, /part, /msg, /nick, etc.)
- [ ] Message history playback buffer
- [ ] IRC client compatibility testing
- [ ] IRC color codes support
- [ ] Channel modes and user modes
- [ ] CTCP support (VERSION, PING, etc.)
- [ ] DCC chat support (optional)

**Benefits:**
- Users can connect with any IRC client (mIRC, HexChat, WeeChat, etc.)
- Bridge with existing IRC networks
- Leverage IRC bot ecosystem
- Better accessibility for screen reader users
- Lower bandwidth usage
- Power user features (scripting, logging)

**Technical Requirements:**
- IRC server library (PHP IRC daemon or gateway)
- Protocol translation layer (WebSocket/SSE ↔ IRC)
- Connection pooling and state management
- IRC services integration (NickServ, ChanServ)

**Limitations:**
- No native reactions/read receipts (IRC doesn't support)
- Photos shared as URLs
- Limited rich formatting (mIRC colors only)
- Different authentication model

**Database Changes:**
- Add `irc_nickname`, `irc_password_hash` to `users` table
- New `irc_connections` table (id, user_id, client_info, connected_at, last_ping)
- New `irc_channels` table (maps rooms to IRC channels)

**API/Protocol Endpoints:**
- IRC port listener (default 6667 or 6697 for SSL)
- IRC protocol handlers for all RFC 1459/2812 commands
- Bridge service between IRC and web chat

---

## Additional Enhancements (Ongoing)

### Performance Optimizations
- [ ] WebSocket support (upgrade from SSE for bidirectional real-time communication)
- [ ] Redis clustering for high availability
- [ ] PostgreSQL read replicas
- [ ] CDN integration for static assets
- [ ] Lazy loading for images
- [ ] Image optimization pipeline (WebP conversion)

### Security Enhancements
- [ ] Security audit logging
- [ ] DDoS protection (Cloudflare integration)
- [ ] CSRF token implementation
- [ ] Content Security Policy (CSP) headers
- [ ] Regular dependency security updates
- [ ] Penetration testing
- [ ] Bug bounty program

### Internationalization (i18n)
**Priority:** LOW-MEDIUM - Depends on target audience

- [ ] Multi-language support framework
- [ ] Language selector UI
- [ ] Translatable strings extraction
- [ ] RTL language support (Arabic, Hebrew)
- [ ] Date/time localization
- [ ] Number/currency formatting
- [ ] Community translation platform
- [ ] Auto-detect browser language
- [ ] Languages: English, Spanish, French, German, Portuguese, Arabic, Russian, Chinese

### Accessibility (a11y)
**Priority:** MEDIUM - Important for inclusivity

- [ ] Screen reader support (ARIA labels)
- [ ] Keyboard navigation improvements
- [ ] Focus indicators
- [ ] Color contrast compliance (WCAG 2.1 AA)
- [ ] Font size adjustments
- [ ] High contrast mode
- [ ] Reduced motion mode
- [ ] Alt text for images
- [ ] Skip to content links

---

## Feature Requests & Community Input

We welcome feature requests and suggestions from the community! Please:

1. Check existing issues on [GitHub](https://github.com/mrpc/RadioChatBox/issues)
2. Submit new feature requests with the `enhancement` label
3. Vote on features you'd like to see by adding 👍 reactions
4. Join discussions in [GitHub Discussions](https://github.com/mrpc/RadioChatBox/discussions)

---

## Version History

- **v1.0.0** (November 2025) - Initial release with core chat, moderation, and analytics
- **v1.1.0** (Q1 2026) - User reporting, data export, engagement features
- **v1.2.0** (Q2 2026) - Advanced moderation, auto-mod, intelligent fake users
- **v1.3.0** (Q3 2026) - Message reactions, editing, notifications, radio features, polls
- **v2.0.0** (Q4 2026) - Content safety & multi-room support
- **v2.1.0** (Q1 2027) - Public API & developer platform
- **v2.2.0** (Q2 2027) - Progressive Web App
- **v3.0.0** (Q4 2027) - Native mobile apps
- **v3.1.0** (Q1 2028) - WebRTC video calls
- **v4.0.0** (Q3 2028) - IRC protocol support

---

## Implementation Priority Summary

### Critical (Do First)
1. **Architecture Improvements** - Pramnos Framework integration & automated migrations
2. **User Reporting System** - Essential for community moderation
3. **Data Export & Backup** - GDPR compliance and data safety
4. **User Engagement Features** - Blocking, pinning, commands, slow mode
5. **Message Reactions** - Highly requested, improves engagement

### High Priority (Do Soon)
6. **Mute/Timeout/Warning System** - Completes moderation toolkit
7. **Auto-Moderation** - Reduces moderator workload
8. **Content Filtering/Safety** - Important for production environments
9. **Notifications System** - Improves user engagement
10. **Radio Interaction Features** - Song requests, dedications (core to radio use case)

### Medium Priority (Nice to Have)
11. **Enhanced Profiles** (avatars, bios, ranks) - Improves personalization
12. **Polls & Voting** - Audience engagement
13. **Advertisement System** - Complete existing implementation
14. **Multi-Room Support** - Major architectural enhancement
15. **Advanced Analytics** - Better insights
16. **PWA Features** - Better mobile experience
17. **WebSocket Implementation** - Better performance (optional upgrade from SSE)

### Low Priority (Future)
18. **Intelligent Fake Users** - Enhanced bot behavior
19. **Public API** - For ecosystem growth
20. **WebRTC Video Calls** - Complex, resource-intensive
21. **Native Mobile Apps** - Depends on user base size
22. **Internationalization** - Depends on target markets
23. **IRC Protocol Support** - After feature complete, niche use case

---

*Last updated: January 1, 2026*
