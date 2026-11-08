import { useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { ActionButton, Button, Card, Field, Input, Select, StatusChip } from '@/components/ui';
import { dateOnly, humanize } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']] as const;

const tag = (key: string) => `{{${key}}}`;

function PlaceholderList({ placeholders }: { placeholders: any[] }) {
    return (
        <details className="mb-3 rounded-md border border-line bg-ivory/50 px-3 py-2 text-sm" open>
            <summary className="cursor-pointer font-medium">Placeholders you can use</summary>
            <ul className="mt-2 divide-y divide-line/70">
                {placeholders.map((p) => (
                    <li key={p.key} className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5 text-xs">
                        <code className="w-28 shrink-0 font-mono font-semibold text-chocolate">{tag(p.key)}</code>
                        <span className="min-w-0 flex-1 text-muted">{p.description}{p.where === 'quote' && <strong className="font-medium text-chocolate"> (Quotation only)</strong>}{p.where === 'booking' && <strong className="font-medium text-chocolate"> (not in Quotation)</strong>}</span>
                    </li>
                ))}
            </ul>
        </details>
    );
}

function TemplateRow({ template, placeholders }: any) {
    const form = useForm({ body: template.body });
    const box = useRef<HTMLTextAreaElement>(null);
    const scope = template.key === 'quote' ? 'quote' : 'booking';
    const usable = placeholders.filter((p: any) => p.where === 'all' || p.where === scope);

    const insert = (key: string) => {
        const el = box.current;
        const start = el?.selectionStart ?? form.data.body.length;
        const end = el?.selectionEnd ?? start;
        const text = tag(key);
        form.setData('body', form.data.body.slice(0, start) + text + form.data.body.slice(end));
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(start + text.length, start + text.length);
        });
    };

    return (
        <details className="rounded-md border border-line px-3 py-2 text-sm">
            <summary className="cursor-pointer font-medium">{template.name}</summary>
            <form onSubmit={(e) => { e.preventDefault(); form.post(`/admin/settings/templates/${template.id}`, { preserveScroll: true }); }} className="mt-2 space-y-2">
                <textarea ref={box} aria-label={`${template.name} body`} rows={template.key === 'quote' ? 14 : 4} className="w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                <div>
                    <p className="mb-1 text-xs text-muted">Click to add it where your cursor is:</p>
                    <div className="flex flex-wrap gap-1">
                        {usable.map((p: any) => (
                            <button key={p.key} type="button" title={p.description} onClick={() => insert(p.key)} className="rounded border border-line bg-white px-1.5 py-0.5 font-mono text-[11px] text-chocolate transition-colors hover:border-gold hover:bg-ivory pointer-coarse:min-h-9 pointer-coarse:px-2">{tag(p.key)}</button>
                        ))}
                    </div>
                </div>
                <Button small type="submit" disabled={form.processing}>Save</Button>
            </form>
        </details>
    );
}

