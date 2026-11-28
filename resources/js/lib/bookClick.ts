/**
 * Tells front desk that a guest pressed Book Now (a suite) or Inquire (a camera), so their Messenger
 * chat is answered quickly. Sent as the page hands over to Messenger: a beacon still arrives after
 * the page goes, and nothing here can delay or stop the guest.
 */
export function reportBookClick(click: { kind: 'suite' | 'camera'; slug: string; from: string; to: string; pickup_time?: string; return_time?: string; guests?: number | string }) {
    try {
        const body = JSON.stringify(Object.fromEntries(Object.entries(click).filter(([, v]) => v !== undefined && v !== '')));
        if (navigator.sendBeacon?.('/book-click', new Blob([body], { type: 'application/json' }))) return;
        fetch('/book-click', { method: 'POST', body, keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json' } }).catch(() => undefined);
    } catch {
        // Only a heads-up for staff: the guest's chat opens either way.
    }
}
