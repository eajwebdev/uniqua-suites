import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Button, Card, Empty, Field, Input } from '@/components/ui';
import { dateTime } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function Account({ notifications }: any) {
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
            </div>
        </AdminLayout>
    );
}
