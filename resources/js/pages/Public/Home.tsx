import { AmenityIcon, datesAreFree, Eyebrow, MessageButton, PAGE_WIDTH, RoomCard, roomMatches, SuiteFilterPanel, SuitePhoto, SWIPE_CARD, SWIPE_ROW, SwipeHint, useStayDates, useSuiteFilters } from '@/components/public';
import { cn } from '@/components/ui';
import { shortDate } from '@/lib/format';
import PublicLayout from '@/layouts/PublicLayout';

const STEPS = [
    ['Choose a suite and free dates', 'Taken dates are shown for every suite, and they cannot be chosen.'],
    ['Press Book Now', 'It opens a Messenger chat with us, with your suite and dates already written. Add how many guests.'],
    ['We confirm with you', 'Our staff send your quotation, then reserve your dates and confirm payment details in the chat.'],
];

const FINDER_ID = 'availability';

// Only published suites reach this page, so a photo can only come from one of them.
const photoOf = (rooms: any[], slug: string) => rooms.find((r) => r.slug === slug && r.images.length)?.images[0] ?? rooms.find((r) => r.images.length)?.images[0];

const suiteCount = (n: number) => `${n} suite${n === 1 ? '' : 's'}`;

export default function Home({ rooms, amenities, properties, types }: any) {
    const stay = useStayDates();
    const { filters, setFilter, clearFilters, active } = useSuiteFilters();
    // Largest first, so the list opens on the flagship suites.
    const everything = [...rooms].reverse();
    const matching = everything.filter((room: any) => roomMatches(room, filters));
    const shown = stay.chosen ? matching.filter((room: any) => datesAreFree(room, stay.checkIn, stay.checkOut)) : matching;
    const hero = photoOf(rooms, 'paris');
    const closing = photoOf(rooms, 'tokyo');
    const narrowed = active || stay.chosen;

    const clearAll = () => {
        clearFilters();
        stay.clear();
    };

    // The same list, narrowed where the guest already is: no trip to another page.
    const showLocation = (slug: string) => {
        setFilter('location', slug);
        document.getElementById(FINDER_ID)?.scrollIntoView();
    };

    return (
        <PublicLayout stickyBookFor={FINDER_ID} description="UNIQA SUITES: studio, one-bedroom and two-bedroom suites at The Loop, Limketkai and Mesaverte Residences in Cagayan de Oro. See the dates already taken, then book free dates in a Messenger chat with our staff.">
            <section className="relative isolate flex min-h-[calc(100svh-4rem)] items-end overflow-hidden bg-chocolate text-ivory">
                {hero && <img src={hero.url} alt="" {...{ fetchpriority: 'high' }} className="absolute inset-0 -z-10 size-full scale-105 object-cover object-[72%_50%]" />}
                <div className="absolute inset-0 -z-10 bg-gradient-to-t from-chocolate via-chocolate/70 to-chocolate/10" />
                <div className="absolute inset-0 -z-10 bg-gradient-to-r from-chocolate/60 to-transparent" />
                <div className={cn(PAGE_WIDTH, 'pt-28 pb-8 sm:pb-20')}>
                    <Eyebrow light className="mb-4 sm:mb-5">Cagayan de Oro</Eyebrow>
                    <h1 className="max-w-4xl font-serif text-5xl leading-[1.05] font-light sm:text-7xl lg:text-8xl">Suites with a character of their own.</h1>
                    <p className="mt-4 max-w-xl text-base leading-relaxed text-beige sm:mt-6 sm:text-lg">Studios, one-bedroom and two-bedroom suites at The Loop, Limketkai and Mesaverte Residences.</p>
                    <div className="mt-7 sm:mt-9"><MessageButton hideIfMissing className="w-full sm:w-auto" /></div>
                </div>
            </section>

            <section id={FINDER_ID} className="scroll-mt-16 py-12 sm:py-24">
                <div className={PAGE_WIDTH}>
                    <Eyebrow className="mb-3">Our suites</Eyebrow>
                    <h2 className="font-serif text-4xl font-light sm:text-6xl">Find your suite</h2>
                    <p className="mt-3 max-w-2xl leading-relaxed text-muted">Choose your dates and only the suites free for your whole stay will show.</p>

                    {rooms.length > 0 && <SuiteFilterPanel stay={stay} filters={filters} setFilter={setFilter} properties={properties} types={types} className="mt-6 sm:mt-8" />}

                    {rooms.length > 0 && (
                        <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <p role="status" className="font-medium text-muted">{suiteCount(shown.length)}{stay.chosen ? ` free for ${shortDate(stay.checkIn)} to ${shortDate(stay.checkOut)}` : ''}</p>
                            {narrowed && <button type="button" onClick={clearAll} className="min-h-11 font-medium text-bronze hover:underline">Show all suites</button>}
                        </div>
                    )}

                    {shown.length === 0 ? (
                        <p className="mt-5 border border-line p-10 text-center text-muted">{rooms.length === 0 ? 'Our suites will be listed here soon.' : stay.chosen ? 'No suite matches for those dates. Please try other dates or fewer filters.' : 'No suites match these filters. Try fewer filters.'}</p>
                    ) : (
                        <>
                            <SwipeHint count={shown.length} />
                            {/* A short list fills the row, so one or two matches never leave a gap beside them. */}
                            <div className={cn(SWIPE_ROW, 'mt-3 sm:mt-5 sm:gap-x-8 sm:gap-y-14', shown.length > 1 && 'sm:grid-cols-2', shown.length > 2 && 'lg:grid-cols-3')}>
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
                <section className="bg-chocolate py-12 text-ivory sm:py-20">
                    <div className={PAGE_WIDTH}>
                        <Eyebrow light className="mb-3">In every suite</Eyebrow>
                        <h2 className="mb-8 max-w-2xl font-serif text-4xl font-light sm:mb-12 sm:text-5xl">The details are already taken care of.</h2>
                        <ul className="grid grid-cols-2 gap-x-6 gap-y-6 sm:grid-cols-4 sm:gap-y-9 lg:grid-cols-7">
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

            {rooms.length > 0 && (
                <section className="py-12 sm:py-28">
                    <div className={PAGE_WIDTH}>
                        <Eyebrow className="mb-3">Where we are</Eyebrow>
                        <h2 className="mb-6 font-serif text-4xl font-light sm:mb-12 sm:text-6xl">Our addresses in the city</h2>
                        <div className="grid gap-4 md:grid-cols-2 md:gap-8">
                            {properties.map((p: any) => {
                                const here = rooms.filter((r: any) => r.location_slug === p.slug);
                                if (here.length === 0) return null;
                                const photo = photoOf(here, p.slug === 'the-loop' ? 'milan' : 'berlin');

                                return (
                                    <button key={p.slug} type="button" onClick={() => showLocation(p.slug)} aria-label={`Show the ${here.length} suites at ${p.location_label}`} className="group relative isolate block w-full overflow-hidden text-left text-ivory">
                                        <SuitePhoto image={photo && { url: photo.url, alt: '' }} name={p.name} className="aspect-[4/3] sm:aspect-[3/2]" />
                                        <div className="absolute inset-0 bg-gradient-to-t from-chocolate/90 via-chocolate/30 to-transparent" />
                                        <div className="absolute inset-x-0 bottom-0 p-6 sm:p-8">
                                            <h3 className="font-serif text-3xl font-normal sm:text-4xl">{p.location_label}</h3>
                                            <p className="mt-1 text-sm text-beige">{suiteCount(here.length)}</p>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </section>
            )}

            <section className="pb-12 sm:pb-28">
                <div className={PAGE_WIDTH}>
                    <Eyebrow className="mb-3">How to book</Eyebrow>
                    <h2 className="mb-8 max-w-2xl font-serif text-4xl font-light sm:mb-12 sm:text-6xl">Every stay is arranged by a person.</h2>
                    <ol className="grid gap-7 md:grid-cols-3 md:gap-10">
                        {STEPS.map(([title, body], i) => (
                            <li key={title} className="border-t border-chocolate/25 pt-5">
                                <span className="font-serif text-2xl text-bronze" aria-hidden>0{i + 1}</span>
                                <h3 className="mt-2 mb-2 font-serif text-2xl font-normal sm:text-3xl">{title}</h3>
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
                <div className={cn(PAGE_WIDTH, 'py-16 text-center sm:py-36')}>
                    <h2 className="mx-auto mb-8 max-w-3xl font-serif text-4xl leading-tight font-light sm:text-6xl">Your stay begins with a message.</h2>
                    <MessageButton />
                </div>
            </section>
        </PublicLayout>
    );
}