export default function Settings({ settings: s, decisions, templates, placeholders, contact, taxes }: any) {
    const form = useForm<any>({
        weekend_days: s.weekend_days ?? [],
        extra_guest: s.extra_guest ?? '200.00',
        extra_guest_unit: s.extra_guest_unit ?? '',
        early_fee: s.early_fee ?? '200.00',
        parking_rate: s.parking_rate ?? '300.00',
        downpayment_percent: s.downpayment_percent ?? 50,
        standard_check_in: s.standard_check_in,
        standard_check_out: s.standard_check_out ?? '',
        late_fee: s.late_fee ?? '',
        hold_hours: s.hold_hours,
        laundry_auto: s.laundry_auto,
        require_verification: s.require_verification,
        turnover_due_hours: s.turnover_due_hours,
        expense_threshold: s.expense_threshold ?? '',
        second_reviewer: s.second_reviewer,
        require_two_factor: s.require_two_factor,
        min_nights: s.min_nights ?? '',
        max_advance_days: s.max_advance_days ?? '',
        house_rules: s.house_rules ?? '',
        cancellation: s.cancellation ?? '',
        arrival_lead_days: s.arrival_lead_days,
    });
    const e = form.errors as any;
    const set = (k: string) => (ev: any) => form.setData(k, ev.target.value);

    return (
        <AdminLayout title="Settings">
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
                        {form.data.weekend_days.length === 0 && <p className="mt-1 text-xs text-amber-900">Choose at least one day: quotes stay disabled until then.</p>}
                    </fieldset>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Field label="Extra-guest fee (PHP)" error={e.extra_guest}><Input value={form.data.extra_guest} onChange={set('extra_guest')} /></Field>
                        <Field label="Extra-guest billing" error={e.extra_guest_unit} help="Blank means no fee is charged.">
                            <Select placeholder="No fee" options={[{ value: 'per_night', label: 'Per guest, per night' }, { value: 'per_stay', label: 'Per guest, per stay' }]} value={form.data.extra_guest_unit} onChange={set('extra_guest_unit')} />
                        </Field>
                        <Field label="Early check-in fee per started hour (PHP)" error={e.early_fee}><Input value={form.data.early_fee} onChange={set('early_fee')} /></Field>
                        <Field label="Parking rate per night (PHP)" error={e.parking_rate} help="Filled in on new quotes. It can be changed per quote."><Input value={form.data.parking_rate} onChange={set('parking_rate')} /></Field>
                        <Field label="Downpayment (%)" error={e.downpayment_percent} help="Shown on new quotes for every booking source. Use 0 for none. Quotes already saved keep theirs."><Input type="number" min={0} max={100} value={form.data.downpayment_percent} onChange={set('downpayment_percent')} /></Field>
                        <Field label="Standard check-in" error={e.standard_check_in}><Input type="time" value={form.data.standard_check_in} onChange={set('standard_check_in')} /></Field>
                        <Field label="Standard check-out" error={e.standard_check_out}><Input type="time" value={form.data.standard_check_out} onChange={set('standard_check_out')} /></Field>
                                                <Field label="Default hold length (hours)" error={e.hold_hours}><Input type="number" value={form.data.hold_hours} onChange={set('hold_hours')} /></Field>
                        <Field label="Turnover due within (hours)" error={e.turnover_due_hours}><Input type="number" value={form.data.turnover_due_hours} onChange={set('turnover_due_hours')} /></Field>
                        <Field label="Expense approval threshold (PHP)" error={e.expense_threshold} help="Bills at or above this need approval before they are paid. Blank means none do."><Input value={form.data.expense_threshold} onChange={set('expense_threshold')} /></Field>
                        <Field label="Minimum stay (nights)" error={e.min_nights} help="Blank means no minimum. A room can set its own."><Input type="number" min={1} value={form.data.min_nights} onChange={set('min_nights')} /></Field>
                        <Field label="Book at most this many days ahead" error={e.max_advance_days} help="Blank means no limit. A room can set its own."><Input type="number" min={1} value={form.data.max_advance_days} onChange={set('max_advance_days')} /></Field>
                        <Field label="Arrival reminder (days before)" error={e.arrival_lead_days}><Input type="number" min={1} max={14} value={form.data.arrival_lead_days} onChange={set('arrival_lead_days')} /></Field>
                    </div>
                    <div className="grid gap-3 lg:grid-cols-2">
                        <Field label="Cancellation policy" error={e.cancellation} help="Shown on the public policies page once written. Blank shows nothing."><textarea rows={3} className="w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base" value={form.data.cancellation} onChange={set('cancellation')} /></Field>
                        <Field label="House rules" error={e.house_rules} help="Shown on the public policies page once written. Blank shows nothing."><textarea rows={3} className="w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base" value={form.data.house_rules} onChange={set('house_rules')} /></Field>
                    </div>
                    <div className="space-y-1 text-sm">
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.laundry_auto} onChange={(ev) => form.setData('laundry_auto', ev.target.checked)} /> Suggest a laundry job at every checkout</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.require_verification} onChange={(ev) => form.setData('require_verification', ev.target.checked)} /> Require a verified payment before confirming a booking</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.second_reviewer} onChange={(ev) => form.setData('second_reviewer', ev.target.checked)} /> Require a second person to verify receipts and approve refunds and expenses they did not record</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.require_two_factor} onChange={(ev) => form.setData('require_two_factor', ev.target.checked)} /> Require two-factor sign-in for staff with finance, security or administrator access</label>
                    </div>
                    <Button type="submit" variant="primary" disabled={form.processing}>Save policy</Button>
                    <p className="text-xs text-muted">Changes apply to new prices only. Confirmed bookings keep their saved snapshot.</p>
                </form>
            </Card>

            <Card title="Taxes and fees" className="mb-4" action={
                <ActionButton action={{ label: 'Add tax', url: '/admin/settings/taxes', confirm: 'New quotes and repriced stays will include this. Have you confirmed the rate with your accountant?', fields: [
                    { name: 'name', label: 'Name shown on quotes', type: 'text', required: true },
                    { name: 'percent', label: 'Rate (%)', type: 'text', required: true, help: 'For example 12 or 1.5.' },
                    { name: 'effective_from', label: 'Applies to stays checking in from', type: 'date', required: true },
                ] }} />
            }>
                {taxes.length === 0 ? <p className="text-sm text-muted">None configured, so none are charged.</p> : (
                    <ul className="divide-y divide-line text-sm">
                        {taxes.map((t: any) => (
                            <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>{t.name}, {t.percent}% <span className="text-xs text-muted">from {dateOnly(t.from)}{t.to ? ` to ${dateOnly(t.to)}` : ''}</span></span>
                                {!t.to && <ActionButton action={{ label: 'End', url: `/admin/settings/taxes/${t.id}/end`, fields: [{ name: 'effective_to', label: 'Last check-in date it applies to', type: 'date', required: true }] }} />}
                            </li>
                        ))}
                    </ul>
                )}
                <p className="mt-2 text-xs text-muted">Worked out on accommodation, extra-guest and early check-in charges, before discounts. Kept out of revenue figures. Confirmed bookings keep their saved price.</p>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Message templates">
                    <p className="mb-2 text-xs text-muted">Placeholders such as {tag('guest')} are replaced with the real details when a message is copied. Type them exactly as shown.</p>
                    <PlaceholderList placeholders={placeholders} />
                    <div className="space-y-2">{templates.map((t: any) => <TemplateRow key={t.id} template={t} placeholders={placeholders} />)}</div>
                </Card>
                <div className="space-y-4">
                    <Card title="Public contact channels">
                        <ul className="space-y-1 text-sm">
                            {Object.entries(contact).map(([k, v]) => <li key={k} className="flex justify-between gap-2"><span>{humanize(k)}</span><StatusChip value={v ? 'approved' : 'pending'} label={v ? 'Set' : 'Not supplied'} /></li>)}
                        </ul>
                        <p className="mt-2 text-xs text-muted">Set these in the environment file. The website's Message us button opens the Messenger link, or the Facebook link if there is no Messenger link.</p>
                    </Card>
                </div>
            </div>

            {decisions && (
                <details className="mt-4 rounded-xl border border-line/80 bg-white p-4 text-sm shadow-xs">
                    <summary className="font-medium">How this system was set up ({decisions.length} choices)</summary>
                    <p className="mt-2 text-xs text-muted">Settled from your room list, suite flyers and sales workbooks. Anything above can still be changed.</p>
                    <ul className="mt-2 divide-y divide-line/70">
                        {decisions.map((d: any) => (
                            <li key={d.id} className="py-2">
                                <p className="font-medium">{d.title}</p>
                                <p className="text-muted">{d.resolution}</p>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </AdminLayout>
    );
}
