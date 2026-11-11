import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Ban, Pencil, RotateCcw } from 'lucide-react';
import { openNewBooking } from '@/components/NewBooking';
import { AssignmentChip, ParkingForm, ParkingPaymentForm, PaymentChip } from '@/components/ParkingForms';
import { nextDay, RoomPicker, useRoomAvailability } from '@/components/RoomPicker';
import { ActionButton, Button, Card, confirmDialog, CopyButton, Empty, Field, FieldDef, Input, Modal, RowAction, Select, StatusChip, buttonClass } from '@/components/ui';
import { dateOnly, dateTime, humanize, peso, time12 } from '@/lib/format';
import { shrinkPhoto } from '@/lib/images';
import AdminLayout from '@/layouts/AdminLayout';

const minuteBefore = (time: string) => {
    const [hour, minute] = time.slice(0, 5).split(':').map(Number);
    const total = Math.max(0, hour * 60 + minute - 1);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
};

const ID_STATUS = [['none', 'Not checked yet'], ['held', 'Seen or kept at pickup'], ['returned', 'Handed back']];
const labelOf = (list: string[][], value: string) => list.find(([v]) => v === value)?.[1] ?? value;
const daysBetween = (from: string, to: string) => Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000);

/** Grab Cam rentals: pickup and return instead of check-in and check-out, and the renter's ID check. Downpayments are agreed in Messenger and recorded as payments. */
function CameraRental({ booking: b }: { booking: any }) {
    const { auth } = usePage<any>().props as any;
    const canManage = (auth.user?.permissions ?? []).includes('bookings.manage');
    const days = daysBetween(b.check_in, b.check_out);

    return (
        <Card
            title="Camera rental"
            action={canManage ? (
                <ActionButton action={{
                    label: 'ID check', url: `/admin/bookings/${b.id}/rental-security`, fields: [
                        { name: 'id_type', label: 'ID type', type: 'text', help: "For example Driver's license or Passport. For a pickup on someone's behalf, their ID." },
                        { name: 'id_status', label: 'ID', type: 'select', required: true, options: ID_STATUS.map(([value, label]) => ({ value, label })) },
                    ],
                    data: { id_type: b.id_type ?? '', id_status: b.id_status },
                }} />
            ) : undefined}
        >
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <Row label="Customer"><Link href={`/admin/guests/${b.guest.id}`} className="underline">{b.guest.full_name}</Link></Row>
                <Row label="Contact">{[b.guest.phone, b.guest.email, b.guest.messenger_handle].filter(Boolean).join(' · ') || '—'}</Row>
                <Row label="Camera">{b.rooms.map((r: any) => `${r.name} (${r.unit})`).join(', ')}</Row>
                <Row label="Rental">{days} day{days === 1 ? '' : 's'}</Row>
                <Row label="Pickup">{dateOnly(b.check_in)}{b.pickup_time ? `, ${time12(b.pickup_time)}` : ''}</Row>
                <Row label="Return">{dateOnly(b.check_out)}{b.return_time ? `, ${time12(b.return_time)}` : ''}</Row>
                <Row label="ID">{labelOf(ID_STATUS, b.id_status)}{b.id_type ? ` · ${b.id_type}` : ''}</Row>
                <Row label="Booking source">{b.source}{b.source_reference ? ` · ${b.source_reference}` : ''}</Row>
                <Row label="Picked up">{dateTime(b.actual_check_in_at)}</Row>
                <Row label="Returned">{dateTime(b.actual_check_out_at)}</Row>
            </dl>
            {b.special_requests && <p className="mt-3 rounded-md bg-beige px-3 py-2 text-sm"><strong>Requests:</strong> {b.special_requests}</p>}
            {b.internal_notes && <p className="mt-2 text-sm text-muted"><strong>Internal:</strong> {b.internal_notes}</p>}
        </Card>
    );
}

