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

│   └── init.sql         # Complete schema (v1.0)**Response:**

├── docs/                # API documentation```json

│   └── openapi.yaml     # OpenAPI 3.0 specification{

├── tests/               # PHPUnit tests    "success": true,

├── docker-compose.yml   # Docker orchestration    "messages": [...]

└── .env.example         # Environment template}

``````



### Key Concepts### GET `/api/stream.php`

Server-Sent Events stream for real-time updates.

#### Server-Sent Events (SSE)

RadioChatBox uses SSE for real-time updates instead of WebSockets:**Events:**

- Simpler to deploy (works through standard HTTP/HTTPS)- `history`: Initial message history

- No special server configuration needed- `message`: New message received

- Automatic reconnection handling

- Works behind most proxies and firewalls## Database Schema



#### Message FlowThe PostgreSQL database includes:

1. User sends message via `POST /api/send.php`

2. Message is validated, filtered, and stored in PostgreSQL- **messages**: Stores all chat messages

3. Message is published to Redis pub/sub channel- **users**: Tracks user statistics

4. All connected SSE clients receive the message instantly- **banned_ips**: IP ban management

5. Messages are cached in Redis for quick history retrieval- **Views**: `recent_messages`, `user_stats`



#### Photo Uploads### Useful Queries

- Photos are resized to max 1920×1080 using GD library

- JPEG quality: 85%, PNG compression: 8**Get message statistics:**

- Files stored in `public/uploads/photos/````sql

- Database tracks metadata and expirationSELECT 

- Cron-based cleanup removes expired photos (48h)    COUNT(*) as total_messages,

    COUNT(DISTINCT username) as unique_users,

---    DATE_TRUNC('hour', created_at) as hour

FROM messages

## 🔌 API DocumentationWHERE created_at > NOW() - INTERVAL '24 hours'

GROUP BY hour

Full API documentation is available in OpenAPI 3.0 format: [`docs/openapi.yaml`](docs/openapi.yaml)ORDER BY hour DESC;

```

### Quick Reference

**Top chatters:**

#### Public Endpoints```sql

SELECT * FROM user_stats LIMIT 10;

| Endpoint | Method | Description |```

|----------|--------|-------------|

| `/api/stream.php` | GET | SSE stream for real-time updates |**Recent messages:**

| `/api/send.php` | POST | Send a public message |```sql

| `/api/history.php` | GET | Get message history |SELECT * FROM recent_messages LIMIT 50;

| `/api/private-message.php` | GET/POST | Private messaging |```

| `/api/upload-photo.php` | POST | Upload photo (multipart) |

| `/api/register.php` | POST | Register username |## Production Deployment

| `/api/heartbeat.php` | POST | Maintain online status |

| `/api/settings.php` | GET | Get public settings |### Performance Optimization



#### Admin Endpoints (Require Basic Auth)1. **Enable PHP OPcache** - Edit `Dockerfile`:

   ```dockerfile

| Endpoint | Method | Description |   RUN docker-php-ext-install opcache

|----------|--------|-------------|   ```

| `/api/admin/messages.php` | GET | Paginated message list |

| `/api/admin/clear-chat.php` | POST | Clear all messages |2. **Increase PHP-FPM workers** - Create `php-fpm.conf`:

| `/api/admin/ban-ip.php` | POST | Ban IP address |   ```ini

| `/api/admin/ban-nickname.php` | POST | Ban nickname |   pm.max_children = 50

| `/api/admin/settings.php` | GET/POST | Manage settings |   pm.start_servers = 10

| `/api/admin/photos.php` | GET/DELETE | Manage photos |   pm.min_spare_servers = 5

   pm.max_spare_servers = 20

### Example: Send Message   ```



```bash3. **Redis persistence** - Already configured with AOF

curl -X POST http://localhost:98/api/send.php \

  -H "Content-Type: application/json" \4. **PostgreSQL tuning** - Add to `docker-compose.yml`:

  -d '{   ```yaml

    "username": "Alice",   command: postgres -c shared_buffers=256MB -c max_connections=200

    "message": "Hello world!",   ```

    "sessionId": "abc123"

  }'### Security Recommendations

```

1. **Change default passwords** in `.env`

---2. **Use HTTPS** with SSL/TLS certificates

3. **Configure firewall** to restrict database access

## 🧪 Testing4. **Enable rate limiting** at nginx level

5. **Regular backups** of PostgreSQL data

Run the test suite:

### SSL/HTTPS Setup

```bash

./test.shAdd SSL certificates to Apache configuration in `apache/site.conf`:

``````apache

<VirtualHost *:443>

Or manually:    ServerName your-domain.com

    SSLEngine on

```bash    SSLCertificateFile /path/to/cert.pem

docker exec radiochatbox_apache composer install --dev    SSLCertificateKeyFile /path/to/key.pem

docker exec radiochatbox_apache ./vendor/bin/phpunit    # ... rest of config

```</VirtualHost>

```

---

### Monitoring

## 🎨 Embedding

**Check container logs:**

Embed RadioChatBox in your website:```bash

docker-compose logs -f

```html```

<iframe 

  src="https://your-domain.com/?audio=false" **Monitor Redis:**

  width="100%" ```bash

  height="600" docker exec -it radiochatbox_redis redis-cli

  frameborder="0">> INFO stats

</iframe>> MONITOR

``````



**Parameters:****Monitor PostgreSQL:**

- `?audio=true` - Enable notification sounds (default)```bash

- `?audio=false` - Disable notification soundsdocker exec -it radiochatbox_postgres psql -U radiochatbox -d radiochatbox

\dt

The chat automatically detects iframe embedding and:SELECT * FROM user_stats;

- Auto-collapses the sidebar on mobile```

