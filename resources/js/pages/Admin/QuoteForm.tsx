import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Loader2 } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { Button, Card, cn, copyToClipboard, Field, Input, SearchSelect, Select, StatusChip } from '@/components/ui';
import { peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

const nextDay = (date: string) => new Date(Date.parse(`${date}T00:00:00Z`) + 86400000).toISOString().slice(0, 10);
const nightsBetween = (from: string, to: string) => from && to && to > from ? Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000) : 0;
const minuteBefore = (time: string) => {
    const [hours, minutes] = time.split(':').map(Number);
    const total = Math.max(0, hours * 60 + minutes - 1);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
};

export default function QuoteForm({ rooms, sources, guests: guestList, filters, preview, vacancy, bookingId, parkingRate, today, standardCheckIn }: any) {
    const permissions: string[] = (usePage<any>().props as any).auth.user?.permissions ?? [];
    const canDiscount = permissions.includes('bookings.discount');
    const form = useForm<any>({
        room_id: filters.room_id ?? '', check_in: filters.check_in ?? '', check_out: filters.check_out ?? '', guests: filters.guests ?? 2,
        early_time: filters.early_time ?? '', source_id: filters.source_id ?? '', discount: filters.discount ?? '', parking_nights: filters.parking_nights ?? '', parking_rate: filters.parking_rate ?? parkingRate,
        guest_mode: filters.guest_id ? 'existing' : 'new', guest_id: filters.guest_id ?? '', guest_name: filters.guest_id ? '' : (filters.guest_name ?? ''), guest_phone: '', guest_email: '', guest_messenger_handle: '', booking_id: bookingId ?? '', reference: filters.reference ?? '',
    });
    const e = form.errors as any;
    const set = (k: string) => (ev: any) => form.setData(k, ev.target.value);
    const chooseCheckIn = (value: string) => form.setData((current: any) => ({
        ...current,
        check_in: value,
        check_out: value && (!current.check_out || current.check_out <= value) ? nextDay(value) : current.check_out,
    }));

    const [searching, setSearching] = useState(false);
    const pickedRoom = useRef(false);

    function search(data: any, onSuccess?: () => void) {
        const { guest_mode, guest_id, guest_name, guest_phone, guest_email, guest_messenger_handle, ...query } = data;
        if (guest_mode === 'existing' && guest_id) query.guest_id = guest_id;
        router.get('/admin/quotes/create', query, { preserveState: true, preserveScroll: true, replace: true, onSuccess, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    }

    // The vacant list follows the form: choosing dates is all it takes, with no button to press.
    const { room_id, check_in, check_out, guests, early_time, source_id, discount, parking_nights, parking_rate } = form.data;
    const stayNights = nightsBetween(check_in, check_out);
    const parkingCount = Number(parking_nights || 0);
    const parkingOptions = Array.from({ length: stayNights }, (_, index) => ({ value: index + 1, label: `${index + 1} night${index === 0 ? '' : 's'}` }));
    const firstRender = useRef(true);

    // Shortening a stay also shortens parking, so an old value cannot become an accidental overcharge.
    useEffect(() => {
        if (parkingCount > stayNights) form.setData('parking_nights', stayNights > 0 ? String(stayNights) : '');
    }, [parkingCount, stayNights]);
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        if (pickedRoom.current) {
            pickedRoom.current = false;
            return;
        }
        if (!check_in || !check_out || check_in >= check_out || !guests) return;
        const timer = setTimeout(() => search(form.data), 350);

        return () => clearTimeout(timer);
    }, [room_id, check_in, check_out, guests, early_time, source_id, discount, parking_nights, parking_rate]);

    function calculate(ev: FormEvent) {
        ev.preventDefault();
        search(form.data);
    }

    function pick(id: number) {
        pickedRoom.current = true;
        form.setData('room_id', id);
        search({ ...form.data, room_id: id }, () => document.getElementById('estimate')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }

    const room = rooms.find((r: any) => String(r.id) === String(form.data.room_id));
    const pricedFields = ['room_id', 'check_in', 'check_out', 'guests', 'early_time', 'source_id', 'discount', 'parking_nights', 'parking_rate'];
    const previewCurrent = pricedFields.every((key) => String(filters[key] ?? '') === String(form.data[key] ?? ''));
    const ready = preview && !preview.error && previewCurrent && !searching;
    const takenIds = (vacancy?.unavailable ?? []).map((u: any) => u.id);

    const existingGuest = form.data.guest_mode === 'existing';
    const chosenGuest = guestList.find((g: any) => String(g.id) === String(form.data.guest_id));
    const guestName = (existingGuest ? chosenGuest?.full_name ?? '' : form.data.guest_name).trim();
    const guestReady = existingGuest ? !!form.data.guest_id : !!form.data.guest_name.trim();
    const chooseExisting = (checked: boolean) => form.setData((d: any) => ({ ...d, guest_mode: checked ? 'existing' : 'new', ...(checked ? { guest_name: '', guest_phone: '', guest_email: '', guest_messenger_handle: '' } : { guest_id: '' }) }));

    // The guest's name can be typed after the price is worked out. Staff may still reword it before copying.
    const messageBox = useRef<HTMLTextAreaElement>(null);
    const [message, setMessage] = useState('');
    const [copy, setCopy] = useState<{ state: 'idle' | 'checking' | 'copied' | 'manual' | 'taken'; detail?: string }>({ state: 'idle' });
    useEffect(() => {
        setMessage((preview?.copyText ?? '').replaceAll('{{guest}}', guestName || 'there'));
        setCopy({ state: 'idle' });
    }, [preview?.copyText, guestName]);

    async function copyMessage() {
        if (!ready) return;
        setCopy({ state: 'checking' });
        const query = new URLSearchParams({ room_id: String(form.data.room_id), check_in: form.data.check_in, check_out: form.data.check_out, guests: String(form.data.guests ?? 1), ...(bookingId ? { booking_id: String(bookingId) } : {}) });
        const live = await fetch(`/admin/quotes/check?${query}`, { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : null)).catch(() => null);
        if (!live) {
            setCopy({ state: 'taken', detail: 'We could not check these dates, so nothing was copied. Please try again.' });
            return;
        }
        if (!live.available) {
            setCopy({ state: 'taken', detail: `Not copied. ${live.message}` });
            return;
        }
        if (await copyToClipboard(message)) {
            setCopy({ state: 'copied' });
            return;
        }
        messageBox.current?.select();
        setCopy({ state: 'manual' });
    }

    return (
        <AdminLayout title={filters.reference ? `Update quote ${filters.reference}` : 'Quotation calculator'} subtitle={filters.reference ? 'Change anything below. Saving makes a new version with the same reference, and the earlier versions are kept.' : undefined}>
            <div className="grid gap-4 lg:grid-cols-2">
                <div className="space-y-4">
                    <Card title="Guest">
                        <div className="space-y-3">
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={existingGuest} onChange={(ev) => chooseExisting(ev.target.checked)} /> Existing guest</label>
                            {existingGuest ? (
                                <>
                                    <Field label="Guest" required error={e.guest_id}>
                                        <SearchSelect aria-label="Guest" placeholder={`Select a guest (${guestList.length})`} searchPlaceholder="Search name, phone or Messenger" empty="No guest matches. Untick Existing guest to add a new one."
                                            options={guestList.map((g: any) => ({ value: g.id, label: g.full_name, hint: g.contact ?? undefined }))} value={form.data.guest_id} onChange={(v) => form.setData('guest_id', v)} />
                                    </Field>
                                </>
                            ) : (
                                <>
                                    <Field label="Full name" required error={e.guest_name}><Input autoComplete="off" value={form.data.guest_name} onChange={set('guest_name')} /></Field>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Field label="Phone" error={e.guest_phone}><Input type="tel" value={form.data.guest_phone} onChange={set('guest_phone')} /></Field>
                                        <Field label="Email" error={e.guest_email}><Input type="email" value={form.data.guest_email} onChange={set('guest_email')} /></Field>
                                    </div>
                                    <Field label="Messenger name or profile" error={e.guest_messenger_handle} help="Saved to Guests with the quote, so a booking made from it is already filled in."><Input value={form.data.guest_messenger_handle} onChange={set('guest_messenger_handle')} /></Field>
                                </>
                            )}
                        </div>
                    </Card>

                    <Card title="Stay details">
                        <form onSubmit={calculate} className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Check-in" required error={e.check_in}><Input type="date" required min={today} value={form.data.check_in} onChange={(ev) => chooseCheckIn(ev.target.value)} /></Field>
                                <Field label="Check-out" required error={e.check_out}><Input type="date" required min={form.data.check_in ? nextDay(form.data.check_in) : today} value={form.data.check_out} onChange={set('check_out')} /></Field>
                            </div>
                            {stayNights > 0 && <p className="rounded-md bg-ivory px-3 py-2 text-sm font-medium" role="status">Stay length: {stayNights} night{stayNights === 1 ? '' : 's'}</p>}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Guests" required error={e.guests} help={room ? `Includes ${room.included}; this room allows at most ${room.max}.` : 'Choose the total number of adults and children.'}><Input type="number" min={1} max={room?.max ?? 30} step={1} value={form.data.guests} onChange={set('guests')} /></Field>
                                <Field label="Requested early check-in" error={e.early_time} help={`Only choose a time before the standard ${standardCheckIn} check-in. Fee is per started hour.`}><Input type="time" max={minuteBefore(standardCheckIn)} value={form.data.early_time} onChange={set('early_time')} /></Field>
                            </div>
                            <Field label="Booking source" error={e.source_id} help="Applies any channel-specific rate">
                                <Select placeholder="None" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={form.data.source_id} onChange={set('source_id')} />
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Parking" error={e.parking_nights} help={stayNights > 0 ? `Cannot exceed this ${stayNights}-night stay.` : 'Choose valid stay dates first.'}>
                                    <Select placeholder="No parking" options={parkingOptions} disabled={stayNights === 0} value={form.data.parking_nights} onChange={set('parking_nights')} />
                                </Field>
                                <Field label="Parking rate per night (PHP)" error={e.parking_rate} help={parkingCount > 0 ? `${parkingCount} night${parkingCount === 1 ? '' : 's'} selected.` : 'Enabled after parking is selected.'}><Input type="number" min={0.01} step="0.01" inputMode="decimal" disabled={parkingCount === 0} value={form.data.parking_rate} onChange={set('parking_rate')} /></Field>
                            </div>
                            {canDiscount && <Field label="Authorized discount (PHP)" error={e.discount} help="Enter only an approved peso amount."><Input type="number" min={0} step="0.01" inputMode="decimal" value={form.data.discount} onChange={set('discount')} /></Field>}
                            <Field label="Unit" error={e.room_id} help="Optional. Leave as is to choose from the vacant list.">
                                <Select placeholder="Choose from the vacant list" options={rooms.map((r: any) => ({ value: r.id, label: takenIds.includes(r.id) ? `${r.label} - not available` : r.label, disabled: takenIds.includes(r.id) }))} value={form.data.room_id} onChange={set('room_id')} />
                            </Field>
                            <p className="flex items-center gap-2 text-sm text-muted" role="status">
                                {searching ? <><Loader2 size={15} className="animate-spin" aria-hidden /> Finding vacant units…</> : check_in && check_out && check_in >= check_out ? 'Check-out must be after check-in.' : !check_in || !check_out ? 'Choose your dates and the vacant units appear by themselves.' : 'The vacant units below update as you change anything.'}
                            </p>
                        </form>
                    </Card>

                    {vacancy && (
                        <Card title={`Vacant units (${vacancy.vacant.length})`}>
                            {vacancy.pricing_pending && <p role="alert" className="mb-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">Prices cannot be shown yet: {vacancy.pricing_pending}</p>}
                            {vacancy.vacant.length === 0 ? <p className="text-sm text-muted">No unit is vacant for these dates and guests.</p> : (
                                <ul className="divide-y divide-line">
                                    {vacancy.vacant.map((u: any) => (
                                        <li key={u.id} className={cn('flex flex-wrap items-center justify-between gap-2 py-2.5', String(form.data.room_id) === String(u.id) && '-mx-2 rounded bg-beige px-2')}>
                                            <div className="min-w-0">
                                                <div className="font-medium">{u.name} <span className="text-xs font-normal text-muted">{u.unit} · {u.property}</span></div>
                                                <div className="text-xs text-muted">{u.type} · includes {u.included}, maximum {u.max}{u.housekeeping !== 'ready' ? ' · ' : ''}{u.housekeeping !== 'ready' && <StatusChip value={u.housekeeping} />}</div>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                {u.total_cents != null && <div className="text-right"><div className="font-semibold tabular-nums">{peso(u.total_cents)}</div><div className="text-xs text-muted">{u.nights} night{u.nights === 1 ? '' : 's'}</div></div>}
                                                <Button small variant={String(form.data.room_id) === String(u.id) ? 'secondary' : 'primary'} disabled={u.total_cents == null} onClick={() => pick(u.id)}>{String(form.data.room_id) === String(u.id) ? 'Selected' : 'Quote this'}</Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {vacancy.unavailable.length > 0 && (
                                <details open className="mt-3 text-sm">
                                    <summary className="cursor-pointer text-muted">Not available, cannot be quoted ({vacancy.unavailable.length})</summary>
                                    <ul className="mt-2 space-y-1.5">
                                        {vacancy.unavailable.map((u: any) => <li key={u.id}><span className="font-medium">{u.name}</span> <span className="text-xs text-muted">{u.reason}</span></li>)}
                                    </ul>
                                </details>
                            )}
                            <p className="mt-3 text-xs text-muted">Vacant right now. This does not reserve anything: place a hold on a booking to do that. Airbnb bookings not yet entered here will not show.</p>
                        </Card>
                    )}
                </div>

                <div id="estimate" className="scroll-mt-16">
                    <Card title={room ? `Estimate: ${room.label}` : 'Estimate'}>
                        {!preview && <p className="text-sm text-muted">Enter the dates and guests, then choose a vacant unit.</p>}
                        {preview && !previewCurrent && <p role="status" className="flex items-center gap-2 rounded-md bg-ivory px-3 py-2 text-sm text-muted"><Loader2 size={15} className="animate-spin" aria-hidden /> Updating this estimate…</p>}
                        {preview?.error && <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-900">{preview.error}</p>}
                        {ready && (
                            <>
                                <table className="w-full text-sm">
                                    <tbody>
                                        {preview.lines.map((l: any, i: number) => (
                                            <tr key={i} className="border-b border-line"><td className="py-1.5 pr-3">{l.description}</td><td className="py-1.5 text-right tabular-nums">{peso(l.amount_cents)}</td></tr>
                                        ))}
                                        <tr className="font-semibold"><td className="py-2">Total</td><td className="py-2 text-right tabular-nums">{peso(preview.total_cents)}</td></tr>
                                        {preview.downpayment_percent && <tr className="border-t border-line"><td className="py-1.5">{preview.downpayment_percent}% downpayment</td><td className="py-1.5 text-right tabular-nums">{peso(preview.downpayment_cents)}</td></tr>}
                                        {preview.downpayment_percent && <tr className="border-t border-line"><td className="py-1.5">Balance upon check-in</td><td className="py-1.5 text-right tabular-nums">{peso(preview.balance_cents)}</td></tr>}
                                    </tbody>
                                </table>
                                {preview.warnings.map((w: string) => <p key={w} className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">{w}</p>)}
                                <form onSubmit={(ev) => { ev.preventDefault(); form.transform((d: any) => (d.guest_mode === 'existing' ? { ...d, guest_name: '', guest_phone: '', guest_email: '', guest_messenger_handle: '' } : { ...d, guest_id: '' })); form.post('/admin/quotes'); }} className="mt-4 space-y-3 border-t border-line pt-3">
                                    <Field label="Message for Messenger" help="You can change the wording before copying.">
                                        <textarea ref={messageBox} rows={20} value={message} onChange={(ev) => setMessage(ev.target.value)} className="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm shadow-xs focus:border-gold-dark focus:ring-2 focus:ring-gold/30 focus:outline-none pointer-coarse:text-base" />
                                    </Field>
                                    <div className="flex flex-wrap gap-2">
                                        <Button variant="primary" disabled={!ready || copy.state === 'checking'} onClick={copyMessage}>
                                            {copy.state === 'checking' ? <Loader2 size={15} className="animate-spin" aria-hidden /> : copy.state === 'copied' ? <Check size={15} aria-hidden /> : <Copy size={15} aria-hidden />}
                                            {copy.state === 'checking' ? 'Checking dates…' : copy.state === 'copied' ? 'Copied' : 'Copy for Messenger'}
                                        </Button>
                                        <Button type="submit" disabled={!ready || !guestReady || form.processing}>Save quote version</Button>
                                    </div>
                                    {!guestReady && <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">{existingGuest ? 'Select the guest' : "Enter the guest's name"} above to save this quote.</p>}
                                    <div role="status">
                                        {copy.state === 'copied' && <p className="rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-950">Copied. Paste it into Messenger with Ctrl+V. Press Save quote version to keep a record of it.</p>}
                                        {copy.state === 'manual' && <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">Your browser blocked copying. The message is selected above: press Ctrl+C.</p>}
                                        {copy.state === 'taken' && <p className="rounded-md bg-red-50 px-3 py-2 text-xs text-red-900">{copy.detail}</p>}
                                    </div>
                                    <p className="text-xs text-muted">A quote does not reserve the room, so two guests can be quoted the same dates. Dates are only secured when you place a hold or confirm the booking, and the system refuses any overlap.</p>
                                </form>
                            </>
                        )}
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
