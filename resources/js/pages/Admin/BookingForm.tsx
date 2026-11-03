import { useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import { Button, Card, Field, Input, Select } from '@/components/ui';
import AdminLayout from '@/layouts/AdminLayout';

export default function BookingForm({ rooms, sources, guests, prefill }: any) {
    const [existing, setExisting] = useState(false);
    const form = useForm<any>({
        guest_id: '', guest_name: '', guest_phone: '', guest_email: '',
        room_ids: prefill.room_id ? [Number(prefill.room_id)] : [],
        booking_source_id: '', source_reference: '',
        check_in: prefill.check_in ?? '', check_out: prefill.check_out ?? '',
        adults: 2, children: 0, approved_early_time: '', special_requests: '', internal_notes: '',
        idempotency_key: useMemo(() => crypto.randomUUID?.() ?? String(Date.now()), []),
        then: '',
    });
    const e = form.errors as any;
    const selected = rooms.filter((r: any) => form.data.room_ids.includes(r.id));
    const capacity = selected.reduce((sum: number, r: any) => sum + r.max, 0);
    const party = Number(form.data.adults) + Number(form.data.children);

    function submit(event: FormEvent, then: string) {
        event.preventDefault();
        form.transform((d: any) => ({ ...d, then }));
        form.post('/admin/bookings');
    }

    return (
        <AdminLayout title="New inquiry">
            <form onSubmit={(ev) => submit(ev, '')} className="grid gap-4 lg:grid-cols-2">
                <Card title="Guest">
                    <div className="space-y-3">
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
                        <Field label="Booking source" required error={e.booking_source_id} help="Where the guest came from. How they pay is recorded separately.">
                            <Select placeholder="Select…" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={form.data.booking_source_id} onChange={(ev) => form.setData('booking_source_id', ev.target.value)} />
                        </Field>
                        <Field label="External reservation or message reference" error={e.source_reference}><Input value={form.data.source_reference} onChange={(ev) => form.setData('source_reference', ev.target.value)} /></Field>
                    </div>
                </Card>

                <Card title="Stay">
                    <div className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Check-in" required error={e.check_in}><Input type="date" value={form.data.check_in} onChange={(ev) => form.setData('check_in', ev.target.value)} /></Field>
                            <Field label="Check-out" required error={e.check_out}><Input type="date" value={form.data.check_out} onChange={(ev) => form.setData('check_out', ev.target.value)} /></Field>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label="Adults" required error={e.adults}><Input type="number" min={1} value={form.data.adults} onChange={(ev) => form.setData('adults', ev.target.value)} /></Field>
                            <Field label="Children" error={e.children}><Input type="number" min={0} value={form.data.children} onChange={(ev) => form.setData('children', ev.target.value)} /></Field>
                            <Field label="Approved early time" error={e.approved_early_time}><Input type="time" value={form.data.approved_early_time} onChange={(ev) => form.setData('approved_early_time', ev.target.value)} /></Field>
                        </div>
                        <fieldset>
                            <legend className="mb-1 text-sm font-medium">Rooms <span className="text-red-800">*</span></legend>
                            <div className="grid max-h-52 gap-1 overflow-y-auto rounded-md border border-line p-2 text-sm">
                                {rooms.map((r: any) => (
                                    <label key={r.id} className="flex items-center gap-2">
                                        <input type="checkbox" checked={form.data.room_ids.includes(r.id)} onChange={(ev) => form.setData('room_ids', ev.target.checked ? [...form.data.room_ids, r.id] : form.data.room_ids.filter((x: number) => x !== r.id))} />
                                        <span>{r.label} <span className="text-xs text-muted">incl. {r.included}, max {r.max}</span></span>
                                    </label>
                                ))}
                            </div>
                            {e.room_ids && <p role="alert" className="mt-1 text-xs text-red-800">{e.room_ids}</p>}
                            {selected.length > 0 && party > capacity && <p role="alert" className="mt-1 text-xs text-red-800">{party} guests exceeds the maximum of {capacity} for the selected room(s).</p>}
                        </fieldset>
                    </div>
                </Card>

                <Card title="Notes" className="lg:col-span-2">
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="Special requests" error={e.special_requests}><textarea rows={3} className="w-full rounded-md border border-line px-3 py-2 text-sm" value={form.data.special_requests} onChange={(ev) => form.setData('special_requests', ev.target.value)} /></Field>
                        <Field label="Internal notes" error={e.internal_notes}><textarea rows={3} className="w-full rounded-md border border-line px-3 py-2 text-sm" value={form.data.internal_notes} onChange={(ev) => form.setData('internal_notes', ev.target.value)} /></Field>
                    </div>
                </Card>

                <div className="flex flex-wrap justify-end gap-2 lg:col-span-2">
                    <p className="mr-auto max-w-md text-xs text-muted">An inquiry does not block the room. A hold blocks it until the hold expires. Rooms are reserved only by an active hold or a confirmed booking.</p>
                    <Button type="submit" disabled={form.processing}>Save as inquiry</Button>
                    <Button variant="primary" disabled={form.processing} onClick={(ev) => submit(ev, 'hold')}>Save and place hold</Button>
                </div>
            </form>
        </AdminLayout>
    );
}
