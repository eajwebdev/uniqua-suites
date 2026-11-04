import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Field, Input } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function TwoFactor() {
    const [useRecovery, setUseRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });
    const e = form.errors as any;

    function toggle() {
        form.reset();
        form.clearErrors();
        setUseRecovery(!useRecovery);
    }

    return (
        <AuthLayout title="Two-factor sign-in">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/two-factor', { onFinish: () => form.reset() }); }} className="space-y-3">
                {useRecovery ? (
                    <Field label="Recovery code" error={e.recovery_code} help="Each recovery code works once.">
                        <Input autoComplete="one-time-code" required autoFocus value={form.data.recovery_code} onChange={(ev) => form.setData('recovery_code', ev.target.value)} />
                    </Field>
                ) : (
                    <Field label="Code from your authenticator app" error={e.code}>
                        <Input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9 ]*" maxLength={7} required autoFocus value={form.data.code} onChange={(ev) => form.setData('code', ev.target.value)} />
                    </Field>
                )}
                <Button type="submit" variant="primary" className="w-full" disabled={form.processing}>Verify</Button>
                <button type="button" className="block w-full text-center text-sm underline" onClick={toggle}>
                    {useRecovery ? 'Use the authenticator app' : 'Lost your phone? Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}
