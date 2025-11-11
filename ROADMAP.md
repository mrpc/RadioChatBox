# RadioChatBox Roadmap

This document outlines the planned features and enhancements for RadioChatBox.

---

## Phase 1: User Management & Moderation (v1.1.0)

**Target:** Q1 2026

### User Authentication System
- [ ] User registration with email/password
- [ ] Facebook OAuth integration for login/registration
- [ ] Login/logout functionality
- [ ] Password reset via email
- [ ] Email verification for new accounts
- [ ] Session management with "Remember Me" option
- [ ] Guest mode vs registered users
- [ ] Account settings page

**Database Changes:**
- New `accounts` table (id, email, password_hash, facebook_id, email_verified, created_at)
- New `sessions` table for session tracking
- New `password_resets` table for reset tokens
- Update `users` table to link with `accounts`

### User Roles & Permissions
- [ ] Three-tier role system: User, Moderator, Admin
- [ ] Role-based access control (RBAC) implementation
- [ ] Admin interface for role assignment
- [ ] Permission matrix configuration
- [ ] Moderator appointment/removal by admins
- [ ] Role-specific UI elements (badges, colors)

**Database Changes:**
- Add `role` column to `accounts` table (enum: 'user', 'moderator', 'admin')
- New `permissions` table for granular permissions
- New `role_permissions` junction table

### Reporting System
- [ ] Report user/message/photo functionality
- [ ] Report submission form with reason selection
- [ ] Report categories (predefined + custom)
- [ ] Report storage and tracking
- [ ] Reporter anonymity option

**Database Changes:**
- New `reports` table (id, reporter_id, reported_user_id, reported_content_type, reported_content_id, reason_id, description, status, created_at)
- New `report_reasons` table (id, reason_text, category, is_active, order)

### Report Reasons Management
- [ ] Admin panel page for managing report reasons
- [ ] Add/edit/delete report reasons
- [ ] Reorder reasons by drag-and-drop
- [ ] Enable/disable specific reasons
- [ ] Default reasons (spam, harassment, inappropriate content, offensive language, impersonation, other)
- [ ] Multi-language support for reasons

**API Endpoints:**
- `POST /api/auth/register` - User registration
- `POST /api/auth/login` - User login
- `POST /api/auth/logout` - User logout
- `POST /api/auth/facebook` - Facebook OAuth
- `POST /api/auth/password-reset` - Request password reset
- `POST /api/auth/password-reset/confirm` - Confirm password reset
- `POST /api/reports/submit` - Submit a report
- `GET /api/admin/report-reasons` - List report reasons
- `POST /api/admin/report-reasons` - Create report reason
- `PUT /api/admin/report-reasons/:id` - Update report reason
- `DELETE /api/admin/report-reasons/:id` - Delete report reason

---

## Phase 2: Advanced Moderation (v1.2.0)

**Target:** Q2 2026

### Moderator Controls
- [ ] In-chat moderator action buttons
- [ ] Mute user (temporary silence)
- [ ] Kick user from chat
- [ ] Timeout user (5min, 1hr, 24hr options)
- [ ] Delete individual messages
- [ ] Quick-ban functionality
- [ ] Warning system (3 warnings = auto-timeout)
- [ ] Moderator activity logs
- [ ] Moderator commands (/mute, /kick, /ban, /warn)

**Database Changes:**
- New `moderator_actions` table (id, moderator_id, action_type, target_user_id, target_content_id, reason, duration, created_at)
- New `user_warnings` table (id, user_id, moderator_id, reason, created_at)
- New `user_timeouts` table (id, user_id, timeout_until, reason, created_at)

### Admin Reports Panel
- [ ] Dedicated reports page in admin panel
- [ ] Pending reports queue with filtering
- [ ] Report details view (reporter, reported user, content, history)
- [ ] Quick actions (dismiss, warn, timeout, ban)
- [ ] Bulk actions for multiple reports
- [ ] Report history and statistics
- [ ] Export reports to CSV
- [ ] Email notifications for new reports

**UI Components:**
- Reports dashboard with stats
- Reports table with search/filter
- Report detail modal
- Action confirmation dialogs

### Auto-Moderation System
- [ ] Configurable auto-ban thresholds
- [ ] Track reports per user
- [ ] Automatic escalation rules (e.g., 5 reports = auto-ban)
- [ ] Reputation score system
- [ ] Trust levels based on behavior
- [ ] Automatic shadow ban for repeat offenders
- [ ] Configurable automation rules in admin panel
- [ ] Whitelist/blacklist for auto-mod bypass

