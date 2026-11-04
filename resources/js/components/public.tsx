import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, BriefcaseMedical, CalendarCheck, CalendarX, Check, ChevronLeft, ChevronRight, CookingPot, Dices, Dumbbell, Loader2, MessageCircle, Shirt, Tv, Waves, Wifi, Wind } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { cn, Input, Modal } from '@/components/ui';
import { shortDate } from '@/lib/format';

export const PAGE_WIDTH = 'mx-auto w-full max-w-7xl px-5 sm:px-8';

const AMENITY_ICONS: Record<string, any> = {
    'Wi-Fi': Wifi,
    Netflix: Tv,
    'Board games': Dices,
    'Hair dryer': Wind,
    Iron: Shirt,
    'First aid kit': BriefcaseMedical,
    'Cooking facilities': CookingPot,
    'Swimming pool': Waves,
    Gym: Dumbbell,
};

export function AmenityIcon({ name, size = 22, className }: { name: string; size?: number; className?: string }) {
    const Icon = AMENITY_ICONS[name] ?? Check;

    return <Icon size={size} strokeWidth={1.25} className={className} aria-hidden />;
}

export function Eyebrow({ children, light, className }: { children: React.ReactNode; light?: boolean; className?: string }) {
    return <p className={cn('text-xs font-medium tracking-[0.3em] uppercase', light ? 'text-gold' : 'text-bronze', className)}>{children}</p>;
}

/** Guests cannot book on this site: booking happens in a Messenger chat with staff. Null until the owner supplies the link. */
export function useChatLink(): string | null {
    const { contact } = usePage<any>().props as any;

    return contact.messenger_url || contact.facebook_url || null;
}

/** Only m.me links can carry a first message, and the guest can still edit it before sending. */
function chatAboutDates(url: string, room: string, checkIn: string, checkOut: string): string {
    if (!/^https:\/\/(www\.)?m\.me\//.test(url)) return url;
    const text = `Hi! I would like to book ${room}, check-in ${shortDate(checkIn)}, check-out ${shortDate(checkOut)}. Number of guests: `;

    return `${url}${url.includes('?') ? '&' : '?'}text=${encodeURIComponent(text)}`;
}

const BUTTON = 'inline-flex items-center justify-center gap-2.5 text-[0.8125rem] font-medium tracking-[0.18em] whitespace-nowrap uppercase transition-colors duration-200';

const BUTTON_TONES = {
    gold: 'bg-gold text-chocolate hover:bg-ivory active:bg-ivory',
    dark: 'bg-chocolate text-ivory hover:bg-espresso active:bg-espresso',
    outline: 'border border-ivory/60 text-ivory hover:border-gold hover:bg-gold hover:text-chocolate active:bg-gold active:text-chocolate',
    line: 'border border-chocolate/40 text-chocolate hover:bg-chocolate hover:text-ivory active:bg-chocolate active:text-ivory',
};

export function linkButton(tone: keyof typeof BUTTON_TONES = 'gold', small?: boolean) {
    return cn(BUTTON, BUTTON_TONES[tone], small ? 'min-h-10 px-4 pointer-coarse:min-h-11' : 'min-h-12 px-7');
}

/** With no suite chosen yet, Book Now leads to the taken dates. Only a suite's own panel opens the chat, and only with free dates. */
export function MessageButton({ label = 'Book Now', tone = 'gold', small, hideIfMissing, className }: { label?: string; tone?: keyof typeof BUTTON_TONES; small?: boolean; hideIfMissing?: boolean; className?: string }) {
    const url = useChatLink();

    if (!url) {
        return hideIfMissing ? null : <p className={cn('text-sm opacity-80', className)}>Our official Facebook Messenger link will be published here soon.</p>;
    }

    return (
        <a href="/#availability" aria-label="Book now: choose a suite and dates" className={cn(linkButton(tone, small), className)}>
            <MessageCircle size={small ? 15 : 17} strokeWidth={1.5} aria-hidden /> {label}
        </a>
    );
}

const addDays = (date: string, days: number) => new Date(Date.parse(`${date}T00:00:00Z`) + days * 86400000).toISOString().slice(0, 10);

export const todayInManila = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());

