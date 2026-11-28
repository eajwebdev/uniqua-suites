import { Link } from '@inertiajs/react';
import { CalendarCheck, CalendarDays, CalendarX, ChevronDown, ChevronLeft, ChevronRight, Search, SlidersHorizontal } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { addDays, AmenityIcon, suiteName, BookNow, datesAreFree, Eyebrow, linkButton, LocationTabs, MessageButton, monthLabel, MonthGrid, PAGE_WIDTH, RoomCard, roomMatches, shiftMonth, SuiteFilterPanel, SuitePhoto, SWIPE_CARD, SWIPE_ROW, SwipeHint, todayInManila, useChatLink, useStayDates, useSuiteFilters } from '@/components/public';
import { cn, Input, Modal } from '@/components/ui';
import { pesoWhole, shortDate } from '@/lib/format';
import PublicLayout from '@/layouts/PublicLayout';

const STEPS = [
    ['Choose a suite and free dates', 'Taken dates are shown for every suite, and they cannot be chosen.'],
    ['Press Book Now', 'It opens a Messenger chat with us, with your suite and dates already written. Add how many guests.'],
    ['We confirm with you', 'Our staff send your quotation, then reserve your dates and confirm payment details in the chat.'],
];

const FINDER_ID = 'availability';
const SUITES_ID = 'suites';

// One rhythm for every section, so the page reads as one piece and stays short on a phone.
const SECTION = 'scroll-mt-16 py-10 sm:py-16';
// Plain, confident headings: one weight heavier than the text, never shouting in capitals.
const H2 = 'text-2xl leading-tight font-semibold tracking-tight sm:text-[2rem]';

type Stay = ReturnType<typeof useStayDates>;

// Only published suites reach this page, so a photo can only come from one of them.
const photoOf = (rooms: any[], slug: string) => rooms.find((r) => r.slug === slug && r.images.length)?.images[0] ?? rooms.find((r) => r.images.length)?.images[0];

const suiteCount = (n: number) => `${n} suite${n === 1 ? '' : 's'}`;

function scrollToId(id: string) {
    const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.getElementById(id)?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto' });
}

const FIELD = 'flex min-w-0 flex-col gap-1 text-xs font-semibold text-chocolate';
const CONTROL = 'w-full min-w-0 font-normal tracking-normal text-chocolate normal-case';

const datesLabel = (stay: Stay) => (stay.chosen ? `${shortDate(stay.checkIn)} – ${shortDate(stay.checkOut)}` : 'Any dates');
const guestsLabel = (guests: string) => (Number(guests) > 0 ? `${Number(guests)} guest${Number(guests) === 1 ? '' : 's'}` : 'Add guests');

/** A click anywhere on a segment opens the browser's calendar, not just on its small icon. */
function openPicker(input: HTMLInputElement | null) {
    try {
        input?.showPicker?.();
    } catch {
        // Some browsers allow it only for a direct tap on the field; the field still works as typed input.
    }
}

type SearchProps = { stay: Stay; guests: string; setGuests: (value: string) => void; onSearch: () => void };

/**
 * Dates and guests in one go, the way guests expect from any booking site. It writes into the same
 * state as the filters below, so whatever it finds is already narrowed when the list comes into view.
 * `pill` is the wide-screen bar in the hero; otherwise the fields stack, for the booking sheet.
 */
