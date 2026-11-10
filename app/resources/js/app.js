// WebAuthn (passkeys) helpers used by the passkey login button and the security settings page.
import { startAuthentication, startRegistration } from '@simplewebauthn/browser';
import './pwa';

window.startAuthentication = startAuthentication;
window.startRegistration = startRegistration;
