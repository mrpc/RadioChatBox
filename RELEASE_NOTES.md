# RadioChatBox v1.0 Release Notes

## Latest Updates

### Fake Users Feature
- Added fake users system to make chat appear more active
- Admins can create a list of fake users with profiles
- System automatically fills chat to meet minimum user threshold
- Fake users appear identical to real users (can be messaged)
- New admin panel tab for managing fake users
- New `minimum_users` setting (default: 0 = disabled)
- New database table: `fake_users`
- Auto-balancing on user join/heartbeat

### Recent Improvements
- Multi-instance Redis isolation with database name prefixing
- Comprehensive unit tests (51 tests, 154 assertions)
- Fixed SSE user updates to include fake users
- Added FakeUserService with balancing logic

## Core Features

✅ Real-time chat with Server-Sent Events (SSE)
✅ Public & private messaging
✅ Photo uploads with 48-hour auto-expiration
✅ Three chat modes: public, private, or both
✅ Admin moderation panel
✅ User profiles (optional, age/location/sex)
✅ IP and nickname banning
✅ URL filtering with blacklist
✅ Rate limiting with auto-ban escalation
✅ Redis caching for performance
✅ PostgreSQL for persistence
✅ Responsive mobile-friendly design
✅ Embeddable in any website
✅ Dark theme
✅ Security hardened
✅ Docker deployment ready
✅ Full API documentation (OpenAPI 3.0)
✅ Unit tested with PHPUnit

## Tech Stack

- **Backend**: PHP 8.3, PostgreSQL 16, Redis 7
- **Frontend**: Vanilla JavaScript, Server-Sent Events
- **Deployment**: Docker Compose
- **Testing**: PHPUnit 10, Mockery
- **Documentation**: OpenAPI 3.0

## Quick Start

```bash
docker-compose up -d
# Access: http://localhost:98
# Admin: http://localhost:98/admin.html (admin/admin123)
```

See README.md for complete documentation.

