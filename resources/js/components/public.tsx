import { Link, router, usePage } from '@inertiajs/react';
import { BedDouble, Bath, Fan, MessageCircle, Sun, Users } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { buttonClass, CopyButton, Field, Input, Select } from '@/components/ui';
import { dateOnly, peso } from '@/lib/format';

export function SearchBar({ filters = {}, properties, types }: any) {
    const [d, setD] = useState({ check_in: filters.check_in ?? '', check_out: filters.check_out ?? '', guests: filters.guests ?? '', location: filters.location ?? '', type: filters.type ?? '' });
    const set = (k: string) => (e: any) => setD({ ...d, [k]: e.target.value });

    function submit(e: FormEvent) {
        e.preventDefault();
        const query = Object.fromEntries(Object.entries({ ...filters, ...d }).filter(([, v]) => v !== '' && v !== null && v !== undefined));
        router.get('/suites', query as any);
    }

    return (
        <form onSubmit={submit} aria-label="Find your suite" className="grid gap-3 rounded-lg bg-white p-4 text-chocolate shadow-sm sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
            <Field label="Check-in"><Input type="date" value={d.check_in} onChange={set('check_in')} /></Field>
            <Field label="Check-out"><Input type="date" value={d.check_out} onChange={set('check_out')} /></Field>
            <Field label="Guests"><Input type="number" min={1} max={20} value={d.guests} onChange={set('guests')} /></Field>
            <Field label="Location"><Select placeholder="Any" options={properties.map((p: any) => ({ value: p.slug, label: p.name }))} value={d.location} onChange={set('location')} /></Field>
            <Field label="Type"><Select placeholder="Any" options={types.map((t: any) => ({ value: t.slug, label: t.name }))} value={d.type} onChange={set('type')} /></Field>
            <button type="submit" className={buttonClass('primary')}>Find your suite</button>
        </form>
    );
}

export function RoomFacts({ room }: any) {
    return (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
            <li className="flex items-center gap-1"><Users size={14} aria-hidden /> Up to {room.max_guests} guests ({room.included_guests} included)</li>
            <li className="flex items-center gap-1"><BedDouble size={14} aria-hidden /> {room.bedrooms === 0 ? 'Studio' : `${room.bedrooms} bedroom${room.bedrooms > 1 ? 's' : ''}`}</li>
            <li className="flex items-center gap-1"><Bath size={14} aria-hidden /> {room.bathrooms} bath</li>
            <li className="flex items-center gap-1"><Fan size={14} aria-hidden /> {room.ac_count} AC</li>
            {room.has_balcony && <li className="flex items-center gap-1"><Sun size={14} aria-hidden /> Balcony</li>}
        </ul>
    );
}

export function AvailabilityNote({ state }: { state: string }) {
    if (state === 'indicative') return <span className="inline-block rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-900">Indicatively available · please confirm with us</span>;
    if (state === 'unavailable') return <span className="inline-block rounded-full border border-red-200 bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-900">Not available for these dates</span>;
    return null;
}

export function RoomCard({ room, query }: any) {
    const href = `/suites/${room.slug}${query ? `?${query}` : ''}`;
    const image = room.images?.[0];

    return (
        <article className="group flex flex-col overflow-hidden rounded-lg border border-line bg-white">
            <Link href={href} className="block" tabIndex={-1} aria-hidden>
                {image ? (
                    <img src={image.url} alt="" loading="lazy" className="aspect-[4/3] w-full object-cover" />
                ) : (
                    <div className="flex aspect-[4/3] items-center justify-center bg-chocolate">
                        <span className="font-serif text-4xl tracking-[0.2em] text-ivory">{room.name}</span>
                    </div>
                )}
            </Link>
            <div className="flex flex-1 flex-col gap-2 p-4">
                <div className="flex items-baseline justify-between gap-2">
                    <h3 className="font-serif text-2xl font-semibold"><Link href={href} className="hover:underline">{room.name}</Link></h3>
                    <span className="text-xs text-muted">{room.type}</span>
                </div>
                <p className="text-sm text-muted">{room.location}</p>
                <RoomFacts room={room} />
                {room.availability && <AvailabilityNote state={room.availability} />}
                <div className="mt-auto pt-2 text-sm">
                    {room.estimate_cents != null ? (
                        <p><span className="text-lg font-semibold">{peso(room.estimate_cents)}</span> <span className="text-muted">estimated stay</span></p>
                    ) : room.weekday_cents != null ? (
                        <p>
                            <span className="text-lg font-semibold">{peso(room.weekday_cents)}</span> <span className="text-muted">weekday night</span>
                            <span className="block text-muted">{peso(room.weekend_cents)} weekend night</span>
                        </p>
                    ) : <p className="text-muted">Please ask us for rates</p>}
                </div>
            </div>
        </article>
    );
}

/** The site cannot send a Messenger message for a guest, so the summary is copied by the guest and nothing is logged. */
export function InquiryHelper({ room, filters, estimate }: any) {
    const { contact } = usePage<any>().props as any;
    const lines = [
        `Hi UNIQA SUITES! I'd like to inquire about ${room.name} (${room.location}).`,
        filters.check_in && filters.check_out ? `Dates: ${dateOnly(filters.check_in)} to ${dateOnly(filters.check_out)}` : 'Dates: to be discussed',
        filters.guests ? `Guests: ${filters.guests}` : null,
        filters.early ? `Early check-in requested: ${filters.early}` : null,
        estimate?.total_cents != null ? `Website estimate: ${peso(estimate.total_cents)} (I understand this is not a confirmation)` : null,
    ].filter(Boolean);
    const summary = lines.join('\n');

    return (
        <div className="rounded-lg border border-line bg-white p-4">
            <h2 className="mb-1 font-serif text-xl font-semibold">Inquire about this suite</h2>
            <p className="mb-3 text-sm text-muted">Copy your summary, then paste it into your message to us. Your booking is only confirmed once our staff confirm it with you. No payment is taken on this website.</p>
            <pre className="mb-3 rounded-md bg-ivory p-3 font-sans text-sm whitespace-pre-wrap">{summary}</pre>
            <div className="flex flex-wrap gap-2">
                <CopyButton text={summary} label="Copy inquiry" small={false} />
                {contact.messenger_url ? (
                    <a href={contact.messenger_url} target="_blank" rel="noreferrer" className={buttonClass('primary')}><MessageCircle size={16} /> Open Messenger</a>
                ) : (
                    <p className="self-center text-xs text-muted">Our official Messenger link will be published here soon.</p>
                )}
            </div>
            {contact.messenger_url && <p className="mt-2 text-xs text-muted">Opening Messenger does not send anything. Paste your summary and press send.</p>}
        </div>
    );
}
