/**
 * RadioChatBox - Client-side JavaScript
 * Real-time chat using Server-Sent Events (SSE)
 */

/**
 * GIF provider definitions. Each provider knows how to build its request URL
 * and normalize its response so the picker code stays provider-agnostic.
 *
 * NOTE: Klipy's response shape and CDN domain are implemented best-effort from
 * its public docs and MUST be verified against a live API key. If GIFs load but
 * don't render inside messages, check the CDN host against the klipy.com entry
 * in the GIF-rendering regexes (formatMessageText + MessageFilter.php).
 */
const GIF_PROVIDERS = {
    giphy: {
        label: 'Giphy',
        docsUrl: 'https://developers.giphy.com/',
        docsLabel: 'developers.giphy.com',
        buildEndpoint(query, key, rating) {
            return query
                ? `https://api.giphy.com/v1/gifs/search?api_key=${key}&q=${encodeURIComponent(query)}&limit=12&rating=${rating}`
                : `https://api.giphy.com/v1/gifs/trending?api_key=${key}&limit=12&rating=${rating}`;
        },
        // Giphy signals auth/quota errors via meta.status (e.g. 401/403/429)
        getError(data) {
            return (data && data.meta && data.meta.status >= 400) ? (data.meta.msg || 'API key') : null;
        },
        getItems(data) {
            return (data && data.data) || [];
        },
        getTitle(item) {
            return item.title;
        },
        getPreviewUrl(item) {
            const img = item.images || {};
            return (img.fixed_width_small && img.fixed_width_small.url)
                || (img.fixed_width && img.fixed_width.url) || null;
        },
        getSendUrl(item) {
            const img = item.images || {};
            const raw = (img.downsized_medium && img.downsized_medium.url)
                || (img.original && img.original.url) || null;
            return raw ? raw.split('?')[0] : raw;
        },
    },
    klipy: {
        label: 'Klipy',
        docsUrl: 'https://klipy.com/api',
        docsLabel: 'klipy.com/api',
        buildEndpoint(query, key, rating) {
            // Klipy passes the app key in the path, not as a query param
            return query
                ? `https://api.klipy.com/api/v1/${key}/gifs/search?q=${encodeURIComponent(query)}&per_page=12&rating=${rating}`
                : `https://api.klipy.com/api/v1/${key}/gifs/trending?per_page=12&rating=${rating}`;
        },
        // Klipy wraps success in { result: true, data: {...} }
        getError(data) {
            return (data && data.result === false) ? 'API key' : null;
        },
        getItems(data) {
            return (data && data.data && data.data.data) || [];
        },
        getTitle(item) {
            return item.title || item.slug;
        },
        // Small, fast thumbnail for the picker grid
        getPreviewUrl(item) {
            const pick = klipyPickGif(item, KLIPY_PREVIEW_MAX_BYTES);
            return pick ? pick.url : null;
        },
        // For sending, Klipy's md/hd gifs are often multi-MB (up to ~6MB) which
        // load slowly or hit browser GIF-decode limits and appear broken. Pick
        // the largest rendition under a byte budget so chat GIFs stay reliable.
        getSendUrl(item) {
            const pick = klipyPickGif(item, KLIPY_SEND_MAX_BYTES);
            return pick ? pick.url.split('?')[0] : null;
        },
    },
};

// Byte budgets for choosing a Klipy gif rendition (Klipy returns per-rendition size)
const KLIPY_PREVIEW_MAX_BYTES = 400 * 1024;   // thumbnails: keep the grid light
const KLIPY_SEND_MAX_BYTES = 1.5 * 1024 * 1024; // sent gifs: reliable to load in chat

/**
 * Picks a gif rendition from a Klipy item by byte size: the largest one at or
 * under maxBytes (best quality that still loads fast); if all exceed the budget,
 * the smallest available. Klipy nests urls as item.file[size].gif.{url,size};
 * the wrapper key varies by docs version (`file` vs `files`), so probe both.
 * Returns {url, bytes, size} or null.
 */
