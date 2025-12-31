/**
 * RadioChatBox - Client-side JavaScript
 * Real-time chat using Server-Sent Events (SSE)
 */

class RadioChatBox {
        // ...existing methods...
    constructor(apiUrl = '') {
            // Store original title for notification reset
            this.originalTitle = document.title;
            this.titleNotificationActive = false;
            this.titleNotificationTimeout = null;
            // Listen for tab focus to clear notification
            window.addEventListener('focus', () => this.clearTitleNotification());
        this.apiUrl = apiUrl;
        this.eventSource = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 999; // Essentially unlimited
        this.reconnectDelay = 2000; // Start with 2 second delay
        this.maxReconnectDelay = 30000; // Max 30 seconds between retries
        this.sessionId = this.getOrCreateSessionId();
        this.username = null;
        this.lastMessageId = null; // Track last received message ID for catch-up on reconnect
        this.heartbeatInterval = null;
        this.soundEnabled = localStorage.getItem('chatSoundEnabled') !== 'false'; // default to true
        this.chatMode = 'both'; // Default chat mode, will be updated from server
        this.isEmbedded = window.self !== window.top; // Detect if in iframe
        this.isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth <= 768;
        
        // Check URL parameters for embedded mode settings
        const urlParams = new URLSearchParams(window.location.search);
        const audioParam = urlParams.get('audio');
        
        // If audio parameter is explicitly set in URL, use it
        if (audioParam !== null) {
            this.soundEnabled = audioParam === 'true' || audioParam === '1';
            localStorage.setItem('chatSoundEnabled', this.soundEnabled);
        }
        
        // Apply embedded mode class if needed
        if (this.isEmbedded) {
            document.body.classList.add('embedded-mode');
        }
        
        // Private chat state
        this.privateChat = {
            active: false,
            withUser: null,
            messages: []
        };
        
        // Reply state
        this.replyState = {
            active: false,
            messageId: null,
            username: null,
            message: null
        };
        
        // Conversations tracking
        this.conversations = new Map(); // Map of username -> { lastMessage, unreadCount, timestamp }
        this.conversationsPanelOpen = false;

        // DOM elements (will be initialized after nickname selection)
        this.messagesContainer = null;
        this.messageInput = null;
        this.sendButton = null;
        this.statusIndicator = null;
        this.statusText = null;
        this.activeUsersContainer = null;
        this.activeUsersCount = null;

        this.init();
    }

    init() {
        // Load settings first
        this.loadSettings().then(() => {
            this.proceedWithNormalLogin();
        });
        
        // Listen for storage changes (detect when admin logs out)
        this.setupStorageListener();
        
        // Listen for page visibility changes to detect when returning from admin panel
        document.addEventListener('visibilitychange', () => this.handleVisibilityChange());
    }
    
    handleVisibilityChange() {
        // When page becomes visible, check if we've been logged out from admin
        if (!document.hidden && this.username) {
            // Check if adminToken was cleared (user logged out from admin)
            const adminToken = localStorage.getItem('adminToken');
            const wasLoggedInAsAdmin = this.userRole && ['root', 'administrator', 'moderator'].includes(this.userRole);
            
            if (wasLoggedInAsAdmin && !adminToken) {
                // Admin token was cleared, logout from chat too
                this.logoutUser();
            }
        }
    }
    
    setupStorageListener() {
        // Listen for storage changes from other tabs/windows
        window.addEventListener('storage', (e) => {
            if (e.key === 'adminToken' && e.newValue === null && this.username) {
                // Admin token was cleared in another tab/window
                this.logoutUser();
            }
        });
    }
    
    logoutUser() {
        console.log('Logging out user:', this.username);
        
        // Clear user data
        this.username = null;
        this.userId = null;
        this.userRole = null;
        this.setStorage('chatNickname', null);
        this.setStorage('userId', null);
        this.setStorage('chatAge', null);
        this.setStorage('chatLocation', null);
        this.setStorage('chatSex', null);
        
        // Close any active connections
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
        
        // Stop heartbeat
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
        
        // Close any active chats
        if (this.privateChat.active) {
            this.closePrivateChat();
        }
        
        // Hide main chat interface
        const appContainer = document.getElementById('app-container');
        if (appContainer) {
            appContainer.style.display = 'none';
        }
        
        // Show nickname modal (triggers login flow again)
        this.showNicknameModal();
    }
    