/** Changing a stay uses the same room picker as a new booking. The booking's own dates never count against it. */
function ChangeStay({ booking: b, rooms, url, today, standardCheckIn }: { booking: any; rooms: any[]; url: string; today: string; standardCheckIn: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm<any>({ check_in: b.check_in, check_out: b.check_out, room_ids: b.rooms.map((r: any) => r.id), adults: b.adults, children: b.children, approved_early_time: b.approved_early_time ?? '', channel_total: b.channel_total_cents != null ? (b.channel_total_cents / 100).toFixed(2) : '', reason: '' });
    const e = form.errors as any;
    const availability = useRoomAvailability(open ? form.data.check_in : '', open ? form.data.check_out : '', b.id);
    const picker = rooms.map((r: any) => ({ id: r.id, label: `${r.name} (${r.unit_code})`, max: r.max_guests ?? 0 }));
    const capacity = picker.filter((r: any) => form.data.room_ids.includes(r.id)).reduce((sum: number, r: any) => sum + Number(r.max || 0), 0);
    const party = Number(form.data.adults || 0) + Number(form.data.children || 0);
    const capacityValid = capacity > 0 && party <= capacity;
    const earlyTimeValid = !form.data.approved_early_time || form.data.approved_early_time < standardCheckIn.slice(0, 5);
    const channelTotalValid = b.channel_total_cents == null || Number(form.data.channel_total) > 0;
    const canSave = availability.state === 'ready' && form.data.room_ids.length > 0 && capacityValid && earlyTimeValid && channelTotalValid && String(form.data.reason).trim().length > 0 && !form.processing;

    return (
        <>
            <Button onClick={() => setOpen(true)}>Change dates, room or party</Button>
            {open && (
                <Modal open title="Change dates, room or party" onClose={() => setOpen(false)}>
                    <form
                        className="space-y-3"
                        onSubmit={async (ev) => {
                            ev.preventDefault();
                            if (await confirmDialog({ title: 'Save these changes?', message: 'This may change what the guest owes.', confirmLabel: 'Save changes' })) form.post(`${url}/amend`, { preserveScroll: true, onSuccess: () => setOpen(false) });
                        }}
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Check-in" error={e.check_in}><Input type="date" min={b.status === 'checked_in' ? undefined : today} value={form.data.check_in} onChange={(ev) => { const value = ev.target.value; form.setData((d: any) => ({ ...d, check_in: value, check_out: value && d.check_out <= value ? nextDay(value) : d.check_out })); }} /></Field>
                            <Field label="Check-out" error={e.check_out}><Input type="date" min={form.data.check_in ? nextDay(form.data.check_in) : undefined} value={form.data.check_out} onChange={(ev) => form.setData('check_out', ev.target.value)} /></Field>
                        </div>
                        <RoomPicker rooms={picker} availability={availability} value={form.data.room_ids} onChange={(ids) => form.setData('room_ids', ids)} error={e.room_ids} />
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="Adults" error={e.adults}><Input type="number" min={1} max={30} step={1} value={form.data.adults} onChange={(ev) => form.setData('adults', ev.target.value)} /></Field>
                            <Field label="Children" error={e.children}><Input type="number" min={0} max={30} step={1} value={form.data.children} onChange={(ev) => form.setData('children', ev.target.value)} /></Field>
                            <Field label="Approved early check-in" error={e.approved_early_time} help={`Optional. Must be before the standard ${time12(standardCheckIn)} check-in.`}><Input type="time" max={minuteBefore(standardCheckIn)} value={form.data.approved_early_time} onChange={(ev) => form.setData('approved_early_time', ev.target.value)} /></Field>
                        </div>
                        {b.channel_total_cents != null && (
                            <Field label="Total to pay on Airbnb (₱)" required error={e.channel_total} help="If the dates changed on Airbnb, enter the new total from Airbnb.">
                                <Input type="number" inputMode="decimal" min={0.01} step={0.01} value={form.data.channel_total} onChange={(ev) => form.setData('channel_total', ev.target.value)} />
                            </Field>
                        )}
                        {!capacityValid && form.data.room_ids.length > 0 && <p role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">The selected room{form.data.room_ids.length === 1 ? '' : 's'} hold {capacity} guest{capacity === 1 ? '' : 's'}, but the party has {party}. Select another room or correct the guest count.</p>}
                        {!earlyTimeValid && <p role="alert" className="text-sm font-medium text-red-800">Early check-in must be before {time12(standardCheckIn)}.</p>}
                        <Field label="Reason" required error={e.reason}><Input required value={form.data.reason} onChange={(ev) => form.setData('reason', ev.target.value)} /></Field>
                        <div className="flex justify-end gap-2 pt-1">
                            <Button onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" variant="primary" disabled={!canSave}>Save changes</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}

const METHODS = [{ value: 'cash', label: 'Cash' }, { value: 'gcash', label: 'GCash' }, { value: 'bank_transfer', label: 'Bank transfer' }, { value: 'other', label: 'Other' }];

const toCents = (value: string) => {
    const cents = Math.round(Number(value) * 100);

    return Number.isFinite(cents) ? cents : 0;
};
const toPesos = (cents: number) => (cents / 100).toFixed(2);

/** Filled in as far as it safely can be. The server makes the same checks, so a mistake is caught either way. */
function RecordPayment({ booking: b, balance, downpaymentPercent, nowLocal, onClose }: { booking: any; balance: any; downpaymentPercent: number | null; nowLocal: string; onClose: () => void }) {
    const form = useForm<any>({ booking_reference: b.reference, received_at: nowLocal, amount: '', method: 'cash', external_reference: '', evidence: null, confirm_unusual: false });
    const e = form.errors as any;
    const [shrinking, setShrinking] = useState(false);

    const priced = balance.charges_cents > 0;
    const remaining = Math.max(0, balance.balance_cents - balance.pending_cents);
    const cents = toCents(form.data.amount);
    const over = priced && cents > remaining;
    const downpayment = downpaymentPercent && priced && balance.verified_cents === 0 && balance.pending_cents === 0 ? Math.round((balance.charges_cents * downpaymentPercent) / 100) : 0;
    const needsProof = ['gcash', 'bank_transfer'].includes(form.data.method);

    async function chooseProof(file?: File) {
        if (!file) return form.setData('evidence', null);
        if (!file.type.startsWith('image/')) return form.setData('evidence', file);
        setShrinking(true);
        form.setData('evidence', await shrinkPhoto(file));
        setShrinking(false);
    }

    return (
        <Modal open title="Record a payment" onClose={onClose}>
            <form className="space-y-4" onSubmit={(ev) => { ev.preventDefault(); form.post('/admin/receipts', { forceFormData: true, preserveScroll: true, onSuccess: onClose }); }}>
                <dl className="grid grid-cols-3 gap-2 rounded-lg bg-ivory p-3 text-sm">
                    <div><dt className="text-xs text-muted">{balance.estimated ? 'Estimated charges' : 'Total charges'}</dt><dd className="font-semibold tabular-nums">{peso(balance.charges_cents)}</dd></div>
                    <div><dt className="text-xs text-muted">Waiting to verify</dt><dd className="font-semibold tabular-nums">{peso(balance.pending_cents)}</dd></div>
                    <div><dt className="text-xs text-muted">Left to pay</dt><dd className="font-semibold tabular-nums">{peso(remaining)}</dd></div>
                </dl>

                <div>
                    <Field label="Amount received (PHP)" required error={e.amount}>
                        <Input required autoFocus inputMode="decimal" aria-invalid={!!e.amount} placeholder="0.00" value={form.data.amount} onChange={(ev) => form.setData('amount', ev.target.value)} />
                    </Field>
                    {remaining > 0 && (
                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                            <Button small onClick={() => form.setData('amount', toPesos(remaining))}>Full balance {peso(remaining)}</Button>
                            {downpayment > 0 && downpayment < remaining && <Button small onClick={() => form.setData('amount', toPesos(downpayment))}>{downpaymentPercent}% downpayment {peso(downpayment)}</Button>}
                        </div>
                    )}
                    {priced && cents > 0 && !over && <p className="mt-1.5 text-xs text-muted">After this payment is verified, {cents === remaining ? 'nothing is left to pay' : `${peso(remaining - cents)} is left to pay`}.</p>}
                    {!priced && <p className="mt-1.5 text-xs text-muted">This booking is not priced yet, so there is no balance to compare with.</p>}
                    {balance.estimated && <p className="mt-1.5 text-xs text-muted">Amounts are an estimate until the booking is confirmed.</p>}
                    {over && !e.amount && <p className="mt-1.5 text-xs font-medium text-amber-900">This is {peso(cents - remaining)} more than what is left to pay.</p>}
                </div>

                <fieldset>
                    <legend className="mb-1 text-sm font-medium">How was it paid? <span className="text-red-800" aria-hidden>*</span></legend>
                    <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                        {METHODS.map((m) => (
                            <button key={m.value} type="button" aria-pressed={form.data.method === m.value} onClick={() => form.setData('method', m.value)} className={`rounded-lg border px-2 py-2 text-sm font-medium transition-colors pointer-coarse:min-h-11 ${form.data.method === m.value ? 'border-chocolate bg-chocolate text-ivory' : 'border-line bg-white hover:border-gold'}`}>{m.label}</button>
                        ))}
                    </div>
                    {e.method && <p role="alert" className="mt-1 text-xs text-red-800">{e.method}</p>}
                </fieldset>

                <Field label="Date and time received" required error={e.received_at} help="Change this only if the money came in earlier.">
                    <Input required type="datetime-local" max={nowLocal} aria-invalid={!!e.received_at} value={form.data.received_at} onChange={(ev) => form.setData('received_at', ev.target.value)} />
                </Field>

                {form.data.method !== 'cash' && (
                    <Field label="Reference number" error={e.external_reference} help={needsProof ? 'For GCash and bank payments, add this number or attach proof below.' : undefined}>
                        <Input aria-invalid={!!e.external_reference} value={form.data.external_reference} onChange={(ev) => form.setData('external_reference', ev.target.value)} />
                    </Field>
                )}

                <Field label="Proof (photo or PDF)" error={e.evidence} help={shrinking ? 'Making the photo smaller…' : 'Optional for cash.'}>
                    <input type="file" accept="image/*,application/pdf" className="block w-full text-sm" onChange={(ev) => chooseProof(ev.target.files?.[0])} />
                </Field>

                {(e.confirm_unusual || form.data.confirm_unusual) && (
                    <label className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                        <input type="checkbox" className="mt-0.5 size-4 shrink-0" checked={form.data.confirm_unusual} onChange={(ev) => form.setData('confirm_unusual', ev.target.checked)} />
                        <span>I checked the warning above, and this payment is correct. Save it anyway.</span>
                    </label>
                )}

                <div className="flex justify-end gap-2">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="primary" disabled={form.processing || shrinking}>{form.processing ? 'Saving…' : cents > 0 ? `Record ${peso(cents)}` : 'Record payment'}</Button>
                </div>
                <p className="text-xs text-muted">The payment counts toward the balance after it is verified.</p>
            </form>
        </Modal>
    );
}

/** A guest can always leave. Leaving with money owed is allowed, but it is never silent: it needs a reason, which stays on the booking. */
function CheckOut({ url, checkout, onRecordPayment, camera }: { url: string; checkout: any; onRecordPayment?: () => void; camera?: boolean }) {
    const [open, setOpen] = useState(false);
    const label = camera ? 'Mark returned' : 'Check out';
    const form = useForm({ unpaid_reason: '' });
    const e = form.errors as any;

    if (!checkout.unpaid) {
        return <ActionButton small={false} action={{ label, url: `${url}/check-out`, variant: 'primary', confirm: camera ? 'Mark this camera as returned? Check the camera and its accessories first.' : 'Check out? This marks the room dirty and creates turnover work.' }} />;
    }

    return (
        <>
            <Button variant="primary" onClick={() => setOpen(true)}>{label}</Button>
            {open && (
                <Modal open title="Check out with an unpaid balance?" onClose={() => setOpen(false)}>
                    <form className="space-y-3" onSubmit={(ev) => { ev.preventDefault(); form.post(`${url}/check-out`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                            {checkout.balance_cents !== null ? <p className="text-lg font-semibold tabular-nums">{peso(checkout.balance_cents)} still unpaid</p> : <p className="font-semibold">This booking still has an unpaid balance.</p>}
                            {checkout.pending_cents > 0 && <p className="mt-1 text-xs">{peso(checkout.pending_cents)} of that is a payment waiting to be verified.</p>}
                            <p className="mt-1 text-xs">The guest can still check out. The balance stays on this booking and on your list of money to collect, and you can record the payment any time.</p>
                        </div>
                        <Field label="Why is it not fully paid?" required error={e.unpaid_reason} help="For example: paying by bank transfer tomorrow. This is saved in the booking history.">
                            <Input required autoFocus aria-invalid={!!e.unpaid_reason} value={form.data.unpaid_reason} onChange={(ev) => form.setData('unpaid_reason', ev.target.value)} />
                        </Field>
                        <div className="flex flex-wrap justify-end gap-2 pt-1">
                            {onRecordPayment && <Button onClick={() => { setOpen(false); onRecordPayment(); }}>Record a payment first</Button>}
                            <Button type="submit" variant="primary" disabled={form.processing}>{form.processing ? 'Checking out…' : 'Check out with balance'}</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}

function ReceiptRow({ receipt: r, can }: { receipt: any; can: (p: string) => boolean }) {
    const reversal = r.reverses_receipt_id !== null;

    return (
        <li className="space-y-1">
            <div className="flex items-center justify-between gap-2">
                <span className={r.verification_status === 'rejected' || r.reversed ? 'text-muted line-through' : ''}>{peso(r.amount_cents)}</span>
                <StatusChip value={r.verification_status} label={reversal ? 'Reversal' : r.reversed ? 'Reversed' : undefined} />
            </div>
            <p className="text-xs text-muted">
                {humanize(r.method)}, {dateOnly(r.receipt_date)}{r.external_reference ? ` · ${r.external_reference}` : ''}
                {r.has_proof && <> · <a href={`/admin/files/receipt/${r.id}`} target="_blank" rel="noreferrer" className="underline">Proof</a></>}
            </p>
            {r.review_note && <p className="text-xs text-muted">{r.review_note}</p>}
            {can('payments.verify') && r.verification_status === 'pending' && (
                <div className="flex flex-wrap gap-1">
                    <ActionButton action={{ label: 'Verify', url: `/admin/receipts/${r.id}/review`, data: { decision: 'verified' }, variant: 'primary', confirm: `Confirm ${peso(r.amount_cents)} was actually received?` }} />
                    <ActionButton action={{ label: 'Reject', url: `/admin/receipts/${r.id}/review`, data: { decision: 'rejected' }, variant: 'danger', fields: [{ name: 'note', label: 'Why is this being rejected?', type: 'text', required: true, help: 'For example: entered twice, or the money never arrived.' }] }} />
                </div>
            )}
            {can('payments.verify') && r.verification_status === 'verified' && !reversal && !r.reversed && (
                <ActionButton action={{ label: 'Undo (reverse)', url: `/admin/receipts/${r.id}/reverse`, variant: 'danger', confirm: 'This adds a negative entry dated today, and the original stays on file. Continue?', fields: [{ name: 'reason', label: 'Why is this being reversed?', type: 'text', required: true }] }} />
            )}
        </li>
    );
}

/**
 * Until the booking is priced, its estimate stands in for the charges, marked as an estimate. Charges added
 * before confirmation (such as parking) are already part of the estimate, so they are not counted twice.
 */
function withEstimate(balance: any, estimate: any, pricingVersion: number) {
    if (!balance || pricingVersion > 0 || !estimate?.total_cents) return balance;
    return { ...balance, estimated: true, charges_cents: estimate.total_cents, balance_cents: balance.balance_cents - balance.charges_cents + estimate.total_cents, status: 'Estimate' };
}

function EstimateTable({ estimate }: { estimate: any }) {
    return (
        <>
            <table className="w-full text-sm">
                <tbody>
                    {estimate.lines.map((l: any, i: number) => (
                        <tr key={i} className="border-b border-line last:border-0">
                            <td className="py-1.5 pr-3">{l.description}</td>
                            <td className="py-1.5 pr-3 text-xs text-muted">{humanize(l.type)}</td>
                            <td className="py-1.5 text-right tabular-nums">{peso(l.amount_cents)}</td>
                        </tr>
                    ))}
                    <tr className="border-t border-line font-semibold"><td className="py-2" colSpan={2}>Estimated total</td><td className="py-2 text-right tabular-nums">{peso(estimate.total_cents)}</td></tr>
                </tbody>
            </table>
            <p className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">
                {estimate.source === 'quote' ? `As quoted to the guest (${estimate.quote}).` : estimate.source === 'channel' ? 'The total entered from Airbnb, shared across the nights.' : "Worked out at today's rates."} Nothing is owed yet: the price is locked when the booking is confirmed.
            </p>
        </>
    );
}

const CLOSED = ['cancelled', 'no_show', 'expired_hold'];
const area = 'w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base';
const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

/** Source, reference and notes in one place. Changing the source of a confirmed stay reprices it. */
function EditDetails({ booking: b, sources, url, onClose }: { booking: any; sources: any[]; url: string; onClose: () => void }) {
    const form = useForm<any>({
        booking_source_id: String(b.booking_source_id ?? ''),
        channel_total: b.channel_total_cents != null ? (b.channel_total_cents / 100).toFixed(2) : '',
        source_reference: b.source_reference ?? '',
        special_requests: b.special_requests ?? '',
        internal_notes: b.internal_notes ?? '',
    });
    const e = form.errors as any;
    const locked = !['inquiry', 'tentative', 'confirmed', 'checked_in'].includes(b.status);
    const airbnb = !b.is_camera && sources.find((x: any) => String(x.id) === form.data.booking_source_id)?.code === 'airbnb';
    const priced = ['confirmed', 'checked_in'].includes(b.status);
    const priceChanges = form.data.booking_source_id !== String(b.booking_source_id) || (airbnb && Math.round(Number(form.data.channel_total) * 100) !== b.channel_total_cents);
    const canSave = (!airbnb || Number(form.data.channel_total) > 0) && !form.processing;

    async function save(ev: React.FormEvent) {
        ev.preventDefault();
        if (priced && priceChanges && !(await confirmDialog({ title: 'Recalculate the price?', message: airbnb ? 'This stay will cost the Airbnb total you entered.' : 'This stay will be priced from the nightly rates again.', confirmLabel: 'Save and recalculate' }))) return;
        form.transform((d: any) => ({ ...d, channel_total: airbnb ? d.channel_total : '' }));
        form.post(`${url}/details`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal open title="Edit booking details" onClose={onClose}>
            <form className="space-y-3" onSubmit={save}>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Booking source" required error={e.booking_source_id} help={locked ? `Cannot change on a ${humanize(b.status).toLowerCase()} booking.` : undefined}>
                        <Select disabled={locked} options={sources.map((x: any) => ({ value: String(x.id), label: x.name }))} value={form.data.booking_source_id} onChange={(ev) => form.setData('booking_source_id', ev.target.value)} />
                    </Field>
                    <Field label="Reservation or message reference" error={e.source_reference} help="For example the Airbnb code.">
                        <Input value={form.data.source_reference} onChange={(ev) => form.setData('source_reference', ev.target.value)} />
                    </Field>
                </div>
                {airbnb && (
                    <Field label="Total to pay on Airbnb (₱)" required error={e.channel_total} help="Copy it from the Airbnb reservation.">
                        <Input type="number" inputMode="decimal" min={0.01} step={0.01} disabled={locked} value={form.data.channel_total} onChange={(ev) => form.setData('channel_total', ev.target.value)} />
                    </Field>
                )}
                {priced && priceChanges && <p role="status" className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">Saving recalculates the price of this stay. Charges you added by hand are kept.</p>}
                <Field label="Special requests" error={e.special_requests} help="What the guest asked for. Staff see it on the booking.">
                    <textarea rows={3} maxLength={2000} className={area} value={form.data.special_requests} onChange={(ev) => form.setData('special_requests', ev.target.value)} />
                </Field>
                <Field label="Internal notes" error={e.internal_notes} help="Only staff see this.">
                    <textarea rows={3} maxLength={2000} className={area} value={form.data.internal_notes} onChange={(ev) => form.setData('internal_notes', ev.target.value)} />
                </Field>
                <div className="flex justify-end gap-2 pt-1">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="primary" disabled={!canSave}>Save</Button>
                </div>
            </form>
        </Modal>
    );
}

/** The guest's name and contact, saved to the guest record, so every booking of theirs shows the change. */
function EditGuest({ guest, onClose }: { guest: any; onClose: () => void }) {
    const form = useForm<any>({ full_name: guest.full_name ?? '', phone: guest.phone ?? '', email: guest.email ?? '', messenger_handle: guest.messenger_handle ?? '' });
    const e = form.errors as any;

    return (
        <Modal open title="Edit guest" onClose={onClose}>
            <form className="space-y-3" onSubmit={(ev) => { ev.preventDefault(); form.post(`/admin/guests/${guest.id}`, { preserveScroll: true, onSuccess: onClose }); }}>
                <Field label="Full name" required error={e.full_name}><Input required autoComplete="off" value={form.data.full_name} onChange={(ev) => form.setData('full_name', ev.target.value)} /></Field>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Phone" error={e.phone}><Input type="tel" value={form.data.phone} onChange={(ev) => form.setData('phone', ev.target.value)} /></Field>
                    <Field label="Email" error={e.email}><Input type="email" value={form.data.email} onChange={(ev) => form.setData('email', ev.target.value)} /></Field>
                </div>
                <Field label="Messenger name or profile" error={e.messenger_handle}><Input value={form.data.messenger_handle} onChange={(ev) => form.setData('messenger_handle', ev.target.value)} /></Field>
                <p className="text-xs text-muted">This updates the guest record, so every booking for this guest shows the change.</p>
                <div className="flex justify-end gap-2 pt-1">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="primary" disabled={form.processing || !form.data.full_name.trim()}>Save guest</Button>
                </div>
            </form>
        </Modal>
    );
}

/** Says plainly why nothing more happens on a closed booking, and offers to start a new one from it. */
function ClosedNotice({ booking: b, today, canBook }: { booking: any; today: string; canBook: boolean }) {
    const what = ({ cancelled: 'This booking was cancelled', no_show: 'The guest did not arrive', expired_hold: 'This hold expired' } as Record<string, string>)[b.status] ?? 'This booking is closed';
    const rooms = b.rooms.length ? b.rooms : (b.released_rooms ?? []);
    const future = b.check_in >= today;
    const bookAgain = () => openNewBooking({
        room_id: rooms[0]?.id,
        check_in: future ? b.check_in : undefined,
        check_out: future ? b.check_out : undefined,
        guest: b.guest,
        adults: b.adults,
        source_id: b.booking_source_id,
        note: `Rebooked from ${b.reference}.`,
    });

    return (
        <div role="status" className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm">
            <Ban size={18} className="shrink-0 text-red-800" aria-hidden />
            <div className="min-w-0 flex-1">
                <p className="font-medium text-red-950">{what}{b.cancellation_reason ? `: ${b.cancellation_reason}` : '.'}</p>
                <p className="text-xs text-red-900/80">Its dates are free again. Nothing is owed unless a charge is listed below. Notes can still be edited.</p>
            </div>
            {canBook && <Button small onClick={bookAgain}><RotateCcw size={14} aria-hidden /> Book again</Button>}
        </div>
    );
}

export default function BookingShow({ booking: b, charges, balance: savedBalance, estimate, receipts, checkout, nowLocal, today, standardCheckIn, downpaymentPercent, settlements, refunds, events, snapshots, parking, parkingSplit, parkingForm, messages, rooms, sources = [] }: any) {
    const [paying, setPaying] = useState(false);
    const [parkingDialog, setParkingDialog] = useState<null | { kind: 'edit' | 'payment'; item?: any }>(null);
    const [editing, setEditing] = useState<'details' | 'guest' | null>(null);
    const closed = CLOSED.includes(b.status);
    const shownRooms = b.rooms.length ? b.rooms : (b.released_rooms ?? []);
    const balance = withEstimate(savedBalance, estimate, b.pricing_version);
    const perms: string[] = (usePage<any>().props as any).auth.user.permissions;
    const can = (p: string) => perms.includes(p);
    const url = `/admin/bookings/${b.id}`;
    const open = ['inquiry', 'tentative', 'confirmed'].includes(b.status);
    let amendable = false;

    // Parking is added with Add parking, so it has its own dates, stall and payment status.
    const moneyFields: FieldDef[] = [
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
            actions.push({ label: b.is_camera ? 'Mark picked up' : 'Check in', url: `${url}/check-in`, variant: 'primary', fields: fields.length ? fields : undefined });
            actions.push({ label: 'No-show', url: `${url}/no-show`, variant: 'danger', confirm: 'Mark this booking as a no-show? The room dates will be released.' });
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
                    {amendable && <ChangeStay booking={b} rooms={rooms} url={url} today={today} standardCheckIn={standardCheckIn} />}
                {actions.map((a) => <ActionButton key={a.label} action={a} small={false} />)}
                    {can('bookings.manage') && b.status === 'checked_in' && <CheckOut camera={b.is_camera} url={url} checkout={checkout} onRecordPayment={can('payments.record') && balance ? () => setPaying(true) : undefined} />}
                    {can('quotes.manage') && !closed && b.status !== 'checked_out' && <Link className={buttonClass('secondary')} href={`/admin/quotes/create?room_id=${b.rooms[0]?.id ?? ''}&check_in=${b.check_in}&check_out=${b.check_out}&guests=${b.guest_count}&booking_id=${b.id}`}>New quote</Link>}
                </div>
            </div>

            {closed && <ClosedNotice booking={b} today={today} canBook={can('bookings.manage')} />}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {b.is_camera ? <CameraRental booking={b} /> : null}
                    <Card title="Stay" className={b.is_camera ? 'hidden' : undefined} action={can('bookings.manage') ? <Button small onClick={() => setEditing('details')}><Pencil size={13} aria-hidden /> Edit details</Button> : undefined}>
                        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                            <Row label="Guest">
                                <Link href={`/admin/guests/${b.guest.id}`} className="font-medium underline">{b.guest.full_name}</Link>
                                {can('guests.manage') && <button type="button" onClick={() => setEditing('guest')} className="ml-2 inline-flex items-center gap-1 text-xs font-medium text-bronze hover:underline pointer-coarse:min-h-11"><Pencil size={12} aria-hidden /> Edit guest</button>}
                            </Row>
                            <Row label="Contact">
                                {[b.guest.phone, b.guest.email, b.guest.messenger_handle].filter(Boolean).join(' · ') || <span className="text-muted">None saved{can('guests.manage') ? ': use Edit guest to add one' : ''}</span>}
                            </Row>
                            <Row label={b.rooms.length ? (b.rooms.length === 1 ? 'Room' : 'Rooms') : 'Was booked for'}>
                                {shownRooms.length === 0 ? <span className="text-muted">—</span> : shownRooms.map((r: any) => (
                                    <span key={r.id} className={`mr-2 inline-flex items-center gap-1 ${b.rooms.length ? '' : 'text-muted'}`}>{r.name} ({r.unit}){r.housekeeping && <StatusChip value={r.housekeeping} />}</span>
                                ))}
                                {!b.rooms.length && shownRooms.length > 0 && <span className="text-xs text-muted">(released)</span>}
                            </Row>
                            <Row label="Property">{b.property}</Row>
                            <Row label="Dates">{dateOnly(b.check_in)} to {dateOnly(b.check_out)} <span className="text-muted">· {plural(daysBetween(b.check_in, b.check_out), 'night')}</span></Row>
                            <Row label="Party">{plural(b.adults, 'adult')}{b.children > 0 ? `, ${plural(b.children, 'child', 'children')}` : ''}</Row>
                            <Row label="Booking source">{b.source}{b.source_reference ? <span className="text-muted"> · {b.source_reference}</span> : ''}</Row>
                            {b.channel_total_cents != null && <Row label="Airbnb total">{peso(b.channel_total_cents)}</Row>}
                            <Row label="Approved early check-in">{b.approved_early_time ? time12(b.approved_early_time) : 'None'}</Row>
                            {(b.actual_check_in_at || b.actual_check_out_at || ['checked_in', 'checked_out'].includes(b.status)) && <>
                                <Row label="Actual check-in">{dateTime(b.actual_check_in_at)}</Row>
                                <Row label="Actual check-out">{dateTime(b.actual_check_out_at)}</Row>
                            </>}
                        </dl>
                        <div className="mt-4 grid gap-2 sm:grid-cols-2">
                            <Note label="Special requests" text={b.special_requests} empty="None" />
                            <Note label="Internal notes" text={b.internal_notes} empty="None" muted />
                        </div>
                    </Card>

                    <Card
                        title={b.pricing_version ? `Charges (price version ${b.pricing_version})` : closed ? 'Charges' : 'Charges (estimate)'}
                        action={can('bookings.manage') ? (
                            <ActionButton action={{
                                label: 'Add charge', url: `${url}/charges`, confirm: 'This changes what the guest owes. Continue?', fields: [
                                    { name: 'type', label: 'Type', type: 'select', required: true, options: [
                                        { value: 'service', label: 'Other service' }, { value: 'late_check_out', label: 'Late check-out' },
                                        ...(can('bookings.discount') ? [{ value: 'adjustment', label: 'Adjustment' }, { value: 'discount', label: 'Discount' }] : []),
                                    ] },
                                    { name: 'description', label: 'Description', type: 'text', required: true },
                                    { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true },
                                ],
                            }} />
                        ) : undefined}
                    >
                        {estimate?.lines && !b.pricing_version && <EstimateTable estimate={estimate} />}
                        {estimate?.lines && !b.pricing_version && charges.length > 0 && <p className="mt-3 mb-1 text-xs font-medium text-muted">Added before confirmation (kept when the price is locked)</p>}
                        {estimate?.lines && !b.pricing_version && charges.length === 0 ? null : charges.length === 0 ? (
                            <Empty>{closed ? 'No charges. This booking closed before it was priced.' : estimate?.error ?? 'Priced when the booking is confirmed. Use New quote for an estimate.'}</Empty>
                        ) : (
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
                        <Card title="Balance" action={balance.charges_cents === 0 && balance.balance_cents === 0 ? <StatusChip value="none" label="Nothing owed" /> : <StatusChip value={balance.status === 'Paid' ? 'paid' : 'pending'} label={balance.status} />}>
                            <dl className="space-y-1 text-sm">
                                <Line label={balance.estimated ? 'Charges (estimate)' : 'Charges'} value={balance.charges_cents} />
                                <Line label="Verified receipts" value={-balance.verified_cents} />
                                {balance.settled_gross_cents > 0 && <Line label="Settled via channel (gross)" value={-balance.settled_gross_cents} />}
                                {balance.refunded_cents > 0 && <Line label="Refunds paid out" value={balance.refunded_cents} />}
                                <div className="flex justify-between border-t border-line pt-1 font-semibold"><dt>{balance.estimated ? 'Balance due (estimate)' : 'Balance due'}</dt><dd className="tabular-nums">{peso(balance.balance_cents)}</dd></div>
                                {parkingSplit && parkingSplit.parking_fee_cents > 0 && !balance.estimated && (
                                    <div className="mt-2 space-y-1 rounded-md bg-ivory px-2.5 py-2 text-xs">
                                        <div className="flex justify-between gap-2"><dt>Stay: {peso(parkingSplit.stay_paid_cents)} paid of {peso(parkingSplit.stay_charges_cents)}</dt><dd className="font-medium">{parkingSplit.stay_paid_cents >= parkingSplit.stay_charges_cents ? 'Paid' : 'Open'}</dd></div>
                                        <div className="flex justify-between gap-2"><dt>Parking: {peso(parkingSplit.parking_paid_cents)} paid of {peso(parkingSplit.parking_fee_cents)}</dt><dd className="font-medium">{parkingSplit.parking_balance_cents > 0 ? `${peso(parkingSplit.parking_balance_cents)} to collect` : 'Paid'}</dd></div>
                                    </div>
                                )}
                                {balance.estimated && downpaymentPercent && balance.verified_cents === 0 && <Line label={`${downpaymentPercent}% downpayment`} value={Math.round((balance.charges_cents * downpaymentPercent) / 100)} />}
                                {balance.pending_cents > 0 && <p className="text-xs text-amber-900">{peso(balance.pending_cents)} awaiting verification is not counted yet.</p>}
                            </dl>
                        </Card>
                    ) : null}

                    {can('payments.view') && (
                        <Card title="Payments" action={can('payments.record') && balance ? <Button small onClick={() => setPaying(true)}>Record payment</Button> : undefined}>
                            {receipts.length === 0 && settlements.length === 0 ? <Empty>No payments yet.</Empty> : (
                                <ul className="space-y-3 text-sm">
                                    {receipts.map((r: any) => <ReceiptRow key={r.id} receipt={r} can={can} />)}
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

                    {parkingForm && (
                        <Card title="Parking" action={can('parking.manage') && !closed && b.status !== 'checked_out' ? <Button small onClick={() => setParkingDialog({ kind: 'edit' })}>Add parking</Button> : undefined}>
                            {parking.length === 0 ? <Empty>No parking. Add it now or any time up to check-out; the room price is not changed.</Empty> : (
                                <ul className="space-y-3 text-sm">
                                    {parking.map((p: any) => (
                                        <li key={p.id} className={p.status === 'cancelled' ? 'text-muted' : undefined}>
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <span className="font-medium">{p.slot ?? 'Stall to be assigned'}</span>
                                                <AssignmentChip status={p.status} />
                                                {p.status !== 'cancelled' && <PaymentChip status={p.payment_status} />}
                                            </div>
                                            <p className="text-xs text-muted">{dateOnly(p.start)} to {dateOnly(p.end)} · {p.nights} night{p.nights === 1 ? '' : 's'}{p.vehicle ? ` · ${p.vehicle}` : ''}</p>
                                            {p.status !== 'cancelled' && p.fee_cents > 0 && <p className="text-xs tabular-nums">Fee {peso(p.fee_cents)} · paid {peso(p.paid_cents)}{p.balance_cents > 0 ? ` · ${peso(p.balance_cents)} to collect` : ''}{p.pending_cents > 0 ? ` · ${peso(p.pending_cents)} to verify` : ''}</p>}
                                            {p.slot && <p className="text-xs text-muted">{p.borrowed ? `Third-party stall of ${p.owner || 'another owner'}: the fee is remitted to them${p.remitted ? ' (done)' : ''}.` : 'Condo-owned stall.'}</p>}
                                            {p.status !== 'cancelled' && (
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {can('parking.manage') && <Button small onClick={() => setParkingDialog({ kind: 'edit', item: p })}>Edit</Button>}
                                                    {can('payments.record') && p.balance_cents > 0 && <Button small onClick={() => setParkingDialog({ kind: 'payment', item: p })}>Record parking payment</Button>}
                                                    {can('parking.manage') && !p.remitted && <ActionButton action={{ label: 'Cancel', url: `/admin/parking/assignments/${p.id}/cancel`, variant: 'danger', confirm: 'Cancel this parking? Its stall is released and its charge is removed.' }} />}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}
                </div>
            </div>
            {editing === 'details' && <EditDetails booking={b} sources={sources} url={url} onClose={() => setEditing(null)} />}
            {editing === 'guest' && <EditGuest guest={b.guest} onClose={() => setEditing(null)} />}
            {parkingDialog?.kind === 'edit' && parkingForm && <ParkingForm assignment={parkingDialog.item} fixedReservation={parkingForm.reservation} defaultRate={parkingForm.defaultRate} onClose={() => setParkingDialog(null)} />}
            {parkingDialog?.kind === 'payment' && parkingForm && <ParkingPaymentForm parking={parkingDialog.item} methods={parkingForm.methods} nowLocal={nowLocal} onClose={() => setParkingDialog(null)} />}
            {paying && balance && <RecordPayment booking={b} balance={balance} downpaymentPercent={downpaymentPercent} nowLocal={nowLocal} onClose={() => setPaying(false)} />}
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

function Note({ label, text, empty, muted }: { label: string; text?: string | null; empty: string; muted?: boolean }) {
    return (
        <div className={`rounded-md px-3 py-2 text-sm ${muted ? 'bg-ivory' : 'bg-beige/70'}`}>
            <p className="text-xs text-muted">{label}</p>
            <p className={`whitespace-pre-line ${text ? '' : 'text-muted'}`}>{text || empty}</p>
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
