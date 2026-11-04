import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle, ArrowRight, Ban, BedDouble, CalendarDays, Car, CheckCircle2, ChevronRight, CircleDollarSign, ClipboardCheck, DoorOpen, HandCoins, HelpCircle, LogIn, LogOut, Minus, Package, PieChart, Plus, Receipt, Shirt, Sparkles, TrendingDown,
    TrendingUp, Wallet, Wrench,
} from 'lucide-react';
import { ReactNode, useMemo } from 'react';
import { CHART, ForecastChart, Legend, MoneyTrendChart, OccupancyTrendChart, RoomsDonut, SERIES, Sparkline, ValueBar } from '@/components/charts';
import { buttonClass, Card, cn, Empty, Stat, StatusChip } from '@/components/ui';
import { dateOnly, pesoWhole, shortDate, time12 } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

const PERIODS = [
    { key: 'month', label: 'This month' },
    { key: 'last_month', label: 'Last month' },
    { key: '90d', label: 'Last 90 days' },
];

const ROOM_STATE: Record<string, { label: string; icon: any; style: string }> = {
    occupied: { label: 'Occupied', icon: BedDouble, style: 'bg-espresso text-ivory' },
    arriving: { label: 'Arriving today', icon: LogIn, style: 'bg-sky-100 text-sky-950' },
    vacant: { label: 'Vacant', icon: DoorOpen, style: 'bg-emerald-50 text-emerald-950' },
    blocked: { label: 'Blocked', icon: Ban, style: 'bg-red-50 text-red-950' },
    maintenance: { label: 'Maintenance', icon: Wrench, style: 'bg-amber-50 text-amber-950' },
};

function SectionTitle({ title, children, right }: { title: string; children?: ReactNode; right?: ReactNode }) {
    return (
        <div className="mt-8 mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
                {children && <p className="text-sm text-muted">{children}</p>}
            </div>
            {right}
        </div>
    );
}

