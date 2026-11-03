import { Link, useForm } from '@inertiajs/react';
import { Button, Field, Input } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });
    const e = form.errors as any;

    return (
        <AuthLayout title="Staff sign in">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/login', { onFinish: () => form.reset('password') }); }} className="space-y-3">
                <Field label="Email" error={e.email}><Input type="email" autoComplete="username" required value={form.data.email} onChange={(ev) => form.setData('email', ev.target.value)} /></Field>
                <Field label="Password" error={e.password}><Input type="password" autoComplete="current-password" required value={form.data.password} onChange={(ev) => form.setData('password', ev.target.value)} /></Field>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remember} onChange={(ev) => form.setData('remember', ev.target.checked)} /> Keep me signed in</label>
                <Button type="submit" variant="primary" className="w-full" disabled={form.processing}>Sign in</Button>
                <Link href="/forgot-password" className="block text-center text-sm underline">Forgot password?</Link>
            </form>
        </AuthLayout>
    );
}
