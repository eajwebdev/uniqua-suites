import { Link, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { QRCodeSVG } from 'qrcode.react';
import { FormEvent, useState } from 'react';
import { Button, Card, confirmDialog, CopyButton, Empty, Field, Input } from '@/components/ui';
import { dateTime } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

function firstError(error: any) {
    const errors = error?.response?.data?.errors;
    if (errors) return Object.values(errors).flat()[0] as string;
    return error?.response?.status === 429 ? 'Too many attempts. Wait a minute and try again.' : 'Something went wrong. Please try again.';
}

function RecoveryCodes({ codes }: { codes: string[] }) {
    return (
        <div className="rounded-md bg-beige p-3 text-sm">
            <p className="mb-2 font-medium">Save these recovery codes now. They will not be shown again.</p>
            <ul className="mb-2 grid gap-1 font-mono text-xs sm:grid-cols-2">{codes.map((c) => <li key={c}>{c}</li>)}</ul>
            <CopyButton text={codes.join('\n')} label="Copy codes" />
        </div>
    );
}

/** Secrets arrive by JSON and stay in component state, so they are not kept in Inertia page history. */
function TwoFactorCard({ state }: any) {
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [enrolment, setEnrolment] = useState<{ secret: string; uri: string } | null>(null);
    const [codes, setCodes] = useState<string[] | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    async function call(url: string, body: object, done: (data: any) => void) {
        setBusy(true);
        setError('');
        try {
            done((await axios.post(url, body, { headers: { Accept: 'application/json' } })).data);
        } catch (err) {
            setError(firstError(err));
        } finally {
            setBusy(false);
        }
    }

    function start(ev: FormEvent) {
        ev.preventDefault();
        call('/admin/account/two-factor/setup', { current_password: password }, (data) => { setEnrolment(data); setPassword(''); });
    }

    function confirm(ev: FormEvent) {
        ev.preventDefault();
        call('/admin/account/two-factor/confirm', { code }, (data) => {
            setCodes(data.recovery_codes);
            setEnrolment(null);
            setCode('');
            router.reload({ only: ['twoFactor'] });
        });
    }

    function regenerate() {
        call('/admin/account/two-factor/recovery-codes', { current_password: password }, (data) => {
            setCodes(data.recovery_codes);
            setPassword('');
            router.reload({ only: ['twoFactor'] });
        });
    }

    async function disable() {
        if (!(await confirmDialog({ title: 'Turn off two-factor sign-in?', message: 'Your account will be protected by its password alone.', confirmLabel: 'Turn off', danger: true }))) return;
        setError('');
        router.post('/admin/account/two-factor/disable', { current_password: password }, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors)[0] as string),
            onSuccess: () => { setPassword(''); setCodes(null); },
        });
    }

    const passwordField = (
        <Field label="Current password">
            <Input type="password" autoComplete="current-password" required value={password} onChange={(ev) => setPassword(ev.target.value)} />
        </Field>
    );

    return (
        <Card title="Two-factor sign-in">
            {codes && <div className="mb-3"><RecoveryCodes codes={codes} /></div>}
            {error && <p role="alert" className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-900">{error}</p>}

            {state.enabled ? (
                <div className="space-y-3">
                    <p className="text-sm">On since {dateTime(state.confirmed_at)}. {state.recovery_remaining} recovery code(s) left.</p>
                    {passwordField}
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={regenerate} disabled={busy || !password}>New recovery codes</Button>
                        <Button variant="danger" onClick={disable} disabled={busy || !password}>Turn off</Button>
                    </div>
                </div>
            ) : enrolment ? (
                <form onSubmit={confirm} className="space-y-3">
                    <p className="text-sm">Scan this with an authenticator app, then enter the code it shows.</p>
                    <div className="inline-block rounded-md border border-line bg-white p-3"><QRCodeSVG value={enrolment.uri} size={176} title="Two-factor setup QR code" /></div>
                    <p className="text-xs text-muted">Can't scan it? Enter this key by hand: <span className="font-mono break-all">{enrolment.secret}</span></p>
                    <Field label="Code from the app">
                        <Input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9 ]*" maxLength={7} required value={code} onChange={(ev) => setCode(ev.target.value)} />
                    </Field>
                    <Button type="submit" variant="primary" disabled={busy}>Turn on</Button>
                </form>
            ) : (
                <form onSubmit={start} className="space-y-3">
                    <p className="text-sm">{state.required ? 'Required for your role.' : 'Recommended.'} Sign-in will also ask for a code from an authenticator app on your phone.</p>
                    {passwordField}
                    <Button type="submit" variant="primary" disabled={busy}>Set up</Button>
                </form>
            )}
        </Card>
    );
}

function NotificationPrefs({ prefs }: any) {
    const form = useForm<{ muted: string[] }>({ muted: prefs.muted });
    const toggle = (key: string, on: boolean) => form.setData('muted', on ? form.data.muted.filter((m) => m !== key) : [...form.data.muted, key]);

    return (
        <Card title="What to notify me about">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/admin/account/notifications', { preserveScroll: true }); }} className="space-y-2">
                {Object.entries(prefs.categories).map(([key, label]: any) => (
                    <label key={key} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!form.data.muted.includes(key)} onChange={(ev) => toggle(key, ev.target.checked)} /> {label}</label>
                ))}
                <p className="text-xs text-muted">These appear only inside this system, so nothing interrupts you off shift. Work assigned directly to you always shows.</p>
                <Button type="submit" disabled={form.processing}>Save choices</Button>
            </form>
        </Card>
    );
}

export default function Account({ notifications, twoFactor, notificationPrefs }: any) {
    const { auth } = usePage<any>().props as any;
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const e = form.errors as any;

    return (
        <AdminLayout title="Account">
            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Your account">
                    <p className="mb-3 text-sm">{auth.user.name} · {auth.user.email}<br /><span className="text-muted">{auth.user.roles.join(', ')}</span></p>
                    <form onSubmit={(ev) => { ev.preventDefault(); form.post('/admin/account/password', { preserveScroll: true, onSuccess: () => form.reset() }); }} className="space-y-3">
                        <Field label="Current password" error={e.current_password}><Input type="password" autoComplete="current-password" value={form.data.current_password} onChange={(ev) => form.setData('current_password', ev.target.value)} /></Field>
                        <Field label="New password" error={e.password} help="At least 10 characters."><Input type="password" autoComplete="new-password" value={form.data.password} onChange={(ev) => form.setData('password', ev.target.value)} /></Field>
                        <Field label="Confirm new password"><Input type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(ev) => form.setData('password_confirmation', ev.target.value)} /></Field>
                        <Button type="submit" variant="primary" disabled={form.processing}>Change password</Button>
                    </form>
                </Card>
                <TwoFactorCard state={twoFactor} />
                <NotificationPrefs prefs={notificationPrefs} />
                <section id="notifications" className="scroll-mt-20">
                    <Card title="Notifications" action={auth.user.unread > 0 ? <Button small onClick={() => router.post('/admin/notifications/read', {}, { preserveScroll: true })}>Mark all read</Button> : undefined}>
                        {notifications.length === 0 ? <Empty>No notifications.</Empty> : (
                            <ul className="divide-y divide-line text-sm">
                                {notifications.map((n: any) => (
                                    <li key={n.id} className="py-2">
                                        {n.url ? <Link href={n.url} className={n.read ? 'underline' : 'font-medium underline'}>{n.title}</Link> : <span className={n.read ? '' : 'font-medium'}>{n.title}</span>}
                                        <span className="block text-xs text-muted">{dateTime(n.at)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </section>
            </div>
        </AdminLayout>
    );
}
