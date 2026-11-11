import { Link, router, usePage } from '@inertiajs/react';
import { Ban, ChevronLeft, ChevronRight, Plus, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { AssignmentChip, BlockForm, ParkingForm, ParkingPaymentForm, PaymentChip } from '@/components/ParkingForms';
import { ActionButton, Button, buttonClass, Card, cn, Empty, Input, Select, StatusChip, Tabs, tableClass, tdClass, thClass, theadClass, trClass } from '@/components/ui';
import { dateOnly, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';
import { useLiveRefresh } from '@/lib/useLiveRefresh';

const TIMING: Record<string, string> = { occupied: 'Occupied now', upcoming: 'Upcoming', pending_slot: 'Waiting for a stall', ended: 'Ended', cancelled: 'Cancelled' };
const TYPE: Record<string, string> = { airbnb: 'Airbnb', direct: 'Direct', none: 'No booking' };

/** Calendar bar colours: the four slot states the team asked for, plus a dashed outline for "not yet confirmed". */
const BAR: Record<string, string> = {
    occupied: 'bg-chocolate text-ivory',
    upcoming: 'bg-gold text-chocolate',
    ended: 'bg-beige text-muted',
    pending_slot: 'border border-dashed border-amber-500 bg-amber-50 text-amber-950',
};

function OwnerTag({ space }: { space: any }) {
    return space.borrowed
        ? <span className="rounded-full border border-amber-200 bg-amber-50 px-1.5 py-px text-[10px] font-medium text-amber-900">Third-party</span>
        : <span className="rounded-full border border-emerald-200 bg-emerald-50 px-1.5 py-px text-[10px] font-medium text-emerald-900">Condo-owned</span>;
}

function Legend() {
    const item = (cls: string, label: string) => <span className="flex items-center gap-1.5"><span className={cn('inline-block h-3 w-5 rounded-sm', cls)} aria-hidden />{label}</span>;

    return (
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
            {item('border border-line bg-white', 'Available')}
            {item('bg-gold', 'Reserved')}
            {item('bg-chocolate', 'Occupied')}
            {item('parking-blocked', 'Blocked')}
            {item('border border-dashed border-amber-500 bg-amber-50', 'Not confirmed (does not hold the stall)')}
        </div>
    );
}

export default function ParkingCalendar({ month, label, prev, next, days, today, nowLocal, tabs, spaces, assignments, blocks, summary, filters, options, reservations, defaultRate }: any) {
    useLiveRefresh(['spaces', 'assignments', 'blocks', 'summary'], 30_000);
    const { auth } = usePage<any>().props as any;
    const can = (p: string) => (auth.user?.permissions ?? []).includes(p);
    const canManage = can('parking.manage');
    const canPay = can('payments.record');
    const path = usePage().url.split('?')[0];
    const dates = useMemo(() => Array.from({ length: days }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`), [month, days]);
    const [form, setForm] = useState<null | { kind: 'parking' | 'block' | 'payment'; item?: any; prefill?: any }>(null);
    const close = () => setForm(null);

    const query = (extra: Record<string, any> = {}): Record<string, any> => Object.fromEntries(Object.entries({ month, ...filters, ...extra }).filter(([, v]) => v !== '' && v !== null && v !== undefined));
    const go = (target: string) => router.get('/admin/parking', query({ month: target }), { preserveScroll: true, preserveState: true });
    const forSpace = (id: number) => assignments.filter((a: any) => a.space_id === id);
    const blocksFor = (id: number) => blocks.filter((b: any) => b.space_id === id);
    const unassigned = assignments.filter((a: any) => !a.space_id);
    const byId = (id: number) => summary.find((r: any) => r.id === id);

    // The end date is exclusive, so that day's column stays free for the next holder.
    function span(start: string, end: string) {
        const from = start < dates[0] ? 1 : Number(start.slice(8, 10));
        const lastExclusive = end > dates[days - 1] ? days + 1 : Number(end.slice(8, 10));
        return { gridColumn: `${from} / ${Math.max(lastExclusive, from + 1)}` };
    }

    function openAssignment(id: number) {
        const row = byId(id);
        if (row && canManage) setForm({ kind: 'parking', item: row });
        else if (!row) document.getElementById('parking-summary')?.scrollIntoView({ behavior: 'smooth' });
    }

    const bar = (a: any) => {
        const state = a.status !== 'assigned' ? 'pending_slot' : a.state;
        return (
            <button key={`a${a.id}`} type="button" onClick={() => openAssignment(a.id)} title={`${a.holder}${a.vehicle ? `, ${a.vehicle}` : ''}: ${a.start} to ${a.end}${a.status !== 'assigned' ? ' (not confirmed)' : ''}`}
                className={cn('mx-0.5 truncate rounded px-1.5 py-1 text-left text-[11px] leading-tight', BAR[state] ?? BAR.upcoming)} style={span(a.start, a.end)}>
                {a.holder}
            </button>
        );
    };
    const blockBar = (b: any) => (
        <button key={`b${b.id}`} type="button" disabled={!canManage} onClick={() => setForm({ kind: 'block', item: b })} title={`${b.reason_label}${b.note ? `: ${b.note}` : ''}, ${b.start} to ${b.end}`}
            className="parking-blocked mx-0.5 truncate rounded px-1.5 py-1 text-left text-[11px] leading-tight text-espresso" style={span(b.start, b.end)}>
            {b.reason_label}
        </button>
    );

    const counts = {
        occupied: summary.filter((r: any) => r.timing === 'occupied').length,
        upcoming: summary.filter((r: any) => r.timing === 'upcoming').length,
        pending: summary.filter((r: any) => r.timing === 'pending_slot').length,
        owed: summary.reduce((sum: number, r: any) => sum + (r.status !== 'cancelled' ? r.balance_cents : 0), 0),
    };

    return (
        <AdminLayout title="Parking">
            <style>{'.parking-blocked{background:repeating-linear-gradient(135deg,#e7e1d8 0 6px,#d6cec2 6px 12px)}'}</style>
            <Tabs tabs={tabs} current={path} />
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <button aria-label="Previous month" className={buttonClass('secondary')} onClick={() => go(prev)}><ChevronLeft size={16} /></button>
                <span className="min-w-36 text-center font-semibold">{label}</span>
                <button aria-label="Next month" className={buttonClass('secondary')} onClick={() => go(next)}><ChevronRight size={16} /></button>
                {canManage && (
                    <div className="ml-auto flex flex-wrap gap-2">
                        <Button onClick={() => setForm({ kind: 'block' })}><Ban size={15} aria-hidden /> Block a stall</Button>
                        <Button variant="primary" onClick={() => setForm({ kind: 'parking' })}><Plus size={15} aria-hidden /> Add parking</Button>
                    </div>
                )}
            </div>
            <div className="mb-3"><Legend /></div>

            {spaces.length === 0 ? <Empty>No parking spaces yet. Add them under Spaces.</Empty> : (
                <>
                    <div className="hidden overflow-x-auto rounded-lg border border-line bg-white md:block">
                        <div style={{ minWidth: `${176 + days * 36}px` }}>
                            <div className="flex border-b border-line bg-ivory text-xs">
                                <div className="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-ivory px-3 py-2 font-medium">Stall</div>
                                <div className="grid flex-1" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                    {dates.map((d, i) => <div key={d} className={cn('py-2 text-center', d === today && 'bg-gold/30 font-semibold')}>{i + 1}</div>)}
                                </div>
                            </div>
                            {spaces.map((s: any) => (
                                <div key={s.id} className="flex border-b border-line last:border-b-0">
                                    <div className="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-white px-3 py-2 text-sm">
                                        <div className="flex flex-wrap items-center gap-1.5 font-medium">{s.code} <OwnerTag space={s} /></div>
                                        <div className="truncate text-xs text-muted">{s.name ? `${s.name} · ` : ''}{s.property}</div>
                                        {s.status !== 'active' && <div className="text-[11px] font-medium text-red-800">Unavailable</div>}
                                        {s.borrowed && <div className="truncate text-[11px] text-amber-900" title="We only see our own guests here. Call the owner to check.">Call {s.owner || 'owner'}{s.contact ? ` · ${s.contact}` : ''}</div>}
                                    </div>
                                    <div className={cn('relative flex-1', s.status !== 'active' && 'parking-blocked')}>
                                        <div className="absolute inset-0 grid" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                            {dates.map((d) => canManage && s.status === 'active' ? (
                                                <button key={d} type="button" aria-label={`Add parking on ${s.code} from ${dateOnly(d)}`} onClick={() => setForm({ kind: 'parking', prefill: { space_id: s.id, start: d } })} className={cn('border-l border-line/60 hover:bg-gold/15', d === today && 'bg-gold/10')} />
                                            ) : <div key={d} aria-hidden className={cn('border-l border-line/60', d === today && 'bg-gold/10')} />)}
                                        </div>
                                        <div className="pointer-events-none relative grid min-h-14 gap-y-0.5 py-1 *:pointer-events-auto" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                            {blocksFor(s.id).map(blockBar)}
                                            {forSpace(s.id).map(bar)}
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {unassigned.length > 0 && (
                                <div className="flex border-t-2 border-dashed border-amber-300 bg-amber-50/40">
                                    <div className="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-amber-50 px-3 py-2 text-sm">
                                        <div className="font-medium">To be assigned</div>
                                        <div className="text-xs text-muted">{unassigned.length} request{unassigned.length === 1 ? '' : 's'}, no stall held</div>
                                    </div>
                                    <div className="relative grid min-h-14 flex-1 gap-y-0.5 py-1" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                        {unassigned.map(bar)}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="space-y-3 md:hidden">
                        {[...spaces, ...(unassigned.length ? [{ id: 0, code: 'To be assigned', tba: true }] : [])].map((s: any) => {
                            const items = s.tba ? unassigned : forSpace(s.id);
                            const stops = s.tba ? [] : blocksFor(s.id);
                            return (
                                <section key={s.id} className="rounded-lg border border-line bg-white p-3">
                                    <h2 className="flex flex-wrap items-center gap-1.5 font-medium">{s.code} {!s.tba && <OwnerTag space={s} />} {!s.tba && <span className="text-xs text-muted">{s.property}</span>}</h2>
                                    {s.status && s.status !== 'active' && <p className="text-xs font-medium text-red-800">Marked unavailable.</p>}
                                    {s.borrowed && <p className="text-xs text-amber-900">Not ours: call {s.owner || 'the owner'}{s.contact ? ` (${s.contact})` : ''} to check it is free.</p>}
                                    {items.length === 0 && stops.length === 0 ? <p className="mt-1 text-sm text-muted">{s.borrowed ? 'None of our guests this month.' : 'Free this month.'}</p> : (
                                        <ul className="divide-y divide-line text-sm">
                                            {stops.map((b: any) => (
                                                <li key={`b${b.id}`} className="py-1.5">
                                                    <button type="button" disabled={!canManage} onClick={() => setForm({ kind: 'block', item: b })} className="text-left">Blocked: {b.reason_label}<span className="block text-xs text-muted">{dateOnly(b.start)} to {dateOnly(b.end)}</span></button>
                                                </li>
                                            ))}
                                            {items.map((a: any) => (
                                                <li key={a.id} className="py-1.5">
                                                    <button type="button" onClick={() => openAssignment(a.id)} className="text-left">{a.holder}{a.vehicle ? `, ${a.vehicle}` : ''} <AssignmentChip status={a.status} /></button>
                                                    <span className="block text-xs text-muted">{dateOnly(a.start)} to {dateOnly(a.end)}{a.booking_id ? <> · <Link href={`/admin/bookings/${a.booking_id}`} className="underline">{a.booking}</Link></> : null}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            );
                        })}
                    </div>
                </>
            )}

            <div id="parking-summary" className="mt-6 scroll-mt-4">
                <Card title="Parking reservation summary">
                    <dl className="mb-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                        <div className="rounded-md bg-sky-50 px-3 py-2"><dt className="text-xs text-sky-900">Occupied now</dt><dd className="text-lg font-semibold">{counts.occupied}</dd></div>
                        <div className="rounded-md bg-emerald-50 px-3 py-2"><dt className="text-xs text-emerald-900">Upcoming</dt><dd className="text-lg font-semibold">{counts.upcoming}</dd></div>
                        <div className="rounded-md bg-amber-50 px-3 py-2"><dt className="text-xs text-amber-900">Waiting for a stall</dt><dd className="text-lg font-semibold">{counts.pending}</dd></div>
                        <div className="rounded-md bg-red-50 px-3 py-2"><dt className="text-xs text-red-900">Parking still to collect</dt><dd className="text-lg font-semibold tabular-nums">{peso(counts.owed)}</dd></div>
                    </dl>
                    <SummaryFilters filters={filters} options={options} month={month} />
                    {summary.length === 0 ? <Empty>No parking reservations match.</Empty> : (
                        <div className="-mx-4 overflow-x-auto sm:mx-0">
                            <table className={tableClass}>
                                <thead className={theadClass}>
                                    <tr>
                                        {['Guest', 'Room', 'Stall', 'Dates', 'Fee', 'Paid', 'Balance', 'Payment', 'Assignment', ''].map((h) => <th key={h} scope="col" className={thClass}>{h}</th>)}
                                    </tr>
                                </thead>
                                <tbody>
                                    {summary.map((r: any) => (
                                        <tr key={r.id} className={cn(trClass, r.timing === 'occupied' && 'bg-sky-50/50', r.timing === 'pending_slot' && 'bg-amber-50/40', r.status === 'cancelled' && 'text-muted')}>
                                            <td className={tdClass}>
                                                <div className="font-medium">{r.guest}</div>
                                                <div className="text-xs text-muted">
                                                    {r.booking ? <Link href={`/admin/bookings/${r.booking.id}`} className="underline">{r.booking.reference}</Link> : 'No booking'} · {TYPE[r.booking_type]}
                                                    {r.vehicle ? ` · ${r.vehicle}` : ''}
                                                </div>
                                            </td>
                                            <td className={tdClass}>{r.rooms || '—'}</td>
                                            <td className={tdClass}>
                                                {r.slot ? <div className="font-medium">{r.slot}</div> : <StatusChip value="pending_slot" label="To be assigned" />}
                                                <div className="text-xs text-muted">{r.ownership === 'tba' ? 'Owner not known yet' : r.ownership === 'borrowed' ? `Third-party${r.owner ? `: ${r.owner}` : ''}` : 'Condo-owned'}</div>
                                            </td>
                                            <td className={tdClass}>
                                                <div>{dateOnly(r.start)} to {dateOnly(r.end)}</div>
                                                <div className="text-xs text-muted">{r.nights} night{r.nights === 1 ? '' : 's'} · <StatusChip value={r.timing} label={TIMING[r.timing]} /></div>
                                            </td>
                                            <td className={cn(tdClass, 'tabular-nums')}>{peso(r.fee_cents)}</td>
                                            <td className={cn(tdClass, 'tabular-nums')}>{peso(r.paid_cents)}{r.pending_cents > 0 && <div className="text-[11px] text-amber-900">+{peso(r.pending_cents)} to verify</div>}</td>
                                            <td className={cn(tdClass, 'font-semibold tabular-nums')}>{peso(r.balance_cents)}</td>
                                            <td className={tdClass}><PaymentChip status={r.payment_status} /></td>
                                            <td className={tdClass}><AssignmentChip status={r.status} /></td>
                                            <td className={cn(tdClass, 'text-right')}>
                                                {r.status !== 'cancelled' && (
                                                    <div className="flex justify-end gap-1">
                                                        {canManage && <Button small onClick={() => setForm({ kind: 'parking', item: r })}>Edit</Button>}
                                                        {canPay && r.balance_cents > 0 && <Button small onClick={() => setForm({ kind: 'payment', item: r })}>Payment</Button>}
                                                        {canManage && !r.remitted && <ActionButton action={{ label: 'Cancel', url: `/admin/parking/assignments/${r.id}/cancel`, variant: 'danger', confirm: 'Cancel this parking? Its stall is released and its charge is removed from the booking.' }} />}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>

            {form?.kind === 'parking' && <ParkingForm assignment={form.item} reservations={reservations} defaultRate={defaultRate} prefill={form.prefill} onClose={close} />}
            {form?.kind === 'block' && <BlockForm block={form.item} spaces={spaces.filter((s: any) => s.status === 'active' || s.id === form.item?.space_id)} reasons={options.blockReasons} prefill={form.prefill} onClose={close} />}
            {form?.kind === 'payment' && <ParkingPaymentForm parking={form.item} methods={options.methods} nowLocal={nowLocal} onClose={close} />}
        </AdminLayout>
    );
}

function SummaryFilters({ filters, options, month }: { filters: any; options: any; month: string }) {
    const [q, setQ] = useState(filters.q ?? '');
    const first = useRef(true);
    const apply = (changes: Record<string, any>) => {
        const next: Record<string, any> = Object.fromEntries(Object.entries({ month, ...filters, ...changes }).filter(([, v]) => v !== '' && v !== null && v !== undefined));
        router.get('/admin/parking', next, { preserveScroll: true, preserveState: true, only: ['summary', 'filters'] });
    };
    useEffect(() => {
        if (first.current) { first.current = false; return; }
        const timer = window.setTimeout(() => apply({ q }), 350);
        return () => window.clearTimeout(timer);
    }, [q]);

    const select = (key: string, placeholder: string, list: any[]) => (
        <Select aria-label={placeholder} placeholder={placeholder} options={list} value={filters[key] ?? ''} onChange={(ev) => apply({ [key]: ev.target.value })} />
    );
    const active = ['from', 'to', 'space', 'room', 'payment', 'assignment', 'type', 'ownership', 'q'].some((k) => filters[k]) || filters.view !== 'open';

    return (
        <div className="mb-3 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-56 flex-1">
                    <Search size={14} className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-muted" aria-hidden />
                    <Input aria-label="Search guest, booking reference or vehicle" placeholder="Search guest, booking reference or vehicle" className="pl-8" value={q} onChange={(ev) => setQ(ev.target.value)} />
                </div>
                <Select aria-label="Which reservations" options={[{ value: 'open', label: 'Current, upcoming and waiting' }, { value: 'past', label: 'Ended and cancelled' }, { value: 'all', label: 'All' }]} value={filters.view ?? 'open'} onChange={(ev) => apply({ view: ev.target.value })} />
                {active && <Button small onClick={() => { setQ(''); router.get('/admin/parking', { month }, { preserveScroll: true, preserveState: true, only: ['summary', 'filters'] }); }}>Clear filters</Button>}
            </div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8">
                <Input type="date" aria-label="On or after" title="Parking on or after" value={filters.from ?? ''} onChange={(ev) => apply({ from: ev.target.value })} />
                <Input type="date" aria-label="On or before" title="Parking on or before" value={filters.to ?? ''} onChange={(ev) => apply({ to: ev.target.value })} />
                {select('space', 'Any stall', options.spaces)}
                {select('room', 'Any room', options.rooms)}
                {select('payment', 'Any payment', options.payment)}
                {select('assignment', 'Any assignment', options.assignment)}
                {select('type', 'Any booking type', options.type)}
                {select('ownership', 'Any ownership', options.ownership)}
            </div>
        </div>
    );
}
