import { router, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ActionButton, Button, Card, Empty, Field, Input, Select, StatusChip } from '@/components/ui';
import { dateOnly, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

function SecretCard({ room, secret, can }: any) {
    const [shown, setShown] = useState<any>(null);
    const [error, setError] = useState('');

    async function reveal() {
        setError('');
        const res = await fetch(`/admin/rooms/${room.id}/secret`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!res.ok) { setError('You are not authorized to view this.'); return; }
        setShown((await res.json()) ?? {});
    }

    return (
        <Card title="Wi-Fi and access (restricted)">
            <p className="mb-2 text-xs text-muted">Encrypted at rest. Each reveal is written to the audit log. Share with guests only through a staff message.</p>
            <p className="mb-2 text-sm">{secret.exists ? `On file${secret.last_verified_on ? `, last verified ${dateOnly(secret.last_verified_on)}` : ''}.` : 'Nothing on file yet.'}</p>
            {can.secretsReveal && secret.exists && (
                shown ? (
                    <div className="mb-2 space-y-1 rounded-md bg-beige p-3 text-sm">
                        <div>Network: {shown.network_name ?? '—'}</div>
                        <div>Password: {shown.password ?? '—'}</div>
                        <div>Troubleshooting: {shown.troubleshooting ?? '—'}</div>
                        <div>Contact: {shown.contact_person ?? '—'}</div>
                        <Button small onClick={() => setShown(null)}><EyeOff size={13} /> Hide</Button>
                    </div>
                ) : <Button small onClick={reveal}><Eye size={13} /> Reveal</Button>
            )}
            {error && <p role="alert" className="text-xs text-red-800">{error}</p>}
            {can.secretsManage && (
                <div className="mt-2">
                    <ActionButton action={{ label: secret.exists ? 'Rotate or update' : 'Add details', url: `/admin/rooms/${room.id}/secret`, fields: [
                        { name: 'network_name', label: 'Network name', type: 'text' },
                        { name: 'password', label: 'Password', type: 'text' },
                        { name: 'troubleshooting', label: 'Troubleshooting', type: 'textarea' },
                        { name: 'contact_person', label: 'Contact person', type: 'text' },
                        { name: 'last_verified_on', label: 'Last verified', type: 'date' },
                    ] }} />
                </div>
            )}
        </Card>
    );
}

export default function RoomEdit({ room, images, rates, overrides, blocks, sources, secret, can, amenities, listings }: any) {
    const selectedNames = amenities.filter((a: any) => room.amenity_ids.includes(a.id)).map((a: any) => a.name).join(', ');
    const form = useForm<any>({
        floor: room.floor ?? '', bedrooms: room.bedrooms, ac_count: room.ac_count, bathrooms: room.bathrooms, has_balcony: room.has_balcony,
        included_guests: room.included_guests, max_guests: room.max_guests, min_nights: room.min_nights ?? '', max_advance_days: room.max_advance_days ?? '', description: room.description ?? '', inclusions: room.inclusions ?? '', policies: room.policies ?? '',
        operational_notes: room.operational_notes ?? '', operational_status: room.operational_status, parking_eligible: room.parking_eligible, amenities_text: selectedNames,
    });
    const e = form.errors as any;
    const set = (k: string) => (ev: any) => form.setData(k, ev.target.value);
    const area = 'w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base';

    return (
        <AdminLayout title={`${room.name} · ${room.unit_code}`}>
            <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <span className="text-muted">{room.property} · {room.type}</span>
                <StatusChip value={room.catalog_status} />
                <StatusChip value={room.is_published ? 'published' : 'unpublished'} />
                <StatusChip value={room.housekeeping_status} />
            </div>
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card title="Room details">
                        <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/admin/rooms/${room.id}`, { preserveScroll: true }); }} className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Field label="Bedrooms" error={e.bedrooms}><Input type="number" min={0} value={form.data.bedrooms} onChange={set('bedrooms')} /></Field>
                                <Field label="AC units" error={e.ac_count}><Input type="number" min={0} value={form.data.ac_count} onChange={set('ac_count')} /></Field>
                                <Field label="Bathrooms" error={e.bathrooms}><Input type="number" min={0} value={form.data.bathrooms} onChange={set('bathrooms')} /></Field>
                                <Field label="Included guests" error={e.included_guests}><Input type="number" min={1} value={form.data.included_guests} onChange={set('included_guests')} /></Field>
                                <Field label="Maximum guests" error={e.max_guests}><Input type="number" min={1} value={form.data.max_guests} onChange={set('max_guests')} /></Field>
                                <Field label="Floor" error={e.floor}><Input value={form.data.floor} onChange={set('floor')} /></Field>
                                <Field label="Minimum stay (nights)" error={e.min_nights} help="Blank uses the Settings value."><Input type="number" min={1} value={form.data.min_nights} onChange={set('min_nights')} /></Field>
                                <Field label="Book at most (days ahead)" error={e.max_advance_days} help="Blank uses the Settings value."><Input type="number" min={1} value={form.data.max_advance_days} onChange={set('max_advance_days')} /></Field>
                            </div>
                            <div className="flex flex-wrap gap-4 text-sm">
                                <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.has_balcony} onChange={(ev) => form.setData('has_balcony', ev.target.checked)} /> Balcony</label>
                                <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.parking_eligible} onChange={(ev) => form.setData('parking_eligible', ev.target.checked)} /> Parking eligible</label>
                            </div>
                            <Field label="Operational status" error={e.operational_status}>
                                <Select options={[{ value: 'active', label: 'Active' }, { value: 'maintenance', label: 'Temporarily in maintenance' }, { value: 'retired', label: 'Retired' }]} value={form.data.operational_status} onChange={set('operational_status')} />
                            </Field>
                            <Field label="Public description" error={e.description}><textarea rows={3} className={area} value={form.data.description} onChange={set('description')} /></Field>
                            <Field label="Inclusions" error={e.inclusions}><textarea rows={2} className={area} value={form.data.inclusions} onChange={set('inclusions')} /></Field>
                            <Field label="Amenities" error={e.amenities_text} help="Comma-separated. Shown publicly; enter only what is confirmed."><Input value={form.data.amenities_text} onChange={set('amenities_text')} /></Field>
                            <Field label="Room policies" error={e.policies}><textarea rows={2} className={area} value={form.data.policies} onChange={set('policies')} /></Field>
                            <Field label="Internal notes" error={e.operational_notes} help="Never shown publicly."><textarea rows={2} className={area} value={form.data.operational_notes} onChange={set('operational_notes')} /></Field>
                            <Button type="submit" variant="primary" disabled={form.processing}>Save room</Button>
                        </form>
                    </Card>

                    <Card title="Effective-dated rates" action={can.rates ? (
                        <ActionButton action={{ label: 'Add new rate', url: `/admin/rooms/${room.id}/rates`, fields: [
                            { name: 'effective_from', label: 'Effective from', type: 'date', required: true },
                            { name: 'weekday', label: 'Weekday (PHP)', type: 'money', required: true },
                            { name: 'weekend', label: 'Weekend (PHP)', type: 'money', required: true },
                            { name: 'source_note', label: 'Note', type: 'text' },
                        ] }} />
                    ) : undefined}>
                        <table className="w-full text-sm">
                            <thead className="text-xs text-muted"><tr><th className="py-1 text-left font-medium">From</th><th className="text-right font-medium">Weekday</th><th className="text-right font-medium">Weekend</th><th className="pl-3 text-left font-medium">Note</th></tr></thead>
                            <tbody>
                                {rates.map((r: any) => (
                                    <tr key={r.id} className="border-t border-line"><td className="py-1.5">{dateOnly(r.from)}</td><td className="text-right tabular-nums">{peso(r.weekday_cents)}</td><td className="text-right tabular-nums">{peso(r.weekend_cents)}</td><td className="pl-3 text-xs text-muted">{r.note}</td></tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="mt-2 text-xs text-muted">Adding a rate never changes a confirmed booking's price.</p>
                    </Card>

                    <Card title="Season, promo and channel overrides" action={can.rates ? (
                        <ActionButton action={{ label: 'Add override', url: `/admin/rooms/${room.id}/overrides`, fields: [
                            { name: 'label', label: 'Label', type: 'text', required: true },
                            { name: 'date_from', label: 'From', type: 'date', required: true },
                            { name: 'date_to', label: 'To (inclusive)', type: 'date', required: true },
                            { name: 'nightly', label: 'Nightly rate (PHP)', type: 'money', required: true },
                            { name: 'booking_source_id', label: 'Only for channel', type: 'select', options: sources.map((s: any) => ({ value: s.id, label: s.name })) },
                        ] }} />
                    ) : undefined}>
                        {overrides.length === 0 ? <Empty>No overrides.</Empty> : (
                            <ul className="space-y-1 text-sm">
                                {overrides.map((o: any) => <li key={o.id}>{o.label}: {dateOnly(o.from)} to {dateOnly(o.to)}, {peso(o.cents)}{o.source ? ` (${o.source} only)` : ''}</li>)}
                            </ul>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card title="Room blocks" action={
                        <ActionButton action={{ label: 'Block dates', url: `/admin/rooms/${room.id}/blocks`, fields: [
                            { name: 'start_date', label: 'From', type: 'date', required: true },
                            { name: 'end_date', label: 'To (exclusive)', type: 'date', required: true },
                            { name: 'kind', label: 'Kind', type: 'select', required: true, options: [{ value: 'repair', label: 'Repair' }, { value: 'owner_use', label: 'Owner use' }, { value: 'technical', label: 'Technical downtime' }, { value: 'third_party', label: 'Unavailable third-party inventory' }] },
                            { name: 'reason', label: 'Reason', type: 'text', required: true },
                        ] }} />
                    }>
                        {blocks.length === 0 ? <Empty>No active blocks.</Empty> : (
                            <ul className="space-y-2 text-sm">
                                {blocks.map((b: any) => (
                                    <li key={b.id} className="flex items-start justify-between gap-2">
                                        <span>{dateOnly(b.start)} to {dateOnly(b.end)}<span className="block text-xs text-muted">{b.kind}: {b.reason}</span></span>
                                        <ActionButton action={{ label: 'Release', url: `/admin/blocks/${b.id}/release`, confirm: 'Release this block?' }} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card title="Photos" action={
                        <ActionButton action={{ label: 'Add photo', url: `/admin/rooms/${room.id}/images`, fields: [
                            { name: 'image', label: 'Real room photo', type: 'file', required: true, accept: 'image/*' },
                            { name: 'alt_text', label: 'Description for screen readers', type: 'text', required: true },
                        ] }} />
                    }>
                        {images.length === 0 ? <Empty>No photos. The public page shows no image until real ones are uploaded.</Empty> : (
                            <div className="grid grid-cols-2 gap-2">
                                {images.map((i: any) => (
                                    <figure key={i.id} className="relative">
                                        <img src={i.url} alt={i.alt} className="aspect-[4/3] w-full rounded object-cover" />
                                        <button aria-label="Delete photo" className="absolute top-1 right-1 rounded bg-white/90 p-1" onClick={() => window.confirm('Delete this photo?') && router.post(`/admin/room-images/${i.id}/delete`, {}, { preserveScroll: true })}><Trash2 size={13} /></button>
                                    </figure>
                                ))}
                            </div>
                        )}
                    </Card>

                    <Card title="Platform listing IDs" action={
                        <ActionButton action={{ label: 'Add listing', url: `/admin/rooms/${room.id}/listings`, fields: [
                            { name: 'provider', label: 'Platform', type: 'text', required: true, default: 'Airbnb' },
                            { name: 'listing_id', label: 'Listing ID', type: 'text', required: true },
                            { name: 'url', label: 'Listing URL', type: 'text', help: 'Optional. Must start with https://.' },
                        ] }} />
                    }>
                        {listings.length === 0 ? <Empty>No listing IDs recorded.</Empty> : (
                            <ul className="space-y-2 text-sm">
                                {listings.map((l: any) => (
                                    <li key={l.id} className="flex items-start justify-between gap-2">
                                        <span className="min-w-0 break-words">{l.provider}: {l.url ? <a href={l.url} target="_blank" rel="noreferrer" className="underline">{l.listing_id}</a> : l.listing_id}</span>
                                        <ActionButton action={{ label: 'Remove', url: `/admin/room-listings/${l.id}/delete`, confirm: 'Remove this listing ID?' }} />
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="mt-2 text-xs text-muted">For staff reference. This does not connect the room to the platform.</p>
                    </Card>

                    {(can.secretsReveal || can.secretsManage) && <SecretCard room={room} secret={secret} can={can} />}
                </div>
            </div>
        </AdminLayout>
    );
}
