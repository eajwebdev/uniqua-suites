import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle, ArrowRight, Ban, BedDouble, CalendarCheck, CalendarDays, Car, CheckCircle2, ChevronRight, CircleDollarSign, ClipboardCheck, DoorOpen, Hourglass, HandCoins, HelpCircle, LayoutDashboard, LogIn, LogOut, MessageCircle, Minus, Package, PieChart, Plus, Receipt, Shirt, ShieldAlert, Sparkles, TrendingDown,
    TrendingUp, Wallet, Wrench, XCircle,
} from 'lucide-react';
import { KeyboardEvent, ReactNode, useEffect, useMemo, useState } from 'react';
import { CHART, ExpenseTrendChart, ForecastChart, Legend, MoneyTrendChart, OccupancyTrendChart, RoomsDonut, SERIES, Sparkline, ValueBar } from '@/components/charts';
import { openNewBooking } from '@/components/NewBooking';
import { buttonClass, Card, cn, Empty, Stat, StatusChip } from '@/components/ui';
import { dateOnly, dateTime, pesoWhole, shortDate, time12 } from '@/lib/format';
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

const METHODS: Record<string, string> = { cash: 'Cash', gcash: 'GCash', bank_transfer: 'Bank transfer', other: 'Other' };

function Definitions({ definitions, keys }: { definitions: Record<string, string>; keys: [string, string][] }) {
    return (
        <details className="mt-3 rounded-xl border border-line/80 bg-white px-5 py-3 text-sm shadow-xs">
            <summary className="flex cursor-pointer items-center gap-2 font-medium"><HelpCircle size={15} className="text-bronze" aria-hidden /> What do these numbers mean?</summary>
            <dl className="mt-3 grid gap-x-8 gap-y-3 text-xs md:grid-cols-2">
                {keys.map(([label, key]) => <div key={key}><dt className="font-semibold">{label}</dt><dd className="mt-0.5 text-muted">{definitions[key]}</dd></div>)}
            </dl>
            <p className="mt-3 text-xs text-muted">Each figure uses its own dates, so they do not add up across tiles. Amounts here are rounded to the peso. For exact amounts, use <Link href="/admin/reports" className="underline">Reports</Link>.</p>
        </details>
    );
}

function MoreLink({ href, children }: { href: string; children: ReactNode }) {
    return <Link href={href} className="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-chocolate pointer-coarse:min-h-11">{children} <ArrowRight size={12} aria-hidden /></Link>;
}

/** A named amount with a bar, longest first. A net negative amount shows no bar. */
function BarList({ rows, colour, empty, right }: { rows: { name: string; cents: number }[]; colour: string; empty: string; right?: (row: any) => ReactNode }) {
    const top = Math.max(...rows.map((r) => r.cents), 1);
    if (rows.length === 0) return <Empty>{empty}</Empty>;

    return (
        <ul className="space-y-3">
            {rows.map((r) => (
                <li key={r.name} className="text-sm">
                    <div className="mb-1 flex items-baseline justify-between gap-3">
                        <span className="min-w-0 truncate font-medium">{r.name}</span>
                        <span className="shrink-0 text-xs text-muted">{right?.(r)} <strong className="font-semibold text-chocolate tabular-nums">{pesoWhole(r.cents)}</strong></span>
                    </div>
                    <ValueBar share={(Math.max(r.cents, 0) / top) * 100} colour={colour} />
                </li>
            ))}
        </ul>
    );
}

function BookingList({ rows, empty, showStatus }: { rows: any[]; empty: string; showStatus?: boolean }) {
    if (rows.length === 0) return <Empty>{empty}</Empty>;

    return (
        <ul className="-my-1 divide-y divide-line/70">
            {rows.map((b) => (
                <li key={b.id} className="py-2.5 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Link href={`/admin/bookings/${b.id}`} className="inline-block min-w-0 truncate font-medium hover:underline pointer-coarse:py-3">{b.guest}</Link>
                        {showStatus ? <StatusChip value={b.status} /> : <span className="text-xs text-muted">{b.source}</span>}
                    </div>
                    <p className="mt-0.5 text-xs text-muted">
                        {shortDate(b.check_in)} to {shortDate(b.check_out)} · {b.nights} night{b.nights === 1 ? '' : 's'}{b.rooms.length > 0 && ` · ${b.rooms.join(', ')}`}
                        {b.hold_expires_at && ` · hold ends ${dateTime(b.hold_expires_at)}`}
                    </p>
                </li>
            ))}
        </ul>
    );
}

