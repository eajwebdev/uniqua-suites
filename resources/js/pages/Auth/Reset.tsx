import { useForm } from '@inertiajs/react';
import { Button, Field, Input, PasswordInput } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function Reset({ token, email }: { token: string; email?: string }) {
    const form = useForm({ token, email: email ?? '', password: '', password_confirmation: '' });
    const e = form.errors as any;

    return (
        <AuthLayout title="Set a new password">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/reset-password'); }} className="space-y-3">
                <Field label="Email" error={e.email}><Input type="email" required value={form.data.email} onChange={(ev) => form.setData('email', ev.target.value)} /></Field>
                <Field label="New password" error={e.password} help="At least 10 characters."><PasswordInput autoComplete="new-password" required value={form.data.password} onChange={(ev) => form.setData('password', ev.target.value)} /></Field>
                <Field label="Confirm password"><PasswordInput autoComplete="new-password" required value={form.data.password_confirmation} onChange={(ev) => form.setData('password_confirmation', ev.target.value)} /></Field>
                <Button type="submit" variant="primary" className="w-full" disabled={form.processing}>Save password</Button>
            </form>
        </AuthLayout>
    );
}
