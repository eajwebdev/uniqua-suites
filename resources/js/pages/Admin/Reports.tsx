import { router } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { buttonClass, Card, Empty, Input, Select, Stat } from '@/components/ui';
import { peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function Reports({ from, to, property, type, properties, types, kpis, channels, topRooms, trend, definitions, table, canExport }: any) {
    const go = (changes: any) => router.get('/admin/reports', { from, to, property: property ?? undefined, type, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const exportQuery = `?from=${from}&to=${to}`;

    return (
        <AdminLayout title="Reports & Analytics">
            <div className="sticky top-12 z-20 -mx-4 mb-4 flex flex-wrap items-end gap-2 border-b border-line bg-ivory px-4 py-2 sm:-mx-6 sm:px-6">
                <label className="text-xs">From<Input type="date" value={from} onChange={(e) => e.target.value && go({ from: e.target.value })} /></label>
                <label className="text-xs">To<Input type="date" value={to} onChange={(e) => e.target.value && go({ to: e.target.value })} /></label>
                <label className="text-xs">Property<Select placeholder="All" options={properties.map((p: any) => ({ value: p.id, label: p.name }))} value={property ?? ''} onChange={(e) => go({ property: e.target.value || undefined })} /></label>
            </div>

            {kpis && (
                <>
                    <p className="mb-3 max-w-3xl rounded-md bg-beige px-3 py-2 text-sm">Each figure uses its own date basis (stay, receipt, payout, created or expense date), so they do not add up across cards. Legacy spreadsheet data is excluded; it stays provisional under Import &amp; Data Quality.</p>
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
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={trend}>
                                        <CartesianGrid stroke="#e3d9cb" vertical={false} />
                                        <XAxis dataKey="month" tick={{ fontSize: 10 }} />
                                        <YAxis tick={{ fontSize: 10 }} />
                                        <Tooltip formatter={(v: any) => `₱${Number(v).toLocaleString('en-PH')}`} />
                                        <Legend />
                                        <Bar dataKey="earned" name="Earned" fill="#241a15" />
                                        <Bar dataKey="collected" name="Collected" fill="#c6a675" />
                                        <Bar dataKey="expenses" name="Expenses" fill="#a85a4a" />
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
                        <Select aria-label="Report type" className="w-auto" options={types.map((t: any) => ({ value: t.key, label: t.label }))} value={type} onChange={(e) => go({ type: e.target.value })} />
                        {canExport && ['csv', 'xlsx', 'pdf'].map((f) => <a key={f} href={`/admin/reports/export/${type}/${f}${exportQuery}`} className={buttonClass('secondary', true)}>{f.toUpperCase()}</a>)}
                    </div>
                }
            >
                <p className="mb-2 text-xs text-muted">{table.definition}</p>
                {table.rows.length === 0 ? <Empty>No records in this period.</Empty> : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-xs text-muted"><tr>{table.headers.map((h: string) => <th key={h} scope="col" className="py-1 pr-3 font-medium whitespace-nowrap">{h}</th>)}</tr></thead>
                            <tbody>{table.rows.map((row: any[], i: number) => <tr key={i} className="border-t border-line">{row.map((v, j) => <td key={j} className="py-1.5 pr-3">{v}</td>)}</tr>)}</tbody>
                        </table>
                    </div>
                )}
                {table.total > table.rows.length && <p className="mt-2 text-xs text-muted">Showing the first {table.rows.length} of {table.total}. Export for all rows.</p>}
            </Card>
        </AdminLayout>
    );
}
