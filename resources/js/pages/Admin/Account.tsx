import { Link, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { QRCodeSVG } from 'qrcode.react';
import { KeyRound, ShieldCheck } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { PasswordMeter, passwordChecks } from '@/components/PasswordStrength';
import { Button, Card, confirmDialog, CopyButton, Empty, Field, Input, PasswordInput } from '@/components/ui';
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
            <PasswordInput autoComplete="current-password" required value={password} onChange={(ev) => setPassword(ev.target.value)} />
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

function initials(name: string) {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}

/** Signs out every other device on success; the server checks the same rules as the list below. */
function PasswordCard({ user }: { user: any }) {
    const form = useForm({ password: '', password_confirmation: '' });
    const e = form.errors as any;
    const [open, setOpen] = useState(!!user.must_change_password);
    const { ready } = passwordChecks(form.data.password, { confirm: form.data.password_confirmation });

    function submit(ev: FormEvent) {
        ev.preventDefault();
        form.post('/admin/account/password', {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setOpen(false); },
        });
    }

    return (
        <Card title="Your account">
            <div className="flex items-center gap-3">
                <span aria-hidden className="grid size-11 shrink-0 place-items-center rounded-full bg-chocolate text-sm font-semibold text-ivory">{initials(user.name)}</span>
                <div className="min-w-0 text-sm">
                    <p className="truncate font-medium">{user.name}</p>
                    <p className="truncate text-muted">{user.email}</p>
                </div>
                <div className="ml-auto flex flex-wrap justify-end gap-1">
                    {user.roles.map((r: string) => <span key={r} className="rounded-full bg-beige px-2 py-0.5 text-xs font-medium">{r}</span>)}
                </div>
            </div>

            <div className="mt-4 rounded-lg border border-line/80">
                <div className="flex items-center gap-3 px-4 py-3">
                    <KeyRound size={18} className="shrink-0 text-muted" aria-hidden />
                    <div className="min-w-0 flex-1 text-sm">
                        <p className="font-medium">Password</p>
                        <p className="text-xs text-muted">{user.must_change_password ? 'You are still using the starting password. Choose your own to continue.' : 'Changing it signs you out on every other device.'}</p>
                    </div>
                    {!open && <Button small onClick={() => setOpen(true)}>Change</Button>}
                </div>

                {open && (
                    <form onSubmit={submit} className="space-y-3 border-t border-line/80 px-4 py-4">
                        <Field label="New password" error={e.password}>
                            <PasswordInput autoComplete="new-password" required autoFocus maxLength={72} value={form.data.password} onChange={(ev) => form.setData('password', ev.target.value)} />
                        </Field>
                        <Field label="Confirm new password" error={e.password_confirmation}>
                            <PasswordInput autoComplete="new-password" required maxLength={72} value={form.data.password_confirmation} onChange={(ev) => form.setData('password_confirmation', ev.target.value)} />
                        </Field>
                        <PasswordMeter password={form.data.password} confirm={form.data.password_confirmation} />
                        <div className="flex flex-wrap items-center gap-2 pt-1">
                            <Button type="submit" variant="primary" disabled={form.processing || !ready}>
                                <ShieldCheck size={16} aria-hidden /> {form.processing ? 'Saving…' : 'Update password'}
                            </Button>
                            {!user.must_change_password && <Button variant="ghost" onClick={() => { form.reset(); form.clearErrors(); setOpen(false); }}>Cancel</Button>}
                        </div>
                    </form>
                )}
            </div>
        </Card>
    );
}

export default function Account({ notifications, twoFactor, notificationPrefs }: any) {
    const { auth } = usePage<any>().props as any;

    return (
        <AdminLayout title="Account">
            <div className="grid gap-4 lg:grid-cols-2">
                <PasswordCard user={auth.user} />
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
