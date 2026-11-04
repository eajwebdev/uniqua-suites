import { router } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CHART, SERIES } from '@/components/charts';
import { buttonClass, Card, Empty, Input, Select, Stat } from '@/components/ui';
import { chartsAnimate, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function Reports({ from, to, property, type, basis, bases, filters, rooms, sources, properties, types, kpis, channels, topRooms, trend, definitions, table, canExport }: any) {
    const go = (changes: any) => router.get('/admin/reports', { from, to, property: property ?? undefined, type, basis: type === 'by_basis' ? basis : undefined, source: filters.source, room: filters.room, method: filters.method, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const query = new URLSearchParams({ from, to, ...(property ? { property: String(property) } : {}), ...(type === 'by_basis' ? { basis } : {}), ...(type === 'room_ledger' && filters.source ? { source: String(filters.source) } : {}), ...(type === 'room_ledger' && filters.room ? { room: String(filters.room) } : {}), ...(type === 'collections' && filters.method ? { method: filters.method } : {}) });
    const exportQuery = `?${query}`;

    return (
        <AdminLayout title="Reports & Analytics">
            <div className="sticky top-14 z-20 lg:top-16 -mx-4 mb-4 flex flex-wrap items-end gap-2 border-b border-line bg-ivory px-4 py-2 sm:-mx-6 sm:px-6">
                <label className="text-xs">From<Input type="date" value={from} onChange={(e) => e.target.value && go({ from: e.target.value })} /></label>
                <label className="text-xs">To<Input type="date" value={to} onChange={(e) => e.target.value && go({ to: e.target.value })} /></label>
                <label className="text-xs">Property<Select placeholder="All" options={properties.map((p: any) => ({ value: p.id, label: p.name }))} value={property ?? ''} onChange={(e) => go({ property: e.target.value || undefined })} /></label>
            </div>

            {kpis && (
                <>
                    <p className="mb-3 max-w-3xl rounded-md bg-beige px-3 py-2 text-sm">Each figure uses its own date basis (stay, receipt, payout, created or expense date), so they do not add up across cards. Workbook history is excluded from these figures. It appears, labelled unverified, in the Sales recording report below.</p>
                    <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Stat label="Earned accommodation (stay date)" value={peso(kpis.earned_cents)} hint={definitions.earned} />
                        <Stat label="Gross operating revenue" value={peso(kpis.gross_operating_cents)} hint={definitions.gross_operating} />
                        <Stat label="Booked revenue (created date)" value={peso(kpis.booked_cents)} hint={definitions.booked} />
                        <Stat label="Collected cash (receipt/payout date)" value={peso(kpis.collected_cents)} hint={definitions.collected} />
                        <Stat label="Operating expenses" value={peso(kpis.expenses_cents)} hint={`${definitions.expenses} Unpaid: ${peso(kpis.expenses_unpaid_cents)}`} />
                        <Stat label="Operating contribution" value={peso(kpis.contribution_cents)} hint={definitions.contribution} />
                        <Stat label="Occupancy" value={kpis.occupancy_pct === null ? '—' : `${kpis.occupancy_pct}%`} hint={`${kpis.occupied_room_nights} of ${kpis.available_room_nights} sellable room nights`} />
                        <Stat label="ADR / RevPAR" value={`${peso(kpis.adr_cents)} / ${peso(kpis.revpar_cents)}`} hint={definitions.adr} />
                    </div>

                    <div className="mb-5 grid gap-4 lg:grid-cols-2">
                        <Card title="12-month trend">
                            <div className="h-64" role="img" aria-label="Bar chart of earned accommodation, collected cash and expenses for each of the last 12 months. Exact figures are in the report below.">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={trend}>
                                        <CartesianGrid stroke={CHART.grid} vertical={false} />
                                        <XAxis dataKey="month" tick={{ fontSize: 10 }} />
                                        <YAxis tick={{ fontSize: 10 }} />
                                        <Tooltip formatter={(v: any) => `₱${Number(v).toLocaleString('en-PH')}`} />
                                        <Legend />
                                        <Bar dataKey="earned" name="Earned" fill={SERIES.earned} radius={[4, 4, 0, 0]} isAnimationActive={chartsAnimate} />
                                        <Bar dataKey="collected" name="Collected" fill={SERIES.collected} radius={[4, 4, 0, 0]} isAnimationActive={chartsAnimate} />
                                        <Bar dataKey="expenses" name="Expenses" fill={SERIES.expenses} radius={[4, 4, 0, 0]} isAnimationActive={chartsAnimate} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </Card>
                        <Card title="Top rooms and channels">
                            {topRooms.length === 0 && channels.length === 0 ? <Empty>No data in this period.</Empty> : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <ul className="space-y-1 text-sm">
                                        {topRooms.map((r: any) => <li key={r.name} className="flex justify-between gap-2"><span>{r.name} <span className="text-xs text-muted">{r.nights}n</span></span><span className="tabular-nums">{peso(r.earned_cents)}</span></li>)}
                                    </ul>
                                    <ul className="space-y-1 text-sm">
                                        {channels.map((c: any) => <li key={c.name} className="flex justify-between gap-2"><span>{c.name} <span className="text-xs text-muted">{c.confirmed} conf.</span></span><span className="tabular-nums">{peso(c.earned_cents)}</span></li>)}
                                    </ul>
                                </div>
                            )}
                        </Card>
                    </div>
                </>
            )}

            <Card
                title="Report"
                action={
                    <div className="flex flex-wrap items-center gap-2">
                        <Select aria-label="Report type" className="w-auto" options={types.map((t: any) => ({ value: t.key, label: t.label }))} value={type} onChange={(e) => go({ type: e.target.value, basis: e.target.value === 'by_basis' ? basis : undefined, source: undefined, room: undefined, method: undefined })} />
                        {type === 'room_ledger' && <Select aria-label="Booking source" className="w-auto" placeholder="All sources" options={sources.map((s: any) => ({ value: s.id, label: s.name }))} value={filters.source ?? ''} onChange={(e) => go({ source: e.target.value || undefined })} />}
                        {type === 'room_ledger' && <Select aria-label="Room" className="w-auto" placeholder="All rooms" options={rooms.map((r: any) => ({ value: r.id, label: r.name }))} value={filters.room ?? ''} onChange={(e) => go({ room: e.target.value || undefined })} />}
                        {type === 'collections' && <Select aria-label="Payment method" className="w-auto" placeholder="All methods" options={[{ value: 'cash', label: 'Cash' }, { value: 'gcash', label: 'GCash' }, { value: 'bank_transfer', label: 'Bank transfer' }, { value: 'other', label: 'Other' }]} value={filters.method ?? ''} onChange={(e) => go({ method: e.target.value || undefined })} />}
                        {type === 'by_basis' && <Select aria-label="Date basis" className="w-auto" options={bases} value={basis} onChange={(e) => go({ basis: e.target.value })} />}
                        {canExport && ['csv', 'xlsx', 'pdf'].map((f) => <a key={f} href={`/admin/reports/export/${type}/${f}${exportQuery}`} className={buttonClass('secondary', true)}>{f.toUpperCase()}</a>)}
                    </div>
                }
            >
                <p className="mb-2 text-xs text-muted">{table.definition}{type === 'collections' && filters.method ? ' Filtered by method, so channel payouts and refunds are not shown.' : ''}</p>
                {table.rows.length === 0 ? <Empty>No records in this period.</Empty> : (
                    <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Report table, scrolls sideways">
                        <table className="w-full text-left text-sm">
                            <thead className="text-xs text-muted"><tr>{table.headers.map((h: string, j: number) => <th key={h} scope="col" className={j === 0 ? 'sticky left-0 z-10 bg-white py-1 pr-3 font-medium whitespace-nowrap' : 'py-1 pr-3 font-medium whitespace-nowrap'}>{h}</th>)}</tr></thead>
                            <tbody>{table.rows.map((row: any[], i: number) => <tr key={i} className="border-t border-line">{row.map((v, j) => <td key={j} className={j === 0 ? 'sticky left-0 bg-white py-1.5 pr-3 whitespace-nowrap tabular-nums' : 'py-1.5 pr-3 whitespace-nowrap tabular-nums'}>{v}</td>)}</tr>)}</tbody>
                        </table>
                    </div>
                )}
                {table.total > table.rows.length && <p className="mt-2 text-xs text-muted">Showing the first {table.rows.length} of {table.total}. Export for all rows.</p>}
            </Card>
        </AdminLayout>
    );
}
