import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { buttonClass, Empty, Input, Pagination, Select, StatusChip } from '@/components/ui';
import { dateOnly, dateTime, humanize, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function BookingList({ bookings, filters, sources, statuses }: any) {
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (changes: any) => router.get('/admin/bookings', { ...filters, ...changes }, { preserveState: true, replace: true });

    return (
        <AdminLayout title="Reservations & Inquiries" actions={<Link href="/admin/bookings/create" className={buttonClass('primary')}><Plus size={15} /> New inquiry</Link>}>
            <div className="mb-4 flex flex-wrap gap-2">
                <form onSubmit={(e) => { e.preventDefault(); apply({ q: q || undefined }); }} className="min-w-52 flex-1 sm:max-w-sm">
                    <Input type="search" aria-label="Search" placeholder="Search reference or guest" value={q} onChange={(e) => setQ(e.target.value)} />
                </form>
                <Select aria-label="Status" className="w-auto" placeholder="All statuses" options={statuses.map((s: string) => ({ value: s, label: humanize(s) }))} value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })} />
                <Select aria-label="Source" className="w-auto" placeholder="All sources" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={filters.source ?? ''} onChange={(e) => apply({ source: e.target.value || undefined })} />
            </div>

            <div className="overflow-x-auto rounded-lg border border-line bg-white">
                {bookings.data.length === 0 ? <Empty>No reservations match these filters.</Empty> : (
                    <table className="w-full text-left text-sm">
                        <thead className="bg-ivory text-xs text-muted">
                            <tr>{['Reference', 'Guest', 'Rooms', 'Source', 'Status', 'Check-in', 'Check-out', 'Total'].map((h) => <th key={h} scope="col" className="px-3 py-2 font-medium">{h}</th>)}</tr>
                        </thead>
                        <tbody>
                            {bookings.data.map((b: any) => (
                                <tr key={b.id} className="border-t border-line">
                                    <td className="px-3 py-2 whitespace-nowrap"><Link href={`/admin/bookings/${b.id}`} className="font-medium underline">{b.reference}</Link></td>
                                    <td className="px-3 py-2">{b.guest}</td>
                                    <td className="px-3 py-2">{b.rooms}</td>
                                    <td className="px-3 py-2">{b.source}</td>
                                    <td className="px-3 py-2">
                                        <StatusChip value={b.status} />
                                        {b.status === 'tentative' && b.hold_expires_at && <span className="mt-0.5 block text-xs text-muted">Until {dateTime(b.hold_expires_at)}</span>}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">{dateOnly(b.check_in)}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">{dateOnly(b.check_out)}</td>
                                    <td className="px-3 py-2 tabular-nums">{b.status === 'inquiry' || b.status === 'tentative' ? <span className="text-muted">Not priced yet</span> : peso(b.total_cents)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
            <Pagination links={bookings.links} />
        </AdminLayout>
    );
}
