import { router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Button, Card, Field, Input, Select } from '@/components/ui';
import { peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function QuoteForm({ rooms, sources, filters, preview, bookingId }: any) {
    const form = useForm<any>({
        room_id: filters.room_id ?? '', check_in: filters.check_in ?? '', check_out: filters.check_out ?? '', guests: filters.guests ?? 2,
        early_time: filters.early_time ?? '', source_id: filters.source_id ?? '', discount: filters.discount ?? '', parking: filters.parking ?? '',
        guest_name: '', booking_id: bookingId ?? '',
    });
    const e = form.errors as any;
    const set = (k: string) => (ev: any) => form.setData(k, ev.target.value);

    function calculate(ev: FormEvent) {
        ev.preventDefault();
        const { guest_name, ...query } = form.data;
        router.get('/admin/quotes/create', query, { preserveState: true, preserveScroll: true, replace: true });
    }

    const room = rooms.find((r: any) => String(r.id) === String(form.data.room_id));
    const ready = preview && !preview.error;

    return (
        <AdminLayout title="Quotation calculator">
            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Stay details">
                    <form onSubmit={calculate} className="space-y-3">
                        <Field label="Room" required error={e.room_id}>
                            <Select placeholder="Select…" options={rooms.map((r: any) => ({ value: r.id, label: r.label }))} value={form.data.room_id} onChange={set('room_id')} />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Check-in" required error={e.check_in}><Input type="date" value={form.data.check_in} onChange={set('check_in')} /></Field>
                            <Field label="Check-out" required error={e.check_out}><Input type="date" value={form.data.check_out} onChange={set('check_out')} /></Field>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Guests" required error={e.guests} help={room ? `Includes ${room.included}, maximum ${room.max}` : undefined}><Input type="number" min={1} value={form.data.guests} onChange={set('guests')} /></Field>
                            <Field label="Requested early check-in" error={e.early_time} help="₱ per started hour before standard check-in"><Input type="time" value={form.data.early_time} onChange={set('early_time')} /></Field>
                        </div>
                        <Field label="Booking source" error={e.source_id} help="Applies any channel-specific rate">
                            <Select placeholder="None" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={form.data.source_id} onChange={set('source_id')} />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Parking (PHP)" error={e.parking}><Input inputMode="decimal" value={form.data.parking} onChange={set('parking')} /></Field>
                            <Field label="Authorized discount (PHP)" error={e.discount}><Input inputMode="decimal" value={form.data.discount} onChange={set('discount')} /></Field>
                        </div>
                        <Button type="submit">Calculate</Button>
                    </form>
                </Card>

                <Card title="Estimate">
                    {!preview && <p className="text-sm text-muted">Choose a room and dates, then calculate.</p>}
                    {preview?.error && <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-900">{preview.error}</p>}
                    {ready && (
                        <>
                            <table className="w-full text-sm">
                                <tbody>
                                    {preview.lines.map((l: any, i: number) => (
                                        <tr key={i} className="border-b border-line"><td className="py-1.5 pr-3">{l.description}</td><td className="py-1.5 text-right tabular-nums">{peso(l.amount_cents)}</td></tr>
                                    ))}
                                    <tr className="font-semibold"><td className="py-2">Total</td><td className="py-2 text-right tabular-nums">{peso(preview.total_cents)}</td></tr>
                                </tbody>
                            </table>
                            {preview.warnings.map((w: string) => <p key={w} className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">{w}</p>)}
                            {preview.conflicts?.length > 0 && <p role="alert" className="mt-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-900">Currently unavailable: {preview.conflicts.join(', ')}</p>}
                            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/admin/quotes'); }} className="mt-4 space-y-2 border-t border-line pt-3">
                                <Field label="Guest name (optional)" error={e.guest_name}><Input value={form.data.guest_name} onChange={set('guest_name')} /></Field>
                                <Button type="submit" variant="primary" disabled={form.processing}>Save quote version</Button>
                                <p className="text-xs text-muted">Saving does not reserve the room. Place a hold on the booking to do that.</p>
                            </form>
                        </>
                    )}
                </Card>
            </div>
        </AdminLayout>
    );
}
