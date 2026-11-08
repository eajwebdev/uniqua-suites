import { useForm } from '@inertiajs/react';
import { PasswordMeter, passwordChecks } from '@/components/PasswordStrength';
import { Button, Field, Input, PasswordInput } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function Reset({ token, email }: { token: string; email?: string }) {
    const form = useForm({ token, email: email ?? '', password: '', password_confirmation: '' });
    const e = form.errors as any;
    const { ready } = passwordChecks(form.data.password, { confirm: form.data.password_confirmation });

    return (
        <AuthLayout title="Set a new password">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/reset-password'); }} className="space-y-3">
                <Field label="Email" error={e.email}><Input type="email" required value={form.data.email} onChange={(ev) => form.setData('email', ev.target.value)} /></Field>
                <Field label="New password" error={e.password}><PasswordInput autoComplete="new-password" required maxLength={72} value={form.data.password} onChange={(ev) => form.setData('password', ev.target.value)} /></Field>
                <Field label="Confirm password"><PasswordInput autoComplete="new-password" required maxLength={72} value={form.data.password_confirmation} onChange={(ev) => form.setData('password_confirmation', ev.target.value)} /></Field>
                <PasswordMeter password={form.data.password} confirm={form.data.password_confirmation} />
                <Button type="submit" variant="primary" className="w-full" disabled={form.processing || !ready}>Save password</Button>
            </form>
        </AuthLayout>
    );
}