function Agenda({ title, icon: Icon, rows, empty }: { title: string; icon: any; rows: any[]; empty: string }) {
    return (
        <Card title={<><Icon size={16} strokeWidth={1.75} className="text-bronze" aria-hidden /> {title} <span className="rounded-full bg-beige px-2 text-xs font-medium">{rows.length}</span></>}>
            {rows.length === 0 ? <Empty>{empty}</Empty> : (
                <ul className="-my-1 divide-y divide-line/70">
                    {rows.map((b) => (
                        <li key={b.id} className="py-2.5 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Link href={`/admin/bookings/${b.id}`} className="inline-block font-medium hover:underline pointer-coarse:py-3">{b.guest}</Link>
                                <span className="text-xs text-muted">{b.source}</span>
                            </div>
                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                {b.rooms.map((r: any) => (
                                    <span key={r.name} className="inline-flex items-center gap-1 text-xs">{r.name} <StatusChip value={r.housekeeping} /></span>
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

function RoomTile({ room }: { room: any }) {
    const state = ROOM_STATE[room.state];
    const href = room.stay ? `/admin/bookings/${room.stay.booking_id}` : room.next ? `/admin/bookings/${room.next.booking_id}` : `/admin/rooms/${room.id}/edit`;
    const { auth } = usePage<any>().props as any;
    const canOpen = room.stay || room.next || auth.user.permissions.includes('rooms.manage');
    const body = (
        <>
            <div className="flex items-center justify-between gap-2">
                <span className="font-semibold tracking-wide">{room.name}</span>
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap', state.style)}><state.icon size={12} aria-hidden /> {state.label}</span>
            </div>
            <p className="mt-1.5 min-h-8 text-xs leading-4 text-muted">
                {room.stay ? <><strong className="font-medium text-chocolate">{room.stay.guest}</strong>, leaves {shortDate(room.stay.check_out)}</>
                    : room.next ? <>Next: <strong className="font-medium text-chocolate">{room.next.guest}</strong>, {shortDate(room.next.check_in)}</>
                        : 'Nothing booked yet'}
            </p>
            <div className="mt-2"><StatusChip value={room.housekeeping} /></div>
        </>
    );
    const frame = 'block rounded-lg border border-line/80 p-3 transition-colors';

    return canOpen ? <Link href={href} className={cn(frame, 'hover:border-gold hover:bg-ivory/50')}>{body}</Link> : <div className={frame}>{body}</div>;
}

function Delta({ now, prior, compare, upIsGood = true, format }: { now: number | null; prior: number | null; compare: string; upIsGood?: boolean; format?: 'points' }) {
    if (now === null || prior === null || (!prior && format !== 'points')) {
        return <p className="text-xs text-muted">No earlier figure to compare</p>;
    }
    const points = format === 'points';
    // A percentage means nothing when a figure crosses zero, or starts from a tiny one, so show the amount instead.
    const asAmount = !points && (now < 0 || prior <= 0 || Math.abs((now - prior) / prior) > 5);
    const change = points ? Math.round((now - prior) * 10) / 10 : asAmount ? now - prior : Math.round(((now - prior) / Math.abs(prior)) * 100);
    const Icon = change === 0 ? Minus : change > 0 ? TrendingUp : TrendingDown;
    const good = change === 0 ? null : (change > 0) === upIsGood;

    return (
        <p className="flex flex-wrap items-center gap-1 text-xs text-muted">
            <span className={cn('inline-flex items-center gap-0.5 font-semibold', good === true && 'text-emerald-800', good === false && 'text-red-800')}>
                <Icon size={13} aria-hidden />
                {change > 0 ? '+' : ''}{asAmount ? pesoWhole(change) : change}{points ? ' pts' : asAmount ? '' : '%'}
            </span>
            {compare}
        </p>
    );
}

function Kpi({ label, value, icon: Icon, spark, colour, children, note }: { label: string; value: ReactNode; icon: any; spark?: number[]; colour?: string; children?: ReactNode; note?: string }) {
    return (
        <div className="flex flex-col rounded-xl border border-line/80 bg-white p-4 shadow-xs">
            <div className="flex items-center gap-2 text-xs font-medium text-muted">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-beige text-espresso"><Icon size={15} strokeWidth={1.75} aria-hidden /></span>
                {label}
            </div>
            <div className="mt-2 text-2xl font-semibold tracking-tight">{value}</div>
            <div className="mt-1 flex-1">{children}{note && <p className="text-xs text-muted">{note}</p>}</div>
            {spark && spark.some((v) => v !== 0) && <div className="mt-2"><Sparkline values={spark} colour={colour ?? CHART.occupancy} /></div>}
        </div>
    );
}

function TrendTable({ rows }: { rows: any[] }) {
    return (
        <details className="mt-3 text-sm">
            <summary className="cursor-pointer text-xs font-medium text-muted hover:text-chocolate">See these figures as a table</summary>
            <div className="mt-2 overflow-x-auto" tabIndex={0} role="region" aria-label="Monthly figures">
                <table className="w-full text-right text-xs">
                    <thead className="text-muted">
                        <tr><th scope="col" className="py-1 text-left font-medium">Month</th><th scope="col" className="font-medium">Earned</th><th scope="col" className="font-medium">Collected</th><th scope="col" className="font-medium">Expenses</th><th scope="col" className="font-medium">Occupancy</th></tr>
                    </thead>
                    <tbody className="tabular-nums">
                        {rows.map((r) => (
                            <tr key={r.month} className="border-t border-line/70">
                                <th scope="row" className="py-1 text-left font-normal">{r.month}</th>
                                <td>{pesoWhole(r.earned * 100)}</td><td>{pesoWhole(r.collected * 100)}</td><td>{pesoWhole(r.expenses * 100)}</td><td>{r.occupancy}%</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}

function greeting(): string {
    const hour = Number(new Intl.DateTimeFormat('en-PH', { hour: 'numeric', hourCycle: 'h23', timeZone: 'Asia/Manila' }).format(new Date()));

    return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
}

export default function Dashboard({ actions, today, board, forecast, period, money, performance, team, date }: any) {
    const { auth } = usePage<any>().props as any;
    const can = (p: string) => auth.user.permissions.includes(p);
    const firstName = String(auth.user.name).split(' ')[0];

    const forecastData = useMemo(() => forecast.map((d: any, i: number) => {
        const [y, m, day] = d.date.split('-').map(Number);
        const weekday = new Date(Date.UTC(y, m - 1, day)).toLocaleDateString('en-PH', { weekday: 'short', timeZone: 'UTC' });

        return { ...d, label: i === 0 ? 'Today' : `${weekday} ${day}` };
    }), [forecast]);
    const nextBusy = forecast.reduce((sum: number, d: any) => sum + d.rooms, 0);

    const trend = performance?.trend ?? [];
    const hasTrend = trend.some((r: any) => r.earned || r.collected || r.expenses);
    const spark = (key: string) => trend.map((r: any) => r[key]);
    const topChannel = Math.max(...(performance?.channels ?? []).map((c: any) => c.earned_cents), 1);
    const topRoom = Math.max(...(performance?.rooms ?? []).map((r: any) => r.earned_cents), 1);
    const channels = [...(performance?.channels ?? [])].sort((a: any, b: any) => b.earned_cents - a.earned_cents);

    const setPeriod = (key: string) => router.get('/admin', key === 'month' ? {} : { period: key }, { preserveScroll: true, preserveState: true, replace: true });

    const donut = [
        { label: 'Occupied', value: today.occupied, colour: CHART.occupancy },
        { label: 'Vacant', value: today.vacant, colour: CHART.vacant },
        { label: 'Blocked', value: today.blocked, colour: SERIES.expenses },
    ];

    return (
        <AdminLayout
            title="Dashboard"
            heading={`${greeting()}, ${firstName}`}
            showSection={false}
            subtitle={`${dateOnly(date)}. Here is what is happening at your suites.`}
            actions={(
                <>
                    {can('bookings.view') && <Link href="/admin/calendar" className={buttonClass('secondary')}><CalendarDays size={15} aria-hidden /> Calendar</Link>}
                    {can('quotes.manage') && <Link href="/admin/quotes/create" className={buttonClass('secondary')}><Receipt size={15} aria-hidden /> New quote</Link>}
                    {can('bookings.manage') && <Link href="/admin/bookings/create" className={buttonClass('primary')}><Plus size={15} aria-hidden /> New booking</Link>}
                </>
            )}
        >
            {actions.length === 0 ? (
                <p className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950"><CheckCircle2 size={17} aria-hidden /> All clear. Nothing needs your attention right now.</p>
            ) : (
                <section aria-label="Needs your attention">
                    <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold"><AlertTriangle size={15} className="text-amber-800" aria-hidden /> Needs your attention</h2>
                    <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {actions.map((a: any) => (
                            <li key={a.label}>
                                <Link href={a.href} className={cn('group flex h-full items-center gap-3 rounded-xl border px-3 py-2.5 text-sm transition-colors pointer-coarse:min-h-11', a.tone === 'danger' ? 'border-red-200 bg-red-50 text-red-950 hover:border-red-400' : 'border-amber-200 bg-amber-50 text-amber-950 hover:border-amber-400')}>
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/70 text-base font-semibold">{a.count}</span>
                                    <span className="min-w-0 flex-1 first-letter:uppercase">{a.label}</span>
                                    <ChevronRight size={16} className="shrink-0 transition-transform group-hover:translate-x-0.5" aria-hidden />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <SectionTitle title="Today" />
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Guests staying" value={<>{today.occupied} <span className="text-sm font-normal text-muted">of {today.rooms} rooms</span></>} icon={BedDouble} />
                <Stat label="Arriving today" value={today.arrivals.length} icon={LogIn} />
                <Stat label="Leaving today" value={today.departures.length} icon={LogOut} />
                <Stat label="Rooms to get ready" value={today.not_ready} icon={Sparkles} href={can('housekeeping.manage') || can('housekeeping.inspect') ? '/admin/housekeeping' : undefined} />
            </div>

            <div className="mt-3 grid gap-3 xl:grid-cols-3">
                <Card title="Your rooms right now" className="xl:col-span-2">
                    {board.length === 0 ? <Empty>No rooms yet.</Empty> : <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">{board.map((r: any) => <RoomTile key={r.id} room={r} />)}</div>}
                </Card>
                <div className="grid content-start gap-3">
                    <Card title="Rooms today">
                        <RoomsDonut segments={donut} label={`${today.occupied} occupied, ${today.vacant} vacant and ${today.blocked} blocked out of ${today.rooms} rooms.`} centre={<><span className="text-3xl font-semibold">{today.rooms ? Math.round((today.occupied / today.rooms) * 100) : 0}%</span><span className="text-xs text-muted">occupied</span></>} />
                        <div className="mt-3 flex justify-center"><Legend items={donut.map((d) => ({ label: d.label, colour: d.colour, value: d.value }))} /></div>
                    </Card>
                    <Stat label="Parking in use" value={today.parking_in_use} icon={Car} href={can('parking.view') ? '/admin/parking' : undefined} />
                </div>
            </div>

            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <Agenda title="Arrivals" icon={LogIn} rows={today.arrivals} empty="No arrivals today." />
                <Agenda title="Departures" icon={LogOut} rows={today.departures} empty="No departures today." />
            </div>

            <div className="mt-3">
                <Card title="Next 14 days" action={<span className="text-xs text-muted">Rooms booked each day</span>}>
                    {nextBusy === 0 ? <Empty>No rooms are booked in the next 14 days yet.</Empty> : <ForecastChart data={forecastData} rooms={today.rooms} />}
                </Card>
            </div>

            {money && performance && (
                <>
                    <SectionTitle
                        title="Money and performance"
                        right={(
                            <div role="group" aria-label="Period" className="inline-flex rounded-lg border border-line bg-white p-0.5 shadow-xs">
                                {PERIODS.map((p) => (
                                    <button key={p.key} type="button" aria-pressed={period.key === p.key} onClick={() => setPeriod(p.key)} className={cn('rounded-md px-3 py-1.5 text-xs font-medium transition-colors pointer-coarse:min-h-11', period.key === p.key ? 'bg-chocolate text-ivory' : 'text-muted hover:text-chocolate')}>{p.label}</button>
                                ))}
                            </div>
                        )}
                    >
                        {shortDate(period.from)} to {shortDate(period.to)}. The small lines show the last 12 months.
                    </SectionTitle>

                    <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                        <Kpi label="Earned from stays" value={pesoWhole(money.earned_cents)} icon={CircleDollarSign} spark={spark('earned')} colour={SERIES.earned}><Delta now={money.earned_cents} prior={money.earned_prior_cents} compare={period.compare} /></Kpi>
                        <Kpi label="Money collected" value={pesoWhole(money.collected_cents)} icon={Wallet} spark={spark('collected')} colour={SERIES.collected}><Delta now={money.collected_cents} prior={money.collected_prior_cents} compare={period.compare} /></Kpi>
                        <Kpi label="Expenses" value={pesoWhole(money.expenses_cents)} icon={Receipt} spark={spark('expenses')} colour={SERIES.expenses}><Delta now={money.expenses_cents} prior={money.expenses_prior_cents} compare={period.compare} upIsGood={false} /></Kpi>
                        <Kpi label="Left after expenses" value={pesoWhole(money.contribution_cents)} icon={HandCoins}><Delta now={money.contribution_cents} prior={money.contribution_prior_cents} compare={period.compare} /><p className="mt-1 text-xs text-muted">Not audited net income</p></Kpi>
                        <Kpi label="Occupancy" value={performance.occupancy_pct === null ? '—' : `${performance.occupancy_pct}%`} icon={PieChart} spark={spark('occupancy')}>
                            <Delta now={performance.occupancy_pct} prior={performance.occupancy_prior_pct} compare={period.compare} format="points" />
                            <p className="mt-1 text-xs text-muted">{performance.occupied_nights} of {performance.available_nights} room nights</p>
                        </Kpi>
                        <Kpi label="Average rate per night" value={pesoWhole(performance.adr_cents)} icon={BedDouble}><Delta now={performance.adr_cents} prior={performance.adr_prior_cents} compare={period.compare} /><p className="mt-1 text-xs text-muted">ADR</p></Kpi>
                        <Kpi label="Earned per available room" value={pesoWhole(performance.revpar_cents)} icon={DoorOpen}><Delta now={performance.revpar_cents} prior={performance.revpar_prior_cents} compare={period.compare} /><p className="mt-1 text-xs text-muted">RevPAR</p></Kpi>
                        <Kpi label="Still to collect" value={pesoWhole(money.outstanding_cents)} icon={ClipboardCheck} note={`As of today. ${pesoWhole(money.collected_today_cents)} collected today, ${pesoWhole(money.expected_payout_cents)} expected from channels.`} />
                    </div>

                    <div className="mt-3 grid gap-3 xl:grid-cols-3">
                        <Card title="Earned, collected and expenses" className="xl:col-span-2" action={<Legend items={[{ label: 'Earned', colour: SERIES.earned }, { label: 'Collected', colour: SERIES.collected }, { label: 'Expenses', colour: SERIES.expenses }]} />}>
                            {hasTrend ? <><MoneyTrendChart data={trend} /><p className="mt-1 text-xs text-muted">The latest month is still in progress, so its line drops until the month is over.</p><TrendTable rows={trend} /></> : <Empty>The chart fills in as stays and payments are recorded.</Empty>}
                        </Card>
                        <Card title="Occupancy by month">
                            {hasTrend ? <OccupancyTrendChart data={trend} /> : <Empty>No stays recorded yet.</Empty>}
                        </Card>
                    </div>

                    <div className="mt-3 grid gap-3 lg:grid-cols-2">
                        <Card title="Where bookings come from" action={<Link href="/admin/reports?type=channels" className="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-chocolate pointer-coarse:min-h-11">Report <ArrowRight size={12} aria-hidden /></Link>}>
                            {channels.length === 0 ? <Empty>No bookings in this period.</Empty> : (
                                <ul className="space-y-3">
                                    {channels.map((c: any) => (
                                        <li key={c.name} className="text-sm">
                                            <div className="mb-1 flex items-baseline justify-between gap-3">
                                                <span className="min-w-0 truncate font-medium">{c.name}</span>
                                                <span className="shrink-0 text-xs text-muted">{c.confirmed} confirmed · <strong className="font-semibold text-chocolate tabular-nums">{pesoWhole(c.earned_cents)}</strong></span>
                                            </div>
                                            <ValueBar share={(c.earned_cents / topChannel) * 100} colour={SERIES.earned} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                        <Card title="How each room is doing">
                            {performance.rooms.length === 0 ? <Empty>No rooms yet.</Empty> : (
                                <ul className="space-y-3">
                                    {performance.rooms.map((r: any) => (
                                        <li key={r.name} className="text-sm">
                                            <div className="mb-1 flex items-baseline justify-between gap-3">
                                                <span className="min-w-0 truncate font-medium">{r.name}</span>
                                                <span className="shrink-0 text-xs text-muted">{r.nights} night{r.nights === 1 ? '' : 's'} · {r.booked_pct}% booked · <strong className="font-semibold text-chocolate tabular-nums">{pesoWhole(r.earned_cents)}</strong></span>
                                            </div>
                                            <ValueBar share={(r.earned_cents / topRoom) * 100} colour={SERIES.earned} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <details className="mt-3 rounded-xl border border-line/80 bg-white px-5 py-3 text-sm shadow-xs">
                        <summary className="flex cursor-pointer items-center gap-2 font-medium"><HelpCircle size={15} className="text-bronze" aria-hidden /> What do these numbers mean?</summary>
                        <dl className="mt-3 grid gap-x-8 gap-y-3 text-xs md:grid-cols-2">
                            {[['Earned from stays', 'earned'], ['Money collected', 'collected'], ['Expenses', 'expenses'], ['Left after expenses', 'contribution'], ['Occupancy', 'occupancy'], ['Average rate per night', 'adr'], ['Earned per available room', 'revpar'], ['Still to collect', 'outstanding']].map(([label, key]) => (
                                <div key={key}><dt className="font-semibold">{label}</dt><dd className="mt-0.5 text-muted">{money.definitions[key]}</dd></div>
                            ))}
                        </dl>
                        <p className="mt-3 text-xs text-muted">Each figure uses its own dates, so they do not add up across tiles. Amounts here are rounded to the peso. For exact amounts, use <Link href="/admin/reports" className="underline">Reports</Link>.</p>
                    </details>
                </>
            )}

            <SectionTitle title="Team and upkeep" />
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Stat label="Cleaning tasks open" value={team.open_tasks} icon={Sparkles} href={can('housekeeping.manage') ? '/admin/housekeeping' : undefined} />
                <Stat label="Cleaning tasks late" value={team.late_tasks} icon={AlertTriangle} href={can('housekeeping.manage') ? '/admin/housekeeping' : undefined} />
                <Stat label="Waiting for inspection" value={team.for_inspection} icon={ClipboardCheck} href={can('housekeeping.inspect') ? '/admin/housekeeping' : undefined} />
                <Stat label="Laundry open" value={team.open_laundry} icon={Shirt} href={can('housekeeping.manage') ? '/admin/laundry' : undefined} />
                <Stat label="Repairs open" value={team.open_work_orders} icon={Wrench} href={can('maintenance.manage') ? '/admin/work-orders' : undefined} />
                <Stat label="Supplies running low" value={team.low_stock} icon={Package} href={can('inventory.view') ? '/admin/inventory' : undefined} />
            </div>
        </AdminLayout>
    );
}