**Database Changes:**
- New `auto_mod_rules` table (id, rule_type, threshold, action, duration, is_active)
- New `user_reputation` table (id, user_id, score, trust_level, last_updated)
- Add `auto_banned` flag to `banned_ips` table

### Enhanced User Profiles
- [ ] Profile pictures/avatars
- [ ] Custom status messages
- [ ] User bio/description
- [ ] Online/away/busy status
- [ ] Last seen timestamp
- [ ] Join date display
- [ ] Message count statistics
- [ ] User profile modal/page

**Database Changes:**
- Add columns to `user_profiles`: `avatar_url`, `bio`, `status`, `status_message`, `last_seen`
- New `user_stats` table (id, user_id, messages_sent, warnings_received, reports_received)

**API Endpoints:**
- `POST /api/moderate/mute` - Mute user
- `POST /api/moderate/kick` - Kick user
- `POST /api/moderate/timeout` - Timeout user
- `POST /api/moderate/warn` - Warn user
- `DELETE /api/moderate/message/:id` - Delete message
- `GET /api/admin/reports` - List all reports
- `GET /api/admin/reports/:id` - Get report details
- `PUT /api/admin/reports/:id` - Update report status
- `POST /api/admin/reports/:id/action` - Take action on report
- `GET /api/admin/auto-mod-rules` - List auto-mod rules
- `POST /api/admin/auto-mod-rules` - Create auto-mod rule
- `PUT /api/admin/auto-mod-rules/:id` - Update auto-mod rule

---

## Phase 3: Enhanced Communication (v1.3.0)

**Target:** Q3 2026

### WebRTC Video Calls
- [ ] 1-on-1 video calls in private chats
- [ ] Call initiation and acceptance UI
- [ ] Audio-only call option
- [ ] Screen sharing capability
- [ ] Call controls (mute, camera off, hang up)
- [ ] Call quality indicators
- [ ] Call history and duration tracking
- [ ] STUN/TURN server configuration
- [ ] Bandwidth optimization
- [ ] Mobile-responsive video layout
- [ ] Call notifications

**Database Changes:**
- New `calls` table (id, caller_id, callee_id, call_type, duration, status, started_at, ended_at)
- New `call_settings` in settings table (stun_server, turn_server, turn_username, turn_credential)

**Technical Requirements:**
- WebRTC implementation (PeerJS or native WebRTC)
- STUN/TURN server setup (Coturn recommended)
- ICE candidate exchange via WebSocket
- Media permissions handling

### Message Reactions
- [ ] React to messages with emojis
- [ ] Multiple reactions per message
- [ ] Reaction picker UI
- [ ] View who reacted
- [ ] Remove own reactions
- [ ] Reaction notifications
- [ ] Most popular reactions tracking

**Database Changes:**
- New `message_reactions` table (id, message_id, user_id, emoji, created_at)
- Add `reaction_count` to `messages` table (cached count)

### Advanced Messaging Features
- [ ] Message editing (within 5-minute window)
- [ ] Edit history tracking
- [ ] Reply/quote messages (threading)
- [ ] @mentions with autocomplete
- [ ] Mention notifications
- [ ] Typing indicators (real-time)
- [ ] Message read receipts
- [ ] "User is typing..." indicator
- [ ] Link previews (title, description, thumbnail)

**Database Changes:**
- New `message_edits` table (id, message_id, old_content, edited_at)
- New `message_mentions` table (id, message_id, mentioned_user_id, created_at)
- New `message_reads` table (id, message_id, user_id, read_at)
- Add `parent_message_id` to `messages` table for threading
- Add `edited_at` to `messages` table

### Notifications System
- [ ] Browser push notifications
- [ ] Email notifications for mentions
- [ ] Notification preferences per user
- [ ] Sound/visual notification settings
- [ ] Do Not Disturb mode
- [ ] Notification history
- [ ] Mark as read/unread
- [ ] Notification grouping
- [ ] Custom notification sounds

**Database Changes:**
- New `notifications` table (id, user_id, type, title, message, is_read, created_at)
- New `notification_preferences` table (id, user_id, push_enabled, email_enabled, sound_enabled, dnd_enabled)
- New `push_subscriptions` table (id, user_id, endpoint, keys, created_at)

