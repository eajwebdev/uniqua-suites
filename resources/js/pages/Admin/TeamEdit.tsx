import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, RotateCcw, ShieldCheck } from 'lucide-react';
import { FormEvent, useEffect } from 'react';
import { Button, buttonClass, Card, cn, confirmDialog, Field, Input, Select } from '@/components/ui';
import AdminLayout from '@/layouts/AdminLayout';

type Page = { key: string; label: string; group: string; help?: string | null; requires?: string | null };

export default function TeamEdit({ person, roles, fullAccess, presets, pages, allowed, areas }: { person: any; roles: string[]; fullAccess: string[]; presets: Record<string, string[]>; pages: Page[]; allowed: string[]; areas: Record<string, string[]> }) {
    const creating = !person.id;
    const form = useForm<any>({ name: person.name ?? '', email: person.email ?? '', role: person.role ?? 'Staff', is_active: person.is_active ?? true, pages: person.pages ?? presets.Staff });
    const e = form.errors as any;
    const full = fullAccess.includes(form.data.role);
    const ticked: string[] = form.data.pages;
    const byKey = Object.fromEntries(pages.map((p) => [p.key, p]));
    const actionsOf = (page: string) => pages.filter((p) => p.requires === page);
    const canGrant = (key: string) => allowed.includes(key);
    const pageCount = ticked.filter((k) => byKey[k]?.group === 'Pages').length;
    const actionCount = ticked.length - pageCount;
    // Anything not placed in an area still gets shown, so a new permission can never be hidden from this screen.
    const placed = new Set([...Object.values(areas).flat(), ...pages.filter((p) => p.requires).map((p) => p.key)]);
    const unplaced = pages.filter((p) => !placed.has(p.key)).map((p) => p.key);
    const sections: [string, string[]][] = [...Object.entries(areas), ...(unplaced.length ? [['Other', unplaced] as [string, string[]]] : [])];

    // Leaving with unsaved ticks asks first, in the app's own dialog. The visit is held, then
    // repeated only if the person chooses to leave.
    useEffect(() => {
        if (!form.isDirty || form.processing) return;
        let leaving = false;
        const stop = router.on('before', (ev) => {
            const visit = ev.detail.visit;
            if (leaving || visit.method !== 'get') return;
            ev.preventDefault();
            confirmDialog({
                title: 'Leave without saving?',
                message: 'Your access changes for this person have not been saved yet. If you leave now, they are lost.',
                confirmLabel: 'Leave without saving',
                danger: true,
            }).then((leave) => {
                if (!leave) return;
                leaving = true;
                router.visit(visit.url.href, { replace: visit.replace });
            });
        });
        return () => { stop(); };
    }, [form.isDirty, form.processing]);

    const setPages = (next: string[]) => form.setData('pages', [...new Set(next)]);
    const chooseRole = (role: string) => form.setData((d: any) => ({ ...d, role, pages: presets[role] ?? [] }));

    /** Ticking an action ticks its page; unticking a page unticks its actions. */
    function toggle(key: string, on: boolean) {
        if (on) {
            const parent = byKey[key]?.requires;
            setPages([...ticked, key, ...(parent && canGrant(parent) ? [parent] : [])]);
        } else {
            const dependants = actionsOf(key).filter((a) => canGrant(a.key)).map((a) => a.key);
            setPages(ticked.filter((k) => k !== key && !dependants.includes(k)));
        }
    }

    function setArea(keys: string[], on: boolean) {
        const all = keys.flatMap((k) => [k, ...actionsOf(k).map((a) => a.key)]).filter(canGrant);
        setPages(on ? [...ticked, ...all] : ticked.filter((k) => !all.includes(k)));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(creating ? '/admin/team' : `/admin/team/${person.id}`);
    }

    // A plain function, not a component, so a checkbox keeps keyboard focus after it is clicked.
    const box = (item: Page, nested = false) => {
        const disabled = !canGrant(item.key);
        return (
            <label key={item.key} className={cn('flex items-start gap-2.5 rounded-lg px-2 py-1.5 text-sm pointer-coarse:min-h-11', disabled ? 'text-muted' : 'cursor-pointer hover:bg-beige', nested && 'ml-7')} title={canGrant(item.key) ? undefined : 'You do not have this access yourself, so you cannot change it.'}>
                <input type="checkbox" className="mt-0.5 size-4 shrink-0" disabled={disabled} checked={ticked.includes(item.key)} onChange={(ev) => toggle(item.key, ev.target.checked)} />
                <span className="min-w-0">
                    <span className={cn('block', !nested && 'font-medium')}>{nested ? <span className="mr-1 text-xs font-semibold tracking-wide text-bronze uppercase">Extra</span> : null}{item.label}</span>
                    {item.help && <span className="block text-xs leading-snug text-muted">{item.help}</span>}
                </span>
            </label>
        );
    };

    const saveButton = (
        <Button type="submit" variant="primary" className="w-full" disabled={form.processing || (!creating && !form.isDirty)}>
            {form.processing ? 'Saving…' : creating ? 'Send invitation' : form.isDirty ? 'Save access' : 'No changes to save'}
        </Button>
    );

    return (
        <AdminLayout title={creating ? 'Invite staff' : `Access for ${person.name}`} subtitle={creating ? 'Choose a role, then tick exactly what they can open.' : person.email}>
            <Link href="/admin/team" className="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-chocolate pointer-coarse:min-h-11"><ArrowLeft size={14} aria-hidden /> Back to team</Link>

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:sticky lg:top-4 lg:self-start">
                    <Card title="Person">
                        <div className="space-y-3">
                            {creating && (
                                <>
                                    <Field label="Name" required error={e.name}><Input required value={form.data.name} onChange={(ev) => form.setData('name', ev.target.value)} /></Field>
                                    <Field label="Email" required error={e.email}><Input type="email" required value={form.data.email} onChange={(ev) => form.setData('email', ev.target.value)} /></Field>
                                </>
                            )}
                            <Field label="Role" required error={e.role} help={full ? undefined : 'Choosing a role ticks its usual pages. You can then change any of them.'}>
                                <Select options={roles.map((r) => ({ value: r, label: r }))} value={form.data.role} onChange={(ev) => chooseRole(ev.target.value)} />
                            </Field>
                            {!creating && <label className="flex items-center gap-2 text-sm pointer-coarse:min-h-11"><input type="checkbox" checked={form.data.is_active} onChange={(ev) => form.setData('is_active', ev.target.checked)} /> Active (can sign in)</label>}
                        </div>
                    </Card>

                    {!full && (
                        <Card title="Summary">
                            <p className="text-sm" aria-live="polite"><strong>{pageCount}</strong> page{pageCount === 1 ? '' : 's'} and <strong>{actionCount}</strong> extra action{actionCount === 1 ? '' : 's'} ticked.</p>
                            {form.isDirty && <p className="mt-1 text-xs font-medium text-amber-700">Unsaved changes</p>}
                            <button type="button" className={cn(buttonClass('ghost', true), 'mt-2 -ml-2')} onClick={() => setPages((presets[form.data.role] ?? []).filter(canGrant))}>
                                <RotateCcw size={14} aria-hidden /> Reset to the usual {form.data.role} pages
                            </button>
                        </Card>
                    )}
                    {saveButton}
                </div>

                <div className="space-y-4 lg:col-span-2">
                    {e.pages && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900">{e.pages}</p>}
                    {full ? (
                        <p className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950"><ShieldCheck size={17} aria-hidden /> {form.data.role} always has every page and action, so there is nothing to tick.</p>
                    ) : sections.map(([area, keys]) => {
                        const items = keys.map((k) => byKey[k]).filter(Boolean);
                        return (
                            <Card key={area} title={area} action={(
                                <span className="flex gap-1">
                                    <button type="button" className={buttonClass('ghost', true)} onClick={() => setArea(keys, true)} aria-label={`Tick everything in ${area}`}>Tick all</button>
                                    <button type="button" className={buttonClass('ghost', true)} onClick={() => setArea(keys, false)} aria-label={`Clear everything in ${area}`}>Clear</button>
                                </span>
                            )}>
                                <div className="divide-y divide-line">
                                    {items.map((item) => (
                                        <div key={item.key} className="py-1.5">
                                            {box(item)}
                                            {actionsOf(item.key).map((action) => box(action, true))}
                                        </div>
                                    ))}
                                </div>
                            </Card>
                        );
                    })}
                    {!full && <div className="lg:hidden">{saveButton}</div>}
                </div>
            </form>
        </AdminLayout>
    );
}