export function takenNights(room: any): Set<string> {
    const nights = new Set<string>();
    (room.unavailable ?? []).forEach((r: any) => {
        for (let night = r.from; night < r.to; night = addDays(night, 1)) nights.add(night);
    });

    return nights;
}

/** True when every night from check-in up to, but not including, check-out is free. */
export function datesAreFree(room: any, checkIn: string, checkOut: string): boolean {
    if (room.closed || checkOut <= checkIn) return false;
    const taken = takenNights(room);
    for (let night = checkIn; night < checkOut; night = addDays(night, 1)) {
        if (taken.has(night)) return false;
    }

    return true;
}

export function useStayDates() {
    const [checkIn, setIn] = useState('');
    const [checkOut, setCheckOut] = useState('');

    return {
        checkIn,
        checkOut,
        setCheckOut,
        setCheckIn: (value: string) => {
            setIn(value);
            if (checkOut && value >= checkOut) setCheckOut('');
        },
        clear: () => {
            setIn('');
            setCheckOut('');
        },
        chosen: !!checkIn && checkOut > checkIn,
    };
}

/** The guest's dates. Whatever lists suites beside it filters itself as soon as both are chosen. */
export function DateFilter({ stay, className }: { stay: ReturnType<typeof useStayDates>; className?: string }) {
    const today = todayInManila();
    const label = 'flex flex-col gap-1.5 text-xs font-medium tracking-[0.14em] text-muted uppercase';
    const input = 'w-44 font-normal tracking-normal normal-case';

    return (
        <div className={cn('flex flex-wrap items-end gap-x-4 gap-y-3', className)}>
            <label className={label}>Your check-in
                <Input type="date" min={today} className={input} value={stay.checkIn} onChange={(e) => stay.setCheckIn(e.target.value)} />
            </label>
            <label className={label}>Your check-out
                <Input type="date" min={stay.checkIn ? addDays(stay.checkIn, 1) : today} className={input} value={stay.checkOut} onChange={(e) => stay.setCheckOut(e.target.value)} />
            </label>
            {(stay.checkIn || stay.checkOut) && <button type="button" onClick={stay.clear} className="min-h-11 text-sm font-medium text-bronze hover:underline">Clear dates</button>}
        </div>
    );
}

/** Each range as the nights actually taken, so the last date shown is the last one nobody can have. */
export function TakenDates({ room, limit }: { room: any; limit?: number }) {
    const ranges = room.unavailable ?? [];
    const shown = limit ? ranges.slice(0, limit) : ranges;

    if (room.closed) return <p className="text-sm text-muted">Not available right now.</p>;
    if (ranges.length === 0) return <p className="text-sm text-muted">No dates taken yet.</p>;

    return (
        <ul aria-label={`Dates not available for ${room.name}`} className="flex flex-wrap gap-1.5">
            {shown.map((r: any) => {
                const last = addDays(r.to, -1);

                return <li key={r.from} className="rounded-sm border border-line bg-white px-2 py-0.5 text-xs whitespace-nowrap text-espresso">{shortDate(r.from)}{last !== r.from && ` to ${shortDate(last)}`}</li>;
            })}
            {limit && ranges.length > limit && <li className="px-1 py-0.5 text-xs text-muted">and {ranges.length - limit} more</li>}
        </ul>
    );
}

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

