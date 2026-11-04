import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ShieldCheck } from 'lucide-react';
import { FormEvent } from 'react';
import { Button, buttonClass, Card, Field, Input, Select } from '@/components/ui';
import AdminLayout from '@/layouts/AdminLayout';

export default function TeamEdit({ person, roles, fullAccess, presets, pages, allowed }: any) {
    const creating = !person.id;
    const form = useForm<any>({ name: person.name ?? '', email: person.email ?? '', role: person.role ?? 'Staff', is_active: person.is_active ?? true, pages: person.pages ?? presets.Staff });
    const e = form.errors as any;
    const full = fullAccess.includes(form.data.role);
    const groups: string[] = [...new Set<string>(pages.map((p: any) => p.group))];
    const canGrant = (key: string) => allowed.includes(key);

    const chooseRole = (role: string) => form.setData((d: any) => ({ ...d, role, pages: presets[role] ?? [] }));
    const toggle = (key: string, on: boolean) => form.setData('pages', on ? [...form.data.pages, key] : form.data.pages.filter((k: string) => k !== key));
    const setAll = (group: string, on: boolean) => {
        const inGroup = pages.filter((p: any) => p.group === group && canGrant(p.key)).map((p: any) => p.key);
        form.setData('pages', on ? [...new Set([...form.data.pages, ...inGroup])] : form.data.pages.filter((k: string) => !inGroup.includes(k)));
    };

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(creating ? '/admin/team' : `/admin/team/${person.id}`);
    }

    return (
        <AdminLayout title={creating ? 'Invite staff' : `Access for ${person.name}`} subtitle={creating ? 'They get an email to set their own password.' : person.email}>
            <Link href="/admin/team" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-chocolate pointer-coarse:min-h-11"><ArrowLeft size={14} aria-hidden /> Back to team</Link>

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-3">
                <Card title="Person" className="lg:self-start">
                    <div className="space-y-3">
                        {creating && (
                            <>
                                <Field label="Name" required error={e.name}><Input required value={form.data.name} onChange={(ev) => form.setData('name', ev.target.value)} /></Field>
                                <Field label="Email" required error={e.email}><Input type="email" required value={form.data.email} onChange={(ev) => form.setData('email', ev.target.value)} /></Field>
                            </>
                        )}
                        <Field label="Role" required error={e.role} help={full ? undefined : 'Choosing a role ticks its usual pages. You can then change any of them.'}>
                            <Select options={roles.map((r: string) => ({ value: r, label: r }))} value={form.data.role} onChange={(ev) => chooseRole(ev.target.value)} />
                        </Field>
                        {!creating && <label className="flex items-center gap-2 text-sm pointer-coarse:min-h-11"><input type="checkbox" checked={form.data.is_active} onChange={(ev) => form.setData('is_active', ev.target.checked)} /> Active (can sign in)</label>}
                        <Button type="submit" variant="primary" className="w-full" disabled={form.processing}>{form.processing ? 'Saving…' : creating ? 'Send invitation' : 'Save access'}</Button>
                    </div>
                </Card>

                <div className="space-y-4 lg:col-span-2">
                    {e.pages && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900">{e.pages}</p>}
                    {full ? (
                        <p className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950"><ShieldCheck size={17} aria-hidden /> {form.data.role} always has every page and action, so there is nothing to tick.</p>
                    ) : groups.map((group) => (
                        <Card key={group} title={group === 'Pages' ? 'Pages this person can open' : 'Extra actions'} action={(
                            <span className="flex gap-1">
                                <button type="button" className={buttonClass('ghost', true)} onClick={() => setAll(group, true)}>Tick all</button>
                                <button type="button" className={buttonClass('ghost', true)} onClick={() => setAll(group, false)}>Clear</button>
                            </span>
                        )}>
                            <div className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
                                {pages.filter((p: any) => p.group === group).map((p: any) => (
                                    <label key={p.key} className={`flex min-h-9 items-center gap-2 rounded px-1 text-sm pointer-coarse:min-h-11 ${canGrant(p.key) ? 'hover:bg-beige' : 'text-muted'}`} title={canGrant(p.key) ? undefined : 'You do not have this access yourself, so you cannot change it.'}>
                                        <input type="checkbox" disabled={!canGrant(p.key)} checked={form.data.pages.includes(p.key)} onChange={(ev) => toggle(p.key, ev.target.checked)} /> {p.label}
                                    </label>
                                ))}
                            </div>
                        </Card>
                    ))}
                </div>
            </form>
        </AdminLayout>
    );
}