function klipyPickGif(item, maxBytes) {
    if (!item) return null;
    const files = item.file || item.files || {};
    const candidates = [];
    for (const size of ['xs', 'sm', 'md', 'hd']) {
        const entry = files[size];
        if (!entry) continue;
        const gif = entry.gif || entry; // tolerate a flatter shape
        const url = (gif && gif.url) || (typeof entry === 'string' ? entry : null);
        if (!url) continue;
        candidates.push({ url, bytes: (gif && gif.size) || 0, size });
    }
    if (!candidates.length) return null;
    const underBudget = candidates
        .filter(c => !maxBytes || c.bytes <= maxBytes)
        .sort((a, b) => b.bytes - a.bytes);
    if (underBudget.length) return underBudget[0];
    // Everything exceeds the budget — send the smallest we have.
    return candidates.sort((a, b) => a.bytes - b.bytes)[0];
}

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
        // Realtime transport state. The token proves our username to BOTH the SSE
        // stream (?token=) and the WS server; the WS fields are only used when the
        // server advertises the WebSocket transport (else we stay on SSE).
        this.realtimeToken = null;
        this._ws = null;
        this._wsSocketId = null;
        this._wsAwaitingToken = false;
        this._wsFellBack = false;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 999; // Essentially unlimited
        this.reconnectDelay = 2000; // Start with 2 second delay
        this.maxReconnectDelay = 30000; // Max 30 seconds between retries
        this.sessionId = this.getOrCreateSessionId();
        this.username = null;
        this.lastMessageId = null; // Track last received message ID for catch-up on reconnect
        this.heartbeatInterval = null;
        this.soundEnabled = localStorage.getItem('chatSoundEnabled') !== 'false'; // default to true
        // Notification preferences (client-side, persisted). DND suppresses ALL
        // sounds/toasts/title cues; reactionToasts gates the "X reacted" toast.
        this.dndEnabled = localStorage.getItem('chatDnd') === 'true';
        this.reactionToastsEnabled = localStorage.getItem('chatReactionToasts') !== 'false';
        // Apply the saved dark-mode preference as early as possible.
        if (localStorage.getItem('chatDarkMode') === 'true') {
            try { document.body.classList.add('dark-mode'); } catch (e) { /* body not ready */ }
        }
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
        this.activeUsersList = []; // Track active users for filtering conversations

        // DOM elements (will be initialized after nickname selection)
        this.messagesContainer = null;
        this.messageInput = null;
        this.sendButton = null;
        this.statusIndicator = null;
        this.statusText = null;
        this.activeUsersContainer = null;
        this.activeUsersCount = null;

        // Infinite scroll state
        this.isLoadingMoreMessages = false; // Prevent duplicate requests while loading
        this.messagesOffset = 0; // Track offset for pagination
        this.hasMoreMessages = true; // Flag to know if there are more messages to load
        this.hasInitialScrolled = false; // Prevent infinite scroll from triggering before initial scroll to bottom

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

        // Network came back (mobile flaps in/out of coverage): re-establish the
        // realtime transport and catch up on anything missed while offline.
        window.addEventListener('online', () => {
            if (this.username) {
                this.ensureRealtimeFresh(9000); // treat as a real suspend → force reconnect
            }
        });
    }
    
    handleVisibilityChange() {
        // Remember when we were backgrounded so we can tell a quick tab-switch
        // from a long suspend (mobile freezes the socket while in the background).
        if (document.hidden) {
            this._hiddenSince = Date.now();
            return;
        }
        if (!this.username) {
            return;
        }

        // Check if adminToken was cleared (user logged out from admin)
        const adminToken = localStorage.getItem('adminToken');
        const wasLoggedInAsAdmin = this.userRole && ['root', 'administrator', 'moderator'].includes(this.userRole);
        if (wasLoggedInAsAdmin && !adminToken) {
            this.logoutUser();
            return;
        }

        const hiddenMs = this._hiddenSince ? (Date.now() - this._hiddenSince) : 0;
        this._hiddenSince = null;
        this.ensureRealtimeFresh(hiddenMs);
    }

    /**
     * Make sure the realtime transport is live and no messages were missed while
     * the tab was hidden. Mobile browsers suspend the socket in the background and
     * it often comes back a zombie that still reports OPEN, so after a longer
     * suspend we force a fresh connection; a brief tab-switch just catches up over
     * HTTP. Either way fetchMissedMessages() fills any gap.
     */
    ensureRealtimeFresh(hiddenMs = 0) {
        if (!this.username) {
            return;
        }
        const wsOpen = this._ws && this._ws.readyState === 1;
        const sseOpen = this.eventSource && this.eventSource.readyState === 1;
        const staleAfterSuspend = hiddenMs > 8000;

        const forceReconnect = () => {
            this.disconnect();
            this.reconnectAttempts = 0; // returning to foreground → reconnect now, no backoff
            this.connect();
            // Give the fresh connection a moment, then reconcile against the server.
            setTimeout(() => this.fetchMissedMessages(), 800);
        };

        // Nothing live at all → reconnect immediately.
        if (!wsOpen && !sseOpen) {
            forceReconnect();
            return;
        }

        // A WebSocket that survived a background suspend often reports OPEN while
        // being a zombie. Rather than tear down a possibly-healthy socket on every
        // return-to-foreground (needless churn, and how sockets used to pile up),
        // probe it: send a ping and reconnect ONLY if no pong comes back shortly.
        if (wsOpen && staleAfterSuspend) {
            const probeAt = Date.now();
            this._wsSend('pusher:ping', {});
            setTimeout(() => {
                if ((this._lastPongAt || 0) >= probeAt) {
                    this.fetchMissedMessages(); // alive — just reconcile
                } else {
                    forceReconnect(); // no pong → genuinely dead
                }
            }, 3000);
            return;
        }

        // SSE after a long suspend: it has no app-level pong to probe, and an SSE
        // reconnect is cheap, so refresh it.
        if (sseOpen && staleAfterSuspend) {
            forceReconnect();
            return;
        }

        // Healthy and only briefly hidden — just reconcile in case a beat was missed.
        this.fetchMissedMessages();
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
        if (this._ws) {
            try { this._ws.onclose = null; this._ws.close(); } catch (_) {}
            this._ws = null;
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
        
        // Check if we have admin credentials but no active chat session
        // This handles the case where session expired and storage was already cleared
        if (!savedNickname || !savedUserId) {
            const adminToken = localStorage.getItem('adminToken');
            if (adminToken && adminToken.includes(':')) {
                const [username, password] = adminToken.split(':');
                console.log('No active session but admin token found - attempting automatic login');
                try {
                    // Attempt automatic login with admin credentials
                    await this.loginAndJoin(username, password);
                    return;
                } catch (loginError) {
                    console.error('Automatic login failed:', loginError);
                    // Clear invalid admin token
                    localStorage.removeItem('adminToken');
                    localStorage.removeItem('isAdmin');
                    // Fall through to show login modal
                }
            }
        }
        
        if (savedNickname) {
            // Check if this is an authenticated user (has userId from login)
            if (savedUserId) {
                // Authenticated user - verify session is still valid on server
                try {
                    const response = await fetch(`${this.apiUrl}/api/heartbeat`, {
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
                        // Get role from heartbeat response (more reliable than storage in iframes)
                        this.userRole = data.user_role || this.getStorage('userRole');
                        // Save to storage for future use
                        if (data.user_role) {
                            this.setStorage('userRole', data.user_role);
                        }
                        
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
                        // Session invalid or user_id mismatch
                        // Check if we have admin credentials to automatically re-login
                        const adminToken = localStorage.getItem('adminToken');
                        if (adminToken && adminToken.includes(':')) {
                            const [username, password] = adminToken.split(':');
                            // Check if the saved username matches the admin username
                            if (username === savedNickname) {
                                console.log('Session expired but admin token found - attempting automatic re-login');
                                try {
                                    // Clear old session data first
                                    this.setStorage('chatNickname', null);
                                    this.setStorage('userId', null);
                                    this.setStorage('userRole', null);
                                    
                                    // Attempt automatic re-login with admin credentials
                                    await this.loginAndJoin(username, password);
                                    return;
                                } catch (loginError) {
                                    console.error('Automatic re-login failed:', loginError);
                                    // Clear invalid admin token
                                    localStorage.removeItem('adminToken');
                                    localStorage.removeItem('isAdmin');
                                    // Fall through to show login modal
                                }
                            }
                        }
                        
                        // Session invalid and no valid admin token - clear stored data and show login
                        console.warn('Session validation failed - user_id mismatch or session expired');
                        this.setStorage('chatNickname', null);
                        this.setStorage('userId', null);
                        this.setStorage('userRole', null);
                        this.showNicknameModal();
                        return;
                    }
                } catch (error) {
                    console.error('Session validation failed:', error);
                    // Check if we have admin credentials to automatically re-login
                    const adminToken = localStorage.getItem('adminToken');
                    if (adminToken && adminToken.includes(':')) {
                        const [username, password] = adminToken.split(':');
                        if (username === savedNickname) {
                            console.log('Session validation error but admin token found - attempting automatic re-login');
                            try {
                                // Clear old session data first
                                this.setStorage('chatNickname', null);
                                this.setStorage('userId', null);
                                this.setStorage('userRole', null);
                                
                                // Attempt automatic re-login with admin credentials
                                await this.loginAndJoin(username, password);
                                return;
                            } catch (loginError) {
                                console.error('Automatic re-login failed:', loginError);
                                // Clear invalid admin token
                                localStorage.removeItem('adminToken');
                                localStorage.removeItem('isAdmin');
                                // Fall through to show login modal
                            }
                        }
                    }
                    
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
            const response = await fetch(`${this.apiUrl}/api/settings?t=${Date.now()}`, {
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
                    this.applyColorScheme(this.settings.color_scheme);
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

                // Top charts button + in-chat radio player (both settings-gated).
                this.initRadioExtras();
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

            // Only show the microphone logo when a radio stream is actually
            // configured. Otherwise users mistakenly assume they can voice chat.
            // (Remembered so it can be restored when a cover is cleared.)
            this._micConfigured = urlSet;
            const micLogo = document.getElementById('mic-logo');
            if (micLogo) {
                micLogo.style.display = urlSet ? 'inline' : 'none';
            }

            const el = document.getElementById('now-playing');
            if (!urlSet || !el) {
                if (el) el.style.display = 'none';
                return;
            }

            // Fetch once now; thereafter the server pushes a 'now_playing' event on
            // every track change (Scheduler -> chat:updates), so no client polling.
            this.updateNowPlaying();
        } catch (e) {
            // Non-fatal
            console.warn('initNowPlaying error', e);
        }
    }

    async updateNowPlaying() {
        const el = document.getElementById('now-playing');
        if (!el) return;
        try {
            const resp = await fetch(`${this.apiUrl}/api/now-playing?t=${Date.now()}`, { cache: 'no-cache' });
            const data = await resp.json();
            this.renderNowPlaying(data && data.success ? data.nowPlaying : null);
        } catch (e) {
            // hide on error
            el.style.display = 'none';
        }
    }

    /**
     * Render the now-playing widget from a nowPlaying object. Used both by the
     * one-shot fetch (on connect) and by the 'now_playing' socket event the server
     * pushes when the track changes — so the browser never polls for it.
     */
    renderNowPlaying(nowPlaying) {
        const el = document.getElementById('now-playing');
        if (!el) return;
        // Mirror the track into the in-chat player bar (when it is the active
        // now-playing surface).
        this.syncPlayerMeta(nowPlaying);
        // Refresh the up/down tally for the (possibly new) current track.
        this.refreshTrackVote();
        if (nowPlaying && nowPlaying.active && nowPlaying.display) {
            el.textContent = `🎵 Now Playing: ${nowPlaying.display}`;
            // Remember the current track so it can be pinned to a message.
            this.currentTrack = nowPlaying.display;
            this._nowPlayingMeta = nowPlaying.meta || null;
            this.updatePinTrackButton();
            // Fetch the cover art (only when the track actually changes).
            this.updateNowPlayingCover(nowPlaying.artist || '', nowPlaying.title || nowPlaying.display || '', nowPlaying.feed_cover || null);

            // Check if user is admin (also check stored role if userRole not set yet)
            const userRole = this.userRole || this.getStorage('userRole');
            if (userRole && ['root'].includes(userRole)) {
                if (nowPlaying.listeners !== null && nowPlaying.listeners !== undefined) {
                    el.title = `${nowPlaying.listeners} listener${nowPlaying.listeners === 1 ? '' : 's'}`;
                }
            } else {
                el.removeAttribute('title');
            }

            // When the player bar is the now-playing surface, the header widget
            // stays hidden (no duplication) — the bar shows the track instead.
            el.style.display = this._playerActive ? 'none' : 'block';
        } else {
            el.style.display = 'none';
            this.currentTrack = null;
            this.updatePinTrackButton();
            this.hideNowPlayingCover();
        }
    }

    /** Fetch + show the cover art for the current track (cached; only on change). */
    async updateNowPlayingCover(artist, title, feedCover = null) {
        const img = document.getElementById('now-playing-cover');
        if (!img) return;
        const key = `${artist}|${title}`;
        if (key === this._coverKey) return; // already handled this track
        this._coverKey = key;

        let full = null;
        let thumb = null;
        try {
            const params = new URLSearchParams({ artist, title });
            const resp = await fetch(`${this.apiUrl}/api/artwork?${params.toString()}`);
            const data = await resp.json();
            // Prefer the album cover; fall back to the artist image when there
            // is no cover.
            full = data && data.success ? (data.cover || data.artist_image) : null;
            thumb = data && data.success ? (data.cover_thumb || data.artist_image_thumb || full) : null;
        } catch (e) {
            // Network/lookup error — fall through to the stream-provided cover.
        }

        // Fall back to the cover art the radio stream itself provides when local
        // artwork is unavailable (a Deezer/iTunes miss, or the image could not be
        // stored locally). Keeps a cover showing whenever the feed carries one.
        if (!full && feedCover) {
            full = feedCover;
            thumb = feedCover;
        }

        if (full && key === this._coverKey && this._playerActive) {
            // The player bar owns the cover; keep the header cover hidden.
            this.syncPlayerCover(thumb || full);
            return;
        }

        if (full && key === this._coverKey) {
            // Small display uses the thumbnail; the lightbox uses the full image.
            img.src = thumb || full;
            img.dataset.full = full;
            img.style.display = 'block';
            if (!img._lightboxBound) {
                img.addEventListener('click', () => {
                    const clickFull = img.dataset.full;
                    if (!clickFull) return;
                    this._galleryPhotos = [{ url: clickFull, from: this.currentTrack || '' }];
                    this.openLightbox(0);
                });
                img.addEventListener('mouseenter', () => this.showNowPlayingCard(img));
                img.addEventListener('mouseleave', () => this.scheduleHideNowPlayingCard());
                img._lightboxBound = true;
            }
            // A cover replaces the mic logo.
            const mic = document.getElementById('mic-logo');
            if (mic) mic.style.display = 'none';
        } else if (key === this._coverKey) {
            this.hideNowPlayingCover();
        }
    }

    hideNowPlayingCover() {
        const img = document.getElementById('now-playing-cover');
        if (img) {
            img.style.display = 'none';
            img.removeAttribute('src');
            delete img.dataset.full;
        }
        // Restore the mic logo if a radio stream is configured.
        const mic = document.getElementById('mic-logo');
        if (mic && this._micConfigured) mic.style.display = 'inline';
        // Clear the player-bar cover too (falls back to its placeholder icon).
        this.syncPlayerCover(null);
    }

    // ==================================================================
    // Top charts panel + in-chat radio player (roadmap: Now-Playing
    // Enhancements). Both are settings-gated so a station opts in.
    // ==================================================================

    /** A setting stored as a string is "on" for true/1/on/yes. */
    _settingOn(value, defaultOn = false) {
        if (value === undefined || value === null || value === '') return defaultOn;
        return ['1', 'true', 'on', 'yes'].includes(String(value).toLowerCase().trim());
    }

    initRadioExtras() {
        try { this.initCharts(); } catch (e) { console.warn('initCharts error', e); }
        try { this.initPlayer(); } catch (e) { console.warn('initPlayer error', e); }
        try { this.initSongRequests(); } catch (e) { console.warn('initSongRequests error', e); }
        try { this.initTrackVoting(); } catch (e) { console.warn('initTrackVoting error', e); }
        try { this.initTypingIndicators(); } catch (e) { console.warn('initTypingIndicators error', e); }
        try { this.initPolls(); } catch (e) { console.warn('initPolls error', e); }
    }

    // ---- Now-playing voting -----------------------------------------

    initTrackVoting() {
        const widget = document.getElementById('track-vote');
        if (!widget) return;
        this._trackVotingEnabled = this._settingOn(this.settings && this.settings.track_voting_enabled);
        if (!this._trackVotingEnabled) {
            widget.style.display = 'none';
            return;
        }
        if (!this._trackVoteBound) {
            this._trackVoteBound = true;
            const up = document.getElementById('tv-up');
            const down = document.getElementById('tv-down');
            if (up) up.addEventListener('click', () => this.castTrackVote('up'));
            if (down) down.addEventListener('click', () => this.castTrackVote('down'));
        }
        // Fetch the tally for whatever is playing now.
        this.refreshTrackVote();
    }

    async refreshTrackVote() {
        const widget = document.getElementById('track-vote');
        if (!widget || !this._trackVotingEnabled) return;
        try {
            const q = this.sessionId ? `?session_id=${encodeURIComponent(this.sessionId)}` : '';
            const resp = await fetch(`${this.apiUrl}/api/songs/vote${q}`);
            if (!resp.ok) { widget.style.display = 'none'; return; }
            const data = await resp.json();
            if (data && data.success && data.track) {
                this.renderTrackVote(data);
            } else {
                widget.style.display = 'none'; // nothing playing
            }
        } catch (e) {
            widget.style.display = 'none';
        }
    }

    renderTrackVote(data) {
        const widget = document.getElementById('track-vote');
        if (!widget) return;
        const upc = document.getElementById('tv-up-count');
        const downc = document.getElementById('tv-down-count');
        if (upc) upc.textContent = data.up ?? 0;
        if (downc) downc.textContent = data.down ?? 0;
        const up = document.getElementById('tv-up');
        const down = document.getElementById('tv-down');
        if (up) up.classList.toggle('active', (data.my_vote || 0) > 0);
        if (down) down.classList.toggle('active', (data.my_vote || 0) < 0);
        widget.style.display = 'inline-flex';
    }

    async castTrackVote(direction) {
        if (!this._trackVotingEnabled) return;
        if (!this.username) { alert('Please join the chat first to vote.'); return; }
        try {
            const resp = await fetch(`${this.apiUrl}/api/songs/vote`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId, direction }),
            });
            const data = await resp.json().catch(() => ({}));
            if (resp.ok && data.success) {
                this.renderTrackVote(data);
            }
        } catch (e) {
            // ignore — the counts just won't update
        }
    }

    // ---- Listener song requests -------------------------------------

    initSongRequests() {
        const btn = document.getElementById('song-request-button');
        if (!btn) return;

        if (!this._settingOn(this.settings && this.settings.song_requests_enabled)) {
            btn.style.display = 'none';
            return;
        }
        btn.style.display = 'inline-flex';

        if (this._songReqBound) return;
        this._songReqBound = true;

        const overlay = document.getElementById('song-request-overlay');
        btn.addEventListener('click', () => this.openSongRequest());
        const closeBtn = document.getElementById('song-request-close');
        if (closeBtn) closeBtn.addEventListener('click', () => this.closeSongRequest());
        if (overlay) {
            overlay.addEventListener('click', (e) => { if (e.target === overlay) this.closeSongRequest(); });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && overlay.style.display === 'flex') this.closeSongRequest();
            });
        }
        const form = document.getElementById('song-request-form');
        if (form) form.addEventListener('submit', (e) => { e.preventDefault(); this.submitSongRequest(); });

        // Populate the artist autocomplete from the known artist list (once).
        this.loadArtistSuggestions();
    }

    async loadArtistSuggestions() {
        const dl = document.getElementById('sr-artist-list');
        if (!dl || this._artistSuggestionsLoaded) return;
        this._artistSuggestionsLoaded = true;
        try {
            const resp = await fetch(`${this.apiUrl}/api/songs/artists`);
            if (!resp.ok) return;
            const data = await resp.json();
            const names = (data && data.artists) || [];
            dl.innerHTML = names.map((n) => `<option value="${this.escapeHtml(n)}"></option>`).join('');
        } catch (e) {
            // Autocomplete is a nicety — a failure just means a plain text field.
            this._artistSuggestionsLoaded = false;
        }
    }

    openSongRequest() {
        const overlay = document.getElementById('song-request-overlay');
        if (!overlay) return;
        const err = document.getElementById('sr-error');
        if (err) { err.style.display = 'none'; err.textContent = ''; err.classList.remove('sr-ok'); }
        overlay.style.display = 'flex';
        const title = document.getElementById('sr-song-title');
        if (title) setTimeout(() => title.focus(), 30);
    }

    closeSongRequest() {
        const overlay = document.getElementById('song-request-overlay');
        if (overlay) overlay.style.display = 'none';
    }

    async submitSongRequest() {
        const err = document.getElementById('sr-error');
        const submitBtn = document.getElementById('sr-submit');
        const showError = (msg) => {
            if (!err) return;
            err.classList.remove('sr-ok');
            err.textContent = msg;
            err.style.display = 'block';
        };

        if (!this.username) {
            showError('Please join the chat first.');
            return;
        }
        const songTitle = (document.getElementById('sr-song-title').value || '').trim();
        if (songTitle === '') {
            showError('Please enter a song title.');
            return;
        }
        const artist = (document.getElementById('sr-artist').value || '').trim();
        const dedication = (document.getElementById('sr-dedication').value || '').trim();

        if (submitBtn) submitBtn.disabled = true;
        try {
            const resp = await fetch(`${this.apiUrl}/api/songs/request`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    username: this.username,
                    session_id: this.sessionId,
                    song_title: songTitle,
                    artist,
                    dedication,
                }),
            });
            const data = await resp.json().catch(() => ({}));
            if (resp.ok && data.success) {
                // Confirm inline (green), then reset + close shortly after.
                document.getElementById('song-request-form').reset();
                if (err) {
                    err.textContent = '🎵 Your request was sent!';
                    err.classList.add('sr-ok');
                    err.style.display = 'block';
                }
                setTimeout(() => {
                    if (err) { err.classList.remove('sr-ok'); err.style.display = 'none'; err.textContent = ''; }
                    this.closeSongRequest();
                }, 1400);
            } else {
                showError((data && data.error) || 'Could not send your request. Please try again.');
            }
        } catch (e) {
            showError('Network error. Please try again.');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    // ---- Top charts panel -------------------------------------------

    initCharts() {
        const btn = document.getElementById('charts-button');
        if (!btn) return;

        if (!this._settingOn(this.settings && this.settings.charts_enabled)) {
            btn.style.display = 'none';
            return;
        }

        let period = (this.settings && this.settings.charts_default_period) || 'day';
        if (!['day', 'week', 'month'].includes(period)) period = 'day';
        this._chartsPeriod = period;

        btn.style.display = 'flex'; // .icon-button is a flex box

        if (this._chartsBound) return;
        this._chartsBound = true;

        const overlay = document.getElementById('charts-overlay');
        btn.addEventListener('click', () => this.openCharts());
        const closeBtn = document.getElementById('charts-close');
        if (closeBtn) closeBtn.addEventListener('click', () => this.closeCharts());
        if (overlay) {
            overlay.addEventListener('click', (e) => { if (e.target === overlay) this.closeCharts(); });
            overlay.querySelectorAll('.charts-period').forEach((b) => {
                b.classList.toggle('active', b.dataset.period === this._chartsPeriod);
                b.addEventListener('click', () => {
                    this._chartsPeriod = b.dataset.period;
                    overlay.querySelectorAll('.charts-period').forEach((x) => x.classList.toggle('active', x === b));
                    this.loadCharts();
                });
            });
        }
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay && overlay.style.display === 'flex') this.closeCharts();
        });
    }

    openCharts() {
        const overlay = document.getElementById('charts-overlay');
        if (!overlay) return;
        overlay.style.display = 'flex';
        this.loadCharts();
    }

    closeCharts() {
        const overlay = document.getElementById('charts-overlay');
        if (overlay) overlay.style.display = 'none';
    }

    async loadCharts() {
        const period = this._chartsPeriod || 'day';
        const tracksEl = document.getElementById('charts-tracks');
        const artistsEl = document.getElementById('charts-artists');
        if (!tracksEl || !artistsEl) return;
        tracksEl.innerHTML = '<li class="charts-empty">Loading…</li>';
        artistsEl.innerHTML = '<li class="charts-empty">Loading…</li>';
        try {
            const [tracks, artists] = await Promise.all([
                fetch(`${this.apiUrl}/api/songs/top?type=tracks&period=${period}&limit=10`).then((r) => r.json()),
                fetch(`${this.apiUrl}/api/songs/top?type=artists&period=${period}&limit=10`).then((r) => r.json()),
            ]);
            this.renderChartList(tracksEl, (tracks && tracks.items) || [], 'tracks');
            this.renderChartList(artistsEl, (artists && artists.items) || [], 'artists');
        } catch (e) {
            tracksEl.innerHTML = '<li class="charts-empty">Failed to load</li>';
            artistsEl.innerHTML = '<li class="charts-empty">Failed to load</li>';
        }
    }

    renderChartList(el, items, type) {
        if (!items.length) {
            el.innerHTML = '<li class="charts-empty">No plays yet</li>';
            return;
        }
        el.innerHTML = items.map((it) => {
            const plays = parseInt(it.plays || 0, 10) || 0;
            if (type === 'artists') {
                const name = this.escapeHtml(it.artist || 'Unknown');
                const img = it.image_file
                    ? `<img class="chart-img" src="${this.escapeHtml(it.image_file)}" alt="" onerror="this.style.visibility='hidden'">`
                    : '';
                return `<li>${img}<span class="chart-name">${name}</span><span class="chart-plays">${plays}</span></li>`;
            }
            const label = it.display || `${it.artist ? it.artist + ' - ' : ''}${it.title || 'Unknown'}`;
            return `<li><span class="chart-name">${this.escapeHtml(label)}</span><span class="chart-plays">${plays}</span></li>`;
        }).join('');
    }

    // ---- In-chat radio player ---------------------------------------

    /**
     * Resolve whether the player shows for THIS embed. Precedence:
     *   if overrides allowed -> URL ?player= param > player_mode
     *   if overrides forbidden -> player_mode only
     * player_mode: off | on | iframe_only | app_only (embedding detected via
     * window.self !== window.top).
     */
    resolvePlayerMode() {
        const s = this.settings || {};
        const mode = s.player_mode || 'off';
        const inIframe = window.self !== window.top;

        if (this._settingOn(s.player_allow_override, true)) {
            const p = new URLSearchParams(window.location.search).get('player');
            if (p !== null) {
                if (['1', 'on', 'true', 'yes'].includes(p.toLowerCase())) return true;
                if (['0', 'off', 'false', 'no'].includes(p.toLowerCase())) return false;
            }
        }

        switch (mode) {
            case 'on': return true;
            case 'iframe_only': return inIframe;
            case 'app_only': return !inIframe;
            default: return false; // 'off' or unknown
        }
    }

    initPlayer() {
        const bar = document.getElementById('radio-player-bar');
        if (!bar) return;

        const streamUrl = ((this.settings && this.settings.player_stream_url) || '').trim();
        const show = this.resolvePlayerMode() && streamUrl !== '';
        this._playerActive = show;

        if (!show) {
            bar.style.display = 'none';
            return;
        }

        // The bar IS the now-playing surface: hide the header widget + cover so
        // the track is not shown twice.
        const npEl = document.getElementById('now-playing');
        if (npEl) npEl.style.display = 'none';
        this.hideNowPlayingCover();
        bar.style.display = 'flex';

        if (this._playerBound) {
            // Settings reloaded — just refresh the source and re-sync the track.
            const audioEl = document.getElementById('rp-audio');
            if (audioEl && audioEl.src !== streamUrl) audioEl.src = streamUrl;
            this.renderNowPlaying(this._lastNowPlaying || null);
            return;
        }
        this._playerBound = true;

        const audio = document.getElementById('rp-audio');
        const playBtn = document.getElementById('rp-play');
        const vol = document.getElementById('rp-volume');
        const volIcon = document.getElementById('rp-vol-icon');
        audio.src = streamUrl;
        audio.volume = 0.8;

        const setPlaying = (playing) => {
            playBtn.textContent = playing ? '⏸' : '▶';
            playBtn.title = playing ? 'Pause' : 'Play';
        };
        const updateVolIcon = () => {
            volIcon.textContent = (audio.muted || audio.volume === 0) ? '🔇' : '🔊';
        };

        playBtn.addEventListener('click', () => {
            if (audio.paused) {
                // A user gesture: unmute if the only reason it was muted was autoplay.
                if (audio.muted && !this._userMuted) { audio.muted = false; updateVolIcon(); }
                audio.play().catch(() => {});
            } else {
                audio.pause();
            }
        });
        audio.addEventListener('play', () => setPlaying(true));
        audio.addEventListener('playing', () => setPlaying(true));
        audio.addEventListener('pause', () => setPlaying(false));

        vol.addEventListener('input', () => {
            audio.volume = (parseInt(vol.value, 10) || 0) / 100;
            audio.muted = audio.volume === 0;
            this._userMuted = audio.muted;
            updateVolIcon();
        });
        volIcon.addEventListener('click', () => {
            audio.muted = !audio.muted;
            this._userMuted = audio.muted;
            if (!audio.muted && audio.volume === 0) { audio.volume = 0.5; vol.value = 50; }
            updateVolIcon();
        });

        updateVolIcon();
        setPlaying(false);

        // Autoplay policy: a live stream cannot autoplay with sound, so start
        // muted and let the user's first tap unmute. Only attempt if configured.
        if (this._settingOn(this.settings.player_autoplay)) {
            audio.muted = true;
            this._userMuted = false; // muted by policy, not by the user
            updateVolIcon();
            audio.play().catch(() => {});
        }
    }

    /** Update the player bar's title from a nowPlaying object (when active). */
    syncPlayerMeta(nowPlaying) {
        this._lastNowPlaying = nowPlaying || null;
        if (!this._playerActive) return;
        const titleEl = document.getElementById('rp-title');
        if (!titleEl) return;
        const s = this.settings || {};
        titleEl.textContent = (nowPlaying && nowPlaying.active && nowPlaying.display)
            ? nowPlaying.display
            : (s.brand_name || s.page_title || 'Live Radio');
    }

    /** Show/clear the player bar cover (falls back to a music-note placeholder). */
    syncPlayerCover(url) {
        if (!this._playerActive) return;
        const img = document.getElementById('rp-cover-img');
        const ph = document.getElementById('rp-cover-ph');
        if (!img) return;
        if (url) {
            img.src = url;
            img.style.display = 'block';
            if (ph) ph.style.display = 'none';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
            if (ph) ph.style.display = '';
        }
    }

    /** Hover card next to the now-playing cover with track/album/artist info. */
    showNowPlayingCard(anchor) {
        clearTimeout(this._npCardTimer);
        const full = anchor.dataset.full;
        const meta = this._nowPlayingMeta || {};
        // Nothing useful to show beyond the cover? Still show the enlarged cover.
        let card = this._npCard;
        if (!card) {
            card = document.createElement('div');
            card.className = 'now-playing-card';
            card.addEventListener('mouseenter', () => clearTimeout(this._npCardTimer));
            card.addEventListener('mouseleave', () => this.scheduleHideNowPlayingCard());
            document.body.appendChild(card);
            this._npCard = card;
        }

        const rows = [];
        if (meta.artist) rows.push(`<div><span class="np-label">Artist:</span> ${this.escapeHtml(meta.artist)}</div>`);
        if (meta.album) rows.push(`<div><span class="np-label">Album:</span> ${this.escapeHtml(meta.album)}</div>`);
        if (meta.year) rows.push(`<div><span class="np-label">Year:</span> ${this.escapeHtml(meta.year)}</div>`);
        if (meta.genre) rows.push(`<div><span class="np-label">Genre:</span> ${this.escapeHtml(meta.genre)}</div>`);

        card.innerHTML = `
            ${full ? `<img class="np-card-cover" src="${this.escapeHtml(full)}" alt="">` : ''}
            <div class="np-card-body">
                <div class="np-card-title">${this.escapeHtml(this.currentTrack || '')}</div>
                ${rows.join('') || '<div style="color:#9ca3af;">No extra info yet</div>'}
            </div>`;

        const rect = anchor.getBoundingClientRect();
        card.style.display = 'flex';
        card.style.top = `${window.scrollY + rect.bottom + 6}px`;
        // clamp horizontally
        const w = card.offsetWidth || 300;
        let left = window.scrollX + rect.left;
        const maxLeft = window.scrollX + document.documentElement.clientWidth - w - 8;
        if (left > maxLeft) left = maxLeft;
        if (left < window.scrollX + 8) left = window.scrollX + 8;
        card.style.left = `${left}px`;
    }

    scheduleHideNowPlayingCard() {
        clearTimeout(this._npCardTimer);
        this._npCardTimer = setTimeout(() => {
            if (this._npCard) this._npCard.style.display = 'none';
        }, 200);
    }

    // ===================== Pin current track to a message =====================

    /** Show/hide the pin button based on track availability + public chat. */
    updatePinTrackButton() {
        if (!this.pinTrackButton) return;
        const available = !!this.currentTrack
            && !(this.privateChat && this.privateChat.active)
            && (this.chatMode === 'public' || this.chatMode === 'both');

        if (!available) {
            this.pinTrackButton.style.display = 'none';
            if (this.pinTrackActive) {
                this.pinTrackActive = false;
                this.pinTrackButton.classList.remove('active');
                this.renderPinTrackChip();
            }
            return;
        }

        this.pinTrackButton.style.display = 'inline-flex';
        if (!this.pinTrackActive) {
            this.pinTrackButton.title = `Pin the current track (${this.currentTrack}) to your message`;
        }
    }

    /** Toggle whether the current track is attached to the next message. */
    setPinTrack(active) {
        this.pinTrackActive = !!active && !!this.currentTrack;
        if (this.pinTrackButton) {
            this.pinTrackButton.classList.toggle('active', this.pinTrackActive);
            this.pinTrackButton.title = this.pinTrackActive
                ? `Pinned: ${this.currentTrack}`
                : (this.currentTrack ? `Pin the current track (${this.currentTrack}) to your message` : 'Pin the current track');
        }
        this.renderPinTrackChip();
    }

    /** Render (or remove) the little chip above the input showing the pinned track. */
    renderPinTrackChip() {
        if (this._pinChip) {
            this._pinChip.remove();
            this._pinChip = null;
        }
        if (!this.pinTrackActive || !this.currentTrack) return;

        const chip = document.createElement('div');
        chip.className = 'pin-track-chip';
        chip.innerHTML = `<span class="pin-track-chip-label">🎵 ${this.escapeHtml(this.currentTrack)}</span>
                          <button class="pin-track-remove" title="Remove">✕</button>`;
        const inputContainer = document.getElementById('chat-input-container');
        if (inputContainer && inputContainer.parentNode) {
            inputContainer.parentNode.insertBefore(chip, inputContainer);
        }
        chip.querySelector('.pin-track-remove').addEventListener('click', () => this.setPinTrack(false));
        this._pinChip = chip;
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
            const checkResponse = await fetch(`${this.apiUrl}/api/check-nickname`, {
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
            
            const registerResponse = await fetch(`${this.apiUrl}/api/register`, {
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

            const twofaInput = document.getElementById('login-2fa-input');
            const code = twofaInput && twofaInput.style.display !== 'none' ? twofaInput.value.trim() : '';
            try {
                await this.loginAndJoin(username, password, code);
            } catch (error) {
                if (error && error.twofaRequired) {
                    // Reveal the code field and ask for the authenticator code.
                    if (twofaInput) { twofaInput.style.display = 'block'; twofaInput.focus(); }
                    loginError.textContent = code ? 'Invalid code — try again.' : 'Enter the code from your authenticator app.';
                } else {
                    loginError.textContent = error.message;
                }
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

        // Passkey (passwordless) sign-in. Hide the button where WebAuthn is absent.
        const loginPasskeyBtn = document.getElementById('login-passkey-btn');
        if (loginPasskeyBtn) {
            if (!window.PublicKeyCredential) {
                loginPasskeyBtn.style.display = 'none';
            } else {
                loginPasskeyBtn.onclick = () => this.loginWithPasskey(loginUsernameInput ? loginUsernameInput.value : '');
            }
        }

        // ---- Register mode (only when self-registration is enabled) ----
        const registerModeBtn = document.getElementById('register-mode-btn');
        const registerForm = document.getElementById('register-form');
        const registerError = document.getElementById('register-error');
        const registerSubmit = document.getElementById('register-submit');
        const selfRegOn = this.settings && this.settings.self_registration_enabled === 'true';
        if (registerModeBtn && selfRegOn) registerModeBtn.style.display = '';

        const showRegister = () => {
            [guestModeBtn, loginModeBtn].forEach(b => { b.classList.remove('active'); b.style.background = 'transparent'; b.style.color = '#667eea'; });
            registerModeBtn.classList.add('active'); registerModeBtn.style.background = '#667eea'; registerModeBtn.style.color = 'white';
            guestForm.style.display = 'none'; loginForm.style.display = 'none'; registerForm.style.display = 'block';
            document.getElementById('nickname-error').textContent = '';
            const u = document.getElementById('register-username-input'); if (u) u.focus();
        };
        if (registerModeBtn) registerModeBtn.onclick = showRegister;

        const submitRegister = async () => {
            const username = (document.getElementById('register-username-input').value || '').trim();
            const email = (document.getElementById('register-email-input').value || '').trim();
            const password = document.getElementById('register-password-input').value;
            const password2 = document.getElementById('register-password2-input').value;
            registerError.textContent = '';
            if (!username) { registerError.textContent = 'Please choose a username'; return; }
            if (password.length < 8) { registerError.textContent = 'Password must be at least 8 characters'; return; }
            if (password !== password2) { registerError.textContent = 'Passwords do not match'; return; }
            registerSubmit.disabled = true; registerSubmit.textContent = 'Creating…';
            try {
                const resp = await fetch(`${this.apiUrl}/api/register-account`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, email, password, password_confirm: password2, sessionId: this.sessionId }),
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) throw new Error(data.error || 'Registration failed');
                // Auto-login into the freshly created account.
                await this.loginAndJoin(username, password);
            } catch (e) {
                registerError.textContent = e.message;
                registerSubmit.disabled = false; registerSubmit.textContent = 'Create account & Join';
            }
        };
        if (registerSubmit) registerSubmit.onclick = submitRegister;
        ['register-username-input', 'register-email-input', 'register-password-input', 'register-password2-input'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.onkeypress = (e) => { if (e.key === 'Enter') submitRegister(); };
        });
    }
    
    async loginAndJoin(username, password, code = '') {
        try {
            // Call login API
            const response = await fetch(`${this.apiUrl}/api/login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    username,
                    password,
                    sessionId: this.sessionId,
                    code: code || undefined
                })
            });

            const data = await response.json();

            // Two-factor step-up: signal the caller to collect a code and retry.
            if (data && data.twofa_required && !data.success) {
                const err = new Error('Two-factor code required');
                err.twofaRequired = true;
                throw err;
            }

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Login failed');
            }
            
            // Success! Set username and initialize chat
            this.username = data.user.username;
            this.userId = data.user.id;
            this.userRole = data.user.role;
            this.setStorage('chatNickname', this.username);
            this.setStorage('userId', this.userId);
            this.setStorage('userRole', this.userRole); // Store role for session restoration
            
            // If user has admin/moderator role, also set admin credentials for admin panel access
            if (['root', 'administrator', 'moderator'].includes(data.user.role)) {
                const credentials = `${username}:${password}`;
                localStorage.setItem('adminToken', credentials);
                localStorage.setItem('isAdmin', 'true');
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
        const profileDisplayName = document.getElementById('profile-display-name');
        const profileDisplayNameField = document.getElementById('profile-display-name-field');
        const profileAge = document.getElementById('profile-age');
        const profileSex = document.getElementById('profile-sex');
        const profileLocation = document.getElementById('profile-location');
        const profileEditFields = document.getElementById('profile-edit-fields');
        const profileSaveBtn = document.getElementById('profile-save');
        const profileSaveDisplayNameBtn = document.getElementById('profile-save-displayname');
        const profileError = document.getElementById('profile-error');
        
        // Populate country dropdown
        this.populateProfileCountryDropdown();
        
        // Set current values
        profileNickname.value = this.username;
        profileError.textContent = '';

        // Notification preference toggles (persist immediately on change).
        const dndEl = document.getElementById('pref-dnd');
        const toastEl = document.getElementById('pref-reaction-toasts');
        if (dndEl) {
            dndEl.checked = this.dndEnabled;
            dndEl.onchange = () => {
                this.dndEnabled = dndEl.checked;
                localStorage.setItem('chatDnd', this.dndEnabled ? 'true' : 'false');
            };
        }
        if (toastEl) {
            toastEl.checked = this.reactionToastsEnabled;
            toastEl.onchange = () => {
                this.reactionToastsEnabled = toastEl.checked;
                localStorage.setItem('chatReactionToasts', this.reactionToastsEnabled ? 'true' : 'false');
            };
        }
        const darkEl = document.getElementById('pref-dark-mode');
        if (darkEl) {
            darkEl.checked = document.body.classList.contains('dark-mode');
            darkEl.onchange = () => this.setDarkMode(darkEl.checked);
        }
        const fontEl = document.getElementById('pref-font-size');
        if (fontEl) {
            fontEl.value = localStorage.getItem('chatFontSize') || 'normal';
            fontEl.onchange = () => this.setFontSize(fontEl.value);
        }
        const soundStyleEl = document.getElementById('pref-sound-style');
        if (soundStyleEl) {
            soundStyleEl.value = localStorage.getItem('chatSoundStyle') || 'beep';
            soundStyleEl.onchange = () => {
                localStorage.setItem('chatSoundStyle', soundStyleEl.value);
                this.playNotificationSound(); // preview
            };
        }
        const hcEl = document.getElementById('pref-high-contrast');
        if (hcEl) {
            hcEl.checked = document.body.classList.contains('high-contrast');
            hcEl.onchange = () => {
                document.body.classList.toggle('high-contrast', hcEl.checked);
                localStorage.setItem('chatHighContrast', hcEl.checked ? 'true' : 'false');
            };
        }

        // Two-factor + passkey sections: only for registered accounts.
        this._initTwoFactorSection();
        this._initPasskeySection();

        // Pre-fill the bio/status from my own profile card (best-effort).
        (async () => {
            try {
                const r = await fetch(`${this.apiUrl}/api/user-profile/card?username=${encodeURIComponent(this.username)}`);
                const d = await r.json();
                if (d && d.success && d.profile) {
                    const bioEl = document.getElementById('profile-bio');
                    const statusEl = document.getElementById('profile-status');
                    if (bioEl && d.profile.bio) bioEl.value = d.profile.bio;
                    if (statusEl && d.profile.status) statusEl.value = d.profile.status;
                }
            } catch (e) { /* best-effort */ }
        })();

        // Check if user is authenticated (has userId from login)
        const isAuthenticated = !!this.userId;
        
        // Show display name field for authenticated users
        if (isAuthenticated && profileDisplayNameField) {
            profileDisplayNameField.style.display = 'block';
            profileSaveDisplayNameBtn.style.display = 'inline-block';
            
            // Load current display name
            this.loadCurrentProfile(profileDisplayName, profileAge, profileSex, profileLocation);
        } else if (profileDisplayNameField) {
            profileDisplayNameField.style.display = 'none';
            profileSaveDisplayNameBtn.style.display = 'none';
        }
        
        // Load current profile if profile fields are enabled
        if (this.settings.require_profile === 'true') {
            profileEditFields.style.display = 'block';
            profileSaveBtn.style.display = 'inline-block';
            if (!isAuthenticated) {
                this.loadCurrentProfile(profileDisplayName, profileAge, profileSex, profileLocation);
            }
        } else {
            profileEditFields.style.display = 'none';
            profileSaveBtn.style.display = 'none';
        }
        
        modal.style.display = 'flex';
        
        // Event listeners
        const closeBtn = document.getElementById('profile-close');
        const logoutBtn = document.getElementById('profile-logout');
        const saveBtn = document.getElementById('profile-save');
        const saveDisplayNameBtn = document.getElementById('profile-save-displayname');
        
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
                await this.saveProfile(profileDisplayName, profileAge, profileSex, profileLocation, profileError, saveBtn);
            };
        }
        
        if (saveDisplayNameBtn) {
            saveDisplayNameBtn.onclick = async () => {
                await this.saveDisplayNameOnly(profileDisplayName, profileError, saveDisplayNameBtn);
            };
        }
    }
    
    async loadCurrentProfile(displayNameInput, ageInput, sexInput, locationInput) {
        try {
            const response = await fetch(`${this.apiUrl}/api/user-profile?username=${encodeURIComponent(this.username)}`);
            const data = await response.json();
            
            if (data.success && data.profile) {
                displayNameInput.value = data.profile.display_name || '';
                ageInput.value = data.profile.age || '';
                sexInput.value = data.profile.sex || '';
                locationInput.value = data.profile.location || '';
            }
        } catch (error) {
            console.error('Failed to load profile:', error);
        }
    }
    
    async saveProfile(displayNameInput, ageInput, sexInput, locationInput, errorDiv, saveBtn) {
        const displayName = displayNameInput.value.trim();
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
            const response = await fetch(`${this.apiUrl}/api/update-profile`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    username: this.username,
                    sessionId: this.sessionId,
                    displayName: displayName || null,
                    age: age,
                    sex: sex,
                    location: location,
                    bio: (document.getElementById('profile-bio')?.value || '').trim(),
                    status_message: (document.getElementById('profile-status')?.value || '').trim()
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
    
    async saveDisplayNameOnly(displayNameInput, errorDiv, saveBtn) {
        const displayName = displayNameInput.value.trim();
        
        saveBtn.disabled = true;
        errorDiv.textContent = '';
        
        try {
            const response = await fetch(`${this.apiUrl}/api/update-profile`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    username: this.username,
                    sessionId: this.sessionId,
                    displayName: displayName || null
                })
            });

            const data = await response.json();

            if (data.success) {
                // Close the modal without alert
                document.getElementById('profile-modal').style.display = 'none';
                
                // Reload history to show updated display name
                // Wait for server to clear cache and complete database update
                setTimeout(() => {
                    this.reloadHistory();
                }, 800);
            } else {
                errorDiv.textContent = data.error || 'Failed to update display name';
            }
        } catch (error) {
            console.error('Error updating display name:', error);
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
            fetch(`${this.apiUrl}/api/logout`, {
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
        this.blockUserBtn = document.getElementById('block-user-btn');
        this.galleryBtn = document.getElementById('gallery-btn');
        this.backToPublicBtn = document.getElementById('back-to-public');
        this.conversationsToggle = document.getElementById('conversations-toggle');
        this.adminPanelBtn = document.getElementById('admin-panel-btn');
        this.conversationsPanel = document.getElementById('conversations-panel');
        this.closeConversationsBtn = document.getElementById('close-conversations');
        this.conversationsList = document.getElementById('conversations-list');
        this.unreadBadge = document.getElementById('unread-badge');
        this.scrollToBottomBtn = document.getElementById('scroll-to-bottom');

        // Load the set of users I've blocked so their public messages are hidden
        // from my feed (blocking is otherwise DM-only). Re-applies once resolved.
        this.loadBlockedUsers();

        // Ensure the saved dark-mode / high-contrast preferences are applied.
        if (localStorage.getItem('chatDarkMode') === 'true') {
            document.body.classList.add('dark-mode');
        }
        if (localStorage.getItem('chatHighContrast') === 'true') {
            document.body.classList.add('high-contrast');
        }
        this.setFontSize(localStorage.getItem('chatFontSize') || 'normal');

        // Initial unread-notification count for the header badge.
        this.refreshNotificationCount();

        // Photo upload elements
        this.photoButton = document.getElementById('photo-button');
        this.photoInput = document.getElementById('photo-input');
        this.pinTrackButton = document.getElementById('pin-track-button');
        this.selectedPhoto = null;
        this.photoPreviewElement = null;

        // Show current username
        document.getElementById('current-username').textContent = this.username;

        // Update UI based on chat mode (hide/show private chat elements)
        this.updateChatModeUI();

        // Event listeners
        this.sendButton.addEventListener('click', () => this.sendMessage());
        this.messageInput.addEventListener('keypress', (e) => {
            // Don't send while the @mention autocomplete is capturing Enter.
            if (e.key === 'Enter' && this._mentionActive) return;
            if (e.key === 'Enter') this.sendMessage();
        });
        // @mention autocomplete (public chat)
        this.messageInput.addEventListener('input', () => this.handleMentionInput());
        this.messageInput.addEventListener('keydown', (e) => this.handleMentionKeydown(e));
        this.messageInput.addEventListener('blur', () => setTimeout(() => this.closeMentionDropdown(), 150));
        // Typing indicator ping (onTypingInput self-guards on the feature flag).
        // Wired here — not in initTypingIndicators — because the input element
        // only exists after the chat view is built (settings load runs earlier).
        this.messageInput.addEventListener('input', () => this.onTypingInput());
        // Paste an image straight into the chat to attach it.
        this.messageInput.addEventListener('paste', (e) => this.handlePaste(e));

        // Back/forward + manual hash edits: open or close the DM in the URL.
        window.addEventListener('hashchange', () => {
            if (this._suppressHashChange) return;
            const isDm = (location.hash || '').indexOf('#dm/') === 0;
            if (isDm) {
                this.restoreConversationFromHash();
            } else if (this.privateChat && this.privateChat.active) {
                this.exitPrivateChat();
            }
        });

        // Click a username inside the conversation (sender name or @mention) to
        // open a small popover offering to start a private chat with them.
        if (this.messagesContainer) {
            this.messagesContainer.addEventListener('click', (e) => {
                const nameEl = e.target.closest('.message-username[data-username], .mention[data-username]');
                if (!nameEl) return;
                this.openUserActionsPopover(nameEl.getAttribute('data-username'), nameEl);
            });
        }
        
        // Emoji picker
        this.initEmojiPicker();
        
        // GIF picker
        this.initGifPicker();
        
        // Scroll to bottom button
        if (this.scrollToBottomBtn) {
            this.scrollToBottomBtn.addEventListener('click', () => this.scrollToBottom());
        }
        
        // Detect scroll position to show/hide scroll button and handle infinite scroll
        const container = this.messagesContainer.parentElement;
        container.addEventListener('scroll', () => {
            const isScrolledUp = container.scrollHeight - container.scrollTop - container.clientHeight > 100;
            if (this.scrollToBottomBtn) {
                this.scrollToBottomBtn.classList.toggle('show', isScrolledUp);
            }
            
            // Infinite scroll: load more messages when user scrolls to top
            // BUT: Don't trigger on initial load when scrollTop is 0 and we haven't scrolled to bottom yet
            // hasInitialScrolled flag prevents loading more messages until after initial scroll to bottom
            if (!this.privateChat.active && container.scrollTop < 500 && !this.isLoadingMoreMessages && this.hasMoreMessages && this.hasInitialScrolled) {
                this.loadMoreMessages();
            }
        });
        
        // Private chat back button
        if (this.backToPublicBtn) {
            this.backToPublicBtn.addEventListener('click', () => this.exitPrivateChat());
        }

        // Block/unblock button in the private chat header
        if (this.blockUserBtn) {
            this.blockUserBtn.addEventListener('click', () => this.toggleBlockCurrentUser());
        }

        // Gallery button in the private chat header
        if (this.galleryBtn) {
            this.galleryBtn.addEventListener('click', () => this.openPrivateGallery());
        }
        
        // Conversations panel toggle
        if (this.conversationsToggle) {
            this.conversationsToggle.addEventListener('click', () => this.toggleConversationsPanel());
        }
        
        if (this.closeConversationsBtn) {
            this.closeConversationsBtn.addEventListener('click', () => this.closeConversationsPanel());
        }
        
        // Admin panel button
        if (this.adminPanelBtn) {
            this.adminPanelBtn.addEventListener('click', () => {
                window.open('/admin/index.html', '_blank');
            });
        }
        
        // Show admin panel button for root users
        if (this.userRole === 'root' && this.adminPanelBtn) {
            this.adminPanelBtn.style.display = '';
        }

        // Sound toggle button
        const soundBtn = document.getElementById('sound-toggle');
        if (soundBtn) {
            soundBtn.addEventListener('click', () => this.toggleSound());
            this.updateSoundButton();
        }

        // Keyboard: Escape closes any open header overlay.
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            ['notifications-panel', 'schedule-panel', 'search-panel'].forEach(id => {
                const el = document.getElementById(id);
                if (el && el.style.display !== 'none') el.style.display = 'none';
            });
        });

        // Notifications inbox
        const notifBtn = document.getElementById('notifications-toggle');
        if (notifBtn) notifBtn.addEventListener('click', () => this.toggleNotifications());
        const notifClose = document.getElementById('notifications-close');
        if (notifClose) notifClose.addEventListener('click', () => this.toggleNotifications(false));
        const notifMarkAll = document.getElementById('notif-mark-all');
        if (notifMarkAll) notifMarkAll.addEventListener('click', () => this.markAllNotificationsRead());

        // Show schedule
        const scheduleBtn = document.getElementById('schedule-toggle');
        if (scheduleBtn) scheduleBtn.addEventListener('click', () => this.toggleSchedule());
        const scheduleClose = document.getElementById('schedule-close');
        if (scheduleClose) scheduleClose.addEventListener('click', () => this.toggleSchedule(false));

        // Message search
        const searchBtn = document.getElementById('search-toggle');
        if (searchBtn) searchBtn.addEventListener('click', () => this.toggleSearch());
        const searchClose = document.getElementById('search-close');
        if (searchClose) searchClose.addEventListener('click', () => this.toggleSearch(false));
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(this._searchDebounce);
                this._searchDebounce = setTimeout(() => this.runSearch(searchInput.value), 250);
            });
            searchInput.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.toggleSearch(false); });
        }
        
        // Photo upload button
        if (this.photoButton) {
            this.photoButton.addEventListener('click', () => this.photoInput.click());
        }

        // Pin-current-track button
        if (this.pinTrackButton) {
            this.pinTrackButton.addEventListener('click', () => this.setPinTrack(!this.pinTrackActive));
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

    // Realtime transport entry point. Asks the server which transport to use;
    // WebSocket only when /api/realtime-config advertises it (admin-enabled +
    // worker healthy), otherwise — or on any WS failure — the SSE default.
    async connect() {
        this.updateStatus('connecting', 'Connecting...');

        // Keep the presence heartbeat running across every (re)connect. It is
        // independent of the realtime transport, but disconnect() stops it (so a
        // real logout does) — and reconnect paths call disconnect() then connect().
        // Without restarting it here, the first background-suspend/network-flap
        // reconnect killed the heartbeat for good and the user silently dropped
        // offline after the 5-minute presence cleanup while their socket stayed
        // open. startHeartbeat() is idempotent, so this never stacks timers.
        if (this.username) {
            this.startHeartbeat();
        }

        // Tear down any existing realtime socket before opening a new one, so no
        // caller (reconnect, ensureRealtimeFresh, the online/visibility handlers)
        // can ever stack a second live connection. Detach handlers first so closing
        // the old socket does not re-enter reconnect(). Heartbeat is left alone.
        if (this._ws) {
            try { this._ws.onclose = null; this._ws.onmessage = null; this._ws.onerror = null; this._ws.close(); } catch (_) {}
            this._ws = null;
        }
        if (this.eventSource) {
            try { this.eventSource.close(); } catch (_) {}
            this.eventSource = null;
        }

        // connect() awaits (token + config) before it actually opens a socket. If a
        // second connect() starts during that window, both would open one. Stamp a
        // generation: a socket whose generation is no longer current aborts itself
        // on open, so only the newest connect() survives.
        const myGen = this._connGen = (this._connGen || 0) + 1;

        // Hold a fresh realtime token (proves our username to both transports)
        // before connecting; the beat also refreshes presence immediately.
        if (!this.realtimeToken && this.username) {
            await this.sendHeartbeat();
        }

        let cfg = null;
        try {
            const res = await fetch(`${this.apiUrl}/api/realtime-config`, { cache: 'no-store' });
            if (res.ok) cfg = await res.json();
        } catch (_) { /* fall through to SSE */ }

        // A newer connect() superseded this one while we awaited — stand down.
        if (myGen !== this._connGen) {
            return;
        }

        if (cfg && cfg.transport === 'websocket' && typeof WebSocket !== 'undefined') {
            this.connectWebSocket(cfg, myGen);
        } else {
            this.connectSSE();
        }
    }

    connectSSE() {
        this.updateStatus('connecting', 'Connecting...');
        try {
            // EventSource cannot send headers, so the token rides in the query
            // string; the server derives the *verified* username from it.
            const tokenParam = this.realtimeToken ? `&token=${encodeURIComponent(this.realtimeToken)}` : '';
            this.eventSource = new EventSource(
                `${this.apiUrl}/api/stream?username=${encodeURIComponent(this.username)}${tokenParam}`
            );

            this.eventSource.addEventListener('open', () => {
                this.updateStatus('connected', 'Connected');
                this.reconnectAttempts = 0;
                console.log('SSE connection established');
            });

            // One dispatcher for every data event — shared with the WS transport.
            const bind = (name) => this.eventSource.addEventListener(name, (e) => {
                let data = null;
                try { data = e.data ? JSON.parse(e.data) : null; } catch (_) { return; }
                this._dispatchRealtimeEvent(name, data);
            });
            ['message', 'history', 'users', 'config', 'private', 'clear',
             'message_deleted', 'message_edited', 'reaction', 'now_playing', 'pins_changed', 'typing', 'poll'].forEach(bind);

            this.eventSource.addEventListener('reconnect', () => {
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

    // Shared dispatch for BOTH transports. The WS worker emits the same event
    // names the SSE stream does, so one switch serves both — keeping behaviour
    // identical whichever transport is active.
    _dispatchRealtimeEvent(event, data) {
        switch (event) {
            case 'message':
                if (data && data.type === 'refresh_history') {
                    if (this.displayNameCache) { try { this.displayNameCache.clear(); } catch (_) {} }
                    this.loadActiveUsers();
                    this.reloadHistory();
                } else {
                    this.handleMessage(data);
                }
                break;
            case 'history':         this.loadHistory(data); break;
            case 'users':           this._handleUsersEvent(data); break;
            case 'config':
                this.chatMode = (data && data.chat_mode) || 'public';
                this.updateChatModeUI();
                // The server sends 'config' on every (re)connect — re-sync the
                // now-playing widget here so a client that reconnected after missing
                // a track-change push shows the current track (still no polling).
                this.updateNowPlaying();
                // Same for the pinned bar (a pin/unpin may have happened offline).
                this.refreshPinnedBar();
                // Now that chatMode is known, restore a DM named in the URL (#dm/…)
                // so a refresh keeps the open conversation. Guarded against re-opening.
                this.restoreConversationFromHash();
                // Re-sync the live poll (one may have opened/closed while away).
                this.refreshPoll();
                break;
            case 'private':         this.handlePrivateMessage(data); break;
            case 'clear':           this.handleChatClear(); break;
            case 'message_deleted': this.handleMessageDeleted(data.message_id); break;
            case 'message_edited':  this.handleMessageEdited(data.message_id, data.message, data.edited_at); break;
            case 'reaction':        this.handleReactionUpdate(data); break;
            case 'now_playing':     this.renderNowPlaying(data && data.nowPlaying); break;
            case 'pins_changed':    this.refreshPinnedBar(); break;
            case 'typing':          this.handleTypingEvent(data); break;
            case 'poll':            this.renderPoll(data && data.poll); break;
        }
    }

    // ---- Live polls -------------------------------------------------

    initPolls() {
        this._pollsEnabled = this._settingOn(this.settings && this.settings.polls_enabled);
        if (!this._pollsEnabled) {
            const w = document.getElementById('poll-widget');
            if (w) w.style.display = 'none';
            return;
        }
        this.refreshPoll();
    }

    async refreshPoll() {
        if (!this._pollsEnabled) return;
        try {
            const q = this.sessionId ? `?session_id=${encodeURIComponent(this.sessionId)}` : '';
            const resp = await fetch(`${this.apiUrl}/api/polls/active${q}`);
            if (!resp.ok) return;
            const data = await resp.json();
            this.renderPoll(data && data.poll);
        } catch (e) { /* non-fatal */ }
    }

    renderPoll(poll) {
        const w = document.getElementById('poll-widget');
        if (!w) return;
        // No poll, or a closed poll nobody in view voted on → hide.
        if (!poll || !poll.id || (!poll.is_active && (poll.total || 0) === 0)) {
            w.style.display = 'none';
            w.innerHTML = '';
            return;
        }
        this._currentPollId = poll.id;
        const total = poll.total || 0;
        const voted = poll.my_vote !== null && poll.my_vote !== undefined;
        const showResults = voted || !poll.is_active;

        const rows = (poll.options || []).map((opt, i) => {
            const count = (poll.counts && poll.counts[i]) || 0;
            const pct = total > 0 ? Math.round((count / total) * 100) : 0;
            const mine = poll.my_vote === i;
            if (showResults) {
                return `<div class="poll-result ${mine ? 'mine' : ''}">
                            <div class="poll-result-fill" style="width:${pct}%"></div>
                            <span class="poll-result-label">${this.escapeHtml(opt)}${mine ? ' ✓' : ''}</span>
                            <span class="poll-result-pct">${pct}% (${count})</span>
                        </div>`;
            }
            return `<button class="poll-option" onclick="window.chatBox.votePoll(${poll.id}, ${i})">${this.escapeHtml(opt)}</button>`;
        }).join('');

        w.innerHTML = `
            <div class="poll-head">
                <span class="poll-badge">📊 Poll</span>
                <span class="poll-question">${this.escapeHtml(poll.question)}</span>
                ${poll.is_active ? '' : '<span class="poll-closed">closed</span>'}
            </div>
            <div class="poll-body">${rows}</div>
            <div class="poll-foot">${total} vote${total === 1 ? '' : 's'}</div>`;
        w.style.display = 'block';
    }

    async votePoll(pollId, optionIndex) {
        if (!this.username) { alert('Please join the chat first to vote.'); return; }
        try {
            const resp = await fetch(`${this.apiUrl}/api/polls/vote`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ poll_id: pollId, option_index: optionIndex, username: this.username, session_id: this.sessionId }),
            });
            const data = await resp.json().catch(() => ({}));
            if (resp.ok && data.success) {
                this.renderPoll(data.poll);
            } else if (data && data.error) {
                // A closed poll or a stale widget — refresh to the current state.
                this.refreshPoll();
            }
        } catch (e) { /* ignore */ }
    }

    // ---- Typing indicators ------------------------------------------

    initTypingIndicators() {
        // Public and DM typing are toggled separately. Record both flags; the input
        // listener is wired in setupEventListeners (the input element doesn't exist
        // yet when settings first load).
        const s = this.settings || {};
        this._typingEnabledPublic = this._settingOn(s.typing_indicators_enabled);
        this._typingEnabledDm = this._settingOn(s.dm_typing_indicators_enabled);
        // Kept for callers that just need "is typing wired at all".
        this._typingEnabled = this._typingEnabledPublic || this._typingEnabledDm;
        this._typingUsers = this._typingUsers || new Map(); // username -> expiry ms
    }

    /** Throttled "I'm typing" ping — DM-aware (rides the private channel in a DM). */
    onTypingInput() {
        if (!this.username) return;
        const inDm = !!(this.privateChat && this.privateChat.active && this.privateChat.withUser);
        // Gate on the toggle for THIS context (public vs DM).
        if (inDm ? !this._typingEnabledDm : !this._typingEnabledPublic) return;
        const to = inDm ? this.privateChat.withUser : null;
        const now = Date.now();
        if (!this._typingLastSent || now - this._typingLastSent > 2000 || this._typingLastTo !== to) {
            this._typingLastSent = now;
            this._typingLastTo = to;
            this.sendTypingState(true, to);
        }
        clearTimeout(this._typingStopTimer);
        this._typingStopTimer = setTimeout(() => {
            this._typingLastSent = 0;
            this.sendTypingState(false, to);
        }, 3000);
    }

    async sendTypingState(isTyping, to = null) {
        if (!this.username) return;
        // Respect the per-context toggle.
        if (to ? !this._typingEnabledDm : !this._typingEnabledPublic) return;
        try {
            const body = { username: this.username, session_id: this.sessionId, is_typing: !!isTyping };
            if (to) body.to = to;
            await fetch(`${this.apiUrl}/api/typing`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
        } catch (e) { /* best-effort */ }
    }

    /** Public-feed typing cue (chat:updates). */
    handleTypingEvent(data) {
        if (!data || !data.username || data.username === this.username) return;
        this._typingUsers = this._typingUsers || new Map();
        if (data.is_typing) {
            this._typingUsers.set(data.username, Date.now() + 4000);
        } else {
            this._typingUsers.delete(data.username);
        }
        this.renderTypingIndicator();
        clearTimeout(this._typingRenderTimer);
        this._typingRenderTimer = setTimeout(() => this.renderTypingIndicator(), 4100);
    }

    /**
     * DM typing cue (private channel): only the peer I'm currently viewing. A cue
     * may carry ttl_ms (bots/impersonation announce how long they'll "type") so
     * the indicator lasts the whole compose; real users omit it (default 4s).
     */
    handleDmTyping(data) {
        if (!data || data.from_username === this.username) return;
        // Only track the peer of the conversation I have open.
        if (!(this.privateChat && this.privateChat.active) || data.from_username !== this.privateChat.withUser) {
            return;
        }
        const ttl = data.is_typing ? Math.min(parseInt(data.ttl_ms, 10) || 4000, 30000) : 0;
        this._dmPeerTypingUntil = ttl ? Date.now() + ttl : 0;
        this._dmPeerTypingWho = data.from_username;
        this.renderTypingIndicator();
        clearTimeout(this._typingRenderTimer);
        this._typingRenderTimer = setTimeout(() => this.renderTypingIndicator(), (ttl || 100) + 100);
    }

    renderTypingIndicator() {
        const el = document.getElementById('typing-indicator');
        if (!el) return;
        const now = Date.now();
        const inDm = !!(this.privateChat && this.privateChat.active && this.privateChat.withUser);

        if (inDm) {
            // Show only if the current peer is typing to me.
            const active = this._dmPeerTypingUntil > now && this._dmPeerTypingWho === this.privateChat.withUser;
            if (active) {
                el.textContent = `${this.privateChat.withUser} is typing…`;
                el.style.display = 'block';
            } else {
                el.style.display = 'none';
                el.textContent = '';
            }
            return;
        }

        // Public feed.
        const names = [];
        for (const [user, expiry] of (this._typingUsers || new Map())) {
            if (expiry > now && user !== this.username) names.push(user);
            else if (expiry <= now) this._typingUsers.delete(user);
        }
        if (!names.length) {
            el.style.display = 'none';
            el.textContent = '';
            return;
        }
        let text;
        if (names.length === 1) text = `${names[0]} is typing…`;
        else if (names.length === 2) text = `${names[0]} and ${names[1]} are typing…`;
        else text = `${names.length} people are typing…`;
        el.textContent = text;
        el.style.display = 'block';
    }

    /** Transient toast when someone reacts to one of my messages. */
    showReactionNotification(fromUser, emoji) {
        if (this.dndEnabled || !this.reactionToastsEnabled) return;
        try {
            let host = document.getElementById('rcb-toast-host');
            if (!host) {
                host = document.createElement('div');
                host.id = 'rcb-toast-host';
                document.body.appendChild(host);
            }
            const toast = document.createElement('div');
            toast.className = 'rcb-toast';
            toast.innerHTML = `<span class="rcb-toast-emoji">${this.escapeHtml(emoji || '👍')}</span> <strong>${this.escapeHtml(fromUser || 'Someone')}</strong> reacted to your message`;
            host.appendChild(toast);
            // Fade in, then auto-dismiss.
            requestAnimationFrame(() => toast.classList.add('show'));
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        } catch (e) { /* non-fatal */ }
    }

    // ---- WebAuthn passkeys ----
    _b64urlToBuf(value) {
        let s = String(value).replace(/-/g, '+').replace(/_/g, '/');
        const pad = s.length % 4; if (pad) s += '===='.slice(pad);
        const bin = atob(s); const buf = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }
    _bufToB64url(buf) {
        const bytes = new Uint8Array(buf); let bin = '';
        for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    _prepareCreation(o) {
        o = Object.assign({}, o);
        o.challenge = this._b64urlToBuf(o.challenge);
        if (o.user && o.user.id) o.user = Object.assign({}, o.user, { id: this._b64urlToBuf(o.user.id) });
        if (Array.isArray(o.excludeCredentials)) o.excludeCredentials = o.excludeCredentials.map(c => Object.assign({}, c, { id: this._b64urlToBuf(c.id) }));
        return o;
    }
    _prepareRequest(o) {
        o = Object.assign({}, o);
        o.challenge = this._b64urlToBuf(o.challenge);
        if (Array.isArray(o.allowCredentials)) o.allowCredentials = o.allowCredentials.map(c => Object.assign({}, c, { id: this._b64urlToBuf(c.id) }));
        return o;
    }
    _serializeAttestation(cred) {
        const r = cred.response;
        return { id: cred.id, type: cred.type, rawId: this._bufToB64url(cred.rawId),
            response: { clientDataJSON: this._bufToB64url(r.clientDataJSON), attestationObject: this._bufToB64url(r.attestationObject) },
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {} };
    }
    _serializeAssertion(cred) {
        const r = cred.response;
        return { id: cred.id, type: cred.type, rawId: this._bufToB64url(cred.rawId),
            response: { clientDataJSON: this._bufToB64url(r.clientDataJSON), authenticatorData: this._bufToB64url(r.authenticatorData), signature: this._bufToB64url(r.signature), userHandle: r.userHandle ? this._bufToB64url(r.userHandle) : null },
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {} };
    }

    _initPasskeySection() {
        const section = document.getElementById('profile-passkey-section');
        if (!section) return;
        if (!this.userId || !window.PublicKeyCredential) { section.style.display = 'none'; return; }
        section.style.display = 'block';
        const addBtn = document.getElementById('profile-passkey-add-btn');
        if (addBtn && !addBtn._wired) { addBtn._wired = true; addBtn.onclick = () => this._addPasskey(); }
        this._loadPasskeys();
    }
    async _loadPasskeys() {
        const box = document.getElementById('profile-passkey-list');
        try {
            const r = await fetch(`${this.apiUrl}/api/passkey/list?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}`);
            const d = await r.json();
            const list = (d && d.passkeys) || [];
            box.innerHTML = list.length
                ? list.map(p => `<div>🔑 ${this.escapeHtml(p.name || p.label || 'Passkey')} <a href="#" onclick="window.chatBox._revokePasskey(${p.id || p.credentialid}); return false;" style="color:#dc2626; font-size:12px;">remove</a></div>`).join('')
                : '<span style="color:#6b7280;">No passkeys yet.</span>';
        } catch (e) { box.innerHTML = ''; }
    }
    async _addPasskey() {
        const err = document.getElementById('profile-passkey-error'); err.textContent = '';
        try {
            const optResp = await fetch(`${this.apiUrl}/api/passkey/register/options`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId }),
            });
            const options = await optResp.json();
            if (!optResp.ok) { err.textContent = options.error || 'Could not start'; return; }
            const cred = await navigator.credentials.create({ publicKey: this._prepareCreation(options) });
            const label = (prompt('Name this passkey (e.g. "My phone"):', 'Passkey') || 'Passkey').slice(0, 60);
            const regResp = await fetch(`${this.apiUrl}/api/passkey/register`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId, label, credential: this._serializeAttestation(cred) }),
            });
            const d = await regResp.json();
            if (!d.success) { err.textContent = d.error || 'Registration failed'; return; }
            this._loadPasskeys();
        } catch (e) { err.textContent = (e && e.name === 'NotAllowedError') ? 'Cancelled.' : 'Passkey error.'; }
    }
    async _revokePasskey(id) {
        if (!id || !confirm('Remove this passkey?')) return;
        try {
            await fetch(`${this.apiUrl}/api/passkey/revoke`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId, credential_id: id }),
            });
            this._loadPasskeys();
        } catch (e) { /* best-effort */ }
    }

    /** Passwordless sign-in with a passkey for the username in the login form. */
    async loginWithPasskey(username) {
        username = (username || '').trim();
        const err = document.getElementById('login-error');
        if (!username) { if (err) err.textContent = 'Enter your username first.'; return; }
        if (!window.PublicKeyCredential) { if (err) err.textContent = 'Passkeys are not supported on this device.'; return; }
        try {
            const optResp = await fetch(`${this.apiUrl}/api/passkey/login/options`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username }),
            });
            const options = await optResp.json();
            if (!optResp.ok) { if (err) err.textContent = options.error || 'No passkey for this account'; return; }
            const cred = await navigator.credentials.get({ publicKey: this._prepareRequest(options) });
            const resp = await fetch(`${this.apiUrl}/api/passkey/login`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, sessionId: this.sessionId, credential: this._serializeAssertion(cred) }),
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) { if (err) err.textContent = data.error || 'Passkey sign-in failed'; return; }
            // Same post-login path as password login.
            this.username = data.user.username;
            this.userId = data.user.id;
            this.userRole = data.user.role;
            this.setStorage('chatNickname', this.username);
            this.setStorage('userId', this.userId);
            this.setStorage('userRole', this.userRole);
            this.hideNicknameModal();
            this.initializeChat();
        } catch (e) {
            if (err) err.textContent = (e && e.name === 'NotAllowedError') ? 'Cancelled.' : 'Passkey sign-in error.';
        }
    }

    // ---- Two-factor authentication (profile settings) ----
    _initTwoFactorSection() {
        const section = document.getElementById('profile-2fa-section');
        if (!section) return;
        // Guests (no account) don't get 2FA.
        if (!this.userId) { section.style.display = 'none'; return; }
        section.style.display = 'block';
        document.getElementById('profile-2fa-setup').style.display = 'none';
        document.getElementById('profile-2fa-error').textContent = '';

        const setupBtn = document.getElementById('profile-2fa-setup-btn');
        const enableBtn = document.getElementById('profile-2fa-enable-btn');
        const disableBtn = document.getElementById('profile-2fa-disable-btn');
        if (setupBtn && !setupBtn._wired) { setupBtn._wired = true; setupBtn.onclick = () => this._begin2faSetup(); }
        if (enableBtn && !enableBtn._wired) { enableBtn._wired = true; enableBtn.onclick = () => this._enable2fa(); }
        if (disableBtn && !disableBtn._wired) { disableBtn._wired = true; disableBtn.onclick = () => this._disable2fa(); }

        this._refresh2faStatus();
    }

    async _refresh2faStatus() {
        const statusEl = document.getElementById('profile-2fa-status');
        try {
            const r = await fetch(`${this.apiUrl}/api/2fa/status?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}`);
            const d = await r.json();
            const enabled = !!(d.status && d.status.enabled);
            statusEl.textContent = enabled ? '✅ Enabled' : 'Not enabled';
            document.getElementById('profile-2fa-setup-btn').style.display = enabled ? 'none' : 'inline-block';
            document.getElementById('profile-2fa-disable').style.display = enabled ? 'block' : 'none';
            document.getElementById('profile-2fa-setup').style.display = 'none';
        } catch (e) { statusEl.textContent = ''; }
    }

    async _begin2faSetup() {
        const err = document.getElementById('profile-2fa-error'); err.textContent = '';
        try {
            const r = await fetch(`${this.apiUrl}/api/2fa/setup`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId }),
            });
            const d = await r.json();
            if (!d.success) { err.textContent = d.error || 'Could not start setup'; return; }
            document.getElementById('profile-2fa-qr').src = d.setup.qr_code_data_uri || '';
            document.getElementById('profile-2fa-secret').textContent = d.setup.manual_entry_key || d.setup.secret || '';
            const codes = (d.setup.backup_codes || []);
            document.getElementById('profile-2fa-backup').innerHTML = codes.length
                ? 'Backup codes (save these): <code>' + codes.map(c => this.escapeHtml(c)).join('</code> <code>') + '</code>' : '';
            document.getElementById('profile-2fa-setup').style.display = 'block';
            document.getElementById('profile-2fa-setup-btn').style.display = 'none';
        } catch (e) { err.textContent = 'Error starting setup.'; }
    }

    async _enable2fa() {
        const err = document.getElementById('profile-2fa-error'); err.textContent = '';
        const code = document.getElementById('profile-2fa-code').value.trim();
        if (!code) { err.textContent = 'Enter the code from your app.'; return; }
        try {
            const r = await fetch(`${this.apiUrl}/api/2fa/verify-setup`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId, code }),
            });
            const d = await r.json();
            if (!d.success) { err.textContent = d.error || 'Invalid code'; return; }
            this._refresh2faStatus();
        } catch (e) { err.textContent = 'Error enabling 2FA.'; }
    }

    async _disable2fa() {
        const err = document.getElementById('profile-2fa-error'); err.textContent = '';
        const code = document.getElementById('profile-2fa-disable-code').value.trim();
        if (!code) { err.textContent = 'Enter a current code to disable.'; return; }
        try {
            const r = await fetch(`${this.apiUrl}/api/2fa/disable`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId, code }),
            });
            const d = await r.json();
            if (!d.success) { err.textContent = d.error || 'Could not disable'; return; }
            this._refresh2faStatus();
        } catch (e) { err.textContent = 'Error disabling 2FA.'; }
    }

    /** Toggle the dark theme and persist the choice. */
    setDarkMode(on) {
        document.body.classList.toggle('dark-mode', !!on);
        localStorage.setItem('chatDarkMode', on ? 'true' : 'false');
    }

    /** Apply a text-size preference (small|normal|large) and persist it. */
    setFontSize(size) {
        document.body.classList.remove('font-small', 'font-large');
        if (size === 'small') document.body.classList.add('font-small');
        else if (size === 'large') document.body.classList.add('font-large');
        localStorage.setItem('chatFontSize', size || 'normal');
    }

    // ---- DM read receipts -------------------------------------------

    /** Flip every one of my sent DM bubbles to the "seen" (✓✓) receipt. */
    markMySentMessagesSeen() {
        if (!this.messagesContainer) return;
        this.messagesContainer.querySelectorAll('.message.sent .dm-receipt:not(.seen)').forEach(el => {
            el.classList.add('seen');
            el.textContent = '✓✓';
            el.title = 'Seen';
        });
    }

    /** Tell the server I've read the open conversation with `peer` (best-effort). */
    async sendReadReceipt(peer) {
        if (!peer || !this.username || !this.sessionId) return;
        try {
            await fetch(`${this.apiUrl}/api/private/mark-read`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId, peer }),
            });
        } catch (e) { /* best-effort */ }
    }

    // ---- User profile card ------------------------------------------

    async showUserProfile(username) {
        let modal = document.getElementById('user-profile-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'user-profile-modal';
            modal.className = 'profile-modal-overlay';
            modal.innerHTML = `<div class="profile-modal-card"><button class="profile-modal-close" title="Close">✕</button><div id="user-profile-body"></div></div>`;
            document.body.appendChild(modal);
            modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
            modal.querySelector('.profile-modal-close').addEventListener('click', () => { modal.style.display = 'none'; });
        }
        const body = modal.querySelector('#user-profile-body');
        body.innerHTML = '<p style="color:#6b7280;">Loading…</p>';
        modal.style.display = 'flex';
        try {
            const resp = await fetch(`${this.apiUrl}/api/user-profile/card?username=${encodeURIComponent(username)}`);
            const data = await resp.json();
            const p = data && data.profile;
            if (!data.success || !p) { body.innerHTML = '<p style="color:#ef4444;">Could not load profile.</p>'; return; }
            const name = this.escapeHtml(p.display_name || p.username);
            const badge = p.badge ? `<span class="profile-badge">${this.escapeHtml(p.badge)}</span>` : '';
            const online = p.is_online
                ? '<span class="profile-online"><span class="dot"></span> Online</span>'
                : '<span class="profile-offline">Offline</span>';
            const bits = [];
            if (p.age) bits.push(`${this.escapeHtml(String(p.age))} y/o`);
            if (p.sex) bits.push(this.escapeHtml(p.sex));
            if (p.location) bits.push(this.escapeHtml(p.location));
            const meta = bits.length ? `<div class="profile-meta">${bits.join(' · ')}</div>` : '';
            const statusHTML = p.status ? `<div class="profile-status">“${this.escapeHtml(p.status)}”</div>` : '';
            const bioHTML = p.bio ? `<div class="profile-bio">${this.escapeHtml(p.bio)}</div>` : '';
            const since = p.first_seen ? new Date((p.first_seen + '').replace(' ', 'T')).toLocaleDateString() : null;
            const rank = p.rank
                ? `<span class="profile-rank" style="background:${this.escapeHtml(p.rank.color)}1a; color:${this.escapeHtml(p.rank.color)};">${this.escapeHtml(p.rank.title)}</span>`
                : '';
            body.innerHTML = `
                <div class="profile-head">
                    <div class="profile-avatar" style="${p.rank ? 'background:' + this.escapeHtml(p.rank.color) : ''}">${this.escapeHtml((p.username || '?').charAt(0).toUpperCase())}</div>
                    <div>
                        <div class="profile-name">${name} ${badge}</div>
                        ${online} ${rank}
                    </div>
                </div>
                ${statusHTML}
                ${bioHTML}
                ${meta}
                <div class="profile-stats">
                    <div><strong>${p.message_count || 0}</strong><span>messages</span></div>
                    ${p.reputation ? `<div><strong style="color:${this.escapeHtml(p.reputation.color)}">${p.reputation.score}</strong><span>reputation · ${this.escapeHtml(p.reputation.tier)}</span></div>` : ''}
                    ${since ? `<div><strong>${this.escapeHtml(since)}</strong><span>first seen</span></div>` : ''}
                </div>`;
        } catch (e) { body.innerHTML = '<p style="color:#ef4444;">Error loading profile.</p>'; }
    }

    // ---- Notifications inbox ----------------------------------------

    /** Fetch the unread count and update the header badge. */
    async refreshNotificationCount() {
        if (!this.username || !this.sessionId) return;
        try {
            const r = await fetch(`${this.apiUrl}/api/notifications?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}&unread_only=1`);
            if (!r.ok) return;
            const d = await r.json();
            this.updateNotifBadge((d && d.unread_count) || 0);
        } catch (e) { /* best-effort */ }
    }

    updateNotifBadge(count) {
        const badge = document.getElementById('notif-badge');
        if (!badge) return;
        if (count > 0) { badge.textContent = count > 99 ? '99+' : count; badge.style.display = ''; }
        else { badge.style.display = 'none'; }
    }

    toggleNotifications(force) {
        const panel = document.getElementById('notifications-panel');
        if (!panel) return;
        const show = force === undefined ? panel.style.display === 'none' : force;
        panel.style.display = show ? 'flex' : 'none';
        if (show) this.loadNotifications();
    }

    async loadNotifications() {
        const box = document.getElementById('notifications-list');
        if (!box) return;
        box.innerHTML = '<div class="search-empty">Loading…</div>';
        try {
            const r = await fetch(`${this.apiUrl}/api/notifications?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}`);
            if (!r.ok) { box.innerHTML = '<div class="search-empty">Could not load notifications.</div>'; return; }
            const d = await r.json();
            let items = (d && d.notifications) || [];
            this.updateNotifBadge(d.unread_count || 0);
            if (!items.length) { box.innerHTML = '<div class="search-empty">No notifications yet.</div>'; return; }
            items = this._groupNotifications(items);
            box.innerHTML = items.map(n => {
                const when = n.created_at ? new Date((n.created_at + '').replace(' ', 'T')).toLocaleString() : '';
                const unread = !(n.is_read === true || n.is_read === 't');
                const jump = n.link ? ` onclick="window.chatBox.jumpToMessage('${this.escapeHtml(String(n.link))}')"` : '';
                return `<div class="notif-item${unread ? ' unread' : ''}"${jump}>
                        <div class="notif-title">${this.escapeHtml(n.title)}</div>
                        ${n.body ? `<div class="notif-body">${this.escapeHtml(n.body)}</div>` : ''}
                        <div class="notif-when">${this.escapeHtml(when)}</div>
                    </div>`;
            }).join('');
        } catch (e) { box.innerHTML = '<div class="search-empty">Error loading notifications.</div>'; }
    }

    /**
     * Collapse multiple reaction notifications on the SAME message into a single
     * "N reactions to your message" entry (newest timestamp; unread if any were).
     * Other notification types pass through unchanged.
     */
    _groupNotifications(items) {
        const out = [];
        const byLink = new Map(); // link -> index in out
        for (const n of items) {
            const isReaction = n.type === 'reaction' && n.link;
            if (!isReaction) { out.push(n); continue; }
            if (byLink.has(n.link)) {
                const g = out[byLink.get(n.link)];
                g._count = (g._count || 1) + 1;
                if (!(n.is_read === true || n.is_read === 't')) g.is_read = false;
                g.title = `${g._count} reactions to your message`;
            } else {
                byLink.set(n.link, out.length);
                out.push({ ...n, _count: 1 });
            }
        }
        return out;
    }

    async markAllNotificationsRead() {
        try {
            await fetch(`${this.apiUrl}/api/notifications/read`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId }),
            });
            this.updateNotifBadge(0);
            this.loadNotifications();
        } catch (e) { /* best-effort */ }
    }

    // ---- Show schedule ----------------------------------------------

    /** Show/hide the upcoming-shows overlay, loading it on open. */
    toggleSchedule(force) {
        const panel = document.getElementById('schedule-panel');
        if (!panel) return;
        const show = force === undefined ? panel.style.display === 'none' : force;
        panel.style.display = show ? 'flex' : 'none';
        if (show) { this.loadSchedule(); this.loadShoutouts(); }
    }

    async loadShoutouts() {
        const box = document.getElementById('shoutouts-block');
        if (!box) return;
        try {
            const resp = await fetch(`${this.apiUrl}/api/shoutouts?limit=8`);
            if (!resp.ok) { box.innerHTML = ''; return; }
            const data = await resp.json();
            const items = (data && data.shoutouts) || [];
            if (!items.length) { box.innerHTML = ''; return; }
            box.innerHTML = `<div class="sched-section">📣 Shout-outs</div>` + items.map(s => {
                const who = this.escapeHtml(s.requester_username || 'Someone');
                const song = this.escapeHtml([s.song_title, s.artist].filter(Boolean).join(' — '));
                const ded = this.escapeHtml(s.dedication || '');
                return `<div class="shoutout-item">
                        <div class="shoutout-ded">“${ded}”</div>
                        <div class="shoutout-meta">${who}${song ? ' · ' + song : ''}</div>
                    </div>`;
            }).join('');
        } catch (e) { box.innerHTML = ''; }
    }

    async loadSchedule() {
        const box = document.getElementById('schedule-list');
        if (!box) return;
        box.innerHTML = '<div class="search-empty">Loading…</div>';
        try {
            const [resp, subIds] = await Promise.all([
                fetch(`${this.apiUrl}/api/shows/upcoming?limit=10`),
                this._loadShowSubscriptions(),
            ]);
            if (!resp.ok) { box.innerHTML = '<div class="search-empty">Could not load the schedule.</div>'; return; }
            const data = await resp.json();
            const shows = (data && data.shows) || [];
            this._subShowIds = new Set(subIds);
            if (!shows.length) { box.innerHTML = '<div class="search-empty">No upcoming shows.</div>'; return; }
            box.innerHTML = shows.map(s => {
                const when = s.next_start ? new Date(s.next_start) : null;
                const whenStr = when ? when.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
                const host = s.host ? ` · ${this.escapeHtml(s.host)}` : '';
                const desc = s.description ? `<div class="sched-desc">${this.escapeHtml(s.description)}</div>` : '';
                const archive = s.archive_url ? `<div><a href="${this.escapeHtml(s.archive_url)}" target="_blank" rel="noopener" style="font-size:12px;">▶ past episodes</a></div>` : '';
                const subbed = this._subShowIds.has(Number(s.id));
                const bell = this.username ? `<button class="sched-sub${subbed ? ' on' : ''}" onclick="window.chatBox.toggleShowSubscription(${Number(s.id)}, this)" title="${subbed ? 'Unsubscribe from reminders' : 'Remind me before this show'}">${subbed ? '🔔 On' : '🔕 Remind me'}</button>` : '';
                return `<div class="sched-item">
                        <div class="sched-when">${this.escapeHtml(whenStr)} ${bell}</div>
                        <div class="sched-title">${this.escapeHtml(s.title)}${host}</div>
                        ${desc}
                        ${archive}
                    </div>`;
            }).join('');
        } catch (e) { box.innerHTML = '<div class="search-empty">Error loading the schedule.</div>'; }
    }

    async _loadShowSubscriptions() {
        if (!this.username || !this.sessionId) return [];
        try {
            const r = await fetch(`${this.apiUrl}/api/shows/subscriptions?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}`);
            if (!r.ok) return [];
            const d = await r.json();
            return (d && d.show_ids) || [];
        } catch (e) { return []; }
    }

    async toggleShowSubscription(showId, btn) {
        const subbed = this._subShowIds && this._subShowIds.has(Number(showId));
        try {
            const r = await fetch(`${this.apiUrl}/api/shows/subscribe`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, session_id: this.sessionId, show_id: showId, subscribe: subbed ? 'false' : 'true' }),
            });
            const d = await r.json();
            if (!d.success) return;
            this._subShowIds = this._subShowIds || new Set();
            if (d.subscribed) { this._subShowIds.add(Number(showId)); btn.textContent = '🔔 On'; btn.classList.add('on'); btn.title = 'Unsubscribe from reminders'; }
            else { this._subShowIds.delete(Number(showId)); btn.textContent = '🔕 Remind me'; btn.classList.remove('on'); btn.title = 'Remind me before this show'; }
        } catch (e) { /* best-effort */ }
    }

    // ---- Message search ---------------------------------------------

    /** Show/hide the search overlay. Pass a boolean to force a state. */
    toggleSearch(force) {
        const panel = document.getElementById('search-panel');
        if (!panel) return;
        const show = force === undefined ? panel.style.display === 'none' : force;
        panel.style.display = show ? 'flex' : 'none';
        if (show) {
            const input = document.getElementById('search-input');
            if (input) { input.focus(); input.select(); }
        } else {
            const results = document.getElementById('search-results');
            if (results) results.innerHTML = '';
            const input = document.getElementById('search-input');
            if (input) input.value = '';
        }
    }

    async runSearch(query) {
        const box = document.getElementById('search-results');
        if (!box) return;
        const q = (query || '').trim();
        if (q.length < 2) { box.innerHTML = ''; return; }
        box.innerHTML = '<div class="search-empty">Searching…</div>';
        try {
            const resp = await fetch(`${this.apiUrl}/api/search?q=${encodeURIComponent(q)}`);
            if (!resp.ok) { box.innerHTML = '<div class="search-empty">Search failed.</div>'; return; }
            const data = await resp.json();
            const results = (data && data.results) || [];
            if (!results.length) { box.innerHTML = '<div class="search-empty">No matches.</div>'; return; }
            box.innerHTML = results.map(r => {
                const name = this.escapeHtml(r.display_name || r.username || '');
                const when = r.created_at ? new Date((r.created_at + '').replace(' ', 'T')).toLocaleString() : '';
                const text = this.highlightSearch(r.message || '', q);
                return `<div class="search-result" onclick="window.chatBox.jumpToMessage('${this.escapeHtml(String(r.message_id || ''))}')">
                        <div class="sr-head"><span class="sr-user">${name}</span><span>${when}</span></div>
                        <div class="sr-body">${text}</div>
                    </div>`;
            }).join('');
        } catch (e) { box.innerHTML = '<div class="search-empty">Search error.</div>'; }
    }

    /** Escape a message and wrap case-insensitive matches of `q` in <mark>. */
    highlightSearch(message, q) {
        const escaped = this.escapeHtml(message);
        try {
            const rx = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
            return escaped.replace(rx, '<mark>$1</mark>');
        } catch (e) { return escaped; }
    }

    /** Scroll to a message already in the DOM (if present) and flash it. */
    jumpToMessage(messageId) {
        if (!messageId) return;
        this.toggleSearch(false);
        const el = this.messagesContainer && this.messagesContainer.querySelector(`[data-message-id="${messageId}"]`);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add('message-flash');
            setTimeout(() => el.classList.remove('message-flash'), 1500);
        }
    }

    // ---- Pinned messages bar ----------------------------------------

    async refreshPinnedBar() {
        const bar = document.getElementById('pinned-bar');
        if (!bar) return;
        try {
            const resp = await fetch(`${this.apiUrl}/api/pinned-messages`);
            if (!resp.ok) return;
            const data = await resp.json();
            this.renderPinnedBar((data && data.pins) || []);
        } catch (e) {
            // Non-fatal — leave the bar as-is.
        }
    }

    renderPinnedBar(pins) {
        const bar = document.getElementById('pinned-bar');
        if (!bar) return;
        if (!pins.length) {
            bar.style.display = 'none';
            bar.innerHTML = '';
            return;
        }
        const isAdmin = localStorage.getItem('isAdmin') === 'true';
        bar.innerHTML = pins.map((p) => {
            const who = p.username ? `<span class="pinned-author">${this.escapeHtml(p.username)}:</span> ` : '';
            const unpin = isAdmin
                ? `<button class="pinned-unpin" title="Unpin" onclick="window.chatBox.unpinMessage(${parseInt(p.id, 10) || 0})">✕</button>`
                : '';
            return `<div class="pinned-item">
                        <span class="pinned-icon">📌</span>
                        <div class="pinned-text">${who}${this.escapeHtml(p.content)}</div>
                        ${unpin}
                    </div>`;
        }).join('');
        bar.style.display = 'block';
    }

    async pinMessage(messageId) {
        const adminToken = localStorage.getItem('adminToken');
        if (!adminToken) { alert('Admin authentication required'); return; }
        // Snapshot the message text + author from the DOM.
        let content = '';
        let username = '';
        const el = this.messagesContainer
            ? this.messagesContainer.querySelector(`.message[data-message-id="${(window.CSS && CSS.escape) ? CSS.escape(messageId) : messageId}"]`)
            : null;
        if (el) {
            const textEl = el.querySelector('.message-text');
            content = textEl ? textEl.textContent.trim() : '';
            username = el.dataset.username || '';
        }
        if (!content) { alert('Could not read the message text to pin.'); return; }
        try {
            const resp = await fetch(`${this.apiUrl}/api/admin/pin-message`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${adminToken}` },
                body: JSON.stringify({ message_id: messageId, content, username }),
            });
            const data = await resp.json().catch(() => ({}));
            if (resp.ok && data.success) {
                this.refreshPinnedBar();
            } else {
                alert((data && data.error) || 'Failed to pin message');
            }
        } catch (e) {
            alert('Failed to pin message');
        }
    }

    async unpinMessage(id) {
        const adminToken = localStorage.getItem('adminToken');
        if (!adminToken) { alert('Admin authentication required'); return; }
        try {
            const resp = await fetch(`${this.apiUrl}/api/admin/unpin-message`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${adminToken}` },
                body: JSON.stringify({ id }),
            });
            const data = await resp.json().catch(() => ({}));
            if (resp.ok && data.success) {
                this.refreshPinnedBar();
            } else {
                alert((data && data.error) || 'Failed to unpin message');
            }
        } catch (e) {
            alert('Failed to unpin message');
        }
    }

    _handleUsersEvent(data) {
        if (!data) return;
        if (data.type === 'user_kicked') {
            if (data.username === this.username) {
                this.removeStorage('chatNickname');
                this.removeStorage('chatAge');
                this.removeStorage('chatLocation');
                this.removeStorage('chatSex');
                this.removeStorage('chatSessionId');
                localStorage.clear();
                alert('You have been kicked from the chat by an administrator.');
                this.disconnect();
                setTimeout(() => window.location.reload(), 1000);
            }
            this.loadActiveUsers();
        } else if (data.count !== undefined && data.users !== undefined) {
            this.activeUsersCount.textContent = data.count;
            this.activeUsersList = data.users;
            this.renderActiveUsers(data.users);
            this.updateConversationDisplayNames(data.users);
        }
    }

    // --- WebSocket transport (Pusher wire protocol v7) with SSE fallback ------
    connectWebSocket(cfg, gen = null) {
        let established = false;
        this._wsFellBack = false;

        const fallback = (why) => {
            if (this._wsFellBack) return;
            this._wsFellBack = true;
            console.warn('WebSocket unavailable (' + why + ') — falling back to SSE');
            try { if (this._ws) { this._ws.onclose = null; this._ws.close(); } } catch (_) {}
            this._ws = null;
            this.connectSSE();
        };

        let ws;
        try {
            const url = `${cfg.scheme}://${cfg.host}:${cfg.port}/app/${cfg.appKey}?protocol=7&client=rcb&version=1.0`;
            ws = new WebSocket(url);
        } catch (e) { fallback('construct'); return; }
        this._ws = ws;

        const openTimer = setTimeout(() => { if (!established) fallback('timeout'); }, 6000);

        ws.onmessage = (evt) => {
            let frame;
            try { frame = JSON.parse(evt.data); } catch (_) { return; }
            const event = frame.event;

            if (event === 'pusher:connection_established') {
                // A newer connect() superseded this socket while it was opening —
                // close it so we never end up with two live connections.
                if (gen !== null && gen !== this._connGen) {
                    try { ws.onclose = null; ws.onmessage = null; ws.close(); } catch (_) {}
                    if (this._ws === ws) { this._ws = null; }
                    return;
                }
                established = true;
                clearTimeout(openTimer);
                this.updateStatus('connected', 'Connected');
                this.reconnectAttempts = 0;
                try { this._wsSocketId = JSON.parse(frame.data).socket_id; } catch (_) { this._wsSocketId = ''; }
                // Public channels need no auth; the DM channel needs a signature.
                this._wsSubscribe('chat:updates');
                this._wsSubscribe('chat:user_updates');
                this._subscribePrivateChannel();
                // WS carries no initial snapshot — pull history + users over REST.
                this.loadActiveUsers();
                this.reloadHistory();
                return;
            }
            if (event === 'pusher:ping') { this._wsSend('pusher:pong', {}); return; }
            // The server's reply to a ping WE sent — used to verify the socket is
            // actually alive after a background suspend (see ensureRealtimeFresh)
            // instead of blindly reconnecting a healthy connection.
            if (event === 'pusher:pong') { this._lastPongAt = Date.now(); return; }
            if (!event || event.indexOf('pusher') === 0) return; // ignore pusher:* / pusher_internal:*

            let data = null;
            try { data = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data; } catch (_) { return; }

            if (frame.channel && frame.channel.indexOf('private-pm-') === 0) {
                this._dispatchRealtimeEvent('private', data);
            } else {
                this._dispatchRealtimeEvent(event, data);
            }
        };

        ws.onerror = () => { if (!established) fallback('error'); };
        ws.onclose = () => {
            clearTimeout(openTimer);
            if (!established) { fallback('closed'); return; }
            // Dropped after connecting: reconnect (re-checks transport → WS or SSE).
            this.updateStatus('disconnected', 'Disconnected');
            this._ws = null;
            this.reconnect();
        };
    }

    _wsSend(event, data) {
        try { if (this._ws && this._ws.readyState === 1) this._ws.send(JSON.stringify({ event, data })); } catch (_) {}
    }

    _wsSubscribe(channel, auth) {
        this._wsSend('pusher:subscribe', auth ? { channel, auth } : { channel });
    }

    // Subscribe to our OWN private DM channel. The auth endpoint signs only the
    // channel matching our token's username, so a client can never subscribe to
    // someone else's DMs. If the token has not arrived yet, retry on next beat.
    async _subscribePrivateChannel() {
        if (!this._ws || !this._wsSocketId || !this.username) return;
        if (!this.realtimeToken) { this._wsAwaitingToken = true; return; }
        this._wsAwaitingToken = false;
        const channel = 'private-pm-' + this.username;
        try {
            const res = await fetch(`${this.apiUrl}/api/broadcasting/auth`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ socket_id: this._wsSocketId, channel_name: channel, token: this.realtimeToken })
            });
            if (!res.ok) { console.warn('DM channel authorization refused'); return; }
            const { auth } = await res.json();
            this._wsSubscribe(channel, auth);
        } catch (e) { console.warn('DM channel authorization failed', e); }
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
                const response = await fetch(`${this.apiUrl}/api/private-message?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}&with_user=${encodeURIComponent(this.privateChat.withUser)}`);
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
                const response = await fetch(`${this.apiUrl}/api/history?username=${encodeURIComponent(this.username)}`);
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
        // Idempotent: clear any existing interval first so repeated calls (every
        // (re)connect calls this) never stack two beating timers.
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
        // Beat immediately (refresh presence + obtain the first realtime token so
        // the very first connection can present it), then every 60 seconds.
        this.sendHeartbeat();
        this.heartbeatInterval = setInterval(() => this.sendHeartbeat(), 60000);
    }

    // One heartbeat: updates presence and captures the rolling realtime token.
    async sendHeartbeat() {
        try {
            const res = await fetch(`${this.apiUrl}/api/heartbeat`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: this.username, sessionId: this.sessionId })
            });
            const data = await res.json().catch(() => null);
            if (data && data.realtime_token) {
                this.realtimeToken = data.realtime_token;
                // A WS private subscription that was waiting for the token can proceed.
                if (this._wsAwaitingToken) this._subscribePrivateChannel();
            }
            // Redundancy for the now-playing widget: normally the track arrives over
            // the socket, but a lost 'now_playing' push (a brief disconnect during a
            // track change) left the widget stuck on the old song. The heartbeat
            // carries the current cached track every 60s on both transports — re-sync
            // from it so a missed push self-corrects within a minute. Only apply a real
            // track: a null (cold cache) must NOT wipe a widget the socket set fine.
            if (data && data.now_playing) {
                this.renderNowPlaying(data.now_playing);
            }
            return data;
        } catch (error) {
            console.error('Heartbeat failed:', error);
            return null;
        }
    }

    // Keep this method as a fallback, but it's no longer polled
    async loadActiveUsers() {
        try {
            const response = await fetch(`${this.apiUrl}/api/active-users`);
            const data = await response.json();

            if (data.success) {
                this.activeUsersList = data.users; // Store for filtering conversations
                this.activeUsersCount.textContent = data.count;
                this.renderActiveUsers(data.users);

                // Update local display name cache and conversation display names
                if (!this.displayNameCache) {
                    this.displayNameCache = new Map();
                } else {
                    this.displayNameCache.clear();
                }
                data.users.forEach(u => {
                    if (u.display_name) {
                        this.displayNameCache.set(u.username, u.display_name);
                    }
                });
                this.updateConversationDisplayNames(data.users);
            }
        } catch (error) {
            console.error('Failed to load active users:', error);
        }
    }

    renderActiveUsers(users) {
        this.activeUsersContainer.innerHTML = users.map(user => {
            const isCurrentUser = user.username === this.username;
            const canMessage = !isCurrentUser && (this.chatMode === 'private' || this.chatMode === 'both');
            
            // Use display_name if available, otherwise username
            const displayName = user.display_name || user.username;
            
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
                <span class="user-name">${this.escapeHtml(displayName)}</span>
                ${profileDisplay ? `<span class="user-profile">${profileDisplay}</span>` : ''}
                ${isCurrentUser ? '<span class="user-badge">(you)</span>' : ''}
                ${canMessage ? '<span class="pm-icon">💬</span>' : ''}
            </div>`;
        }).join('');
    }
    
    /**
     * Reflect the open DM in the URL hash (#dm/<peer>) so a refresh restores it.
     * Passing null clears it. Guarded so our own change doesn't re-trigger the
     * hashchange handler.
     */
    _setDmHash(username) {
        this._suppressHashChange = true;
        try {
            if (username) {
                location.hash = 'dm/' + encodeURIComponent(username);
            } else if ((location.hash || '').indexOf('#dm/') === 0) {
                history.replaceState(null, '', location.pathname + location.search);
            }
        } catch (_) { /* ignore */ }
        setTimeout(() => { this._suppressHashChange = false; }, 0);
    }

    /** On load / hashchange, open the DM named in #dm/<peer> (if any). */
    restoreConversationFromHash() {
        const m = (location.hash || '').match(/^#dm\/(.+)$/);
        if (!m) return;
        let peer = '';
        try { peer = decodeURIComponent(m[1]); } catch (_) { peer = m[1]; }
        if (!peer || !this.username || this.chatMode === 'public') return;
        if (this.privateChat && this.privateChat.active && this.privateChat.withUser === peer) return;
        this.startPrivateChat(peer);
    }

    async startPrivateChat(username) {
        // Check if private chat is allowed
        if (this.chatMode === 'public') {
            alert('Private messaging is currently disabled.');
            return;
        }

        // Reset any DM typing cue carried over from a previous conversation. The
        // shared #typing-indicator is re-rendered below (after withUser is set) so a
        // leftover "<previous peer> is typing…" — e.g. a bot that was composing in the
        // DM we just left — doesn't stay frozen on screen in the newly-opened DM.
        this._dmPeerTypingUntil = 0;
        this._dmPeerTypingWho = null;

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

        // Now that the peer is set (and the carried-over cue cleared above), refresh
        // the typing indicator so any stale "<other> is typing…" from the previous
        // conversation is cleared immediately instead of lingering until the next event.
        this.renderTypingIndicator();

        // Keep the open conversation in the URL so a refresh restores it.
        this._setDmHash(username);

        // Mark conversation as read
        this.markConversationAsRead(username);
        
        // Get display name for this user from conversations or fetch it
        const conv = this.conversations.get(username);
        const displayName = conv?.displayName || username;
        
        // Update UI
        if (this.privateChatWith) {
            this.privateChatWith.textContent = displayName;
        }
        if (this.privateChatHeader) {
            this.privateChatHeader.style.display = 'flex';
        }

        // Switch the view to the (currently empty) private conversation right
        // away. This clears the public messages immediately so we never show
        // public chat under the private header even if the history fetch below
        // is slow or fails.
        this.renderPrivateMessages();

        // Load block state for this conversation (updates the Block/Unblock
        // button and disables input if a mutual block is in effect).
        this.loadBlockState(username);

        // Show and enable input container
        const inputContainer = document.getElementById('chat-input-container');
        if (inputContainer) {
            inputContainer.style.display = 'flex';
            inputContainer.classList.add('visibleChatInput');
        }
        if (this.messageInput) {
            this.messageInput.disabled = false;
            this.messageInput.placeholder = `Send private message to ${displayName}...`;
        }
        if (this.sendButton) {
            this.sendButton.disabled = false;
        }
        
        // Update photo button visibility
        this.updatePhotoButtonVisibility();
        
        // Load conversation history
        try {
            const response = await fetch(`${this.apiUrl}/api/private-message?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}&with_user=${encodeURIComponent(username)}`);
            const data = await response.json();
            
            if (data.success && data.messages) {
                this.privateChat.messages = data.messages;
                
                // Extract display_name from the loaded messages and update conversation
                let otherUserDisplayName = null;
                if (data.messages.length > 0) {
                    const firstMsg = data.messages[0];
                    otherUserDisplayName = firstMsg.from_username === username 
                        ? firstMsg.from_display_name 
                        : firstMsg.to_display_name;
                }
                
                // If no display name from messages, try cache or active users list
                if (!otherUserDisplayName) {
                    if (this.displayNameCache) {
                        otherUserDisplayName = this.displayNameCache.get(username);
                    }
                    if (!otherUserDisplayName && this.activeUsersList) {
                        const activeUser = this.activeUsersList.find(u => u.username === username);
                        if (activeUser?.display_name) {
                            otherUserDisplayName = activeUser.display_name;
                        }
                    }
                }
                
                
                    
                if (otherUserDisplayName) {
                    // Update the conversation with the display name
                    const conv = this.conversations.get(username);
                    if (conv) {
                        conv.displayName = otherUserDisplayName;
                        this.conversations.set(username, conv);
                    } else {
                        this.conversations.set(username, {
                            displayName: otherUserDisplayName,
                            lastMessage: '',
                            unreadCount: 0,
                            timestamp: Date.now()
                        });
                    }
                    
                    // Update UI with display name
                    if (this.privateChatWith) {
                        this.privateChatWith.textContent = otherUserDisplayName;
                    }
                    if (this.messageInput) {
                        this.messageInput.placeholder = `Send private message to ${otherUserDisplayName}...`;
                    }
                    
                    // Re-render conversations panel to show updated display name
                    this.renderConversations();
                } else {
                    
                }
                
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

        // Drop the DM from the URL.
        this._setDmHash(null);

        // Drop any stale DM typing cue and refresh the indicator for public view.
        this._dmPeerTypingUntil = 0;
        this.renderTypingIndicator();
        
        // Update UI
        if (this.privateChatHeader) {
            this.privateChatHeader.style.display = 'none';
        }
        if (this.galleryBtn) {
            this.galleryBtn.style.display = 'none';
        }
        this.closePrivateGallery();
        
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

    // ===================== DM blocking =====================

    /**
     * Fetch and apply block state for the conversation with `username`.
     * Updates the Block/Unblock button and disables input on a mutual block.
     */
    /** Fetch block state between the current user and `withUser`. */
    async getBlockState(withUser) {
        try {
            const resp = await fetch(`${this.apiUrl}/api/block?username=${encodeURIComponent(this.username)}&with_user=${encodeURIComponent(withUser)}`);
            const data = await resp.json();
            if (data.success) {
                return { i_blocked: !!data.i_blocked, is_blocked_between: !!data.is_blocked_between };
            }
        } catch (e) {
            // Non-fatal.
        }
        return { i_blocked: false, is_blocked_between: false };
    }

    /** POST a block/unblock action. Returns the parsed response. */
    async setBlock(targetUsername, action) {
        const resp = await fetch(`${this.apiUrl}/api/block`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: action,
                username: this.username,
                session_id: this.sessionId,
                target_username: targetUsername
            })
        });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || 'Failed');
        // Keep the public-feed block filter in sync, then re-apply it live.
        const t = (targetUsername || '').toLowerCase();
        this._blockedPublic = this._blockedPublic || new Set();
        if (action === 'unblock') this._blockedPublic.delete(t);
        else this._blockedPublic.add(t);
        this.applyBlockFilterToPublicFeed();
        return data;
    }

    /** Whether I've blocked `username` (case-insensitive). Never hides my own. */
    isBlockedByMe(username) {
        if (!username || !this._blockedPublic) return false;
        const u = username.toLowerCase();
        if (u === (this.username || '').toLowerCase()) return false;
        return this._blockedPublic.has(u);
    }

    /** Fetch the list of users I've blocked into a Set for public-feed filtering. */
    async loadBlockedUsers() {
        if (!this.username) return;
        try {
            const resp = await fetch(`${this.apiUrl}/api/block?username=${encodeURIComponent(this.username)}`);
            if (!resp.ok) return;
            const data = await resp.json();
            const list = (data && data.blocked_users) || [];
            this._blockedPublic = new Set(list.map(u => (typeof u === 'string' ? u : (u.username || '')).toLowerCase()).filter(Boolean));
            this.applyBlockFilterToPublicFeed();
        } catch (e) { /* best-effort */ }
    }

    /** Remove any already-rendered public messages from users I've now blocked. */
    applyBlockFilterToPublicFeed() {
        if (!this.messagesContainer || this.privateChat.active) return;
        this.messagesContainer.querySelectorAll('.message-username[data-username]').forEach(nameEl => {
            if (this.isBlockedByMe(nameEl.getAttribute('data-username'))) {
                const msg = nameEl.closest('.message');
                if (msg) msg.remove();
            }
        });
    }

    async loadBlockState(username) {
        if (!this.blockUserBtn) return;
        this._blockState = await this.getBlockState(username);
        // Only meaningful for the conversation we're still viewing.
        if (this.privateChat.active && this.privateChat.withUser === username) {
            this.applyBlockUI(username);
        }
    }

    /**
     * Render the Block/Unblock button and enable/disable input based on
     * this._blockState for the given conversation partner.
     */
    applyBlockUI(username) {
        const state = this._blockState || { i_blocked: false, is_blocked_between: false };

        if (this.blockUserBtn) {
            this.blockUserBtn.style.display = 'inline-flex';
            this.blockUserBtn.textContent = state.i_blocked ? '✅ Unblock' : '🚫 Block';
            this.blockUserBtn.classList.toggle('is-blocked', state.i_blocked);
        }

        // On any active block (either direction), messaging is not possible.
        const inputContainer = document.getElementById('chat-input-container');
        if (state.is_blocked_between) {
            if (this.messageInput) {
                this.messageInput.disabled = true;
                this.messageInput.placeholder = state.i_blocked
                    ? `You blocked ${username}. Unblock to chat.`
                    : `You can't message ${username}.`;
            }
            if (this.sendButton) this.sendButton.disabled = true;
            if (inputContainer) inputContainer.classList.add('input-blocked');
        } else {
            if (this.messageInput) {
                this.messageInput.disabled = false;
                this.messageInput.placeholder = `Send private message to ${username}...`;
            }
            if (this.sendButton) this.sendButton.disabled = false;
            if (inputContainer) inputContainer.classList.remove('input-blocked');
        }
    }

    /** Toggle block/unblock for the currently open private conversation. */
    async toggleBlockCurrentUser() {
        const target = this.privateChat.withUser;
        if (!target) return;

        const currentlyBlocked = this._blockState && this._blockState.i_blocked;
        const action = currentlyBlocked ? 'unblock' : 'block';

        if (action === 'block' && !confirm(`Block ${target}? You will no longer be able to exchange private messages.`)) {
            return;
        }

        try {
            await this.setBlock(target, action);
            // Refresh authoritative state from the server.
            await this.loadBlockState(target);
        } catch (e) {
            console.error('Error toggling block:', e);
            alert(e.message);
        }
    }

    async loadPublicMessages() {
        try {
            console.log('[loadPublicMessages] Starting...');
            const response = await fetch(`${this.apiUrl}/api/history?username=${encodeURIComponent(this.username)}`);
            const data = await response.json();
            
            if (data.success && data.messages) {
                console.log('[loadPublicMessages] Got', data.messages.length, 'messages');
                // Reset pagination state for fresh history load
                this.messagesOffset = data.messages.length;
                this.hasMoreMessages = data.messages.length > 0;
                
                this.messagesContainer.innerHTML = '';
                data.messages.forEach(msg => {
                    this.addMessageToUI(msg, false);
                });
                
                // Parse emojis
                if (typeof twemoji !== 'undefined') {
                    twemoji.parse(this.messagesContainer, {
                        folder: 'svg',
                        ext: '.svg'
                    });
                }
                
                // Disable smooth scroll temporarily to prevent scroll events during animation
                const container = document.getElementById('messages-container');
                if (container) {
                    const originalScrollBehavior = container.style.scrollBehavior;
                    container.style.scrollBehavior = 'auto';
                    
                    // Scroll to bottom immediately
                    container.scrollTop = container.scrollHeight;
                    
                    // Re-enable smooth scroll after a brief moment
                    setTimeout(() => {
                        container.style.scrollBehavior = originalScrollBehavior;
                        this.hasInitialScrolled = true;
                    }, 50);
                }
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
            const msgId = msg.id || msg.message_id;
            if (msgId) messageDiv.dataset.messageId = msgId;

            const timestamp = new Date(msg.created_at);
            const timeString = timestamp.toLocaleTimeString();
            const fullDate = timestamp.toLocaleString();

            // Use display_name if available, otherwise use username
            const displayName = isFromMe
                ? 'You'
                : (msg.from_display_name || msg.from_username);

            // Reply quote, when this DM is a reply to another one.
            let replyQuoteHTML = '';
            if (msg.reply_data && (msg.reply_data.from_username || msg.reply_data.username)) {
                const rdName = msg.reply_data.from_display_name || msg.reply_data.from_username
                    || msg.reply_data.display_name || msg.reply_data.username;
                const rdMsg = msg.reply_data.message || (msg.reply_data.has_attachment ? '[photo]' : '');
                const rdShort = rdMsg.length > 50 ? rdMsg.substring(0, 50) + '...' : rdMsg;
                replyQuoteHTML = `
                    <div class="reply-quote">
                        <div class="reply-quote-bar"></div>
                        <div class="reply-quote-content">
                            <span class="reply-quote-username">${this.escapeHtml(rdName)}</span>
                            <span class="reply-quote-message">${this.escapeHtml(rdShort)}</span>
                        </div>
                    </div>
                `;
            }

            // Reply action (any DM can be replied to).
            const replyButton = msgId ? `
                <button class="reply-message-btn" data-message-id="${msgId}" title="Reply to this message">↩️</button>
            ` : '';

            let bodyInner = '';
            if (msg.message) {
                bodyInner += `<div class="message-text">${this.formatMessageText(msg.message)}</div>`;
            }
            if (msg.attachment) {
                bodyInner += `
                    <div class="message-photo">
                        <img src="${this.escapeHtml(msg.attachment.file_path)}"
                             alt="Photo"
                             onclick="window.chatBox.openImageLightbox('${this.escapeHtml(msg.attachment.file_path)}')"
                             loading="lazy">
                    </div>
                `;
            }

            // Report action for the other person's DMs (never your own).
            const reportButton = (msgId && !isFromMe) ? `
                <button class="report-message-btn" data-message-id="${msgId}" title="Report this message">🚩</button>
            ` : '';

            // Read receipt on MY sent messages: ✓ sent, ✓✓ seen (read_at present).
            const receiptHTML = isFromMe
                ? `<span class="dm-receipt${msg.read_at ? ' seen' : ''}" title="${msg.read_at ? 'Seen' : 'Sent'}">${msg.read_at ? '✓✓' : '✓'}</span>`
                : '';

            messageDiv.innerHTML = `
                <div class="message-header">
                    <strong class="message-username">${this.escapeHtml(displayName)}</strong>
                    <span class="message-time" title="${this.escapeHtml(fullDate)}">${timeString}${receiptHTML}</span>
                </div>
                ${replyQuoteHTML}
                <div class="message-body">
                    ${bodyInner}
                    <div class="message-actions">
                        ${replyButton}
                        ${reportButton}
                    </div>
                </div>
            `;
            this.messagesContainer.appendChild(messageDiv);

            // Reply button → set reply state targeting this DM's id.
            const replyBtn = messageDiv.querySelector('.reply-message-btn');
            if (replyBtn) {
                replyBtn.addEventListener('click', () => {
                    this.setReplyState(msgId, displayName, msg.message || '[photo]');
                    this.showReplyPreview();
                    this.messageInput.focus();
                });
            }

            // Report button → reason menu (private message type).
            const reportBtn = messageDiv.querySelector('.report-message-btn');
            if (reportBtn) {
                reportBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.openReportMenu(reportBtn, msgId, 'private', msg.from_username, msg.message);
                });
            }

            // Emoji reactions (own DMs are display-only, like public chat).
            if (msgId) {
                this.setupReactions(messageDiv, msgId, msg.reactions || [], isFromMe);
            }

            // Fetch and render link preview for any URL in this message
            this.attachLinkPreviews(messageDiv);

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

        // Toggle the Gallery button based on whether the conversation has photos.
        this.updatePrivateGalleryButton();

        this.scrollToBottom();

        // I'm looking at this conversation → mark the peer's messages read (server
        // skips the broadcast when nothing was unread, so this is cheap to repeat).
        if (this.privateChat.active && this.privateChat.withUser) {
            this.sendReadReceipt(this.privateChat.withUser);
        }
    }

    loadHistory(messages) {
        // Don't load public history if in private chat mode
        if (this.privateChat.active) {
            return;
        }
        
        // Reset pagination state for fresh history load
        this.messagesOffset = messages.length;
        this.hasMoreMessages = messages.length > 0; // If we got messages, there might be more
        
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

        // Disable smooth scroll temporarily to prevent scroll events during animation
        const container = document.getElementById('messages-container');
        if (container) {
            const originalScrollBehavior = container.style.scrollBehavior;
            container.style.scrollBehavior = 'auto';
            
            // Scroll to bottom immediately
            container.scrollTop = container.scrollHeight;
            
            // Re-enable smooth scroll after a brief moment
            setTimeout(() => {
                container.style.scrollBehavior = originalScrollBehavior;
                this.hasInitialScrolled = true;
            }, 50);
        }
    }

    async reloadHistory() {
        // Fetch fresh history from server
        try {
            const response = await fetch(`${this.apiUrl}/api/history?username=${encodeURIComponent(this.username)}`);
            const data = await response.json();
            
            if (data.success && data.messages) {
                
                this.loadHistory(data.messages);
            }
        } catch (error) {
            console.error('Failed to reload history:', error);
        }
    }

    async loadMoreMessages() {
        // Prevent multiple simultaneous requests
        if (this.isLoadingMoreMessages) return;
        
        this.isLoadingMoreMessages = true;
        
        try {
            // Calculate offset based on how many messages we've loaded so far
            // messagesOffset tracks the starting point for the next batch
            const offset = this.messagesOffset;
            const limit = 50;
            
            // Get the first visible message before loading (for scroll anchor)
            const container = document.getElementById('messages-container');
            const messages = this.messagesContainer.querySelectorAll('.message');
            let anchorMessage = null;
            let anchorOffset = 0;
            
            if (messages.length > 0) {
                // Find the first message that's at least partially visible
                for (let msg of messages) {
                    const rect = msg.getBoundingClientRect();
                    if (rect.bottom > 0) {
                        anchorMessage = msg;
                        anchorOffset = rect.top; // Store the offset from top of viewport
                        break;
                    }
                }
            }
            
            const response = await fetch(`${this.apiUrl}/api/history?offset=${offset}&limit=${limit}&username=${encodeURIComponent(this.username)}`);
            const data = await response.json();
            
            if (!data.success || !data.messages || data.messages.length === 0) {
                // No more messages available
                this.hasMoreMessages = false;
                this.isLoadingMoreMessages = false;
                return;
            }
            
            // Prepend old messages to the top (in correct order)
            const messagesFragment = document.createDocumentFragment();
            
            data.messages.forEach(msg => {
                // Create message element without animation since these are old
                const messageDiv = document.createElement('div');
                messageDiv.className = 'message';
                const msgId = msg.id || msg.message_id;
                messageDiv.dataset.messageId = msgId;
                messageDiv.dataset.username = msg.username;
                messageDiv.dataset.timestamp = msg.timestamp;
                
                const timestamp = new Date(msg.timestamp * 1000);
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
                // Pin button (admin-only): pins the message to the top-of-chat bar.
                const pinButton = isAdmin && msgId ? `
                    <button class="pin-message-btn" data-message-id="${msgId}" title="Pin message" onclick="window.chatBox.pinMessage('${String(msgId).replace(/'/g, "\\'")}')">
                        📌
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
                if (msg.reply_data && msg.reply_data.username) {
                    const replyDisplayName = msg.reply_data.display_name || msg.reply_data.username;
                    const truncatedMessage = msg.reply_data.message.length > 50 
                        ? msg.reply_data.message.substring(0, 50) + '...'
                        : msg.reply_data.message;
                    replyQuoteHTML = `
                        <div class="reply-quote">
                            <div class="reply-quote-bar"></div>
                            <div class="reply-quote-content">
                                <span class="reply-quote-username">${this.escapeHtml(replyDisplayName)}</span>
                                <span class="reply-quote-message">${this.escapeHtml(truncatedMessage)}</span>
                            </div>
                        </div>
                    `;
                }
                
                // Use display_name if available, otherwise use username
                const displayName = msg.display_name || msg.username;

                const pinnedTrackHTML = msg.pinned_track
                    ? `<div class="pinned-track" title="Track playing when this message was sent">🎵 ${this.escapeHtml(msg.pinned_track)}</div>`
                    : '';

                messageDiv.innerHTML = `
                    <div class="message-header">
                        <span class="message-username" data-username="${this.escapeHtml(msg.username)}">${this.escapeHtml(displayName)}</span>
                        <span class="message-time" title="${this.escapeHtml(fullDate)}">${timeString}</span>
                    </div>
                    ${replyQuoteHTML}
                    ${pinnedTrackHTML}
                    <div class="message-body">
                        <div class="message-text">${this.formatMessageText(msg.message)}</div>
                        <div class="message-actions">
                            ${replyButton}
                            ${deleteButton}
                            ${pinButton}
                        </div>
                    </div>
                `;

                // Add event listener for delete button if admin
                if (isAdmin) {
                    const deleteBtn = messageDiv.querySelector('.delete-message-btn');
                    if (deleteBtn) {
                        deleteBtn.addEventListener('click', (e) => {
                            const button = e.target.closest('.delete-message-btn');
                            const mId = button ? button.getAttribute('data-message-id') : null;
                            this.deleteMessage(mId, button || e.target);
                        });
                    }
                }
                
                // Add event listener for reply button
                const replyBtn = messageDiv.querySelector('.reply-message-btn');
                if (replyBtn) {
                    replyBtn.addEventListener('click', (e) => {
                        const button = e.target.closest('.reply-message-btn');
                        const mId = button ? button.getAttribute('data-message-id') : null;
                        this.setReplyState(mId, displayName, msg.message);
                        this.showReplyPreview();
                        this.messageInput.focus();
                    });
                }

                // Emoji reactions (public messages)
                this.setupReactions(messageDiv, msgId, msg.reactions || [], msg.username === this.username);

                // Highlight older messages that mention the current user.
                if (this.messageMentionsMe(msg.message)) {
                    messageDiv.classList.add('mentions-me');
                }

                messagesFragment.appendChild(messageDiv);
            });
            
            // Insert all messages at the beginning
            if (this.messagesContainer.firstChild) {
                this.messagesContainer.insertBefore(messagesFragment, this.messagesContainer.firstChild);
            } else {
                this.messagesContainer.appendChild(messagesFragment);
            }
            
            // Parse emojis for new messages
            if (typeof twemoji !== 'undefined') {
                twemoji.parse(this.messagesContainer, {
                    folder: 'svg',
                    ext: '.svg'
                });
            }
            
            // Restore scroll position to the anchor message
            // This prevents jumping even if the user scrolled while waiting for the response
            if (anchorMessage && anchorMessage.parentElement) {
                // Scroll the anchor message to the same position it was before
                const newRect = anchorMessage.getBoundingClientRect();
                const scrollAdjustment = newRect.top - anchorOffset;
                container.scrollTop = container.scrollTop + scrollAdjustment;
            }
            
            // Update offset for next request
            this.messagesOffset += data.messages.length;
            
            // If we got fewer messages than requested, we've reached the end
            if (data.messages.length < limit) {
                this.hasMoreMessages = false;
            }
            
        } catch (error) {
            console.error('Failed to load more messages:', error);
        } finally {
            this.isLoadingMoreMessages = false;
        }
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

    handleMessageEdited(messageId, newText, editedAt, timestamp) {
        const msgEl = this.messagesContainer.querySelector(`.message[data-message-id="${messageId}"]`);
        if (!msgEl) return;

        // Update text
        const textEl = msgEl.querySelector('.message-text');
        if (textEl) {
            textEl.innerHTML = this.formatMessageText(newText);
        }

        // Update timestamp if provided (for correct edit button logic)
        if (typeof timestamp === 'number') {
            msgEl.dataset.timestamp = timestamp;
        }

        // Add/update the "edited" badge in the header
        const header = msgEl.querySelector('.message-header');
        if (header) {
            let badge = header.querySelector('.edited-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'edited-badge';
                header.appendChild(badge);
            }
            badge.textContent = '(edited)';
            badge.title = editedAt ? `Edited at ${new Date(editedAt).toLocaleTimeString()}` : 'Edited';
        }

        // Remove and re-render edit button if still within window
        const oldEditBtn = msgEl.querySelector('.edit-message-btn');
        if (oldEditBtn) oldEditBtn.remove();
        // Re-render edit button if still allowed
        const isOwnMsg = msgEl.dataset.username === this.username;
        const msgTimestamp = typeof timestamp === 'number' ? timestamp : (msgEl.dataset.timestamp ? parseInt(msgEl.dataset.timestamp) : 0);
        const msgAgeSeconds = Math.floor(Date.now() / 1000) - msgTimestamp;
        const canEdit = isOwnMsg && messageId && msgAgeSeconds < 600;
        if (canEdit) {
            const actions = msgEl.querySelector('.message-actions');
            if (actions) {
                const editBtn = document.createElement('button');
                editBtn.className = 'edit-message-btn';
                editBtn.setAttribute('data-message-id', messageId);
                editBtn.title = 'Edit message';
                editBtn.textContent = '✏️';
                editBtn.addEventListener('click', (e) => {
                    this.startEditMessage(messageId, msgEl, newText);
                });
                actions.insertBefore(editBtn, actions.firstChild.nextSibling); // after reply
            }
        }

        // Re-run link preview in case URLs changed
        this.attachLinkPreviews(msgEl);
    }

    startEditMessage(messageId, msgEl, currentText) {
        // Prevent double-editing
        if (msgEl.querySelector('.edit-inline-form')) return;

        const body = msgEl.querySelector('.message-body');
        if (!body) return;

        // Hide the normal text + actions while editing
        body.style.display = 'none';

        const form = document.createElement('div');
        form.className = 'edit-inline-form';
        form.innerHTML = `
            <textarea class="edit-inline-input" maxlength="500">${this.escapeHtml(currentText)}</textarea>
            <div class="edit-inline-actions">
                <button class="edit-cancel-btn">Cancel</button>
                <button class="edit-save-btn">Save</button>
            </div>
        `;
        body.after(form);

        const textarea = form.querySelector('.edit-inline-input');
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);

        // Cancel
        form.querySelector('.edit-cancel-btn').addEventListener('click', () => {
            form.remove();
            body.style.display = '';
        });

        // Save on button click
        form.querySelector('.edit-save-btn').addEventListener('click', () => {
            this.submitEditMessage(messageId, msgEl, textarea.value.trim(), form, body);
        });

        // Save on Ctrl+Enter / Cmd+Enter, cancel on Escape
        textarea.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                form.remove();
                body.style.display = '';
            } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                this.submitEditMessage(messageId, msgEl, textarea.value.trim(), form, body);
            }
        });
    }

    async submitEditMessage(messageId, msgEl, newText, form, body) {
        if (!newText) return;

        const saveBtn = form.querySelector('.edit-save-btn');
        saveBtn.disabled = true;
        saveBtn.textContent = '…';

        try {
            const response = await fetch('/api/edit-message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message_id: messageId,
                    message:    newText,
                    username:   this.username,
                    sessionId:  this.sessionId,
                }),
            });

            const data = await response.json();

            if (!response.ok || data.error) {
                alert(data.error || 'Failed to edit message');
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save';
                return;
            }

            // Update local UI immediately (SSE event will also arrive for other clients)
            form.remove();
            body.style.display = '';
            this.handleMessageEdited(messageId, data.message, data.edited_at);

        } catch (err) {
            console.error('Edit message error:', err);
            alert('Failed to edit message');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }
    }
    
    /** The tone presets for the notification sound (selectable in preferences). */
    static get SOUND_PRESETS() {
        return {
            beep:  [{ f: 800, t: 'sine', d: 0.10 }],
            ding:  [{ f: 1200, t: 'sine', d: 0.18 }],
            pop:   [{ f: 440, t: 'triangle', d: 0.06 }],
            chime: [{ f: 660, t: 'sine', d: 0.12 }, { f: 990, t: 'sine', d: 0.16, delay: 0.10 }],
        };
    }

    playNotificationSound() {
        // Do Not Disturb suppresses every audible cue.
        if (this.dndEnabled) return;
        const style = localStorage.getItem('chatSoundStyle') || 'beep';
        const preset = RadioChatBox.SOUND_PRESETS[style] || RadioChatBox.SOUND_PRESETS.beep;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            preset.forEach(({ f, t, d, delay }) => {
                const start = ctx.currentTime + (delay || 0);
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.frequency.value = f; osc.type = t || 'sine';
                gain.gain.setValueAtTime(0.3, start);
                gain.gain.exponentialRampToValueAtTime(0.01, start + d);
                osc.start(start);
                osc.stop(start + d);
            });
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
        // Guard against rendering a non-message payload as a chat bubble. The
        // realtime feed multiplexes several event shapes over one channel and the
        // event name is derived from `type`; anything unrecognised falls back to a
        // "message". A client running stale JS (or a future/foreign payload) can
        // therefore hand us an object with no text and no timestamp, which rendered
        // as an empty bubble showing "Invalid Date" until the next history reload
        // wiped it. A real message always has a finite timestamp AND some content
        // (text or an attachment) — reject anything else instead of drawing it.
        const ts = Number(messageData && messageData.timestamp);
        const hasContent = !!(messageData
            && ((typeof messageData.message === 'string' && messageData.message.trim() !== '')
                || messageData.attachment));
        if (!Number.isFinite(ts) || ts <= 0 || !hasContent) {
            console.warn('Ignoring non-message payload on the public feed:', messageData);
            return;
        }

        // Hide public messages from users I've blocked (blocking is otherwise
        // DM-scoped). My own messages always show.
        if (messageData.username && this.isBlockedByMe(messageData.username)) {
            return;
        }

        // Use either 'id' (from real-time) or 'message_id' (from database/admin API)
        const msgId = messageData.id || messageData.message_id;

        // Deduplicate: if a message with the same id is already in the container, skip
        if (msgId && this.messagesContainer && this.messagesContainer.querySelector(`.message[data-message-id="${msgId}"]`)) {
            if (animate) {
                this.scrollToBottom();
            }
            return;
        }

        const messageDiv = document.createElement('div');
        const isOwnMessage = messageData.username === this.username;
        messageDiv.className = `message ${isOwnMessage ? 'own-message' : ''}`;
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
        // Pin button (admin-only): pins the message to the top-of-chat bar.
        const pinButton = isAdmin && msgId ? `
            <button class="pin-message-btn" data-message-id="${msgId}" title="Pin message" onclick="window.chatBox.pinMessage('${String(msgId).replace(/'/g, "\\'")}')">
                📌
            </button>
        ` : '';

        // Add reply button for all messages
        const replyButton = msgId ? `
            <button class="reply-message-btn" data-message-id="${msgId}" title="Reply to this message">
                ↩️
            </button>
        ` : '';

        // Edit button: only for own messages, only when within 10 min window
        const isOwnMsg = messageData.username === this.username;
        const msgAgeSeconds = Math.floor(Date.now() / 1000) - (messageData.timestamp || 0);
        const canEdit = isOwnMsg && msgId && msgAgeSeconds < 600;
        const editButton = canEdit ? `
            <button class="edit-message-btn" data-message-id="${msgId}" title="Edit message">
                ✏️
            </button>
        ` : '';

        // Report button: flag someone else's message for the moderators.
        const reportButton = (msgId && !isOwnMsg) ? `
            <button class="report-message-btn" data-message-id="${msgId}" title="Report this message">
                🚩
            </button>
        ` : '';

        // Edited badge
        const editedBadge = messageData.edited_at
            ? `<span class="edited-badge" title="Edited">(edited)</span>`
            : '';

        // Build reply quote HTML if this is a reply
        let replyQuoteHTML = '';
        if (messageData.reply_data && messageData.reply_data.username) {
            const replyDisplayName = messageData.reply_data.display_name || messageData.reply_data.username;
            const truncatedMessage = messageData.reply_data.message.length > 50 
                ? messageData.reply_data.message.substring(0, 50) + '...'
                : messageData.reply_data.message;
            replyQuoteHTML = `
                <div class="reply-quote">
                    <div class="reply-quote-bar"></div>
                    <div class="reply-quote-content">
                        <span class="reply-quote-username">${this.escapeHtml(replyDisplayName)}</span>
                        <span class="reply-quote-message">${this.escapeHtml(truncatedMessage)}</span>
                    </div>
                </div>
            `;
        }
        
        const displayName = messageData.display_name || messageData.username;

        // Pinned "now playing" track badge
        const pinnedTrackHTML = messageData.pinned_track
            ? `<div class="pinned-track" title="Track playing when this message was sent">🎵 ${this.escapeHtml(messageData.pinned_track)}</div>`
            : '';

        messageDiv.innerHTML = `
            <div class="message-header">
                <span class="message-username" data-username="${this.escapeHtml(messageData.username)}">${this.escapeHtml(displayName)}</span>
                <span class="message-time" title="${this.escapeHtml(fullDate)}">${timeString}</span>
                ${editedBadge}
            </div>
            ${replyQuoteHTML}
            ${pinnedTrackHTML}
            <div class="message-body">
                <div class="message-text">${this.formatMessageText(messageData.message)}</div>
                <div class="message-actions">
                    ${replyButton}
                    ${reportButton}
                    ${editButton}
                    ${deleteButton}
                    ${pinButton}
                </div>
            </div>
        `;

        // Report button → reason menu.
        const reportBtn = messageDiv.querySelector('.report-message-btn');
        if (reportBtn) {
            reportBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.openReportMenu(reportBtn, msgId, 'public', messageData.username, messageData.message);
            });
        }

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

        // Add event listener for edit button (own messages within 10 min)
        const editBtn = messageDiv.querySelector('.edit-message-btn');
        if (editBtn) {
            editBtn.addEventListener('click', (e) => {
                const button = e.target.closest('.edit-message-btn');
                const msgId = button ? button.getAttribute('data-message-id') : null;
                this.startEditMessage(msgId, messageDiv, messageData.message);
            });
        }

        // Emoji reactions (public messages)
        this.setupReactions(messageDiv, msgId, messageData.reactions || [], isOwnMessage);

        // Highlight messages that mention the current user, and notify on new ones.
        if (this.messageMentionsMe(messageData.message)) {
            messageDiv.classList.add('mentions-me');
            if (!isOwnMessage && animate) {
                if (this.soundEnabled) this.playNotificationSound();
                this.showTitleNotification('💬 You were mentioned!');
            }
        }

        this.messagesContainer.appendChild(messageDiv);
        
        // Fetch and render link preview for any URL in this message
        this.attachLinkPreviews(messageDiv);

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

    // ===================== Emoji reactions =====================

    /** Allowed reaction emojis (must match ReactionService::ALLOWED_EMOJIS). */
    getAllowedReactions() {
        return ['👍', '❤️', '😂', '😮', '😢', '🔥', '🤘'];
    }

    /**
     * Add the react (＋) button and reactions bar to a message node and render
     * any existing reactions. `reactions` = [{emoji,count,mine}].
     * You cannot react to your own messages, so the react button and pill
     * clicks are disabled for own messages (others' reactions still render).
     */
    setupReactions(messageDiv, msgId, reactions = [], isOwn = false) {
        if (!msgId) return;
        if (!this.myReactions) this.myReactions = new Map();

        if (!isOwn) {
            const actions = messageDiv.querySelector('.message-actions');
            if (actions && !actions.querySelector('.react-message-btn')) {
                const btn = document.createElement('button');
                btn.className = 'react-message-btn';
                btn.setAttribute('data-message-id', msgId);
                btn.title = 'Add reaction';
                btn.textContent = '😊';
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.openReactionPicker(msgId, btn);
                });
                actions.appendChild(btn);
            }
        }

        const body = messageDiv.querySelector('.message-body');
        let bar = body ? body.querySelector('.message-reactions') : null;
        if (body && !bar) {
            bar = document.createElement('div');
            bar.className = 'message-reactions';
            bar.setAttribute('data-message-id', msgId);
            if (isOwn) {
                bar.classList.add('own-reactions');
            }
            body.appendChild(bar);
        }

        // Render directly into the bar element: the message node may not be in
        // the messages container yet (history/pagination build it detached), so
        // we can't rely on a container query here.
        // Reactions coming from history/DB carry authoritative "mine" flags.
        this.renderReactionBar(msgId, reactions, { authoritative: true, el: bar });
    }

    /**
     * Render the reactions bar for a message.
     * opts.authoritative: reactions include correct per-viewer "mine" flags
     * (history load / our own toggle response) so we reset the local mine-set;
     * otherwise (SSE broadcast, counts only) we keep the existing mine-set.
     */
    renderReactionBar(msgId, reactions, opts = {}) {
        if (!this.myReactions) this.myReactions = new Map();
        let mineSet = this.myReactions.get(msgId) || new Set();
        if (opts.authoritative) {
            mineSet = new Set((reactions || []).filter(r => r.mine).map(r => r.emoji));
        }
        this.myReactions.set(msgId, mineSet);

        // Prefer an explicitly provided bar element (message node may still be
        // detached during history/pagination rendering); otherwise look it up.
        const container = opts.el
            || (this.messagesContainer && this.messagesContainer.querySelector(`.message-reactions[data-message-id="${msgId}"]`));
        if (!container) return;

        // Own messages: reactions are display-only (you can't react to yourself).
        const interactive = !container.classList.contains('own-reactions');

        const list = (reactions || []).filter(r => r.count > 0);
        container.innerHTML = list.map(r => {
            const active = mineSet.has(r.emoji) ? 'active' : '';
            const cls = `reaction-pill ${active} ${interactive ? '' : 'static'}`.trim();
            return `<button class="${cls}" data-emoji="${r.emoji}"${interactive ? '' : ' disabled'}>${r.emoji} <span class="reaction-count">${r.count}</span></button>`;
        }).join('');
        container.style.display = list.length ? 'flex' : 'none';

        if (interactive) {
            container.querySelectorAll('.reaction-pill').forEach(pill => {
                pill.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.toggleReaction(msgId, pill.getAttribute('data-emoji'));
                });
            });
        }

        // Lazily reveal who reacted as a native tooltip on first hover.
        container.querySelectorAll('.reaction-pill').forEach(pill => {
            pill.addEventListener('mouseenter', () => this.loadReactionWho(msgId, container), { once: true });
        });
    }

    /**
     * Fetch the who-reacted roster for a message (once) and set each pill's
     * `title` to its list of usernames — a lightweight hover tooltip.
     */
    async loadReactionWho(msgId, container) {
        try {
            const isPrivate = !!(this.privateChat && this.privateChat.active);
            const q = `message_id=${encodeURIComponent(msgId)}${isPrivate ? '&dm=1' : ''}`;
            const resp = await fetch(`${this.apiUrl}/api/react/who?${q}`);
            if (!resp.ok) return;
            const data = await resp.json();
            const byEmoji = {};
            (data.reactions || []).forEach(r => { byEmoji[r.emoji] = r.users || []; });
            container.querySelectorAll('.reaction-pill').forEach(pill => {
                const users = byEmoji[pill.getAttribute('data-emoji')];
                if (users && users.length) {
                    const shown = users.slice(0, 20).join(', ');
                    pill.title = users.length > 20 ? `${shown} +${users.length - 20} more` : shown;
                }
            });
        } catch (e) { /* tooltip is best-effort */ }
    }

    /** Real-time reaction update (SSE): counts only, preserve our mine-set. */
    handleReactionUpdate(data) {
        if (!data || !data.message_id) return;
        const counts = data.counts || {};
        const reactions = Object.keys(counts).map(emoji => ({ emoji, count: counts[emoji], mine: false }));
        this.renderReactionBar(data.message_id, reactions, { authoritative: false });
    }

    /**
     * Toggle one of our reactions via the API. In a private conversation the DM
     * endpoint is used (its own table, keyed by the numeric private_messages id);
     * in public chat the message_id string is used.
     */
    async toggleReaction(msgId, emoji) {
        try {
            const isPrivate = !!(this.privateChat && this.privateChat.active);
            const url = isPrivate ? `${this.apiUrl}/api/private/react` : `${this.apiUrl}/api/react`;
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message_id: msgId,
                    username: this.username,
                    session_id: this.sessionId,
                    emoji: emoji
                })
            });
            const data = await resp.json();
            if (!resp.ok) throw new Error(data.error || 'Failed to react');
            // Response carries authoritative reactions (with our mine flags).
            this.renderReactionBar(msgId, data.reactions, { authoritative: true });
        } catch (error) {
            console.error('Error toggling reaction:', error);
        }
    }

    /** Open the emoji picker popover anchored to the react button. */
    openReactionPicker(msgId, anchorBtn) {
        this.closeReactionPicker();
        const picker = document.createElement('div');
        picker.className = 'reaction-picker';
        picker.innerHTML = this.getAllowedReactions()
            .map(e => `<button class="reaction-option" data-emoji="${e}">${e}</button>`)
            .join('');
        document.body.appendChild(picker);

        // Position under the button, but clamp within the viewport so it never
        // renders off-screen (e.g. for right-aligned own messages / edge cases).
        const rect = anchorBtn.getBoundingClientRect();
        const pickerWidth = picker.offsetWidth || 220;
        const margin = 8;
        let left = window.scrollX + rect.left;
        const maxLeft = window.scrollX + document.documentElement.clientWidth - pickerWidth - margin;
        if (left > maxLeft) left = maxLeft;
        if (left < window.scrollX + margin) left = window.scrollX + margin;
        picker.style.top = `${window.scrollY + rect.bottom + 4}px`;
        picker.style.left = `${left}px`;

        picker.querySelectorAll('.reaction-option').forEach(opt => {
            opt.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleReaction(msgId, opt.getAttribute('data-emoji'));
                this.closeReactionPicker();
            });
        });

        this._reactionPicker = picker;
        setTimeout(() => {
            this._reactionPickerOutside = (ev) => {
                if (this._reactionPicker && !this._reactionPicker.contains(ev.target)) {
                    this.closeReactionPicker();
                }
            };
            document.addEventListener('click', this._reactionPickerOutside);
        }, 0);
    }

    closeReactionPicker() {
        if (this._reactionPicker) {
            this._reactionPicker.remove();
            this._reactionPicker = null;
        }
        if (this._reactionPickerOutside) {
            document.removeEventListener('click', this._reactionPickerOutside);
            this._reactionPickerOutside = null;
        }
    }

    /** Small popup to pick a report reason, anchored to the flag button. */
    openReportMenu(anchorBtn, msgId, messageType, reportedUsername, contentSnapshot) {
        this.closeReportMenu();
        const reasons = [
            ['spam', 'Spam'],
            ['harassment', 'Harassment'],
            ['inappropriate', 'Inappropriate'],
            ['offensive', 'Offensive language'],
            ['impersonation', 'Impersonation'],
            ['other', 'Other'],
        ];
        const menu = document.createElement('div');
        menu.className = 'report-menu';
        menu.innerHTML = '<div class="report-menu-title">Report message</div>'
            + reasons.map(([v, l]) => `<button class="report-option" data-reason="${v}">${this.escapeHtml(l)}</button>`).join('')
            + '<label class="report-anon"><input type="checkbox" class="report-anon-cb"> Report anonymously</label>';
        document.body.appendChild(menu);

        const rect = anchorBtn.getBoundingClientRect();
        const width = menu.offsetWidth || 180;
        let left = window.scrollX + rect.left;
        const maxLeft = window.scrollX + document.documentElement.clientWidth - width - 8;
        if (left > maxLeft) left = maxLeft;
        menu.style.top = `${window.scrollY + rect.bottom + 4}px`;
        menu.style.left = `${Math.max(8, left)}px`;

        // Keep the menu open when toggling the anonymous checkbox.
        const anonLabel = menu.querySelector('.report-anon');
        if (anonLabel) anonLabel.addEventListener('click', (e) => e.stopPropagation());

        menu.querySelectorAll('.report-option').forEach(opt => {
            opt.addEventListener('click', (e) => {
                e.stopPropagation();
                const anon = !!(menu.querySelector('.report-anon-cb') && menu.querySelector('.report-anon-cb').checked);
                this.submitReport(msgId, messageType, reportedUsername, opt.getAttribute('data-reason'), contentSnapshot, anon);
                this.closeReportMenu();
            });
        });

        this._reportMenu = menu;
        setTimeout(() => {
            this._reportMenuOutside = (ev) => {
                if (this._reportMenu && !this._reportMenu.contains(ev.target)) this.closeReportMenu();
            };
            document.addEventListener('click', this._reportMenuOutside);
        }, 0);
    }

    closeReportMenu() {
        if (this._reportMenu) { this._reportMenu.remove(); this._reportMenu = null; }
        if (this._reportMenuOutside) {
            document.removeEventListener('click', this._reportMenuOutside);
            this._reportMenuOutside = null;
        }
    }

    async submitReport(msgId, messageType, reportedUsername, reason, contentSnapshot, anonymous = false) {
        try {
            const resp = await fetch(`${this.apiUrl}/api/report`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    username: this.username,
                    session_id: this.sessionId,
                    message_id: msgId,
                    message_type: messageType || 'public',
                    reported_username: reportedUsername || '',
                    reason: reason,
                    content_snapshot: (contentSnapshot || '').substring(0, 2000),
                    anonymous: anonymous ? 'true' : 'false',
                }),
            });
            const data = await resp.json();
            if (data.success) {
                alert('Report sent. Thank you — the moderators will review it.');
            } else {
                alert('Could not send report: ' + (data.error || 'error'));
            }
        } catch (e) {
            alert('Could not send report.');
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
                const replyTo = (this.replyState.active && this.replyState.messageId)
                    ? this.replyState.messageId : null;
                await this.sendPrivateMessage(this.privateChat.withUser, message, attachmentId, replyTo);
                this.clearReplyState();

                // We just sent — stop the "typing" cue to this peer.
                if (this._typingEnabled) {
                    clearTimeout(this._typingStopTimer);
                    this._typingLastSent = 0;
                    this.sendTypingState(false, this.privateChat.withUser);
                }

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

                // Include the pinned track if the user attached "now playing".
                if (this.pinTrackActive && this.currentTrack) {
                    messagePayload.pinned_track = this.currentTrack;
                }

                const response = await fetch(`${this.apiUrl}/api/send`, {
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

                // A slash-command: the server answered US directly (nothing was
                // posted). Show the reply as a local system message and stop.
                if (data.command) {
                    this.messageInput.value = '';
                    this.clearReplyState();
                    this.setPinTrack(false);
                    this.showSystemMessage(data.response || '');
                    return;
                }

                // Clear reply state after sending
                this.clearReplyState();

                // We just sent — we're no longer "typing".
                if (this._typingEnabled) {
                    clearTimeout(this._typingStopTimer);
                    this._typingLastSent = 0;
                    this.sendTypingState(false);
                }

                // Clear the pinned-track attachment after sending
                this.setPinTrack(false);

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

    /**
     * Append a local, ephemeral system message to the public chat (only this
     * client sees it) — used for slash-command replies. Newlines are preserved.
     */
    showSystemMessage(text) {
        if (!this.messagesContainer || !text) return;
        const el = document.createElement('div');
        el.className = 'system-message';
        el.innerHTML = this.escapeHtml(String(text)).replace(/\n/g, '<br>');
        this.messagesContainer.appendChild(el);
        this.scrollToBottom();
    }

    updateStatus(status, text) {
        if (this.statusIndicator && this.statusText) {
            this.statusIndicator.className = `status-${status}`;
            this.statusText.textContent = text;
        }
    }

    scrollToBottom() {
        // Simple scroll to bottom
        const container = document.getElementById('messages-container');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
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
            
            const response = await fetch(`${this.apiUrl}/api/admin/delete-message`, {
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
        // Close the WebSocket too — this was previously omitted, so on the WS
        // transport every reconnect/visibility/online cycle opened a fresh socket
        // and left the old one OPEN. They accumulated (a tab open for hours reached
        // half a dozen live sockets), each delivering every message — which also
        // duplicated DMs and now-playing pushes. Detach onclose first so tearing it
        // down here does not itself trigger reconnect().
        if (this._ws) {
            try { this._ws.onclose = null; this._ws.onmessage = null; this._ws.onerror = null; this._ws.close(); } catch (_) {}
            this._ws = null;
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
        // A DM typing cue (shares the private channel, tagged type:'typing').
        if (messageData && messageData.type === 'typing') {
            this.handleDmTyping(messageData);
            return;
        }
        // A "read receipt": the peer read the messages I sent them.
        if (messageData && messageData.type === 'read') {
            // The reader is from_username; if I'm viewing that conversation, mark my
            // sent bubbles as seen.
            if (this.privateChat && this.privateChat.active
                && this.privateChat.withUser === messageData.from_username) {
                this.markMySentMessagesSeen();
            }
            return;
        }
        // Author-directed reaction notification (someone reacted to my message).
        if (messageData && messageData.type === 'reaction_notification') {
            if (messageData.to_username === this.username && messageData.from_username !== this.username) {
                this.showReactionNotification(messageData.from_username, messageData.emoji);
                // A persistent notification was also written server-side — refresh the badge.
                this.refreshNotificationCount();
            }
            return;
        }
        // A DM reaction update (shares the private channel, tagged type:'reaction')
        // is not a new message: update the pills on the target bubble in place.
        if (messageData && messageData.type === 'reaction') {
            const other = messageData.from_username === this.username
                ? messageData.to_username : messageData.from_username;
            if (this.privateChat.active && other === this.privateChat.withUser && messageData.message_id) {
                this.handleReactionUpdate({ message_id: messageData.message_id, counts: messageData.counts });
            }
            return;
        }

        const isFromMe = messageData.from_username === this.username;
        const otherUser = isFromMe ? messageData.to_username : messageData.from_username;
        const otherUserDisplayName = isFromMe ? messageData.to_display_name : messageData.from_display_name;

        // The peer's message just landed → they're no longer "typing".
        if (!isFromMe && this.privateChat && this.privateChat.active
            && otherUser === this.privateChat.withUser && this._dmPeerTypingUntil) {
            this._dmPeerTypingUntil = 0;
            this.renderTypingIndicator();
        }

        // Realtime delivery is at-least-once: a flaky mobile connection that
        // reconnects — or briefly runs both transports during a WS→SSE fallback —
        // can deliver the same DM more than once. Without a guard each copy was
        // rendered, incremented the unread badge and replayed the sound (the bug
        // where the sender saw their own DM three times). Drop a DM we have already
        // handled live, keyed by id. Bounded so the set never grows without limit;
        // history loaded over REST bypasses this path, so re-opening a conversation
        // still shows everything.
        const dmId = messageData.id || messageData.message_id || null;
        if (dmId != null) {
            if (!this._seenDmIds) { this._seenDmIds = new Set(); }
            if (this._seenDmIds.has(dmId)) { return; }
            this._seenDmIds.add(dmId);
            if (this._seenDmIds.size > 500) {
                // Drop the oldest ~100 ids (insertion order) to cap memory.
                const it = this._seenDmIds.values();
                for (let i = 0; i < 100; i++) { this._seenDmIds.delete(it.next().value); }
            }
        }

        // Track analytics for received messages
        if (!isFromMe && window.analytics) {
            window.analytics.trackPrivateMessageReceived(messageData.from_username);
        }
        
        // Update conversations list
        const displayText = messageData.message || (messageData.attachment ? '[Photo]' : '');
        this.updateConversation(otherUser, displayText, isFromMe, otherUserDisplayName);

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

            // Add to private chat messages and re-render (carry id/reply/reactions
            // so the reply quote and reaction pills render for live messages too).
            this.privateChat.messages.push({
                id: messageData.id || messageData.message_id || null,
                from_username: messageData.from_username,
                from_display_name: messageData.from_display_name,
                to_username: messageData.to_username,
                to_display_name: messageData.to_display_name,
                message: messageData.message || '',
                attachment: messageData.attachment || null,
                reply_data: messageData.reply_data || null,
                reactions: messageData.reactions || [],
                created_at: new Date(messageData.timestamp * 1000).toISOString()
            });

            this.renderPrivateMessages();
        } else {
            // Not in private chat mode - show inline with indicator
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message private-message clickable-private';
            const inlineId = messageData.id || messageData.message_id || null;
            if (inlineId != null) messageDiv.dataset.messageId = inlineId;
            messageDiv.title = `Click to open private chat with ${this.escapeHtml(otherUserDisplayName || otherUser)}`;

            // Clicking the notification box opens the private conversation with
            // the other user (ignore clicks on the attachment image, which has
            // its own open-in-new-tab behavior).
            messageDiv.addEventListener('click', (e) => {
                if (e.target.closest('.message-photo')) return;
                this.startPrivateChat(otherUser);
            });

            const timestamp = new Date(messageData.timestamp * 1000);
            const timeString = timestamp.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });

            // Prefer display names where available
            const fromLabel = isFromMe ? 'You' : this.escapeHtml(otherUserDisplayName || messageData.from_username);
            const toLabel = isFromMe ? this.escapeHtml(otherUserDisplayName || otherUser) : 'you';

            let content = `
                <div class="message-header">
                    <span class="message-username">${fromLabel}</span>
                    <span class="private-indicator">🔒 Private to ${toLabel}</span>
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
                             onclick="event.stopPropagation(); window.chatBox.openImageLightbox('${this.escapeHtml(messageData.attachment.file_path)}')"
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
    
    async sendPrivateMessage(toUsername, message, attachmentId = null, replyToId = null) {
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

            if (replyToId) {
                payload.reply_to_id = replyToId;
            }
            
            const response = await fetch(`${this.apiUrl}/api/private-message`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || 'Failed to send private message');
            }
            
            // Update conversation with sent message
            // Get existing display name from conversation
            const conv = this.conversations.get(toUsername);
            const displayName = conv?.displayName || toUsername;
            this.updateConversation(toUsername, message || '[Photo]', true, displayName);
            
            return true;
        } catch (error) {
            console.error('Error sending private message:', error);
            alert(error.message);
            return false;
        }
    }
    
    handlePhotoSelect(event) {
        const file = event.target.files[0];
        this.acceptPhotoFile(file);
        event.target.value = ''; // Reset input
    }

    /** Whether a photo can be attached right now (same rule as the 📷 button). */
    photosAllowedNow() {
        const photosAllowed = !this.settings || this.settings.allow_photo_uploads !== 'false';
        return photosAllowed && this.privateChat && this.privateChat.active;
    }

    /**
     * Validate an image File (type + size) and, if ok, set it as the pending
     * attachment and show the preview. Shared by the file picker and paste.
     */
    acceptPhotoFile(file) {
        if (!file) return false;

        // Type check that tolerates mobile browsers. On Android Chrome a photo
        // picked from the gallery/camera often arrives with an EMPTY file.type (or
        // a HEIC type), which the old strict allow-list wrongly rejected — so the
        // user "couldn't send". Accept when the MIME is a known image type OR,
        // when the browser didn't give us one, fall back to the file extension.
        // The server does the authoritative content check (getimagesize), so a
        // permissive client check is safe.
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        const allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        const type = (file.type || '').toLowerCase();
        const ext = (file.name || '').split('.').pop().toLowerCase();
        const heicLike = type === 'image/heic' || type === 'image/heif' || ext === 'heic' || ext === 'heif';

        if (heicLike) {
            alert('HEIC photos aren\'t supported. In your camera settings choose "Most compatible" (JPEG), or pick a JPG/PNG.');
            return false;
        }
        const typeOk = allowedTypes.includes(type);
        const extOk = allowedExt.includes(ext);
        // Reject only when we're confident it isn't an image: a non-image MIME that
        // also has no image extension. Empty/generic MIME with an image extension
        // (the common Android case) is allowed through.
        if (!typeOk && !extOk) {
            alert('Please select a valid image file (JPG, PNG, GIF, or WebP)');
            return false;
        }

        const maxSizeMB = parseInt(this.settings.max_photo_size_mb || 5);
        const maxSizeBytes = maxSizeMB * 1024 * 1024;
        if (file.size > maxSizeBytes) {
            alert(`Photo is too large. Maximum size is ${maxSizeMB}MB.`);
            return false;
        }

        this.selectedPhoto = file;
        this.showPhotoPreview(file);
        return true;
    }

    /**
     * Paste an image straight into the chat: if the clipboard holds an image,
     * attach it (same flow as picking a file) instead of pasting binary text.
     */
    handlePaste(event) {
        const items = (event.clipboardData && event.clipboardData.items) || [];
        for (const item of items) {
            if (item.type && item.type.indexOf('image/') === 0) {
                const blob = item.getAsFile();
                if (!blob) continue;
                event.preventDefault();
                if (!this.photosAllowedNow()) {
                    alert('Photo uploads are only available in private messages.');
                    return;
                }
                // Clipboard images are usually nameless — give it a sane filename.
                const ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                const named = new File([blob], `pasted-${Date.now()}.${ext}`, { type: blob.type });
                this.acceptPhotoFile(named);
                return;
            }
        }
        // No image in the clipboard — let the normal text paste happen.
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
    
    /**
     * Downscale/compress a large photo in the browser before upload. Big camera
     * photos (common on mobile) are drawn onto a canvas capped at ~1920px and
     * re-encoded as JPEG, which shrinks the upload and avoids server-side size
     * rejections. Animated GIFs and already-small images are returned unchanged.
     */
    async maybeDownscaleImage(file) {
        try {
            const type = (file.type || '').toLowerCase();
            if (type === 'image/gif') return file;                 // keep animation
            if (file.size && file.size < 1024 * 1024 && type) return file; // small enough
            const bitmap = await this._loadBitmap(file);
            if (!bitmap) return file;
            const maxDim = 1920;
            let { width, height } = bitmap;
            if (width <= maxDim && height <= maxDim && file.size < 2 * 1024 * 1024) return file;
            const scale = Math.min(1, maxDim / Math.max(width, height));
            const w = Math.max(1, Math.round(width * scale));
            const h = Math.max(1, Math.round(height * scale));
            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            canvas.getContext('2d').drawImage(bitmap, 0, 0, w, h);
            const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.85));
            if (!blob) return file;
            const name = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
            return new File([blob], name, { type: 'image/jpeg' });
        } catch (e) {
            return file; // never block the upload on a downscale failure
        }
    }

    /** Load a File into an ImageBitmap (or <img> fallback) for canvas drawing. */
    async _loadBitmap(file) {
        try {
            if (window.createImageBitmap) return await createImageBitmap(file);
        } catch (e) { /* fall through */ }
        return await new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
            img.src = url;
        });
    }

    async uploadPhoto(file, recipient) {
        // Shrink big images client-side first (mobile camera photos especially).
        file = await this.maybeDownscaleImage(file);
        const formData = new FormData();
        formData.append('photo', file);
        formData.append('username', this.username);
        formData.append('recipient', recipient);
        formData.append('sessionId', this.sessionId);
        
        try {
            const response = await fetch(`${this.apiUrl}/api/upload-photo`, {
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
            // Load latest active users to ensure accurate filtering
            await this.loadActiveUsers();
            // Load all conversations from server
            await this.loadAllConversations();
            this.renderConversations();
        }
    }
    
    async loadAllConversations() {
        try {
            const response = await fetch(`${this.apiUrl}/api/private-message?username=${encodeURIComponent(this.username)}&session_id=${encodeURIComponent(this.sessionId)}`);
            const data = await response.json();
            
            if (data.success && data.messages) {
                // Group messages by conversation partner
                const conversationMap = new Map();
                
                data.messages.forEach(msg => {
                    const otherUser = msg.from_username === this.username ? msg.to_username : msg.from_username;
                    const existing = conversationMap.get(otherUser);

                    // Resolve display name for the conversation partner from message, cache, or active users
                    let displayName = null;
                    if (otherUser === msg.from_username && msg.from_display_name) {
                        displayName = msg.from_display_name;
                    } else if (otherUser === msg.to_username && msg.to_display_name) {
                        displayName = msg.to_display_name;
                    }
                    // Fallback to cached display names
                    if (!displayName && this.displayNameCache) {
                        displayName = this.displayNameCache.get(otherUser) || null;
                    }
                    // Fallback to active users list
                    if (!displayName && Array.isArray(this.activeUsersList)) {
                        const au = this.activeUsersList.find(u => u.username === otherUser);
                        if (au && au.display_name) {
                            displayName = au.display_name;
                        }
                    }
                    
                    if (!existing || new Date(msg.created_at) > new Date(existing.timestamp)) {
                        conversationMap.set(otherUser, {
                            displayName: displayName || existing?.displayName || null,
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
                        // Preserve existing displayName if we don't have one yet
                        conv.displayName = conv.displayName || existing.displayName || null;
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
        
        // Get set of active usernames for quick lookup
        const activeUsernames = new Set(this.activeUsersList.map(u => u.username));

        // Show ALL conversations (including with users who are currently offline).
        // Previously offline users were filtered out, which meant an unread
        // conversation with a user who went offline could never be opened to
        // clear it — leaving the unread badge stuck on forever. Keeping offline
        // conversations visible lets the user open them (clearing the unread
        // count) and message users who just dropped off (unstable connections).
        const conversationsArray = Array.from(this.conversations.entries())
            .sort((a, b) => b[1].timestamp - a[1].timestamp);

        if (conversationsArray.length === 0) {
            this.conversationsList.innerHTML = '<div class="empty-conversations">No conversations yet</div>';
            return;
        }

        this.conversationsList.innerHTML = conversationsArray.map(([username, conv]) => {
            const hasUnread = conv.unreadCount > 0;
            const isOnline = activeUsernames.has(username);
            const displayName = conv.displayName || username;
            return `
                <div class="conversation-item ${hasUnread ? 'unread' : ''} ${isOnline ? '' : 'offline'}" onclick="window.chatBox.openConversation('${this.escapeHtml(username).replace(/'/g, '&#39;')}')">
                    <div>
                        <div class="conversation-user">${this.escapeHtml(displayName)}${isOnline ? '' : ' <span class="conversation-offline">(offline)</span>'}</div>
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
    
    updateConversation(username, message, isFromMe, displayName = null) {
        const existing = this.conversations.get(username);
        
        // If no display name provided, try to get it from multiple sources
        if (!displayName && !existing?.displayName) {
            // Try active users list first
            const activeUser = this.activeUsersList?.find(u => u.username === username);
            if (activeUser?.display_name) {
                displayName = activeUser.display_name;
            }
            // Fallback to cached display names
            if (!displayName && this.displayNameCache) {
                displayName = this.displayNameCache.get(username);
            }
        }
        
        
        
        this.conversations.set(username, {
            displayName: displayName || existing?.displayName || username,
            lastMessage: message,
            unreadCount: (existing?.unreadCount || 0) + (isFromMe ? 0 : 1),
            timestamp: Date.now()
        });
        
        this.updateUnreadBadge();
        this.renderConversations();
    }
    
    markConversationAsRead(username) {
        const conv = this.conversations.get(username);
        if (conv) {
            conv.unreadCount = 0;
            this.conversations.set(username, conv);
            this.updateUnreadBadge();
        }
    }
    
    updateConversationDisplayNames(activeUsers) {
        // Update display names for existing conversations based on active users list
        if (!activeUsers || !Array.isArray(activeUsers)) return;
        
        
        
        // Create a map of username -> display_name from active users
        const displayNameMap = new Map();
        activeUsers.forEach(user => {
            if (user.display_name) {
                displayNameMap.set(user.username, user.display_name);
            }
        });
        
        
        
        // Store the display name map for future use when conversations are created
        if (!this.displayNameCache) {
            this.displayNameCache = new Map();
        }
        displayNameMap.forEach((displayName, username) => {
            this.displayNameCache.set(username, displayName);
        });
        
        // Update conversations that have matching users
        let updated = false;
        this.conversations.forEach((conv, username) => {
            const displayName = displayNameMap.get(username);
            // Update if we have a display name from activeUsers and it's different from current
            // Also update if current displayName equals username (fallback was used)
            if (displayName && (conv.displayName !== displayName || conv.displayName === username)) {
                
                conv.displayName = displayName;
                this.conversations.set(username, conv);
                updated = true;
            }
        });
        
        
        
        // Re-render conversations panel if any were updated
        if (updated) {
            this.renderConversations();
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
        
        // Check if GIFs are enabled via settings and load API key. Uses the clean
        // route (the legacy api/settings.php was removed in the framework
        // migration and 404s under clean-path routing → the button never showed).
        fetch(`${this.apiUrl}/api/settings`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.settings.gif_enabled === 'true') {
                    // Load GIF provider + API keys from settings
                    this.gifProvider = data.settings.gif_provider || 'giphy';
                    this.giphyApiKey = data.settings.giphy_api_key || '';
                    this.klipyApiKey = data.settings.klipy_api_key || '';

                    // Only show GIF button if the active provider has a key configured
                    if (this.getGifApiKey().trim() !== '') {
                        gifButton.style.display = 'inline-block';
                    }
                }
            })
            .catch(err => console.error('Failed to check GIF settings:', err));

        // Content rating for GIF results (g, pg, pg-13, r)
        this.gifRating = 'pg-13';
        
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
    
    // Returns the API key for the currently selected provider
    getGifApiKey() {
        return (this.gifProvider === 'klipy' ? this.klipyApiKey : this.giphyApiKey) || '';
    }

    // Provider abstraction (see GIF_PROVIDERS): builds the request URL and
    // normalizes the response so the picker code is provider-agnostic.
    getGifProviderConfig() {
        return GIF_PROVIDERS[this.gifProvider] || GIF_PROVIDERS.giphy;
    }

    async searchGifs(query) {
        const gifGrid = document.getElementById('gif-grid');
        const gifLoading = document.getElementById('gif-loading');

        if (!gifGrid) return;

        gifGrid.innerHTML = '';
        gifLoading.style.display = 'block';

        const provider = this.getGifProviderConfig();
        const apiKey = this.getGifApiKey();

        // Check if API key is configured
        if (!apiKey) {
            gifLoading.style.display = 'none';
            gifGrid.innerHTML = `<div style="padding: 20px; text-align: center; color: #ef4444; font-size: 13px;">⚠️ ${provider.label} API key not configured.<br><br>Admin: Go to Settings and add your free API key from <a href="${provider.docsUrl}" target="_blank" style="color: #667eea;">${provider.docsLabel}</a></div>`;
            return;
        }

        try {
            const endpoint = provider.buildEndpoint(query, apiKey, this.gifRating);

            const response = await fetch(endpoint);
            const data = await response.json();

            gifLoading.style.display = 'none';

            const apiError = provider.getError(data);
            if (apiError) {
                throw new Error(apiError);
            }

            const items = provider.getItems(data);
            if (items && items.length > 0) {
                items.forEach(gif => {
                    const previewUrl = provider.getPreviewUrl(gif);
                    if (!previewUrl) return; // skip malformed items

                    const gifItem = document.createElement('div');
                    gifItem.className = 'gif-item';

                    const img = document.createElement('img');
                    img.src = previewUrl;
                    img.alt = provider.getTitle(gif) || 'GIF';
                    img.loading = 'lazy';

                    gifItem.appendChild(img);

                    gifItem.addEventListener('click', () => {
                        const gifUrl = provider.getSendUrl(gif);
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
            const errorMsg = error.message && error.message.toLowerCase().includes('key')
                ? `Invalid API key. Admin: Update your ${provider.label} API key in Settings.`
                : 'Failed to load GIFs. Please try again.';
            gifGrid.innerHTML = `<div style="padding: 20px; text-align: center; color: #ef4444; font-size: 13px;">${errorMsg}</div>`;
        }
    }
    
    insertGif(gifUrl) {
        // Send GIF directly via API to bypass browser emoji autocorrect
        // The input field emoji conversion was breaking the URL

        // Giphy/Klipy URLs carry query params after ".gif" (e.g. ?cid=...) that the
        // GIF-rendering regex doesn't match, so strip them to keep the message clean.
        if (gifUrl) {
            gifUrl = gifUrl.split('?')[0];
        }

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

        fetch('api/send', {
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
        if (this.dndEnabled) return;
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
    
    /**
     * Fetch Open Graph metadata for any URL links inside a rendered message element
     * and inject a preview card. Only the first URL per message is previewed.
     */
    async attachLinkPreviews(element) {
        const links = element.querySelectorAll('a.message-link[data-preview-url]');
        if (links.length === 0) return;

        // Only preview the first link in the message
        const link = links[0];
        const url = link.getAttribute('data-preview-url');
        if (!url) return;

        // Static in-memory cache shared across all messages
        if (!RadioChatBox._previewCache) RadioChatBox._previewCache = {};

        let preview;
        if (url in RadioChatBox._previewCache) {
            preview = RadioChatBox._previewCache[url];
        } else {
            try {
                const resp = await fetch(`/api/link-preview?url=${encodeURIComponent(url)}`);
                if (!resp.ok) {
                    RadioChatBox._previewCache[url] = null;
                    return;
                }
                preview = await resp.json();
                if (preview.error || !preview.title) {
                    RadioChatBox._previewCache[url] = null;
                    return;
                }
                RadioChatBox._previewCache[url] = preview;
            } catch (e) {
                RadioChatBox._previewCache[url] = null;
                return;
            }
        }

        if (!preview || !preview.title) return;

        // Build the preview card
        const card = document.createElement('a');
        card.className = 'link-preview';
        card.href = url;
        card.target = '_blank';
        card.rel = 'noopener noreferrer';

        let imageHtml = '';
        if (preview.image) {
            imageHtml = `<div class="link-preview-image">
                <img src="${this.escapeHtml(preview.image)}" alt="" loading="lazy"
                     onerror="this.closest('.link-preview-image').style.display='none'">
            </div>`;
        }

        const desc = preview.description
            ? `<div class="link-preview-description">${this.escapeHtml(
                preview.description.length > 130
                    ? preview.description.substring(0, 130) + '…'
                    : preview.description
              )}</div>`
            : '';

        card.innerHTML = `
            ${imageHtml}
            <div class="link-preview-body">
                <div class="link-preview-domain">${this.escapeHtml(preview.domain)}</div>
                <div class="link-preview-title">${this.escapeHtml(preview.title)}</div>
                ${desc}
            </div>
        `;

        // Insert after the .message-body element (below the text row, still inside the bubble)
        const msgBody = element.querySelector('.message-body');
        if (msgBody) {
            msgBody.after(card);
        }
    }

    formatMessageText(text) {
        // Escape HTML first
        const escaped = this.escapeHtml(text);
        
        // Detect Tenor/Giphy GIF URLs and render as images
        const gifRegex = /(https?:\/\/(?:media\.tenor\.com|media[0-9]*\.giphy\.com|i\.giphy\.com|[a-z0-9-]+\.klipy\.com)\/[^\s]+\.gif)/gi;
        let formatted = escaped.replace(gifRegex, (url) => {
            // Use a data attribute to mark this as a GIF to prevent Twemoji parsing on the URL
            return `<br><span class="gif-url" data-gif-url="${url}"><img src="${url}" alt="GIF" loading="lazy" class="message-gif" style="max-width: 100%; max-height: 300px; border-radius: 8px; margin-top: 8px;"></span>`;
        });
        
        // Convert regular URLs to clickable links (excluding GIF URLs which are already handled)
        const urlRegex = /(https?:\/\/(?!(?:media\.tenor\.com|media[0-9]*\.giphy\.com|i\.giphy\.com|[a-z0-9-]+\.klipy\.com)\/[^\s]+\.gif)[^\s<]+)/gi;
        formatted = formatted.replace(urlRegex, (url) => {
            return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="message-link" data-preview-url="${url}">${url}</a>`;
        });

        // Highlight ONLY a mention of the CURRENT user (@myusername). Other
        // people's @mentions are left as plain text. Works for any username,
        // including non-ASCII (Greek) and punctuation. Runs against the
        // already-escaped text.
        if (this.username) {
            const escName = this.escapeHtml(this.username);
            const selfRe = new RegExp(`(^|[\\s(])@${this.escapeRegex(escName)}(?=$|[\\s.,!?;:)\\]"'<])`, 'gu');
            formatted = formatted.replace(selfRe, (match, pre) =>
                `${pre}<span class="mention mention-me" data-username="${escName}">@${escName}</span>`);
        }

        return formatted;
    }

    /** Escape a string for safe use inside a RegExp. */
    escapeRegex(str) {
        return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * True if `text` mentions the current user (@username). Uses an explicit
     * boundary lookahead instead of \b so it also works for non-ASCII usernames
     * (Greek, punctuation, etc.), where \b is unreliable.
     */
    messageMentionsMe(text) {
        if (!text || !this.username) return false;
        const re = new RegExp(`(^|[\\s(])@${this.escapeRegex(this.username)}(?=$|[\\s.,!?;:)\\]"'<])`, 'iu');
        return re.test(text);
    }

    // ===================== @mention autocomplete =====================

    /** Detect an @token at the caret and show/hide the autocomplete dropdown. */
    handleMentionInput() {
        if (!this.messageInput) return;
        // Only in public chat (mentions target active public users).
        if (this.privateChat && this.privateChat.active) {
            this.closeMentionDropdown();
            return;
        }

        const value = this.messageInput.value;
        const caret = this.messageInput.selectionStart || 0;
        const before = value.substring(0, caret);
        // Allow Unicode letters/digits in the typed query (Greek, etc.).
        const match = before.match(/(^|[\s(])@([\p{L}\p{N}_]*)$/u);

        if (!match) {
            this.closeMentionDropdown();
            return;
        }

        const query = match[2].toLowerCase();
        this._mentionStart = caret - match[2].length - 1; // index of '@'

        const users = (this.activeUsersList || [])
            .filter(u => u.username && u.username.toLowerCase() !== (this.username || '').toLowerCase())
            .filter(u => {
                const uname = u.username.toLowerCase();
                const dname = (u.display_name || '').toLowerCase();
                return query === '' || uname.startsWith(query) || dname.startsWith(query);
            })
            .slice(0, 8);

        if (users.length === 0) {
            this.closeMentionDropdown();
            return;
        }

        this._mentionMatches = users;
        this._mentionIndex = 0;
        this.renderMentionDropdown();
    }

    renderMentionDropdown() {
        let dropdown = this._mentionDropdown;
        if (!dropdown) {
            dropdown = document.createElement('div');
            dropdown.className = 'mention-dropdown';
            document.body.appendChild(dropdown);
            this._mentionDropdown = dropdown;
        }

        dropdown.innerHTML = this._mentionMatches.map((u, i) => {
            const display = u.display_name || u.username;
            const sub = u.display_name ? `<span class="mention-sub">@${this.escapeHtml(u.username)}</span>` : '';
            return `<div class="mention-item ${i === this._mentionIndex ? 'active' : ''}" data-index="${i}">
                        <span class="mention-name">${this.escapeHtml(display)}</span> ${sub}
                    </div>`;
        }).join('');

        // Position above the input.
        const rect = this.messageInput.getBoundingClientRect();
        dropdown.style.left = `${window.scrollX + rect.left}px`;
        dropdown.style.width = `${Math.min(rect.width, 320)}px`;
        // Show, then place its bottom just above the input.
        dropdown.style.top = '0px';
        dropdown.style.display = 'block';
        const dh = dropdown.offsetHeight;
        dropdown.style.top = `${window.scrollY + rect.top - dh - 4}px`;

        this._mentionActive = true;

        dropdown.querySelectorAll('.mention-item').forEach(item => {
            // mousedown (not click) so it fires before the input's blur handler.
            item.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const idx = parseInt(item.getAttribute('data-index'), 10);
                this.selectMention(idx);
            });
        });
    }

    handleMentionKeydown(e) {
        if (!this._mentionActive || !this._mentionMatches) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this._mentionIndex = (this._mentionIndex + 1) % this._mentionMatches.length;
            this.renderMentionDropdown();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            this._mentionIndex = (this._mentionIndex - 1 + this._mentionMatches.length) % this._mentionMatches.length;
            this.renderMentionDropdown();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            this.selectMention(this._mentionIndex);
        } else if (e.key === 'Escape') {
            this.closeMentionDropdown();
        }
    }

    selectMention(index) {
        const user = this._mentionMatches && this._mentionMatches[index];
        if (!user || this._mentionStart == null) {
            this.closeMentionDropdown();
            return;
        }

        const value = this.messageInput.value;
        const caret = this.messageInput.selectionStart || 0;
        const insert = `@${user.username} `;
        const newValue = value.substring(0, this._mentionStart) + insert + value.substring(caret);
        this.messageInput.value = newValue;

        const newCaret = this._mentionStart + insert.length;
        this.messageInput.setSelectionRange(newCaret, newCaret);
        this.messageInput.focus();

        this.closeMentionDropdown();
    }

    closeMentionDropdown() {
        this._mentionActive = false;
        this._mentionMatches = null;
        this._mentionStart = null;
        if (this._mentionDropdown) {
            this._mentionDropdown.remove();
            this._mentionDropdown = null;
        }
    }

    // ============ Username click → DM popover (public + private mode) ============

    /**
     * Show a popover next to a clicked username offering to start a DM.
     * Only when both public and private chat are enabled (chat_mode 'both')
     * and the name isn't the current user.
     */
    openUserActionsPopover(username, anchorEl) {
        if (!username || !anchorEl) return;
        if (username.toLowerCase() === (this.username || '').toLowerCase()) return;

        // Mention is available whenever public chat exists; DM/Block only when
        // private messaging runs alongside public ('both').
        const allowMention = this.chatMode === 'public' || this.chatMode === 'both';
        const allowPrivate = this.chatMode === 'both';
        if (!allowMention && !allowPrivate) return;

        this.closeUserActionsPopover();

        const pop = document.createElement('div');
        pop.className = 'user-actions-popover';
        let html = `<div class="user-actions-name">${this.escapeHtml(username)}</div>`;
        html += `<button class="user-action-profile">👤 View profile</button>`;
        if (allowMention) html += `<button class="user-action-mention">@ Mention</button>`;
        if (allowPrivate) html += `<button class="user-action-dm">💬 Send private message</button>`;
        if (allowPrivate) html += `<button class="user-action-block">🚫 Block</button>`;
        pop.innerHTML = html;
        document.body.appendChild(pop);

        // Position under the name, clamped to the viewport.
        const rect = anchorEl.getBoundingClientRect();
        const w = pop.offsetWidth || 200;
        const margin = 8;
        let left = window.scrollX + rect.left;
        const maxLeft = window.scrollX + document.documentElement.clientWidth - w - margin;
        if (left > maxLeft) left = maxLeft;
        if (left < window.scrollX + margin) left = window.scrollX + margin;
        pop.style.left = `${left}px`;
        pop.style.top = `${window.scrollY + rect.bottom + 4}px`;

        const profileBtn = pop.querySelector('.user-action-profile');
        if (profileBtn) {
            profileBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.closeUserActionsPopover();
                this.showUserProfile(username);
            });
        }

        const mentionBtn = pop.querySelector('.user-action-mention');
        if (mentionBtn) {
            mentionBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.closeUserActionsPopover();
                this.insertMentionIntoInput(username);
            });
        }

        const dmBtn = pop.querySelector('.user-action-dm');
        if (dmBtn) {
            dmBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.closeUserActionsPopover();
                this.startPrivateChat(username);
            });
        }

        const blockBtn = pop.querySelector('.user-action-block');
        if (blockBtn) {
            blockBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const action = blockBtn.dataset.action === 'unblock' ? 'unblock' : 'block';
                if (action === 'block' && !confirm(`Block ${username}? You will no longer be able to exchange private messages.`)) {
                    return;
                }
                try {
                    await this.setBlock(username, action);
                    // If we're currently in a private chat with this user, refresh it.
                    if (this.privateChat.active && this.privateChat.withUser === username) {
                        await this.loadBlockState(username);
                    }
                } catch (err) {
                    alert(err.message);
                }
                this.closeUserActionsPopover();
            });

            // Resolve the current block state to set the button label.
            this.getBlockState(username).then(state => {
                if (this._userPopover !== pop) return; // popover changed/closed
                blockBtn.dataset.action = state.i_blocked ? 'unblock' : 'block';
                blockBtn.textContent = state.i_blocked ? '✅ Unblock' : '🚫 Block';
                blockBtn.classList.toggle('is-blocked', state.i_blocked);
            });
        }

        this._userPopover = pop;
        setTimeout(() => {
            this._userPopoverOutside = (ev) => {
                if (this._userPopover && !this._userPopover.contains(ev.target)) {
                    this.closeUserActionsPopover();
                }
            };
            document.addEventListener('click', this._userPopoverOutside);
        }, 0);
    }

    closeUserActionsPopover() {
        if (this._userPopover) {
            this._userPopover.remove();
            this._userPopover = null;
        }
        if (this._userPopoverOutside) {
            document.removeEventListener('click', this._userPopoverOutside);
            this._userPopoverOutside = null;
        }
    }

    /** Insert "@username " into the message input at the caret and focus it. */
    insertMentionIntoInput(username) {
        if (!this.messageInput) return;
        const token = `@${username} `;
        const el = this.messageInput;
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        const before = el.value.substring(0, start);
        const after = el.value.substring(end);
        // Add a leading space if the preceding char isn't whitespace.
        const sep = (before && !/\s$/.test(before)) ? ' ' : '';
        el.value = before + sep + token + after;
        const caret = (before + sep + token).length;
        el.focus();
        el.setSelectionRange(caret, caret);
    }

    // ===================== Private conversation photo gallery =====================

    /** All photo attachments in the current private conversation, oldest first. */
    getPrivateGalleryPhotos() {
        return (this.privateChat.messages || [])
            .filter(m => m.attachment && m.attachment.file_path)
            .map(m => ({
                url: m.attachment.file_path,
                from: m.from_display_name || m.from_username,
                created_at: m.created_at
            }));
    }

    /** Show/hide the Gallery button based on whether the conversation has photos. */
    updatePrivateGalleryButton() {
        if (!this.galleryBtn) return;
        const photos = this.getPrivateGalleryPhotos();
        if (photos.length > 0) {
            this.galleryBtn.style.display = 'inline-flex';
            this.galleryBtn.textContent = `🖼️ Gallery (${photos.length})`;
        } else {
            this.galleryBtn.style.display = 'none';
        }
    }

    /** Open a modal with all conversation photos as a grid. */
    openPrivateGallery() {
        const photos = this.getPrivateGalleryPhotos();
        if (photos.length === 0) return;
        this._galleryPhotos = photos;

        this.closePrivateGallery();

        const overlay = document.createElement('div');
        overlay.className = 'gallery-modal-overlay';
        overlay.innerHTML = `
            <div class="gallery-modal">
                <div class="gallery-modal-header">
                    <span>🖼️ Photos with ${this.escapeHtml(this.privateChat.withUser || '')} (${photos.length})</span>
                    <button class="gallery-close" title="Close">✕</button>
                </div>
                <div class="gallery-grid">
                    ${photos.map((p, i) => `
                        <div class="gallery-thumb" data-index="${i}">
                            <img src="${this.escapeHtml(p.url)}" alt="Photo" loading="lazy">
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        this._galleryOverlay = overlay;

        overlay.querySelector('.gallery-close').addEventListener('click', () => this.closePrivateGallery());
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.closePrivateGallery();
        });
        overlay.querySelectorAll('.gallery-thumb').forEach(thumb => {
            thumb.addEventListener('click', () => {
                this.openLightbox(parseInt(thumb.getAttribute('data-index'), 10));
            });
        });
    }

    closePrivateGallery() {
        this.closeLightbox();
        if (this._galleryOverlay) {
            this._galleryOverlay.remove();
            this._galleryOverlay = null;
        }
    }

    /** Open a single image in the lightbox (used for message photos). */
    openImageLightbox(url) {
        if (!url) return;
        this._galleryPhotos = [{ url: url, from: '' }];
        this.openLightbox(0);
    }

    /** Open the full-size lightbox at a given photo index, with prev/next. */
    openLightbox(index) {
        const photos = this._galleryPhotos || [];
        if (!photos.length) return;
        this._lightboxIndex = Math.max(0, Math.min(index, photos.length - 1));

        this.closeLightbox();

        const lb = document.createElement('div');
        lb.className = 'lightbox-overlay';
        lb.innerHTML = `
            <button class="lightbox-close" title="Close">✕</button>
            <button class="lightbox-nav lightbox-prev" title="Previous">‹</button>
            <img class="lightbox-image" src="" alt="Photo">
            <button class="lightbox-nav lightbox-next" title="Next">›</button>
            <div class="lightbox-caption"></div>
        `;
        document.body.appendChild(lb);
        this._lightbox = lb;

        lb.querySelector('.lightbox-close').addEventListener('click', () => this.closeLightbox());
        lb.querySelector('.lightbox-prev').addEventListener('click', (e) => { e.stopPropagation(); this.lightboxStep(-1); });
        lb.querySelector('.lightbox-next').addEventListener('click', (e) => { e.stopPropagation(); this.lightboxStep(1); });
        lb.addEventListener('click', (e) => { if (e.target === lb) this.closeLightbox(); });

        this._lightboxKeyHandler = (e) => {
            if (e.key === 'Escape') this.closeLightbox();
            else if (e.key === 'ArrowLeft') this.lightboxStep(-1);
            else if (e.key === 'ArrowRight') this.lightboxStep(1);
        };
        document.addEventListener('keydown', this._lightboxKeyHandler);

        this.renderLightbox();
    }

    lightboxStep(delta) {
        const photos = this._galleryPhotos || [];
        if (!photos.length) return;
        this._lightboxIndex = (this._lightboxIndex + delta + photos.length) % photos.length;
        this.renderLightbox();
    }

    renderLightbox() {
        if (!this._lightbox) return;
        const photos = this._galleryPhotos || [];
        const p = photos[this._lightboxIndex];
        if (!p) return;
        this._lightbox.querySelector('.lightbox-image').src = p.url;
        const caption = this._lightbox.querySelector('.lightbox-caption');
        caption.textContent = `${p.from || ''} · ${this._lightboxIndex + 1}/${photos.length}`;
        // Hide nav arrows when there is only one photo.
        const single = photos.length <= 1;
        this._lightbox.querySelector('.lightbox-prev').style.display = single ? 'none' : '';
        this._lightbox.querySelector('.lightbox-next').style.display = single ? 'none' : '';
    }

    closeLightbox() {
        if (this._lightbox) {
            this._lightbox.remove();
            this._lightbox = null;
        }
        if (this._lightboxKeyHandler) {
            document.removeEventListener('keydown', this._lightboxKeyHandler);
            this._lightboxKeyHandler = null;
        }
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
