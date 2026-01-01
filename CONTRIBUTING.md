# Contributing to RadioChatBox

Thank you for your interest in contributing to RadioChatBox! We welcome contributions from the community.

## How to Contribute

### Reporting Bugs

If you find a bug, please open an issue on GitHub with:
- Clear description of the problem
- Steps to reproduce
- Expected vs actual behavior
- Your environment (OS, PHP version, browser)
- Screenshots if applicable

### Suggesting Features

Feature requests are welcome! Please open an issue describing:
- The problem you're trying to solve
- Your proposed solution
- Any alternatives you've considered
- Why this would be useful to others

### Pull Requests

1. **Fork the repository** and create your branch from `main`
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes**
   - Write clear, commented code
   - Follow existing code style
   - Add tests for new functionality
   - Update documentation as needed

3. **Test your changes**
   ```bash
   ./test.sh
   ```

4. **Commit with clear messages**
   ```bash
   git commit -m "Add feature: describe what you did"
   ```

5. **Push and create a PR**
   ```bash
   git push origin feature/your-feature-name
   ```

## Development Setup

1. Clone your fork:
   ```bash
   git clone https://github.com/mrpc/RadioChatBox.git
   cd RadioChatBox
   ```

2. Set up development environment:
   ```bash
   cp .env.example .env
   ./start.sh
   ```

3. Install dev dependencies:
   ```bash
   docker exec radiochatbox_apache composer install --dev
   ```

## Code Style

- **PHP**: PSR-12 coding standard
- **JavaScript**: Use consistent indentation (2 spaces)
- **SQL**: Uppercase keywords, snake_case for identifiers
- **Comments**: Write clear comments for complex logic

## Testing

- Write PHPUnit tests for new backend functionality
- Test in multiple browsers for frontend changes
- Verify both desktop and mobile layouts
- Test with rate limiting enabled

## Documentation

- Update README.md for new features
- Add API endpoints to docs/openapi.yaml
- Comment complex code sections
- Update inline help text in admin panel

## Admin Panel Development

The admin panel is located at `/public/admin/index.html` and accessible at `/admin/` thanks to Apache's directory index handling.

### Key Features:
- **Tabbed navigation** with browser back/forward support
- **URL-based routing** using hash navigation (e.g., `/admin#messages`)
- **Responsive design** for desktop and mobile
- **Statistics dashboard** with hourly, daily, weekly, monthly, yearly views
- **User management** - create, edit, delete admin accounts
- **Moderation tools** - IP bans, nickname bans, URL filtering
- **Active/inactive user tracking** with detailed profiles

### Admin Routes:
All endpoints require authentication via `AdminAuth::verify()`:
- `/api/admin/stats.php` - Statistics retrieval (GET) and recording (POST)
- `/api/admin/messages.php` - Message management
- `/api/admin/active-users.php` - Active user listing
- `/api/admin/ban-*.php` - Ban management endpoints
- `/api/admin/fake-users.php` - Fake user management
- See `public/api/admin/` for complete list

### Adding New Admin Features:

1. **Create API endpoint** in `public/api/admin/your-feature.php`:
   ```php
   require_once __DIR__ . '/../../vendor/autoload.php';
   
   use RadioChatBox\AdminAuth;
   AdminAuth::verify();  // Requires authentication
   
   // Your endpoint logic
   ```

2. **Add UI section** to `public/admin/index.html`:
   ```html
   <!-- Add new tab -->
   <div class="nav-item" onclick="switchTab('your-feature')" data-tab="your-feature">
       Your Feature
   </div>
   
   <!-- Add content div -->
   <div id="your-feature-tab" class="tab-content">
       <!-- Your UI here -->
   </div>
   ```

3. **Add JavaScript function** to handle loading:
   ```javascript
   async function loadYourFeature() {
       const response = await fetch('/api/admin/your-feature.php', {
           headers: { 'Authorization': `Bearer ${authToken}` }
       });
       // Process and display
   }
   ```

4. **Update `switchTab()` function** to initialize on tab selection:
   ```javascript
   } else if (tabName === 'your-feature') {
       loadYourFeature();
   ```

## Code of Conduct

- Be respectful and inclusive
- Welcome newcomers
- Focus on constructive feedback
- Help others learn and grow

## Questions?

Feel free to open a GitHub Discussion or issue if you have questions about contributing!

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
