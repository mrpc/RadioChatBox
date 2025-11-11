/**
 * Advertisement Manager for RadioChatBox
 * Handles ad display and auto-refresh
 */

class AdManager {
    constructor() {
        this.enabled = false;
        this.settings = {};
        this.refreshInterval = null;
        this.adContainers = new Map();
    }

    /**
     * Initialize ad manager with settings
     */
    init(settings) {
        if (!settings || !settings.ads) {
            return;
        }

        this.settings = settings.ads;
        this.enabled = this.settings.enabled === true;

        if (!this.enabled) {
            console.log('[Ads] Disabled');
            return;
        }

        console.log('[Ads] Enabled - Refresh:', this.settings.refresh_enabled);

        // Create ad containers
        this.createAdContainers();

        // Display ads
        this.displayAds();

        // Set up auto-refresh if enabled
        if (this.settings.refresh_enabled && this.settings.refresh_interval > 0) {
            this.startAutoRefresh();
        }

        // Track ad views
        this.trackAdViews();
    }

    /**
     * Create ad container elements in the DOM
     */
    createAdContainers() {
        // Main top ad
        if (this.settings.main_top) {
            const container = this.createContainer('ad-main-top', 'main-top');
            const mainContent = document.querySelector('.container') || document.body;
            mainContent.insertBefore(container, mainContent.firstChild);
            this.adContainers.set('main_top', container);
        }

        // Main bottom ad
        if (this.settings.main_bottom) {
            const container = this.createContainer('ad-main-bottom', 'main-bottom');
            const mainContent = document.querySelector('.container') || document.body;
            mainContent.appendChild(container);
            this.adContainers.set('main_bottom', container);
        }

        // Chat sidebar ad
        if (this.settings.chat_sidebar) {
            const container = this.createContainer('ad-chat-sidebar', 'chat-sidebar');
            const chatContainer = document.querySelector('#chat-container, .chat-box');
            if (chatContainer) {
                chatContainer.appendChild(container);
            }
            this.adContainers.set('chat_sidebar', container);
        }
    }

    /**
     * Create a single ad container element
     */
    createContainer(id, placement) {
        const container = document.createElement('div');
        container.id = id;
        container.className = 'ad-container';
        container.setAttribute('data-placement', placement);
        container.style.margin = '10px 0';
        container.style.textAlign = 'center';
        return container;
    }

    /**
     * Display all ads
     */
    displayAds() {
        if (this.settings.main_top) {
            this.renderAd('main_top', this.settings.main_top);
        }

        if (this.settings.main_bottom) {
            this.renderAd('main_bottom', this.settings.main_bottom);
        }

        if (this.settings.chat_sidebar) {
            this.renderAd('chat_sidebar', this.settings.chat_sidebar);
        }
    }

    /**
     * Render ad code into container
     */
    renderAd(placement, adCode) {
        const container = this.adContainers.get(placement);
        if (!container) {
            return;
        }

        // Clear existing content
        container.innerHTML = '';

        // Create wrapper for the ad
        const wrapper = document.createElement('div');
        wrapper.className = 'ad-content';
        wrapper.innerHTML = adCode;

        container.appendChild(wrapper);

        // Execute any scripts in the ad code
        const scripts = wrapper.querySelectorAll('script');
        scripts.forEach(script => {
            const newScript = document.createElement('script');
            if (script.src) {
                newScript.src = script.src;
            } else {
                newScript.textContent = script.textContent;
            }
            script.parentNode.replaceChild(newScript, script);
        });

        // Add click tracking
        this.addClickTracking(container, placement);
    }

    /**
     * Add click tracking to ad container
     */
    addClickTracking(container, placement) {
        container.addEventListener('click', (e) => {
            // Check if click is on an actual ad element (link, button, etc.)
            if (e.target.tagName === 'A' || e.target.closest('a')) {
                if (window.analytics) {
                    window.analytics.trackAdClick(placement);
                }
            }
        });
    }

    /**
     * Track ad views
     */
    trackAdViews() {
        if (!window.analytics) {
            return;
        }

        this.adContainers.forEach((container, placement) => {
            if (container.innerHTML.trim() !== '') {
                window.analytics.trackAdView(placement);
            }
        });
    }

    /**
     * Start auto-refresh timer
     */
    startAutoRefresh() {
        const intervalMs = this.settings.refresh_interval * 1000;

        console.log(`[Ads] Auto-refresh enabled: ${this.settings.refresh_interval}s`);

        this.refreshInterval = setInterval(() => {
            console.log('[Ads] Refreshing ads...');
            this.refreshAds();
        }, intervalMs);
    }

    /**
     * Stop auto-refresh timer
     */
    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
            console.log('[Ads] Auto-refresh stopped');
        }
    }

    /**
     * Refresh all ads
     */
    async refreshAds() {
        try {
            // Fetch latest settings to get updated ad codes
            const response = await fetch('/api/settings.php');
            const data = await response.json();

            if (data.success && data.settings && data.settings.ads) {
                const newSettings = data.settings.ads;

                // Update and re-render ads
                if (newSettings.main_top && newSettings.main_top !== this.settings.main_top) {
                    this.settings.main_top = newSettings.main_top;
                    this.renderAd('main_top', newSettings.main_top);
                }

                if (newSettings.main_bottom && newSettings.main_bottom !== this.settings.main_bottom) {
                    this.settings.main_bottom = newSettings.main_bottom;
                    this.renderAd('main_bottom', newSettings.main_bottom);
                }

                if (newSettings.chat_sidebar && newSettings.chat_sidebar !== this.settings.chat_sidebar) {
                    this.settings.chat_sidebar = newSettings.chat_sidebar;
                    this.renderAd('chat_sidebar', newSettings.chat_sidebar);
                }

                // Track views for refreshed ads
                this.trackAdViews();
            }
        } catch (error) {
            console.error('[Ads] Failed to refresh:', error);
        }
    }

    /**
     * Clean up on page unload
     */
    destroy() {
        this.stopAutoRefresh();
        this.adContainers.clear();
    }
}

// Create global instance
window.adManager = new AdManager();

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    if (window.adManager) {
        window.adManager.destroy();
    }
});
