/**
 * Analytics Helper for RadioChatBox
 * Tracks user interactions and sends events to configured analytics provider
 */

class AnalyticsHelper {
    constructor() {
        this.enabled = false;
        this.provider = '';
        this.trackingId = '';
        this.sessionId = this.generateSessionId();
    }

    /**
     * Initialize analytics with settings from server
     */
    async init(settings) {
        if (!settings || !settings.analytics) {
            return;
        }

        this.enabled = settings.analytics.enabled === true || settings.analytics.enabled === 'true';
        this.provider = settings.analytics.provider || '';
        this.trackingId = settings.analytics.tracking_id || '';

        if (!this.enabled) {
            console.log('[Analytics] Disabled');
            return;
        }
        
        if (!this.trackingId) {
            console.error('[Analytics] No tracking ID configured');
            return;
        }

        console.log(`[Analytics] Enabled - Provider: ${this.provider}, ID: ${this.trackingId}`);
        
        // Load provider-specific scripts
        if (this.provider === 'ga4') {
            this.loadGoogleAnalytics4();
        } else if (this.provider === 'gtm') {
            this.loadGoogleTagManager();
        }
    }
    
    /**
     * Load Google Analytics 4 script
     */
    loadGoogleAnalytics4() {
        // Inject Google Analytics script
        const script = document.createElement('script');
        script.async = true;
        script.src = `https://www.googletagmanager.com/gtag/js?id=${this.trackingId}`;
        document.head.appendChild(script);
        
        // Initialize gtag
        window.dataLayer = window.dataLayer || [];
        window.gtag = function() { dataLayer.push(arguments); };
        gtag('js', new Date());
        gtag('config', this.trackingId);
        
        console.log('[Analytics] Google Analytics 4 loaded:', this.trackingId);
    }
    
    /**
     * Load Google Tag Manager script
     */
    loadGoogleTagManager() {
        const containerId = this.trackingId;
        
        // Inject GTM script
        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer',containerId);
        
        console.log('[Analytics] Google Tag Manager loaded:', containerId);
    }

    /**
     * Generate unique session ID
     */
    generateSessionId() {
        return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    /**
     * Track a custom event
     */
    track(eventName, eventData = {}) {
        if (!this.enabled) {
            return;
        }

        const event = {
            event: eventName,
            timestamp: new Date().toISOString(),
            sessionId: this.sessionId,
            ...eventData
        };

        console.log('[Analytics] Track:', event);

        // Send to Google Analytics 4
        if (this.provider === 'ga4' && typeof gtag !== 'undefined') {
            gtag('event', eventName, eventData);
        }

        // Send to Google Tag Manager
        if (this.provider === 'gtm' && typeof dataLayer !== 'undefined') {
            dataLayer.push({
                event: eventName,
                ...eventData
            });
        }

        // Send to Matomo/Piwik
        if (this.provider === 'matomo' && typeof _paq !== 'undefined') {
            _paq.push(['trackEvent', eventName, JSON.stringify(eventData)]);
        }

        // Custom tracking endpoint (if configured)
        if (this.provider === 'custom') {
            this.sendToCustomEndpoint(event);
        }
    }

    /**
     * Send event to custom tracking endpoint
     */
    async sendToCustomEndpoint(event) {
        try {
            await fetch('/api/track-event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(event)
            });
        } catch (error) {
            console.error('[Analytics] Failed to send event:', error);
        }
    }

    // ========================================================================
    // Chat Event Tracking
    // ========================================================================

    /**
     * Track when user opens private chat
     */
    trackPrivateChatOpen(targetUsername) {
        this.track('private_chat_open', {
            category: 'chat',
            action: 'open_private_chat',
            label: targetUsername,
            target_user: targetUsername
        });
    }

    /**
     * Track when user closes private chat
     */
    trackPrivateChatClose(targetUsername, duration) {
        this.track('private_chat_close', {
            category: 'chat',
            action: 'close_private_chat',
            label: targetUsername,
            target_user: targetUsername,
            duration_seconds: Math.round(duration / 1000)
        });
    }

    /**
     * Track when user sends a message
     */
    trackMessageSent(messageType = 'public') {
        this.track('message_sent', {
            category: 'chat',
            action: 'send_message',
            label: messageType,
            message_type: messageType
        });
    }

    /**
     * Track when user receives a private message
     */
    trackPrivateMessageReceived(fromUsername) {
        this.track('private_message_received', {
            category: 'chat',
            action: 'receive_private_message',
            label: fromUsername,
            from_user: fromUsername
        });
    }

    /**
     * Track photo upload
     */
    trackPhotoUpload(success, fileSize = 0) {
        this.track('photo_upload', {
            category: 'chat',
            action: 'upload_photo',
            label: success ? 'success' : 'failed',
            success: success,
            file_size_kb: Math.round(fileSize / 1024)
        });
    }

    /**
     * Track user registration
     */
    trackUserRegistration(username) {
        this.track('user_registration', {
            category: 'user',
            action: 'register',
            label: username
        });
    }

    /**
     * Track profile update
     */
    trackProfileUpdate() {
        this.track('profile_update', {
            category: 'user',
            action: 'update_profile'
        });
    }

    /**
     * Track ad view
     */
    trackAdView(placement) {
        this.track('ad_view', {
            category: 'advertisement',
            action: 'view',
            label: placement,
            placement: placement
        });
    }

    /**
     * Track ad click
     */
    trackAdClick(placement) {
        this.track('ad_click', {
            category: 'advertisement',
            action: 'click',
            label: placement,
            placement: placement
        });
    }

    /**
     * Track session start
     */
    trackSessionStart() {
        this.track('session_start', {
            category: 'engagement',
            action: 'start_session',
            session_id: this.sessionId
        });
    }

    /**
     * Track session end
     */
    trackSessionEnd(duration) {
        this.track('session_end', {
            category: 'engagement',
            action: 'end_session',
            session_id: this.sessionId,
            duration_seconds: Math.round(duration / 1000)
        });
    }

    /**
     * Track page view
     */
    trackPageView(page) {
        this.track('page_view', {
            category: 'engagement',
            action: 'view_page',
            label: page,
            page: page
        });
    }
}

// Create global instance
window.analytics = new AnalyticsHelper();
