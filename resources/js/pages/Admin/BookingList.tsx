import { Link, router, usePage } from '@inertiajs/react';
import { AlertCircle, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { openNewBooking } from '@/components/NewBooking';
import { buttonClass, cn, Empty, Input, Pagination, Select, StatusChip, tableClass, tdClass, thClass, theadClass, trClass } from '@/components/ui';
import { dateOnly, dateTime, humanize, peso } from '@/lib/format';
import { useLiveRefresh } from '@/lib/useLiveRefresh';
import AdminLayout from '@/layouts/AdminLayout';

const UNPRICED = ['inquiry', 'tentative', 'expired_hold'];

/** What the person sees in the Payment column, worked out from the same numbers as the booking page. */
function paymentState(b: any): { label: string; tone: string } | null {
    if (b.balance_cents === null || UNPRICED.includes(b.status)) return null;
    if (b.charges_cents <= 0 && b.paid_cents <= 0) return { label: 'No charges', tone: 'text-muted' };
    if (b.balance_cents < 0) return { label: 'Overpaid', tone: 'text-sky-800' };
    if (b.balance_cents === 0) return { label: 'Paid', tone: 'text-emerald-800' };
    if (b.pending_cents > 0) return { label: 'Under review', tone: 'text-amber-800' };
    return { label: b.paid_cents > 0 ? 'Partially paid' : 'Unpaid', tone: 'text-red-800' };
}

export default function BookingList({ bookings, filters, sources, statuses, counts, summary, canPay }: any) {
    useLiveRefresh(['bookings'], 30_000);
    const cam = (usePage<any>().props as any).category === 'cameras';
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (changes: any) => router.get('/admin/bookings', clean({ ...filters, ...changes }), { preserveState: true, preserveScroll: true, replace: true });
    const view = filters.view ?? 'all';

    const views: { key: string; label: string }[] = [
        { key: 'all', label: 'All' },
        { key: 'open', label: cam ? 'Open requests' : 'Open & upcoming' },
        { key: 'arriving', label: cam ? 'Pickups (7 days)' : 'Arriving (7 days)' },
        { key: 'in_house', label: cam ? 'Out now' : 'In house' },
        ...(canPay ? [{ key: 'unpaid', label: 'Unpaid balance' }] : []),
        { key: 'past', label: 'Past & closed' },
    ];

    const extraFilters = ['status', 'source', 'payment', 'from', 'to', 'q', 'arrivals', 'room'].filter((k) => filters[k]);
    const sortOptions = [
        { value: 'newest', label: 'Newest first' },
        { value: 'check_in', label: cam ? 'Pickup (soonest)' : 'Check-in (soonest)' },
        { value: 'check_in_desc', label: cam ? 'Pickup (latest)' : 'Check-in (latest)' },
        ...(canPay ? [{ value: 'balance', label: 'Largest balance' }] : []),
    ];

    const headers = [
        'Reference',
        cam ? 'Customer' : 'Guest',
        cam ? 'Camera' : 'Rooms',
        'Status',
        cam ? 'Pickup → Return' : 'Stay',
        'Total',
        ...(canPay ? ['Paid', 'Balance', 'Payment'] : []),
    ];

    return (
        <AdminLayout title={cam ? 'Camera Rentals' : 'Reservations & Inquiries'} actions={<button type="button" onClick={() => openNewBooking()} className={buttonClass('primary')}><Plus size={15} aria-hidden /> {cam ? 'New rental' : 'New booking'}</button>}>
            {summary && summary.unpaid_count > 0 && (
                <button
                    type="button"
                    onClick={() => apply({ view: 'unpaid', sort: 'balance', page: undefined })}
                    className="mb-4 flex w-full flex-wrap items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-left text-sm text-red-900 transition-colors hover:border-red-300"
                >
                    <AlertCircle size={18} aria-hidden className="shrink-0" />
                    <span className="flex-1">
                        <span className="font-semibold">{summary.unpaid_count} {cam ? 'rental' : 'booking'}{summary.unpaid_count === 1 ? '' : 's'} with an unpaid balance</span>
                        {' · '}
                        <span className="font-semibold tabular-nums">{peso(summary.unpaid_cents)}</span> to collect
                        {summary.pending_cents > 0 && <span className="text-red-800/80"> ({peso(summary.pending_cents)} awaiting payment review)</span>}
                    </span>
                    <span className="text-xs font-medium underline">Show who owes</span>
                </button>
            )}

            <nav aria-label="Quick views" className="mb-3 flex gap-1 overflow-x-auto border-b border-line">
                {views.map((v) => (
                    <button
                        key={v.key}
                        type="button"
                        aria-current={view === v.key ? 'page' : undefined}
                        onClick={() => apply({ view: v.key === 'all' ? undefined : v.key, page: undefined, sort: v.key === 'unpaid' ? 'balance' : v.key === 'arriving' ? 'check_in' : filters.sort })}
                        className={cn('flex items-center gap-1.5 px-3 py-2 text-sm whitespace-nowrap pointer-coarse:min-h-11', view === v.key ? 'border-b-2 border-gold-dark font-semibold' : 'text-muted hover:text-chocolate')}
                    >
                        {v.label}
                        {counts?.[v.key] !== undefined && (
                            <span className={cn('rounded-full px-1.5 text-[11px] tabular-nums', v.key === 'unpaid' && counts[v.key] > 0 ? 'bg-red-100 text-red-900' : 'bg-beige text-espresso')}>{counts[v.key]}</span>
                        )}
                    </button>
                ))}
            </nav>

            <div className="mb-4 flex flex-wrap items-end gap-2">
                <form onSubmit={(e) => { e.preventDefault(); apply({ q: q || undefined, page: undefined }); }} className="min-w-52 flex-1 sm:max-w-xs">
                    <Input type="search" aria-label="Search" placeholder={cam ? 'Reference, customer, phone, email' : 'Reference, guest, phone, email'} value={q} onChange={(e) => setQ(e.target.value)} />
                </form>
                <Select aria-label="Status" className="w-auto" placeholder="All statuses" options={statuses.map((s: string) => ({ value: s, label: s === 'tentative' ? 'On hold' : humanize(s) }))} value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined, page: undefined })} />
                <Select aria-label="Source" className="w-auto" placeholder="All sources" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={filters.source ?? ''} onChange={(e) => apply({ source: e.target.value || undefined, page: undefined })} />
                {canPay && (
                    <Select
                        aria-label="Payment"
                        className="w-auto"
                        placeholder="Any payment"
                        options={[
                            { value: 'unpaid', label: 'Has unpaid balance' },
                            { value: 'review', label: 'Payment under review' },
                            { value: 'paid', label: 'Fully paid' },
                            { value: 'overpaid', label: 'Overpaid' },
                        ]}
                        value={filters.payment ?? ''}
                        onChange={(e) => apply({ payment: e.target.value || undefined, page: undefined })}
                    />
                )}
                <label className="flex items-center gap-1.5 text-xs text-muted">
                    From
                    <Input type="date" aria-label="Stays from" className="w-auto" value={filters.from ?? ''} onChange={(e) => apply({ from: e.target.value || undefined, page: undefined })} />
                </label>
                <label className="flex items-center gap-1.5 text-xs text-muted">
                    To
                    <Input type="date" aria-label="Stays to" className="w-auto" value={filters.to ?? ''} onChange={(e) => apply({ to: e.target.value || undefined, page: undefined })} />
                </label>
                <Select aria-label="Sort" className="w-auto" options={sortOptions} value={filters.sort ?? 'newest'} onChange={(e) => apply({ sort: e.target.value === 'newest' ? undefined : e.target.value, page: undefined })} />
                {extraFilters.length > 0 && (
                    <button
                        type="button"
                        className={buttonClass('ghost', true)}
                        onClick={() => {
                            setQ('');
                            router.get('/admin/bookings', clean({ view: filters.view, sort: filters.sort }), { preserveState: true, replace: true });
                        }}
                    >
                        <X size={14} aria-hidden /> Clear filters
                    </button>
                )}
            </div>

            {summary && extraFilters.length > 0 && summary.filtered_balance_cents > 0 && (
                <p className="mb-2 text-xs text-muted">
                    Unpaid in these results: <span className="font-semibold text-red-800 tabular-nums">{peso(summary.filtered_balance_cents)}</span>
                </p>
            )}

            <div className="overflow-hidden rounded-xl border border-line/80 bg-white shadow-xs">
                {bookings.data.length === 0 ? <Empty>{cam ? 'No rentals match these filters.' : 'No reservations match these filters.'}</Empty> : (
                    <>
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead className={theadClass}>
                                    <tr>{headers.map((h) => <th key={h} scope="col" className={cn(thClass, ['Total', 'Paid', 'Balance'].includes(h) && 'text-right')}>{h}</th>)}</tr>
                                </thead>
                                <tbody>
                                    {bookings.data.map((b: any) => {
                                        const unpriced = UNPRICED.includes(b.status);
                                        const pay = paymentState(b);
                                        const owes = canPay && !unpriced && b.balance_cents > 0;
                                        return (
                                            <tr key={b.id} className={cn(trClass, owes && 'bg-red-50/40')}>
                                                <td className={tdClass}><Link href={`/admin/bookings/${b.id}`} className="font-medium underline">{b.reference}</Link></td>
                                                <td className={tdClass}>
                                                    <span className="block">{b.guest}</span>
                                                    {b.phone && <span className="block text-xs text-muted">{b.phone}</span>}
                                                </td>
                                                <td className={cn(tdClass, 'max-w-48 truncate')} title={b.rooms}>
                                                    {b.rooms}
                                                    <span className="block text-xs text-muted">{b.source}</span>
                                                </td>
                                                <td className={tdClass}>
                                                    <StatusChip value={b.status} />
                                                    {b.status === 'tentative' && b.hold_expires_at && <span className="mt-0.5 block text-xs text-muted">Until {dateTime(b.hold_expires_at)}</span>}
                                                </td>
                                                <td className={tdClass}>
                                                    {dateOnly(b.check_in)} → {dateOnly(b.check_out)}
                                                    <span className="block text-xs text-muted">{b.nights} {cam ? (b.nights === 1 ? 'day' : 'days') : (b.nights === 1 ? 'night' : 'nights')}</span>
                                                </td>
                                                <td className={cn(tdClass, 'text-right tabular-nums')}>{unpriced ? <span className="text-muted">Not priced yet</span> : peso(b.charges_cents ?? b.total_cents)}</td>
                                                {canPay && (
                                                    <>
                                                        <td className={cn(tdClass, 'text-right tabular-nums')}>
                                                            {unpriced ? <span className="text-muted">—</span> : peso(b.paid_cents)}
                                                            {!unpriced && b.pending_cents > 0 && <span className="block text-xs text-amber-800">+{peso(b.pending_cents)} pending</span>}
                                                        </td>
                                                        <td className={cn(tdClass, 'text-right font-semibold tabular-nums', owes ? 'text-red-800' : b.balance_cents < 0 ? 'text-sky-800' : 'text-muted')}>
                                                            {unpriced ? '—' : b.balance_cents < 0 ? `${peso(-b.balance_cents)} over` : peso(b.balance_cents)}
                                                        </td>
                                                        <td className={tdClass}>{pay ? <span className={cn('text-xs font-medium', pay.tone)}>{pay.label}</span> : <span className="text-muted">—</span>}</td>
                                                    </>
                                                )}
                                            </tr>
                                        );
                                    })}
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

/** Drops empty values so the address stays short and shareable. */
function clean(params: Record<string, any>) {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}
