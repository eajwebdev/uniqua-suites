import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Button, Field, Input, Modal, SearchSelect, Select, StatusChip } from '@/components/ui';
import { dateOnly, peso } from '@/lib/format';

/** A reservation the parking can belong to. */
export type Reservation = { id: number; reference: string; guest: string; rooms: string; check_in: string; check_out: string; status: string; airbnb: boolean };

type SpaceOption = { id: number; label: string; property: string; ownership: string; owner: string | null; rate_cents: number | null; status: string; free: boolean; reason: string | null };

export const ASSIGNMENT_LABELS: Record<string, string> = { requested: 'Requested', pending_confirmation: 'Pending confirmation', assigned: 'Assigned', cancelled: 'Cancelled' };
export const PAYMENT_LABELS: Record<string, string> = { to_collect: 'To collect', partially_paid: 'Partially paid', paid: 'Paid', no_fee: 'No fee' };

export const nightsBetween = (from: string, to: string) => (from && to && to > from ? Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000) : 0);
const toCents = (value: string) => {
    const cents = Math.round(Number(value) * 100);

    return Number.isFinite(cents) ? cents : 0;
};

/** Assignment status, with "assigned" in green: here it means the stall is confirmed. */
export function AssignmentChip({ status }: { status: string }) {
    return <StatusChip value={status === 'assigned' ? 'parking_assigned' : status} label={ASSIGNMENT_LABELS[status] ?? status} />;
}

export function PaymentChip({ status }: { status: string }) {
    return <StatusChip value={status} label={PAYMENT_LABELS[status] ?? status} />;
}

/** Stalls that can take these dates, fetched live as the dates change. */
function useSpaceAvailability(start: string, end: string, ignore?: number) {
    const [spaces, setSpaces] = useState<SpaceOption[] | null>(null);
    useEffect(() => {
        let cancelled = false;
        const timer = window.setTimeout(() => {
            axios.get('/admin/parking/availability', { params: { start: start || undefined, end: end || undefined, ignore } })
                .then((r) => { if (!cancelled) setSpaces(r.data.spaces); })
                .catch(() => { if (!cancelled) setSpaces([]); });
        }, 200);

        return () => { cancelled = true; window.clearTimeout(timer); };
    }, [start, end, ignore]);

    return spaces;
}

/**
 * Add or change a parking reservation. Pick the guest's reservation (or type a name), the dates and rate,
 * then either leave the stall "To be assigned" or pick one that is free. Payment is recorded separately.
 */
