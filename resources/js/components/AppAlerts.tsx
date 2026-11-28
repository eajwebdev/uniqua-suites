import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { BellRing, CheckCircle2, Download, Monitor, Share, Smartphone, X } from 'lucide-react';
import { useState } from 'react';
import { Button, Card, cn } from '@/components/ui';
import { dateTime } from '@/lib/format';
import { install, Platform, turnOffAlerts, turnOnAlerts, useAppState } from '@/lib/pwa';

const DEVICE_WORD: Record<Platform, string> = { ios: 'iPhone or iPad', android: 'phone', desktop: 'computer' };

function useKey(): string | null {
    return (usePage<any>().props as any).push?.key ?? null;
}

/** iPhone and iPad have no install button: the person adds the app from Safari's Share menu. */
function IosSteps() {
    return (
        <ol className="list-decimal space-y-1 pl-5 text-sm">
            <li>Open this page in <strong>Safari</strong>.</li>
            <li>Tap <Share size={14} className="inline -translate-y-0.5" aria-label="Share" /> <strong>Share</strong>, then <strong>Add to Home Screen</strong>.</li>
            <li>Open <strong>Uniqa Staff</strong> from your Home Screen and sign in.</li>
            <li>Come back here and tap <strong>Turn on alerts</strong>.</li>
        </ol>
    );
}

