import { Link } from '@inertiajs/react';
import { CalendarCheck, CalendarX, ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AmenityIcon, BookNow, DateFilter, datesAreFree, Eyebrow, linkButton, MessageButton, monthLabel, MonthGrid, PAGE_WIDTH, RoomCard, shiftMonth, SuitePhoto, todayInManila, useStayDates } from '@/components/public';
import { cn } from '@/components/ui';
import { shortDate } from '@/lib/format';
import PublicLayout from '@/layouts/PublicLayout';

const STEPS = [
    ['Choose a suite and free dates', 'Taken dates are shown for every suite, and they cannot be chosen.'],
    ['Press Book Now', 'It opens a Messenger chat with us, with your suite and dates already written. Add how many guests.'],
    ['We confirm with you', 'Our staff send your quotation, then reserve your dates and confirm payment details in the chat.'],
];

// Only published suites reach this page, so a photo can only come from one of them.
const photoOf = (rooms: any[], slug: string) => rooms.find((r) => r.slug === slug && r.images.length)?.images[0] ?? rooms.find((r) => r.images.length)?.images[0];

const suiteCount = (n: number) => `${n} suite${n === 1 ? '' : 's'}`;

function Availability({ rooms, stay }: { rooms: any[]; stay: ReturnType<typeof useStayDates> }) {
    const today = todayInManila();
    const [month, setMonth] = useState(today.slice(0, 7));
    const all = [...rooms].reverse();
    const shown = stay.chosen ? all.filter((room) => datesAreFree(room, stay.checkIn, stay.checkOut)) : all;
    const taken = all.filter((room) => !shown.includes(room));

    // The calendars jump to the month the guest is asking about.
    useEffect(() => {
        if (stay.checkIn) setMonth(stay.checkIn.slice(0, 7));
    }, [stay.checkIn]);

    return (
        <section id="availability" className="scroll-mt-16 bg-beige py-20 sm:py-28">
            <div className={PAGE_WIDTH}>
                <Eyebrow className="mb-3">Availability</Eyebrow>
                <h2 className="max-w-3xl font-serif text-4xl font-light sm:text-6xl">Dates not available</h2>
                <p className="mt-4 max-w-2xl leading-relaxed text-muted">Crossed-out dates are already taken and cannot be booked. Choose your dates and only the suites free for your whole stay will show. A new guest can arrive on the day another leaves.</p>

                <div className="mt-8 flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                    <DateFilter stay={stay} />
                    <div className="flex items-center">
                        <button type="button" aria-label="Previous month" disabled={month <= today.slice(0, 7)} onClick={() => setMonth(shiftMonth(month, -1))} className="flex size-11 items-center justify-center disabled:opacity-30"><ChevronLeft size={20} strokeWidth={1.5} /></button>
                        <h3 className="min-w-44 text-center font-serif text-2xl font-normal" aria-live="polite">{monthLabel(month)}</h3>
                        <button type="button" aria-label="Next month" onClick={() => setMonth(shiftMonth(month, 1))} className="flex size-11 items-center justify-center"><ChevronRight size={20} strokeWidth={1.5} /></button>
                    </div>
                </div>

                <div role="status" className="mt-5 text-sm">
                    {stay.chosen && (
                        <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="inline-flex items-center gap-1.5 font-medium text-emerald-900"><CalendarCheck size={16} aria-hidden /> {suiteCount(shown.length)} free for {shortDate(stay.checkIn)} to {shortDate(stay.checkOut)}</span>
                            {taken.length > 0 && <span className="inline-flex items-center gap-1.5 text-muted"><CalendarX size={16} aria-hidden /> Not available: {taken.map((room) => room.name).join(', ')}</span>}
                        </p>
                    )}
                </div>

                {shown.length === 0 ? (
                    <p className="mt-6 bg-white p-10 text-center text-muted">No suite is free for all of those dates. Please try other dates.</p>
                ) : (
                    <div className="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                        {shown.map((room: any) => (
                            <article key={room.slug} className="flex flex-col bg-white p-5">
                                <div className="mb-3 min-w-0">
                                    <Link href={`/suites/${room.slug}`} className="font-serif text-2xl tracking-[0.08em] uppercase transition-colors hover:text-bronze pointer-coarse:inline-block pointer-coarse:py-1">{room.name}</Link>
                                    <p className="text-xs text-muted">{room.location}</p>
                                </div>
                                <MonthGrid room={room} month={month} checkIn={stay.chosen ? stay.checkIn : undefined} checkOut={stay.chosen ? stay.checkOut : undefined} />
                                <div className="mt-4"><BookNow key={`${stay.checkIn}-${stay.checkOut}`} room={room} initialIn={stay.chosen ? stay.checkIn : undefined} initialOut={stay.chosen ? stay.checkOut : undefined} className="w-full" /></div>
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </section>
    );
}

export default function Home({ rooms, amenities, properties }: any) {
    // Largest first. The gallery opens on the flagship suites.
    const stay = useStayDates();
    const everything = [...rooms].reverse();
    const collection = stay.chosen ? everything.filter((room: any) => datesAreFree(room, stay.checkIn, stay.checkOut)) : everything;
    const hero = photoOf(rooms, 'paris');
    const closing = photoOf(rooms, 'tokyo');

    return (
        <PublicLayout description="UNIQA SUITES: studio, one-bedroom and two-bedroom suites at The Loop, Limketkai and Mesaverte Residences in Cagayan de Oro. See the dates already taken, then book free dates in a Messenger chat with our staff.">
            <section className="relative isolate flex min-h-[calc(100svh-4rem)] items-end overflow-hidden bg-chocolate text-ivory">
                {hero && <img src={hero.url} alt="" className="absolute inset-0 -z-10 size-full scale-105 object-cover object-[72%_50%]" />}
                <div className="absolute inset-0 -z-10 bg-gradient-to-t from-chocolate via-chocolate/70 to-chocolate/10" />
                <div className="absolute inset-0 -z-10 bg-gradient-to-r from-chocolate/60 to-transparent" />
                <div className={cn(PAGE_WIDTH, 'pt-28 pb-14 sm:pb-20')}>
                    <Eyebrow light className="mb-5">Cagayan de Oro</Eyebrow>
                    <h1 className="max-w-4xl font-serif text-5xl leading-[1.05] font-light sm:text-7xl lg:text-8xl">Suites with a character of their own.</h1>
                    <p className="mt-6 max-w-xl text-lg leading-relaxed text-beige">Studios, one-bedroom and two-bedroom suites at The Loop, Limketkai and Mesaverte Residences.</p>
                    <div className="mt-9 flex flex-wrap gap-3">
                        <a href="#suites" className={linkButton('outline')}>Browse suites</a>
                        <MessageButton hideIfMissing />
                    </div>
                </div>
            </section>

            {rooms.length > 0 && <Availability rooms={rooms} stay={stay} />}

            <section id="suites" className="scroll-mt-16 py-20 sm:py-28">
                <div className={PAGE_WIDTH}>
                    <div className="mb-12 flex flex-wrap items-end justify-between gap-5">
                        <div>
                            <Eyebrow className="mb-3">The collection</Eyebrow>
                            <h2 className="font-serif text-4xl font-light sm:text-6xl">Choose your suite</h2>
                            <p className="mt-2 text-sm text-muted" role="status">{stay.chosen ? `${suiteCount(collection.length)} free for ${shortDate(stay.checkIn)} to ${shortDate(stay.checkOut)}` : 'Choose your dates to see only the suites that are free.'}</p>
                        </div>
                        <Link href="/suites" className={linkButton('line', true)}>More filters</Link>
                    </div>
                    {rooms.length > 0 && <DateFilter stay={stay} className="mb-10" />}
                    {collection.length === 0 ? (
                        <p className="border border-line p-10 text-center text-muted">{stay.chosen ? 'No suite is free for all of those dates. Please try other dates.' : 'Our suites will be listed here soon.'}</p>
                    ) : (
                        <div className="grid gap-x-8 gap-y-14 sm:grid-cols-2 lg:grid-cols-6">
                            {collection.map((room: any, i: number) => (
                                <div key={room.slug} className={cn('flex flex-col', i === 0 ? 'sm:col-span-2 lg:col-span-6' : i < 3 ? 'lg:col-span-3' : 'lg:col-span-2')}>
                                    <RoomCard room={room} feature={i === 0} checkIn={stay.chosen ? stay.checkIn : undefined} checkOut={stay.chosen ? stay.checkOut : undefined} />
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </section>

            {amenities.length > 0 && (
                <section className="bg-chocolate py-20 text-ivory">
                    <div className={PAGE_WIDTH}>
                        <Eyebrow light className="mb-3">In every suite</Eyebrow>
                        <h2 className="mb-12 max-w-2xl font-serif text-4xl font-light sm:text-5xl">The details are already taken care of.</h2>
                        <ul className="grid grid-cols-2 gap-x-6 gap-y-9 sm:grid-cols-4 lg:grid-cols-7">
                            {amenities.map((name: string) => (
                                <li key={name} className="border-t border-gold/30 pt-4">
                                    <AmenityIcon name={name} size={26} className="mb-3 text-gold" />
                                    <span className="text-sm text-beige">{name}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            )}

            {collection.length > 0 && (
                <section className="py-20 sm:py-28">
                    <div className={PAGE_WIDTH}>
                        <Eyebrow className="mb-3">Where we are</Eyebrow>
                        <h2 className="mb-12 font-serif text-4xl font-light sm:text-6xl">Our addresses in the city</h2>
                        <div className="grid gap-8 md:grid-cols-2">
                            {properties.map((p: any) => {
                                const here = rooms.filter((r: any) => r.location === p.location_label);
                                if (here.length === 0) return null;
                                const photo = photoOf(here, p.slug === 'the-loop' ? 'milan' : 'berlin');

                                return (
                                    <Link key={p.slug} href={`/suites?location=${p.slug}`} className="group relative isolate block overflow-hidden text-ivory">
                                        <SuitePhoto image={photo && { url: photo.url, alt: '' }} name={p.name} className="aspect-[4/3] sm:aspect-[3/2]" />
                                        <div className="absolute inset-0 bg-gradient-to-t from-chocolate/90 via-chocolate/30 to-transparent" />
                                        <div className="absolute inset-x-0 bottom-0 p-6 sm:p-8">
                                            <h3 className="font-serif text-3xl font-normal sm:text-4xl">{p.location_label}</h3>
                                            <p className="mt-1 text-sm text-beige">{here.length} suite{here.length === 1 ? '' : 's'}</p>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                </section>
            )}

            <section className="py-20 sm:py-28">
                <div className={PAGE_WIDTH}>
                    <Eyebrow className="mb-3">How to book</Eyebrow>
                    <h2 className="mb-12 max-w-2xl font-serif text-4xl font-light sm:text-6xl">Every stay is arranged by a person.</h2>
                    <ol className="grid gap-10 md:grid-cols-3">
                        {STEPS.map(([title, body], i) => (
                            <li key={title} className="border-t border-chocolate/25 pt-5">
                                <span className="font-serif text-2xl text-bronze" aria-hidden>0{i + 1}</span>
                                <h3 className="mt-2 mb-2 font-serif text-3xl font-normal">{title}</h3>
                                <p className="leading-relaxed text-muted">{body}</p>
                            </li>
                        ))}
                    </ol>
                    <p className="mt-10 text-sm text-muted">No booking or payment happens on this website.</p>
                </div>
            </section>

            <section className="relative isolate overflow-hidden bg-chocolate text-ivory">
                {closing && <img src={closing.url} alt="" loading="lazy" className="absolute inset-0 -z-10 size-full scale-105 object-cover" />}
                <div className="absolute inset-0 -z-10 bg-chocolate/75" />
                <div className={cn(PAGE_WIDTH, 'py-24 text-center sm:py-36')}>
                    <h2 className="mx-auto mb-8 max-w-3xl font-serif text-4xl leading-tight font-light sm:text-6xl">Your stay begins with a message.</h2>
                    <MessageButton />
                </div>
            </section>
        </PublicLayout>
    );
}
