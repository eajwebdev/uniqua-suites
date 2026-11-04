import { ReactNode } from 'react';
import { Area, Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { cn } from '@/components/ui';
import { chartsAnimate, pesoCompact } from '@/lib/format';

// Checked with the dataviz palette validator against white, including for colour blindness.
// A money measure keeps its colour on every chart.
export const SERIES = { earned: '#a97a1f', collected: '#0088a8', expenses: '#c8552a' };

export const CHART = { grid: '#efe9df', axis: '#6b5b4e', occupancy: '#46362d', vacant: '#e3d9cb' };

const AXIS = { fontSize: 11, fill: CHART.axis };

export function Legend({ items }: { items: { label: string; colour: string; value?: ReactNode }[] }) {
    return (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
            {items.map((i) => (
                <li key={i.label} className="flex items-center gap-1.5">
                    <span className="size-2.5 shrink-0 rounded-full" style={{ background: i.colour }} aria-hidden />
                    {i.label}{i.value !== undefined && <strong className="font-semibold text-chocolate">{i.value}</strong>}
                </li>
            ))}
        </ul>
    );
}

function Tip({ active, label, rows }: { active?: boolean; label?: ReactNode; rows: { name: string; colour: string; value: string }[] }) {
    if (!active) return null;

    return (
        <div className="rounded-lg border border-line bg-white px-3 py-2 text-xs shadow-md">
            <p className="mb-1 font-semibold">{label}</p>
            <ul className="space-y-0.5">
                {rows.map((r) => (
                    <li key={r.name} className="flex items-center justify-between gap-4">
                        <span className="flex items-center gap-1.5 text-muted"><span className="size-2 rounded-full" style={{ background: r.colour }} aria-hidden />{r.name}</span>
                        <span className="font-medium tabular-nums">{r.value}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

const pesoFull = (value: number) => `₱${Math.round(value).toLocaleString('en-PH')}`;

export function MoneyTrendChart({ data }: { data: any[] }) {
    return (
        <div className="h-72" role="img" aria-label="Line chart of money earned, money collected and expenses for each of the last 12 months. The same figures are in the table below it.">
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} accessibilityLayer>
                    <CartesianGrid stroke={CHART.grid} vertical={false} />
                    <XAxis dataKey="month" tick={AXIS} tickFormatter={(m: string) => m.slice(0, 3)} tickLine={false} axisLine={{ stroke: CHART.grid }} />
                    <YAxis tick={AXIS} tickFormatter={pesoCompact} tickLine={false} axisLine={false} width={52} />
                    <Tooltip
                        cursor={{ stroke: CHART.axis, strokeWidth: 1 }}
                        content={({ active, label, payload }: any) => {
                            const row = payload?.[0]?.payload;

                            return row ? <Tip active={active} label={label} rows={[
                                { name: 'Earned', colour: SERIES.earned, value: pesoFull(row.earned) },
                                { name: 'Collected', colour: SERIES.collected, value: pesoFull(row.collected) },
                                { name: 'Expenses', colour: SERIES.expenses, value: pesoFull(row.expenses) },
                            ]} /> : null;
                        }}
                    />
                    <Area type="monotone" dataKey="earned" fill={SERIES.earned} fillOpacity={0.1} stroke="none" isAnimationActive={chartsAnimate} legendType="none" tooltipType="none" />
                    {(['earned', 'collected', 'expenses'] as const).map((key) => (
                        <Line key={key} type="monotone" dataKey={key} stroke={SERIES[key]} strokeWidth={2} dot={false} activeDot={{ r: 5, stroke: '#fff', strokeWidth: 2 }} isAnimationActive={chartsAnimate} />
                    ))}
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}

export function OccupancyTrendChart({ data }: { data: any[] }) {
    return (
        <div className="h-56" role="img" aria-label="Line chart of the share of rooms booked in each of the last 12 months. The same figures are in the table under the money chart.">
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} accessibilityLayer>
                    <CartesianGrid stroke={CHART.grid} vertical={false} />
                    <XAxis dataKey="month" tick={AXIS} tickFormatter={(m: string) => m.slice(0, 3)} tickLine={false} axisLine={{ stroke: CHART.grid }} interval={1} />
                    <YAxis domain={[0, 100]} ticks={[0, 25, 50, 75, 100]} tick={AXIS} tickFormatter={(v: number) => `${v}%`} tickLine={false} axisLine={false} width={44} />
                    <Tooltip cursor={{ stroke: CHART.axis, strokeWidth: 1 }} content={({ active, label, payload }: any) => (payload?.[0] ? <Tip active={active} label={label} rows={[{ name: 'Occupancy', colour: CHART.occupancy, value: `${payload[0].payload.occupancy}%` }]} /> : null)} />
                    <Area type="monotone" dataKey="occupancy" fill={CHART.occupancy} fillOpacity={0.1} stroke={CHART.occupancy} strokeWidth={2} activeDot={{ r: 5, stroke: '#fff', strokeWidth: 2 }} isAnimationActive={chartsAnimate} />
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}

export function ForecastChart({ data, rooms }: { data: { date: string; label: string; rooms: number }[]; rooms: number }) {
    return (
        <div className="h-56" role="img" aria-label={`Bar chart of how many of the ${rooms} rooms are booked on each of the next 14 days. ${data.map((d) => `${d.label}: ${d.rooms}`).join(', ')}.`}>
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ top: 8, right: 4, left: 0, bottom: 0 }} accessibilityLayer>
                    <CartesianGrid stroke={CHART.grid} vertical={false} />
                    <XAxis dataKey="label" tick={AXIS} tickLine={false} axisLine={{ stroke: CHART.grid }} interval="preserveStartEnd" minTickGap={16} />
                    <YAxis domain={[0, Math.max(rooms, 1)]} allowDecimals={false} tick={AXIS} tickLine={false} axisLine={false} width={24} />
                    <Tooltip cursor={{ fill: CHART.grid }} content={({ active, label, payload }: any) => (payload?.[0] ? <Tip active={active} label={label} rows={[{ name: 'Rooms booked', colour: CHART.occupancy, value: `${payload[0].value} of ${rooms}` }]} /> : null)} />
                    <Bar dataKey="rooms" fill={CHART.occupancy} radius={[4, 4, 0, 0]} maxBarSize={24} isAnimationActive={chartsAnimate} />
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}

/** Drawn directly: a share of nine rooms needs no chart library, and the labels sit beside it. */
export function RoomsDonut({ segments, centre, label }: { segments: { label: string; value: number; colour: string }[]; centre: ReactNode; label: string }) {
    const shown = segments.filter((s) => s.value > 0);
    const total = shown.reduce((sum, s) => sum + s.value, 0);
    const gap = shown.length > 1 ? 0.8 : 0;
    let offset = 0;

    return (
        <div className="relative mx-auto size-40" role="img" aria-label={label}>
            {/* A radius of 15.9155 makes the circle 100 long, so each dash length is simply a percentage. */}
            <svg viewBox="0 0 36 36" className="size-full -rotate-90" aria-hidden>
                {total === 0 && <circle cx="18" cy="18" r="15.9155" fill="none" stroke={CHART.vacant} strokeWidth="3.4" />}
                {shown.map((s) => {
                    const share = (s.value / total) * 100;
                    const dash = <circle key={s.label} cx="18" cy="18" r="15.9155" fill="none" stroke={s.colour} strokeWidth="3.4" strokeDasharray={`${share - gap} ${100 - share + gap}`} strokeDashoffset={-offset} />;
                    offset += share;

                    return dash;
                })}
            </svg>
            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">{centre}</div>
        </div>
    );
}

export function Sparkline({ values, colour }: { values: number[]; colour: string }) {
    const max = Math.max(...values, 0);
    const min = Math.min(...values, 0);
    const range = max - min || 1;
    const points = values.map((v, i) => `${(i / Math.max(values.length - 1, 1)) * 100},${28 - ((v - min) / range) * 24}`).join(' ');

    return (
        <svg viewBox="0 0 100 30" preserveAspectRatio="none" className="h-8 w-full" aria-hidden>
            <polyline points={points} fill="none" stroke={colour} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

/** A table and a chart in one: every value stays readable without hovering. */
export function ValueBar({ share, colour, className }: { share: number; colour: string; className?: string }) {
    return (
        <div className={cn('h-1.5 overflow-hidden rounded-full bg-beige', className)} aria-hidden>
            <div className="h-full rounded-full" style={{ width: `${Math.max(share > 0 ? 2 : 0, Math.min(share, 100))}%`, background: colour }} />
        </div>
    );
}
