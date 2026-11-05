import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { openNewBooking } from '@/components/NewBooking';
import { buttonClass, Empty, Input, Pagination, Select, StatusChip, tableClass, tdClass, thClass, theadClass, trClass } from '@/components/ui';
import { dateOnly, dateTime, humanize, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function BookingList({ bookings, filters, sources, statuses }: any) {
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (changes: any) => router.get('/admin/bookings', { ...filters, ...changes }, { preserveState: true, replace: true });

    return (
        <AdminLayout title="Reservations & Inquiries" actions={<button type="button" onClick={() => openNewBooking()} className={buttonClass('primary')}><Plus size={15} aria-hidden /> New booking</button>}>
            <div className="mb-4 flex flex-wrap gap-2">
                <form onSubmit={(e) => { e.preventDefault(); apply({ q: q || undefined }); }} className="min-w-52 flex-1 sm:max-w-sm">
                    <Input type="search" aria-label="Search" placeholder="Search reference or guest" value={q} onChange={(e) => setQ(e.target.value)} />
                </form>
                <Select aria-label="Status" className="w-auto" placeholder="All statuses" options={statuses.map((s: string) => ({ value: s, label: humanize(s) }))} value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })} />
                <Select aria-label="Source" className="w-auto" placeholder="All sources" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={filters.source ?? ''} onChange={(e) => apply({ source: e.target.value || undefined })} />
            </div>

            <div className="overflow-hidden rounded-xl border border-line/80 bg-white shadow-xs">
                {bookings.data.length === 0 ? <Empty>No reservations match these filters.</Empty> : (
                    <>
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead className={theadClass}>
                                    <tr>{['Reference', 'Guest', 'Rooms', 'Source', 'Status', 'Check-in', 'Check-out', 'Total'].map((h) => <th key={h} scope="col" className={thClass}>{h}</th>)}</tr>
                                </thead>
                                <tbody>
                                    {bookings.data.map((b: any) => (
                                        <tr key={b.id} className={trClass}>
                                            <td className={`${tdClass} whitespace-nowrap`}><Link href={`/admin/bookings/${b.id}`} className="font-medium underline">{b.reference}</Link></td>
                                            <td className={tdClass}>{b.guest}</td>
                                            <td className={tdClass}>{b.rooms}</td>
                                            <td className={tdClass}>{b.source}</td>
                                            <td className={tdClass}>
                                                <StatusChip value={b.status} />
                                                {b.status === 'tentative' && b.hold_expires_at && <span className="mt-0.5 block text-xs text-muted">Until {dateTime(b.hold_expires_at)}</span>}
                                            </td>
                                            <td className={`${tdClass} whitespace-nowrap`}>{dateOnly(b.check_in)}</td>
                                            <td className={`${tdClass} whitespace-nowrap`}>{dateOnly(b.check_out)}</td>
                                            <td className={`${tdClass} tabular-nums`}>{b.status === 'inquiry' || b.status === 'tentative' ? <span className="text-muted">Not priced yet</span> : peso(b.total_cents)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={bookings.links} from={bookings.from} to={bookings.to} total={bookings.total} />
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
