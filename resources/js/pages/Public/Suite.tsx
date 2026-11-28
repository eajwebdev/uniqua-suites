import { Link } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { AmenityIcon, BookingPanel, type BookingStatus, Eyebrow, PAGE_WIDTH, PinnedBook, RoomFacts, RoomRate, suiteName, SuitePhoto, TakenDates, useChatLink } from '@/components/public';
import { cn } from '@/components/ui';
import { time12 } from '@/lib/format';
import PublicLayout from '@/layouts/PublicLayout';

function Gallery({ images, name }: { images: { url: string; alt: string }[]; name: string }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [index, setIndex] = useState(0);
    const step = (by: number) => setIndex((index + by + images.length) % images.length);
    const open = (n: number) => {
        setIndex(n);
        dialog.current?.showModal();
    };
    const many = images.length > 1;

    return (
        <>
            <div className={cn('grid gap-2 sm:gap-3', many ? 'grid-cols-3 lg:h-[min(62vh,40rem)] lg:grid-cols-[2fr_1fr] lg:grid-rows-3' : 'mx-auto max-w-3xl')}>
                {images.slice(0, 4).map((image, n) => (
                    <button key={image.url} type="button" onClick={() => open(n)} aria-label={`Enlarge photo: ${image.alt}`} className={cn('group block min-h-0 overflow-hidden rounded-xl', many && n === 0 && 'col-span-3 lg:col-span-1 lg:row-span-3')}>
                        <SuitePhoto image={image} name={name} eager={n === 0} className={cn(!many ? 'aspect-[2/1]' : n === 0 ? 'aspect-[3/2]' : 'aspect-[4/3]', many && 'lg:aspect-auto lg:h-full')} />
                    </button>
                ))}
            </div>

            <dialog
                ref={dialog}
                aria-label={`${name} photos`}
                onClick={(e) => e.target === dialog.current && dialog.current?.close()}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowLeft') step(-1);
                    if (e.key === 'ArrowRight') step(1);
                }}
                className="fixed inset-0 m-0 size-full max-h-none max-w-none items-center justify-center bg-chocolate/95 p-4 text-ivory open:flex"
            >
                <div className="max-w-3xl overflow-hidden">
                    <img src={images[index]?.url} alt={images[index]?.alt} className="max-h-[80dvh] scale-110 object-contain" />
                </div>
                <button type="button" onClick={() => dialog.current?.close()} aria-label="Close photos" className="absolute top-3 right-3 flex size-11 items-center justify-center hover:text-gold"><X size={26} strokeWidth={1.25} /></button>
                {many && (
                    <>
                        <button type="button" onClick={() => step(-1)} aria-label="Previous photo" className="absolute top-1/2 left-2 flex size-11 -translate-y-1/2 items-center justify-center bg-chocolate/60 hover:text-gold"><ChevronLeft size={28} strokeWidth={1.25} /></button>
                        <button type="button" onClick={() => step(1)} aria-label="Next photo" className="absolute top-1/2 right-2 flex size-11 -translate-y-1/2 items-center justify-center bg-chocolate/60 hover:text-gold"><ChevronRight size={28} strokeWidth={1.25} /></button>
                        <p className="absolute bottom-3 left-1/2 -translate-x-1/2 text-sm text-beige" aria-live="polite">{index + 1} of {images.length}</p>
                    </>
                )}
            </dialog>
        </>
    );
}

// Each part of the listing: a thin rule above, a plain heading, then the detail, as booking sites lay out a listing.
const PART = 'border-t border-line pt-6 mt-6';
const PART_HEADING = 'mb-2 text-xl font-semibold tracking-tight';

