import { useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { nextDay, RoomPicker, useRoomAvailability } from '@/components/RoomPicker';
import { Button, confirmDialog, Field, Input, Modal, SearchSelect, Select } from '@/components/ui';
import { dateOnly, peso, time12 } from '@/lib/format';

type QuotedGuest = { id: number; full_name: string; phone: string | null; email: string | null; messenger_handle: string | null };
/** A quote passes everything it knows: the guest, party size, source and early check-in. */
type Prefill = { room_id?: number | string; check_in?: string; check_out?: string; guest?: QuotedGuest | null; guest_name?: string | null; adults?: number; source_id?: number | string | null; early_time?: string | null; quote_id?: number; note?: string; from?: string; parking_nights?: number; parking_rate?: string };
type Options = { rooms: any[]; sources: any[]; guests: any[]; today: string; standard_check_in: string; parking_rate?: string | null };

const OPEN_EVENT = 'uniqa:new-booking';
const addDays = (date: string, days: number) => new Date(Date.parse(`${date}T00:00:00Z`) + days * 86400000).toISOString().slice(0, 10);
const nightsBetween = (from: string, to: string) => from && to && to > from ? Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000) : 0;
const minuteBefore = (time: string) => {
    const [hours, minutes] = time.split(':').map(Number);
    const total = Math.max(0, hours * 60 + minutes - 1);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
};

/** Opens the booking form over whatever page you are on. */
export function openNewBooking(prefill: Prefill = {}) {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT, { detail: prefill }));
}

