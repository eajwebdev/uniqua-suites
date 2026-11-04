import { Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo } from 'react';
import { buttonClass, cn, Empty, Tabs } from '@/components/ui';
import { dateOnly } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function ParkingCalendar({ month, label, prev, next, days, today, tabs, spaces, assignments }: any) {
    const path = usePage().url.split('?')[0];
    const dates = useMemo(() => Array.from({ length: days }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`), [month, days]);
    const go = (target: string) => router.get('/admin/parking/calendar', { month: target }, { preserveScroll: true });
    const forSpace = (id: number) => assignments.filter((a: any) => a.space_id === id);

    // The end date is exclusive, so that day's column stays free for the next holder.
    function span(start: string, end: string) {
        const from = start < dates[0] ? 1 : Number(start.slice(8, 10));
        const lastExclusive = end > dates[days - 1] ? days + 1 : Number(end.slice(8, 10));
        return { gridColumn: `${from} / ${Math.max(lastExclusive, from + 1)}` };
    }

    return (
        <AdminLayout title="Parking">
            <Tabs tabs={tabs} current={path} />
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <button aria-label="Previous month" className={buttonClass('secondary')} onClick={() => go(prev)}><ChevronLeft size={16} /></button>
                <span className="min-w-36 text-center font-semibold">{label}</span>
                <button aria-label="Next month" className={buttonClass('secondary')} onClick={() => go(next)}><ChevronRight size={16} /></button>
            </div>

            {spaces.length === 0 ? <Empty>No parking spaces yet.</Empty> : (
                <>
                    <div className="hidden overflow-x-auto rounded-lg border border-line bg-white md:block">
                        <div style={{ minWidth: `${176 + days * 36}px` }}>
                            <div className="flex border-b border-line bg-ivory text-xs">
                                <div className="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-ivory px-3 py-2 font-medium">Space</div>
                                <div className="grid flex-1" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                    {dates.map((d, i) => <div key={d} className={cn('py-2 text-center', d === today && 'bg-gold/30 font-semibold')}>{i + 1}</div>)}
                                </div>
                            </div>
                            {spaces.map((s: any) => (
                                <div key={s.id} className="flex border-b border-line last:border-b-0">
                                    <div className="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-white px-3 py-2 text-sm">
                                        <div className="font-medium">{s.code}</div>
                                        <div className="text-xs text-muted">{s.property}</div>
                                    </div>
                                    <div className="relative flex-1">
                                        <div className="absolute inset-0 grid" aria-hidden style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                            {dates.map((d) => <div key={d} className={cn('border-l border-line/60', d === today && 'bg-gold/10')} />)}
                                        </div>
                                        <div className="relative grid min-h-14 gap-y-0.5 py-1" style={{ gridTemplateColumns: `repeat(${days}, minmax(36px, 1fr))` }}>
                                            {forSpace(s.id).map((a: any) => (
                                                <div key={a.id} title={`${a.holder}${a.vehicle ? `, ${a.vehicle}` : ''}: ${a.start} to ${a.end}`} className="mx-0.5 truncate rounded bg-chocolate px-1.5 py-1 text-[11px] leading-tight text-ivory" style={span(a.start, a.end)}>{a.holder}</div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-3 md:hidden">
                        {spaces.map((s: any) => (
                            <section key={s.id} className="rounded-lg border border-line bg-white p-3">
                                <h2 className="font-medium">{s.code} <span className="text-xs text-muted">{s.property}</span></h2>
                                {forSpace(s.id).length === 0 ? <p className="mt-1 text-sm text-muted">Free this month.</p> : (
                                    <ul className="divide-y divide-line text-sm">
                                        {forSpace(s.id).map((a: any) => (
                                            <li key={a.id} className="py-1.5">
                                                {a.holder}{a.vehicle ? `, ${a.vehicle}` : ''}
                                                <span className="block text-xs text-muted">{dateOnly(a.start)} to {dateOnly(a.end)}{a.booking_id ? <> · <Link href={`/admin/bookings/${a.booking_id}`} className="underline">{a.booking}</Link></> : null}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        ))}
                    </div>
                </>
            )}
        </AdminLayout>
    );
}