export default function Suite({ room, policy }: any) {
    const canBook = !!useChatLink() && !room.closed;
    const name = suiteName(room.name);
    const [status, setStatus] = useState<BookingStatus | null>(null);
    const card = useRef<HTMLElement>(null);

    // Below desktop width the booking card sits after the details, so the pinned bar brings it into view.
    const toCard = () => {
        const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        card.current?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
    };

    return (
        <PublicLayout
            title={name}
            description={`${name} at ${room.location}, ${room.type.toLowerCase()} for up to ${room.max_guests} guests. Browse the suite, then book in a Messenger chat with our staff.`}
            bottomBar={canBook ? <PinnedBook room={room} status={status} onChoose={toCard} className="border-t border-line px-5 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-8px_24px_rgb(0_0_0/0.08)]" /> : undefined}
        >
            <div className={cn(PAGE_WIDTH, 'pt-4 pb-10 sm:pt-6 lg:pb-16')}>
                <Link href="/#suites" className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold transition-colors hover:text-bronze pointer-coarse:min-h-11"><ArrowLeft size={16} strokeWidth={1.75} aria-hidden /> All suites</Link>

                {/* Name first, as booking sites do: guests know which suite they are looking at before the photos. */}
                <Eyebrow className="mb-1">{room.location}</Eyebrow>
                <h1 className="mb-4 text-[1.75rem] leading-tight font-semibold tracking-tight sm:mb-5 sm:text-4xl">{name}</h1>

                {room.images.length > 0 ? <Gallery images={room.images} name={name} /> : <SuitePhoto name={room.name} className="aspect-[3/1] rounded-xl" />}

                <div className="grid gap-8 pt-6 lg:grid-cols-3 lg:gap-16 lg:pt-10">
                    <div className="min-w-0 lg:col-span-2">
                        {room.description && <p className="max-w-2xl text-base leading-relaxed sm:text-lg">{room.description}</p>}
                        <RoomFacts room={room} className="mt-4 gap-x-6 text-muted" />

                        <section className={PART}>
                            <h2 className={PART_HEADING}>Dates not available</h2>
                            <p className="mb-3 text-sm text-muted">Already taken, so they cannot be booked. A new guest can arrive on the day another leaves.</p>
                            <TakenDates room={room} />
                        </section>

                        {room.inclusions && (
                            <section className={PART}>
                                <h2 className={PART_HEADING}>Beds and inclusions</h2>
                                <p className="leading-relaxed whitespace-pre-line text-muted">{room.inclusions}</p>
                            </section>
                        )}

                        {room.amenities.length > 0 && (
                            <section className={PART}>
                                <h2 className={cn(PART_HEADING, 'mb-4')}>Amenities</h2>
                                <ul className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                                    {room.amenities.map((a: string) => <li key={a} className="flex items-center gap-3 text-sm"><AmenityIcon name={a} className="shrink-0 text-bronze" /> {a}</li>)}
                                </ul>
                            </section>
                        )}

                        <section className={PART}>
                            <h2 className={PART_HEADING}>Check-in</h2>
                            <ul className="space-y-1 leading-relaxed text-muted">
                                <li>Standard check-in: {time12(policy.standard_check_in)}{policy.standard_check_out ? ` · Check-out: ${time12(policy.standard_check_out)}` : ''}</li>
                                <li>Need to arrive earlier? Please ask us. It depends on availability and room readiness.</li>
                            </ul>
                            {room.policies && <p className="mt-3 text-sm whitespace-pre-line text-muted">{room.policies}</p>}
                        </section>
                    </div>

                    <aside ref={card} id="book" className="min-w-0 scroll-mt-20 lg:sticky lg:top-20 lg:self-start">
                        <div className="rounded-xl border border-line bg-white p-5 shadow-[0_6px_16px_rgb(0_0_0/0.08)]">
                            <RoomRate room={room} className="mb-4 border-b border-line pb-4" />
                            <BookingPanel room={room} onStatus={setStatus} pinnedButton={canBook ? 'lg' : undefined} />
                        </div>
                    </aside>
                </div>
            </div>
        </PublicLayout>
    );
}
