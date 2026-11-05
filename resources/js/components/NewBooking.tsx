import { useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { nextDay, RoomPicker, useRoomAvailability } from '@/components/RoomPicker';
import { Button, Field, Input, Modal, Select } from '@/components/ui';

type Prefill = { room_id?: number | string; check_in?: string; check_out?: string };
type Options = { rooms: any[]; sources: any[]; guests: any[] };

const OPEN_EVENT = 'uniqa:new-booking';

/** Opens the booking form over whatever page you are on. */
export function openNewBooking(prefill: Prefill = {}) {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT, { detail: prefill }));
}

function BookingFields({ options, prefill, onClose }: { options: Options; prefill: Prefill; onClose: () => void }) {
    const { rooms, sources, guests } = options;
    const perms: string[] = (usePage<any>().props as any).auth.user?.permissions ?? [];
    const canConfirm = perms.includes('bookings.confirm');
    const [existing, setExisting] = useState(false);
    const form = useForm<any>({
        guest_id: '', guest_name: '', guest_phone: '', guest_email: '',
        room_ids: prefill.room_id ? [Number(prefill.room_id)] : [],
        booking_source_id: '', source_reference: '',
        check_in: prefill.check_in ?? '', check_out: prefill.check_out ?? (prefill.check_in ? nextDay(prefill.check_in) : ''),
        adults: 2, children: 0, approved_early_time: '', special_requests: '', internal_notes: '',
        idempotency_key: useMemo(() => crypto.randomUUID?.() ?? String(Date.now()), []),
        then: '',
    });
    const e = form.errors as any;
    const availability = useRoomAvailability(form.data.check_in, form.data.check_out);
    const canSave = availability.state === 'ready' && form.data.room_ids.length > 0 && !form.processing;
    const isAirbnb = sources.find((s: any) => String(s.id) === String(form.data.booking_source_id))?.code === 'airbnb';

    const chooseCheckIn = (value: string) => form.setData((d: any) => ({ ...d, check_in: value, check_out: value && (!d.check_out || d.check_out <= value) ? nextDay(value) : d.check_out }));
    const selected = rooms.filter((r: any) => form.data.room_ids.includes(r.id));
    const capacity = selected.reduce((sum: number, r: any) => sum + r.max, 0);
    const party = Number(form.data.adults) + Number(form.data.children);

    function submit(event: FormEvent, then: string) {
        event.preventDefault();
        form.transform((d: any) => ({ ...d, then }));
        form.post('/admin/bookings', { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <form onSubmit={(ev) => submit(ev, canConfirm ? 'confirm' : '')} className="space-y-5">
            <div className="grid gap-5 md:grid-cols-2">
                <fieldset className="space-y-3">
                    <legend className="mb-1 text-sm font-semibold">Guest</legend>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={existing} onChange={(ev) => setExisting(ev.target.checked)} /> Existing guest</label>
                    {existing ? (
                        <Field label="Guest" required error={e.guest_id}>
                            <Select placeholder="Select…" options={guests.map((g: any) => ({ value: g.id, label: `${g.full_name}${g.phone ? ` (${g.phone})` : ''}` }))} value={form.data.guest_id} onChange={(ev) => form.setData('guest_id', ev.target.value)} />
                        </Field>
                    ) : (
                        <>
                            <Field label="Full name" required error={e.guest_name}><Input value={form.data.guest_name} onChange={(ev) => form.setData('guest_name', ev.target.value)} /></Field>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Phone" error={e.guest_phone}><Input value={form.data.guest_phone} onChange={(ev) => form.setData('guest_phone', ev.target.value)} /></Field>
                                <Field label="Email" error={e.guest_email}><Input type="email" value={form.data.guest_email} onChange={(ev) => form.setData('guest_email', ev.target.value)} /></Field>
                            </div>
                        </>
                    )}
                    <Field label="Booking source" required error={e.booking_source_id} help="Where the guest came from.">
                        <Select placeholder="Select…" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={form.data.booking_source_id} onChange={(ev) => form.setData('booking_source_id', ev.target.value)} />
                    </Field>
                    <Field label={isAirbnb ? 'Airbnb confirmation code' : 'Reservation or message reference'} error={e.source_reference}><Input value={form.data.source_reference} onChange={(ev) => form.setData('source_reference', ev.target.value)} /></Field>
                    {isAirbnb && <p className="rounded-lg bg-ivory px-3 py-2 text-xs text-muted">Already booked and paid on Airbnb, so use Save and confirm. Record the payout under Channel payouts when it arrives.</p>}
                </fieldset>

                <fieldset className="min-w-0 space-y-3">
                    <legend className="mb-1 text-sm font-semibold">Stay</legend>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Check-in" required error={e.check_in}><Input type="date" value={form.data.check_in} onChange={(ev) => chooseCheckIn(ev.target.value)} /></Field>
                        <Field label="Check-out" required error={e.check_out}><Input type="date" min={form.data.check_in ? nextDay(form.data.check_in) : undefined} value={form.data.check_out} onChange={(ev) => form.setData('check_out', ev.target.value)} /></Field>
                    </div>
                    <div className="grid grid-cols-3 gap-3">
                        <Field label="Adults" required error={e.adults}><Input type="number" min={1} value={form.data.adults} onChange={(ev) => form.setData('adults', ev.target.value)} /></Field>
                        <Field label="Children" error={e.children}><Input type="number" min={0} value={form.data.children} onChange={(ev) => form.setData('children', ev.target.value)} /></Field>
                        <Field label="Early time" error={e.approved_early_time}><Input type="time" value={form.data.approved_early_time} onChange={(ev) => form.setData('approved_early_time', ev.target.value)} /></Field>
                    </div>
                    <RoomPicker rooms={rooms} availability={availability} value={form.data.room_ids} onChange={(ids) => form.setData('room_ids', ids)} error={e.room_ids} />
                    {selected.length > 0 && party > capacity && <p role="alert" className="text-xs text-red-800">{party} guests exceeds the maximum of {capacity} for the selected room(s).</p>}
                </fieldset>
            </div>

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