export function ParkingForm({ assignment, reservations, fixedReservation, defaultRate, prefill, onClose }: {
    assignment?: any; reservations?: Reservation[]; fixedReservation?: Reservation; defaultRate: string | null; prefill?: { space_id?: number; start?: string }; onClose: () => void;
}) {
    const editing = !!assignment;
    const list = useMemo(() => {
        const all = fixedReservation ? [fixedReservation] : (reservations ?? []);
        // An older booking on an existing parking is still offered, so editing does not drop it.
        return assignment?.booking && !all.some((r) => r.id === assignment.booking.id)
            ? [{ id: assignment.booking.id, reference: assignment.booking.reference, guest: assignment.booking.guest, rooms: assignment.rooms ?? '', check_in: assignment.booking.check_in, check_out: assignment.booking.check_out, status: '', airbnb: assignment.booking_type === 'airbnb' }, ...all]
            : all;
    }, [reservations, fixedReservation, assignment]);
    const startBooking = fixedReservation ?? (assignment?.booking ? list.find((r) => r.id === assignment.booking.id) : undefined);

    const form = useForm<any>({
        booking_id: startBooking?.id ?? '',
        holder_name: assignment?.guest ?? startBooking?.guest ?? '',
        start_date: assignment?.start ?? prefill?.start ?? startBooking?.check_in ?? '',
        end_date: assignment?.end ?? startBooking?.check_out ?? '',
        rate: assignment?.rate ?? defaultRate ?? '',
        parking_space_id: assignment?.space_id ?? prefill?.space_id ?? '',
        status: assignment?.status ?? (prefill?.space_id ? 'assigned' : 'requested'),
        vehicle: assignment?.vehicle ?? '',
        notes: assignment?.notes ?? '',
        owner_confirmed: false,
    });
    const e = form.errors as any;
    const d = form.data;
    const booking = list.find((r) => String(r.id) === String(d.booking_id));
    const nights = nightsBetween(d.start_date, d.end_date);
    const fee = nights * toCents(d.rate || '0');
    const spaces = useSpaceAvailability(d.start_date, d.end_date, assignment?.id);
    const space = spaces?.find((s) => String(s.id) === String(d.parking_space_id));
    const needsOwnerCall = space?.ownership === 'borrowed' && d.status === 'assigned' && !(assignment?.status === 'assigned' && String(assignment?.space_id) === String(d.parking_space_id));

    function chooseBooking(value: string) {
        const r = list.find((x) => String(x.id) === value);
        form.setData((cur: any) => ({
            ...cur, booking_id: value,
            holder_name: r ? r.guest : cur.holder_name,
            // A new request follows the stay; staff can shorten it.
            ...(r && !editing ? { start_date: r.check_in, end_date: r.check_out } : {}),
        }));
    }

    function chooseSpace(value: string) {
        const s = spaces?.find((x) => String(x.id) === value);
        form.setData((cur: any) => ({
            ...cur, parking_space_id: value,
            status: !value ? (cur.status === 'assigned' ? 'requested' : cur.status) : cur.status === 'requested' ? 'pending_confirmation' : cur.status,
            rate: !editing && s?.rate_cents && !cur.rate_touched ? (s.rate_cents / 100).toFixed(2) : cur.rate,
        }));
    }

    function submit(ev: FormEvent) {
        ev.preventDefault();
        form.transform((data: any) => ({ ...data, booking_id: data.booking_id || null, parking_space_id: data.parking_space_id || null }));
        form.post(editing ? `/admin/parking/assignments/${assignment.id}` : '/admin/parking/assignments', { preserveScroll: true, onSuccess: onClose });
    }

    const statusOptions = [
        { value: 'requested', label: 'Requested (no stall yet)', disabled: !!d.parking_space_id },
        { value: 'pending_confirmation', label: 'Pending confirmation (stall not confirmed yet)' },
        { value: 'assigned', label: 'Assigned (stall confirmed: blocks it)', disabled: !d.parking_space_id },
    ];

    return (
        <Modal open wide title={editing ? 'Edit parking' : 'Add parking'} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 md:grid-cols-2">
                    <fieldset className="space-y-3">
                        <legend className="mb-1 text-sm font-semibold">Guest</legend>
                        {fixedReservation ? (
                            <p className="rounded-md bg-ivory px-3 py-2 text-sm"><span className="font-medium">{fixedReservation.reference}</span> · {fixedReservation.guest}{fixedReservation.rooms ? ` · ${fixedReservation.rooms}` : ''}</p>
                        ) : (
                            <Field label="Guest reservation" error={e.booking_id} help="Pick the booking; the guest, room and stay dates fill in. Leave empty for parking without a booking.">
                                <SearchSelect aria-label="Guest reservation" placeholder={`Choose a reservation (${list.length})`} searchPlaceholder="Search guest, reference or room" empty="No reservation matches."
                                    options={[{ value: '', label: 'No booking (type a name below)' }, ...list.map((r) => ({ value: r.id, label: `${r.guest} · ${r.reference}`, hint: `${r.rooms || 'No room'} · ${dateOnly(r.check_in)} to ${dateOnly(r.check_out)}${r.airbnb ? ' · Airbnb' : ''}`, keywords: `${r.reference} ${r.rooms}` }))]}
                                    value={d.booking_id} onChange={chooseBooking} />
                            </Field>
                        )}
                        {booking && (
                            <dl className="grid grid-cols-3 gap-2 rounded-md border border-line px-3 py-2 text-xs">
                                <div><dt className="text-muted">Booking</dt><dd className="font-medium">{booking.reference}</dd></div>
                                <div><dt className="text-muted">Room</dt><dd className="font-medium">{booking.rooms || '—'}</dd></div>
                                <div><dt className="text-muted">Stay</dt><dd className="font-medium">{dateOnly(booking.check_in)} to {dateOnly(booking.check_out)}</dd></div>
                            </dl>
                        )}
                        <Field label="Guest or holder name" required={!d.booking_id} error={e.holder_name}><Input value={d.holder_name} onChange={(ev) => form.setData('holder_name', ev.target.value)} /></Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Vehicle" error={e.vehicle} help="Plate or model, optional."><Input value={d.vehicle} onChange={(ev) => form.setData('vehicle', ev.target.value)} /></Field>
                            <Field label="Notes" error={e.notes}><Input value={d.notes} onChange={(ev) => form.setData('notes', ev.target.value)} /></Field>
                        </div>
                    </fieldset>

                    <fieldset className="space-y-3">
                        <legend className="mb-1 text-sm font-semibold">Dates and fee</legend>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="From" required error={e.start_date}><Input type="date" value={d.start_date} onChange={(ev) => form.setData('start_date', ev.target.value)} /></Field>
                            <Field label="Until (car leaves)" required error={e.end_date}><Input type="date" min={d.start_date || undefined} value={d.end_date} onChange={(ev) => form.setData('end_date', ev.target.value)} /></Field>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Rate per night (PHP)" error={e.rate} help="Blank for no fee."><Input inputMode="decimal" value={d.rate} onChange={(ev) => form.setData((cur: any) => ({ ...cur, rate: ev.target.value, rate_touched: true }))} /></Field>
                            <div className="rounded-md bg-ivory px-3 py-2 text-sm">
                                <p className="text-xs text-muted">Total parking fee</p>
                                <p className="text-lg font-semibold tabular-nums">{peso(fee)}</p>
                                <p className="text-xs text-muted">{nights} night{nights === 1 ? '' : 's'}{nights ? ` × ${peso(toCents(d.rate || '0'))}` : ''}</p>
                            </div>
                        </div>
                        {booking?.airbnb && <p className="text-xs text-muted">Airbnb booking: the stay is paid on Airbnb. This fee is collected here and does not change the stay's paid status.</p>}
                        {booking && fee > 0 && <p className="text-xs text-muted">Added to {booking.reference} as its own charge. The room price and earlier payments stay as they are.</p>}
                    </fieldset>
                </div>

                <fieldset className="space-y-3 rounded-lg border border-line p-3">
                    <legend className="px-1 text-sm font-semibold">Stall</legend>
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="Space" error={e.parking_space_id} help={spaces === null ? 'Checking which stalls are free…' : 'Stalls already taken or blocked for these dates cannot be chosen.'}>
                            <Select value={d.parking_space_id} onChange={(ev) => chooseSpace(ev.target.value)} placeholder="To be assigned"
                                options={(spaces ?? []).map((s) => ({ value: s.id, disabled: !s.free && String(s.id) !== String(assignment?.space_id ?? ''), label: `${s.label} (${s.property}) · ${s.ownership === 'borrowed' ? `third-party${s.owner ? `: ${s.owner}` : ''}` : 'condo-owned'}${s.free ? ' · free' : ` · ${s.reason}`}` }))} />
                        </Field>
                        <Field label="Assignment status" required error={e.status} help="Separate from payment: a guest can pay before the stall is confirmed.">
                            <Select value={d.status} onChange={(ev) => form.setData('status', ev.target.value)} options={statusOptions} />
                        </Field>
                    </div>
                    {spaces && (
                        <p className="text-xs text-muted">{spaces.filter((s) => s.free).length} of {spaces.length} stalls free for {nights ? `these ${nights} night${nights === 1 ? '' : 's'}` : 'the dates'}. {d.status !== 'assigned' ? 'Until it is assigned, no stall is held.' : 'Assigning blocks the stall for these dates.'}</p>
                    )}
                    {needsOwnerCall && (
                        <label className="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                            <input type="checkbox" className="mt-0.5" checked={d.owner_confirmed} onChange={(ev) => form.setData('owner_confirmed', ev.target.checked)} />
                            <span>I confirmed with {space?.owner || 'the owner'} that this third-party stall is free for these dates.{e.owner_confirmed && <span role="alert" className="block text-xs text-red-800">{e.owner_confirmed}</span>}</span>
                        </label>
                    )}
                </fieldset>

                <div className="flex justify-end gap-2">
                    <Button onClick={onClose}>Close</Button>
                    <Button type="submit" variant="primary" disabled={form.processing}>{form.processing ? 'Saving…' : editing ? 'Save changes' : 'Save parking'}</Button>
                </div>
            </form>
        </Modal>
    );
}

/** A payment for one parking: a receipt tagged to it, so parking is collected apart from the stay. */
export function ParkingPaymentForm({ parking, methods, nowLocal, onClose }: { parking: any; methods: { value: string; label: string }[]; nowLocal: string; onClose: () => void }) {
    const left = Math.max(0, parking.balance_cents - parking.pending_cents);
    const form = useForm<any>({ parking_assignment_id: parking.id, received_at: nowLocal, amount: left ? (left / 100).toFixed(2) : '', method: 'cash', external_reference: '', evidence: null, confirm_unusual: false });
    const e = form.errors as any;

    return (
        <Modal open title={`Parking payment: ${parking.guest}`} onClose={onClose}>
            <form className="space-y-3" onSubmit={(ev) => { ev.preventDefault(); form.post('/admin/receipts', { forceFormData: true, preserveScroll: true, onSuccess: onClose }); }}>
                <dl className="grid grid-cols-3 gap-2 rounded-lg bg-ivory p-3 text-sm">
                    <div><dt className="text-xs text-muted">Parking fee</dt><dd className="font-semibold tabular-nums">{peso(parking.fee_cents)}</dd></div>
                    <div><dt className="text-xs text-muted">Paid</dt><dd className="font-semibold tabular-nums">{peso(parking.paid_cents)}</dd></div>
                    <div><dt className="text-xs text-muted">Left to pay</dt><dd className="font-semibold tabular-nums">{peso(left)}</dd></div>
                </dl>
                {parking.pending_cents > 0 && <p className="text-xs text-amber-900">{peso(parking.pending_cents)} is already recorded and waiting to be verified.</p>}
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Amount (PHP)" required error={e.amount}><Input required autoFocus inputMode="decimal" value={form.data.amount} onChange={(ev) => form.setData('amount', ev.target.value)} /></Field>
                    <Field label="Method" required error={e.method}><Select value={form.data.method} onChange={(ev) => form.setData('method', ev.target.value)} options={methods} /></Field>
                </div>
                <Field label="Received" required error={e.received_at}><Input type="datetime-local" max={nowLocal} value={form.data.received_at} onChange={(ev) => form.setData('received_at', ev.target.value)} /></Field>
                <Field label="GCash or bank reference" error={e.external_reference}><Input value={form.data.external_reference} onChange={(ev) => form.setData('external_reference', ev.target.value)} /></Field>
                <Field label="Proof (optional)" error={e.evidence}><input type="file" accept="image/*,application/pdf" className="w-full text-sm" onChange={(ev) => form.setData('evidence', ev.target.files?.[0] ?? null)} /></Field>
                {e.confirm_unusual && (
                    <label className="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                        <input type="checkbox" className="mt-0.5" checked={form.data.confirm_unusual} onChange={(ev) => form.setData('confirm_unusual', ev.target.checked)} />
                        <span>{e.confirm_unusual}</span>
                    </label>
                )}
                <p className="text-xs text-muted">Partial payments are fine; the balance updates once the payment is verified. It does not change the stay's own payments.</p>
                <div className="flex justify-end gap-2">
                    <Button onClick={onClose}>Close</Button>
                    <Button type="submit" variant="primary" disabled={form.processing}>{form.processing ? 'Saving…' : 'Record payment'}</Button>
                </div>
            </form>
        </Modal>
    );
}

/** Keep a stall out of use for some dates (owner using it, maintenance), or change or remove such a block. */
export function BlockForm({ block, spaces, reasons, prefill, onClose }: { block?: any; spaces: any[]; reasons: { value: string; label: string }[]; prefill?: { space_id?: number; start?: string }; onClose: () => void }) {
    const form = useForm<any>({
        parking_space_id: block?.space_id ?? prefill?.space_id ?? '', start_date: block?.start ?? prefill?.start ?? '', end_date: block?.end ?? '', reason: block?.reason ?? 'owner_use', note: block?.note ?? '',
    });
    const e = form.errors as any;

    return (
        <Modal open title={block ? 'Edit block' : 'Block a stall'} onClose={onClose}>
            <form className="space-y-3" onSubmit={(ev) => { ev.preventDefault(); form.post(block ? `/admin/parking/blocks/${block.id}` : '/admin/parking/blocks', { preserveScroll: true, onSuccess: onClose }); }}>
                <Field label="Stall" required error={e.parking_space_id}><Select required placeholder="Select…" value={form.data.parking_space_id} onChange={(ev) => form.setData('parking_space_id', ev.target.value)} options={spaces.map((s) => ({ value: s.id, label: `${s.code}${s.name ? ` · ${s.name}` : ''} (${s.property})` }))} /></Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="From" required error={e.start_date}><Input type="date" required value={form.data.start_date} onChange={(ev) => form.setData('start_date', ev.target.value)} /></Field>
                    <Field label="Free again on" required error={e.end_date}><Input type="date" required min={form.data.start_date || undefined} value={form.data.end_date} onChange={(ev) => form.setData('end_date', ev.target.value)} /></Field>
                </div>
                <Field label="Reason" required error={e.reason}><Select value={form.data.reason} onChange={(ev) => form.setData('reason', ev.target.value)} options={reasons} /></Field>
                <Field label="Note" error={e.note}><Input value={form.data.note} onChange={(ev) => form.setData('note', ev.target.value)} /></Field>
                <div className="flex flex-wrap justify-end gap-2">
                    {block && <Button variant="danger" className="mr-auto" onClick={() => router.post(`/admin/parking/blocks/${block.id}/delete`, {}, { preserveScroll: true, onSuccess: onClose })}>Remove block</Button>}
                    <Button onClick={onClose}>Close</Button>
                    <Button type="submit" variant="primary" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save block'}</Button>
                </div>
            </form>
        </Modal>
    );
}
