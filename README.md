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

- **Admin panel** with comprehensive moderation tools
- **IP banning** with temporary or permanent durations
- **Nickname blacklist** to reserve or block usernames
- **URL filtering** with customizable blacklist patterns
- **Rate limiting** to prevent spam
- **Automatic violation tracking** with auto-ban system

### User Experience

- **Optional user profiles** (age, location, sex)
- **Emoji picker** with categorized emojis
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

Configure via admin panel (http://localhost:98/admin.html):

- Default credentials: `admin` / `admin123` (change immediately!)

| Setting | Description | Default |
|---------|-------------|---------|
| Page Title | Browser tab title | RadioChatBox |
| Chat Mode | `public`, `private`, or `both` | both |
| Require Profile | Force users to provide age/sex/location | false |
| Allow Photo Uploads | Enable photo sharing in private messages | true |
| Max Photo Size | Maximum upload size in MB | 5 MB |
| Rate Limit | Messages per time window | 10 per 60s |

---

## � API Documentation

### POST `/api/send.php`

Send a new message to the chat.

**Request:**
```json
{
    "username": "DJ Mike",
    "message": "Hello everyone!"
}
```

**Response:**
```json
{
    "success": true,
    "message": {
        "id": "msg_123456",
        "username": "DJ Mike",
        "message": "Hello everyone!",
        "timestamp": 1699999999
    }
}
```

### GET `/api/history.php`

Get recent message history.

**Parameters:**
- `limit` (optional): Number of messages to retrieve (default: 50, max: 100)

**Response:**
```json
{
    "success": true,
    "messages": [...]
}
```

---

## 📚 Documentation

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
| `/api/register.php` | POST | Register username |
| `/api/settings.php` | GET | Get public settings |

#### Admin Endpoints (Require Basic Auth)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/admin/messages.php` | GET | Paginated message list |
| `/api/admin/clear-chat.php` | POST | Clear all messages |
| `/api/admin/ban-ip.php` | POST | Ban IP address |
| `/api/admin/settings.php` | GET/POST | Manage settings |

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