/** Account: install the app, turn alerts on or off for this device, and see every device that gets them. */
export function AppAlertsCard({ devices }: { devices: { id: number; device: string; since: string; last_sent_at: string | null; this: boolean }[] }) {
    const key = useKey();
    const app = useAppState(key);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
    const needsInstallFirst = app.platform === 'ios' && !app.installed;

    const run = async (work: () => Promise<string | void>) => {
        setBusy(true);
        setMessage(null);
        try {
            const text = await work();
            if (text) setMessage({ tone: 'ok', text });
            router.reload({ only: ['devices'] });
        } catch {
            setMessage({ tone: 'error', text: 'That did not work. Check your connection and try again.' });
        } finally {
            setBusy(false);
        }
    };

    const turnOn = () => run(async () => {
        // Refused: the card switches to how to allow them in settings.
        const permission = await turnOnAlerts(key!);
        return permission === 'granted' ? 'Alerts are on for this device.' : undefined;
    });

    return (
        <Card title={<><BellRing size={17} aria-hidden /> Staff app and alerts</>}>
            <div id="app" className="scroll-mt-20 space-y-4 text-sm">
                <section className="rounded-lg border border-line/80 p-4">
                    <h3 className="mb-1 flex items-center gap-2 font-medium">{app.platform === 'desktop' ? <Monitor size={16} aria-hidden /> : <Smartphone size={16} aria-hidden />} Install on this {DEVICE_WORD[app.platform]}</h3>
                    {app.installed ? (
                        <p className="flex items-center gap-1.5 text-emerald-800"><CheckCircle2 size={15} aria-hidden /> Installed. You are using the app now.</p>
                    ) : app.canInstall ? (
                        <>
                            <p className="mb-3 text-muted">Opens in its own window with the UNIQA icon, like any other app.</p>
                            <Button variant="primary" onClick={() => install()}><Download size={15} aria-hidden /> Install app</Button>
                        </>
                    ) : app.platform === 'ios' ? (
                        <IosSteps />
                    ) : (
                        <p className="text-muted">In your browser's menu, choose <strong>Install app</strong> or <strong>Add to Home screen</strong>. If neither is there, open this page in Chrome or Edge. Alerts below still work without installing.</p>
                    )}
                </section>

                <section className="rounded-lg border border-line/80 p-4">
                    <h3 className="mb-1 flex items-center gap-2 font-medium"><BellRing size={16} aria-hidden /> Alerts on this device</h3>
                    {!app.configured ? (
                        <p className="text-muted">Alerts are not set up on this server yet. The Administrator runs <code className="rounded bg-ivory px-1">php artisan uniqa:push-keys</code> once.</p>
                    ) : needsInstallFirst ? (
                        <p className="text-muted">On iPhone and iPad, alerts work only in the installed app (iOS 16.4 or later). Install it first, using the steps above.</p>
                    ) : !app.supported ? (
                        <p className="text-muted">This browser cannot show alerts. Use Chrome, Edge, Firefox or Safari, on a secure (https) address.</p>
                    ) : app.permission === 'denied' ? (
                        <p className="text-red-900">Alerts are blocked for this site. Allow notifications for it in your browser or {app.platform === 'desktop' ? 'system' : 'phone'} settings, then come back here.</p>
                    ) : app.on ? (
                        <>
                            <p className="mb-3 flex items-center gap-1.5 text-emerald-800"><CheckCircle2 size={15} aria-hidden /> On. Alerts arrive here even when the app is closed.</p>
                            <div className="flex flex-wrap gap-2">
                                <Button disabled={busy} onClick={() => run(async () => { await axios.post('/admin/push/test'); return 'Test alert sent. It should arrive in a few seconds.'; })}>Send a test alert</Button>
                                <Button variant="ghost" disabled={busy} onClick={() => run(async () => { await turnOffAlerts(); return 'Alerts are off for this device.'; })}>Turn off</Button>
                            </div>
                        </>
                    ) : (
                        <>
                            <p className="mb-3 text-muted">Get the alerts you see in the bell on this {DEVICE_WORD[app.platform]} too, even when the app is closed. Your browser will ask to allow notifications.</p>
                            <Button variant="primary" disabled={busy} onClick={turnOn}><BellRing size={15} aria-hidden /> Turn on alerts</Button>
                        </>
                    )}
                    {message && <p role="status" className={cn('mt-3', message.tone === 'ok' ? 'text-emerald-800' : 'text-red-900')}>{message.text}</p>}
                </section>

                {devices.length > 0 && (
                    <section>
                        <h3 className="mb-1 font-medium">Devices with your alerts</h3>
                        <ul className="divide-y divide-line">
                            {devices.map((d) => (
                                <li key={d.id} className="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                                    <span>{d.device}{d.this && <span className="ml-2 rounded-full bg-beige px-2 py-0.5 text-xs font-medium">This device</span>}</span>
                                    <span className="text-xs text-muted">{d.last_sent_at ? `Last alert ${dateTime(d.last_sent_at)}` : `Since ${dateTime(d.since)}`}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-1 text-xs text-muted">Signing out on a device stops its alerts. Which alerts you get follows the choices under What to notify me about.</p>
                    </section>
                )}
            </div>
        </Card>
    );
}

const DISMISS_KEY = 'uniqa.app-prompt.dismissed';
const DISMISS_DAYS = 14;

function dismissedRecently(): boolean {
    try {
        return Date.now() - Number(localStorage.getItem(DISMISS_KEY) ?? 0) < DISMISS_DAYS * 86400000;
    } catch {
        return false;
    }
}

/** A quiet offer at the top of admin pages, until the device is set up or the person says not now. */
export function AppAlertsPrompt() {
    const key = useKey();
    const app = useAppState(key);
    const [hidden, setHidden] = useState(dismissedRecently);
    const [busy, setBusy] = useState(false);

    const canTurnOn = app.configured && app.supported && app.permission === 'default' && app.on === false && !(app.platform === 'ios' && !app.installed);
    const canInstall = !app.installed && (app.canInstall || app.platform === 'ios');
    if (hidden || app.on === null || (!canTurnOn && !canInstall)) return null;

    const dismiss = () => {
        setHidden(true);
        try {
            localStorage.setItem(DISMISS_KEY, String(Date.now()));
        } catch {
            // Without storage it simply shows again next time.
        }
    };

    return (
        <div role="region" aria-label="Staff app" className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-gold/40 bg-ivory px-4 py-3 text-sm">
            <BellRing size={17} className="shrink-0 text-gold-dark" aria-hidden />
            <p className="min-w-0 flex-1">
                <strong className="font-medium">Get alerts on this {DEVICE_WORD[app.platform]}.</strong>{' '}
                <span className="text-muted">New booking requests, tasks and approvals, even when the app is closed.</span>
            </p>
            <div className="flex flex-wrap items-center gap-2">
                {canTurnOn && <Button small variant="primary" disabled={busy} onClick={async () => { setBusy(true); try { await turnOnAlerts(key!); } finally { setBusy(false); } }}>Turn on alerts</Button>}
                {canInstall && app.canInstall && <Button small variant={canTurnOn ? 'secondary' : 'primary'} onClick={() => install()}><Download size={14} aria-hidden /> Install app</Button>}
                {canInstall && !app.canInstall && <Link href="/admin/account#app" className="font-medium text-bronze underline pointer-coarse:py-2">How to install</Link>}
                <button type="button" aria-label="Not now" onClick={dismiss} className="flex size-8 items-center justify-center rounded text-muted hover:bg-beige pointer-coarse:size-11"><X size={15} aria-hidden /></button>
            </div>
        </div>
    );
}
