import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Card, cn, Empty, Stat, StatusChip } from '@/components/ui';
import { dateOnly, peso, time12 } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

function Agenda({ title, rows }: { title: string; rows: any[] }) {
    return (
        <Card title={`${title} (${rows.length})`}>
            {rows.length === 0 ? (
                <Empty>None today.</Empty>
            ) : (
                <ul className="divide-y divide-line">
                    {rows.map((b) => (
                        <li key={b.id} className="py-2 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Link href={`/admin/bookings/${b.id}`} className="font-medium underline">{b.guest}</Link>
                                <span className="text-xs text-muted">{b.source}</span>
                            </div>
                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                {b.rooms.map((r: any) => (
                                    <span key={r.name} className="inline-flex items-center gap-1 text-xs">
                                        {r.name} <StatusChip value={r.housekeeping} />
                                    </span>
                                ))}
                                {b.early && <span className="text-xs text-muted">Early {time12(b.early)}</span>}
                            </div>
                            {b.special_requests && <p className="mt-1 text-xs text-muted">{b.special_requests}</p>}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function delta(now: number, prior: number) {
    if (!prior) return 'No prior-month figure';
    const pct = Math.round(((now - prior) / Math.abs(prior)) * 100);
    return `${pct >= 0 ? '+' : ''}${pct}% vs last full month`;
}

export default function Dashboard({ actions, today, money, performance, team, date }: any) {
    return (
        <AdminLayout title="Dashboard">
            <p className="mb-4 text-sm text-muted">{dateOnly(date)}, Asia/Manila</p>

            <Card title="Action needed" className="mb-5">
                {actions.length === 0 ? (
                    <p className="flex items-center gap-2 text-sm text-emerald-900"><CheckCircle2 size={16} /> Nothing needs attention right now.</p>
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {actions.map((a: any) => (
                            <li key={a.label}>
                                <Link href={a.href} className={cn('flex items-start gap-2 rounded-md border px-3 py-2 text-sm', a.tone === 'danger' ? 'border-red-200 bg-red-50 text-red-950' : 'border-amber-200 bg-amber-50 text-amber-950')}>
                                    <AlertTriangle size={15} className="mt-0.5 shrink-0" aria-hidden />
                                    <span><strong>{a.count}</strong> {a.label}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <h2 className="mb-2 font-serif text-xl font-semibold">Today</h2>
            <div className="mb-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Stat label="Occupied" value={today.occupied} />
                <Stat label="Vacant" value={today.vacant} />
                <Stat label="Blocked" value={today.blocked} />
                <Stat label="Rooms ready" value={today.ready} />
                <Stat label="Rooms not ready" value={today.not_ready} />
                <Stat label="Parking in use" value={today.parking_in_use} />
            </div>
            <div className="mb-5 grid gap-3 md:grid-cols-2">
                <Agenda title="Arrivals" rows={today.arrivals} />
                <Agenda title="Departures" rows={today.departures} />
            </div>

            {money && (
                <>
                    <h2 className="mb-2 font-serif text-xl font-semibold">Money</h2>
                    <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Stat label="Verified collected today" value={peso(money.collected_today_cents)} />
                        <Stat label="Collected this month" value={peso(money.collected_month_cents)} hint={delta(money.collected_month_cents, money.collected_prior_cents)} />
                        <Stat label="Outstanding balances" value={peso(money.outstanding_cents)} />
                        <Stat label="Channel payouts expected" value={peso(money.expected_payout_cents)} hint={`Received this month: ${peso(money.received_payout_month_cents)}`} />
                        <Stat label="Earned accommodation, month" value={peso(money.earned_month_cents)} hint={delta(money.earned_month_cents, money.earned_prior_cents)} />
                        <Stat label="Expenses, month" value={peso(money.expenses_month_cents)} />
                        <Stat label="Operating contribution" value={peso(money.contribution_month_cents)} hint="Not audited net income" />
                    </div>
                </>
            )}

            {performance && (
                <>
                    <h2 className="mb-2 font-serif text-xl font-semibold">Performance</h2>
                    <div className="mb-3 grid grid-cols-3 gap-3">
                        <Stat label="Occupancy, month to date" value={performance.occupancy_pct === null ? '—' : `${performance.occupancy_pct}%`} />
                        <Stat label="ADR" value={peso(performance.adr_cents)} />
                        <Stat label="RevPAR" value={peso(performance.revpar_cents)} />
                    </div>
                    <div className="mb-5 grid gap-3 lg:grid-cols-3">
                        <Card title="Six-month trend (live system data only)" className="lg:col-span-2">
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={performance.trend}>
                                        <CartesianGrid stroke="#e3d9cb" vertical={false} />
                                        <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                                        <YAxis tick={{ fontSize: 11 }} />
                                        <Tooltip formatter={(v: any) => `₱${Number(v).toLocaleString('en-PH')}`} />
                                        <Legend />
                                        <Line type="monotone" dataKey="earned" name="Earned" stroke="#241a15" strokeWidth={2} dot={false} />
                                        <Line type="monotone" dataKey="collected" name="Collected" stroke="#8a6d3f" strokeWidth={2} strokeDasharray="5 3" dot={false} />
                                        <Line type="monotone" dataKey="expenses" name="Expenses" stroke="#a33" strokeWidth={2} strokeDasharray="1 3" dot={false} />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        </Card>
                        <Card title="Top rooms, this month">
                            {performance.top_rooms.length === 0 ? <Empty>No occupied nights yet.</Empty> : (
                                <ul className="space-y-1.5 text-sm">
                                    {performance.top_rooms.map((r: any) => (
                                        <li key={r.name} className="flex justify-between gap-2"><span>{r.name} <span className="text-xs text-muted">({r.nights} nights)</span></span><span className="tabular-nums">{peso(r.earned_cents)}</span></li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>
                </>
            )}

            <h2 className="mb-2 font-serif text-xl font-semibold">Team</h2>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Stat label="Open cleaning tasks" value={team.open_tasks} />
                <Stat label="Late tasks" value={team.late_tasks} />
                <Stat label="Awaiting inspection" value={team.for_inspection} />
                <Stat label="Open laundry" value={team.open_laundry} />
                <Stat label="Open work orders" value={team.open_work_orders} />
                <Stat label="Low-stock lines" value={team.low_stock} />
            </div>
        </AdminLayout>
    );
}