function ExpenseList({ rows, empty }: { rows: any[]; empty: string }) {
    if (rows.length === 0) return <Empty>{empty}</Empty>;

    return (
        <ul className="-my-1 divide-y divide-line/70">
            {rows.map((e) => (
                <li key={e.id} className="flex items-start justify-between gap-3 py-2.5 text-sm">
                    <div className="min-w-0">
                        <p className="truncate font-medium">{e.description}</p>
                        <p className="mt-0.5 text-xs text-muted">{shortDate(e.date)} · {e.category} · {e.where}{e.vendor && ` · ${e.vendor}`}</p>
                    </div>
                    <span className="shrink-0 font-semibold tabular-nums">{pesoWhole(e.amount_cents)}</span>
                </li>
            ))}
        </ul>
    );
}

function Overview({ actions, today, forecastData, nextBusy, money, performance, period, donut, can, openTab }: any) {
    return (
        <>
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

            {money && performance && (
                <>
                    <SectionTitle
                        title="Money at a glance"
                        right={(
                            <div className="flex gap-3">
                                <button type="button" onClick={() => openTab('income')} className="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-chocolate pointer-coarse:min-h-11">Income <ArrowRight size={12} aria-hidden /></button>
                                <button type="button" onClick={() => openTab('expenses')} className="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-chocolate pointer-coarse:min-h-11">Expenses <ArrowRight size={12} aria-hidden /></button>
                            </div>
                        )}
                    >
                        {shortDate(period.from)} to {shortDate(period.to)}
                    </SectionTitle>
                    <div className="grid grid-cols-2 gap-3 xl:grid-cols-3">
                        <Kpi label="Earned from stays" value={pesoWhole(money.earned_cents)} icon={CircleDollarSign}><Delta now={money.earned_cents} prior={money.earned_prior_cents} compare={period.compare} /></Kpi>
                        <Kpi label="Money collected" value={pesoWhole(money.collected_cents)} icon={Wallet}><Delta now={money.collected_cents} prior={money.collected_prior_cents} compare={period.compare} /></Kpi>
                        <Kpi label="Still to collect" value={pesoWhole(money.outstanding_cents)} icon={ClipboardCheck} note="As of today" />
                        <Kpi label="Expenses" value={pesoWhole(money.expenses_cents)} icon={Receipt}><Delta now={money.expenses_cents} prior={money.expenses_prior_cents} compare={period.compare} upIsGood={false} /></Kpi>
                        <Kpi label="Left after expenses" value={pesoWhole(money.contribution_cents)} icon={HandCoins}><Delta now={money.contribution_cents} prior={money.contribution_prior_cents} compare={period.compare} /></Kpi>
                        <Kpi label="Occupancy" value={performance.occupancy_pct === null ? '—' : `${performance.occupancy_pct}%`} icon={PieChart}><Delta now={performance.occupancy_pct} prior={performance.occupancy_prior_pct} compare={period.compare} format="points" /></Kpi>
                    </div>
                </>
            )}

            <SectionTitle title="Who is coming and going" />
            <div className="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
                <Agenda title="Arrivals" icon={LogIn} rows={today.arrivals} empty="No arrivals today." />
                <Agenda title="Departures" icon={LogOut} rows={today.departures} empty="No departures today." />
                <Card title="Rooms today" className="lg:col-span-2 xl:col-span-1" action={<button type="button" onClick={() => openTab('rooms')} className="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-chocolate pointer-coarse:min-h-11">All rooms <ArrowRight size={12} aria-hidden /></button>}>
                    <RoomsDonut segments={donut} label={`${today.occupied} occupied, ${today.vacant} vacant and ${today.blocked} blocked out of ${today.rooms} rooms.`} centre={<><span className="text-3xl font-semibold">{today.rooms ? Math.round((today.occupied / today.rooms) * 100) : 0}%</span><span className="text-xs text-muted">occupied</span></>} />
                    <div className="mt-3 flex justify-center"><Legend items={donut.map((d: any) => ({ label: d.label, colour: d.colour, value: d.value }))} /></div>
                </Card>
            </div>

            <div className="mt-3">
                <Card title="Next 14 days" action={<span className="text-xs text-muted">Rooms booked each day</span>}>
                    {nextBusy === 0 ? <Empty>No rooms are booked in the next 14 days yet.</Empty> : <ForecastChart data={forecastData} rooms={today.rooms} />}
                </Card>
            </div>
        </>
    );
}

