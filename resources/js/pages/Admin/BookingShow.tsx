import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { nextDay, RoomPicker, useRoomAvailability } from '@/components/RoomPicker';
import { ActionButton, Button, Card, CopyButton, Empty, Field, FieldDef, Input, Modal, RowAction, StatusChip, buttonClass } from '@/components/ui';
import { dateOnly, dateTime, humanize, peso, time12 } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

/** Changing a stay uses the same room picker as a new booking. The booking's own dates never count against it. */
function ChangeStay({ booking: b, rooms, url }: { booking: any; rooms: any[]; url: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm<any>({ check_in: b.check_in, check_out: b.check_out, room_ids: b.rooms.map((r: any) => r.id), adults: b.adults, children: b.children, approved_early_time: b.approved_early_time ?? '', reason: '' });
    const e = form.errors as any;
    const availability = useRoomAvailability(open ? form.data.check_in : '', open ? form.data.check_out : '', b.id);
    const picker = rooms.map((r: any) => ({ id: r.id, label: `${r.name} (${r.unit_code})`, max: r.max_guests ?? 99 }));

    return (
        <>
            <Button onClick={() => setOpen(true)}>Change dates, room or party</Button>
            {open && (
                <Modal open title="Change dates, room or party" onClose={() => setOpen(false)}>
                    <form
                        className="space-y-3"
                        onSubmit={(ev) => {
                            ev.preventDefault();
                            if (window.confirm('This may change what the guest owes. Continue?')) form.post(`${url}/amend`, { preserveScroll: true, onSuccess: () => setOpen(false) });
                        }}
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Check-in" error={e.check_in}><Input type="date" value={form.data.check_in} onChange={(ev) => { const value = ev.target.value; form.setData((d: any) => ({ ...d, check_in: value, check_out: value && d.check_out <= value ? nextDay(value) : d.check_out })); }} /></Field>
                            <Field label="Check-out" error={e.check_out}><Input type="date" min={form.data.check_in ? nextDay(form.data.check_in) : undefined} value={form.data.check_out} onChange={(ev) => form.setData('check_out', ev.target.value)} /></Field>
                        </div>
                        <RoomPicker rooms={picker} availability={availability} value={form.data.room_ids} onChange={(ids) => form.setData('room_ids', ids)} error={e.room_ids} />
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="Adults" error={e.adults}><Input type="number" min={1} value={form.data.adults} onChange={(ev) => form.setData('adults', ev.target.value)} /></Field>
                            <Field label="Children" error={e.children}><Input type="number" min={0} value={form.data.children} onChange={(ev) => form.setData('children', ev.target.value)} /></Field>
                            <Field label="Early check-in" error={e.approved_early_time}><Input type="time" value={form.data.approved_early_time} onChange={(ev) => form.setData('approved_early_time', ev.target.value)} /></Field>
                        </div>
                        <Field label="Reason" required error={e.reason}><Input required value={form.data.reason} onChange={(ev) => form.setData('reason', ev.target.value)} /></Field>
                        <div className="flex justify-end gap-2 pt-1">
                            <Button onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" variant="primary" disabled={availability.state !== 'ready' || form.data.room_ids.length === 0 || form.processing}>Save changes</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}

export default function BookingShow({ booking: b, charges, balance, receipts, settlements, refunds, events, snapshots, parking, messages, rooms }: any) {
    const perms: string[] = (usePage<any>().props as any).auth.user.permissions;
    const can = (p: string) => perms.includes(p);
    const url = `/admin/bookings/${b.id}`;
    const open = ['inquiry', 'tentative', 'confirmed'].includes(b.status);
    let amendable = false;

    const moneyFields: FieldDef[] = [
        { name: 'parking', label: 'Parking (PHP)', type: 'money' },
        ...(can('bookings.discount') ? ([
            { name: 'discount', label: 'Authorized discount (PHP)', type: 'money' },
            { name: 'discount_reason', label: 'Discount reason', type: 'text' },
        ] as FieldDef[]) : []),
    ];

    const actions: RowAction[] = [];
    if (can('bookings.manage') && ['inquiry', 'tentative', 'expired_hold'].includes(b.status)) {
        actions.push({ label: b.status === 'tentative' ? 'Extend hold' : 'Place hold', url: `${url}/hold`, fields: [{ name: 'hours', label: 'Hold for (hours)', type: 'number', help: 'Blank uses the default from Settings.' }] });
    }
    if (can('bookings.confirm') && ['inquiry', 'tentative'].includes(b.status)) {
        actions.push({ label: 'Confirm booking', url: `${url}/confirm`, variant: 'primary', fields: moneyFields, confirm: 'Confirm this booking and lock the price?' });
    }
    if (can('bookings.manage')) {
        if (b.status === 'confirmed') {
            const fields: FieldDef[] = can('bookings.override_readiness') ? [
                { name: 'override', label: 'Override room readiness', type: 'checkbox' },
                { name: 'override_note', label: 'Reason for override', type: 'text', help: 'Required when overriding.' },
            ] : [];
            actions.push({ label: 'Check in', url: `${url}/check-in`, variant: 'primary', fields: fields.length ? fields : undefined });
            actions.push({ label: 'No-show', url: `${url}/no-show`, variant: 'danger', confirm: 'Mark this booking as a no-show? The room dates will be released.' });
        }
        if (b.status === 'checked_in') {
            actions.push({ label: 'Check out', url: `${url}/check-out`, variant: 'primary', confirm: 'Check out? This marks the room dirty and creates turnover work.' });
        }
        if (['inquiry', 'tentative', 'confirmed', 'checked_in'].includes(b.status)) {
            amendable = true;
        }
        if (open) {
            actions.push({ label: 'Cancel', url: `${url}/cancel`, variant: 'danger', fields: [{ name: 'reason', label: 'Reason', type: 'text', required: true }], confirm: 'Cancel this booking and release its dates?' });
        }
    }

    return (
        <AdminLayout title={`Booking ${b.reference}`}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <StatusChip value={b.status} />
                {b.status === 'tentative' && b.hold_expires_at && <span className="text-sm text-muted">Hold until {dateTime(b.hold_expires_at)}</span>}
                <div className="ml-auto flex flex-wrap gap-2">
                    {amendable && <ChangeStay booking={b} rooms={rooms} url={url} />}
                {actions.map((a) => <ActionButton key={a.label} action={a} small={false} />)}
                    {can('quotes.manage') && <Link className={buttonClass('secondary')} href={`/admin/quotes/create?room_id=${b.rooms[0]?.id ?? ''}&check_in=${b.check_in}&check_out=${b.check_out}&guests=${b.guest_count}&booking_id=${b.id}`}>New quote</Link>}
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card title="Stay">
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <Row label="Guest"><Link href={`/admin/guests/${b.guest.id}`} className="underline">{b.guest.full_name}</Link></Row>
                            <Row label="Contact">{[b.guest.phone, b.guest.email, b.guest.messenger_handle].filter(Boolean).join(' · ') || '—'}</Row>
                            <Row label="Rooms">
                                {b.rooms.map((r: any) => <span key={r.id} className="mr-2 inline-flex items-center gap-1">{r.name} ({r.unit}) <StatusChip value={r.housekeeping} /></span>)}
                            </Row>
                            <Row label="Property">{b.property}</Row>
                            <Row label="Dates">{dateOnly(b.check_in)} to {dateOnly(b.check_out)}</Row>
                            <Row label="Party">{b.adults} adult(s), {b.children} child(ren)</Row>
                            <Row label="Booking source">{b.source}{b.source_reference ? ` · ${b.source_reference}` : ''}</Row>
                            <Row label="Approved early check-in">{b.approved_early_time ? time12(b.approved_early_time) : 'None'}</Row>
                            <Row label="Actual check-in">{dateTime(b.actual_check_in_at)}</Row>
                            <Row label="Actual check-out">{dateTime(b.actual_check_out_at)}</Row>
                        </dl>
                        {b.special_requests && <p className="mt-3 rounded-md bg-beige px-3 py-2 text-sm"><strong>Special requests:</strong> {b.special_requests}</p>}
                        {b.internal_notes && <p className="mt-2 text-sm text-muted"><strong>Internal:</strong> {b.internal_notes}</p>}
                    </Card>

                    <Card
                        title={`Charges (price version ${b.pricing_version || 'not set'})`}
                        action={can('bookings.manage') ? (
                            <ActionButton action={{
                                label: 'Add charge', url: `${url}/charges`, confirm: 'This changes what the guest owes. Continue?', fields: [
                                    { name: 'type', label: 'Type', type: 'select', required: true, options: [
                                        { value: 'service', label: 'Other service' }, { value: 'late_check_out', label: 'Late check-out' }, { value: 'parking', label: 'Parking' },
                                        ...(can('bookings.discount') ? [{ value: 'adjustment', label: 'Adjustment' }, { value: 'discount', label: 'Discount' }] : []),
                                    ] },
                                    { name: 'description', label: 'Description', type: 'text', required: true },
                                    { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true },
                                ],
                            }} />
                        ) : undefined}
                    >
                        {charges.length === 0 ? <Empty>Priced when the booking is confirmed. Use New quote for an estimate.</Empty> : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <tbody>
                                        {charges.map((c: any) => (
                                            <tr key={c.id} className={`border-b border-line last:border-0 ${c.voided_at ? 'text-muted line-through' : ''}`}>
                                                <td className="py-1.5 pr-3">{c.description}{c.voided_at && <span className="ml-1 text-xs no-underline">({c.void_reason})</span>}</td>
                                                <td className="py-1.5 pr-3 text-xs text-muted">{humanize(c.type)}</td>
                                                <td className="py-1.5 text-right tabular-nums">{peso(c.amount_cents)}</td>
                                                {can('bookings.manage') && (
                                                    <td className="py-1.5 pl-2 text-right">
                                                        {!c.voided_at && <ActionButton action={{ label: 'Void', url: `/admin/charges/${c.id}/void`, variant: 'danger', fields: [{ name: 'reason', label: 'Reason', type: 'text', required: true }] }} />}
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {snapshots.length > 0 && (
                            <details className="mt-3 text-sm">
                                <summary className="cursor-pointer text-muted">Price history ({snapshots.length})</summary>
                                <ul className="mt-1 space-y-1">
                                    {snapshots.map((s: any) => <li key={s.version}>v{s.version}: {peso(s.total_cents)}, {s.reason} <span className="text-xs text-muted">({dateTime(s.at)})</span></li>)}
                                </ul>
                            </details>
                        )}
                    </Card>

                    <Card title="Copy-ready messages">
                        <p className="mb-2 text-xs text-muted">Copy and send these yourself in Messenger or Instagram. Nothing is sent automatically.</p>
                        <div className="space-y-2">
                            {messages.map((m: any) => (
                                <details key={m.key} className="rounded-md border border-line px-3 py-2 text-sm">
                                    <summary className="cursor-pointer font-medium">{m.name}</summary>
                                    <p className="mt-2 whitespace-pre-wrap">{m.body}</p>
                                    <div className="mt-2"><CopyButton text={m.body} /></div>
                                </details>
                            ))}
                        </div>
                    </Card>

                    <Card title="History">
                        <ul className="space-y-1.5 text-sm">
                            {events.map((e: any, i: number) => (
                                <li key={i}><span className="text-xs text-muted">{dateTime(e.at)}</span> <strong>{humanize(e.to)}</strong>{e.note ? `: ${e.note}` : ''} {e.by && <span className="text-xs text-muted">({e.by})</span>}</li>
                            ))}
                        </ul>
                    </Card>
                </div>

                <div className="space-y-4">
                    {balance ? (
                        <Card title="Balance" action={<StatusChip value={balance.status === 'Paid' ? 'paid' : 'pending'} label={balance.status} />}>
                            <dl className="space-y-1 text-sm">
                                <Line label="Charges" value={balance.charges_cents} />
                                <Line label="Verified receipts" value={-balance.verified_cents} />
                                {balance.settled_gross_cents > 0 && <Line label="Settled via channel (gross)" value={-balance.settled_gross_cents} />}
                                {balance.refunded_cents > 0 && <Line label="Refunds paid out" value={balance.refunded_cents} />}
                                <div className="flex justify-between border-t border-line pt-1 font-semibold"><dt>Balance due</dt><dd className="tabular-nums">{peso(balance.balance_cents)}</dd></div>
                                {balance.pending_cents > 0 && <p className="text-xs text-amber-900">{peso(balance.pending_cents)} awaiting verification is not counted yet.</p>}
                            </dl>
                        </Card>
                    ) : null}

                    {can('payments.view') && (
                        <Card title="Payments" action={can('payments.record') ? (
                            <ActionButton action={{
                                label: 'Record', url: '/admin/receipts', data: { booking_reference: b.reference }, fields: [
                                    { name: 'received_at', label: 'Received at', type: 'datetime', required: true },
                                    { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true },
                                    { name: 'method', label: 'Method', type: 'select', required: true, options: [{ value: 'cash', label: 'Cash' }, { value: 'gcash', label: 'GCash' }, { value: 'bank_transfer', label: 'Bank transfer' }, { value: 'other', label: 'Other' }] },
                                    { name: 'external_reference', label: 'Reference', type: 'text' },
                                    { name: 'evidence', label: 'Proof', type: 'file' },
                                ],
                            }} />
                        ) : undefined}>
                            {receipts.length === 0 && settlements.length === 0 ? <Empty>No payments yet.</Empty> : (
                                <ul className="space-y-2 text-sm">
                                    {receipts.map((r: any) => (
                                        <li key={r.id} className="flex items-center justify-between gap-2">
                                            <span>{peso(r.amount_cents)} <span className="text-xs text-muted">{humanize(r.method)}, {dateOnly(r.receipt_date)}</span></span>
                                            <StatusChip value={r.verification_status} />
                                        </li>
                                    ))}
                                    {settlements.map((s: any) => (
                                        <li key={`s${s.id}`} className="flex items-center justify-between gap-2">
                                            <span>{s.provider}: expected {peso(s.expected_net_cents)}</span>
                                            <StatusChip value={s.actual_payout_cents !== null ? 'paid' : 'pending'} label={s.actual_payout_cents !== null ? `Received ${peso(s.actual_payout_cents)}` : 'Not received'} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}

                    {can('payments.view') && (
                        <Card title="Refunds" action={can('payments.record') ? (
                            <ActionButton action={{ label: 'Request', url: '/admin/refunds', data: { booking_id: b.id }, fields: [
                                { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true },
                                { name: 'reason', label: 'Reason', type: 'text', required: true },
                            ] }} />
                        ) : undefined}>
                            {refunds.length === 0 ? <Empty>None.</Empty> : (
                                <ul className="space-y-2 text-sm">
                                    {refunds.map((r: any) => (
                                        <li key={r.id}>
                                            <div className="flex items-center justify-between gap-2"><span>{peso(r.amount_cents)}</span><StatusChip value={r.status === 'executed' ? 'paid' : r.status === 'requested' ? 'pending' : r.status} label={humanize(r.status)} /></div>
                                            <p className="text-xs text-muted">{r.reason}</p>
                                            {can('refunds.approve') && r.status === 'requested' && (
                                                <div className="mt-1 flex gap-1">
                                                    <ActionButton action={{ label: 'Approve', url: `/admin/refunds/${r.id}/decide`, data: { action: 'approve' } }} />
                                                    <ActionButton action={{ label: 'Reject', variant: 'danger', url: `/admin/refunds/${r.id}/decide`, data: { action: 'reject' } }} />
                                                </div>
                                            )}
                                            {can('refunds.approve') && r.status === 'approved' && (
                                                <div className="mt-1"><ActionButton action={{ label: 'Mark as paid out', url: `/admin/refunds/${r.id}/decide`, data: { action: 'execute' }, confirm: 'Only mark this once the money has actually been sent. Continue?', fields: [{ name: 'executed_on', label: 'Paid on', type: 'date', required: true }] }} /></div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}

                    {parking.length > 0 && (
                        <Card title="Parking">
                            <ul className="space-y-1 text-sm">
                                {parking.map((p: any, i: number) => <li key={i}>{p.code}: {dateOnly(p.start)} to {dateOnly(p.end)} <StatusChip value={p.status} /></li>)}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}

function Row({ label, children }: any) {
    return (
        <div>
            <dt className="text-xs text-muted">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

function Line({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex justify-between gap-2">
            <dt>{label}</dt>
            <dd className="tabular-nums">{peso(value)}</dd>
        </div>
    );
}
