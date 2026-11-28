import axios from 'axios';
import { useEffect, useState } from 'react';

/**
 * The staff app: installing it, and alerts on this device.
 *
 * Where alerts work: Android (Chrome, Edge, Samsung Internet, Firefox), Windows, Mac, Linux and
 * Chromebook browsers, and iPhone or iPad from iOS 16.4 once the app is added to the Home Screen.
 * Everything needs https (or localhost while testing).
 */

type InstallPrompt = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

let installPrompt: InstallPrompt | null = null;
const listeners = new Set<() => void>();
const changed = () => listeners.forEach((listener) => listener());

// The browser offers installing once, early. It is kept for the Install button.
window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event as InstallPrompt;
    changed();
});
window.addEventListener('appinstalled', () => {
    installPrompt = null;
    changed();
});

export type Platform = 'ios' | 'android' | 'desktop';

export function platform(): Platform {
    const agent = navigator.userAgent;
    // iPadOS reports itself as a Mac, but a Mac has no touch screen.
    if (/iPhone|iPad|iPod/.test(agent) || (agent.includes('Macintosh') && navigator.maxTouchPoints > 1)) return 'ios';
    if (/Android/.test(agent)) return 'android';

    return 'desktop';
}

export const isInstalled = () => window.matchMedia('(display-mode: standalone)').matches || (navigator as any).standalone === true;

export const alertsSupported = () => window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

export async function install(): Promise<boolean> {
    if (!installPrompt) return false;
    await installPrompt.prompt();
    const { outcome } = await installPrompt.userChoice;
    installPrompt = null;
    changed();

    return outcome === 'accepted';
}

let registration: Promise<ServiceWorkerRegistration> | null = null;

export function registerWorker(): Promise<ServiceWorkerRegistration> | null {
    if (!window.isSecureContext || !('serviceWorker' in navigator)) return null;
    registration ??= navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(() => navigator.serviceWorker.ready);

    return registration;
}

function keyBytes(base64url: string): Uint8Array {
    const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
}

function sameKey(subscription: PushSubscription, key: string): boolean {
    const current = subscription.options?.applicationServerKey;
    if (!current) return true; // Older browsers do not say; the server tells us if it fails.
    const a = new Uint8Array(current);
    const b = keyBytes(key);

    return a.length === b.length && a.every((byte, i) => byte === b[i]);
}

async function subscription(key: string): Promise<PushSubscription> {
    const worker = await registerWorker()!;
    let current = await worker.pushManager.getSubscription();
    // New keys on the server make an old sign-up useless.
    if (current && !sameKey(current, key)) {
        await current.unsubscribe();
        current = null;
    }

    return current ?? worker.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) as BufferSource });
}

async function save(sub: PushSubscription) {
    const json = sub.toJSON();
    const encodings: string[] = (PushManager as any).supportedContentEncodings ?? ['aes128gcm'];
    await axios.post('/admin/push/subscribe', { endpoint: json.endpoint, keys: json.keys, content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' });
}

/** Must run from a tap: iPhones and some browsers only ask for permission then. */
export async function turnOnAlerts(key: string): Promise<NotificationPermission> {
    const permission = await Notification.requestPermission();
    if (permission === 'granted') await save(await subscription(key));
    changed();

    return permission;
}

export async function turnOffAlerts(): Promise<void> {
    const worker = await registerWorker();
    const current = await worker?.pushManager.getSubscription();
    if (current) {
        await axios.post('/admin/push/unsubscribe', { endpoint: current.endpoint });
        await current.unsubscribe();
    }
    changed();
}

export async function alertsAreOn(): Promise<boolean> {
    if (!alertsSupported() || Notification.permission !== 'granted') return false;
    const worker = await registerWorker();

    return !!(await worker?.pushManager.getSubscription());
}

let syncedFor: number | null = null;

/**
 * Once per sign-in: a device that already allowed alerts is (re)attached to whoever is signed in,
 * so signing out and in again, or new server keys, never silently stop them.
 */
export async function syncAlerts(key: string | null | undefined, userId: number) {
    if (!key || syncedFor === userId || !alertsSupported() || Notification.permission !== 'granted') return;
    syncedFor = userId;
    try {
        await save(await subscription(key));
        changed();
    } catch {
        syncedFor = null;
    }
}

/** Signing out detaches this device on the server; the next sign-in attaches it again. */
export const forgetSync = () => {
    syncedFor = null;
};

/** What the Install and alert controls need to know, kept current. */
export function useAppState(key: string | null | undefined) {
    const read = () => ({
        installed: isInstalled(),
        canInstall: !!installPrompt,
        platform: platform(),
        supported: alertsSupported(),
        permission: ('Notification' in window ? Notification.permission : 'denied') as NotificationPermission,
        configured: !!key,
    });
    const [state, setState] = useState(read);
    const [on, setOn] = useState<boolean | null>(null);

    useEffect(() => {
        const refresh = () => {
            setState(read());
            alertsAreOn().then(setOn).catch(() => setOn(false));
        };
        refresh();
        listeners.add(refresh);
        const standalone = window.matchMedia('(display-mode: standalone)');
        standalone.addEventListener?.('change', refresh);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            listeners.delete(refresh);
            standalone.removeEventListener?.('change', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [key]);

    return { ...state, on };
}
