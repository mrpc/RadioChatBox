<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="page-title">RadioChatBox - Live Chat</title>
    <meta name="description" id="meta-description" content="Real-time chat for radio shows">
    <meta name="keywords" id="meta-keywords" content="radio, chat, live">
    <meta name="author" id="meta-author" content="">
    <meta property="og:title" id="og-title" content="RadioChatBox">
    <meta property="og:description" id="og-description" content="Real-time chat for radio shows">
    <meta property="og:type" id="og-type" content="website">
    <meta property="og:image" id="og-image" content="">
    <link rel="icon" id="favicon" type="image/svg+xml" href="/favicon.svg">
    <!-- Installable web app (Add to Home Screen). No service worker: avoids
         stale-asset caching risk while still enabling a home-screen launch. -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#667eea">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RadioChat">
    <link rel="apple-touch-icon" href="/favicon.svg">
    <!-- CSS with automatic cache-busting version based on file modification time -->
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(PUBLIC_PATH . '/css/style.css'); ?>">
    <!-- Twemoji library for emoji support on older Windows versions -->
    <script src="https://cdn.jsdelivr.net/npm/@twemoji/api@latest/dist/twemoji.min.js" crossorigin="anonymous"></script>
    <!-- Custom header scripts will be injected here -->
    <script id="custom-header-scripts"></script>
