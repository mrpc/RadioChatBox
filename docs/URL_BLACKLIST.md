# URL Blacklist Feature

## Overview
The URL blacklist feature allows administrators to manage a list of URL patterns that will be blocked and replaced with `***` in both public and private messages.

## How It Works

### Message Filtering
1. **Public Chat**: All URLs, phone numbers, and dangerous content are replaced with `***`
2. **Private Chat**: Only blacklisted URLs and dangerous content are replaced with `***`

### Dangerous Content Detection
The filter detects and replaces the following patterns:
- `<script>` tags
- Event handlers (`onclick`, `onerror`, etc.)
- `javascript:` protocol
- `data:` protocol
- `<iframe>` tags
- `<form>` tags
- CSS injection attempts
- `<meta>` tags

### URL Blacklist Matching
- Patterns support wildcards using `*`
- Example: `*.malicious.com` matches `http://sub.malicious.com/path`
- Case-insensitive matching
- Subdomain support

## Database Schema

```sql
CREATE TABLE url_blacklist (
    id SERIAL PRIMARY KEY,
    pattern VARCHAR(500) UNIQUE NOT NULL,
    description TEXT,
    added_by VARCHAR(100),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_url_blacklist_pattern ON url_blacklist(pattern);
```

## Default Blacklisted Patterns
1. `bit.ly` - URL shortener
2. `tinyurl.com` - URL shortener
3. `goo.gl` - URL shortener

## Admin Panel Integration

### Accessing URL Blacklist Management
1. Navigate to admin panel: `http://your-domain/admin.html`
2. Login with admin password (default: `admin123`)
3. Click on "URL Blacklist" tab

### Managing Patterns

#### Add a Pattern
1. Enter the URL pattern (e.g., `malicious-site.com` or `*.spam.org`)
2. Enter a description (e.g., "Known phishing site")
3. Click "Add Pattern"

#### View All Patterns
The table displays:
- Pattern
- Description
- Added by
- Date added
- Delete button

#### Delete a Pattern
Click the "Delete" button next to the pattern you want to remove.

## API Endpoints

### GET `/api/admin/url-blacklist.php`
**Authentication Required**: Bearer token in Authorization header

**Response**:
```json
{
  "success": true,
  "patterns": [
    {
      "id": 1,
      "pattern": "bit.ly",
      "description": "URL shortener",
      "added_by": "system",
      "added_at": "2025-11-11 01:14:11.18944"
    }
  ]
}
```

### POST `/api/admin/url-blacklist.php`
**Authentication Required**: Bearer token in Authorization header

**Request Body**:
```json
{
  "pattern": "*.malicious.com",
  "description": "Malicious domain"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Pattern added successfully"
}
```

**Error Cases**:
- 400: Pattern is required
- 400: Pattern already exists (duplicate)
- 401: Unauthorized

### DELETE `/api/admin/url-blacklist.php?id=<id>`
**Authentication Required**: Bearer token in Authorization header

**Response**:
```json
{
  "success": true,
  "message": "Pattern deleted successfully"
}
```

**Error Cases**:
- 400: ID is required
- 401: Unauthorized

## Testing Examples

### Test Public Message with URL
```bash
curl -X POST http://localhost:98/api/send.php \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Check out http://bit.ly/test123 for more info",
    "username": "TestUser",
    "age": "25",
    "sex": "M",
    "location": "Earth"
  }'
```

**Result**: `"Check out *** for more info"`

### Test Dangerous Content
```bash
curl -X POST http://localhost:98/api/send.php \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Test <script>alert(1)</script> message",
    "username": "TestUser",
    "age": "25",
    "sex": "M",
    "location": "Earth"
  }'
```

**Result**: `"Test *** message"`

### Test Phone Number Filtering
```bash
curl -X POST http://localhost:98/api/send.php \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Call me at 555-123-4567 or email",
    "username": "TestUser",
    "age": "25",
    "sex": "M",
    "location": "Earth"
  }'
```

**Result**: `"Call me at *** or email"`

### Add Blacklist Pattern
```bash
curl -X POST http://localhost:98/api/admin/url-blacklist.php \
  -H "Authorization: Bearer password" \
  -H "Content-Type: application/json" \
  -d '{
    "pattern": "*.spam.com",
    "description": "Known spam domain"
  }'
```

### Get All Patterns
```bash
curl http://localhost:98/api/admin/url-blacklist.php \
  -H "Authorization: Bearer password"
```

### Delete Pattern
```bash
curl -X DELETE "http://localhost:98/api/admin/url-blacklist.php?id=4" \
  -H "Authorization: Bearer password"
```

## Files Modified/Created

### New Files
- `/public/api/admin/url-blacklist.php` - API endpoint for CRUD operations
- `/database/migrations/003_add_url_blacklist.sql` - Database migration
- `/docs/URL_BLACKLIST.md` - This documentation

### Modified Files
- `/src/MessageFilter.php` - Added replacement-based filtering
- `/public/api/send.php` - Integrated message filtering
- `/public/api/private-message.php` - Integrated message filtering
- `/public/admin.html` - Added URL Blacklist management tab

## Security Considerations

1. **Admin Authentication**: All blacklist management requires admin Bearer token
2. **SQL Injection Protection**: Uses prepared statements with PDO
3. **Duplicate Prevention**: Unique constraint on pattern column
4. **Content Replacement**: Dangerous content is replaced, not rejected (better UX)
5. **Private Message Safety**: Blacklisted URLs still blocked in private chats

## Future Enhancements

Potential improvements:
1. Pattern validation (regex syntax checking)
2. Import/export blacklist
3. Pattern categories (malware, spam, adult, etc.)
4. Whitelist exceptions
5. User-level URL blocking preferences
6. Bulk pattern operations
7. Pattern usage statistics
8. Auto-discovery of malicious URLs