function monthDays(month: string): (string | null)[] {
    const [y, m] = month.split('-').map(Number);
    const blanks = new Date(Date.UTC(y, m - 1, 1)).getUTCDay();
    const count = new Date(Date.UTC(y, m, 0)).getUTCDate();

    return [...Array(blanks).fill(null), ...Array.from({ length: count }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`)];
}

export function shiftMonth(month: string, by: number): string {
    const [y, m] = month.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1 + by, 1)).toISOString().slice(0, 7);
}

export const monthLabel = (month: string) => new Date(`${month}-01T00:00:00Z`).toLocaleDateString('en-PH', { month: 'long', year: 'numeric', timeZone: 'UTC' });

/** One suite's month at a glance, for reading only: booking happens in the panel, with its live check. */
export function MonthGrid({ room, month, checkIn, checkOut }: { room: any; month: string; checkIn?: string; checkOut?: string }) {
    const today = todayInManila();
    const taken = useMemo(() => takenNights(room), [room]);

    return (
        <div role="group" aria-label={`${room.name}, ${monthLabel(month)}`}>
            <div className="grid grid-cols-7 text-center text-[11px] text-muted" aria-hidden>{WEEKDAYS.map((d) => <span key={d} className="py-1">{d}</span>)}</div>
            <div className="grid grid-cols-7 gap-y-0.5 text-center text-sm tabular-nums">
                {monthDays(month).map((date, i) => {
                    if (!date) return <span key={`blank-${i}`} />;
                    const past = date < today;
                    const isTaken = room.closed || taken.has(date);
                    const chosen = !!checkIn && !!checkOut && date >= checkIn && date <= checkOut;

                    return (
                        <span key={date} className={cn('py-1.5', past ? 'text-muted/40' : isTaken ? 'bg-beige text-muted line-through' : chosen ? 'bg-gold/40 font-medium' : '', date === today && 'font-semibold')}>
                            {Number(date.slice(8))}
                            {!past && isTaken && <span className="sr-only"> not available</span>}
                        </span>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Three checks stand between a guest and the chat: taken dates cannot be picked here, the server
 * re-reads live inventory before Book Now unlocks, and staff are still refused any overlap when they enter it.
 */
export function BookingPanel({ room, initialIn = '', initialOut = '' }: { room: any; initialIn?: string; initialOut?: string }) {
    const today = todayInManila();
    const chat = useChatLink();
    const taken = useMemo(() => takenNights(room), [room]);
    const [checkIn, setCheckIn] = useState(initialIn);
    const [checkOut, setCheckOut] = useState(initialOut);
    const [month, setMonth] = useState((initialIn || today).slice(0, 7));
    const [result, setResult] = useState<{ state: 'idle' | 'checking' | 'free' | 'taken'; message?: string }>({ state: 'idle' });

    // While choosing check-out, the first taken night is the last possible date: leaving that morning is fine.
    const limit = useMemo(() => {
        if (!checkIn || checkOut) return null;
        const nights = [...taken].filter((n) => n > checkIn).sort();

        return nights[0] ?? null;
    }, [checkIn, checkOut, taken]);

    useEffect(() => {
        if (!checkIn || !checkOut) {
            setResult({ state: 'idle' });
            return;
        }
        const request = new AbortController();
        setResult({ state: 'checking' });
        fetch(`/suites/${room.slug}/check-dates?check_in=${checkIn}&check_out=${checkOut}`, { headers: { Accept: 'application/json' }, signal: request.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('check failed'))))
            .then((body) => setResult(body.available ? { state: 'free' } : { state: 'taken', message: body.message }))
            .catch((error) => {
                // If the check cannot run, Book Now stays locked rather than trusting the page.
                if (error.name !== 'AbortError') setResult({ state: 'taken', message: 'We could not check those dates. Please try again.' });
            });

        return () => request.abort();
    }, [checkIn, checkOut, room.slug]);

    const pick = (date: string) => {
        if (!checkIn || checkOut || date <= checkIn) {
            setCheckIn(date);
            setCheckOut('');
        } else {
            setCheckOut(date);
        }
    };

    if (room.closed) return <p className="text-sm text-muted">{room.name} is not available right now. Please look at our other suites.</p>;

    const choosingOut = !!checkIn && !checkOut;

    return (
        <div>
            <div className="mb-2 flex items-center justify-between">
                <button type="button" aria-label="Previous month" disabled={month <= today.slice(0, 7)} onClick={() => setMonth(shiftMonth(month, -1))} className="flex size-11 items-center justify-center disabled:opacity-30"><ChevronLeft size={18} strokeWidth={1.5} /></button>
                <p className="text-sm font-medium" aria-live="polite">{monthLabel(month)}</p>
                <button type="button" aria-label="Next month" onClick={() => setMonth(shiftMonth(month, 1))} className="flex size-11 items-center justify-center"><ChevronRight size={18} strokeWidth={1.5} /></button>
            </div>
            <div className="grid grid-cols-7 text-center text-xs text-muted" aria-hidden>{WEEKDAYS.map((d) => <span key={d} className="py-1">{d}</span>)}</div>
            <div className="grid grid-cols-7 gap-y-0.5">
                {monthDays(month).map((date, i) => {
                    if (!date) return <span key={`blank-${i}`} />;
                    const isTaken = taken.has(date);
                    const past = date < today;
                    const canLeave = choosingOut && date > checkIn && (!limit || date <= limit);
                    const disabled = past || (choosingOut ? date > checkIn && !canLeave : false) || (isTaken && !canLeave);
                    const chosen = date === checkIn || date === checkOut;
                    const between = !!checkIn && !!checkOut && date > checkIn && date < checkOut;
                    const status = past ? 'past' : isTaken && !canLeave ? 'not available' : 'available';

                    return (
                        <button key={date} type="button" disabled={disabled} aria-pressed={chosen} aria-label={`${shortDate(date)}, ${status}`} onClick={() => pick(date)}
                            className={cn('h-11 text-sm tabular-nums transition-colors', chosen ? 'bg-chocolate font-semibold text-ivory' : between ? 'bg-gold/30' : isTaken && !canLeave ? 'cursor-not-allowed bg-beige text-muted line-through' : past ? 'cursor-not-allowed text-muted/40' : disabled ? 'cursor-not-allowed text-muted/40' : 'hover:bg-gold/30')}>
                            {Number(date.slice(8))}
                        </button>
                    );
                })}
            </div>
            <p className="mt-2 text-xs text-muted">{choosingOut ? 'Now choose your check-out date.' : checkIn && checkOut ? `${shortDate(checkIn)} to ${shortDate(checkOut)}` : 'Choose your check-in date. Crossed-out dates are already taken.'}</p>

            <div className="mt-4" role="status">
                {result.state === 'checking' && <p className="flex items-center gap-2 text-sm text-muted"><Loader2 size={15} className="animate-spin" aria-hidden /> Checking those dates…</p>}
                {result.state === 'free' && <p className="flex items-center gap-2 text-sm font-medium text-emerald-900"><CalendarCheck size={16} aria-hidden /> Those dates are free right now.</p>}
                {result.state === 'taken' && <p className="flex items-start gap-2 text-sm font-medium text-red-900"><CalendarX size={16} className="mt-0.5 shrink-0" aria-hidden /> {result.message}</p>}
            </div>

            {result.state === 'free' && chat ? (
                <a href={chatAboutDates(chat, room.name, checkIn, checkOut)} target="_blank" rel="noreferrer" aria-label={`Book ${room.name} now on Messenger`} className={cn(linkButton('dark'), 'mt-3 w-full')}>
                    <MessageCircle size={17} strokeWidth={1.5} aria-hidden /> Book Now
                </a>
            ) : (
                <button type="button" disabled className={cn(linkButton('dark'), 'mt-3 w-full cursor-not-allowed opacity-40')}><MessageCircle size={17} strokeWidth={1.5} aria-hidden /> Book Now</button>
            )}
            <p className="mt-3 text-xs leading-relaxed text-muted">Book Now opens a Messenger chat with us. Your dates are reserved only when our staff confirm them there. No payment happens on this website.</p>
        </div>
    );
}

export function BookNow({ room, initialIn, initialOut, small = true, tone = 'dark', className }: { room: any; initialIn?: string; initialOut?: string; small?: boolean; tone?: keyof typeof BUTTON_TONES; className?: string }) {
    const [open, setOpen] = useState(false);

    if (!useChatLink() || room.closed) return null;

    return (
        <>
            <button type="button" aria-label={`Book ${room.name} now: choose your dates`} onClick={() => setOpen(true)} className={cn(linkButton(tone, small), className)}>
                <MessageCircle size={small ? 15 : 17} strokeWidth={1.5} aria-hidden /> Book Now
            </button>
            {open && <Modal open title={`Book ${room.name}`} onClose={() => setOpen(false)}><BookingPanel room={room} initialIn={initialIn} initialOut={initialOut} /></Modal>}
        </>
    );
}

export function roomFacts(room: any): string[] {
    return [
        `Up to ${room.max_guests} guests`,
        room.bedrooms === 0 ? 'Studio' : `${room.bedrooms} bedroom${room.bedrooms > 1 ? 's' : ''}`,
        `${room.bathrooms} bath${room.bathrooms > 1 ? 's' : ''}`,
        `${room.ac_count} AC`,
        ...(room.has_balcony ? ['Balcony'] : []),
    ];
}

/** The largest crop that hides the white rounded corners baked into the supplied photos. */
export function SuitePhoto({ image, name, className, eager }: { image?: { url: string; alt: string }; name: string; className?: string; eager?: boolean }) {
    if (!image) {
        return (
            <div className={cn('flex items-center justify-center bg-chocolate', className)}>
                <span className="font-serif text-3xl font-light tracking-[0.2em] text-ivory uppercase">{name}</span>
            </div>
        );
    }

    return (
        <div className={cn('overflow-hidden bg-espresso', className)}>
            <img src={image.url} alt={image.alt} loading={eager ? 'eager' : 'lazy'} className="size-full scale-110 object-cover transition-transform duration-700 ease-out group-hover:scale-[1.15]" />
        </div>
    );
}

export function RoomCard({ room, feature, checkIn, checkOut }: { room: any; feature?: boolean; checkIn?: string; checkOut?: string }) {
    const href = `/suites/${room.slug}`;

    return (
        <article className={cn('group flex flex-col', feature && 'lg:grid lg:grid-cols-12 lg:items-center lg:gap-14')}>
            <Link href={href} className={cn('block', feature && 'lg:col-span-8')} tabIndex={-1} aria-hidden>
                <SuitePhoto image={room.images?.[0]} name={room.name} className={feature ? 'aspect-[3/2] lg:aspect-[16/9]' : 'aspect-[3/2]'} />
            </Link>
            <div className={cn('flex flex-1 flex-col pt-5', feature && 'lg:col-span-4 lg:pt-0')}>
                <Eyebrow className="mb-2 tracking-[0.22em]">{room.location}</Eyebrow>
                <h3 className={cn('font-serif font-normal tracking-[0.08em] uppercase', feature ? 'text-4xl sm:text-5xl' : 'text-3xl')}>
                    <Link href={href} className="inline-block transition-colors hover:text-bronze pointer-coarse:py-1">{room.name}</Link>
                </h3>
                {room.description && <p className={cn('mt-2 text-muted', feature ? 'text-base leading-relaxed' : 'text-sm leading-relaxed')}>{room.description}</p>}
                <p className="mt-2 text-sm text-muted">{roomFacts(room).join(' · ')}</p>
                <div className="mt-auto flex flex-wrap items-center gap-x-6 gap-y-2 pt-5">
                    <BookNow key={`${checkIn}-${checkOut}`} room={room} initialIn={checkIn} initialOut={checkOut} />
                    <Link href={href} className="inline-flex items-center gap-1.5 text-[0.8125rem] font-medium tracking-[0.14em] uppercase transition-colors hover:text-bronze pointer-coarse:min-h-11" aria-label={`View ${room.name}`}>
                        View suite <ArrowRight size={14} strokeWidth={1.5} aria-hidden />
                    </Link>
                </div>
            </div>
        </article>
    );
}