    async proceedWithNormalLogin() {
        // Check if user has a saved nickname
        const savedNickname = this.getStorage('chatNickname');
        const savedUserId = this.getStorage('userId');
        
        if (savedNickname) {
            // Check if this is an authenticated user (has userId from login)
            if (savedUserId) {
                // Authenticated user - verify session is still valid on server
                try {
                    const response = await fetch(`${this.apiUrl}/api/heartbeat.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            username: savedNickname,
                            sessionId: this.sessionId
                        })
                    });
                    
                    const data = await response.json();
                    
                    // Check if session is still linked to this user
                    if (response.ok && data.success && data.user_id == savedUserId) {
                        // Session is valid and still linked to this user
                        this.username = savedNickname;
                        this.userId = savedUserId;
                        this.userRole = this.getStorage('userRole');
                        
                        // Get profile data if available
                        const savedAge = this.getStorage('chatAge');
                        const savedLocation = this.getStorage('chatLocation');
                        const savedSex = this.getStorage('chatSex');
                        this.userProfile = { age: savedAge, location: savedLocation, sex: savedSex };
                        
                        this.initializeChat();
                        
                        // Force update now playing for admin users to show listener count
                        if (this.userRole && ['root', 'administrator', 'moderator'].includes(this.userRole)) {
                            // Wait a bit for DOM to be ready, then force a fresh fetch
                            setTimeout(() => {
                                const el = document.getElementById('now-playing');
                                if (el && el.style.display !== 'none') {
                                    this.updateNowPlaying();
                                }
                            }, 200);
                        }
                        return;
                    } else {
                        // Session invalid or user_id mismatch - clear stored data and show login
                        console.warn('Session validation failed - user_id mismatch or session expired');
                        this.setStorage('chatNickname', null);
                        this.setStorage('userId', null);
                        this.setStorage('userRole', null);
                        this.showNicknameModal();
                        return;
                    }
                } catch (error) {
                    console.error('Session validation failed:', error);
                    // On error, fall through to normal login flow
                    this.setStorage('chatNickname', null);
                    this.setStorage('userId', null);
                    this.showNicknameModal();
                    return;
                }
            }
            
            // Guest user - go through normal registration flow
            // Try to get saved profile data
            const savedAge = this.getStorage('chatAge');
            const savedLocation = this.getStorage('chatLocation');
            const savedSex = this.getStorage('chatSex');
            
            // Check if profile is required
            const requireProfile = this.settings?.require_profile === 'true';
            
            if (requireProfile && (!savedAge || !savedLocation || !savedSex)) {
                // Profile is required but we don't have complete data - show modal
                this.showNicknameModal(savedNickname);
            } else {
                // Either profile not required, or we have complete data
                // Always send profile data if available (even if not required)
                this.checkAndRegisterNickname(savedNickname, savedAge, savedLocation, savedSex);
            }
        } else {
            // Show nickname selection modal
            this.showNicknameModal();
        }
    }
    
    async loadSettings() {
        try {
            const response = await fetch(`${this.apiUrl}/api/settings.php?t=${Date.now()}`, {
                cache: 'no-cache'
            });
            const data = await response.json();
            
            if (data.success) {
                this.settings = data.settings;
                
                // Apply SEO meta tags
                if (this.settings.seo) {
                    this.applySeoMeta(this.settings.seo);
                }
                
                // Apply branding
                if (this.settings.branding) {
                    this.applyBranding(this.settings.branding);
                }
                
                // Inject custom scripts
                if (this.settings.scripts) {
                    this.injectCustomScripts(this.settings.scripts);
                }
                
                // Initialize analytics
                if (window.analytics) {
                    await window.analytics.init(this.settings);
                    window.analytics.trackSessionStart();
                    window.analytics.trackPageView('chat');
                }
                
                // Initialize ad manager
                if (window.adManager) {
                    window.adManager.init(this.settings);
                }
                
                // Update chat mode from settings
                if (this.settings.chat_mode) {
                    this.chatMode = this.settings.chat_mode;
                }
                
                // Update page title if set
                if (this.settings.page_title) {
                    document.title = this.settings.page_title;
                }
                
                // Apply color scheme
                if (this.settings.color_scheme) {
                    console.log('Applying color scheme from settings:', this.settings.color_scheme); // Debug
                    this.applyColorScheme(this.settings.color_scheme);
                } else {
                    console.log('No color_scheme in settings, using default'); // Debug
                }
                
                // Show profile fields if required
                if (this.settings.require_profile === 'true') {
                    const profileFields = document.getElementById('profile-fields');
                    if (profileFields) {
                        profileFields.style.display = 'block';
                        this.populateCountryDropdown();
                    }
                }
                
                // Update photo upload button visibility
                this.updatePhotoButtonVisibility();

                // Initialize now playing polling if configured
                this.initNowPlaying();
            }
        } catch (error) {
            console.error('Failed to load settings:', error);
            this.settings = {};
        }
    }
    
    applyColorScheme(scheme) {
        // Remove existing scheme classes
        document.body.classList.remove('dark-theme', 'light-theme', 'metal-theme');
        
        // Add new scheme class
        if (scheme === 'light') {
            document.body.classList.add('light-theme');
        } else if (scheme === 'metal') {
            document.body.classList.add('metal-theme');
        } else {
            document.body.classList.add('dark-theme');
        }
    }
    
    updatePhotoButtonVisibility() {
        if (this.photoButton) {
            const photosAllowed = this.settings.allow_photo_uploads !== 'false';
            const inPrivateChat = this.privateChat.active;
            
            // Only show photo button in private chat when allowed
            this.photoButton.style.display = (photosAllowed && inPrivateChat) ? 'block' : 'none';
        }
    }
    
    applySeoMeta(seo) {
        // Update title
        if (seo.title) {
            document.title = seo.title;
            const titleEl = document.getElementById('page-title');
            if (titleEl) titleEl.textContent = seo.title;
            const ogTitle = document.getElementById('og-title');
            if (ogTitle) ogTitle.setAttribute('content', seo.title);
        }
        
        // Update meta description
        if (seo.description) {
            const desc = document.getElementById('meta-description');
            if (desc) desc.setAttribute('content', seo.description);
            const ogDesc = document.getElementById('og-description');
            if (ogDesc) ogDesc.setAttribute('content', seo.description);
        }
        
        // Update meta keywords
        if (seo.keywords) {
            const keywords = document.getElementById('meta-keywords');
            if (keywords) keywords.setAttribute('content', seo.keywords);
        }
        
        // Update author
        if (seo.author) {
            const author = document.getElementById('meta-author');
            if (author) author.setAttribute('content', seo.author);
        }
        
        // Update OG image
        if (seo.og_image) {
            const ogImage = document.getElementById('og-image');
            if (ogImage) ogImage.setAttribute('content', seo.og_image);
        }
        
        // Update OG type
        if (seo.og_type) {
            const ogType = document.getElementById('og-type');
            if (ogType) ogType.setAttribute('content', seo.og_type);
        }
    }
    
    applyBranding(branding) {
        // Update favicon
        if (branding.favicon_url) {
            const favicon = document.getElementById('favicon');
            if (favicon) favicon.setAttribute('href', branding.favicon_url);
        }
        
        // Update brand color (can be used for theme)
        if (branding.color) {
            document.documentElement.style.setProperty('--brand-color', branding.color);
        }
        
        // Display logo if available
        if (branding.logo_url) {
            this.brandLogoUrl = branding.logo_url;
            const logoContainer = document.getElementById('brand-logo-container');
            const logoImg = document.getElementById('brand-logo');
            if (logoContainer && logoImg) {
                logoImg.src = branding.logo_url;
                logoContainer.style.display = 'block';
            }
        }
    }
    
    injectCustomScripts(scripts) {
        // Inject header scripts
        if (scripts.header) {
            const headerScriptEl = document.getElementById('custom-header-scripts');
            if (headerScriptEl) {
                const scriptContent = scripts.header;
                // Create a new script element to properly execute the code
                const newScript = document.createElement('div');
                newScript.innerHTML = scriptContent;
                headerScriptEl.parentNode.insertBefore(newScript, headerScriptEl.nextSibling);
            }
        }
        
        // Inject body scripts
        if (scripts.body) {
            const bodyScriptEl = document.getElementById('custom-body-scripts');
            if (bodyScriptEl) {
                const scriptContent = scripts.body;
                // Create a new div to hold the scripts
                const newScript = document.createElement('div');
                newScript.innerHTML = scriptContent;
                bodyScriptEl.parentNode.insertBefore(newScript, bodyScriptEl.nextSibling);
                
                // Execute any script tags
                const scriptTags = newScript.querySelectorAll('script');
                scriptTags.forEach(oldScript => {
                    const newScriptTag = document.createElement('script');
                    if (oldScript.src) {
                        newScriptTag.src = oldScript.src;
                    } else {
                        newScriptTag.textContent = oldScript.textContent;
                    }
                    Array.from(oldScript.attributes).forEach(attr => {
                        newScriptTag.setAttribute(attr.name, attr.value);
                    });
                    oldScript.parentNode.replaceChild(newScriptTag, oldScript);
                });
            }
        }
    }

    initNowPlaying() {
        try {
            const urlSet = !!(this.settings && this.settings.radio_status_url && this.settings.radio_status_url.trim() !== '');
            const el = document.getElementById('now-playing');
            if (!urlSet || !el) {
                if (el) el.style.display = 'none';
                return;
            }

            // Immediately fetch once, then poll
            this.updateNowPlaying();
            if (!this._nowPlayingInterval) {
                this._nowPlayingInterval = setInterval(() => this.updateNowPlaying(), 15000);
            }
        } catch (e) {
            // Non-fatal
            console.warn('initNowPlaying error', e);
        }
    }

    async updateNowPlaying() {
        const el = document.getElementById('now-playing');
        if (!el) return;
        try {
            const resp = await fetch(`${this.apiUrl}/api/now-playing.php?t=${Date.now()}`, { cache: 'no-cache' });
            const data = await resp.json();
            if (data && data.success && data.nowPlaying && data.nowPlaying.active && data.nowPlaying.display) {
                el.textContent = `🎵 Now Playing: ${data.nowPlaying.display}`;
                
                // Check if user is admin (also check stored role if userRole not set yet)
                const userRole = this.userRole || this.getStorage('userRole');
                if (userRole && ['root', 'administrator', 'moderator'].includes(userRole)) {
                    if (data.nowPlaying.listeners !== null && data.nowPlaying.listeners !== undefined) {
                        el.title = `${data.nowPlaying.listeners} listener${data.nowPlaying.listeners === 1 ? '' : 's'}`;
                    }
                } else {
                    el.removeAttribute('title');
                }
                
                el.style.display = 'block';
            } else {
                el.style.display = 'none';
            }
        } catch (e) {
            // hide on error
            el.style.display = 'none';
        }
    }
    
    populateCountryDropdown() {
        const locationSelect = document.getElementById('location-input');
        if (!locationSelect || typeof COUNTRIES === 'undefined') return;
        
        // Clear existing options except the first one
        locationSelect.innerHTML = '<option value="">Select Country</option>';
        
        COUNTRIES.forEach(country => {
            const option = document.createElement('option');
            option.value = country.code;
            option.textContent = `${country.flag} ${country.name}`;
            if (country.code === 'GR') {
                option.selected = true;
            }
            locationSelect.appendChild(option);
        });
    }

    getOrCreateSessionId() {
        // Use localStorage first (works in iframes), fallback to cookies
        let sessionId = this.getStorage('chatSessionId');
        if (!sessionId) {
            sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            this.setStorage('chatSessionId', sessionId);
        }
        return sessionId;
    }

    // Modern storage method - uses localStorage first (works in iframes), falls back to cookies
    getStorage(name) {
        // Try localStorage first (works even in iframes when third-party cookies are blocked)
        try {
            const value = localStorage.getItem(name);
            if (value !== null) return value;
        } catch (e) {
            // localStorage not available (rare case)
        }
        
        // Fallback to cookies
        return this.getCookie(name);
    }

    setStorage(name, value) {
        // If value is null, remove the storage item
        if (value === null) {
            this.removeStorage(name);
            return true;
        }
        
        // Try localStorage first
        try {
            localStorage.setItem(name, value);
            // Also set cookie as backup for cross-tab sync
            this.setCookie(name, value, 365);
            return true;
        } catch (e) {
            // localStorage not available or full - use cookies only
            this.setCookie(name, value, 365);
            return false;
        }
    }

    removeStorage(name) {
        // Remove from localStorage
        try {
            localStorage.removeItem(name);
        } catch (e) {
            // Ignore
        }
        
        // Remove cookies from all possible paths
        // Try root path
        document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax`;
        // Try with Secure and Partitioned (for HTTPS)
        if (window.location.protocol === 'https:') {
            document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=None; Secure; Partitioned`;
        }
    }

    getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    setCookie(name, value, days = 365) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        // Use SameSite=None; Secure for third-party iframe context
        // Falls back to SameSite=Lax if not in HTTPS
        const isSecure = window.location.protocol === 'https:';
        const sameSite = isSecure ? 'None; Secure' : 'Lax';
        
        // Add Partitioned attribute for CHIPS (Cookies Having Independent Partitioned State)
        // This allows cookies to work in third-party contexts when properly partitioned
        const partitioned = isSecure ? '; Partitioned' : '';
        
        document.cookie = `${name}=${value}; expires=${expires}; path=/; SameSite=${sameSite}${partitioned}`;
    }

    async checkAndRegisterNickname(nickname, age = null, location = null, sex = null) {
        try {
            // Check if nickname is available
            const checkResponse = await fetch(`${this.apiUrl}/api/check-nickname.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nickname, sessionId: this.sessionId })
            });

            const checkData = await checkResponse.json();

            if (!checkData.available) {
                // Nickname taken, show modal again
                alert('This nickname is already in use. Please choose another one.');
                this.showNicknameModal();
                return;
            }

            // Register the user
            const registerPayload = {
                username: nickname,
                sessionId: this.sessionId
            };
            
            // Add profile data if provided (not null and not empty string)
            if (age !== null && age !== undefined && age !== '') {
                registerPayload.age = age;
            }
            if (location !== null && location !== undefined && location !== '') {
                registerPayload.location = location;
            }
            if (sex !== null && sex !== undefined && sex !== '') {
                registerPayload.sex = sex;
            }
            
            const registerResponse = await fetch(`${this.apiUrl}/api/register.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(registerPayload)
            });

            const registerData = await registerResponse.json();

            if (!registerResponse.ok || !registerData.success) {
                throw new Error(registerData.error || 'Failed to register nickname');
            }

            // Success! Set username and initialize chat
            this.username = nickname;
            this.userProfile = { age, location, sex };
            this.setStorage('chatNickname', nickname);
            
            // Save profile data in storage if provided
            if (age) this.setStorage('chatAge', age);
            if (location) this.setStorage('chatLocation', location);
            if (sex) this.setStorage('chatSex', sex);
            
            // Track user registration
            if (window.analytics) {
                window.analytics.trackUserRegistration(nickname);
            }
            
            this.hideNicknameModal();
            this.initializeChat();

        } catch (error) {
            console.error('Error registering nickname:', error);
            alert(error.message);
            this.showNicknameModal();
        }
    }

    showNicknameModal(prefillNickname = null) {
        const modal = document.getElementById('nickname-modal');
        const nicknameInput = document.getElementById('nickname-input');
        const ageInput = document.getElementById('age-input');
        const locationInput = document.getElementById('location-input');
        const sexInput = document.getElementById('sex-input');
        const nicknameSubmit = document.getElementById('nickname-submit');
        const nicknameError = document.getElementById('nickname-error');
        const profileFields = document.getElementById('profile-fields');
        
        // Setup mode toggle buttons
        this.setupModeToggle();

        // Check if user is logged in as admin and show quick-join option
        const adminToken = localStorage.getItem('adminToken');
        if (adminToken && !prefillNickname) {
            const username = adminToken.split(':')[0];
            if (username) {
                // Create admin quick-join notice
                let adminNotice = document.getElementById('admin-quick-join-notice');
                if (!adminNotice) {
                    adminNotice = document.createElement('div');
                    adminNotice.id = 'admin-quick-join-notice';
                    adminNotice.style.cssText = 'background: #667eea; color: white; padding: 12px; border-radius: 8px; margin-bottom: 15px; text-align: center;';
                    adminNotice.innerHTML = `
                        <p style="margin: 0 0 10px 0; font-size: 14px;">👋 You're logged in as admin <strong>${username}</strong></p>
                        <button id="admin-quick-join-btn" style="background: white; color: #667eea; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px;">
                            Join Chat as ${username}
                        </button>
                        <p style="margin: 10px 0 0 0; font-size: 12px; opacity: 0.9;">or enter a different nickname below</p>
                    `;
                    // Insert at the top of guest-form
                    const guestForm = document.getElementById('guest-form');
                    if (guestForm) {
                        guestForm.insertBefore(adminNotice, guestForm.firstChild);
                    }
                }
                
                // Add click handler for quick-join button
                const quickJoinBtn = document.getElementById('admin-quick-join-btn');
                if (quickJoinBtn) {
                    quickJoinBtn.onclick = async () => {
                        const originalText = quickJoinBtn.textContent;
                        try {
                            quickJoinBtn.disabled = true;
                            quickJoinBtn.textContent = 'Joining...';
                            
                            // Admin usernames are allowed to have multiple sessions
                            // Just proceed with registration
                            await this.checkAndRegisterNickname(username, null, null, null);
                            
                        } catch (error) {
                            console.error('Admin quick-join failed:', error);
                            alert('Failed to join chat. Please try again.');
                            quickJoinBtn.disabled = false;
                            quickJoinBtn.textContent = originalText;
                        }
                    };
                }
            }
        }

        // Show/hide profile fields based on setting
        const requireProfile = this.settings?.require_profile === 'true';
        if (profileFields) {
            profileFields.style.display = requireProfile ? 'block' : 'none';
            if (requireProfile) {
                this.populateCountryDropdown();
            }
        }

        modal.style.display = 'flex';
        
        // Pre-fill nickname if provided
        if (prefillNickname) {
            nicknameInput.value = prefillNickname;
            // Focus on first profile field if profile is required, otherwise nickname
            if (requireProfile && ageInput) {
                ageInput.focus();
            } else {
                nicknameInput.focus();
            }
        } else {
            nicknameInput.focus();
        }

        const submitNickname = async () => {
            const nickname = nicknameInput.value.trim();
            const requireProfile = this.settings?.require_profile === 'true';
            
            if (!nickname) {
                nicknameError.textContent = 'Please enter a nickname';
                return;
            }

            if (nickname.length > 50) {
                nicknameError.textContent = 'Nickname must be 50 characters or less';
                return;
            }
            
            let age = null;
            let location = null;
            let sex = null;
            
            // Validate profile fields if required
            if (requireProfile) {
                age = ageInput ? ageInput.value.trim() : null;
                location = locationInput ? locationInput.value : null;
                sex = sexInput ? sexInput.value : null;
                
                if (!age || age < 18 || age > 120) {
                    nicknameError.textContent = 'Please enter a valid age (18-120)';
                    return;
                }
                
                if (!location) {
                    nicknameError.textContent = 'Please select your country';
                    return;
                }
                
                if (!sex) {
                    nicknameError.textContent = 'Please select your sex';
                    return;
                }
            }

            nicknameSubmit.disabled = true;
            nicknameError.textContent = '';

            await this.checkAndRegisterNickname(nickname, age, location, sex);
            
            nicknameSubmit.disabled = false;
        };

        nicknameSubmit.onclick = submitNickname;
        nicknameInput.onkeypress = (e) => {
            if (e.key === 'Enter') submitNickname();
        };
        
        // Allow Enter key on location input too
        if (locationInput) {
            locationInput.onkeypress = (e) => {
                if (e.key === 'Enter') submitNickname();
            };
        }
    }

    hideNicknameModal() {
        const modal = document.getElementById('nickname-modal');
        modal.style.display = 'none';
    }
    
    setupModeToggle() {
        const guestModeBtn = document.getElementById('guest-mode-btn');
        const loginModeBtn = document.getElementById('login-mode-btn');
        const guestForm = document.getElementById('guest-form');
        const loginForm = document.getElementById('login-form');
        const loginUsernameInput = document.getElementById('login-username-input');
        const loginPasswordInput = document.getElementById('login-password-input');
        const loginSubmit = document.getElementById('login-submit');
        const loginError = document.getElementById('login-error');
        
        if (!guestModeBtn || !loginModeBtn) return;
        
        // Toggle to guest mode
        guestModeBtn.onclick = () => {
            guestModeBtn.classList.add('active');
            guestModeBtn.style.background = '#667eea';
            guestModeBtn.style.color = 'white';
            loginModeBtn.classList.remove('active');
            loginModeBtn.style.background = 'transparent';
            loginModeBtn.style.color = '#667eea';
            guestForm.style.display = 'block';
            loginForm.style.display = 'none';
            loginError.textContent = '';
        };
        
        // Toggle to login mode
        loginModeBtn.onclick = () => {
            loginModeBtn.classList.add('active');
            loginModeBtn.style.background = '#667eea';
            loginModeBtn.style.color = 'white';
            guestModeBtn.classList.remove('active');
            guestModeBtn.style.background = 'transparent';
            guestModeBtn.style.color = '#667eea';
            guestForm.style.display = 'none';
            loginForm.style.display = 'block';
            document.getElementById('nickname-error').textContent = '';
            if (loginUsernameInput) loginUsernameInput.focus();
        };
        
        // Handle login submission
        const submitLogin = async () => {
            const username = loginUsernameInput.value.trim();
            const password = loginPasswordInput.value;
            
            if (!username) {
                loginError.textContent = 'Please enter your username';
                return;
            }
            
            if (!password) {
                loginError.textContent = 'Please enter your password';
                return;
            }
            
            loginSubmit.disabled = true;
            loginError.textContent = '';
            loginSubmit.textContent = 'Logging in...';
            
            try {
                await this.loginAndJoin(username, password);
            } catch (error) {
                loginError.textContent = error.message;
                loginSubmit.disabled = false;
                loginSubmit.textContent = 'Login & Join Chat';
            }
        };
        
        if (loginSubmit) {
            loginSubmit.onclick = submitLogin;
        }
        
        if (loginUsernameInput) {
            loginUsernameInput.onkeypress = (e) => {
                if (e.key === 'Enter') {
                    if (loginPasswordInput && !loginPasswordInput.value) {
                        loginPasswordInput.focus();
                    } else {
                        submitLogin();
                    }
                }
            };
        }
        
        if (loginPasswordInput) {
            loginPasswordInput.onkeypress = (e) => {
                if (e.key === 'Enter') submitLogin();
            };
        }
    }
    
    async loginAndJoin(username, password) {
        try {
            // Call login API
            const response = await fetch(`${this.apiUrl}/api/login.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    username, 
                    password,
                    sessionId: this.sessionId
                })
            });
            
            const data = await response.json();
            
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Login failed');
            }
            
            // Success! Set username and initialize chat
            this.username = data.user.username;
            this.userId = data.user.id;
            this.userRole = data.user.role;
            this.setStorage('chatNickname', this.username);
            this.setStorage('userId', this.userId);
            
            // If user has admin/moderator role, also set admin credentials for admin panel access
            if (['root', 'administrator', 'moderator'].includes(data.user.role)) {
                const credentials = `${username}:${password}`;
                localStorage.setItem('adminToken', credentials);
                localStorage.setItem('isAdmin', 'true');
                console.log('Admin credentials stored for role:', data.user.role);
            }
            
            // Track user login (if analytics is available and has the method)
            if (window.analytics && typeof window.analytics.trackUserLogin === 'function') {
                window.analytics.trackUserLogin(this.username);
            }
            
            this.hideNicknameModal();
            this.initializeChat();
            
            // Force update now playing for admin users to show listener count
            if (this.userRole && ['root', 'administrator', 'moderator'].includes(this.userRole)) {
                setTimeout(() => {
                    const el = document.getElementById('now-playing');
                    if (el && el.style.display !== 'none') {
                        this.updateNowPlaying();
                    }
                }, 200);
            }

        } catch (error) {
            console.error('Error logging in:', error);
            throw error;
        }
    }

    showProfileModal() {
        const modal = document.getElementById('profile-modal');
        const profileNickname = document.getElementById('profile-nickname');
        const profileAge = document.getElementById('profile-age');
        const profileSex = document.getElementById('profile-sex');
        const profileLocation = document.getElementById('profile-location');
        const profileEditFields = document.getElementById('profile-edit-fields');
        const profileSaveBtn = document.getElementById('profile-save');
        const profileError = document.getElementById('profile-error');
        
        // Populate country dropdown
        this.populateProfileCountryDropdown();
        
        // Set current values
        profileNickname.value = this.username;
        profileError.textContent = '';
        
        // Load current profile if profile fields are enabled
        if (this.settings.require_profile === 'true') {
            profileEditFields.style.display = 'block';
            profileSaveBtn.style.display = 'block';
            this.loadCurrentProfile(profileAge, profileSex, profileLocation);
        } else {
            profileEditFields.style.display = 'none';
            profileSaveBtn.style.display = 'none';
        }
        
        modal.style.display = 'flex';
        
        // Event listeners
        const closeBtn = document.getElementById('profile-close');
        const logoutBtn = document.getElementById('profile-logout');
        const saveBtn = document.getElementById('profile-save');
        
        closeBtn.onclick = () => {
            modal.style.display = 'none';
        };
        
        logoutBtn.onclick = () => {
            if (confirm('Are you sure you want to logout?')) {
                this.logout();
            }
        };
        
        if (saveBtn) {
            saveBtn.onclick = async () => {
                await this.saveProfile(profileAge, profileSex, profileLocation, profileError, saveBtn);
            };
        }
    }
    
    async loadCurrentProfile(ageInput, sexInput, locationInput) {
        try {
            const response = await fetch(`${this.apiUrl}/api/user-profile.php?username=${encodeURIComponent(this.username)}`);
            const data = await response.json();
            
            if (data.success && data.profile) {
                ageInput.value = data.profile.age || '';
                sexInput.value = data.profile.sex || '';
                locationInput.value = data.profile.location || '';
            }
        } catch (error) {
            console.error('Failed to load profile:', error);
        }
    }
    
    async saveProfile(ageInput, sexInput, locationInput, errorDiv, saveBtn) {
        const age = parseInt(ageInput.value);
        const sex = sexInput.value;
        const location = locationInput.value;
        
        // Validation
        if (!age || age < 18 || age > 120) {
            errorDiv.textContent = 'Age must be between 18 and 120';
            return;
        }
        
        if (!sex) {
            errorDiv.textContent = 'Please select your sex';
            return;
        }
        
        if (!location) {
            errorDiv.textContent = 'Please select your country';
            return;
        }
        
        saveBtn.disabled = true;
        errorDiv.textContent = '';
        
        try {
            const response = await fetch(`${this.apiUrl}/api/update-profile.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    username: this.username,
                    sessionId: this.sessionId,
                    age: age,
                    sex: sex,
                    location: location
                })
            });

            const data = await response.json();

            if (data.success) {
                // Update storage with new profile data
                this.setStorage('chatAge', age);
                this.setStorage('chatLocation', location);
                this.setStorage('chatSex', sex);
                
                // Update local profile object
                this.userProfile = { age, location, sex };
                
                alert('Profile updated successfully!');
                document.getElementById('profile-modal').style.display = 'none';
            } else {
                errorDiv.textContent = data.error || 'Failed to update profile';
            }
        } catch (error) {
            console.error('Error updating profile:', error);
            errorDiv.textContent = 'Network error. Please try again.';
        } finally {
            saveBtn.disabled = false;
        }
    }
    
    populateProfileCountryDropdown() {
        const locationSelect = document.getElementById('profile-location');
        if (!locationSelect || typeof COUNTRIES === 'undefined') return;
        
        // Clear and populate
        locationSelect.innerHTML = '<option value="">Select Country</option>';
        
        COUNTRIES.forEach(country => {
            const option = document.createElement('option');
            option.value = country.code;
            option.textContent = `${country.flag} ${country.name}`;
            locationSelect.appendChild(option);
        });
    }
    
    logout() {
        // Call logout API to remove session from database
        const sessionId = this.getStorage('chatSessionId');
        if (sessionId) {
            fetch(`${this.apiUrl}/api/logout.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    sessionId: sessionId
                })
            }).catch(err => {
                console.error('Logout API error:', err);
            });
        }
        
        this.disconnect();
        
        // Remove individual storage items
        this.removeStorage('chatNickname');
        this.removeStorage('chatAge');
        this.removeStorage('chatLocation');
        this.removeStorage('chatSex');
        this.removeStorage('chatSessionId');
        
        // Clear all localStorage
        try {
            localStorage.clear();
        } catch (e) {
            console.error('Failed to clear localStorage:', e);
        }
        
        // Clear all cookies by setting them to empty with past date
        document.cookie.split(';').forEach(c => {
            const eqPos = c.indexOf('=');
            const name = eqPos > -1 ? c.substr(0, eqPos).trim() : c.trim();
            if (name && name.startsWith('chat')) {
                // Remove cookie with various attributes
                document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax`;
                if (window.location.protocol === 'https:') {
                    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=None; Secure`;
                }
            }
        });
        
        // Reload page after ensuring storage is cleared
        setTimeout(() => {
            location.reload();
        }, 100);
    }

    initializeChat() {
        // Apply embedded mode styling if in iframe
        if (this.isEmbedded) {
            document.body.classList.add('embedded-mode');
        }
        
        // Initialize DOM elements
        this.messagesContainer = document.getElementById('messages');
        this.messageInput = document.getElementById('message-input');
        this.sendButton = document.getElementById('send-button');
        this.statusIndicator = document.getElementById('status-indicator');
        this.statusText = document.getElementById('status-text');
        this.activeUsersContainer = document.getElementById('active-users-list');
        this.activeUsersCount = document.getElementById('active-users-count');
        
        // Private chat elements
        this.privateChatHeader = document.getElementById('private-chat-header');
        this.privateChatWith = document.getElementById('private-chat-with');
        this.backToPublicBtn = document.getElementById('back-to-public');
        this.conversationsToggle = document.getElementById('conversations-toggle');
        this.conversationsPanel = document.getElementById('conversations-panel');
        this.closeConversationsBtn = document.getElementById('close-conversations');
        this.conversationsList = document.getElementById('conversations-list');
        this.unreadBadge = document.getElementById('unread-badge');
        this.scrollToBottomBtn = document.getElementById('scroll-to-bottom');
        
        // Photo upload elements
        this.photoButton = document.getElementById('photo-button');
        this.photoInput = document.getElementById('photo-input');
        this.selectedPhoto = null;
        this.photoPreviewElement = null;

        // Show current username
        document.getElementById('current-username').textContent = this.username;

        // Update UI based on chat mode (hide/show private chat elements)
        this.updateChatModeUI();

        // Event listeners
        this.sendButton.addEventListener('click', () => this.sendMessage());
        this.messageInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.sendMessage();
        });
        
        // Emoji picker
        this.initEmojiPicker();
        
        // GIF picker
        this.initGifPicker();
        
        // Scroll to bottom button
        if (this.scrollToBottomBtn) {
            this.scrollToBottomBtn.addEventListener('click', () => this.scrollToBottom());
        }
        
        // Detect scroll position to show/hide scroll button
        const container = this.messagesContainer.parentElement;
        container.addEventListener('scroll', () => {
            const isScrolledUp = container.scrollHeight - container.scrollTop - container.clientHeight > 100;
            if (this.scrollToBottomBtn) {
                this.scrollToBottomBtn.classList.toggle('show', isScrolledUp);
            }
        });
        
        // Private chat back button
        if (this.backToPublicBtn) {
            this.backToPublicBtn.addEventListener('click', () => this.exitPrivateChat());
        }
        
        // Conversations panel toggle
        if (this.conversationsToggle) {
            this.conversationsToggle.addEventListener('click', () => this.toggleConversationsPanel());
        }
        
        if (this.closeConversationsBtn) {
            this.closeConversationsBtn.addEventListener('click', () => this.closeConversationsPanel());
        }

        // Sound toggle button
        const soundBtn = document.getElementById('sound-toggle');
        if (soundBtn) {
            soundBtn.addEventListener('click', () => this.toggleSound());
            this.updateSoundButton();
        }
        
        // Photo upload button
        if (this.photoButton) {
            this.photoButton.addEventListener('click', () => this.photoInput.click());
        }
        
        if (this.photoInput) {
            this.photoInput.addEventListener('change', (e) => this.handlePhotoSelect(e));
        }

        // Profile button
        document.getElementById('change-nickname').addEventListener('click', () => {
            this.showProfileModal();
        });

        // Connect to SSE stream
        this.connect();

        // Start heartbeat
        this.startHeartbeat();
    }

    connect() {
        this.updateStatus('connecting', 'Connecting...');

        try {
            this.eventSource = new EventSource(`${this.apiUrl}/api/stream.php?username=${encodeURIComponent(this.username)}`);

            this.eventSource.addEventListener('open', () => {
                this.updateStatus('connected', 'Connected');
                this.reconnectAttempts = 0;
                console.log('SSE connection established');
            });

            this.eventSource.addEventListener('message', (e) => {
                this.handleMessage(JSON.parse(e.data));
            });

            this.eventSource.addEventListener('history', (e) => {
                const messages = JSON.parse(e.data);
                this.loadHistory(messages);
            });

            this.eventSource.addEventListener('users', (e) => {
                const data = JSON.parse(e.data);
                
                console.log('Received users event:', data);
                
                // Handle user kick event
                if (data.type === 'user_kicked') {
                    console.log('User kicked event received:', data.username);
                    console.log('Current username:', this.username);
                    if (data.username === this.username) {
                        // Current user was kicked
                        console.log('Current user was kicked!');
                        
                        // Clear all storage (both localStorage and cookies)
                        this.removeStorage('chatNickname');
                        this.removeStorage('chatAge');
                        this.removeStorage('chatLocation');
                        this.removeStorage('chatSex');
                        this.removeStorage('chatSessionId');
                        localStorage.clear();
                        
                        alert('You have been kicked from the chat by an administrator.');
                        this.disconnect();
                        // Reload to show registration screen
                        setTimeout(() => window.location.reload(), 1000);
                    }
                    // Refresh active users list for everyone
                    this.loadActiveUsers();
                } else if (data.count !== undefined && data.users !== undefined) {
                    // Normal user list update
                    this.activeUsersCount.textContent = data.count;
                    this.renderActiveUsers(data.users);
                }
            });
            
            this.eventSource.addEventListener('config', (e) => {
                const data = JSON.parse(e.data);
                this.chatMode = data.chat_mode || 'public';
                this.updateChatModeUI();
            });
            
            this.eventSource.addEventListener('private', (e) => {
                const messageData = JSON.parse(e.data);
                this.handlePrivateMessage(messageData);
            });
            
            this.eventSource.addEventListener('clear', (e) => {
                this.handleChatClear();
            });

            this.eventSource.addEventListener('message_deleted', (e) => {
                const data = JSON.parse(e.data);
                this.handleMessageDeleted(data.message_id);
            });
            
            this.eventSource.addEventListener('reconnect', (e) => {
                console.log('Server requested reconnect');
                this.eventSource.close();
                this.reconnect();
            });

            this.eventSource.addEventListener('error', (e) => {
                console.error('SSE error:', e);
                this.updateStatus('disconnected', 'Disconnected');
                this.eventSource.close();
                this.reconnect();
            });

        } catch (error) {
            console.error('Failed to connect:', error);
            this.updateStatus('disconnected', 'Connection failed');
            this.reconnect();
        }
    }

    reconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            this.updateStatus('disconnected', 'Connection failed');
            console.error('Max reconnection attempts reached');
            return;
        }

        this.reconnectAttempts++;
        // Exponential backoff with max delay
        const delay = Math.min(this.reconnectDelay * Math.pow(1.5, this.reconnectAttempts - 1), this.maxReconnectDelay);

        this.updateStatus('connecting', `Reconnecting in ${Math.round(delay / 1000)}s...`);

        setTimeout(() => {
            console.log(`Reconnection attempt ${this.reconnectAttempts}`);
            this.connect();
            
            // After reconnecting, wait a bit for SSE to send history, then fetch missed messages
            // This ensures we have the latest lastMessageId from the initial SSE history
            setTimeout(() => {
                this.fetchMissedMessages();
            }, 500);
        }, delay);
    }

    async fetchMissedMessages() {
        try {
            // If in private chat, reload the entire conversation
            if (this.privateChat.active && this.privateChat.withUser) {
                const response = await fetch(`${this.apiUrl}/api/private-message.php?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}&with_user=${encodeURIComponent(this.privateChat.withUser)}`);
                const data = await response.json();
                
                if (data.success && data.messages) {
                    const oldCount = this.privateChat.messages.length;
                    
                    // Check if there are new messages from the other user (not from me)
                    const lastOldTimestamp = this.getLastPrivateMessageTimestamp();
                    const newMessagesFromOther = data.messages.filter(msg => {
                        const msgTimestamp = new Date(msg.created_at).getTime();
                        return msgTimestamp > lastOldTimestamp && msg.from_username !== this.username;
                    });
                    
                    // Replace with fresh data from server
                    this.privateChat.messages = data.messages;
                    this.renderPrivateMessages();
                    
                    const newCount = this.privateChat.messages.length;
                    if (newCount > oldCount) {
                        // Play notification sound if there are new messages from the other user
                        if (newMessagesFromOther.length > 0 && this.soundEnabled) {
                            this.playNotificationSound();
                            this.showTitleNotification('🔒 New private message!');
                        }
                    }
                }
            } else {
                // Fetch missed public messages
                const response = await fetch(`${this.apiUrl}/api/history.php`);
                const data = await response.json();
                
                if (data.success && data.messages) {
                    // Find messages newer than the last one we saw
                    const newMessages = data.messages.filter(msg => {
                        const msgId = parseInt(msg.id || msg.message_id || 0);
                        return msgId > this.lastMessageId;
                    });
                    
                    // Add missed messages to UI
                    newMessages.forEach(msg => {
                        // Don't add if already in UI
                        const msgId = msg.id || msg.message_id;
                        if (!this.messagesContainer.querySelector(`[data-message-id="${msgId}"]`)) {
                            this.addMessageToUI(msg, false);
                        }
                    });
                    
                    if (newMessages.length > 0) {
                        console.log(`Fetched ${newMessages.length} missed public messages`);
                        
                        // Play notification sound for missed public messages from others
                        const messagesFromOthers = newMessages.filter(msg => msg.username !== this.username);
                        if (messagesFromOthers.length > 0 && this.soundEnabled) {
                            this.playNotificationSound();
                            this.showTitleNotification('💬 New message!');
                        }
                        
                        this.scrollToBottom();
                    }
                }
            }
        } catch (error) {
            console.error('Failed to fetch missed messages:', error);
        }
    }
    
    getLastPrivateMessageTimestamp() {
        if (this.privateChat.messages.length === 0) return 0;
        const lastMsg = this.privateChat.messages[this.privateChat.messages.length - 1];
        return new Date(lastMsg.created_at).getTime();
    }

    startHeartbeat() {
        // Send heartbeat every 60 seconds
        this.heartbeatInterval = setInterval(async () => {
            try {
                await fetch(`${this.apiUrl}/api/heartbeat.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: this.username,
                        sessionId: this.sessionId
                    })
                });
                // User updates will be pushed via SSE, no need to fetch here
            } catch (error) {
                console.error('Heartbeat failed:', error);
            }
        }, 60000);
    }

    // Keep this method as a fallback, but it's no longer polled
    async loadActiveUsers() {
        try {
            const response = await fetch(`${this.apiUrl}/api/active-users.php`);
            const data = await response.json();

            if (data.success) {
                this.activeUsersCount.textContent = data.count;
                this.renderActiveUsers(data.users);
            }
        } catch (error) {
            console.error('Failed to load active users:', error);
        }
    }

    renderActiveUsers(users) {
        this.activeUsersContainer.innerHTML = users.map(user => {
            const isCurrentUser = user.username === this.username;
            const canMessage = !isCurrentUser && (this.chatMode === 'private' || this.chatMode === 'both');
            
            // Get profile display elements
            let profileDisplay = '';
            let sexClass = '';
            
            if (user.sex) {
                sexClass = user.sex === 'male' ? 'user-male' : 'user-female';
            }
            
            if (user.location && typeof getCountryFlag === 'function') {
                profileDisplay += getCountryFlag(user.location) + ' ';
            }
            
            if (user.age) {
                profileDisplay += `${user.age} `;
            }
            
            return `<div class="active-user ${sexClass} ${isCurrentUser ? 'current-user' : ''} ${canMessage ? 'clickable' : ''}" 
                         ${canMessage ? `onclick="window.chatBox.startPrivateChat('${this.escapeHtml(user.username).replace(/'/g, '&#39;')}')"` : ''}>
                <span class="user-name">${this.escapeHtml(user.username)}</span>
                ${profileDisplay ? `<span class="user-profile">${profileDisplay}</span>` : ''}
                ${isCurrentUser ? '<span class="user-badge">(you)</span>' : ''}
                ${canMessage ? '<span class="pm-icon">💬</span>' : ''}
            </div>`;
        }).join('');
    }
    
    async startPrivateChat(username) {
        // Check if private chat is allowed
        if (this.chatMode === 'public') {
            alert('Private messaging is currently disabled.');
            return;
        }
        
        // Track analytics event
        if (window.analytics) {
            window.analytics.trackPrivateChatOpen(username);
        }
        
        // Store start time for duration tracking
        this.privateChat.startTime = Date.now();
        
        // Enter private chat mode
        this.privateChat.active = true;
        this.privateChat.withUser = username;
        this.privateChat.messages = [];
        
        // Mark conversation as read
        this.markConversationAsRead(username);
        
        // Update UI
        if (this.privateChatWith) {
            this.privateChatWith.textContent = username;
        }
        if (this.privateChatHeader) {
            this.privateChatHeader.style.display = 'flex';
        }
        
        // Show and enable input container
        const inputContainer = document.getElementById('chat-input-container');
        if (inputContainer) {
            inputContainer.style.display = 'flex';
            inputContainer.classList.add('visibleChatInput');
        }
        if (this.messageInput) {
            this.messageInput.disabled = false;
            this.messageInput.placeholder = `Send private message to ${username}...`;
        }
        if (this.sendButton) {
            this.sendButton.disabled = false;
        }
        
        // Update photo button visibility
        this.updatePhotoButtonVisibility();
        
        // Load conversation history
        try {
            const response = await fetch(`${this.apiUrl}/api/private-message.php?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}&with_user=${encodeURIComponent(username)}`);
            const data = await response.json();
            
            if (data.success && data.messages) {
                this.privateChat.messages = data.messages;
                this.renderPrivateMessages();
            }
        } catch (error) {
            console.error('Failed to load conversation history:', error);
        }
        
        this.scrollToBottom();
    }
    
    exitPrivateChat() {
        // Track analytics event
        if (window.analytics && this.privateChat.withUser && this.privateChat.startTime) {
            const duration = Date.now() - this.privateChat.startTime;
            window.analytics.trackPrivateChatClose(this.privateChat.withUser, duration);
        }
        
        // Exit private chat mode
        this.privateChat.active = false;
        this.privateChat.withUser = null;
        this.privateChat.messages = [];
        this.privateChat.startTime = null;
        
        // Update UI
        if (this.privateChatHeader) {
            this.privateChatHeader.style.display = 'none';
        }
        
        // Reset input based on chat mode
        if (this.chatMode === 'private') {
            // If in private-only mode, disable input when going back
            if (this.messageInput) {
                this.messageInput.disabled = true;
                this.messageInput.placeholder = 'Select a user to start chatting...';
            }
            if (this.sendButton) {
                this.sendButton.disabled = true;
            }
        } else if (this.messageInput) {
            // Enable for public chat
            this.messageInput.disabled = false;
            this.messageInput.placeholder = 'Type your message...';
            if (this.sendButton) {
                this.sendButton.disabled = false;
            }
        }
        
        // Update photo button visibility
        this.updatePhotoButtonVisibility();
        
        // Remove any pending photo preview
        this.removePhotoPreview();
        
        // Reload public messages or show private-only message
        if (this.chatMode === 'private') {
            this.updateChatModeUI();
        } else {
            this.loadPublicMessages();
        }
    }
    
    async loadPublicMessages() {
        try {
            const response = await fetch(`${this.apiUrl}/api/history.php`);
            const data = await response.json();
            
            if (data.success && data.messages) {
                this.messagesContainer.innerHTML = '';
                data.messages.forEach(msg => {
                    this.addMessageToUI(msg, false);
                });
                this.scrollToBottom();
            }
        } catch (error) {
            console.error('Failed to load public messages:', error);
        }
    }
    
    renderPrivateMessages() {
        this.messagesContainer.innerHTML = '';
        
        this.privateChat.messages.forEach(msg => {
            const messageDiv = document.createElement('div');
            const isFromMe = msg.from_username === this.username;
            messageDiv.className = `message private-message ${isFromMe ? 'sent' : 'received'}`;
            
            const timestamp = new Date(msg.created_at);
            const timeString = timestamp.toLocaleTimeString();
            const fullDate = timestamp.toLocaleString();
            
            let content = `
                <div class="message-header">
                    <strong class="message-username">${this.escapeHtml(isFromMe ? 'You' : msg.from_username)}</strong>
                    <span class="message-time" title="${this.escapeHtml(fullDate)}">${timeString}</span>
                </div>
            `;
            
            // Add message text if present
            if (msg.message) {
                content += `<div class="message-text">${this.formatMessageText(msg.message)}</div>`;
            }
            
            // Add photo if present
            if (msg.attachment) {
                content += `
                    <div class="message-photo">
                        <img src="${this.escapeHtml(msg.attachment.file_path)}" 
                             alt="Photo" 
                             onclick="window.open('${this.escapeHtml(msg.attachment.file_path)}', '_blank')"
                             loading="lazy">
                    </div>
                `;
            }
            
            messageDiv.innerHTML = content;
            this.messagesContainer.appendChild(messageDiv);
            
            // Parse emojis with Twemoji for older Windows support
            // Use attributes callback to prevent parsing inside GIF URLs
            if (typeof twemoji !== 'undefined') {
                twemoji.parse(messageDiv, {
                    folder: 'svg',
                    ext: '.svg',
                    attributes: function() {
                        return {class: 'emoji'};
                    }
                });
            }
        });
        
        this.scrollToBottom();
    }

    loadHistory(messages) {
        // Don't load public history if in private chat mode
        if (this.privateChat.active) {
            return;
        }
        
        // Clear existing messages
        this.messagesContainer.innerHTML = '';

        // Add all history messages
        messages.forEach(msg => {
            this.addMessageToUI(msg, false);
        });
        
        // Track the highest message ID from history
        messages.forEach(msg => {
            const msgIdNum = parseInt(msg.id || msg.message_id || 0);
            if (msgIdNum > (this.lastMessageId || 0)) {
                this.lastMessageId = msgIdNum;
            }
        });
        
        // Parse emojis with Twemoji for older Windows support (entire container for efficiency)
        if (typeof twemoji !== 'undefined') {
            twemoji.parse(this.messagesContainer, {
                folder: 'svg',
                ext: '.svg'
            });
        }

        this.scrollToBottom();
    }

    handleMessage(messageData) {
        // Don't add public messages if in private chat mode
        if (this.privateChat.active) {
            return;
        }
        
        this.addMessageToUI(messageData, true);
        
        // Play sound for new messages from others
        if (messageData.username !== this.username && this.soundEnabled) {
            this.playNotificationSound();
            this.showTitleNotification('💬 New message!');
        }
    }
    
    handleChatClear() {
        // Clear all public messages from the UI
        if (this.messagesContainer) {
            // If in private chat, switch back to public chat first
            if (this.privateChat.active) {
                this.switchToPublicChat();
            }
            
            this.messagesContainer.innerHTML = '';
            
            // Show a notification message
            const noticeDiv = document.createElement('div');
            noticeDiv.style.textAlign = 'center';
            noticeDiv.style.padding = '20px';
            noticeDiv.style.color = '#6b7280';
            noticeDiv.style.fontStyle = 'italic';
            noticeDiv.innerHTML = '🗑️ Chat has been cleared by an administrator';
            this.messagesContainer.appendChild(noticeDiv);
            
            // Parse emojis with Twemoji for older Windows support
            if (typeof twemoji !== 'undefined') {
                twemoji.parse(noticeDiv, {
                    folder: 'svg',
                    ext: '.svg'
                });
            }
        }
    }

    handleMessageDeleted(messageId) {
        // Find and remove the message from UI by data-message-id attribute
        const messages = this.messagesContainer.querySelectorAll(`.message[data-message-id="${messageId}"]`);
        messages.forEach(msg => msg.remove());
    }
    
    playNotificationSound() {
        // Create a simple beep sound using Web Audio API
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.value = 800; // frequency in Hz
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.1);
        } catch (error) {
            console.error('Failed to play sound:', error);
        }
    }
    
    toggleSound() {
        this.soundEnabled = !this.soundEnabled;
        localStorage.setItem('chatSoundEnabled', this.soundEnabled);
        this.updateSoundButton();
    }
    
    updateSoundButton() {
        const soundBtn = document.getElementById('sound-toggle');
        if (soundBtn) {
            soundBtn.textContent = this.soundEnabled ? '🔔' : '🔕';
            soundBtn.title = this.soundEnabled ? 'Sound On (click to mute)' : 'Sound Off (click to unmute)';
            
            // Parse emojis with Twemoji for older Windows support
            if (typeof twemoji !== 'undefined') {
                twemoji.parse(soundBtn, {
                    folder: 'svg',
                    ext: '.svg'
                });
            }
        }
    }

    addMessageToUI(messageData, animate = true) {
        const messageDiv = document.createElement('div');
        const isOwnMessage = messageData.username === this.username;
        messageDiv.className = `message ${isOwnMessage ? 'own-message' : ''}`;
        // Use either 'id' (from real-time) or 'message_id' (from database/admin API)
        const msgId = messageData.id || messageData.message_id;
        messageDiv.dataset.messageId = msgId;
        
        // Track the highest message ID we've seen
        const msgIdNum = parseInt(msgId || 0);
        if (msgIdNum > (this.lastMessageId || 0)) {
            this.lastMessageId = msgIdNum;
        }
        
        // Check if we should group this message with previous ones
        const messagesInContainer = this.messagesContainer.children;
        const lastMessage = messagesInContainer.length > 0 
            ? messagesInContainer[messagesInContainer.length - 1] 
            : null;
        
        // Check for day separator
        const messageDate = new Date(messageData.timestamp * 1000);
        const shouldShowDaySeparator = this.shouldShowDaySeparator(messageDate);
        
        if (shouldShowDaySeparator) {
            this.addDaySeparator(messageDate);
        }
        
        // Group messages from same user within 5 minutes
        let isGrouped = false;
        if (lastMessage && !lastMessage.classList.contains('day-separator')) {
            const lastUsername = lastMessage.dataset.username;
            const lastTimestamp = parseInt(lastMessage.dataset.timestamp);
            const timeDiff = messageData.timestamp - lastTimestamp;
            
            if (lastUsername === messageData.username && timeDiff < 300) { // 5 minutes
                isGrouped = true;
                messageDiv.classList.add('grouped');
                lastMessage.classList.remove('last-in-group');
            } else {
                if (messagesInContainer.length > 0) {
                    lastMessage.classList.add('last-in-group');
                }
                messageDiv.classList.add('first-in-group');
            }
        } else {
            messageDiv.classList.add('first-in-group');
        }
        
        messageDiv.dataset.username = messageData.username;
        messageDiv.dataset.timestamp = messageData.timestamp;
        
        if (!animate) {
            messageDiv.style.animation = 'none';
        }

        const timestamp = new Date(messageData.timestamp * 1000);
        const timeString = timestamp.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit'
        });
        const fullDate = timestamp.toLocaleString();

        // Check if user is admin
        const isAdmin = localStorage.getItem('isAdmin') === 'true';
        const deleteButton = isAdmin && msgId ? `
            <button class="delete-message-btn" data-message-id="${msgId}" title="Delete message">
                🗑️
            </button>
        ` : '';
        
        // Add reply button for all messages
        const replyButton = msgId ? `
            <button class="reply-message-btn" data-message-id="${msgId}" title="Reply to this message">
                ↩️
            </button>
        ` : '';
        
        // Build reply quote HTML if this is a reply
        let replyQuoteHTML = '';
        if (messageData.reply_data && messageData.reply_data.username) {
            const truncatedMessage = messageData.reply_data.message.length > 50 
                ? messageData.reply_data.message.substring(0, 50) + '...'
                : messageData.reply_data.message;
            replyQuoteHTML = `
                <div class="reply-quote">
                    <div class="reply-quote-bar"></div>
                    <div class="reply-quote-content">
                        <span class="reply-quote-username">${this.escapeHtml(messageData.reply_data.username)}</span>
                        <span class="reply-quote-message">${this.escapeHtml(truncatedMessage)}</span>
                    </div>
                </div>
            `;
        }

        messageDiv.innerHTML = `
            <div class="message-header">
                <span class="message-username">${this.escapeHtml(messageData.username)}</span>
                <span class="message-time" title="${this.escapeHtml(fullDate)}">${timeString}</span>
            </div>
            ${replyQuoteHTML}
            <div class="message-body">
                <div class="message-text">${this.formatMessageText(messageData.message)}</div>
                <div class="message-actions">
                    ${replyButton}
                    ${deleteButton}
                </div>
            </div>
        `;

        // Add event listener for delete button if admin
        if (isAdmin) {
            const deleteBtn = messageDiv.querySelector('.delete-message-btn');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', (e) => {
                    // Find the button element (in case click target is the emoji/image inside)
                    const button = e.target.closest('.delete-message-btn');
                    const msgId = button ? button.getAttribute('data-message-id') : null;
                    this.deleteMessage(msgId, button || e.target);
                });
            }
        }
        
        // Add event listener for reply button
        const replyBtn = messageDiv.querySelector('.reply-message-btn');
        if (replyBtn) {
            replyBtn.addEventListener('click', (e) => {
                const button = e.target.closest('.reply-message-btn');
                const msgId = button ? button.getAttribute('data-message-id') : null;
                this.setReplyState(msgId, messageData.username, messageData.message);
            });
        }

        this.messagesContainer.appendChild(messageDiv);
        
        // Parse emojis with Twemoji for older Windows support
        if (typeof twemoji !== 'undefined') {
            twemoji.parse(messageDiv, {
                folder: 'svg',
                ext: '.svg',
                callback: function(icon, options) {
                    return options.base + options.size + '/' + icon + options.ext;
                }
            });
        }
        
        if (animate) {
            this.scrollToBottom();
        }
    }
    
    shouldShowDaySeparator(messageDate) {
        const messagesInContainer = this.messagesContainer.children;
        if (messagesInContainer.length === 0) return true;
        
        // Find the last actual message (not a day separator)
        for (let i = messagesInContainer.length - 1; i >= 0; i--) {
            const child = messagesInContainer[i];
            if (child.classList.contains('day-separator')) continue;
            
            const lastTimestamp = parseInt(child.dataset.timestamp);
            const lastDate = new Date(lastTimestamp * 1000);
            
            // Check if it's a different day
            return messageDate.toDateString() !== lastDate.toDateString();
        }
        
        return true;
    }
    
    addDaySeparator(date) {
        const separator = document.createElement('div');
        separator.className = 'day-separator';
        
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        
        let label;
        if (date.toDateString() === today.toDateString()) {
            label = 'Today';
        } else if (date.toDateString() === yesterday.toDateString()) {
            label = 'Yesterday';
        } else {
            label = date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                month: 'short', 
                day: 'numeric' 
            });
        }
        
        separator.innerHTML = `<span>${label}</span>`;
        this.messagesContainer.appendChild(separator);
    }
    
    convertEmoticonsToEmojis(text) {
        // First, protect URLs by replacing them with placeholders
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        const urls = [];
        let protectedText = text.replace(urlRegex, (url) => {
            const placeholder = `__URL_${urls.length}__`;
            urls.push(url);
            return placeholder;
        });
        
        // Map of common emoticons to their emoji equivalents
        const emoticonMap = [
            // Happy/Smiling faces
            { pattern: /:-\)/g, emoji: '🙂' },
            { pattern: /:\)/g, emoji: '🙂' },
            { pattern: /=\)/g, emoji: '🙂' },
            { pattern: /:-D/g, emoji: '😃' },
            { pattern: /:D/g, emoji: '😃' },
            { pattern: /=D/g, emoji: '😃' },
            { pattern: /xD/gi, emoji: '😆' },
            { pattern: /XD/g, emoji: '😆' },
            
            // Winking
            { pattern: /;-\)/g, emoji: '😉' },
            { pattern: /;\)/g, emoji: '😉' },
            
            // Sad faces
            { pattern: /:-\(/g, emoji: '🙁' },
            { pattern: /:\(/g, emoji: '🙁' },
            { pattern: /=\(/g, emoji: '🙁' },
            { pattern: /:-\[/g, emoji: '😞' },
            { pattern: /:\[/g, emoji: '😞' },
            
            // Tongue out
            { pattern: /:-[pP]/g, emoji: '😛' },
            { pattern: /:[pP]/g, emoji: '😛' },
            { pattern: /:-[bB]/g, emoji: '😛' },
            
            // Love/Hearts
            { pattern: /<3/g, emoji: '❤️' },
            { pattern: /<\/3/g, emoji: '💔' },
            
            // Cool/Sunglasses
            { pattern: /8-\)/g, emoji: '😎' },
            { pattern: /B-\)/gi, emoji: '😎' },
            
            // Surprised/Shocked
            { pattern: /:-[oO]/g, emoji: '😮' },
            { pattern: /:[oO]/g, emoji: '😮' },
            
            // Crying
            { pattern: /:'-\(/g, emoji: '😢' },
            { pattern: /:'\(/g, emoji: '😢' },
            { pattern: /;-;/g, emoji: '😢' },
            { pattern: /T_T/g, emoji: '😭' },
            { pattern: /T-T/g, emoji: '😭' },
            
            // Laughing
            { pattern: /:-\|/g, emoji: '😐' },
            { pattern: /:\|/g, emoji: '😐' },
            
            // Kiss
            { pattern: /:-\*/g, emoji: '😘' },
            { pattern: /:\*/g, emoji: '😘' },
            
            // Angel
            { pattern: /O:-\)/g, emoji: '😇' },
            { pattern: /O:\)/g, emoji: '😇' },
            
            // Devil
            { pattern: />:-\)/g, emoji: '😈' },
            { pattern: />:\)/g, emoji: '😈' },
            
            // Confused
            { pattern: /:-\//g, emoji: '😕' },
            { pattern: /:\//g, emoji: '😕' },
            { pattern: /:-\\/g, emoji: '😕' },
            { pattern: /:\\/g, emoji: '😕' },
            
            // Thinking
            { pattern: /:-\?/g, emoji: '🤔' },
            { pattern: /:\?/g, emoji: '🤔' },
            
            // Thumbs up/down
            { pattern: /\(y\)/gi, emoji: '👍' },
            { pattern: /\(n\)/gi, emoji: '👎' }
        ];
        
        // Apply each emoticon replacement on the protected text
        emoticonMap.forEach(({ pattern, emoji }) => {
            protectedText = protectedText.replace(pattern, emoji);
        });
        
        // Restore URLs from placeholders
        urls.forEach((url, index) => {
            protectedText = protectedText.replace(`__URL_${index}__`, url);
        });
        
        return protectedText;
    }

    async sendMessage() {
        let message = this.messageInput.value.trim();
        
        // Convert emoticons to emojis
        if (message) {
            message = this.convertEmoticonsToEmojis(message);
        }

        // Check if we have a photo or message
        if (!message && !this.selectedPhoto) {
            return;
        }
        
        // Photo uploads only allowed in private chat
        if (this.selectedPhoto && !this.privateChat.active) {
            alert('Photo uploads are only available in private messages');
            this.removePhotoPreview();
            return;
        }

        // Disable send button
        this.sendButton.disabled = true;

        try {
            let attachmentId = null;
            
            // Upload photo if selected
            if (this.selectedPhoto && this.privateChat.active) {
                try {
                    const attachment = await this.uploadPhoto(this.selectedPhoto, this.privateChat.withUser);
                    attachmentId = attachment.attachment_id;
                    this.removePhotoPreview();
                } catch (error) {
                    throw new Error('Failed to upload photo: ' + error.message);
                }
            }
            
            // If in private chat mode, send private message
            if (this.privateChat.active && this.privateChat.withUser) {
                await this.sendPrivateMessage(this.privateChat.withUser, message, attachmentId);
                
                // Track analytics event
                if (window.analytics) {
                    window.analytics.trackMessageSent('private');
                }
            } else {
                // Send public message
                const messagePayload = {
                    username: this.username,
                    message: message,
                    sessionId: this.sessionId
                };
                
                // Include reply_to if replying
                if (this.replyState.active && this.replyState.messageId) {
                    messagePayload.replyTo = this.replyState.messageId;
                }
                
                const response = await fetch(`${this.apiUrl}/api/send.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(messagePayload)
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.error || 'Failed to send message');
                }
                
                // Clear reply state after sending
                this.clearReplyState();
                
                // Track analytics event
                if (window.analytics) {
                    window.analytics.trackMessageSent('public');
                }
            }

            // Clear message input
            this.messageInput.value = '';

        } catch (error) {
            console.error('Error sending message:', error);
            alert(error.message);
        } finally {
            // Re-enable send button after a short delay
            setTimeout(() => {
                this.sendButton.disabled = false;
                this.messageInput.focus();
            }, 500);
        }
    }

    updateStatus(status, text) {
        if (this.statusIndicator && this.statusText) {
            this.statusIndicator.className = `status-${status}`;
            this.statusText.textContent = text;
        }
    }

    scrollToBottom() {
        const container = this.messagesContainer.parentElement;
        container.scrollTop = container.scrollHeight;
    }

    async deleteMessage(messageId, button) {
        console.log('Delete clicked:', messageId, 'Button:', button); // Debug
        
        if (!confirm('Delete this message?')) {
            return;
        }

        const adminToken = localStorage.getItem('adminToken');
        console.log('Admin token:', adminToken ? 'Present' : 'Missing'); // Debug
        
        if (!adminToken) {
            alert('Admin authentication required');
            return;
        }

        try {
            console.log('Sending delete request for message:', messageId); // Debug
            
            const response = await fetch(`${this.apiUrl}/api/admin/delete-message.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${adminToken}`
                },
                body: JSON.stringify({ message_id: messageId })
            });

            const data = await response.json();
            console.log('Delete response:', response.status, data); // Debug

            if (response.ok && data.success) {
                // Remove message from UI immediately
                const messageDiv = button.closest('.message');
                if (messageDiv) {
                    messageDiv.remove();
                }
            } else {
                alert(data.error || 'Failed to delete message');
            }
        } catch (error) {
            console.error('Delete error:', error);
            alert('Failed to delete message');
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Set reply state when user clicks reply button
     */
    setReplyState(messageId, username, message) {
        this.replyState = {
            active: true,
            messageId: messageId,
            username: username,
            message: message
        };
        this.showReplyPreview();
        // Focus the input
        if (this.messageInput) {
            this.messageInput.focus();
        }
    }
    
    /**
     * Clear reply state
     */
    clearReplyState() {
        this.replyState = {
            active: false,
            messageId: null,
            username: null,
            message: null
        };
        this.hideReplyPreview();
    }
    
    /**
     * Show reply preview above input
     */
    showReplyPreview() {
        // Remove existing preview if any
        this.hideReplyPreview();
        
        const truncatedMessage = this.replyState.message.length > 50 
            ? this.replyState.message.substring(0, 50) + '...'
            : this.replyState.message;
        
        const previewDiv = document.createElement('div');
        previewDiv.className = 'reply-preview';
        previewDiv.innerHTML = `
            <div class="reply-preview-content">
                <div class="reply-preview-bar"></div>
                <div class="reply-preview-text">
                    <div class="reply-preview-label">Replying to <strong>${this.escapeHtml(this.replyState.username)}</strong></div>
                    <div class="reply-preview-message">${this.escapeHtml(truncatedMessage)}</div>
                </div>
                <button class="reply-preview-close" title="Cancel reply">✕</button>
            </div>
        `;
        
        // Add close button handler
        const closeBtn = previewDiv.querySelector('.reply-preview-close');
        closeBtn.addEventListener('click', () => this.clearReplyState());
        
        // Insert before the input container
        const inputContainer = document.getElementById('chat-input-container');
        if (inputContainer) {
            inputContainer.parentElement.insertBefore(previewDiv, inputContainer);
        }
    }
    
    /**
     * Hide reply preview
     */
    hideReplyPreview() {
        const existingPreview = document.querySelector('.reply-preview');
        if (existingPreview) {
            existingPreview.remove();
        }
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
        }
    }
    
    updateChatModeUI() {
        // Show/hide elements based on chat mode
        const allowPrivate = this.chatMode === 'private' || this.chatMode === 'both';
        const allowPublic = this.chatMode === 'public' || this.chatMode === 'both';
        const inputContainer = document.getElementById('chat-input-container');
        
        // Show/hide conversations toggle button
        if (this.conversationsToggle) {
            this.conversationsToggle.style.display = allowPrivate ? 'inline-flex' : 'none';
        }
        
        // If private chat is disabled and user is in a private chat, exit it
        if (!allowPrivate && this.privateChat && this.privateChat.active) {
            this.switchToPublicChat();
        }
        
        // Close conversations panel if open
        if (!allowPrivate && this.conversationsPanel && this.conversationsPanel.classList.contains('open')) {
            this.conversationsPanel.classList.remove('open');
        }
        
        // Handle private-only mode (only when NOT in a private chat)
        if (this.chatMode === 'private' && !this.privateChat.active) {
            // Show message that public chat is disabled
            if (this.messagesContainer) {
                this.messagesContainer.innerHTML = `
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; padding: 40px; color: #6b7280;">
                        <div style="font-size: 48px; margin-bottom: 20px;">💬</div>
                        <h3 style="margin: 0 0 10px 0; color: #374151;">Private Messages Only</h3>
                        <p style="margin: 0;">Public chat is disabled. Click on a user to start a private conversation.</p>
                    </div>
                `;
            }
            // Hide input container completely
            if (inputContainer) {
                inputContainer.style.display = 'none';
                inputContainer.classList.remove('visibleChatInput');
            }
        } else if (this.privateChat.active) {
            // In a private chat - always show input (even in private-only mode)
            if (inputContainer) {
                inputContainer.style.display = 'flex';
                inputContainer.classList.add('visibleChatInput');
            }
            if (this.messageInput) {
                this.messageInput.disabled = false;
                this.messageInput.placeholder = `Send private message to ${this.privateChat.withUser || ''}...`;
            }
            if (this.sendButton) {
                this.sendButton.disabled = false;
            }
        } else {
            // Show and enable input for public chat or both modes
            if (inputContainer) {
                inputContainer.style.display = 'flex';
            }
            if (this.messageInput) {
                this.messageInput.disabled = false;
                this.messageInput.placeholder = 'Type your message...';
            }
            if (this.sendButton) {
                this.sendButton.disabled = false;
            }
        }
        
        // Note: Active users will be re-rendered when they're next updated via SSE
        // No need to force re-render here since click handlers are determined dynamically
    }
    
    handlePrivateMessage(messageData) {
        const isFromMe = messageData.from_username === this.username;
        const otherUser = isFromMe ? messageData.to_username : messageData.from_username;
        
        // Track analytics for received messages
        if (!isFromMe && window.analytics) {
            window.analytics.trackPrivateMessageReceived(messageData.from_username);
        }
        
        // Update conversations list
        const displayText = messageData.message || (messageData.attachment ? '[Photo]' : '');
        this.updateConversation(otherUser, displayText, isFromMe);

        // Show tab notification if not from self
        if (!isFromMe) {
            this.showTitleNotification('🔒 New private message!');
        
        }

        RadioChatBox.prototype.showTitleNotification = function(text) {
            if (this.titleNotificationActive) return;
            this.titleNotificationActive = true;
            this.originalTitle = document.title;
            document.title = `${text} ${this.originalTitle}`;
            if (this.titleNotificationTimeout) clearTimeout(this.titleNotificationTimeout);
            this.titleNotificationTimeout = setTimeout(() => {
                this.clearTitleNotification();
            }, 10000);
        };

        RadioChatBox.prototype.clearTitleNotification = function() {
            if (this.titleNotificationActive) {
                document.title = this.originalTitle;
                this.titleNotificationActive = false;
            }
            if (this.titleNotificationTimeout) {
                clearTimeout(this.titleNotificationTimeout);
                this.titleNotificationTimeout = null;
            }
        };
        
        // If in private chat mode, only show messages for current conversation
        if (this.privateChat.active) {
            if (otherUser !== this.privateChat.withUser) {
                // Message is not for current conversation, don't display but still track
                return;
            }
            
            // Mark as read since we're viewing the conversation
            this.markConversationAsRead(otherUser);
            
            // Add to private chat messages and re-render
            this.privateChat.messages.push({
                from_username: messageData.from_username,
                to_username: messageData.to_username,
                message: messageData.message || '',
                attachment: messageData.attachment || null,
                created_at: new Date(messageData.timestamp * 1000).toISOString()
            });
            
            this.renderPrivateMessages();
        } else {
            // Not in private chat mode - show inline with indicator
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message private-message';
            
            const timestamp = new Date(messageData.timestamp * 1000);
            const timeString = timestamp.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            let content = `
                <div class="message-header">
                    <span class="message-username">${isFromMe ? 'You' : this.escapeHtml(messageData.from_username)}</span>
                    <span class="private-indicator">🔒 Private to ${isFromMe ? this.escapeHtml(otherUser) : 'you'}</span>
                    <span class="message-time">${timeString}</span>
                </div>
            `;
            
            if (messageData.message) {
                content += `<div class="message-text">${this.formatMessageText(messageData.message)}</div>`;
            }
            
            if (messageData.attachment) {
                content += `
                    <div class="message-photo">
                        <img src="${this.escapeHtml(messageData.attachment.file_path)}" 
                             alt="Photo" 
                             onclick="window.open('${this.escapeHtml(messageData.attachment.file_path)}', '_blank')"
                             loading="lazy">
                    </div>
                `;
            }
            
            messageDiv.innerHTML = content;
            this.messagesContainer.appendChild(messageDiv);
            
            // Parse emojis with Twemoji for older Windows support
            if (typeof twemoji !== 'undefined') {
                twemoji.parse(messageDiv, {
                    folder: 'svg',
                    ext: '.svg'
                });
            }
            
            this.scrollToBottom();
        }
        
        // Play sound if not from self
        if (!isFromMe && this.soundEnabled) {
            this.playNotificationSound();
        }
    }
    
    async sendPrivateMessage(toUsername, message, attachmentId = null) {
        try {
            const payload = {
                from_username: this.username,
                from_session_id: this.sessionId,
                to_username: toUsername
            };
            
            if (attachmentId) {
                payload.attachment_id = attachmentId;
            }
            
            if (message) {
                payload.message = message;
            }
            
            const response = await fetch(`${this.apiUrl}/api/private-message.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || 'Failed to send private message');
            }
            
            // Update conversation with sent message
            this.updateConversation(toUsername, message || '[Photo]', true);
            
            return true;
        } catch (error) {
            console.error('Error sending private message:', error);
            alert(error.message);
            return false;
        }
    }
    
    handlePhotoSelect(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            alert('Please select a valid image file (JPG, PNG, GIF, or WebP)');
            event.target.value = '';
            return;
        }
        
        // Get max size from settings (convert MB to bytes)
        const maxSizeMB = parseInt(this.settings.max_photo_size_mb || 5);
        const maxSizeBytes = maxSizeMB * 1024 * 1024;
        
        if (file.size > maxSizeBytes) {
            alert(`Photo is too large. Maximum size is ${maxSizeMB}MB.`);
            event.target.value = '';
            return;
        }
        
        this.selectedPhoto = file;
        this.showPhotoPreview(file);
        event.target.value = ''; // Reset input
    }
    
    showPhotoPreview(file) {
        // Remove existing preview
        if (this.photoPreviewElement) {
            this.photoPreviewElement.remove();
        }
        
        // Create preview element
        const preview = document.createElement('div');
        preview.className = 'photo-upload-preview';
        
        const img = document.createElement('img');
        const reader = new FileReader();
        reader.onload = (e) => {
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
        
        const info = document.createElement('div');
        info.className = 'photo-info';
        info.innerHTML = `
            <div><strong>${file.name}</strong></div>
            <div>${(file.size / 1024).toFixed(1)} KB</div>
        `;
        
        const removeBtn = document.createElement('button');
        removeBtn.className = 'remove-photo';
        removeBtn.textContent = 'Remove';
        removeBtn.onclick = () => this.removePhotoPreview();
        
        preview.appendChild(img);
        preview.appendChild(info);
        preview.appendChild(removeBtn);
        
        // Insert preview above input container
        const inputContainer = document.getElementById('chat-input-container');
        inputContainer.parentNode.insertBefore(preview, inputContainer);
        
        this.photoPreviewElement = preview;
    }
    
    removePhotoPreview() {
        if (this.photoPreviewElement) {
            this.photoPreviewElement.remove();
            this.photoPreviewElement = null;
        }
        this.selectedPhoto = null;
    }
    
    async uploadPhoto(file, recipient) {
        const formData = new FormData();
        formData.append('photo', file);
        formData.append('username', this.username);
        formData.append('recipient', recipient);
        formData.append('sessionId', this.sessionId);
        
        try {
            const response = await fetch(`${this.apiUrl}/api/upload-photo.php`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (!response.ok || !data.success) {
                // Track failed upload
                if (window.analytics) {
                    window.analytics.trackPhotoUpload(false, file.size);
                }
                throw new Error(data.error || 'Failed to upload photo');
            }
            
            // Track successful upload
            if (window.analytics) {
                window.analytics.trackPhotoUpload(true, file.size);
            }
            
            return data.attachment;
        } catch (error) {
            console.error('Error uploading photo:', error);
            throw error;
        }
    }
    
    toggleConversationsPanel() {
        if (this.conversationsPanelOpen) {
            this.closeConversationsPanel();
        } else {
            this.openConversationsPanel();
        }
    }
    
    async openConversationsPanel() {
        this.conversationsPanelOpen = true;
        if (this.conversationsPanel) {
            this.conversationsPanel.style.display = 'block';
            // Load all conversations from server
            await this.loadAllConversations();
            this.renderConversations();
        }
    }
    
    async loadAllConversations() {
        try {
            const response = await fetch(`${this.apiUrl}/api/private-message.php?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}`);
            const data = await response.json();
            
            if (data.success && data.messages) {
                // Group messages by conversation partner
                const conversationMap = new Map();
                
                data.messages.forEach(msg => {
                    const otherUser = msg.from_username === this.username ? msg.to_username : msg.from_username;
                    const existing = conversationMap.get(otherUser);
                    
                    if (!existing || new Date(msg.created_at) > new Date(existing.timestamp)) {
                        conversationMap.set(otherUser, {
                            lastMessage: msg.message,
                            timestamp: new Date(msg.created_at).getTime(),
                            unreadCount: 0 // We'll keep the unread count from real-time updates
                        });
                    }
                });
                
                // Merge with existing conversations (preserve unread counts)
                conversationMap.forEach((conv, username) => {
                    const existing = this.conversations.get(username);
                    if (existing) {
                        conv.unreadCount = existing.unreadCount;
                    }
                    this.conversations.set(username, conv);
                });
            }
        } catch (error) {
            console.error('Failed to load conversations:', error);
        }
    }
    
    closeConversationsPanel() {
        this.conversationsPanelOpen = false;
        if (this.conversationsPanel) {
            this.conversationsPanel.style.display = 'none';
        }
    }
    
    renderConversations() {
        if (!this.conversationsList) return;
        
        if (this.conversations.size === 0) {
            this.conversationsList.innerHTML = '<div class="empty-conversations">No conversations yet</div>';
            return;
        }
        
        // Convert to array and sort by timestamp (most recent first)
        const conversationsArray = Array.from(this.conversations.entries())
            .sort((a, b) => b[1].timestamp - a[1].timestamp);
        
        this.conversationsList.innerHTML = conversationsArray.map(([username, conv]) => {
            const hasUnread = conv.unreadCount > 0;
            return `
                <div class="conversation-item ${hasUnread ? 'unread' : ''}" onclick="window.chatBox.openConversation('${this.escapeHtml(username).replace(/'/g, '&#39;')}')">
                    <div>
                        <div class="conversation-user">${this.escapeHtml(username)}</div>
                        <div class="conversation-preview">${this.escapeHtml(conv.lastMessage)}</div>
                    </div>
                    ${hasUnread ? `<span class="conversation-badge">${conv.unreadCount}</span>` : ''}
                </div>
            `;
        }).join('');
        
        // Parse emojis with Twemoji for older Windows support
        if (typeof twemoji !== 'undefined') {
            twemoji.parse(this.conversationsList, {
                folder: 'svg',
                ext: '.svg'
            });
        }
    }
    
    openConversation(username) {
        this.closeConversationsPanel();
        this.startPrivateChat(username);
    }
    
    updateConversation(username, message, isFromMe) {
        const existing = this.conversations.get(username);
        
        this.conversations.set(username, {
            lastMessage: message,
            unreadCount: (existing?.unreadCount || 0) + (isFromMe ? 0 : 1),
            timestamp: Date.now()
        });
        
        this.updateUnreadBadge();
    }
    
    markConversationAsRead(username) {
        const conv = this.conversations.get(username);
        if (conv) {
            conv.unreadCount = 0;
            this.conversations.set(username, conv);
            this.updateUnreadBadge();
        }
    }
    
    updateUnreadBadge() {
        if (!this.unreadBadge) return;
        
        const totalUnread = Array.from(this.conversations.values())
            .reduce((sum, conv) => sum + conv.unreadCount, 0);
        
        if (totalUnread > 0) {
            this.unreadBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
            this.unreadBadge.style.display = 'block';
        } else {
            this.unreadBadge.style.display = 'none';
        }
    }
    
    initEmojiPicker() {
        const emojiButton = document.getElementById('emoji-button');
        const emojiPicker = document.getElementById('emoji-picker');
        const emojiGrid = document.getElementById('emoji-grid');
        
        if (!emojiButton || !emojiPicker || typeof EMOJIS === 'undefined') {
            console.warn('Emoji picker not available');
            return;
        }
        
        let currentCategory = 'smileys';
        
        // Toggle emoji picker
        emojiButton.addEventListener('click', (e) => {
            e.stopPropagation();
            const isVisible = emojiPicker.style.display === 'block';
            emojiPicker.style.display = isVisible ? 'none' : 'block';
            emojiButton.classList.toggle('active', !isVisible);
            
            if (!isVisible) {
                this.renderEmojis(currentCategory);
            }
        });
        
        // Category buttons
        document.querySelectorAll('.emoji-category').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                document.querySelectorAll('.emoji-category').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentCategory = btn.dataset.category;
                this.renderEmojis(currentCategory);
            });
        });
        
        // Close picker when clicking outside
        document.addEventListener('click', (e) => {
            if (!emojiPicker.contains(e.target) && e.target !== emojiButton) {
                emojiPicker.style.display = 'none';
                emojiButton.classList.remove('active');
            }
        });
        
        // Initial render
        this.renderEmojis(currentCategory);
    }
    
    renderEmojis(category) {
        const emojiGrid = document.getElementById('emoji-grid');
        if (!emojiGrid || !EMOJIS[category]) return;
        
        emojiGrid.innerHTML = '';
        
        EMOJIS[category].forEach(emoji => {
            const btn = document.createElement('button');
            btn.className = 'emoji-item';
            btn.textContent = emoji;
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.insertEmoji(emoji);
            });
            emojiGrid.appendChild(btn);
        });
        
        // Parse emojis with Twemoji for older Windows support
        if (typeof twemoji !== 'undefined') {
            twemoji.parse(emojiGrid, {
                folder: 'svg',
                ext: '.svg'
            });
        }
    }
    
    insertEmoji(emoji) {
        const input = this.messageInput;
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const text = input.value;
        
        // Insert emoji at cursor position
        input.value = text.substring(0, start) + emoji + text.substring(end);
        
        // Move cursor after emoji
        const newPos = start + emoji.length;
        input.setSelectionRange(newPos, newPos);
        input.focus();
    }
    
    initGifPicker() {
        const gifButton = document.getElementById('gif-button');
        const gifPicker = document.getElementById('gif-picker');
        const gifSearchInput = document.getElementById('gif-search-input');
        const gifGrid = document.getElementById('gif-grid');
        const gifLoading = document.getElementById('gif-loading');
        
        if (!gifButton || !gifPicker) {
            console.warn('GIF picker not available');
            return;
        }
        
        // Check if GIFs are enabled via settings and load API key
        fetch('api/settings.php')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.settings.gif_enabled === 'true') {
                    gifButton.style.display = 'inline-block';
                    // Load Tenor API key from settings
                    this.tenorApiKey = data.settings.tenor_api_key || '';
                }
            })
            .catch(err => console.error('Failed to check GIF settings:', err));
        
        this.tenorClientKey = 'radiochatbox';
        
        // Toggle GIF picker
        gifButton.addEventListener('click', (e) => {
            e.stopPropagation();
            const isVisible = gifPicker.style.display === 'block';
            gifPicker.style.display = isVisible ? 'none' : 'block';
            gifButton.classList.toggle('active', !isVisible);
            
            // Hide emoji picker if open
            const emojiPicker = document.getElementById('emoji-picker');
            if (emojiPicker && emojiPicker.style.display === 'block') {
                emojiPicker.style.display = 'none';
                document.getElementById('emoji-button').classList.remove('active');
            }
            
            if (!isVisible) {
                // Load trending GIFs on open
                this.searchGifs('');
            }
        });
        
        // Auto-search as user types (debounced)
        let searchTimeout;
        gifSearchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const query = gifSearchInput.value.trim();
                this.searchGifs(query);
            }, 500); // Wait 500ms after user stops typing
        });
        
        // Also search on Enter key
        gifSearchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                clearTimeout(searchTimeout);
                const query = gifSearchInput.value.trim();
                this.searchGifs(query);
            }
        });
        
        // Close picker when clicking outside
        document.addEventListener('click', (e) => {
            if (!gifPicker.contains(e.target) && e.target !== gifButton) {
                gifPicker.style.display = 'none';
                gifButton.classList.remove('active');
            }
        });
    }
    
    async searchGifs(query) {
        const gifGrid = document.getElementById('gif-grid');
        const gifLoading = document.getElementById('gif-loading');
        
        if (!gifGrid) return;
        
        gifGrid.innerHTML = '';
        gifLoading.style.display = 'block';
        
        // Check if API key is configured
        if (!this.tenorApiKey) {
            gifLoading.style.display = 'none';
            gifGrid.innerHTML = '<div style="padding: 20px; text-align: center; color: #ef4444; font-size: 13px;">⚠️ Tenor API key not configured.<br><br>Admin: Go to Settings and add your free API key from <a href="https://developers.google.com/tenor/guides/quickstart" target="_blank" style="color: #667eea;">Google Tenor</a></div>';
            return;
        }
        
        try {
            // Use Tenor API v2
            const endpoint = query 
                ? `https://tenor.googleapis.com/v2/search?q=${encodeURIComponent(query)}&key=${this.tenorApiKey}&client_key=${this.tenorClientKey}&limit=12`
                : `https://tenor.googleapis.com/v2/featured?key=${this.tenorApiKey}&client_key=${this.tenorClientKey}&limit=12`;
            
            const response = await fetch(endpoint);
            const data = await response.json();
            
            gifLoading.style.display = 'none';
            
            if (data.results && data.results.length > 0) {
                data.results.forEach(gif => {
                    const gifItem = document.createElement('div');
                    gifItem.className = 'gif-item';
                    
                    const img = document.createElement('img');
                    // Use tinygif for preview, mediumgif for sending
                    img.src = gif.media_formats.tinygif?.url || gif.media_formats.gif?.url;
                    img.alt = gif.content_description || 'GIF';
                    img.loading = 'lazy';
                    
                    gifItem.appendChild(img);
                    
                    gifItem.addEventListener('click', () => {
                        const gifUrl = gif.media_formats.mediumgif?.url || gif.media_formats.gif?.url;
                        this.insertGif(gifUrl);
                        document.getElementById('gif-picker').style.display = 'none';
                        document.getElementById('gif-button').classList.remove('active');
                    });
                    
                    gifGrid.appendChild(gifItem);
                });
            } else {
                gifGrid.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">No GIFs found</div>';
            }
        } catch (error) {
            console.error('Error fetching GIFs:', error);
            gifLoading.style.display = 'none';
            const errorMsg = error.message && error.message.includes('API key') 
                ? 'Invalid API key. Admin: Update your Tenor API key in Settings.'
                : 'Failed to load GIFs. Please try again.';
            gifGrid.innerHTML = `<div style="padding: 20px; text-align: center; color: #ef4444; font-size: 13px;">${errorMsg}</div>`;
        }
    }
    
    insertGif(gifUrl) {
        // Send GIF directly via API to bypass browser emoji autocorrect
        // The input field emoji conversion was breaking the URL
        
        // If in private chat mode, send as private message
        if (this.privateChat.active && this.privateChat.withUser) {
            this.sendPrivateMessage(this.privateChat.withUser, gifUrl, null);
            return;
        }
        
        // Otherwise send as public message
        const data = {
            username: this.username,
            message: gifUrl,
            session_id: this.sessionId
        };

        fetch('api/send.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (!result.success) {
                console.error('Failed to send GIF:', result.error);
                alert('Failed to send GIF: ' + (result.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error sending GIF:', error);
            alert('Error sending GIF. Please try again.');
        });
    }
    
    showTitleNotification(text) {
        if (this.titleNotificationActive) return;
        this.titleNotificationActive = true;
        this.originalTitle = document.title;
        document.title = `${text} ${this.originalTitle}`;
        if (this.titleNotificationTimeout) clearTimeout(this.titleNotificationTimeout);
        this.titleNotificationTimeout = setTimeout(() => {
            this.clearTitleNotification();
        }, 10000);
    }

    clearTitleNotification() {
        if (this.titleNotificationActive) {
            document.title = this.originalTitle;
            this.titleNotificationActive = false;
        }
        if (this.titleNotificationTimeout) {
            clearTimeout(this.titleNotificationTimeout);
            this.titleNotificationTimeout = null;
        }
    }
    
    formatMessageText(text) {
        // Escape HTML first
        const escaped = this.escapeHtml(text);
        
        // Detect Tenor/Giphy GIF URLs and render as images
        const gifRegex = /(https?:\/\/(?:media\.tenor\.com|media[0-9]*\.giphy\.com|i\.giphy\.com)\/[^\s]+\.gif)/gi;
        let formatted = escaped.replace(gifRegex, (url) => {
            // Use a data attribute to mark this as a GIF to prevent Twemoji parsing on the URL
            return `<br><span class="gif-url" data-gif-url="${url}"><img src="${url}" alt="GIF" class="message-gif" style="max-width: 100%; max-height: 300px; border-radius: 8px; margin-top: 8px;"></span>`;
        });
        
        // Convert regular URLs to clickable links (excluding GIF URLs which are already handled)
        const urlRegex = /(https?:\/\/(?!(?:media\.tenor\.com|media[0-9]*\.giphy\.com|i\.giphy\.com)\/[^\s]+\.gif)[^\s<]+)/gi;
        formatted = formatted.replace(urlRegex, (url) => {
            return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="message-link">${url}</a>`;
        });
        
        return formatted;
    }
    
    decodeEmojiToText(text) {
        // Map of emojis that browsers commonly auto-convert back to their text equivalents
        const emojiMap = {
            '😕': ':/',
            '😃': ':D',
            '😛': ':P',
            '😎': ':)',
            '☹️': ':(',
            '😉': ';)',
            '😮': ':O',
            '😐': ':|'
        };
        
        // Replace emojis back to text, but only if they appear to be in URLs
        let result = text;
        for (const [emoji, textEquiv] of Object.entries(emojiMap)) {
            // Only replace if the emoji appears in what looks like a URL context
            // Check for protocol prefix before the emoji
            const urlPattern = new RegExp(`(https?${emoji})`, 'g');
            result = result.replace(urlPattern, `https${textEquiv}`);
        }
        
        return result;
    }
}

// Initialize chat when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.chatBox = new RadioChatBox();
    window.chat = window.chatBox; // Alias for delete button onclick
    // Setup sidebar toggle for desktop
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    if (sidebarToggle && sidebar) {
        // Auto-collapse on embedded mode (not mobile - mobile uses different button)
        if (window.chatBox.isEmbedded && !window.chatBox.isMobile) {
            sidebar.classList.add('collapsed');
        }
        sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent bubbling to document click handler
            e.preventDefault(); // Prevent any default action
            // If sidebar is in mobile-open mode, always close it
            if (sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
            } else {
                // Normal desktop toggle for collapsed state
                sidebar.classList.toggle('collapsed');
            }
        });
    }
    // Setup mobile sidebar toggle
    const mobileSidebarToggle = document.getElementById('sidebar-toggle-mobile');
    if (mobileSidebarToggle && sidebar) {
        mobileSidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent bubbling to document click handler
            sidebar.classList.toggle('mobile-open');
        });
        // Close sidebar when clicking outside on mobile or embedded mode
        document.addEventListener('click', (e) => {
            if ((window.chatBox.isMobile || window.chatBox.isEmbedded) && 
                sidebar.classList.contains('mobile-open') && 
                !sidebar.contains(e.target) && 
                e.target !== mobileSidebarToggle) {
                sidebar.classList.remove('mobile-open');
            }
        });
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.chatBox) {
        window.chatBox.disconnect();
    }
});