- Adjusts layout for constrained spaces

## Scaling

---

For handling more than 500 concurrent users:

## 🔒 Security

1. **Multiple PHP-FPM containers** with load balancing

### Best Practices2. **Redis Cluster** for distributed caching

3. **PostgreSQL replication** for read scaling

1. **Change default admin password immediately**4. **CDN** for static assets

   - Admin panel → Settings → Update password

   - Use strong, unique password## Troubleshooting



2. **Use HTTPS in production**### Chat won't connect

   - Configure SSL/TLS certificates

   - Update `.env` URLs to https://1. Check if containers are running:

   ```bash

3. **Secure your PostgreSQL**   docker-compose ps

   - Change database password in `.env`   ```

   - Restrict network access to database port

2. Check Apache logs:

4. **Configure rate limiting**   ```bash

   - Adjust rate limits based on your audience size   docker-compose logs apache

   - Monitor logs for abuse patterns   ```



5. **Regular backups**3. Verify SSE endpoint:

   - Backup PostgreSQL database regularly   ```bash

   - Backup uploaded photos if needed   curl http://localhost:98/api/stream.php

   ```

### Reporting Vulnerabilities

### Messages not appearing

Please report security vulnerabilities to [SECURITY.md](SECURITY.md)

1. Check Redis connection:

---   ```bash

   docker exec -it radiochatbox_redis redis-cli ping

## 🛠️ Development   ```



### Requirements2. Check PHP errors:

- PHP 8.1+   ```bash

- PostgreSQL 14+   docker-compose logs apache

- Redis 6.0+   ```

- GD extension (for photo processing)

### Database connection issues

### Local Development

1. Verify PostgreSQL is running:

```bash   ```bash

# Install dependencies   docker exec -it radiochatbox_postgres pg_isready

docker exec radiochatbox_apache composer install --dev   ```



# Run tests2. Check credentials in `.env`

./test.sh

## Development

# Watch logs

docker-compose logs -f apache### Project Structure



# Access database```

docker exec -it radiochatbox_postgres psql -U radiochatbox -d radiochatboxRadioChatBox/

```├── database/

│   └── init.sql              # PostgreSQL schema

### Database Migrations├── apache/

│   └── site.conf             # Apache configuration

This is v1.0 - no migrations needed. Fresh installations use `database/init.sql`.├── public/

│   ├── api/

For future versions, create migration files in `database/migrations/`.│   │   ├── send.php         # Send message endpoint

│   │   ├── stream.php       # SSE endpoint

---│   │   └── history.php      # Message history endpoint

│   ├── css/

## 🤝 Contributing│   │   └── style.css        # Styles

│   ├── js/

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.│   │   └── chat.js          # Frontend logic

│   └── index.html           # Main page

### Quick Contribution Guide├── src/

│   ├── ChatService.php      # Core chat logic

1. Fork the repository│   ├── Config.php           # Configuration management

2. Create a feature branch (`git checkout -b feature/amazing-feature`)│   ├── CorsHandler.php      # CORS support

3. Commit your changes (`git commit -m 'Add amazing feature'`)│   └── Database.php         # Database connections

4. Push to the branch (`git push origin feature/amazing-feature`)├── docker-compose.yml       # Docker orchestration

5. Open a Pull Request├── Dockerfile              # PHP container

├── composer.json           # PHP dependencies

---└── README.md              # This file

```

## 📄 License

### Running Tests

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

```bash

---# Run PHP syntax check

docker-compose exec apache php -l src/*.php

## 🙏 Acknowledgments

# Test Redis connection

- Built with PHP, PostgreSQL, Redisdocker-compose exec apache php -r "var_dump((new Redis())->connect('redis', 6379));"

- Uses Server-Sent Events for real-time communication

- Inspired by the need for simple, embeddable chat for radio shows# Test PostgreSQL connection

docker-compose exec apache php -r "var_dump(new PDO('pgsql:host=postgres;dbname=radiochatbox', 'radiochatbox', 'radiochatbox_secret'));"

---```



## 📞 Support## Contributing



- **Issues**: [GitHub Issues](https://github.com/mrpc/RadioChatBox/issues)Contributions are welcome! Please feel free to submit a Pull Request.

- **Discussions**: [GitHub Discussions](https://github.com/mrpc/RadioChatBox/discussions)

- **Documentation**: [Wiki](https://github.com/mrpc/RadioChatBox/wiki)1. Fork the repository

2. Create your feature branch (`git checkout -b feature/AmazingFeature`)

---3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)

4. Push to the branch (`git push origin feature/AmazingFeature`)

<div align="center">5. Open a Pull Request



Made with ❤️ for radio shows and live broadcasts## License



[⬆ Back to Top](#radiochatbox-)This project is licensed under the MIT License - see the [LICENSE](#license-file) file for details.



</div>## Roadmap


- [ ] User authentication system
- [ ] Moderator tools (ban/mute users)
- [ ] Message reactions (like/emojis)
- [ ] File/image sharing
- [ ] Private messaging
- [ ] Chat rooms/channels
- [ ] Message search
- [ ] Admin dashboard
- [ ] Push notifications
- [ ] Mobile apps (iOS/Android)

## Support

For issues, questions, or contributions:
- 🐛 [Report bugs](https://github.com/mrpc/RadioChatBox/issues)
- 💬 [Discussions](https://github.com/mrpc/RadioChatBox/discussions)

## Credits

Built with ❤️ for radio shows and live streaming communities.

---

**Happy Broadcasting! 🎙️📻**
