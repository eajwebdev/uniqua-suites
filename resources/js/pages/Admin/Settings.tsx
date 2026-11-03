import { useForm } from '@inertiajs/react';
import { ActionButton, Button, Card, Field, Input, Select, StatusChip } from '@/components/ui';
import { dateTime, humanize } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']] as const;
const KIND: Record<string, string> = { default_needs_approval: 'Needs approval', data_conflict: 'Data conflict', source_fact: 'Source fact', future_optional: 'Future / optional' };

function TemplateRow({ template }: any) {
    const form = useForm({ body: template.body });
    return (
        <details className="rounded-md border border-line px-3 py-2 text-sm">
            <summary className="cursor-pointer font-medium">{template.name}</summary>
            <form onSubmit={(e) => { e.preventDefault(); form.post(`/admin/settings/templates/${template.id}`, { preserveScroll: true }); }} className="mt-2 space-y-2">
                <textarea aria-label={`${template.name} body`} rows={4} className="w-full rounded-md border border-line px-3 py-2 text-sm" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                <Button small type="submit" disabled={form.processing}>Save</Button>
            </form>
        </details>
    );
}

export default function Settings({ settings: s, decisions, templates, integrations, feeds, rooms, contact }: any) {
    const form = useForm<any>({
        weekend_days: s.weekend_days ?? [],
        extra_guest: s.extra_guest ?? '200.00',
        extra_guest_unit: s.extra_guest_unit ?? '',
        early_fee: s.early_fee ?? '200.00',
        standard_check_in: s.standard_check_in,
        standard_check_out: s.standard_check_out ?? '',
        late_fee: s.late_fee ?? '',
        hold_hours: s.hold_hours,
        laundry_auto: s.laundry_auto,
        require_verification: s.require_verification,
        turnover_due_hours: s.turnover_due_hours,
    });
    const e = form.errors as any;
    const set = (k: string) => (ev: any) => form.setData(k, ev.target.value);
    const pending = decisions.filter((d: any) => d.status === 'pending').length;

    return (
        <AdminLayout title="Settings & Integrations">
            <Card title={`Assumptions and decisions register (${pending} pending)`} className="mb-4">
                <ul className="divide-y divide-line">
                    {decisions.map((d: any) => (
                        <li key={d.id} className="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
                            <div className="min-w-0 flex-1 basis-80">
                                <div className="font-medium">{d.title}</div>
                                <p className="text-muted">{d.detail}</p>
                                {d.resolution && <p className="mt-0.5"><strong>Decision:</strong> {d.resolution}</p>}
                            </div>
                            <div className="flex items-center gap-1.5">
                                <span className="text-xs text-muted">{KIND[d.kind] ?? d.kind}</span>
                                <StatusChip value={d.status} />
                                <ActionButton action={{ label: 'Record', url: `/admin/settings/decisions/${d.id}`, fields: [
                                    { name: 'status', label: 'Status', type: 'select', required: true, default: d.status, options: [{ value: 'pending', label: 'Pending' }, { value: 'approved', label: 'Approved' }, { value: 'rejected', label: 'Rejected' }] },
                                    { name: 'resolution', label: 'Decision and notes', type: 'textarea', default: d.resolution ?? '' },
                                ] }} />
                            </div>
                        </li>
                    ))}
                </ul>
            </Card>

            <Card title="Pricing and operations policy" className="mb-4">
                <form onSubmit={(ev) => { ev.preventDefault(); form.post('/admin/settings', { preserveScroll: true }); }} className="space-y-4">
                    <fieldset>
                        <legend className="mb-1 text-sm font-medium">Nights charged at the weekend rate</legend>
                        <div className="flex flex-wrap gap-3 text-sm">
                            {DAYS.map(([n, label]) => (
                                <label key={n} className="flex items-center gap-1.5">
                                    <input type="checkbox" checked={form.data.weekend_days.includes(n)} onChange={(ev) => form.setData('weekend_days', ev.target.checked ? [...form.data.weekend_days, n] : form.data.weekend_days.filter((x: number) => x !== n))} /> {label}
                                </label>
                            ))}
                        </div>
                        {form.data.weekend_days.length === 0 && <p className="mt-1 text-xs text-amber-900">Pending: staff quotes stay disabled and the public site shows only the two posted rates until days are chosen.</p>}
                    </fieldset>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Field label="Extra-guest fee (PHP)" error={e.extra_guest}><Input value={form.data.extra_guest} onChange={set('extra_guest')} /></Field>
                        <Field label="Extra-guest billing" error={e.extra_guest_unit} help="Blank means no fee is charged yet.">
                            <Select placeholder="Pending owner decision" options={[{ value: 'per_night', label: 'Per guest, per night' }, { value: 'per_stay', label: 'Per guest, per stay' }]} value={form.data.extra_guest_unit} onChange={set('extra_guest_unit')} />
                        </Field>
                        <Field label="Early check-in fee per started hour (PHP)" error={e.early_fee}><Input value={form.data.early_fee} onChange={set('early_fee')} /></Field>
                        <Field label="Standard check-in" error={e.standard_check_in}><Input type="time" value={form.data.standard_check_in} onChange={set('standard_check_in')} /></Field>
                        <Field label="Standard check-out" error={e.standard_check_out} help="Not established by the source."><Input type="time" value={form.data.standard_check_out} onChange={set('standard_check_out')} /></Field>
                        <Field label="Late check-out fee per hour (PHP)" error={e.late_fee}><Input value={form.data.late_fee} onChange={set('late_fee')} /></Field>
                        <Field label="Default hold length (hours)" error={e.hold_hours}><Input type="number" value={form.data.hold_hours} onChange={set('hold_hours')} /></Field>
                        <Field label="Turnover due within (hours)" error={e.turnover_due_hours}><Input type="number" value={form.data.turnover_due_hours} onChange={set('turnover_due_hours')} /></Field>
                    </div>
                    <div className="space-y-1 text-sm">
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.laundry_auto} onChange={(ev) => form.setData('laundry_auto', ev.target.checked)} /> Suggest a laundry job at every checkout</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.require_verification} onChange={(ev) => form.setData('require_verification', ev.target.checked)} /> Require a verified payment before confirming a booking</label>
                    </div>
                    <Button type="submit" variant="primary" disabled={form.processing}>Save policy</Button>
                    <p className="text-xs text-muted">Changes apply to new prices only. Confirmed bookings keep their saved snapshot.</p>
                </form>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Message templates">
                    <p className="mb-2 text-xs text-muted">Placeholders such as {'{{guest}}'} are filled from the booking.</p>
                    <div className="space-y-2">{templates.map((t: any) => <TemplateRow key={t.id} template={t} />)}</div>
                </Card>
                <div className="space-y-4">
                    <Card title="Integrations">
                        <ul className="space-y-2 text-sm">
                            {integrations.map((i: any) => (
                                <li key={i.provider}>
                                    <div className="flex items-center justify-between gap-2"><span className="font-medium">{i.provider}</span><StatusChip value={i.status} /></div>
                                    <p className="text-xs text-muted">{i.detail}{i.last_success_at ? ` Last success ${dateTime(i.last_success_at)}.` : ''}</p>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card title="External calendar feeds (read-only)" action={
                        <ActionButton action={{ label: 'Add feed', url: '/admin/settings/feeds', fields: [
                            { name: 'room_id', label: 'Room', type: 'select', required: true, options: rooms.map((r: any) => ({ value: r.id, label: r.name })) },
                            { name: 'provider', label: 'Provider', type: 'text', required: true, default: 'airbnb' },
                            { name: 'url', label: 'Private .ics URL', type: 'text', required: true, help: 'Stored encrypted and never displayed again.' },
                        ] }} />
                    }>
                        {feeds.length === 0 ? <p className="text-sm text-muted">No feeds. Airbnb bookings are entered manually.</p> : (
                            <ul className="space-y-1 text-sm">
                                {feeds.map((f: any) => <li key={f.id}>{f.room} · {f.provider}: {f.last_status ? humanize(f.last_status) : 'Not synced yet'} <span className="text-xs text-muted">{f.last_synced_at ? dateTime(f.last_synced_at) : ''}</span></li>)}
                            </ul>
                        )}
                        <p className="mt-2 text-xs text-muted">Feeds can lag behind the channel. This is not real-time or two-way sync.</p>
                    </Card>
                    <Card title="Public contact channels">
                        <ul className="space-y-1 text-sm">
                            {Object.entries(contact).map(([k, v]) => <li key={k} className="flex justify-between gap-2"><span>{humanize(k)}</span><StatusChip value={v ? 'approved' : 'pending'} label={v ? 'Set' : 'Not supplied'} /></li>)}
                        </ul>
                        <p className="mt-2 text-xs text-muted">Set these in the environment file once the owner supplies official links.</p>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