function StaySearch({ stay, guests, setGuests, onSearch, pill, className }: SearchProps & { pill?: boolean; className?: string }) {
    const today = todayInManila();
    // The date field being typed into from the keyboard; a click or tap opens the calendar instead, so it stays dressed.
    const [typing, setTyping] = useState<string | null>(null);
    const pointer = useRef(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        onSearch();
    };

    if (pill) {
        const segment = 'group/seg flex min-w-0 flex-1 cursor-pointer flex-col justify-center rounded-full px-6 py-2 transition-colors hover:bg-beige/70 focus-within:bg-white focus-within:shadow-[0_6px_20px_rgb(0_0_0/0.14)]';
        const caption = 'text-xs font-semibold text-chocolate';
        // No colour in the shared base: `cn` only joins classes, so two colours on one field would fight over CSS order.
        const bare = 'w-full min-w-0 cursor-pointer bg-transparent p-0 text-sm outline-none';
        const raw = `${bare} text-muted placeholder:text-muted`;
        const divider = <span aria-hidden className="my-3 w-px shrink-0 bg-line" />;

        /**
         * The browser's own date field, so typing, keyboard use and screen readers keep working, but dressed the
         * way guests know from booking sites: "Add dates" or "Nov 28" instead of "mm/dd/yyyy" and a calendar icon.
         * Reached from the keyboard, the real field shows, so a typed date is visible as it is entered.
         */
        const date = (label: string, value: string, min: string, onChange: (value: string) => void) => {
            const shown = typing === label;

            return (
                <label className={segment} onPointerDown={() => { pointer.current = true; }} onClick={(e) => openPicker(e.currentTarget.querySelector('input'))}>
                    <span className={caption}>{label}</span>
                    <span className="relative block">
                        <input
                            type="date"
                            min={min}
                            value={value}
                            onChange={(e) => onChange(e.target.value)}
                            onFocus={() => { if (!pointer.current) setTyping(label); pointer.current = false; }}
                            onBlur={() => setTyping(null)}
                            className={cn(bare, '[&::-webkit-calendar-picker-indicator]:hidden', shown ? 'text-chocolate' : 'text-transparent')}
                        />
                        {!shown && <span aria-hidden className={cn('pointer-events-none absolute inset-0 flex items-center truncate text-sm', value ? 'font-medium text-chocolate' : 'text-muted')}>{value ? shortDate(value) : 'Add dates'}</span>}
                    </span>
                </label>
            );
        };

        return (
            <form role="search" aria-label="Find a free suite" onSubmit={submit} className={cn('flex items-stretch rounded-full bg-ivory p-1.5 text-chocolate shadow-[0_12px_40px_rgb(0_0_0/0.35)] ring-1 ring-black/5', className)}>
                {date('Check-in', stay.checkIn, today, stay.setCheckIn)}
                {divider}
                {date('Check-out', stay.checkOut, stay.checkIn ? addDays(stay.checkIn, 1) : today, stay.setCheckOut)}
                {divider}
                <label className={cn(segment, 'max-w-44')}>
                    <span className={caption}>Guests</span>
                    <input type="number" min={1} max={20} inputMode="numeric" placeholder="Add guests" className={raw} value={guests} onChange={(e) => setGuests(e.target.value)} />
                </label>
                <button type="submit" className="ml-1 inline-flex min-h-14 shrink-0 items-center gap-2 rounded-full bg-gold px-6 text-[0.9375rem] font-semibold text-chocolate transition-colors hover:bg-chocolate hover:text-ivory">
                    <Search size={18} strokeWidth={2} aria-hidden /> Search
                </button>
            </form>
        );
    }

    return (
        <form role="search" aria-label="Find a free suite" onSubmit={submit} className={cn('grid grid-cols-2 items-end gap-3', className)}>
            <label className={FIELD}>Check-in
                <Input type="date" min={today} className={CONTROL} value={stay.checkIn} onChange={(e) => stay.setCheckIn(e.target.value)} />
            </label>
            <label className={FIELD}>Check-out
                <Input type="date" min={stay.checkIn ? addDays(stay.checkIn, 1) : today} className={CONTROL} value={stay.checkOut} onChange={(e) => stay.setCheckOut(e.target.value)} />
            </label>
            <label className={cn(FIELD, 'col-span-2')}>Guests
                <Input type="number" min={1} max={20} inputMode="numeric" placeholder="Any" className={CONTROL} value={guests} onChange={(e) => setGuests(e.target.value)} />
            </label>
            <button type="submit" className={cn(linkButton('dark'), 'col-span-2 mt-1')}>
                <Search size={16} strokeWidth={1.75} aria-hidden /> {stay.chosen ? 'Show free suites' : 'Search suites'}
            </button>
        </form>
    );
}