**API Endpoints:**
- `POST /api/calls/initiate` - Initiate video call
- `POST /api/calls/accept` - Accept video call
- `POST /api/calls/reject` - Reject video call
- `POST /api/calls/end` - End video call
- `POST /api/messages/:id/react` - Add reaction
- `DELETE /api/messages/:id/react/:emoji` - Remove reaction
- `PUT /api/messages/:id` - Edit message
- `POST /api/messages/:id/reply` - Reply to message
- `GET /api/notifications` - Get user notifications
- `PUT /api/notifications/:id/read` - Mark notification as read
- `POST /api/notifications/subscribe` - Subscribe to push notifications
- `PUT /api/notifications/preferences` - Update notification preferences

---

## Phase 4: Scalability & Features (v2.0.0)

**Target:** Q4 2026

### Multiple Chat Rooms/Channels
- [ ] Create multiple public chat rooms
- [ ] Room-specific settings and rules
- [ ] Private group chats (invite-only)
- [ ] User-created rooms (with permissions)
- [ ] Room discovery page
- [ ] Room categories/tags
- [ ] Room member management
- [ ] Room-specific moderators
- [ ] Room join/leave notifications
- [ ] Room capacity limits

**Database Changes:**
- New `rooms` table (id, name, description, type, creator_id, max_users, is_public, created_at)
- New `room_members` table (id, room_id, user_id, role, joined_at)
- New `room_settings` table (id, room_id, setting_key, setting_value)
- Update `messages` table with `room_id` column

### Advanced Analytics Dashboard
- [ ] User growth charts (daily/weekly/monthly)
- [ ] Active users over time graph
- [ ] Message volume statistics
- [ ] Peak usage times analysis
- [ ] Popular days/hours heatmap
- [ ] User retention metrics
- [ ] Moderator performance metrics
- [ ] Report resolution time
- [ ] Export analytics to CSV/PDF
- [ ] Custom date range selection
- [ ] Real-time active users counter

**Database Changes:**
- New `analytics_snapshots` table for daily aggregates
- New `user_sessions` table for detailed session tracking

### Content Filtering & Safety
- [ ] Profanity filter with custom word lists
- [ ] AI-powered content moderation (OpenAI Moderation API)
- [ ] NSFW image detection for uploaded photos
- [ ] Spam detection algorithms
- [ ] Link safety checking
- [ ] Malicious file detection
- [ ] Rate limiting per user level
- [ ] CAPTCHA for suspicious activity

**Database Changes:**
- New `filtered_words` table (id, word, severity, is_active)
- New `content_flags` table (id, content_type, content_id, flag_reason, ai_confidence, created_at)

### Backup & Export
- [ ] Automated daily database backups
- [ ] Export user data (GDPR compliance)
- [ ] Import/export settings
- [ ] Migration tools for database versions
- [ ] Restore from backup functionality
- [ ] Download personal data as ZIP
- [ ] Account deletion with data removal

### Public API
- [ ] RESTful API for third-party integrations
- [ ] API key generation and management
- [ ] Rate limiting per API key
- [ ] API usage statistics
- [ ] Webhook system for events
- [ ] Developer documentation
- [ ] OAuth 2.0 for third-party apps
- [ ] API playground/testing interface

**Database Changes:**
- New `api_keys` table (id, user_id, key_hash, name, permissions, rate_limit, created_at)
- New `api_requests` table (id, api_key_id, endpoint, method, status, created_at)
- New `webhooks` table (id, user_id, url, events, secret, is_active, created_at)

**API Endpoints:**
- `GET /api/v1/rooms` - List all rooms
- `POST /api/v1/rooms` - Create room
- `GET /api/v1/rooms/:id` - Get room details
- `GET /api/v1/analytics/users` - User analytics
- `GET /api/v1/analytics/messages` - Message analytics
- `POST /api/v1/webhooks` - Create webhook
- `GET /api/developer/keys` - List API keys
- `POST /api/developer/keys` - Create API key
- `DELETE /api/developer/keys/:id` - Delete API key

### Progressive Web App (PWA)
- [ ] Service worker implementation
- [ ] Offline message queue
- [ ] Install as mobile app
- [ ] App-like navigation
- [ ] Offline indicator
- [ ] Background sync
- [ ] Add to home screen prompt
- [ ] Push notifications support

**Technical Requirements:**
- manifest.json configuration
- Service worker for caching
- IndexedDB for offline storage
- Background sync API

---

## Phase 5: Native Mobile Apps (v3.0.0)

**Target:** Q2 2027