function BookingFields({ options, prefill, onClose }: { options: Options; prefill: Prefill; onClose: () => void }) {
    const { rooms, sources, today, standard_check_in: standardCheckIn } = options;
    // The quoted guest may be older than the recent list, so they are added to it.
    const guests = prefill.guest && !options.guests.some((g: any) => g.id === prefill.guest!.id) ? [prefill.guest, ...options.guests] : options.guests;
    const quotedSource = sources.some((s: any) => String(s.id) === String(prefill.source_id)) ? String(prefill.source_id) : '';
    const perms: string[] = (usePage<any>().props as any).auth.user?.permissions ?? [];
    const canConfirm = perms.includes('bookings.confirm');
    const [existing, setExisting] = useState(!!prefill.guest);
    const form = useForm<any>({
        guest_id: prefill.guest?.id ?? '', guest_name: prefill.guest ? '' : (prefill.guest_name ?? ''), guest_phone: '', guest_email: '', guest_messenger_handle: '',
        quote_id: prefill.quote_id ?? '',
        room_ids: prefill.room_id ? [Number(prefill.room_id)] : [],
        booking_source_id: quotedSource, source_reference: '', channel_total: '',
        check_in: prefill.check_in ?? '', check_out: prefill.check_out ?? (prefill.check_in ? nextDay(prefill.check_in) : ''),
        adults: prefill.adults ?? 2, children: 0, approved_early_time: prefill.early_time ?? '', pickup_time: '', return_time: '', special_requests: '', internal_notes: prefill.note ?? '',
        idempotency_key: useMemo(() => crypto.randomUUID?.() ?? String(Date.now()), []),
        then: '',
        // Parking asked for with the stay: saved as a request with its own fee, a stall is assigned later.
        parking: !!prefill.parking_nights, parking_start: prefill.check_in ?? '',
        parking_end: prefill.parking_nights && prefill.check_in ? addDays(prefill.check_in, prefill.parking_nights) : (prefill.check_out ?? ''),
        parking_rate: prefill.parking_rate ?? options.parking_rate ?? '', parking_vehicle: '',
    });
    const e = form.errors as any;
    const availability = useRoomAvailability(form.data.check_in, form.data.check_out);
    const selected = rooms.filter((r: any) => form.data.room_ids.includes(r.id));
    const selectedProperty = selected[0]?.property_id;
    const roomAvailability = useMemo(() => selectedProperty ? {
        ...availability,
        taken: {
            ...availability.taken,
            ...Object.fromEntries(rooms.filter((r: any) => r.property_id !== selectedProperty).map((r: any) => [r.id, `Choose a separate booking for ${r.property}.`])),
        },
    } : availability, [availability.state, availability.taken, availability.retry, selectedProperty, rooms]);
    const capacity = selected.reduce((sum: number, r: any) => sum + r.max, 0);
    // A Grab Cam unit is rented to one customer: no party size, early check-in or cleaning. Pickup and return times instead.
    const camera = selected[0]?.category === 'cameras';
    const party = camera ? 1 : Number(form.data.adults) + Number(form.data.children);
    const withinCapacity = selected.length > 0 && party <= capacity;
    const source = sources.find((s: any) => String(s.id) === String(form.data.booking_source_id));
    const nights = nightsBetween(form.data.check_in, form.data.check_out);
    // Airbnb sets its own price, so staff type the total from Airbnb instead of using the nightly rates.
    const airbnb = source?.code === 'airbnb' && !camera;
    const channelTotalReady = !airbnb || Number(form.data.channel_total) > 0;
    const hasContact = !!(form.data.guest_phone.trim() || form.data.guest_email.trim() || form.data.guest_messenger_handle.trim());
    const guestReady = existing ? !!form.data.guest_id : !!form.data.guest_name.trim() && hasContact;
    const sourceReady = !!form.data.booking_source_id;
    const earlyTimeValid = camera || !form.data.approved_early_time || form.data.approved_early_time < standardCheckIn;
    const parkingNights = nightsBetween(form.data.parking_start, form.data.parking_end);
    const parkingFee = Math.round(parkingNights * Number(form.data.parking_rate || 0) * 100);
    const parkingReady = camera || !form.data.parking || (parkingNights > 0 && Number(form.data.parking_rate) > 0);
    const canSave = availability.state === 'ready' && withinCapacity && guestReady && sourceReady && earlyTimeValid && channelTotalReady && parkingReady && !form.processing;

    // Parking follows the stay dates unless staff changed them.
    const chooseCheckIn = (value: string) => form.setData((d: any) => {
        const out = value && (!d.check_out || d.check_out <= value) ? nextDay(value) : d.check_out;
        return { ...d, check_in: value, check_out: out, ...(d.parking_start === d.check_in && d.parking_end === d.check_out ? { parking_start: value, parking_end: out } : {}) };
    });
    const chooseCheckOut = (value: string) => form.setData((d: any) => ({ ...d, check_out: value, ...(d.parking_end === d.check_out ? { parking_end: value } : {}) }));
    const toggleExisting = (checked: boolean) => {
        setExisting(checked);
        form.setData((current: any) => checked
            ? { ...current, guest_name: '', guest_phone: '', guest_email: '', guest_messenger_handle: '' }
            : { ...current, guest_id: '' });
    };

    async function submit(event: FormEvent, then: string) {
        event.preventDefault();
        if (then) {
            const action = then === 'confirm' ? 'Confirm booking' : 'Place hold';
            const message = camera
                ? `${selected.map((room: any) => room.label).join(', ')}. Pickup ${dateOnly(form.data.check_in)}, return ${dateOnly(form.data.check_out)}, ${nights} day${nights === 1 ? '' : 's'}.`
                : `${selected.map((room: any) => room.label).join(', ')}. ${dateOnly(form.data.check_in)} to ${dateOnly(form.data.check_out)}, ${nights} night${nights === 1 ? '' : 's'}, ${party} guest${party === 1 ? '' : 's'}.`;
            if (!(await confirmDialog({ title: `${action}?`, message, confirmLabel: action }))) return;
        }
        form.transform((d: any) => camera
            ? { ...d, then, adults: 1, children: 0, approved_early_time: '', channel_total: '', parking: false }
            : { ...d, then, pickup_time: '', return_time: '', channel_total: airbnb ? d.channel_total : '' });
        form.post('/admin/bookings', { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <form onSubmit={(ev) => submit(ev, canConfirm ? 'confirm' : '')} className="space-y-5">
            {prefill.from && (
                <p role="status" className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-950">
                    Filled in from quote {prefill.from}.{' '}
                    {prefill.guest ? 'Check the details, then save.' : prefill.guest_name ? 'This quote has the guest name only: add a phone, email or Messenger name.' : 'This quote has no guest saved: enter the guest below.'}
                </p>
            )}
            <div className="grid gap-5 md:grid-cols-2">
                <fieldset className="space-y-3">
                    <legend className="mb-1 text-sm font-semibold">Guest</legend>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={existing} onChange={(ev) => toggleExisting(ev.target.checked)} /> Existing guest</label>
                    {existing ? (
                        <Field label="Guest" required error={e.guest_id}>
                            <SearchSelect aria-label="Guest" placeholder={`Select a guest (${guests.length})`} searchPlaceholder="Search name or phone" empty="No guest matches. Untick Existing guest to add a new one."
                                options={guests.map((g: any) => ({ value: g.id, label: g.full_name, hint: g.phone ?? undefined }))} value={form.data.guest_id} onChange={(v) => form.setData('guest_id', v)} />
                        </Field>
                    ) : (
                        <>
                            <Field label="Full name" required error={e.guest_name}><Input autoComplete="name" value={form.data.guest_name} onChange={(ev) => form.setData('guest_name', ev.target.value)} /></Field>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Phone" error={e.guest_phone}><Input type="tel" autoComplete="tel" value={form.data.guest_phone} onChange={(ev) => form.setData('guest_phone', ev.target.value)} /></Field>
                                <Field label="Email" error={e.guest_email}><Input type="email" autoComplete="email" value={form.data.guest_email} onChange={(ev) => form.setData('guest_email', ev.target.value)} /></Field>
                            </div>
                            <Field label="Messenger name or profile" error={e.guest_messenger_handle} help="Add at least one contact method: phone, email, or Messenger."><Input value={form.data.guest_messenger_handle} onChange={(ev) => form.setData('guest_messenger_handle', ev.target.value)} /></Field>
                            {!hasContact && <p role="alert" className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">Add a phone number, email, or Messenger profile before saving.</p>}
                        </>
                    )}
                    <Field label="Booking source" required error={e.booking_source_id} help="Where the guest came from.">
                        <Select placeholder="Select…" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={form.data.booking_source_id} onChange={(ev) => form.setData('booking_source_id', ev.target.value)} />
                    </Field>
                    {airbnb && (
                        <Field label="Total to pay on Airbnb (₱)" required error={e.channel_total} help="Copy the guest's total from the Airbnb reservation. The nightly rates here are not used for Airbnb bookings.">
                            <Input type="number" inputMode="decimal" min={0.01} step={0.01} value={form.data.channel_total} onChange={(ev) => form.setData('channel_total', ev.target.value)} />
                        </Field>
                    )}
                    <Field label="Reservation or message reference" error={e.source_reference} help="Optional for every booking source."><Input value={form.data.source_reference} onChange={(ev) => form.setData('source_reference', ev.target.value)} /></Field>
                </fieldset>

                <fieldset className="min-w-0 space-y-3">
                    <legend className="mb-1 text-sm font-semibold">{camera ? 'Rental' : 'Stay'}</legend>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label={camera ? 'Pickup date' : 'Check-in'} required error={e.check_in}><Input type="date" min={today} value={form.data.check_in} onChange={(ev) => chooseCheckIn(ev.target.value)} /></Field>
                        <Field label={camera ? 'Return date' : 'Check-out'} required error={e.check_out}><Input type="date" min={form.data.check_in ? nextDay(form.data.check_in) : today} value={form.data.check_out} onChange={(ev) => chooseCheckOut(ev.target.value)} /></Field>
                    </div>
                    {camera ? (
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Pickup time" error={e.pickup_time}><Input type="time" value={form.data.pickup_time} onChange={(ev) => form.setData('pickup_time', ev.target.value)} /></Field>
                            <Field label="Return time" error={e.return_time}><Input type="time" value={form.data.return_time} onChange={(ev) => form.setData('return_time', ev.target.value)} /></Field>
                        </div>
                    ) : (
                    <div className="grid grid-cols-3 gap-3">
                        <Field label="Adults" required error={e.adults}><Input type="number" min={1} max={30} step={1} value={form.data.adults} onChange={(ev) => form.setData('adults', ev.target.value)} /></Field>
                        <Field label="Children" error={e.children}><Input type="number" min={0} max={30} step={1} value={form.data.children} onChange={(ev) => form.setData('children', ev.target.value)} /></Field>
                        <Field label="Approved early check-in" error={e.approved_early_time} help={`Must be before ${time12(standardCheckIn)}.`}><Input type="time" max={minuteBefore(standardCheckIn)} value={form.data.approved_early_time} onChange={(ev) => form.setData('approved_early_time', ev.target.value)} /></Field>
                    </div>
                    )}
                    <RoomPicker rooms={rooms} availability={roomAvailability} value={form.data.room_ids} onChange={(ids) => form.setData('room_ids', ids)} error={e.room_ids} />
                    {selected.length > 0 && party > capacity && <p role="alert" className="text-xs text-red-800">{party} guests exceeds the maximum of {capacity} for the selected room(s).</p>}
                </fieldset>
            </div>

            {!camera && (
                <fieldset className="rounded-lg border border-line p-3">
                    <label className="flex items-center gap-2 text-sm font-semibold">
                        <input type="checkbox" checked={form.data.parking} onChange={(ev) => form.setData((d: any) => ({ ...d, parking: ev.target.checked, parking_start: d.parking_start || d.check_in, parking_end: d.parking_end || d.check_out }))} /> Add parking
                    </label>
                    {form.data.parking && (
                        <div className="mt-3 space-y-2">
                            <div className="grid gap-3 sm:grid-cols-4">
                                <Field label="Parking from" required error={e.parking_start}><Input type="date" value={form.data.parking_start} onChange={(ev) => form.setData('parking_start', ev.target.value)} /></Field>
                                <Field label="Until (car leaves)" required error={e.parking_end}><Input type="date" min={form.data.parking_start || undefined} value={form.data.parking_end} onChange={(ev) => form.setData('parking_end', ev.target.value)} /></Field>
                                <Field label="Rate per night (PHP)" required error={e.parking_rate}><Input type="number" inputMode="decimal" min={0.01} step={0.01} value={form.data.parking_rate} onChange={(ev) => form.setData('parking_rate', ev.target.value)} /></Field>
                                <Field label="Vehicle" error={e.parking_vehicle}><Input value={form.data.parking_vehicle} onChange={(ev) => form.setData('parking_vehicle', ev.target.value)} /></Field>
                            </div>
                            <p className="text-xs text-muted">
                                {parkingNights > 0 ? <><strong className="text-chocolate">{parkingNights} night{parkingNights === 1 ? '' : 's'} · {peso(parkingFee)}</strong>. </> : 'Choose the parking dates. '}
                                {airbnb ? 'Airbnb guests pay this here, apart from the Airbnb stay.' : 'Included in the quotation and the downpayment.'} The stall is assigned later from Parking; nothing is held until then.
                            </p>
                        </div>
                    )}
                </fieldset>
            )}

            {selected.length > 0 && nights > 0 && (
                <section aria-labelledby="booking-review" className="rounded-lg border border-line bg-ivory/70 p-4 text-sm">
                    <h3 id="booking-review" className="mb-2 font-semibold">Review before saving</h3>
                    <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                        <div><dt className="inline text-muted">Room: </dt><dd className="inline font-medium">{selected.map((room: any) => room.label).join(', ')}</dd></div>
                        <div><dt className="inline text-muted">Stay: </dt><dd className="inline font-medium">{dateOnly(form.data.check_in)} to {dateOnly(form.data.check_out)} · {nights} night{nights === 1 ? '' : 's'}</dd></div>
                        <div><dt className="inline text-muted">Guests: </dt><dd className="inline font-medium">{party} total · maximum {capacity}</dd></div>
                        <div><dt className="inline text-muted">Source: </dt><dd className="inline font-medium">{source?.name ?? 'Not selected'}</dd></div>
                        {airbnb && <div><dt className="inline text-muted">Airbnb total: </dt><dd className="inline font-medium">{Number(form.data.channel_total) > 0 ? `₱${Number(form.data.channel_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'Not entered'}</dd></div>}
                        <div><dt className="inline text-muted">Status after saving: </dt><dd className="inline font-medium">Choose Inquiry, Hold, or Confirm below</dd></div>
                        {form.data.approved_early_time && <div><dt className="inline text-muted">Early check-in: </dt><dd className="inline font-medium">{time12(form.data.approved_early_time)}</dd></div>}
                        {!camera && form.data.parking && parkingNights > 0 && <div><dt className="inline text-muted">Parking: </dt><dd className="inline font-medium">{parkingNights} night{parkingNights === 1 ? '' : 's'}, {peso(parkingFee)} (stall to be assigned)</dd></div>}
                    </dl>
                </section>
            )}

            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950"><strong>Important:</strong> “Save as inquiry” records the request but does not reserve the room. Use “Place hold” or “Save and confirm” to block these dates.</p>

            <details className="text-sm">
                <summary className="font-medium text-muted">Add notes</summary>
                <div className="mt-2 grid gap-3 md:grid-cols-2">
                    <Field label="Special requests" error={e.special_requests}><textarea rows={2} className="w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base" value={form.data.special_requests} onChange={(ev) => form.setData('special_requests', ev.target.value)} /></Field>
                    <Field label="Internal notes" error={e.internal_notes}><textarea rows={2} className="w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base" value={form.data.internal_notes} onChange={(ev) => form.setData('internal_notes', ev.target.value)} /></Field>
                </div>
            </details>

            <div className="sticky -bottom-5 -mx-5 -mb-5 grid grid-cols-2 gap-2 border-t border-line/70 bg-ivory px-5 py-3 sm:flex sm:justify-end">
                <Button onClick={onClose} className="mr-auto max-sm:hidden">Cancel</Button>
                <Button disabled={!canSave} onClick={(ev) => submit(ev, '')}>Save as inquiry</Button>
                <Button disabled={!canSave} onClick={(ev) => submit(ev, 'hold')}>Place hold</Button>
                {canConfirm && <Button type="submit" variant="primary" disabled={!canSave} className="max-sm:col-span-2">Save and confirm</Button>}
            </div>
        </form>
    );
}

/** Lives in the layout, so New Booking opens as a dialog from any page without leaving it. */
export default function NewBookingHost() {
    const [session, setSession] = useState<{ key: number; prefill: Prefill } | null>(null);
    const [options, setOptions] = useState<Options | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const onOpen = (event: Event) => {
            setFailed(false);
            setOptions(null);
            setSession({ key: Date.now(), prefill: (event as CustomEvent<Prefill>).detail ?? {} });
            axios.get('/admin/bookings/form-options').then((response) => setOptions(response.data)).catch(() => setFailed(true));
        };
        window.addEventListener(OPEN_EVENT, onOpen);

        // An old link to the booking form lands on Reservations with this in the address.
        const query = new URLSearchParams(window.location.search);
        if (query.get('new') === '1') {
            const prefill = { room_id: query.get('room_id') ?? undefined, check_in: query.get('check_in') ?? undefined, check_out: query.get('check_out') ?? undefined };
            window.history.replaceState(window.history.state, '', window.location.pathname);
            openNewBooking(prefill);
        }

        return () => window.removeEventListener(OPEN_EVENT, onOpen);
    }, []);

    if (!session) return null;
    const close = () => setSession(null);

    return (
        <Modal open wide title="New booking" onClose={close}>
            {failed ? (
                <p role="alert" className="py-6 text-center text-sm text-red-800">The form could not load. Please check your connection and try again.</p>
            ) : options ? (
                <BookingFields key={session.key} options={options} prefill={session.prefill} onClose={close} />
            ) : (
                <p role="status" className="flex items-center justify-center gap-2 py-10 text-sm text-muted"><Loader2 size={16} className="animate-spin" aria-hidden /> Loading…</p>
            )}
        </Modal>
    );
}