function BookingsTab({ data, money, performance, period }: any) {
    return (
        <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
                <Stat label="New bookings" value={data.created} icon={Plus} />
                <Stat label="Confirmed" value={data.confirmed} icon={CheckCircle2} />
                <Stat label="Cancelled" value={<>{data.cancelled}{data.cancelled_pct !== null && <span className="text-sm font-normal text-muted"> {data.cancelled_pct}%</span>}</>} icon={XCircle} />
                <Stat label="Arriving in 14 days" value={data.upcoming_count} icon={CalendarCheck} />
                <Stat label="Open inquiries" value={data.inquiries} icon={MessageCircle} href="/admin/bookings?status=inquiry" />
                <Stat label="Holds waiting" value={data.holds} icon={Hourglass} href="/admin/bookings?status=tentative" />
            </div>
            <p className="mt-2 text-xs text-muted">New, confirmed and cancelled count bookings created in the period. The others are as of today.</p>

            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <Card title="Coming up" action={<MoreLink href="/admin/calendar">Calendar</MoreLink>}>
                    <BookingList rows={data.upcoming} empty="No confirmed arrivals in the next 14 days." />
                </Card>
                <Card title="Waiting on a decision" action={<MoreLink href="/admin/bookings">Reservations</MoreLink>}>
                    <BookingList rows={data.waiting} showStatus empty="No inquiries or holds for upcoming dates." />
                </Card>
            </div>

            <div className="mt-3">
                <Card title="Where bookings come from" action={performance ? <MoreLink href="/admin/reports?type=channels">Report</MoreLink> : undefined}>
                    {data.channels.length === 0 ? <Empty>No bookings in this period.</Empty> : (
                        <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Bookings by source">
                            <table className="w-full text-right text-sm">
                                <thead className="text-xs text-muted">
                                    <tr><th scope="col" className="py-1.5 text-left font-medium">Source</th><th scope="col" className="px-2 font-medium">All</th><th scope="col" className="px-2 font-medium">Confirmed</th><th scope="col" className="px-2 font-medium">Inquiries</th><th scope="col" className="px-2 font-medium">Cancelled</th>{money && <th scope="col" className="pl-2 font-medium">Earned</th>}</tr>
                                </thead>
                                <tbody className="tabular-nums">
                                    {data.channels.map((c: any) => (
                                        <tr key={c.name} className="border-t border-line/70">
                                            <th scope="row" className="py-2 text-left font-medium">{c.name}</th>
                                            <td className="px-2">{c.total}</td><td className="px-2">{c.confirmed}</td><td className="px-2">{c.inquiries}</td><td className="px-2">{c.cancelled}</td>
                                            {money && <td className="pl-2 font-semibold">{pesoWhole(c.earned_cents)}</td>}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {money && <p className="mt-2 text-xs text-muted">Booking counts use the date created ({shortDate(period.from)} to {shortDate(period.to)}). Earned uses the dates of the stay, so the two do not line up exactly.</p>}
                </Card>
            </div>
        </>
    );
}

function RoomsTab({ today, board, forecastData, nextBusy, performance, period, hasTrend, trend, spark, donut }: any) {
    return (
        <>
            {performance && (
                <div className="mb-3 grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <Kpi label="Occupancy" value={performance.occupancy_pct === null ? '—' : `${performance.occupancy_pct}%`} icon={PieChart} spark={spark('occupancy')}>
                        <Delta now={performance.occupancy_pct} prior={performance.occupancy_prior_pct} compare={period.compare} format="points" />
                        <p className="mt-1 text-xs text-muted">{performance.occupied_nights} of {performance.available_nights} room nights</p>
                    </Kpi>
                    <Kpi label="Average rate per night" value={pesoWhole(performance.adr_cents)} icon={BedDouble}><Delta now={performance.adr_cents} prior={performance.adr_prior_cents} compare={period.compare} /><p className="mt-1 text-xs text-muted">ADR</p></Kpi>
                    <Kpi label="Earned per available room" value={pesoWhole(performance.revpar_cents)} icon={DoorOpen}><Delta now={performance.revpar_cents} prior={performance.revpar_prior_cents} compare={period.compare} /><p className="mt-1 text-xs text-muted">RevPAR</p></Kpi>
                    <Stat label="Rooms ready" value={<>{today.ready} <span className="text-sm font-normal text-muted">of {today.rooms}</span></>} icon={Sparkles} />
                </div>
            )}

            <div className="grid gap-3 xl:grid-cols-3">
                <Card title="Your rooms right now" className="xl:col-span-2">
                    {board.length === 0 ? <Empty>No rooms yet.</Empty> : <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">{board.map((r: any) => <RoomTile key={r.id} room={r} />)}</div>}
                </Card>
                <Card title="Rooms today">
                    <RoomsDonut segments={donut} label={`${today.occupied} occupied, ${today.vacant} vacant and ${today.blocked} blocked out of ${today.rooms} rooms.`} centre={<><span className="text-3xl font-semibold">{today.rooms ? Math.round((today.occupied / today.rooms) * 100) : 0}%</span><span className="text-xs text-muted">occupied</span></>} />
                    <div className="mt-3 flex justify-center"><Legend items={donut.map((d: any) => ({ label: d.label, colour: d.colour, value: d.value }))} /></div>
                </Card>
            </div>

            {performance && (
                <div className="mt-3 grid gap-3 lg:grid-cols-2">
                    <Card title="How each room is doing">
                        {performance.rooms.length === 0 ? <Empty>No rooms yet.</Empty> : (
                            <BarList rows={performance.rooms.map((r: any) => ({ ...r, cents: r.earned_cents }))} colour={SERIES.earned} empty="" right={(r) => <>{r.nights} night{r.nights === 1 ? '' : 's'} · {r.booked_pct}% booked ·</>} />
                        )}
                    </Card>
                    <Card title="Occupancy by month">
                        {hasTrend ? <OccupancyTrendChart data={trend} /> : <Empty>No stays recorded yet.</Empty>}
                    </Card>
                </div>
            )}

            <div className="mt-3">
                <Card title="Next 14 days" action={<span className="text-xs text-muted">Rooms booked each day</span>}>
                    {nextBusy === 0 ? <Empty>No rooms are booked in the next 14 days yet.</Empty> : <ForecastChart data={forecastData} rooms={today.rooms} />}
                </Card>
            </div>
        </>
    );
}

function IncomeTab({ money, income, period, hasTrend, trend, spark }: any) {
    const inflow = [
        ...income.by_method.map((m: any) => ({ name: METHODS[m.method] ?? m.method, cents: m.cents })),
        ...(income.payouts_cents ? [{ name: 'Channel payouts', cents: income.payouts_cents }] : []),
    ];

    return (
        <>
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-3">
                <Kpi label="Earned from stays" value={pesoWhole(money.earned_cents)} icon={CircleDollarSign} spark={spark('earned')} colour={SERIES.earned}><Delta now={money.earned_cents} prior={money.earned_prior_cents} compare={period.compare} /></Kpi>
                <Kpi label="Money collected" value={pesoWhole(money.collected_cents)} icon={Wallet} spark={spark('collected')} colour={SERIES.collected}><Delta now={money.collected_cents} prior={money.collected_prior_cents} compare={period.compare} /></Kpi>
                <Kpi label="Left after expenses" value={pesoWhole(money.contribution_cents)} icon={HandCoins}><Delta now={money.contribution_cents} prior={money.contribution_prior_cents} compare={period.compare} /><p className="mt-1 text-xs text-muted">Not audited net income</p></Kpi>
                <Kpi label="Still to collect" value={pesoWhole(money.outstanding_cents)} icon={ClipboardCheck} note={`As of today, across ${income.unpaid_count} booking${income.unpaid_count === 1 ? '' : 's'}`} />
                <Kpi label="Payments to verify" value={pesoWhole(income.pending_cents)} icon={Hourglass} note={`${income.pending_count} waiting. Not counted as collected yet.`} />
                <Kpi label="Other charges" value={pesoWhole(income.other_charges_cents)} icon={Receipt} note={`Extras on stays. ${pesoWhole(money.expected_payout_cents)} expected from channels. ${pesoWhole(money.collected_today_cents)} collected today.`} />
            </div>

            <div className="mt-3 grid gap-3 xl:grid-cols-3">
                <Card title="Earned, collected and expenses" className="xl:col-span-2" action={<Legend items={[{ label: 'Earned', colour: SERIES.earned }, { label: 'Collected', colour: SERIES.collected }, { label: 'Expenses', colour: SERIES.expenses }]} />}>
                    {hasTrend ? <><MoneyTrendChart data={trend} /><p className="mt-1 text-xs text-muted">The latest month is still in progress, so its line drops until the month is over.</p><TrendTable rows={trend} /></> : <Empty>The chart fills in as stays and payments are recorded.</Empty>}
                </Card>
                <Card title="How money came in">
                    <BarList rows={inflow} colour={SERIES.collected} empty="No verified payments in this period." />
                    {income.refunds_cents > 0 && <p className="mt-3 flex justify-between border-t border-line/70 pt-2 text-sm"><span>Refunds paid</span><strong className="font-semibold tabular-nums">-{pesoWhole(income.refunds_cents)}</strong></p>}
                    {inflow.length > 0 && <p className="mt-3 flex justify-between border-t border-line/70 pt-2 text-sm font-semibold"><span>Collected</span><span className="tabular-nums">{pesoWhole(money.collected_cents)}</span></p>}
                </Card>
            </div>

            {income.unpaid && (
                <div className="mt-3">
                    <Card title="Largest unpaid balances" action={<MoreLink href="/admin/reports?type=aging">Full list</MoreLink>}>
                        {income.unpaid.length === 0 ? <Empty>Nothing to collect right now.</Empty> : (
                            <ul className="-my-1 divide-y divide-line/70">
                                {income.unpaid.map((b: any) => (
                                    <li key={b.booking_id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                                        <div className="min-w-0">
                                            <Link href={`/admin/bookings/${b.booking_id}`} className="block truncate font-medium hover:underline pointer-coarse:py-2">{b.guest}</Link>
                                            <p className="text-xs text-muted">{b.reference} · {b.source} · check-in {shortDate(b.check_in)}{b.days_past_check_in > 0 && `, ${b.days_past_check_in} day${b.days_past_check_in === 1 ? '' : 's'} ago`}</p>
                                        </div>
                                        <span className="shrink-0 font-semibold tabular-nums">{pesoWhole(b.balance_cents)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            )}

            <Definitions definitions={money.definitions} keys={[['Earned from stays', 'earned'], ['Money collected', 'collected'], ['Left after expenses', 'contribution'], ['Still to collect', 'outstanding']]} />
        </>
    );
}

function ExpensesTab({ money, expenses, period, trend, spark }: any) {
    return (
        <>
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <Kpi label="Total expenses" value={pesoWhole(money.expenses_cents)} icon={Receipt} spark={spark('expenses')} colour={SERIES.expenses}><Delta now={money.expenses_cents} prior={money.expenses_prior_cents} compare={period.compare} upIsGood={false} /></Kpi>
                <Kpi label="Paid" value={pesoWhole(expenses.paid_cents)} icon={CheckCircle2} note="Cash already out, for this period" />
                <Kpi label="Not yet paid" value={pesoWhole(expenses.unpaid_cents)} icon={Hourglass} note="Recorded in this period, still owed" />
                <Kpi label="Share of revenue" value={expenses.share_of_revenue_pct === null ? '—' : `${expenses.share_of_revenue_pct}%`} icon={PieChart} note={`Expenses against ${pesoWhole(money.gross_cents)} earned, with extras`} />
            </div>

            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <Card title="By category" action={<MoreLink href="/admin/reports?type=expenses">Report</MoreLink>}>
                    <BarList rows={expenses.by_category} colour={SERIES.expenses} empty="No expenses in this period." right={(c) => <span>{c.prior_cents ? `was ${pesoWhole(c.prior_cents)} ·` : 'new ·'}</span>} />
                </Card>
                <Card title="By room">
                    <BarList rows={expenses.by_room} colour={SERIES.expenses} empty="No expenses in this period." />
                    {expenses.by_room.length > 0 && <p className="mt-3 text-xs text-muted">General covers costs not tied to one room, such as shared bills.</p>}
                </Card>
            </div>

            <div className="mt-3">
                <Card title="Expenses by month">
                    {trend.some((r: any) => r.expenses) ? <ExpenseTrendChart data={trend} /> : <Empty>The chart fills in as expenses are recorded.</Empty>}
                </Card>
            </div>

            {expenses.bills && (
                <div className="mt-3 grid gap-3 lg:grid-cols-2">
                    <Card title={<>Bills still to pay <span className="rounded-full bg-beige px-2 text-xs font-medium">{expenses.bills_count}</span></>} action={<MoreLink href="/admin/expenses?status=incurred">{pesoWhole(expenses.bills_cents)} in all</MoreLink>}>
                        <ExpenseList rows={expenses.bills} empty="No unpaid bills, from any date." />
                    </Card>
                    <Card title="Largest in this period" action={<MoreLink href="/admin/expenses">All expenses</MoreLink>}>
                        <ExpenseList rows={expenses.largest} empty="No expenses in this period." />
                    </Card>
                </div>
            )}

            <Definitions definitions={money.definitions} keys={[['Total expenses', 'expenses'], ['Left after expenses', 'contribution']]} />
        </>
    );
}

function OperationsTab({ team, today, operations, can }: any) {
    const counts = operations.tasks_by_status;
    const steps = [['Not started', (counts.pending ?? 0) + (counts.assigned ?? 0)], ['In progress', counts.in_progress ?? 0], ['For inspection', counts.for_inspection ?? 0]];

    return (
        <>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Cleaning tasks open" value={team.open_tasks} icon={Sparkles} href={can('housekeeping.manage') ? '/admin/housekeeping' : undefined} />
                <Stat label="Cleaning tasks late" value={team.late_tasks} icon={AlertTriangle} href={can('housekeeping.manage') ? '/admin/housekeeping' : undefined} />
                <Stat label="Waiting for inspection" value={team.for_inspection} icon={ClipboardCheck} href={can('housekeeping.inspect') ? '/admin/housekeeping' : undefined} />
                <Stat label="Laundry open" value={team.open_laundry} icon={Shirt} href={can('housekeeping.manage') ? '/admin/laundry' : undefined} />
                <Stat label="Repairs open" value={team.open_work_orders} icon={Wrench} href={can('maintenance.manage') ? '/admin/work-orders' : undefined} />
                <Stat label="Supplies running low" value={team.low_stock} icon={Package} href={can('inventory.view') ? '/admin/inventory' : undefined} />
                <Stat label="Damage claims open" value={operations.open_incidents} icon={ShieldAlert} href={can('incidents.view') ? '/admin/incidents' : undefined} />
                <Stat label="Parking in use" value={today.parking_in_use} icon={Car} href={can('parking.view') ? '/admin/parking' : undefined} />
            </div>

            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                {operations.tasks && (
                    <Card title="Cleaning tasks, soonest first" action={<MoreLink href="/admin/housekeeping">Cleaning</MoreLink>} className="lg:col-span-2">
                        <dl className="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted">
                            {steps.map(([label, n]) => <div key={label} className="flex gap-1.5"><dt>{label}</dt><dd className="font-semibold text-chocolate tabular-nums">{n}</dd></div>)}
                        </dl>
                        {operations.tasks.length === 0 ? <Empty>No cleaning tasks open.</Empty> : (
                            <ul className="-my-1 divide-y divide-line/70">
                                {operations.tasks.map((t: any) => (
                                    <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">{t.room}: {t.title}</p>
                                            <p className={cn('mt-0.5 text-xs', t.late ? 'font-semibold text-red-800' : 'text-muted')}>{t.late ? 'Late. ' : ''}{t.due_at ? `Due ${dateTime(t.due_at)}` : 'No due time'}<span className="font-normal text-muted"> · {t.assignee ?? 'Not assigned'}</span></p>
                                        </div>
                                        <StatusChip value={t.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
                {operations.repairs && (
                    <Card title="Repairs, most serious first" action={<MoreLink href="/admin/work-orders">Maintenance</MoreLink>}>
                        {operations.repairs.length === 0 ? <Empty>No repairs open.</Empty> : (
                            <ul className="-my-1 divide-y divide-line/70">
                                {operations.repairs.map((w: any) => (
                                    <li key={w.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">{w.title}</p>
                                            <p className="mt-0.5 text-xs text-muted first-letter:uppercase">{w.severity} · {w.room ?? 'General'}</p>
                                        </div>
                                        <StatusChip value={w.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
                {operations.low_stock && (
                    <Card title="Supplies to reorder" action={<MoreLink href="/admin/inventory">Inventory</MoreLink>}>
                        {operations.low_stock.length === 0 ? <Empty>Supplies are above their reorder levels.</Empty> : (
                            <ul className="-my-1 divide-y divide-line/70">
                                {operations.low_stock.map((s: any) => (
                                    <li key={`${s.id}-${s.property}`} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">{s.name}</p>
                                            <p className="mt-0.5 text-xs text-muted">{s.property} · reorder at {s.reorder_level} {s.unit}</p>
                                        </div>
                                        <span className="shrink-0 font-semibold tabular-nums">{s.on_hand} {s.unit} left</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
            </div>
        </>
    );
}

const TAB_LIST = [
    { key: 'overview', label: 'Overview', icon: LayoutDashboard },
    { key: 'bookings', label: 'Bookings', icon: CalendarDays },
    { key: 'rooms', label: 'Rooms', icon: BedDouble },
    { key: 'income', label: 'Income', icon: Wallet },
    { key: 'expenses', label: 'Expenses', icon: Receipt },
    { key: 'operations', label: 'Operations', icon: Wrench },
];

export default function Dashboard({ actions, today, board, forecast, period, money, performance, bookingsTab, income, expenses, operations, team, date }: any) {
    const { auth } = usePage<any>().props as any;
    const can = (p: string) => auth.user.permissions.includes(p);
    const firstName = String(auth.user.name).split(' ')[0];

    const tabs = TAB_LIST.filter((t) => (t.key === 'bookings' ? bookingsTab : t.key === 'income' ? income : t.key === 'expenses' ? expenses : true));
    // Kept in the address, so a refresh, the back button or a shared link returns to the same tab.
    const [tab, setTab] = useState(() => {
        const wanted = new URLSearchParams(window.location.search).get('tab');

        return tabs.some((t) => t.key === wanted) ? (wanted as string) : 'overview';
    });
    const openTab = (key: string) => {
        setTab(key);
        const url = new URL(window.location.href);
        if (key === 'overview') url.searchParams.delete('tab');
        else url.searchParams.set('tab', key);
        window.history.replaceState(window.history.state, '', url);
    };
    // On a phone the tabs scroll sideways, so the open one must not sit out of sight.
    useEffect(() => { document.getElementById(`tab-${tab}`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }, [tab]);
    const onTabKey = (e: KeyboardEvent, index: number) => {
        const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (!step) return;
        e.preventDefault();
        const next = tabs[(index + step + tabs.length) % tabs.length];
        openTab(next.key);
        document.getElementById(`tab-${next.key}`)?.focus();
    };

    const forecastData = useMemo(() => forecast.map((d: any, i: number) => {
        const [y, m, day] = d.date.split('-').map(Number);
        const weekday = new Date(Date.UTC(y, m - 1, day)).toLocaleDateString('en-PH', { weekday: 'short', timeZone: 'UTC' });

        return { ...d, label: i === 0 ? 'Today' : `${weekday} ${day}` };
    }), [forecast]);
    const nextBusy = forecast.reduce((sum: number, d: any) => sum + d.rooms, 0);

    const trend = performance?.trend ?? [];
    const hasTrend = trend.some((r: any) => r.earned || r.collected || r.expenses);
    const spark = (key: string) => trend.map((r: any) => r[key]);

    const setPeriod = (key: string) => router.get('/admin', { ...(key === 'month' ? {} : { period: key }), ...(tab === 'overview' ? {} : { tab }) }, { preserveScroll: true, preserveState: true, replace: true });
    const usesPeriod = tab === 'bookings' || tab === 'income' || tab === 'expenses' || ((tab === 'overview' || tab === 'rooms') && !!money);

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
                    {can('bookings.manage') && <button type="button" onClick={() => openNewBooking()} className={buttonClass('primary')}><Plus size={15} aria-hidden /> New booking</button>}
                </>
            )}
        >
            <div className="mb-5 flex flex-wrap items-end justify-between gap-x-4 gap-y-3 border-b border-line">
                <div role="tablist" aria-label="Dashboard sections" className="-mb-px flex max-w-full gap-1 overflow-x-auto">
                    {tabs.map((t, i) => (
                        <button
                            key={t.key}
                            id={`tab-${t.key}`}
                            type="button"
                            role="tab"
                            aria-selected={tab === t.key}
                            aria-controls="dashboard-panel"
                            tabIndex={tab === t.key ? 0 : -1}
                            onClick={() => openTab(t.key)}
                            onKeyDown={(e) => onTabKey(e, i)}
                            className={cn('flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm whitespace-nowrap transition-colors pointer-coarse:min-h-11', tab === t.key ? 'border-gold-dark font-semibold text-chocolate' : 'border-transparent text-muted hover:text-chocolate')}
                        >
                            <t.icon size={15} strokeWidth={1.75} aria-hidden /> {t.label}
                        </button>
                    ))}
                </div>
                {usesPeriod && (
                    <div role="group" aria-label="Period" className="mb-2 inline-flex rounded-lg border border-line bg-white p-0.5 shadow-xs">
                        {PERIODS.map((p) => (
                            <button key={p.key} type="button" aria-pressed={period.key === p.key} onClick={() => setPeriod(p.key)} className={cn('rounded-md px-3 py-1.5 text-xs font-medium transition-colors pointer-coarse:min-h-11', period.key === p.key ? 'bg-chocolate text-ivory' : 'text-muted hover:text-chocolate')}>{p.label}</button>
                        ))}
                    </div>
                )}
            </div>

            <div id="dashboard-panel" role="tabpanel" aria-labelledby={`tab-${tab}`}>
                {tab === 'overview' && <Overview actions={actions} today={today} forecastData={forecastData} nextBusy={nextBusy} money={money} performance={performance} period={period} donut={donut} can={can} openTab={openTab} />}
                {tab === 'bookings' && bookingsTab && <BookingsTab data={bookingsTab} money={money} performance={performance} period={period} />}
                {tab === 'rooms' && <RoomsTab today={today} board={board} forecastData={forecastData} nextBusy={nextBusy} performance={performance} period={period} hasTrend={hasTrend} trend={trend} spark={spark} donut={donut} />}
                {tab === 'income' && income && <IncomeTab money={money} income={income} period={period} hasTrend={hasTrend} trend={trend} spark={spark} />}
                {tab === 'expenses' && expenses && <ExpensesTab money={money} expenses={expenses} period={period} trend={trend} spark={spark} />}
                {tab === 'operations' && <OperationsTab team={team} today={today} operations={operations} can={can} />}
            </div>
        </AdminLayout>
    );
}