### Mobile App Features
- [ ] Native iOS app (Swift/SwiftUI)
- [ ] Native Android app (Kotlin)
- [ ] Cross-platform alternative (React Native or Flutter)
- [ ] Push notifications (FCM/APNs)
- [ ] Biometric authentication (Face ID, Touch ID, Fingerprint)
- [ ] Camera integration for photo/video sharing
- [ ] Contact sync for finding friends
- [ ] Optimized mobile UI/UX
- [ ] Offline mode with sync
- [ ] App settings and preferences
- [ ] Dark mode support
- [ ] Gesture controls (swipe to delete, pull to refresh)
- [ ] In-app media player for shared content
- [ ] Location sharing (optional)
- [ ] Deep linking for shared content
- [ ] App Store and Google Play optimization

### Mobile-Specific Features
- [ ] Voice messages
- [ ] Image editing before sending
- [ ] Gallery access and multi-photo sharing
- [ ] Video recording and sharing
- [ ] Emoji keyboard integration
- [ ] Share extension (share from other apps)
- [ ] Widget for quick access
- [ ] 3D Touch/Haptic feedback
- [ ] Notification actions (reply from notification)
- [ ] Background message fetching

### Mobile Backend Enhancements
- [ ] Mobile API optimization
- [ ] Image compression for mobile uploads
- [ ] Adaptive streaming for videos
- [ ] Battery-efficient polling
- [ ] Mobile-specific rate limiting
- [ ] Device registration and management
- [ ] Remote logout from all devices

**Database Changes:**
- New `devices` table (id, user_id, device_type, device_token, os_version, app_version, last_active)
- New `push_tokens` table (id, user_id, device_id, token, platform, created_at)

**API Endpoints:**
- `POST /api/mobile/register-device` - Register mobile device
- `POST /api/mobile/upload-voice` - Upload voice message
- `PUT /api/mobile/update-location` - Update user location
- `POST /api/mobile/contacts/sync` - Sync contacts

### App Store Requirements
- [ ] Privacy policy compliance
- [ ] Terms of service
- [ ] Age rating configuration
- [ ] App description and screenshots
- [ ] App icon sets (all sizes)
- [ ] Beta testing (TestFlight, Play Console)
- [ ] App review preparation
- [ ] In-app purchases setup (optional premium features)
- [ ] Analytics integration (Firebase, Mixpanel)

---

## Additional Enhancements (Ongoing)

### Performance Optimizations
- [ ] Redis clustering for high availability
- [ ] PostgreSQL read replicas
- [ ] CDN integration for static assets
- [ ] Message pagination optimization
- [ ] Lazy loading for images
- [ ] WebSocket connection pooling
- [ ] Database query optimization
- [ ] Caching strategies improvement

### Security Enhancements
- [ ] Two-factor authentication (2FA)
- [ ] Security audit logging
- [ ] IP reputation checking
- [ ] DDoS protection (Cloudflare)
- [ ] XSS protection improvements
- [ ] CSRF token implementation
- [ ] Content Security Policy (CSP)
- [ ] Regular dependency updates

### Internationalization (i18n)
- [ ] Multi-language support
- [ ] RTL language support
- [ ] Language selector
- [ ] Translatable strings
- [ ] Date/time localization
- [ ] Currency formatting (if applicable)
- [ ] Community translations

### Accessibility (a11y)
- [ ] Screen reader support
- [ ] Keyboard navigation
- [ ] ARIA labels
- [ ] Color contrast compliance (WCAG 2.1)
- [ ] Font size adjustments
- [ ] High contrast mode
- [ ] Focus indicators

---

## Feature Requests & Community Input

We welcome feature requests and suggestions from the community! Please:

1. Check existing issues on [GitHub](https://github.com/mrpc/RadioChatBox/issues)
2. Submit new feature requests with the `enhancement` label
3. Vote on features you'd like to see by adding 👍 reactions
4. Join discussions in [GitHub Discussions](https://github.com/mrpc/RadioChatBox/discussions)

---

## Version History

- **v1.0.0** (November 2025) - Initial release
- **v1.1.0** (Q1 2026) - User management & moderation
- **v1.2.0** (Q2 2026) - Advanced moderation
- **v1.3.0** (Q3 2026) - Enhanced communication
- **v2.0.0** (Q4 2026) - Scalability & features
- **v3.0.0** (Q2 2027) - Native mobile apps

---

*Last updated: November 11, 2025*
