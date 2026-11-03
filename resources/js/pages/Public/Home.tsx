import { Link } from '@inertiajs/react';
import { RoomCard, SearchBar } from '@/components/public';
import { buttonClass } from '@/components/ui';
import PublicLayout from '@/layouts/PublicLayout';

export default function Home({ rooms, properties, types }: any) {
    return (
        <PublicLayout description="UNIQA SUITES: studio, one-bedroom and two-bedroom serviced suites at The Loop, Limketkai and Mesaverte Residences in Cagayan de Oro. Check estimated rates and inquire with us directly.">
            <section className="bg-chocolate text-ivory">
                <div className="mx-auto max-w-6xl px-4 pt-14 pb-10 sm:pt-20">
                    <p className="mb-3 text-xs tracking-[0.3em] text-gold uppercase">Cagayan de Oro</p>
                    <h1 className="max-w-3xl font-serif text-4xl leading-tight font-medium sm:text-6xl">Named suites, thoughtfully kept, for short and longer stays.</h1>
                    <p className="mt-4 max-w-xl text-beige">Choose from studios, one-bedroom and two-bedroom suites at The Loop, Limketkai and Mesaverte Residences. See an estimate, then talk to us directly.</p>
                    <div className="mt-8"><SearchBar properties={properties} types={types} /></div>
                </div>
            </section>

            <section className="mx-auto max-w-6xl px-4 py-14">
                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <h2 className="font-serif text-3xl font-medium sm:text-4xl">Our suites</h2>
                    <Link href="/suites" className={buttonClass('secondary')}>View all and filter</Link>
                </div>
                {rooms.length === 0 ? (
                    <p className="rounded-lg border border-line bg-white p-8 text-center text-muted">Our suites will be listed here soon.</p>
                ) : (
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{rooms.map((r: any) => <RoomCard key={r.slug} room={r} />)}</div>
                )}
            </section>

            <section className="bg-beige">
                <div className="mx-auto grid max-w-6xl gap-8 px-4 py-14 md:grid-cols-3">
                    <div>
                        <h2 className="mb-2 font-serif text-2xl font-medium">Check-in from 2:00 PM</h2>
                        <p className="text-sm text-muted">Need to arrive earlier? Ask us. Early check-in depends on availability and room readiness.</p>
                    </div>
                    <div>
                        <h2 className="mb-2 font-serif text-2xl font-medium">Clear estimates</h2>
                        <p className="text-sm text-muted">Rates depend on the suite, the date and your party size. We confirm the final price with you before you book.</p>
                    </div>
                    <div>
                        <h2 className="mb-2 font-serif text-2xl font-medium">Book with a person</h2>
                        <p className="text-sm text-muted">Nothing is charged on this site. Message us and our staff will confirm your stay and payment details.</p>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
