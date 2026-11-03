import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { useMemo } from 'react';
import { buttonClass, cn, Empty, Select, StatusChip } from '@/components/ui';
import { dateOnly, humanize } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

const STATUS_STYLE: Record<string, string> = {
    inquiry: 'bg-white border border-dashed border-muted text-chocolate',
    tentative: 'bg-amber-100 border border-amber-400 text-amber-950',
    confirmed: 'bg-chocolate text-ivory',
    checked_in: 'bg-sky-800 text-white',
    checked_out: 'bg-beige text-espresso',
    block: 'bg-red-100 border border-red-300 text-red-950',
};

export default function Calendar({ month, label, prev, next, days, today, property, properties, rooms, events, blocks }: any) {
    const dates = useMemo(() => Array.from({ length: days }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`), [month, days]);

    function go(params: any) {
        router.get('/admin/calendar', { month, property: property ?? undefined, ...params }, { preserveScroll: true });
    }

    // Half-open stay: the bar ends at the check-out date's column, which stays bookable for an arrival.
    function span(start: string, end: string) {
        const from = start < dates[0] ? 1 : Number(start.slice(8, 10));
        const lastExclusive = end > dates[days - 1] ? days + 1 : Number(end.slice(8, 10));
        return { gridColumn: `${from} / ${Math.max(lastExclusive, from + 1)}` };
    }

    const byRoom = (id: number) => [
        ...events.filter((e: any) => e.room_id === id).map((e: any) => ({ ...e, start: e.check_in, end: e.check_out, kind: e.status })),
        ...blocks.filter((b: any) => b.room_id === id).map((b: any) => ({ ...b, start: b.start_date, end: b.end_date, kind: 'block' })),
    ];

    return (
        <AdminLayout
            title="Booking Calendar"
            actions={<Link href="/admin/bookings/create" className={buttonClass('primary')}><Plus size={15} /> New booking</Link>}
        >
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <button aria-label="Previous month" className={buttonClass('secondary')} onClick={() => go({ month: prev })}><ChevronLeft size={16} /></button>
                <span className="min-w-36 text-center font-semibold">{label}</span>
                <button aria-label="Next month" className={buttonClass('secondary')} onClick={() => go({ month: next })}><ChevronRight size={16} /></button>
                <Select aria-label="Property" className="w-auto" placeholder="All properties" options={properties.map((p: any) => ({ value: p.id, label: p.name }))} value={property ?? ''} onChange={(e) => go({ property: e.target.value || undefined })} />
            </div>

            <ul aria-label="Legend" className="mb-3 flex flex-wrap gap-2 text-xs">
                {Object.entries(STATUS_STYLE).map(([k, cls]) => (
                    <li key={k} className={cn('rounded px-2 py-0.5', cls)}>{humanize(k)}</li>
                ))}
            </ul>

            {/* Desktop resource timeline with sticky room labels */}
            <div className="hidden overflow-x-auto rounded-lg border border-line bg-white md:block">
                <div style={{ minWidth: `${176 + days * 36}px` }}>
                    <div className="flex border-b border-line bg-ivory text-xs">
                        <div className="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-ivory px-3 py-2 font-medium">Room</div>
                        <div className="grid flex-1" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                            {dates.map((d, i) => (
                                <div key={d} className={cn('py-2 text-center', d === today && 'bg-gold/30 font-semibold')}>{i + 1}</div>
                            ))}
                        </div>
                    </div>
                    {rooms.map((room: any) => (
                        <div key={room.id} className="flex border-b border-line last:border-b-0">
                            <div className="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-white px-3 py-2 text-sm">
                                <div className="font-medium">{room.name}</div>
                                <div className="text-xs text-muted">{room.unit} · {room.property}</div>
                            </div>
                            <div className="relative flex-1">
                                <div className="absolute inset-0 grid" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                    {dates.map((d) => (
                                        <Link key={d} href={`/admin/bookings/create?room_id=${room.id}&check_in=${d}`} aria-label={`New booking, ${room.name}, ${dateOnly(d)}`} className={cn('border-l border-line/60 hover:bg-beige', d === today && 'bg-gold/10')} />
                                    ))}
                                </div>
                                <div className="pointer-events-none relative grid min-h-14 gap-y-0.5 py-1" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                    {byRoom(room.id).map((e: any) => {
                                        const content = e.kind === 'block' ? `Blocked: ${e.reason}` : `${e.guest} · ${e.source}`;
                                        const title = e.kind === 'block' ? content : `${e.reference} ${e.guest} (${humanize(e.status)}, ${e.source}) ${e.start} to ${e.end}`;
                                        const cls = cn('pointer-events-auto mx-0.5 truncate rounded px-1.5 py-1 text-[11px] leading-tight', STATUS_STYLE[e.kind]);
                                        return e.kind === 'block' ? (
                                            <div key={`b${e.id}`} title={title} className={cls} style={span(e.start, e.end)}>{content}</div>
                                        ) : (
                                            <Link key={`${e.id}-${room.id}`} href={`/admin/bookings/${e.id}`} title={title} className={cls} style={span(e.start, e.end)}>{content}</Link>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Mobile list by room */}
            <div className="space-y-3 md:hidden">
                {rooms.map((room: any) => {
                    const items = byRoom(room.id).sort((a: any, b: any) => a.start.localeCompare(b.start));
                    return (
                        <section key={room.id} className="rounded-lg border border-line bg-white p-3">
                            <div className="mb-1 flex items-center justify-between">
                                <h2 className="font-medium">{room.name} <span className="text-xs text-muted">{room.unit}</span></h2>
                                <StatusChip value={room.housekeeping} />
                            </div>
                            {items.length === 0 ? <Empty>Nothing this month.</Empty> : (
                                <ul className="divide-y divide-line text-sm">
                                    {items.map((e: any) => (
                                        <li key={`${e.kind}-${e.id}`} className="flex items-center justify-between gap-2 py-1.5">
                                            <span className="min-w-0">
                                                {e.kind === 'block' ? <>Blocked: {e.reason}</> : <Link href={`/admin/bookings/${e.id}`} className="underline">{e.guest}</Link>}
                                                <span className="block text-xs text-muted">{dateOnly(e.start)} to {dateOnly(e.end)}{e.source ? ` · ${e.source}` : ''}</span>
                                            </span>
                                            {e.kind !== 'block' && <StatusChip value={e.status} />}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    );
                })}
            </div>
        </AdminLayout>
    );
}
