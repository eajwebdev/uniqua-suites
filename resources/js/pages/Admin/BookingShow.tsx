import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { nextDay, RoomPicker, useRoomAvailability } from '@/components/RoomPicker';
import { ActionButton, Button, Card, confirmDialog, CopyButton, Empty, Field, FieldDef, Input, Modal, RowAction, StatusChip, buttonClass } from '@/components/ui';
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
    const form = useForm<any>({ check_in: b.check_in, check_out: b.check_out, room_ids: b.rooms.map((r: any) => r.id), adults: b.adults, children: b.children, approved_early_time: b.approved_early_time ?? '', reason: '' });
    const e = form.errors as any;
    const availability = useRoomAvailability(open ? form.data.check_in : '', open ? form.data.check_out : '', b.id);
    const picker = rooms.map((r: any) => ({ id: r.id, label: `${r.name} (${r.unit_code})`, max: r.max_guests ?? 0 }));
    const capacity = picker.filter((r: any) => form.data.room_ids.includes(r.id)).reduce((sum: number, r: any) => sum + Number(r.max || 0), 0);
    const party = Number(form.data.adults || 0) + Number(form.data.children || 0);
    const capacityValid = capacity > 0 && party <= capacity;
    const earlyTimeValid = !form.data.approved_early_time || form.data.approved_early_time < standardCheckIn.slice(0, 5);
    const canSave = availability.state === 'ready' && form.data.room_ids.length > 0 && capacityValid && earlyTimeValid && String(form.data.reason).trim().length > 0 && !form.processing;

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
                    <div><dt className="text-xs text-muted">Total charges</dt><dd className="font-semibold tabular-nums">{peso(balance.charges_cents)}</dd></div>
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

export default function BookingShow({ booking: b, charges, balance, receipts, checkout, nowLocal, today, standardCheckIn, downpaymentPercent, settlements, refunds, events, snapshots, parking, messages, rooms }: any) {
    const [paying, setPaying] = useState(false);
    const perms: string[] = (usePage<any>().props as any).auth.user.permissions;
    const can = (p: string) => perms.includes(p);
    const url = `/admin/bookings/${b.id}`;
    const open = ['inquiry', 'tentative', 'confirmed'].includes(b.status);
    let amendable = false;

    const moneyFields: FieldDef[] = [
        // Cameras have no parking; a discount still applies.
        ...(b.is_camera ? [] : [{ name: 'parking', label: 'Parking (PHP)', type: 'money' } as FieldDef]),
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
                    {can('quotes.manage') && <Link className={buttonClass('secondary')} href={`/admin/quotes/create?room_id=${b.rooms[0]?.id ?? ''}&check_in=${b.check_in}&check_out=${b.check_out}&guests=${b.guest_count}&booking_id=${b.id}`}>New quote</Link>}
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {b.is_camera ? <CameraRental booking={b} /> : null}
                    <Card title="Stay" className={b.is_camera ? 'hidden' : undefined}>
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

                    {parking.length > 0 && (
                        <Card title="Parking">
                            <ul className="space-y-1 text-sm">
                                {parking.map((p: any, i: number) => <li key={i}>{p.code}: {dateOnly(p.start)} to {dateOnly(p.end)} <StatusChip value={p.status} /></li>)}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>
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

function Line({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex justify-between gap-2">
            <dt>{label}</dt>
            <dd className="tabular-nums">{peso(value)}</dd>
        </div>
    );
}
