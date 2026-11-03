import { Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { AvailabilityNote, InquiryHelper, RoomFacts } from '@/components/public';
import { buttonClass, Field, Input } from '@/components/ui';
import { peso, time12 } from '@/lib/format';
import PublicLayout from '@/layouts/PublicLayout';

export default function Suite({ room, filters, availability, estimate, policy }: any) {
    const [d, setD] = useState({ check_in: filters.check_in ?? '', check_out: filters.check_out ?? '', guests: filters.guests ?? '', early: filters.early ?? '' });
    const set = (k: string) => (e: any) => setD({ ...d, [k]: e.target.value });

    function submit(e: FormEvent) {
        e.preventDefault();
        router.get(`/suites/${room.slug}`, Object.fromEntries(Object.entries(d).filter(([, v]) => v)) as any, { preserveScroll: true, preserveState: true });
    }

    return (
        <PublicLayout title={room.name} description={`${room.name} at ${room.location}, ${room.type.toLowerCase()} for up to ${room.max_guests} guests. See estimated rates and inquire with UNIQA SUITES.`}>
            {room.images.length > 0 ? (
                <div className="mx-auto grid max-w-6xl gap-2 px-4 pt-4 sm:grid-cols-2">
                    {room.images.slice(0, 4).map((i: any, n: number) => <img key={i.url} src={i.url} alt={i.alt} loading={n ? 'lazy' : 'eager'} className={`w-full rounded-md object-cover ${n === 0 ? 'aspect-[4/3] sm:row-span-2 sm:h-full' : 'aspect-[16/9]'}`} />)}
                </div>
            ) : (
                <div className="bg-chocolate py-14 text-center"><span className="font-serif text-5xl tracking-[0.25em] text-ivory sm:text-7xl">{room.name}</span></div>
            )}

            <div className="mx-auto grid max-w-6xl gap-8 px-4 py-8 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Link href="/suites" className="mb-3 inline-flex items-center gap-1 text-sm text-muted"><ArrowLeft size={14} /> All suites</Link>
                    <h1 className="font-serif text-4xl font-medium">{room.name}</h1>
                    <p className="mb-3 text-muted">{room.type} · {room.location}</p>
                    <RoomFacts room={room} />
                    {room.description && <p className="mt-5 whitespace-pre-line">{room.description}</p>}
                    {room.amenities.length > 0 && (
                        <>
                            <h2 className="mt-6 mb-2 font-serif text-2xl font-medium">Amenities</h2>
                            <ul className="grid gap-1 text-sm sm:grid-cols-2">{room.amenities.map((a: string) => <li key={a}>{a}</li>)}</ul>
                        </>
                    )}
                    {room.inclusions && <><h2 className="mt-6 mb-2 font-serif text-2xl font-medium">Inclusions</h2><p className="text-sm whitespace-pre-line">{room.inclusions}</p></>}

                    <h2 className="mt-6 mb-2 font-serif text-2xl font-medium">Rates and check-in</h2>
                    <ul className="space-y-1 text-sm">
                        <li>Weekday night: <strong>{peso(room.weekday_cents)}</strong> · Weekend night: <strong>{peso(room.weekend_cents)}</strong></li>
                        <li>Rates cover up to {room.included_guests} guests. This suite allows up to {room.max_guests}; please ask about extra-guest charges.</li>
                        <li>Standard check-in: {time12(policy.standard_check_in)}{policy.standard_check_out ? ` · Check-out: ${time12(policy.standard_check_out)}` : ''}</li>
                        <li>Early check-in: {peso(policy.early_fee_cents_per_hour)} per started hour before {time12(policy.standard_check_in)}, subject to availability, room readiness and staff approval.</li>
                    </ul>
                    {room.policies && <p className="mt-3 text-sm whitespace-pre-line">{room.policies}</p>}
                </div>

                <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
                    <form onSubmit={submit} className="space-y-3 rounded-lg border border-line bg-white p-4">
                        <h2 className="font-serif text-xl font-semibold">Estimate your stay</h2>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Check-in"><Input type="date" value={d.check_in} onChange={set('check_in')} /></Field>
                            <Field label="Check-out"><Input type="date" value={d.check_out} onChange={set('check_out')} /></Field>
                            <Field label="Guests"><Input type="number" min={1} max={room.max_guests} value={d.guests} onChange={set('guests')} /></Field>
                            <Field label="Early arrival"><Input type="time" value={d.early} onChange={set('early')} /></Field>
                        </div>
                        <button type="submit" className={`${buttonClass('secondary')} w-full`}>See estimate</button>
                        {availability !== 'unknown' && <AvailabilityNote state={availability} />}
                        {estimate?.error && <p role="alert" className="text-sm text-red-900">{estimate.error.includes('Weekend days') ? 'Please message us for a quote for these dates.' : estimate.error}</p>}
                        {estimate?.lines && (
                            <div className="text-sm">
                                <ul className="divide-y divide-line">
                                    {estimate.lines.map((l: any, i: number) => <li key={i} className="flex justify-between gap-2 py-1"><span>{l.description}</span><span className="tabular-nums">{peso(l.amount_cents)}</span></li>)}
                                </ul>
                                <p className="flex justify-between border-t border-chocolate pt-1.5 font-semibold"><span>Estimated total</span><span className="tabular-nums">{peso(estimate.total_cents)}</span></p>
                                {estimate.notes.map((n: string) => <p key={n} className="mt-1.5 text-xs text-muted">{n}</p>)}
                                <p className="mt-1.5 text-xs text-muted">An estimate only. Our staff confirm the final price.</p>
                            </div>
                        )}
                    </form>
                    <InquiryHelper room={room} filters={filters} estimate={estimate} />
                </aside>
            </div>
        </PublicLayout>
    );
}
