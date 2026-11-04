import { Link, router, useForm } from '@inertiajs/react';
import { BedDouble, Building2, Crown, Loader2, Lock, Mail, ShieldCheck, Users } from 'lucide-react';
import { useState } from 'react';
import { Button, Field, Input, PasswordInput } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

const ROLE_ICONS: Record<string, any> = {
    administrator: ShieldCheck,
    owner: Crown,
    'operations-manager': Building2,
    'booking-reception': BedDouble,
    staff: Users,
};

export default function Login({ demoAccounts = [] }: { demoAccounts?: { key: string; label: string }[] }) {
    const form = useForm({ email: '', password: '', remember: false });
    const e = form.errors as any;
    const [capsLock, setCapsLock] = useState(false);

    return (
        <AuthLayout title="Welcome back" subtitle="Sign in to manage your suites.">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/login', { onFinish: () => form.reset('password') }); }} className="space-y-4">
                <Field label="Email" error={e.email}>
                    <span className="relative block">
                        <Mail size={16} strokeWidth={1.75} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" aria-hidden />
                        <Input type="email" autoComplete="username" required autoFocus className="min-h-11 w-full pl-9" placeholder="you@example.com" value={form.data.email} onChange={(ev) => form.setData('email', ev.target.value)} />
                    </span>
                </Field>
                <Field label="Password" error={e.password}>
                    <span className="relative block">
                        <Lock size={16} strokeWidth={1.75} className="pointer-events-none absolute top-1/2 left-3 z-10 -translate-y-1/2 text-muted" aria-hidden />
                        <PasswordInput autoComplete="current-password" required className="min-h-11 pl-9" value={form.data.password} onChange={(ev) => form.setData('password', ev.target.value)} onKeyUp={(ev) => setCapsLock(ev.getModifierState('CapsLock'))} />
                    </span>
                    {capsLock && <span role="status" className="mt-1 block text-xs text-amber-900">Caps Lock is on.</span>}
                </Field>
                <div className="flex items-center justify-between gap-3 text-sm">
                    <label className="flex items-center gap-2 pointer-coarse:min-h-11"><input type="checkbox" checked={form.data.remember} onChange={(ev) => form.setData('remember', ev.target.checked)} /> Keep me signed in</label>
                    <Link href="/forgot-password" className="font-medium text-bronze hover:underline pointer-coarse:py-3">Forgot password?</Link>
                </div>
                <Button type="submit" variant="primary" className="min-h-11 w-full" disabled={form.processing}>
                    {form.processing && <Loader2 size={16} className="animate-spin" aria-hidden />}
                    {form.processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>

            {demoAccounts.length > 0 && (
                <section aria-labelledby="demo-heading" className="mt-6 border-t border-line pt-5">
                    <h2 id="demo-heading" className="text-sm font-semibold">Demo mode: sign in with one click</h2>
                    <p className="mt-0.5 mb-3 text-xs text-muted">Choose a role to see what that person sees.</p>
                    <div className="grid grid-cols-2 gap-2">
                        {demoAccounts.map((account) => {
                            const Icon = ROLE_ICONS[account.key] ?? Users;

                            return (
                                <button key={account.key} type="button" onClick={() => router.post(`/login/demo/${account.key}`)} className="flex min-h-11 items-center gap-2 rounded-lg border border-line bg-white px-3 py-2 text-left text-xs font-medium shadow-xs transition-colors hover:border-gold hover:bg-beige">
                                    <Icon size={15} strokeWidth={1.75} className="shrink-0 text-bronze" aria-hidden /> {account.label}
                                </button>
                            );
                        })}
                    </div>
                </section>
            )}
        </AuthLayout>
    );
}