/**
 * The search folded into one tap target: in the phone hero, and in the header once the hero has scrolled away.
 * It shows what the guest has chosen so far and opens the booking sheet to change it.
 */
function SearchPill({ stay, guests, onOpen, inHeader, className }: { stay: Stay; guests: string; onOpen: () => void; inHeader?: boolean; className?: string }) {
    return (
        <button type="button" onClick={onOpen} aria-haspopup="dialog" aria-label={`Search suites: ${datesLabel(stay)}, ${guestsLabel(guests)}`}
            className={cn('flex w-full min-w-0 items-center rounded-full bg-ivory text-left text-chocolate ring-1 ring-black/5 transition-shadow', inHeader ? 'h-11 gap-3 pr-1.5 pl-5 text-sm hover:shadow-[0_4px_14px_rgb(0_0_0/0.35)]' : 'gap-3 py-2 pr-2 pl-3 shadow-[0_10px_30px_rgb(0_0_0/0.35)]', className)}>
            {inHeader ? (
                <>
                    <span className="truncate font-medium">{datesLabel(stay)}</span>
                    <span aria-hidden className="h-5 w-px shrink-0 bg-line" />
                    <span className="truncate text-muted">{guestsLabel(guests)}</span>
                    <span className="ml-1 flex size-8 shrink-0 items-center justify-center rounded-full bg-gold"><Search size={15} strokeWidth={2.25} aria-hidden /></span>
                </>
            ) : (
                <>
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gold"><Search size={18} strokeWidth={2} aria-hidden /></span>
                    <span className="min-w-0 flex-1 leading-tight">
                        <span className="block truncate text-sm font-semibold">{stay.chosen ? datesLabel(stay) : 'When are you staying?'}</span>
                        <span className="block truncate text-xs text-muted">{stay.chosen ? guestsLabel(guests) : 'Any dates · Add guests'}</span>
                    </span>
                </>
            )}
        </button>
    );
}