</head>
<body>
    <a href="#messages" class="skip-to-content">Skip to messages</a>
    <!-- Nickname Selection Modal -->
    <div id="nickname-modal" class="modal">
        <div class="modal-content">
            <h2>Welcome to Live Chat!</h2>

            <!-- Toggle between guest and login -->
            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <button id="guest-mode-btn" class="mode-toggle-btn active" style="flex: 1; padding: 10px; border: 2px solid #667eea; background: #667eea; color: white; border-radius: 6px; cursor: pointer; font-weight: bold;">
                    Join as Guest
                </button>
                <button id="login-mode-btn" class="mode-toggle-btn" style="flex: 1; padding: 10px; border: 2px solid #667eea; background: transparent; color: #667eea; border-radius: 6px; cursor: pointer; font-weight: bold;">
                    Login
                </button>
                <button id="register-mode-btn" class="mode-toggle-btn" style="flex: 1; padding: 10px; border: 2px solid #667eea; background: transparent; color: #667eea; border-radius: 6px; cursor: pointer; font-weight: bold; display: none;">
                    Register
                </button>
            </div>

            <!-- Guest Mode Form -->
            <div id="guest-form">
                <p>Please enter your details to get started:</p>
                <input
                    type="text"
                    id="nickname-input"
                    placeholder="Enter your nickname"
                    maxlength="50"
                    autocomplete="off"
                    required
                >
                <div id="profile-fields" style="display: none;">
                    <select id="age-input" required></select>
                        <script>
                        // Populate age select (18-90) for registration
                        document.addEventListener('DOMContentLoaded', function() {
                            var ageSelect = document.getElementById('age-input');
                            if (ageSelect) {
                                ageSelect.innerHTML = '<option value="">Select Age</option>';
                                for (var i = 18; i <= 90; i++) {
                                    ageSelect.innerHTML += '<option value="' + i + '">' + i + '</option>';
                                }
                            }
                        });
                        </script>
                    <select id="sex-input" required>
                        <option value="">Select Sex</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                    <select id="location-input" required>
                        <option value="">Select Country</option>
                    </select>
                </div>
                <div id="nickname-error" class="error-message"></div>
                <button id="nickname-submit">Join Chat</button>
            </div>

            <!-- Login Form -->
            <div id="login-form" style="display: none;">
                <p>Login with your registered account:</p>
                <input
                    type="text"
                    id="login-username-input"
                    placeholder="Username"
                    maxlength="50"
                    autocomplete="username"
                    required
                >
                <input
                    type="password"
                    id="login-password-input"
                    placeholder="Password"
                    autocomplete="current-password"
                    required
                >
                <div id="login-error" class="error-message"></div>
                <button id="login-submit">Login & Join Chat</button>
            </div>

            <!-- Register Form (shown only when self-registration is enabled) -->
            <div id="register-form" style="display: none;">
                <p>Create an account:</p>
                <input type="text" id="register-username-input" placeholder="Username" maxlength="50" autocomplete="username" required>
                <input type="email" id="register-email-input" placeholder="Email (optional)" autocomplete="email">
                <input type="password" id="register-password-input" placeholder="Password (min 8 chars)" autocomplete="new-password" required>
                <input type="password" id="register-password2-input" placeholder="Confirm password" autocomplete="new-password" required>
                <div id="register-error" class="error-message"></div>
                <button id="register-submit">Create account & Join</button>
            </div>
        </div>
    </div>

    <!-- Profile Settings Modal -->
    <div id="profile-modal" class="modal">
        <div class="modal-content">
            <h2>Profile Settings</h2>
            <p>Update your profile information:</p>

            <div class="profile-info">
                <label>Nickname</label>
                <input
                    type="text"
                    id="profile-nickname"
                    readonly
                    class="readonly-field"
                >
            </div>

            <!-- Display name - always available for authenticated users -->
            <div id="profile-display-name-field" class="profile-info" style="display: none;">
                <label>Display Name (Optional)</label>
                <input
                    type="text"
                    id="profile-display-name"
                    placeholder="Leave empty to use nickname"
                    maxlength="100"
                >
                <small style="color: #999;">This is the name others will see in chat</small>
            </div>

            <div id="profile-edit-fields" style="display: none;">
                <div class="profile-info">
                    <label>Age</label>
                    <input
                        type="number"
                        id="profile-age"
                        min="18"
                        max="120"
                        required
                    >
                </div>

                <div class="profile-info">
                    <label>Sex</label>
                    <select id="profile-sex" required>
                        <option value="">Select Sex</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <div class="profile-info">
                    <label>Country</label>
                    <select id="profile-location" required>
                        <option value="">Select Country</option>
                    </select>
                </div>

                <div class="profile-info">
                    <label>Status (optional)</label>
                    <input type="text" id="profile-status" maxlength="120" placeholder="e.g. Listening in from Athens 🎧">
                </div>

                <div class="profile-info">
                    <label>Bio (optional)</label>
                    <textarea id="profile-bio" maxlength="300" rows="2" placeholder="A few words about you"></textarea>
                </div>
            </div>

            <div class="profile-info" style="border-top:1px solid #eee; padding-top:12px; margin-top:6px;">
                <label style="font-weight:600;">Notifications</label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-top:6px;">
                    <input type="checkbox" id="pref-dnd" style="width:auto;"> Do Not Disturb (mute all sounds &amp; alerts)
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-top:6px;">
                    <input type="checkbox" id="pref-reaction-toasts" style="width:auto;"> Show reaction notifications
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-top:6px;">
                    Notification sound
                    <select id="pref-sound-style" style="width:auto; padding:2px 6px;">
                        <option value="beep">Beep</option>
                        <option value="ding">Ding</option>
                        <option value="pop">Pop</option>
                        <option value="chime">Chime</option>
                    </select>
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-top:6px;">
                    <input type="checkbox" id="pref-dark-mode" style="width:auto;"> Dark mode 🌙
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-top:6px;">
                    <input type="checkbox" id="pref-high-contrast" style="width:auto;"> High contrast
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-top:6px;">
                    Text size
                    <select id="pref-font-size" style="width:auto; padding:2px 6px;">
                        <option value="small">Small</option>
                        <option value="normal">Normal</option>
                        <option value="large">Large</option>
                    </select>
                </label>
            </div>

            <div id="profile-error" class="error-message"></div>

            <div class="modal-buttons">
                <button id="profile-save-displayname" class="btn-primary" style="display: none;">Save Display Name</button>
                <button id="profile-save" class="btn-primary" style="display: none;">Save Profile</button>
                <button id="profile-logout" class="btn-danger">Logout</button>
                <button id="profile-close" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <div id="app-container">
        <!-- Sidebar with active users -->
        <div id="sidebar">
            <div id="sidebar-header">
                <h3>Active Users</h3>
                <button id="sidebar-toggle" class="sidebar-toggle-btn" title="Toggle sidebar">
                    <span id="sidebar-toggle-icon">◀</span>
                </button>
            </div>
            <div id="sidebar-content" style="display: flex; flex-direction: column; height: 100%; min-height: 0;">
                <div id="active-users-count-container">
                    <span id="active-users-count">0</span> online
                </div>
                <div id="active-users-list" style="flex: 1 1 auto; min-height: 0; overflow-y: auto; height: auto;"></div>
            </div>
        </div>

        <!-- Main chat area -->
        <div id="chat-container">
            <div id="chat-header">
                <div id="brand-logo-container" style="display: none;">
                    <img id="brand-logo" src="" alt="Logo" style="max-height: 50px; max-width: 200px; margin-right: 10px;">
                </div>
                <img id="now-playing-cover" alt="" title="Now playing — click to enlarge" style="display: none;">
                <h1><span id="mic-logo" style="display: none;">🎙️ </span>Live Chat <span id="now-playing" class="now-playing" style="display: none;"></span></h1>
                <button id="charts-button" class="icon-button" title="Top charts" style="display: none;">📊</button>
                <span id="track-vote" class="track-vote" style="display: none;" title="Rate the current track">
                    <button id="tv-up" class="tv-btn" type="button" aria-label="Thumbs up">👍 <span id="tv-up-count">0</span></button>
                    <button id="tv-down" class="tv-btn" type="button" aria-label="Thumbs down">👎 <span id="tv-down-count">0</span></button>
                </span>
                <div id="user-info">
                    <button id="sidebar-toggle-mobile" class="icon-button" title="Active Users">
                        👥
                    </button>
                    <span id="current-username"></span>
                    <button id="conversations-toggle" class="icon-button" title="Private Conversations">
                        💬
                        <span id="unread-badge" class="badge" style="display: none;">0</span>
                    </button>
                    <button id="notifications-toggle" class="icon-button" title="Notifications" aria-label="Notifications" style="position:relative;">📥<span id="notif-badge" class="badge" style="display:none;">0</span></button>
                    <button id="schedule-toggle" class="icon-button" title="Show schedule" aria-label="Show schedule">📅</button>
                    <button id="search-toggle" class="icon-button" title="Search messages" aria-label="Search messages">🔍</button>
                    <button id="sound-toggle" class="icon-button" title="Sound On">🔔</button>
                    <button id="change-nickname" class="icon-button" title="Profile & Settings">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M12 1v6m0 6v6m5.2-14.2l-4.2 4.2m0 6l-4.2 4.2M23 12h-6m-6 0H1m14.2 5.2l-4.2-4.2m0-6l-4.2-4.2" />
                        </svg>
                    </button>
                    <button id="admin-panel-btn" class="icon-button" title="Admin Panel" style="display: none;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <path d="M9 3v18M15 3v18M3 9h18M3 15h18" />
                        </svg>
                    </button>
                </div>
                <div id="status">
                    <span id="status-indicator" class="status-connecting"></span>
                    <span id="status-text">Connecting...</span>
                </div>
            </div>

            <!-- In-chat radio player bar (shown when player_mode resolves to on for
                 this embed). When shown it replaces the header now-playing widget. -->
            <div id="radio-player-bar" style="display: none;">
                <div class="rp-cover">
                    <img id="rp-cover-img" alt="" style="display: none;">
                    <span id="rp-cover-ph" class="rp-cover-ph">🎵</span>
                </div>
                <button id="rp-play" class="rp-play" title="Play" aria-label="Play">▶</button>
                <div class="rp-meta">
                    <div class="rp-label">Now Playing</div>
                    <div class="rp-title" id="rp-title">Live Radio</div>
                </div>
                <div class="rp-right">
                    <button id="rp-vol-icon" class="rp-vol-icon" title="Mute" aria-label="Mute">🔊</button>
                    <input type="range" id="rp-volume" class="rp-volume" min="0" max="100" value="80" title="Volume" aria-label="Volume">
                    <span class="rp-live"><span class="rp-live-dot"></span>Live</span>
                </div>
                <audio id="rp-audio" preload="none"></audio>
            </div>

            <div id="messages-container">
                <!-- Upcoming shows overlay (toggled from the header 📅) -->
                <div id="schedule-panel" style="display: none;">
                    <div class="search-bar">
                        <strong style="flex:1;">📅 Upcoming shows</strong>
                        <a href="/api/shows/ical" title="Subscribe / add to calendar" style="font-size:12px; text-decoration:none; margin-right:8px;">📆 iCal</a>
                        <button id="schedule-close" class="icon-button" title="Close">✕</button>
                    </div>
                    <div id="schedule-list"></div>
                    <div id="shoutouts-block"></div>
                </div>
                <!-- Notifications inbox overlay (toggled from the header 📥) -->
                <div id="notifications-panel" style="display: none;">
                    <div class="search-bar">
                        <strong style="flex:1;">🔔 Notifications</strong>
                        <button id="notif-mark-all" class="icon-button" title="Mark all read" style="font-size:12px;">Mark all read</button>
                        <button id="notifications-close" class="icon-button" title="Close">✕</button>
                    </div>
                    <div id="notifications-list"></div>
                </div>
                <!-- Message search overlay (toggled from the header 🔍) -->
                <div id="search-panel" style="display: none;">
                    <div class="search-bar">
                        <input type="text" id="search-input" placeholder="Search public messages…" autocomplete="off" aria-label="Search public messages">
                        <button id="search-close" class="icon-button" title="Close search">✕</button>
                    </div>
                    <div id="search-results"></div>
                </div>
                <!-- Moderator-pinned messages (shown when there are active pins) -->
                <div id="pinned-bar" class="pinned-bar" style="display: none;"></div>
                <!-- Live poll (shown when there is an active poll and polls are on) -->
                <div id="poll-widget" class="poll-widget" style="display: none;"></div>
                <div id="private-chat-header" style="display: none;">
                    <span id="private-chat-with"></span>
                    <button id="gallery-btn" class="btn" style="display: none;">🖼️ Gallery</button>
                    <button id="block-user-btn" class="btn btn-block" style="display: none;">🚫 Block</button>
                    <button id="back-to-public" class="btn">← Back to Public Chat</button>
                </div>
                <div id="messages" role="log" aria-live="polite" aria-label="Chat messages"></div>
                <button id="scroll-to-bottom" title="Scroll to bottom">↓</button>
            </div>

            <div id="conversations-panel" style="display: none;">
                <div class="conversations-header">
                    <h3>💬 Private Conversations</h3>
                    <button id="close-conversations" class="close-btn">✕</button>
                </div>
                <div id="conversations-list"></div>
            </div>

            <div id="typing-indicator" class="typing-indicator" style="display: none;"></div>

            <div id="chat-input-container">
                <button id="emoji-button" class="emoji-toggle-btn" title="Emojis">😊</button>
                <button id="gif-button" class="gif-toggle-btn" title="GIFs" style="display: none;">GIF</button>
                <button id="photo-button" class="photo-btn" title="Upload Photo" style="display: none;">📷</button>
                <button id="pin-track-button" class="pin-track-btn" title="Attach the current track to your message" style="display: none;">🎵</button>
                <button id="song-request-button" class="song-request-btn" title="Request a song" style="display: none;">📻</button>
                <input
                    type="text"
                    id="message-input"
                    placeholder="Type your message..."
                    maxlength="500"
                    autocomplete="off"
                    inputmode="text"
                    data-disable-emoji="true"
                >
                <button id="send-button">Send</button>
                <input type="file" id="photo-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
            </div>

            <div id="emoji-picker" style="display: none;">
                <div class="emoji-categories">
                    <button class="emoji-category active" data-category="smileys">😀</button>
                    <button class="emoji-category" data-category="gestures">👋</button>
                    <button class="emoji-category" data-category="hearts">❤️</button>
                    <button class="emoji-category" data-category="animals">🐶</button>
                    <button class="emoji-category" data-category="food">🍕</button>
                    <button class="emoji-category" data-category="activities">⚽</button>
                    <button class="emoji-category" data-category="travel">✈️</button>
                    <button class="emoji-category" data-category="objects">💡</button>
                </div>
                <div id="emoji-grid"></div>
            </div>

            <div id="gif-picker" style="display: none;">
                <div class="gif-search-container">
                    <input type="text" id="gif-search-input" placeholder="Search GIFs..." autocomplete="off">
                </div>
                <div id="gif-grid"></div>
                <div id="gif-loading" style="display: none;">Loading...</div>
            </div>
        </div>
    </div>

    <!-- Top charts panel (opened by the 📊 button; only when charts_enabled) -->
    <div id="charts-overlay" class="charts-overlay" style="display: none;">
        <div class="charts-modal" role="dialog" aria-modal="true" aria-label="Top charts">
            <div class="charts-modal-head">
                <h3>📊 Top Charts</h3>
                <button id="charts-close" class="charts-close" title="Close" aria-label="Close">✕</button>
            </div>
            <div class="charts-tabs">
                <div class="charts-periods">
                    <button class="charts-period active" data-period="day">Day</button>
                    <button class="charts-period" data-period="week">Week</button>
                    <button class="charts-period" data-period="month">Month</button>
                </div>
            </div>
            <div class="charts-body">
                <div class="charts-col">
                    <h4>Top Tracks</h4>
                    <ol id="charts-tracks" class="charts-list"></ol>
                </div>
                <div class="charts-col">
                    <h4>Top Artists</h4>
                    <ol id="charts-artists" class="charts-list"></ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Song request modal (opened by the 📻 button; only when song_requests_enabled) -->
    <div id="song-request-overlay" class="charts-overlay" style="display: none;">
        <div class="charts-modal sr-modal" role="dialog" aria-modal="true" aria-label="Request a song">
            <div class="charts-modal-head">
                <h3>📻 Request a Song</h3>
                <button id="song-request-close" class="charts-close" title="Close" aria-label="Close">✕</button>
            </div>
            <form id="song-request-form" class="sr-form">
                <div class="sr-field">
                    <label for="sr-song-title">Song <span class="sr-req">*</span></label>
                    <input type="text" id="sr-song-title" maxlength="300" placeholder="Song title" required autocomplete="off">
                </div>
                <div class="sr-field">
                    <label for="sr-artist">Artist</label>
                    <input type="text" id="sr-artist" maxlength="300" placeholder="Artist (optional)" autocomplete="off" list="sr-artist-list">
                    <datalist id="sr-artist-list"></datalist>
                </div>
                <div class="sr-field">
                    <label for="sr-dedication">Dedication / shout-out</label>
                    <textarea id="sr-dedication" maxlength="500" rows="2" placeholder="Optional message to read on air"></textarea>
                </div>
                <div id="sr-error" class="sr-error" style="display: none;"></div>
                <div class="sr-actions">
                    <button type="submit" id="sr-submit" class="btn">Send request</button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/countries.js?v=<?php echo filemtime(PUBLIC_PATH . '/js/countries.js'); ?>"></script>
    <script src="js/emojis.js?v=<?php echo filemtime(PUBLIC_PATH . '/js/emojis.js'); ?>"></script>
    <script src="js/analytics.js?v=<?php echo filemtime(PUBLIC_PATH . '/js/analytics.js'); ?>"></script>
    <script src="js/ads.js?v=<?php echo filemtime(PUBLIC_PATH . '/js/ads.js'); ?>"></script>
    <script src="js/chat.js?v=<?php echo filemtime(PUBLIC_PATH . '/js/chat.js'); ?>"></script>
    <!-- Custom body scripts will be injected here -->
    <script id="custom-body-scripts"></script>
</body>
</html>
