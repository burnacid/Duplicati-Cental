// Progressive Web App helpers: service worker, "Install app" button and the failing-jobs badge.

// Browsers only allow service workers on HTTPS (or localhost).
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
// iOS has no install prompt: Safari users add the app via Share → Add to Home Screen.
const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

let deferredPrompt = null;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    if (window.Alpine) {
        window.Alpine.store('pwa').available = true;
    }
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    if (window.Alpine) {
        window.Alpine.store('pwa').installed = true;
    }
});

document.addEventListener('alpine:init', () => {
    window.Alpine.store('pwa', {
        available: deferredPrompt !== null,
        installed: standalone(),
        isIos,

        get canInstall() {
            return !this.installed && (this.available || this.isIos);
        },

        async install() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                deferredPrompt = null;
                this.available = false;
                if (outcome === 'accepted') {
                    this.installed = true;
                }
            } else if (this.isIos) {
                window.dispatchEvent(new CustomEvent('modal-show', { detail: { name: 'ios-install' } }));
            }
        },
    });
});

// Called by the FailingJobsBadge Livewire component.
window.dcSetFailingJobs = (count) => {
    const title = document.title.replace(/^\(\d+\)\s*/, '');
    document.title = count > 0 ? `(${count}) ${title}` : title;

    if ('setAppBadge' in navigator) {
        (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
    }
};