function Availability({ rooms, stay, onPick }: { rooms: any[]; stay: Stay; onPick: () => void }) {
    const today = todayInManila();
    const [month, setMonth] = useState(today.slice(0, 7));
    const all = [...rooms].reverse();
    const shown = stay.chosen ? all.filter((room) => datesAreFree(room, stay.checkIn, stay.checkOut)) : all;
    const taken = all.filter((room) => !shown.includes(room));

    // Keep the calendars on the month the guest selects in the shared date fields.
    useEffect(() => {
        if (stay.checkIn) setMonth(stay.checkIn.slice(0, 7));
    }, [stay.checkIn]);

    return (
        <section id={FINDER_ID} className={cn(SECTION, 'bg-beige')}>
            <div className={PAGE_WIDTH}>
                <div className="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
                    <div className="max-w-2xl">
                        <Eyebrow className="mb-2">Availability</Eyebrow>
                        <h2 className={H2}>Dates not available</h2>
                        <p className="mt-2 text-sm leading-relaxed text-muted sm:text-base">Crossed-out dates are already taken. A new guest can arrive on the day another leaves.</p>
                    </div>
                    {/* The dates live in the one search above; here they show as a chip that reopens it, not a second set of fields. */}
                    <div className="flex flex-wrap items-center gap-1">
                        <button type="button" onClick={onPick} aria-haspopup="dialog" className="inline-flex min-h-11 items-center gap-2 rounded-full border border-line bg-white px-4 text-sm transition-colors hover:border-chocolate">
                            <CalendarDays size={16} strokeWidth={1.75} className="text-bronze" aria-hidden />
                            {stay.chosen ? (
                                <>
                                    <span className="font-semibold">{shortDate(stay.checkIn)} – {shortDate(stay.checkOut)}</span>
                                    <span className="text-muted underline underline-offset-2">Change</span>
                                </>
                            ) : (
                                <span className="font-semibold">Choose your dates</span>
                            )}
                        </button>
                        {(stay.checkIn || stay.checkOut) && <button type="button" onClick={stay.clear} className="min-h-11 px-3 text-sm font-medium text-bronze hover:underline">Clear</button>}
                    </div>
                </div>

                <div className="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-chocolate/15 pt-3">
                    <div role="status" className="min-w-0 text-sm">
                        {stay.chosen && (
                            <p className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span className="inline-flex items-center gap-1.5 font-medium text-emerald-900"><CalendarCheck size={16} aria-hidden /> {suiteCount(shown.length)} free for {shortDate(stay.checkIn)} to {shortDate(stay.checkOut)}</span>
                                {taken.length > 0 && <span className="inline-flex items-center gap-1.5 text-muted"><CalendarX size={16} aria-hidden /> Taken: {taken.map((room) => suiteName(room.name)).join(', ')}</span>}
                            </p>
                        )}
                    </div>
                    <div className="-mr-2 ml-auto flex items-center">
                        <button type="button" aria-label="Previous month" disabled={month <= today.slice(0, 7)} onClick={() => setMonth(shiftMonth(month, -1))} className="flex size-11 items-center justify-center transition-colors hover:text-bronze disabled:opacity-30"><ChevronLeft size={20} strokeWidth={1.5} /></button>
                        <h3 className="min-w-36 text-center text-base font-semibold" aria-live="polite">{monthLabel(month)}</h3>
                        <button type="button" aria-label="Next month" onClick={() => setMonth(shiftMonth(month, 1))} className="flex size-11 items-center justify-center transition-colors hover:text-bronze"><ChevronRight size={20} strokeWidth={1.5} /></button>
                    </div>
                </div>

                {shown.length === 0 ? (
                    <p className="mt-4 rounded-xl bg-white p-8 text-center text-muted">No suite is free for all of those dates. Please try other dates.</p>
                ) : (
                    <>
                        <SwipeHint count={shown.length} />
                        {/* On a phone the calendars sit side by side and swipe, instead of stacking a screen each. */}
                        <div className={cn(SWIPE_ROW, 'mt-3 sm:grid-cols-2 sm:gap-4 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5')}>
                            {shown.map((room: any) => (
                                <article key={room.slug} className={cn(SWIPE_CARD, 'flex flex-col rounded-xl bg-white p-4')}>
                                    <div className="mb-2 flex items-baseline justify-between gap-3">
                                        <Link href={`/suites/${room.slug}`} className="shrink-0 text-base font-semibold transition-colors hover:text-bronze pointer-coarse:py-1">{suiteName(room.name)}</Link>
                                        <p className="min-w-0 truncate text-xs text-muted" title={room.location}>{room.location}</p>
                                    </div>
                                    <MonthGrid room={room} month={month} checkIn={stay.chosen ? stay.checkIn : undefined} checkOut={stay.chosen ? stay.checkOut : undefined} />
                                    <div className="mt-auto pt-3"><BookNow key={`${stay.checkIn}-${stay.checkOut}`} room={room} initialIn={stay.chosen ? stay.checkIn : undefined} initialOut={stay.chosen ? stay.checkOut : undefined} className="w-full" /></div>
                                </article>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </section>
    );
}

/** Answers staff keep in Admin > FAQ, marked to show on the website. */
function Questions({ faqs }: { faqs: { question: string; answer: string }[] }) {
    return (
        <div id="faq" className="min-w-0 scroll-mt-20">
            <Eyebrow className="mb-2">Questions</Eyebrow>
            <h2 className={cn(H2, 'mb-4')}>Frequently asked</h2>
            <div className="divide-y divide-chocolate/15 border-y border-chocolate/15">
                {faqs.map((f) => (
                    <details key={f.question} className="group">
                        <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between gap-4 py-3 text-base leading-snug font-medium transition-colors hover:text-bronze [&::-webkit-details-marker]:hidden">
                            {f.question}
                            <ChevronDown size={18} strokeWidth={1.5} className="shrink-0 text-bronze transition-transform group-open:rotate-180" aria-hidden />
                        </summary>
                        <p className="pb-4 text-sm leading-relaxed whitespace-pre-line text-muted sm:text-base">{f.answer}</p>
                    </details>
                ))}
            </div>
            <p className="mt-4 text-sm text-muted">More in <Link href="/policies" className="font-medium text-bronze hover:underline">Policies &amp; FAQ</Link>, or ask us in Messenger.</p>
        </div>
    );
}

/** Always within reach on a phone: what the guest has chosen so far, and the next step from there. */
function BookBar({ stay, free, lowest, onBook, onShow }: { stay: Stay; free: number; lowest: number | null; onBook: () => void; onShow: () => void }) {
    const chosen = stay.chosen;

    return (
        <div className="flex items-center gap-3">
            <button type="button" onClick={onBook} aria-label={chosen ? 'Change your dates' : 'Choose your dates'} className="min-w-0 flex-1 text-left leading-tight">
                {chosen ? (
                    <>
                        <span className="block truncate text-sm font-medium">{shortDate(stay.checkIn)} – {shortDate(stay.checkOut)}</span>
                        <span className={cn('block truncate text-xs', free ? 'text-gold' : 'text-beige')}>{free ? `${suiteCount(free)} free · Change` : 'None free · Change dates'}</span>
                    </>
                ) : (
                    <>
                        {lowest !== null && <span className="block truncate text-sm font-medium">From {pesoWhole(lowest)} <span className="font-normal text-beige">/ night</span></span>}
                        <span className="block truncate text-xs text-beige">Choose dates to see free suites</span>
                    </>
                )}
            </button>
            {chosen && free > 0 ? (
                <button type="button" onClick={onShow} className={cn(linkButton('gold', true), 'shrink-0')}>See suites</button>
            ) : (
                <MessageButton small hideIfMissing onClick={onBook} className="shrink-0" />
            )}
        </div>
    );
}

export default function Home({ rooms, amenities, properties, types, faqs = [] }: any) {
    const stay = useStayDates();
    const { filters, setFilter, clearFilters, active } = useSuiteFilters();
    // Which section the booking sheet returns to once dates are chosen; null while it is closed.
    const [picking, setPicking] = useState<string | null>(null);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const canBook = !!useChatLink();
    // Location has its own tabs, so the Filters button counts only what sits behind it.
    const hiddenFilters = [filters.type, filters.guests, filters.bathrooms, filters.balcony, stay.chosen].filter(Boolean).length;
    // Largest first, so the list opens on the flagship suites.
    const everything = [...rooms].reverse();
    const matching = everything.filter((room: any) => roomMatches(room, filters));
    const shown = stay.chosen ? matching.filter((room: any) => datesAreFree(room, stay.checkIn, stay.checkOut)) : matching;
    const hero = photoOf(rooms, 'paris');
    const closing = photoOf(rooms, 'tokyo');
    const narrowed = active || stay.chosen;
    const rates = rooms.flatMap((room: any) => (room.rate ? [Math.min(room.rate.weekday, room.rate.weekend)] : []));
    const lowest = rates.length ? Math.min(...rates) : null;
    // What each location tab would show with the other filters and dates as they are.
    const fitsAllButLocation = everything.filter((room: any) => roomMatches(room, { ...filters, location: '' }) && (!stay.chosen || datesAreFree(room, stay.checkIn, stay.checkOut)));
    const locationCounts: Record<string, number> = { '': fitsAllButLocation.length };
    fitsAllButLocation.forEach((room: any) => { locationCounts[room.location_slug] = (locationCounts[room.location_slug] ?? 0) + 1; });
    // A location with suites keeps its tab at 0, so it does not vanish while dates are chosen.
    rooms.forEach((room: any) => { locationCounts[room.location_slug] ??= 0; });
    const places = properties
        .map((p: any) => ({ ...p, here: rooms.filter((r: any) => r.location_slug === p.slug) }))
        .filter((p: any) => p.here.length > 0);

    const clearAll = () => {
        clearFilters();
        stay.clear();
    };

    // The same list, narrowed where the guest already is: no trip to another page.
    const showLocation = (slug: string) => {
        setFilter('location', slug);
        scrollToId(SUITES_ID);
    };

    const showSection = (id: string = SUITES_ID) => {
        setPicking(null);
        // After the sheet has closed and given the page its scrolling back.
        setTimeout(() => scrollToId(id), 0);
    };

    const openSheet = rooms.length > 0 ? () => setPicking(SUITES_ID) : undefined;
    const searchProps: SearchProps = { stay, guests: filters.guests, setGuests: (value) => setFilter('guests', value), onSearch: () => showSection(picking ?? SUITES_ID) };

    return (
        <PublicLayout
            onBook={openSheet}
            headerSearch={rooms.length > 0 ? <SearchPill inHeader stay={stay} guests={filters.guests} onOpen={() => setPicking(SUITES_ID)} className="w-[min(24rem,40vw)]" /> : undefined}
            stickyBar={rooms.length > 0 && canBook ? <BookBar stay={stay} free={shown.length} lowest={lowest} onBook={() => setPicking(SUITES_ID)} onShow={() => showSection()} /> : undefined}
            description="UNIQA SUITES: studio, one-bedroom and two-bedroom suites at The Loop, Limketkai and Mesaverte Residences in Cagayan de Oro. See the dates already taken, then book free dates in a Messenger chat with our staff."
        >
            {/* On a phone the next section peeks in under the hero, a cue that there is more below. */}
            <section className="relative isolate flex min-h-[min(calc(82svh-4rem),38rem)] items-end sm:min-h-[min(calc(100svh-4rem),46rem)] overflow-hidden bg-chocolate text-ivory">
                {hero && <img src={hero.url} alt="" {...{ fetchpriority: 'high' }} className="absolute inset-0 -z-10 size-full scale-105 object-cover object-[72%_50%]" />}
                <div className="absolute inset-0 -z-10 bg-gradient-to-t from-chocolate via-chocolate/60 to-chocolate/10" />
                <div className="absolute inset-0 -z-10 bg-gradient-to-r from-chocolate/60 to-transparent" />
                <div className={cn(PAGE_WIDTH, 'pt-24 pb-6 sm:pb-12')}>
                    <Eyebrow light className="mb-3">Cagayan de Oro</Eyebrow>
                    <h1 className="max-w-3xl text-[2.125rem] leading-[1.1] font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl">Suites with a character of their own.</h1>
                    <p className="mt-3 max-w-xl text-sm leading-relaxed text-beige sm:mt-4 sm:text-lg">Studios, one-bedroom and two-bedroom suites at The Loop, Limketkai and Mesaverte Residences.</p>
                    {rooms.length > 0 ? (
                        <div className="mt-6 sm:mt-8">
                            <SearchPill stay={stay} guests={filters.guests} onOpen={() => setPicking(SUITES_ID)} className="md:hidden" />
                            <StaySearch {...searchProps} pill className="max-w-3xl max-md:hidden" />
                            {lowest !== null && <p className="mt-3 text-xs text-beige sm:text-sm">Suites from <span className="font-medium text-ivory">{pesoWhole(lowest)}</span> a night · Book in Messenger, no payment on this website</p>}
                        </div>
                    ) : (
                        <div className="mt-6 sm:mt-8"><MessageButton hideIfMissing className="w-full sm:w-auto" /></div>
                    )}
                </div>
            </section>

            {rooms.length > 0 && <Availability rooms={rooms} stay={stay} onPick={() => setPicking(FINDER_ID)} />}

            <section id={SUITES_ID} className={SECTION}>
                <div className={PAGE_WIDTH}>
                    <Eyebrow className="mb-2">Our rooms</Eyebrow>
                    <h2 className={H2}>Find your suite</h2>
                    <p className="mt-2 max-w-2xl text-sm leading-relaxed text-muted sm:text-base">Pick a location, then choose your dates and only the suites free for your whole stay will show.</p>

                    {rooms.length > 0 && (
                        <>
                            <LocationTabs
                                value={filters.location}
                                onChange={(slug) => setFilter('location', slug)}
                                properties={properties}
                                counts={locationCounts}
                                className="mt-5"
                                extra={
                                    // On a phone the full filter form would fill a screen, so it waits behind one button at the end of the row.
                                    <button type="button" aria-expanded={filtersOpen} aria-controls="suite-filters" onClick={() => setFiltersOpen(!filtersOpen)} className={cn('flex min-h-10 items-center gap-1.5 rounded-full border px-4 text-sm font-medium transition-colors pointer-coarse:min-h-11 sm:hidden', filtersOpen ? 'border-chocolate bg-chocolate text-ivory' : 'border-line bg-white text-chocolate')}>
                                        <SlidersHorizontal size={15} strokeWidth={1.75} aria-hidden /> Filters
                                        {hiddenFilters > 0 && <span className="flex size-5 items-center justify-center rounded-full bg-gold text-xs font-semibold text-chocolate">{hiddenFilters}</span>}
                                    </button>
                                }
                            />
                            <div id="suite-filters" className={cn(!filtersOpen && 'max-sm:hidden')}>
                                <SuiteFilterPanel stay={stay} filters={filters} setFilter={setFilter} types={types} className="mt-3" />
                            </div>
                        </>
                    )}

                    {rooms.length > 0 && (
                        <div className="mt-3 flex flex-wrap items-center gap-x-4 text-sm">
                            <p role="status" className="py-2 font-medium text-muted">{suiteCount(shown.length)}{stay.chosen ? ` free for ${shortDate(stay.checkIn)} to ${shortDate(stay.checkOut)}` : ''}</p>
                            {narrowed && <button type="button" onClick={clearAll} className="min-h-11 font-medium text-bronze hover:underline">Show all suites</button>}
                        </div>
                    )}

                    {shown.length === 0 ? (
                        <p className="mt-3 rounded-xl border border-line p-8 text-center text-muted">{rooms.length === 0 ? 'Our suites will be listed here soon.' : stay.chosen ? 'No suite matches for those dates. Please try other dates or fewer filters.' : 'No suites match these filters. Try fewer filters.'}</p>
                    ) : (
                        <>
                            <SwipeHint count={shown.length} />
                            {/* A short list fills the row, so one or two matches never leave a gap beside them. */}
                            <div className={cn(SWIPE_ROW, 'mt-3 sm:gap-x-6 sm:gap-y-10', shown.length > 1 && 'sm:grid-cols-2', shown.length > 2 && 'md:grid-cols-3')}>
                                {shown.map((room: any) => (
                                    <div key={room.slug} className={cn(shown.length > 1 ? SWIPE_CARD : 'w-full', 'flex flex-col')}>
                                        <RoomCard room={room} feature={shown.length === 1} checkIn={stay.chosen ? stay.checkIn : undefined} checkOut={stay.chosen ? stay.checkOut : undefined} guests={filters.guests} />
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </section>

            {amenities.length > 0 && (
                <section className="bg-chocolate py-10 text-ivory sm:py-14">
                    <div className={cn(PAGE_WIDTH, 'grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center lg:gap-16')}>
                        <div>
                            <Eyebrow light className="mb-2">In every suite</Eyebrow>
                            <h2 className="text-2xl leading-tight font-semibold tracking-tight sm:text-[2rem]">The details are already taken care of.</h2>
                        </div>
                        <ul className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3 sm:gap-y-4">
                            {amenities.map((name: string) => (
                                <li key={name} className="flex min-w-0 items-center gap-3 text-sm text-beige">
                                    <span className="flex size-9 shrink-0 items-center justify-center border border-gold/30"><AmenityIcon name={name} size={18} className="text-gold" /></span>
                                    <span className="min-w-0">{name}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            )}

            {places.length > 0 && (
                <section className={SECTION}>
                    <div className={PAGE_WIDTH}>
                        <Eyebrow className="mb-2">Where we are</Eyebrow>
                        <h2 className={cn(H2, 'mb-5 sm:mb-8')}>Our addresses in the city</h2>
                        {/* Side by side even on a phone, so both addresses fit on one screen. */}
                        <div className={cn('grid gap-3 sm:gap-6', places.length > 1 && 'grid-cols-2', places.length > 2 && 'lg:grid-cols-3')}>
                            {places.map((p: any) => {
                                const photo = photoOf(p.here, p.slug === 'the-loop' ? 'milan' : 'berlin');

                                return (
                                    <button key={p.slug} type="button" onClick={() => showLocation(p.slug)} aria-label={`Show the ${p.here.length} suites at ${p.location_label}`} className="group relative isolate block w-full overflow-hidden rounded-xl text-left text-ivory">
                                        <SuitePhoto image={photo && { url: photo.url, alt: '' }} name={p.name} className={places.length > 1 ? 'aspect-[4/5] sm:aspect-[3/2]' : 'aspect-[16/9] sm:aspect-[21/9]'} />
                                        <div className="absolute inset-0 bg-gradient-to-t from-chocolate/90 via-chocolate/30 to-transparent" />
                                        <div className="absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 p-4 sm:p-6">
                                            <div className="min-w-0">
                                                <h3 className="text-base leading-tight font-semibold sm:text-2xl">{p.location_label}</h3>
                                                <p className="mt-1 text-xs text-beige sm:text-sm">{suiteCount(p.here.length)}</p>
                                            </div>
                                            <ChevronRight size={22} strokeWidth={1.25} className="shrink-0 text-gold transition-transform group-hover:translate-x-1 max-sm:hidden" aria-hidden />
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </section>
            )}

            {/* How to book and the questions people ask before booking, read side by side on a wide screen. */}
            <section className={cn(SECTION, 'bg-beige')}>
                <div className={cn(PAGE_WIDTH, faqs.length > 0 && 'grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16')}>
                    <div className="min-w-0">
                        <Eyebrow className="mb-2">How to book</Eyebrow>
                        <h2 className={cn(H2, 'mb-4')}>Every stay is arranged by a person.</h2>
                        <ol className={cn('grid gap-4', faqs.length === 0 && 'md:grid-cols-3 md:gap-8')}>
                            {STEPS.map(([title, body], i) => (
                                <li key={title} className="flex gap-4 border-t border-chocolate/20 pt-4">
                                    <span className="w-7 shrink-0 text-lg leading-snug font-semibold text-bronze" aria-hidden>0{i + 1}</span>
                                    <div className="min-w-0">
                                        <h3 className="text-base leading-snug font-semibold">{title}</h3>
                                        <p className="mt-1 text-sm leading-relaxed text-muted">{body}</p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                        <p className="mt-5 text-sm text-muted">No booking or payment happens on this website.</p>
                    </div>
                    {faqs.length > 0 && <Questions faqs={faqs} />}
                </div>
            </section>

            <section className="relative isolate overflow-hidden bg-chocolate text-ivory">
                {closing && <img src={closing.url} alt="" loading="lazy" className="absolute inset-0 -z-10 size-full scale-105 object-cover" />}
                <div className="absolute inset-0 -z-10 bg-chocolate/75" />
                <div className={cn(PAGE_WIDTH, 'py-14 text-center sm:py-24')}>
                    <h2 className="mx-auto mb-6 max-w-3xl text-[1.75rem] leading-tight font-semibold tracking-tight text-balance sm:text-5xl">Your stay begins with a message.</h2>
                    <MessageButton onClick={openSheet} />
                </div>
            </section>

            {picking && (
                <Modal open title="Choose your dates" onClose={() => setPicking(null)}>
                    <p className="mb-4 text-sm leading-relaxed text-muted">Taken dates cannot be booked. We will show only the suites free for your whole stay, each with its own Book Now.</p>
                    <StaySearch {...searchProps} />
                </Modal>
            )}
        </PublicLayout>
    );
}
