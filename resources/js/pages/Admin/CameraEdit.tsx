import { Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { ActionButton, Button, buttonClass, Card, confirmDialog, Empty, Field, Input, Select, StatusChip } from '@/components/ui';
import { dateOnly, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

/** One Grab Cam unit: its own details and photos, its model's rate card, and dates it is out of service. */
export default function CameraEdit({ room, images, rates, blocks, defaultImage, can }: any) {
    const form = useForm<any>({
        name: room.name, unit_code: room.unit_code, specs: room.specs ?? '', description: room.description ?? '',
        min_nights: room.min_nights ?? '', max_advance_days: room.max_advance_days ?? '',
        operational_status: room.operational_status, operational_notes: room.operational_notes ?? '',
    });
    const e = form.errors as any;
    const set = (k: string) => (ev: any) => form.setData(k, ev.target.value);
    const area = 'w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base';
    const freeFrom = rates[0]?.free_day_from ?? 0;

    return (
        <AdminLayout title={`${room.name} · ${room.unit_code}`}>
            <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <span className="text-muted">{room.brand ? `${room.brand} · ` : ''}{room.model}</span>
                <StatusChip value={room.is_published ? 'published' : 'unpublished'} />
                <StatusChip value={room.operational_status} />
            </div>
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card title="Camera details">
                        <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/admin/rooms/${room.id}`, { preserveScroll: true }); }} className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Name" required error={e.name} help="Shown to staff. The public page shows the model name."><Input value={form.data.name} onChange={set('name')} /></Field>
                                <Field label="Unit code" required error={e.unit_code} help="Tells two units of the same model apart, such as N and O."><Input value={form.data.unit_code} onChange={set('unit_code')} /></Field>
                                <Field label="Kit or specs" error={e.specs} help="For example 15-45mm kit lens, Creator Combo, 256GB."><Input value={form.data.specs} onChange={set('specs')} /></Field>
                                <Field label="Status" error={e.operational_status}>
                                    <Select options={[{ value: 'active', label: 'Available to rent' }, { value: 'maintenance', label: 'Out for repair or cleaning' }, { value: 'retired', label: 'Retired (no longer rented)' }]} value={form.data.operational_status} onChange={set('operational_status')} />
                                </Field>
                                <Field label="Minimum rental (days)" error={e.min_nights} help="Blank: no minimum."><Input type="number" min={1} value={form.data.min_nights} onChange={set('min_nights')} /></Field>
                                <Field label="Book at most (days ahead)" error={e.max_advance_days} help="Blank: no limit."><Input type="number" min={1} value={form.data.max_advance_days} onChange={set('max_advance_days')} /></Field>
                            </div>
                            <Field label="Public description" error={e.description}><textarea rows={3} className={area} value={form.data.description} onChange={set('description')} /></Field>
                            <Field label="Internal notes" error={e.operational_notes} help="Condition, accessories, serial number. Never shown publicly."><textarea rows={3} className={area} value={form.data.operational_notes} onChange={set('operational_notes')} /></Field>
                            <Button type="submit" variant="primary" disabled={form.processing}>Save camera</Button>
                        </form>
                    </Card>

                    <Card title={`${room.model} rate card`} action={can.rates ? <Link href="/admin/camera-rates" className={buttonClass('secondary', true)}>Change rates</Link> : undefined}>
                        {rates.length === 0 ? <Empty>No rates set for this model.</Empty> : (
                            <table className="w-full text-sm">
                                <tbody>
                                    {rates.map((r: any) => (
                                        <tr key={r.min_days} className="border-t border-line first:border-0"><td className="py-1.5">{r.label}</td><td className="text-right tabular-nums">{peso(r.daily_cents)} per day</td></tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        <p className="mt-2 text-xs text-muted">{freeFrom > 0 ? `Rentals of ${freeFrom} days or more get the last day free. ` : ''}Every unit of this model shares these rates.</p>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card title="Out of service" action={
                        <ActionButton action={{ label: 'Block dates', url: `/admin/rooms/${room.id}/blocks`, fields: [
                            { name: 'start_date', label: 'From', type: 'date', required: true },
                            { name: 'end_date', label: 'Back on (exclusive)', type: 'date', required: true },
                            { name: 'kind', label: 'Why', type: 'select', required: true, options: [{ value: 'repair', label: 'Repair or servicing' }, { value: 'owner_use', label: 'Owner use' }, { value: 'technical', label: 'Other downtime' }] },
                            { name: 'reason', label: 'Details', type: 'text', required: true },
                        ] }} />
                    }>
                        {blocks.length === 0 ? <Empty>Available on every day nobody has rented.</Empty> : (
                            <ul className="space-y-2 text-sm">
                                {blocks.map((b: any) => (
                                    <li key={b.id} className="flex items-start justify-between gap-2">
                                        <span>{dateOnly(b.start)} to {dateOnly(b.end)}<span className="block text-xs text-muted">{b.kind}: {b.reason}</span></span>
                                        <ActionButton action={{ label: 'Release', url: `/admin/blocks/${b.id}/release`, confirm: 'Make this camera available on these dates again?' }} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card title="Photos" action={
                        <ActionButton action={{ label: 'Add photo', url: `/admin/rooms/${room.id}/images`, fields: [
                            { name: 'image', label: 'Photo of this camera', type: 'file', required: true, accept: 'image/*' },
                            { name: 'alt_text', label: 'Description for screen readers', type: 'text', required: true },
                        ] }} />
                    }>
                        {images.length === 0 ? (
                            <div>
                                <img src={defaultImage} alt="" className="aspect-[4/3] w-full rounded object-cover" />
                                <p className="mt-2 text-xs text-muted">No photo yet: the Grab Cam page shows this default image until you add one.</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-2">
                                {images.map((i: any) => (
                                    <figure key={i.id} className="relative">
                                        <img src={i.url} alt={i.alt} className="aspect-[4/3] w-full rounded object-cover" />
                                        <button aria-label="Delete photo" className="absolute top-1 right-1 rounded bg-white/90 p-1" onClick={async () => { if (await confirmDialog({ title: 'Delete this photo?', message: 'It will be removed from this camera, including on the Grab Cam page.', confirmLabel: 'Delete photo', danger: true })) router.post(`/admin/room-images/${i.id}/delete`, {}, { preserveScroll: true }); }}><Trash2 size={13} /></button>
                                    </figure>
                                ))}
                            </div>
                        )}
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
