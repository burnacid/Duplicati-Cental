// WebAuthn (passkeys) helpers used by the passkey login button and the security settings page.
import { startAuthentication, startRegistration } from '@simplewebauthn/browser';

window.startAuthentication = startAuthentication;
window.startRegistration = startRegistration;

// Progressive Web App: lets the dashboard be installed and shows an offline page when the server is unreachable.
// Browsers only allow service workers on HTTPS (or localhost).
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
