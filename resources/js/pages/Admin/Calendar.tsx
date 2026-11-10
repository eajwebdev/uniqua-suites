import { Link, router, usePage } from '@inertiajs/react';
import { Ban, BedDouble, CalendarDays, CalendarOff, CheckCircle2, ChevronLeft, ChevronRight, Clock, HelpCircle, List, LogIn, LogOut, Plus, RefreshCw, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { openNewBooking } from '@/components/NewBooking';
import { ActionButton, buttonClass, cn, confirmDialog, Empty, Input, Select, Stat, StatusChip } from '@/components/ui';
import { dateOnly, humanize } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

const STATUS: Record<string, { style: string; swatch: string; icon: any; label: string }> = {
    inquiry: { style: 'bg-white border border-dashed border-muted text-chocolate', swatch: 'bg-white border border-dashed border-muted', icon: HelpCircle, label: 'Inquiry' },
    tentative: { style: 'bg-amber-100 border border-amber-400 text-amber-950', swatch: 'bg-amber-100 border border-amber-400', icon: Clock, label: 'On hold' },
    confirmed: { style: 'bg-espresso text-ivory', swatch: 'bg-gradient-to-r from-pink-200 to-sky-200 border border-line', icon: CheckCircle2, label: 'Confirmed' },
    checked_in: { style: 'bg-teal-800 text-white', swatch: 'bg-gradient-to-r from-pink-200 to-sky-200 border border-line border-l-4 border-l-espresso', icon: BedDouble, label: 'Checked in' },
    checked_out: { style: 'bg-beige text-espresso border border-line', swatch: 'bg-gradient-to-r from-pink-50 to-sky-50 border border-line', icon: LogOut, label: 'Checked out' },
    block: { style: 'bg-red-100 border border-red-300 text-red-950', swatch: 'bg-red-100 border border-red-300', icon: Ban, label: 'Blocked' },
};

// Stays that are already committed. Active holds also block inventory, but remain visually distinct.
const BOOKED = ['confirmed', 'checked_in', 'checked_out'];

/**
 * Booked stays are coloured by where they came from: pink for Airbnb, blue for every other source.
 * The status still shows in the icon and the bar style: a solid bar is confirmed, a dark left edge is
 * a guest in the room, a faded bar has checked out. Inquiries keep a dashed outline in the source colour.
 */
const CHANNEL = {
    airbnb: { label: 'Airbnb', swatch: 'bg-pink-200 border border-pink-300', confirmed: 'bg-pink-200 border border-pink-300 text-pink-950', checked_in: 'bg-pink-200 border border-pink-300 border-l-4 border-l-pink-600 text-pink-950', checked_out: 'bg-pink-50 border border-pink-200 text-pink-900/70', inquiry: 'bg-white border border-dashed border-pink-400 text-chocolate' },
    other: { label: 'Other sources', swatch: 'bg-sky-200 border border-sky-300', confirmed: 'bg-sky-200 border border-sky-300 text-sky-950', checked_in: 'bg-sky-200 border border-sky-300 border-l-4 border-l-sky-700 text-sky-950', checked_out: 'bg-sky-50 border border-sky-200 text-sky-900/70', inquiry: 'bg-white border border-dashed border-sky-400 text-chocolate' },
} as const;
const channelOf = (e: any) => (e.source_code === 'airbnb' ? CHANNEL.airbnb : CHANNEL.other);
const barStyle = (e: any) => (e.kind === 'block' || e.kind === 'tentative' ? STATUS[e.kind].style : (channelOf(e) as any)[e.kind] ?? STATUS[e.kind].style);

const HOUSEKEEPING_DOT: Record<string, string> = { ready: 'bg-emerald-600', dirty: 'bg-red-700', cleaning: 'bg-amber-500', assigned: 'bg-amber-500', for_inspection: 'bg-sky-600', under_maintenance: 'bg-red-700' };

/** Why dates are closed is visible to all staff, so nobody has to ask. Only the owner can change it. */
function ClosedDates({ closures, canChange, total }: { closures: any[]; canChange: boolean; total: number }) {
    if (closures.length === 0) return null;

    return (
        <section aria-label="Closed dates" className="mb-3 rounded-xl border border-red-200 bg-red-50/60 px-4 py-2">
            <ul className="divide-y divide-red-200/70">
                {closures.map((c) => (
                    <li key={c.group} className="flex flex-wrap items-center justify-between gap-2 py-1.5 text-sm">
                        <span className="flex min-w-0 items-start gap-2">
                            <CalendarOff size={15} className="mt-0.5 shrink-0 text-red-800" aria-hidden />
                            <span className="min-w-0">
                                <span className="font-medium">{c.reason}</span>
                                <span className="block text-xs text-muted">
                                    {c.from === c.last_night ? `Night of ${dateOnly(c.from)}` : `Nights of ${dateOnly(c.from)} to ${dateOnly(c.last_night)}`} · {c.rooms.length >= total ? 'All rooms' : c.rooms.join(', ')}
                                </span>
                            </span>
                        </span>
                        {canChange && <ActionButton action={{ label: 'Reopen', url: `/admin/closures/${c.group}/reopen`, confirm: `Open these dates for booking again? "${c.reason}" will no longer block them.` }} />}
                    </li>
                ))}
            </ul>
        </section>
    );
}

export default function Calendar({ month, label, prev, next, days, today, weekend_days, property, properties, rooms, events, blocks, closures = [], closeRooms }: any) {
    const { auth } = usePage<any>().props as any;
    const canCreate = auth.user.permissions.includes('bookings.manage');
    const [term, setTerm] = useState('');
    const [hidden, setHidden] = useState<string[]>([]);
    const [view, setView] = useState<'timeline' | 'list'>('timeline');
    const [refreshing, setRefreshing] = useState(false);

    function refreshCalendar(force = false) {
        if (!force && (document.visibilityState !== 'visible' || document.querySelector('dialog[open]'))) return;
        router.reload({
            only: ['events', 'blocks', 'closures', 'today'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });
    }

    useEffect(() => {
        const timer = window.setInterval(() => refreshCalendar(), 60_000);
        const onFocus = () => refreshCalendar();
        window.addEventListener('focus', onFocus);

        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', onFocus);
        };
    }, [month, property]);

    const dates = useMemo(() => Array.from({ length: days }, (_, i) => {
        const date = `${month}-${String(i + 1).padStart(2, '0')}`;
        const [y, m, d] = date.split('-').map(Number);
        const js = new Date(Date.UTC(y, m - 1, d));

        return { date, day: i + 1, weekday: js.toLocaleDateString('en-PH', { weekday: 'narrow', timeZone: 'UTC' }), weekend: weekend_days.includes(js.getUTCDay() || 7) };
    }), [month, days, weekend_days]);

    function go(params: any) {
        router.get('/admin/calendar', { month, property: property ?? undefined, ...params }, { preserveScroll: true });
    }

    // Half-open stay: the bar ends at the check-out date's column, which stays bookable for an arrival.
    function span(start: string, end: string) {
        const from = start < dates[0].date ? 1 : Number(start.slice(8, 10));
        const lastExclusive = end > dates[days - 1].date ? days + 1 : Number(end.slice(8, 10));
        return { gridColumn: `${from} / ${Math.max(lastExclusive, from + 1)}` };
    }

    const matches = (e: any) => {
        const q = term.trim().toLowerCase();
        return !q || `${e.guest} ${e.reference}`.toLowerCase().includes(q);
    };

    const items = useMemo(() => [
        ...events.map((e: any) => ({ ...e, start: e.check_in, end: e.check_out, kind: e.status })),
        ...blocks.map((b: any) => ({ ...b, start: b.start_date, end: b.end_date, block_kind: b.kind, kind: 'block' })),
    ], [events, blocks]);

    const visible = items.filter((e: any) => !hidden.includes(e.kind) && (e.kind === 'block' ? !term.trim() : matches(e)));
    // In date order, so stays that do not overlap share one line instead of stacking.
    const byRoom = (id: number) => visible.filter((e: any) => e.room_id === id).sort((a: any, b: any) => a.start.localeCompare(b.start));

    const counts = useMemo(() => {
        const out: Record<string, number> = {};
        items.forEach((e: any) => { out[e.kind] = (out[e.kind] ?? 0) + 1; });
        return out;
    }, [items]);

    // Rooms held for certain on each date, from the same half-open ranges as the bars.
    const bookedByDate = useMemo(() => dates.map(({ date }) => {
        const held = new Set(events.filter((e: any) => BOOKED.includes(e.status) && e.check_in <= date && e.check_out > date).map((e: any) => e.room_id));
        return held.size;
    }), [dates, events]);

    const roomNights = bookedByDate.reduce((sum, n) => sum + n, 0);
    const monthPct = rooms.length ? Math.round((roomNights / (rooms.length * days)) * 100) : 0;
    const arrivals = events.filter((e: any) => BOOKED.includes(e.status) && e.check_in.startsWith(month)).length;
    const isThisMonth = today.startsWith(month);
    const bookedToday = isThisMonth ? bookedByDate[Number(today.slice(8, 10)) - 1] : null;
    const columns = { gridTemplateColumns: `repeat(${days}, minmax(38px, 1fr))` };

    return (
        <AdminLayout
            title="Booking Calendar"
            subtitle={<><span className="md:hidden">Tap a stay to open it.</span><span className="hidden md:inline">{canCreate ? 'Click an empty day on a room to start a booking there. ' : ''}Click a stay to open it. Updates automatically.</span></>}
            actions={
                <>
                    <button type="button" onClick={() => refreshCalendar(true)} disabled={refreshing} className={buttonClass('secondary')}><RefreshCw size={15} className={refreshing ? 'animate-spin' : ''} aria-hidden /> Refresh</button>
                    {closeRooms && (
                        <ActionButton small={false} action={{
                            label: 'Close dates',
                            url: '/admin/closures',
                            fields: [
                                { name: 'reason', label: 'Why are you closing?', type: 'text', required: true, help: 'For example: Christmas party. Only staff see this. Guests just see the dates as taken.' },
                                { name: 'first_night', label: 'First night closed', type: 'date', required: true, default: today },
                                { name: 'last_night', label: 'Last night closed', type: 'date', required: true, default: today, help: 'For a single night, use the same date. A guest can still check out that morning, and a new guest can arrive the morning after.' },
                                { name: 'room_ids', label: 'Rooms to close', type: 'multicheck', options: closeRooms, default: closeRooms.map((r: any) => r.value), help: 'All rooms are ticked. Untick any that stay open.' },
                            ],
                        }} />
                    )}
                    {canCreate && <button type="button" onClick={() => openNewBooking()} className={buttonClass('primary')}><Plus size={15} aria-hidden /> New booking</button>}
                </>
            }
        >
            <ClosedDates closures={closures} canChange={!!closeRooms} total={closeRooms?.length ?? rooms.length} />
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label={isThisMonth ? 'Rooms booked today' : 'Rooms'} value={isThisMonth ? <>{bookedToday} <span className="text-sm font-normal text-muted">of {rooms.length}</span></> : rooms.length} icon={BedDouble} />
                <Stat label={`Booked in ${label}`} value={`${monthPct}%`} icon={CalendarDays} hint={`${roomNights} room nights`} />
                <Stat label="Arrivals this month" value={arrivals} icon={LogIn} />
                <Stat label="Waiting on a guest" value={(counts.inquiry ?? 0) + (counts.tentative ?? 0)} icon={Clock} hint="Inquiries and holds" />
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <div className="flex items-center gap-1">
                    <button aria-label="Previous month" className={buttonClass('secondary')} onClick={() => go({ month: prev })}><ChevronLeft size={16} /></button>
                    {!isThisMonth && <button className={buttonClass('secondary')} onClick={() => go({ month: today.slice(0, 7) })}>Today</button>}
                    <button aria-label="Next month" className={buttonClass('secondary')} onClick={() => go({ month: next })}><ChevronRight size={16} /></button>
                </div>
                <h2 className="min-w-36 text-lg font-semibold tracking-tight">{label}</h2>
                <div className="ml-auto flex flex-wrap items-center gap-2">
                    <label className="relative block min-w-44 flex-1 sm:flex-none">
                        <span className="sr-only">Find a guest or booking on the calendar</span>
                        <Search size={15} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" aria-hidden />
                        <Input type="search" placeholder="Find a guest" className="w-full pl-9 sm:w-52" value={term} onChange={(e) => setTerm(e.target.value)} />
                    </label>
                    <Select aria-label="Property" className="w-auto" placeholder="All properties" options={properties.map((p: any) => ({ value: p.id, label: p.name }))} value={property ?? ''} onChange={(e) => go({ property: e.target.value || undefined })} />
                    <div role="group" aria-label="View" className="hidden rounded-lg border border-line bg-white p-0.5 shadow-xs md:inline-flex">
                        <button type="button" aria-pressed={view === 'timeline'} onClick={() => setView('timeline')} className={cn('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium', view === 'timeline' ? 'bg-chocolate text-ivory' : 'text-muted')}><CalendarDays size={14} aria-hidden /> Timeline</button>
                        <button type="button" aria-pressed={view === 'list'} onClick={() => setView('list')} className={cn('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium', view === 'list' ? 'bg-chocolate text-ivory' : 'text-muted')}><List size={14} aria-hidden /> List</button>
                    </div>
                </div>
            </div>

            <div role="group" aria-label="Show or hide stays by status" className="mb-3 flex flex-wrap gap-1.5">
                {Object.entries(STATUS).map(([key, s]) => {
                    const on = !hidden.includes(key);
                    return (
                        <button key={key} type="button" aria-pressed={on} onClick={() => setHidden(on ? [...hidden, key] : hidden.filter((h) => h !== key))} className={cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors pointer-coarse:min-h-11', on ? 'border-line bg-white text-chocolate' : 'border-transparent bg-transparent text-muted line-through')}>
                            <span className={cn('size-3 rounded-sm', s.swatch)} aria-hidden /> {s.label}
                            <span className="text-muted">{counts[key] ?? 0}</span>
                        </button>
                    );
                })}
                <span className="ml-auto inline-flex flex-wrap items-center gap-3 px-1 text-xs text-muted" aria-label="Colour by booking source">
                    {Object.values(CHANNEL).map((c) => <span key={c.label} className="inline-flex items-center gap-1.5"><span className={cn('size-3 rounded-sm', c.swatch)} aria-hidden /> {c.label}</span>)}
                    <span className="inline-flex items-center gap-1.5"><span className="size-3 rounded-sm border border-emerald-300 bg-emerald-100" aria-hidden /> Today</span>
                </span>
            </div>

            {/* Desktop resource timeline with sticky room labels */}
            <div className={cn('overflow-x-auto rounded-xl border border-line/80 bg-white shadow-xs', view === 'timeline' ? 'hidden md:block' : 'hidden')}>
                <div style={{ minWidth: `${176 + days * 38}px` }}>
                    <div className="flex border-b border-line bg-ivory/70 text-xs">
                        <div className="sticky left-0 z-20 flex w-44 shrink-0 items-center border-r border-line bg-ivory px-3 py-2 font-medium">Room</div>
                        <div className="grid flex-1" style={columns}>
                            {dates.map((d) => (
                                <div key={d.date} className={cn('flex flex-col items-center gap-0.5 py-1.5', d.weekend && 'bg-beige/60', d.date === today && 'bg-emerald-50')}>
                                    <span className="text-[10px] text-muted">{d.weekday}</span>
                                    <span className={cn('flex size-6 items-center justify-center rounded-full', d.date === today ? 'bg-emerald-200 font-semibold text-emerald-950 ring-1 ring-emerald-400' : '')}>{d.day}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                    {rooms.map((room: any) => (
                        <div key={room.id} className="flex border-b border-line/70 last:border-b-0">
                            <div className="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-white px-3 py-2 text-sm">
                                <div className="flex items-center gap-1.5 font-medium">
                                    <span className={cn('size-2 shrink-0 rounded-full', HOUSEKEEPING_DOT[room.housekeeping] ?? 'bg-muted')} title={`Housekeeping: ${humanize(room.housekeeping)}`} aria-hidden />
                                    {room.name}
                                </div>
                                <div className="truncate text-xs text-muted">{humanize(room.housekeeping)} · {room.unit}</div>
                            </div>
                            <div className="relative flex-1">
                                <div className="absolute inset-0 grid" style={columns}>
                                    {dates.map((d) => {
                                        const cell = cn('border-l border-line/40', d.weekend && 'bg-beige/40', d.date === today && 'bg-emerald-50 border-x border-emerald-200');
                                        return canCreate && d.date >= today ? (
                                            <button type="button" key={d.date} onClick={() => openNewBooking({ room_id: room.id, check_in: d.date })} aria-label={`New booking, ${room.name}, ${dateOnly(d.date)}`} title={`New booking, ${room.name}, ${dateOnly(d.date)}`} className={cn('group/cell flex items-center justify-center transition-colors hover:bg-gold/25', cell)}>
                                                <Plus size={13} className="text-espresso opacity-0 group-hover/cell:opacity-100" aria-hidden />
                                            </button>
                                        ) : <div key={d.date} className={cell} />;
                                    })}
                                </div>
                                <div className="pointer-events-none relative grid min-h-14 content-center gap-y-0.5 py-1.5" style={columns}>
                                    {byRoom(room.id).map((e: any) => {
                                        const s = STATUS[e.kind];
                                        const content = e.kind === 'block' ? `${e.block_kind === 'closure' ? 'Closed' : 'Blocked'}: ${e.reason}` : e.guest;
                                        const title = e.kind === 'block' ? content : `${e.reference}: ${e.guest}. ${s.label}, ${e.source}. ${dateOnly(e.start)} to ${dateOnly(e.end)}`;
                                        const cls = cn('pointer-events-auto mx-0.5 flex min-h-7 items-center gap-1 overflow-hidden rounded-md px-1.5 text-xs leading-tight shadow-xs', barStyle(e));
                                        // A one-night stay is too narrow for an icon as well as the name. The colour and tooltip still say what it is.
                                        const oneNight = Number(span(e.start, e.end).gridColumn.split(' / ')[1]) - Number(span(e.start, e.end).gridColumn.split(' / ')[0]) === 1;
                                        const body = <>{!oneNight && <s.icon size={12} className="shrink-0" aria-hidden />}<span className="truncate">{content}</span></>;
                                        if (e.kind === 'block' && e.block_kind === 'workbook' && canCreate) {
                                            return (
                                                <button key={`b${e.id}`} type="button" title={`${title}. Click to release it once the real booking is ready to enter.`} aria-label={`${title}. Release this hold`} className={cn(cls, 'text-left hover:brightness-95')} style={span(e.start, e.end)}
                                                    onClick={async () => { if (await confirmDialog({ title: 'Release this hold?', message: `${room.name}, ${dateOnly(e.start)} to ${dateOnly(e.end)}. The dates become free until you enter the real booking, so enter it right away.`, confirmLabel: 'Release hold', danger: true })) router.post(`/admin/blocks/${e.id}/release`, {}, { preserveScroll: true }); }}>
                                                    {body}
                                                </button>
                                            );
                                        }
                                        return e.kind === 'block' ? (
                                            <div key={`b${e.id}`} title={title} className={cls} style={span(e.start, e.end)}>{body}</div>
                                        ) : (
                                            <Link key={`${e.id}-${room.id}`} href={`/admin/bookings/${e.id}`} title={title} aria-label={title} className={cn(cls, 'transition-[filter] hover:brightness-95')} style={span(e.start, e.end)}>{body}</Link>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    ))}
                    <div className="flex border-t border-line bg-ivory/70 text-xs">
                        <div className="sticky left-0 z-20 flex w-44 shrink-0 items-center border-r border-line bg-ivory px-3 py-2 font-medium">Rooms booked</div>
                        <div className="grid flex-1" style={columns}>
                            {dates.map((d, i) => (
                                <div key={d.date} title={`${dateOnly(d.date)}: ${bookedByDate[i]} of ${rooms.length} rooms booked`} className="relative flex items-center justify-center py-2 tabular-nums">
                                    <span className="absolute inset-1 rounded bg-gold" style={{ opacity: rooms.length ? (bookedByDate[i] / rooms.length) * 0.6 : 0 }} aria-hidden />
                                    <span className="relative">{bookedByDate[i] || <span className="text-muted">·</span>}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* List by room: the phone view, and an option on larger screens */}
            <div className={cn('grid gap-3', view === 'timeline' ? 'md:hidden' : 'lg:grid-cols-2')}>
                {rooms.map((room: any) => {
                    const stays = byRoom(room.id).sort((a: any, b: any) => a.start.localeCompare(b.start));
                    return (
                        <section key={room.id} className="rounded-xl border border-line/80 bg-white p-4 shadow-xs">
                            <div className="mb-1 flex items-center justify-between gap-2">
                                <h2 className="font-semibold">{room.name} <span className="text-xs font-normal text-muted">{room.unit}</span></h2>
                                <StatusChip value={room.housekeeping} />
                            </div>
                            {stays.length === 0 ? <p className="py-2 text-sm text-muted">Nothing this month.</p> : (
                                <ul className="divide-y divide-line/70 text-sm">
                                    {stays.map((e: any) => (
                                        <li key={`${e.kind}-${e.id}`} className="flex items-center justify-between gap-2 py-1.5">
                                            <span className="min-w-0">
                                                {e.kind === 'block' ? <>{e.block_kind === 'closure' ? 'Closed' : 'Blocked'}: {e.reason}</> : <><span className={cn('mr-1.5 inline-block size-2.5 rounded-full align-middle', channelOf(e).swatch)} title={e.source} aria-hidden /><Link href={`/admin/bookings/${e.id}`} className="inline-block font-medium hover:underline pointer-coarse:py-3">{e.guest}</Link></>}
                                                <span className="block text-xs text-muted">{dateOnly(e.start)} to {dateOnly(e.end)}{e.source ? ` · ${e.source}` : ''}</span>
                                            </span>
                                            {e.kind !== 'block' && <StatusChip value={e.status} label={STATUS[e.status]?.label} />}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {canCreate && <button type="button" onClick={() => openNewBooking({ room_id: room.id })} className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-bronze hover:underline pointer-coarse:min-h-11"><Plus size={13} aria-hidden /> New booking</button>}
                        </section>
                    );
                })}
                {rooms.length === 0 && <Empty>No rooms to show.</Empty>}
            </div>
        </AdminLayout>
    );
}
