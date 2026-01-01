# RadioChatBox 🎙️

<div align="center">

A scalable, real-time chat application designed for radio shows, podcasts, and live broadcasts.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-14%2B-blue)](https://www.postgresql.org/)
[![Redis](https://img.shields.io/badge/Redis-6.0%2B-red)](https://redis.io/)

[Features](#-features) • [Quick Start](#-quick-start) • [Documentation](#-documentation) • [API](#-api-documentation) • [Contributing](#-contributing)

</div>

---

## 🌟 Features

### Real-Time Communication

- **Server-Sent Events (SSE)** for instant message delivery without polling
- **Public chat rooms** for audience interaction
- **Private messaging** between users
- **Photo sharing** in private conversations with auto-expiration
- **Active user tracking** with live presence indicators

### Flexible Chat Modes

- **Public Only**: Traditional chat room for broadcasts
- **Private Only**: One-on-one conversations
- **Both**: Combined public and private messaging

### Moderation & Security

- **Role-based admin users** with 4 permission levels (Root, Administrator, Moderator, Simple User)
- **Admin panel** with comprehensive moderation tools
- **User management** - create, edit, and delete admin accounts
- **IP banning** with temporary or permanent durations
- **Nickname blacklist** to reserve or block usernames
- **URL filtering** with customizable blacklist patterns
- **Rate limiting** to prevent spam
- **Automatic violation tracking** with auto-ban system
- **Session-based authentication** with 24-hour Redis caching

### Analytics & Statistics

- **Comprehensive statistics system** tracking hourly, daily, weekly, monthly, and yearly metrics
- **Real-time monitoring** of concurrent users, radio listeners, and activity levels
- **Interactive dashboard** with charts and visualizations (Chart.js)
- **Metrics tracked**: active users, guest vs registered users, messages, private messages, photo uploads, new registrations, radio listeners, peak concurrent users
- **Automated data collection** via cron jobs with PostgreSQL aggregation functions
- **Redis-cached queries** for fast statistics retrieval
- See [docs/STATISTICS.md](docs/STATISTICS.md) for details

### User Experience

- **Optional user profiles** (age, location, sex)
- **Emoji picker** with categorized emojis
- **Universal emoji support** using Twemoji library for older Windows versions (Windows 7/8/10)
- **Responsive design** for desktop and mobile
- **Collapsible sidebar** optimized for small screens
- **Embeddable** widget for websites with customizable audio notifications
- **Dark/light themes** (configurable)

### Performance & Scalability

- **Redis caching** for high-performance message delivery
- **PostgreSQL** for reliable message persistence
- **Indexed queries** optimized for large datasets
- **Photo auto-cleanup** after 48 hours
- **Inactive user cleanup** to maintain accurate presence

---

## 🚀 Quick Start

### Prerequisites

- Docker Desktop (Windows/Mac) or Docker + Docker Compose (Linux)
- Git

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/mrpc/RadioChatBox.git
   cd RadioChatBox
   ```

2. **Copy environment configuration**
   ```bash
   cp .env.example .env
   ```

3. **Start the application**
   ```bash
   docker-compose up -d
   ```

4. **Access the chat**
   
   Open your browser and navigate to:
   ```
   http://localhost:98
   ```

That's it! The chat is now running on port 98.

---

## 📋 Configuration

Edit `.env` to customize settings:

```env
# Redis Configuration
REDIS_HOST=redis
REDIS_PORT=6379

# PostgreSQL Configuration
DB_HOST=postgres
DB_PORT=5432
DB_NAME=radiochatbox
DB_USER=radiochatbox
DB_PASSWORD=radiochatbox_secret

# Chat Configuration
CHAT_MAX_MESSAGE_LENGTH=500
CHAT_RATE_LIMIT_SECONDS=2
CHAT_HISTORY_LIMIT=100
CHAT_MESSAGE_TTL=3600

# CORS (for embedding)
ALLOWED_ORIGINS=http://localhost:98,https://yourradiosite.com
```

---

## 🎨 Embedding in Your Radio Website

To embed the chat in your existing radio website:

### Option 1: IFrame

```html
<iframe 
    src="http://your-server:98" 
    width="100%" 
    height="600px" 
    frameborder="0"
    style="border-radius: 8px;">
</iframe>
```

**Note:** RadioChatBox uses localStorage for session management, which works perfectly in iframes even when third-party cookies are blocked. For modern browsers (Chrome 114+, Edge 114+), it also supports Partitioned Cookies (CHIPS) as a backup mechanism.

### Option 2: Direct Integration

```html
<!-- Include CSS -->
<link rel="stylesheet" href="http://your-server:98/css/style.css">

<!-- Chat Container -->
<div id="chat-container">
    <!-- Chat UI will load here -->
</div>

<!-- Include JavaScript -->
<script src="http://your-server:98/js/chat.js"></script>
<script>
    // Initialize with your API URL
    const chat = new RadioChatBox('http://your-server:98');
</script>
```

**Important**: Add your website domain to `ALLOWED_ORIGINS` in `.env` file.

---

## 📚 Admin Panel

Access at http://localhost:98/admin.html

### Authentication

- **Default credentials**: `admin` / `admin123` ⚠️ **Change immediately!**
- Login format: Username and password (not just password)

### User Roles

The system supports four hierarchical roles with different permission levels:

| Role | Permissions | Use Case |
|------|-------------|----------|
| **Root** 🔴 | Full access to everything, can create/delete all users | System administrators |
| **Administrator** 🟠 | Full access except creating/deleting root users | Trusted moderators |
| **Moderator** 🟡 | Read-only access to messages, bans, blacklists | Content moderators |
| **Simple User** ⚫ | No admin panel access | Reserved for future features |

### Managing Admin Users

1. Go to the **Admin Users** tab
2. Create new users with username, password, email (optional), and role
3. Edit users to change passwords, roles, or disable accounts
4. Delete users (cannot delete yourself or, if you're not root, root users)

**API Endpoints:**
- `GET /api/admin/users.php` - List all admin users
- `POST /api/admin/create-user.php` - Create new user
- `POST /api/admin/update-user.php` - Update existing user
- `DELETE /api/admin/delete-user.php` - Delete user

### Configuration Settings

| Setting | Description | Default |
|---------|-------------|---------|
| Page Title | Browser tab title | RadioChatBox |
| Chat Mode | `public`, `private`, or `both` | both |
| Require Profile | Force users to provide age/sex/location | false |
| Allow Photo Uploads | Enable photo sharing in private messages | true |
| Max Photo Size | Maximum upload size in MB | 5 MB |
| Rate Limit | Messages per time window | 10 per 60s |
| Stream Status URL | Icecast/Shoutcast status JSON URL. When set, the chat header shows the current song/artist. | (empty) |

---

## 📚 Documentation

### Key Documentation Files

- **[STATISTICS.md](docs/STATISTICS.md)** - Comprehensive statistics system guide
- **[STATISTICS_QUICKSTART.md](STATISTICS_QUICKSTART.md)** - Quick setup for statistics
- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Production deployment guide
- **[SECURITY.md](SECURITY.md)** - Security guidelines and best practices
- **[openapi.yaml](docs/openapi.yaml)** - Complete API specification
- **[EMOJI_SUPPORT.md](docs/EMOJI_SUPPORT.md)** - Emoji rendering documentation

### Project Structure

```
radiochatbox/
├── public/              # Frontend assets
│   ├── index.html       # Main chat interface
│   ├── admin.html       # Admin panel
│   ├── api/             # PHP API endpoints
│   ├── css/             # Stylesheets
│   └── js/              # JavaScript application
├── src/                 # PHP backend classes
│   ├── ChatService.php
│   ├── Database.php
│   ├── PhotoService.php
│   └── MessageFilter.php
├── database/            # Database schema
│   └── init.sql         # Complete schema
├── docs/                # API documentation
│   └── openapi.yaml     # OpenAPI 3.0 specification
├── tests/               # PHPUnit tests
├── docker-compose.yml   # Docker orchestration
└── .env.example         # Environment template
```

### Key Concepts

#### Server-Sent Events (SSE)

RadioChatBox uses SSE for real-time updates instead of WebSockets:

- Simpler to deploy (works through standard HTTP/HTTPS)
- No special server configuration needed
- Automatic reconnection handling
- Works behind most proxies and firewalls

#### Message Flow

1. User sends message via `POST /api/send.php`
2. Message is validated, filtered, and stored in PostgreSQL
3. Message is published to Redis pub/sub channel
4. All connected SSE clients receive the message instantly
5. Messages are cached in Redis for quick history retrieval

---

## 🔌 API Documentation

Full API documentation is available in OpenAPI 3.0 format: [`docs/openapi.yaml`](docs/openapi.yaml)

### Quick Reference

#### Public Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/stream.php` | GET | SSE stream for real-time updates |
| `/api/send.php` | POST | Send a public message |
| `/api/history.php` | GET | Get message history |
| `/api/now-playing.php` | GET | Current radio song/artist (if configured) |
| `/api/register.php` | POST | Register username |
| `/api/login.php` | POST | Login with username/password |
| `/api/logout.php` | POST | Logout user session |
| `/api/settings.php` | GET | Get public settings |
| `/api/active-users.php` | GET | Get list of active users |
| `/api/check-nickname.php` | POST | Check nickname availability |
| `/api/heartbeat.php` | POST | Keep session alive |
| `/api/private-message.php` | POST | Send private message |
| `/api/update-profile.php` | POST | Update user profile |
| `/api/upload-photo.php` | POST | Upload photo attachment |
| `/api/user-profile.php` | GET | Get user profile details |
| `/api/health.php` | GET | Health check endpoint |
| `/api/version.php` | GET | Get API version |

#### Admin Endpoints (Require Basic Auth)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/admin/messages.php` | GET | Paginated message list |
| `/api/admin/user-details.php` | GET | User details with pagination & search (`?username=&page=&limit=&search=`) |
| `/api/admin/inactive-users.php` | GET | List inactive users with pagination |
| `/api/admin/clear-chat.php` | POST | Clear all messages |
| `/api/admin/ban-ip.php` | POST | Ban IP address |
| `/api/admin/kick-user.php` | POST | Kick user from chat |
| `/api/admin/settings.php` | GET/POST | Manage settings |
| `/api/admin/users.php` | GET | List all admin users |
| `/api/admin/create-user.php` | POST | Create new admin user |
| `/api/admin/update-user.php` | POST | Update existing admin user |
| `/api/admin/delete-user.php` | DELETE | Delete admin user |

**User Details Endpoint Usage:**
```bash
# Get user details with default pagination (page 1, limit 50)
GET /api/admin/user-details.php?username=john_doe

# Get second page with 20 messages per page
GET /api/admin/user-details.php?username=john_doe&page=2&limit=20

# Search user's messages containing "hello"
GET /api/admin/user-details.php?username=john_doe&search=hello

# Combine pagination and search
GET /api/admin/user-details.php?username=john_doe&page=1&limit=10&search=music
```

---

## 🧪 Testing

Run the test suite:

```bash
./test.sh
```

Or manually:

```bash
docker exec radiochatbox_apache composer install --dev
docker exec radiochatbox_apache ./vendor/bin/phpunit
```

---

## 🚀 Production Deployment

RadioChatBox can be deployed on any server with Apache, PHP 8.3+, PostgreSQL, and Redis.

### Quick Installation

If you've already cloned the project to your server with LAMP stack installed:

```bash
cd /path/to/RadioChatBox
./setup-production.sh
```

This script will:
- ✅ Check prerequisites (PHP 8.3+, PostgreSQL, Redis, Composer)
- ✅ Create and configure database
- ✅ Set up `.env` configuration with auto-generated webhook secret
- ✅ Install PHP dependencies
- ✅ Configure Apache virtual host
- ✅ Set correct file permissions

### Automatic Deployment with Git Webhooks

Configure your Git platform (GitHub/GitLab/Gitea) to auto-deploy on every push:

1. **Get your webhook secret**:
   ```bash
   cd /path/to/RadioChatBox
   grep WEBHOOK_SECRET .env
   ```

2. **Add webhook** in your Git platform:
   - GitHub: Settings → Webhooks → Add webhook
   - **Payload URL**: `https://your-domain.com/webhook.php`
   - **Content type**: `application/json`
   - **Secret**: Paste your `WEBHOOK_SECRET`
   - **Events**: Just the push event

3. **Test deployment**:
   ```bash
   git push origin main
   # Webhook will automatically:
   # - Pull latest code
   # - Run database migrations
   # - Update dependencies
   # - Clear cache
   # - Reload Apache
   ```

### Manual Deployment

To deploy updates manually:

```bash
cd /path/to/RadioChatBox
./deploy.sh
```

See **[DEPLOYMENT.md](DEPLOYMENT.md)** for complete documentation including:
- Git webhook setup guide
- SSL certificate setup with Let's Encrypt
- Nginx reverse proxy configuration
- Security best practices
- Backup strategies
- Monitoring and troubleshooting

---

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

## 📞 Support

- 🐛 [Report bugs](https://github.com/mrpc/RadioChatBox/issues)
- 💬 [Discussions](https://github.com/mrpc/RadioChatBox/discussions)
- 📖 [Documentation](https://github.com/mrpc/RadioChatBox/wiki)

---

<div align="center">

**Built with ❤️ for radio shows and live streaming communities**

[⬆ Back to Top](#radiochatbox-)

</div>

