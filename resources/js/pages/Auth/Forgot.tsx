import { Link, useForm } from '@inertiajs/react';
import { Button, Field, Input } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function Forgot() {
    const form = useForm({ email: '' });

    return (
        <AuthLayout title="Reset password">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/forgot-password'); }} className="space-y-3">
                <Field label="Email" error={(form.errors as any).email}><Input type="email" required value={form.data.email} onChange={(ev) => form.setData('email', ev.target.value)} /></Field>
                <Button type="submit" variant="primary" className="w-full" disabled={form.processing}>Send reset link</Button>
                <Link href="/login" className="block text-center text-sm underline">Back to sign in</Link>
            </form>
        </AuthLayout>
    );
}
