import { Head } from '@inertiajs/react';
import {
    ArrowRight, BadgeCheck, CalendarCheck, Camera, ChevronLeft, ChevronRight, Clock, HandCoins, IdCard, Loader2, Lock, MessageCircle, PackageCheck, Plane,
    ShieldAlert, ShieldCheck, Sparkles, TimerReset, X,
} from 'lucide-react';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { datesAreFree, monthLabel, shiftMonth, takenNights, todayInManila } from '@/components/public';
import { cn } from '@/components/ui';
import { pesoWhole, shortDate } from '@/lib/format';

/*
 * Grab Cam Rental's own page, made to sit in an iframe on Grab Cam's site. Renters see every camera,
 * the rate card, the days a camera is fully booked, the terms, and message Grab Cam on Facebook.
 * Staff confirm and assign the exact unit. Nothing is paid here.
 * Look: the Grab Cam navy brand of the rate card and social posts (Montserrat / Poppins, tilted
 * photo frames, soft light streaks, the logo's arcs in the background).
 */

type Rate = { label: string; min_days: number; max_days: number | null; daily_cents: number };
type Model = { free_day_from: number; name: string; slug: string; brand: string | null; specs: string | null; description: string | null; units: number; closed: boolean; images: { url: string; alt: string; default?: boolean }[]; rates: Rate[]; unavailable: { from: string; to: string }[] };
type Check = { state: 'idle' | 'checking' | 'free' | 'taken'; message?: string; days?: number; estimate_cents?: number; daily_cents?: number; free_day?: boolean };

const WIDTH = 'mx-auto w-full max-w-6xl px-4 sm:px-6';
const DISPLAY = "font-['Montserrat',ui-sans-serif,system-ui,sans-serif]";
const BTN = 'inline-flex min-h-12 items-center justify-center gap-2 rounded-full px-7 text-sm font-semibold transition duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';
const BTN_LIGHT = cn(BTN, 'bg-white text-[#0c1f3d] shadow-[0_10px_30px_-10px_rgba(255,255,255,0.6)] hover:-translate-y-0.5 hover:bg-[#f4ecdc]');
const BTN_GHOST = cn(BTN, 'border border-white/25 text-white backdrop-blur hover:border-white/60 hover:bg-white/10');
const BTN_NAVY = cn(BTN, 'bg-[#0c1f3d] text-white hover:bg-[#17345f]');
// The rate card's three dots: cream for 1–2 days, slate blue for 3–5, grey for 6 or more.
const TIER_DOT = ['bg-[#f3e6c9]', 'bg-[#8fa3c2]', 'bg-[#8b8f98]'];

const STEPS: [string, string, any][] = [
    ['Choose your camera and days', 'Crossed-out days are fully booked. The longer you rent, the lower the daily rate.', Camera],
    ['Message us on Facebook', 'Inquire opens our Facebook page. Tell us the camera and days you picked, and we confirm the time.', MessageCircle],
    ['Pick up with a valid ID', 'No security deposit. Pick up and return within store hours, 8:00 AM to 6:00 PM daily.', IdCard],
];

// From the Grab Cam "Terms & Condition" card, word for word.
const TERMS: { title: string; icon: any; lead?: string; items?: string[]; groups?: { lead: string; items: string[] }[] }[] = [
    { title: 'Rental period', icon: CalendarCheck, items: ['Rental is strictly counted as 24 hours. Example: Pick-up 9 AM → Return 9 AM the next day.', 'Please follow your confirmed time as another renter may be scheduled right after your booking.'] },
    { title: 'Pick-up & delivery', icon: PackageCheck, groups: [
        { lead: 'If another person will pick up on your behalf, please send us in advance:', items: ['Full name of the authorized person', 'Photo/screenshot of their valid ID'] },
        { lead: 'For courier pick-up or return:', items: ['Angkas Padala or Grab only', 'Send a screenshot showing the rider’s name and booking details before release.'] },
    ] },
    { title: 'No security deposit', icon: HandCoins, items: ['No security deposit required.', 'Any damage, loss, missing items, or delays will be the renter’s responsibility.'] },
    { title: 'Security', icon: Lock, lead: 'For security, we will not release the camera to an unverified person or rider.' },
    { title: 'Store hours & return', icon: Clock, items: ['8:00 AM – 6:00 PM daily.', 'Pick-up and return are strictly within store hours.', 'Please return the camera on or before your confirmed return time.', 'Kindly allow extra time for traffic or unexpected delays.'] },
    { title: 'Domestic & international travel', icon: Plane, items: ['Plan your pick-up & return schedule carefully when traveling.', 'Allow extra time for flight delays, traffic, transportation delays, trip extensions, or other unexpected travel issues.'] },
    { title: 'Late return', icon: TimerReset, items: ['Late fee: ₱100 per hour.', 'If you need to extend, please inform us as early as possible.', 'If your delay affects the next booking, additional charges may apply as we may need to compensate the affected renter.'] },
    { title: 'Damage, loss & replacement', icon: ShieldAlert, items: ['Handle all equipment with care.', 'Any lost, stolen, damaged, or missing camera/accessory will be charged based on the applicable repair or replacement cost.'] },
];

// Colour stand-ins for the frames when no camera has a photo yet.
const SCENES = [
    'linear-gradient(160deg,#f7b267 0%,#f25c54 45%,#3d2c5e 100%)',
    'linear-gradient(200deg,#1b2a4a 0%,#3b4f7d 40%,#f2a65a 100%)',
    'linear-gradient(170deg,#9ad1d4 0%,#3b8ea5 50%,#0b3954 100%)',
    'linear-gradient(150deg,#ffd6a5 0%,#ff9b85 50%,#6d597a 100%)',
    'radial-gradient(circle at 30% 30%,#ffe066 0%,#f08a4b 35%,#1d1e3a 100%)',
    'linear-gradient(180deg,#a2d2ff 0%,#bde0fe 40%,#2b6777 100%)',
];

const addDays = (date: string, days: number) => new Date(Date.parse(`${date}T00:00:00Z`) + days * 86400000).toISOString().slice(0, 10);
const daysBetween = (from: string, to: string) => Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000);

function monthCells(month: string): (string | null)[] {
    const [y, m] = month.split('-').map(Number);
    const blanks = new Date(Date.UTC(y, m - 1, 1)).getUTCDay();
    const count = new Date(Date.UTC(y, m, 0)).getUTCDate();

    return [...Array(blanks).fill(null), ...Array.from({ length: count }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`)];
}

const WEEK = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

/** Fades a section in once it scrolls into view. Without motion preferences or IntersectionObserver it is simply shown. */
function Reveal({ children, className, delay = 0 }: { children: ReactNode; className?: string; delay?: number }) {
    const ref = useRef<HTMLDivElement>(null);
    const [shown, setShown] = useState(false);
    useEffect(() => {
        const el = ref.current;
        if (!el || !('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return setShown(true);
        const io = new IntersectionObserver(([e]) => e.isIntersecting && (setShown(true), io.disconnect()), { rootMargin: '0px 0px -10% 0px' });
        io.observe(el);

        return () => io.disconnect();
    }, []);

    return <div ref={ref} style={{ transitionDelay: `${delay}ms` }} className={cn('transition duration-700 ease-out', shown ? 'translate-y-0 opacity-100' : 'translate-y-6 opacity-0', className)}>{children}</div>;
}

/** The logo's camera-"G" arcs, huge and faint, as in the brand posts. */
function BrandArcs({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 800 800" aria-hidden className={cn('pointer-events-none absolute', className)} fill="none">
            <path d="M560 210A270 270 0 1 0 610 470" stroke="currentColor" strokeWidth="110" strokeLinecap="round" />
            <path d="M470 330A120 120 0 1 0 490 430" stroke="currentColor" strokeWidth="70" strokeLinecap="round" />
        </svg>
    );
}

function Paperclip({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 40 120" aria-hidden className={className} fill="none">
            <path d="M28 18v70a10 10 0 0 1-20 0V22a14 14 0 0 1 28 0v62" stroke="url(#clip)" strokeWidth="4" strokeLinecap="round" />
            <defs><linearGradient id="clip" x1="0" x2="40" y1="0" y2="120" gradientUnits="userSpaceOnUse"><stop stopColor="#f5f7fa" /><stop offset=".5" stopColor="#9aa4b2" /><stop offset="1" stopColor="#e3e7ec" /></linearGradient></defs>
        </svg>
    );
}

/** A cream print, tilted like the ones on Grab Cam's posts: a 2×2 collage of camera photos, or of stand-in scenes until there are some. */
function Print({ shots, offset, className, caption }: { shots: string[]; offset: number; className?: string; caption?: string }) {
    return (
        <figure className={cn('rounded-[4px] bg-[#f4ecdc] p-2 shadow-[0_30px_60px_-20px_rgba(0,0,0,0.65)] transition duration-500 sm:p-2.5', className)}>
            <div className="grid aspect-[4/5] grid-cols-2 gap-1">
                {[0, 1, 2, 3].map((i) => {
                    const shot = shots.length ? shots[(offset + i) % shots.length] : null;

                    return shot
                        ? <span key={i} className="flex size-full items-center justify-center bg-[radial-gradient(circle_at_50%_40%,#1d3a66_0%,#0b2443_75%)]"><img src={shot} alt="" loading="lazy" className="max-h-full max-w-full object-contain [mask-image:radial-gradient(ellipse_at_center,black_55%,transparent_78%)]" /></span>
                        : <span key={i} className="relative block size-full overflow-hidden" style={{ background: SCENES[(offset + i) % SCENES.length] }}><span className="absolute inset-0 bg-[radial-gradient(circle_at_70%_20%,rgba(255,255,255,0.35),transparent_45%)]" /></span>;
                })}
            </div>
            {caption && <figcaption className="pt-1.5 text-center text-[10px] font-semibold tracking-[0.25em] text-[#0c1f3d]/70 uppercase">{caption}</figcaption>}
        </figure>
    );
}

/** The "grabcam RENTAL" lettering of the logo, readable on the navy page. */
function Wordmark() {
    return (
        <span className={cn(DISPLAY, 'leading-none text-white')}>
            <span className="text-lg tracking-tight sm:text-xl"><span className="font-normal">grab</span><span className="font-extrabold">cam</span></span>
            <span className="mt-1 block text-[9px] font-medium tracking-[0.55em] text-white/80">RENTAL</span>
        </span>
    );
}

/** A Messenger link for the Grab Cam page. A Facebook profile link (profile.php?id=N) becomes m.me/N, the same page in Messenger. */
function messengerUrl(url: string): string {
    const id = url.match(/^https:\/\/(?:www\.|m\.)?facebook\.com\/profile\.php\?id=(\d+)/)?.[1];

    return id ? `https://m.me/${id}` : url;
}

/** Like the suites: Messenger opens with the camera and days already written, and the renter can still edit it before sending. */
function chatLink(raw: string, model: string, from: string, to: string, days?: number, estimate?: number): string {
    const url = messengerUrl(raw);
    if (!/^https:\/\/(www\.)?m\.me\//.test(url)) return url;
    const text = `Hi Grab Cam! I would like to rent the ${model}, pickup ${shortDate(from)}, return ${shortDate(to)}${days ? ` (${days} day${days > 1 ? 's' : ''})` : ''}.${estimate ? ` Estimated ${pesoWhole(estimate)}.` : ''} Is it available?`;

    return `${url}${url.includes('?') ? '&' : '?'}text=${encodeURIComponent(text)}`;
}

function RateDots({ rates, freeFrom }: { rates: Rate[]; freeFrom: number }) {
    return (
        <div>
            <ul className="space-y-1.5">
                {rates.map((r, i) => (
                    <li key={r.min_days} className="flex items-center gap-2.5 text-sm">
                        <span className={cn('size-3.5 shrink-0 rounded-full ring-2 ring-[#0c1f3d]/5', TIER_DOT[i] ?? TIER_DOT[2])} aria-hidden />
                        <span className="flex-1 text-slate-500">{r.label}</span>
                        <span className="font-bold text-[#0c1f3d] tabular-nums">{pesoWhole(r.daily_cents)}<span className="ml-0.5 text-[10px] font-semibold text-slate-400">/day</span></span>
                    </li>
                ))}
            </ul>
            {freeFrom > 0 && <p className="mt-2.5 inline-flex items-center gap-1.5 rounded-full bg-[#f3e6c9]/60 px-2.5 py-1 text-[11px] font-semibold text-[#7a5a1c]"><Sparkles size={12} aria-hidden /> {freeFrom}+ days: 1 day free</p>}
        </div>
    );
}

/** One month of a camera's fully booked days, for reading. */
function MiniMonth({ model, month, from, to }: { model: Model; month: string; from?: string; to?: string }) {
    const today = todayInManila();
    const taken = useMemo(() => takenNights(model), [model]);

    return (
        <div role="group" aria-label={`${model.name}, ${monthLabel(month)}`}>
            <div className="grid grid-cols-7 text-center text-[10px] font-semibold tracking-wide text-slate-400 uppercase" aria-hidden>{WEEK.map((d) => <span key={d} className="py-1">{d}</span>)}</div>
            <div className="grid grid-cols-7 gap-0.5 text-center text-xs tabular-nums">
                {monthCells(month).map((date, i) => {
                    if (!date) return <span key={`b${i}`} />;
                    const past = date < today;
                    const isTaken = model.closed || taken.has(date);
                    const chosen = !!from && !!to && date >= from && date < to;

                    return (
                        <span key={date} className={cn('rounded-md py-1.5', past ? 'text-slate-300' : isTaken ? 'bg-slate-100 text-slate-400 line-through' : chosen ? 'bg-[#0c1f3d] font-semibold text-white' : 'text-[#0c1f3d]', date === today && !chosen && 'ring-1 ring-[#0c1f3d]/30')}>
                            {Number(date.slice(8))}
                            {!past && isTaken && <span className="sr-only"> fully booked</span>}
                        </span>
                    );
                })}
            </div>
        </div>
    );
}

/** Pick days, then the server re-reads live bookings and prices those exact days before Inquire unlocks. */
function InquirePanel({ model, chat, initialFrom = '', initialTo = '' }: { model: Model; chat: string | null; initialFrom?: string; initialTo?: string }) {
    const today = todayInManila();
    const taken = useMemo(() => takenNights(model), [model]);
    // Days already chosen on the page carry over, so the renter does not pick them twice.
    const [from, setFrom] = useState(initialFrom);
    const [to, setTo] = useState(initialTo);
    const [month, setMonth] = useState((initialFrom || today).slice(0, 7));
    const [check, setCheck] = useState<Check>({ state: 'idle' });
    const days = from && to ? daysBetween(from, to) : 0;

    useEffect(() => {
        if (!from || !to) return setCheck({ state: 'idle' });
        const request = new AbortController();
        setCheck({ state: 'checking' });
        fetch(`/grabcam/${model.slug}/check-dates?check_in=${from}&check_out=${to}`, { headers: { Accept: 'application/json' }, signal: request.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('check failed'))))
            .then((body) => setCheck(body.available ? { state: 'free', days: body.days, estimate_cents: body.estimate_cents, daily_cents: body.daily_cents, free_day: body.free_day } : { state: 'taken', message: body.message }))
            .catch((error) => error.name !== 'AbortError' && setCheck({ state: 'taken', message: 'We could not check those days. Please try again.' }));

        return () => request.abort();
    }, [from, to, model.slug]);

    const pick = (date: string) => {
        if (!from || to || date <= from) {
            setFrom(date);
            setTo('');
        } else {
            setTo(date);
        }
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-slate-500">{from && to ? 'Your days are below. Tap a new pickup day to change them.' : from ? 'Now tap your return day.' : 'Tap your pickup day, then your return day. A rental day is 24 hours.'}</p>
            <div>
                <div className="mb-1 flex items-center justify-between">
                    <button type="button" aria-label="Previous month" disabled={month <= today.slice(0, 7)} onClick={() => setMonth(shiftMonth(month, -1))} className="flex size-11 items-center justify-center rounded-full hover:bg-slate-100 disabled:opacity-30"><ChevronLeft size={18} /></button>
                    <p className={cn(DISPLAY, 'text-sm font-bold text-[#0c1f3d]')} aria-live="polite">{monthLabel(month)}</p>
                    <button type="button" aria-label="Next month" onClick={() => setMonth(shiftMonth(month, 1))} className="flex size-11 items-center justify-center rounded-full hover:bg-slate-100"><ChevronRight size={18} /></button>
                </div>
                <div className="grid grid-cols-7 text-center text-[10px] font-semibold tracking-wide text-slate-400 uppercase" aria-hidden>{WEEK.map((d) => <span key={d} className="py-1">{d}</span>)}</div>
                <div className="grid grid-cols-7 gap-1">
                    {monthCells(month).map((date, i) => {
                        if (!date) return <span key={`b${i}`} />;
                        const past = date < today;
                        const isTaken = taken.has(date);
                        // A return day may be another renter's pickup day; a pickup day may not be taken.
                        const blocked = past || (isTaken && !(from && !to && date > from));
                        const inRange = !!from && date >= from && date <= (to || from);

                        return (
                            <button key={date} type="button" disabled={blocked} onClick={() => pick(date)} aria-pressed={inRange}
                                className={cn('min-h-10 rounded-lg text-sm tabular-nums transition', blocked ? 'text-slate-300 line-through' : 'text-[#0c1f3d] hover:bg-slate-100', inRange && 'bg-[#0c1f3d] font-semibold text-white hover:bg-[#0c1f3d]')}>
                                {Number(date.slice(8))}
                                {isTaken && !past && <span className="sr-only"> fully booked</span>}
                            </button>
                        );
                    })}
                </div>
            </div>

            {from && to && (
                <div className="rounded-2xl bg-slate-50 p-4 text-sm ring-1 ring-slate-200">
                    <p className="font-semibold text-[#0c1f3d]">Pickup {shortDate(from)} · Return {shortDate(to)} · {days} day{days === 1 ? '' : 's'}</p>
                    <p role="status" className="mt-2 flex items-center gap-1.5 font-medium">
                        {check.state === 'checking' && <><Loader2 size={15} className="animate-spin" aria-hidden /> Checking…</>}
                        {check.state === 'free' && <span className="flex items-center gap-1.5 text-emerald-700"><BadgeCheck size={16} aria-hidden /> Available</span>}
                        {check.state === 'taken' && <span className="text-red-700">{check.message}</span>}
                    </p>
                    {check.state === 'free' && check.estimate_cents !== undefined && (
                        <p className="mt-1 text-slate-600">Estimated <strong className="text-[#0c1f3d]">{pesoWhole(check.estimate_cents)}</strong> at {pesoWhole(check.daily_cents)}/day{check.free_day ? ', 1 day free' : ''}. We confirm the final amount.</p>
                    )}
                </div>
            )}

            {check.state === 'free' && chat ? (
                <a href={chatLink(chat, model.name, from, to, check.days, check.estimate_cents)} target="_blank" rel="noreferrer" aria-label={`Inquire about the ${model.name} on Messenger`} className={cn(BTN_NAVY, 'w-full')}><MessageCircle size={17} aria-hidden /> Inquire on Messenger</a>
            ) : (
                <button type="button" disabled className={cn(BTN_NAVY, 'w-full cursor-not-allowed opacity-40')}><MessageCircle size={17} aria-hidden /> Inquire on Messenger</button>
            )}
            <p className="text-xs leading-relaxed text-slate-500">Opens a Messenger chat with your camera and days already written. Your days are reserved only when we confirm them there. No payment happens on this page.</p>
        </div>
    );
}

/** A light dialog for the inquiry, styled for this page rather than the admin. */
function Sheet({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => { ref.current?.showModal(); }, []);

    return (
        <dialog ref={ref} onClose={onClose} onClick={(e) => e.target === ref.current && ref.current?.close()} className="m-auto w-[calc(100%-2rem)] max-w-md overflow-hidden rounded-3xl bg-white p-0 shadow-2xl backdrop:bg-[#06142b]/70 backdrop:backdrop-blur-sm">
            <header className="flex items-center justify-between gap-3 bg-[#0c1f3d] px-5 py-4 text-white">
                <h2 className={cn(DISPLAY, 'text-base font-bold')}>{title}</h2>
                <button type="button" aria-label="Close" onClick={() => ref.current?.close()} className="flex size-10 items-center justify-center rounded-full hover:bg-white/10"><X size={18} /></button>
            </header>
            <div className="max-h-[80vh] overflow-y-auto p-5">{children}</div>
        </dialog>
    );
}

function CameraCard({ model, month, from, to, onInquire }: { model: Model; month: string; from?: string; to?: string; onInquire: () => void }) {
    const photo = model.images[0];

    return (
        <article className="group flex h-full flex-col overflow-hidden rounded-[28px] bg-white shadow-[0_30px_60px_-35px_rgba(0,0,0,0.6)] ring-1 ring-white/10 transition duration-300 hover:-translate-y-1 hover:shadow-[0_40px_70px_-30px_rgba(0,0,0,0.7)]">
            <div className={cn('relative flex aspect-[4/3] items-center justify-center overflow-hidden', photo && !photo.default ? 'bg-[radial-gradient(circle_at_50%_45%,#24467a_0%,#0f2a4d_60%,#0b2443_100%)]' : 'bg-[radial-gradient(circle_at_50%_40%,#ffffff_0%,#eef1f6_55%,#dde3ec_100%)]')}>
                {photo && !photo.default
                    ? <img src={photo.url} alt={photo.alt || model.name} loading="lazy" className="max-h-[88%] max-w-[88%] object-contain [mask-image:radial-gradient(ellipse_at_center,black_55%,transparent_78%)] transition duration-500 group-hover:scale-105" />
                    : <div className="flex flex-col items-center gap-2 text-[#0c1f3d]/35"><Camera size={64} strokeWidth={1} aria-hidden /><span className="text-[10px] font-semibold tracking-[0.3em] uppercase">Photo soon</span></div>}
                {model.brand && <span className="absolute top-4 left-4 rounded-full bg-[#0c1f3d] px-3 py-1 text-[10px] font-bold tracking-[0.2em] text-white uppercase">{model.brand}</span>}
                {model.units > 1 && <span className="absolute top-4 right-4 rounded-full bg-white/90 px-2.5 py-1 text-[10px] font-semibold text-[#0c1f3d] shadow-sm">{model.units} units</span>}
            </div>
            <div className="flex flex-1 flex-col gap-4 p-5 sm:p-6">
                <div>
                    <h3 className={cn(DISPLAY, 'text-lg leading-tight font-extrabold tracking-tight text-[#0c1f3d] uppercase')}>{model.name}</h3>
                    {model.specs && <p className="mt-0.5 text-sm text-slate-500">{model.specs}</p>}
                </div>
                <RateDots rates={model.rates} freeFrom={model.free_day_from} />
                <MiniMonth model={model} month={month} from={from} to={to} />
                <button type="button" disabled={model.closed} onClick={onInquire} className={cn(BTN_NAVY, 'mt-auto w-full disabled:cursor-not-allowed disabled:opacity-40')}>
                    {model.closed ? 'Not available right now' : <>Check days & inquire <ArrowRight size={16} className="transition group-hover:translate-x-0.5" aria-hidden /></>}
                </button>
            </div>
        </article>
    );
}

function Heading({ eyebrow, bold, light, center, children }: { eyebrow?: string; bold: string; light?: string; center?: boolean; children?: ReactNode }) {
    return (
        <div className={cn('max-w-3xl', center && 'mx-auto text-center')}>
            {eyebrow && <p className="mb-3 text-xs font-semibold tracking-[0.35em] text-[#f3e6c9] uppercase">{eyebrow}</p>}
            <h2 className={cn(DISPLAY, 'text-3xl leading-[1.05] text-white uppercase sm:text-5xl')}><span className="font-extrabold">{bold}</span>{light && <span className="block font-light">{light}</span>}</h2>
            {children && <p className="mt-4 text-base leading-relaxed text-white/70 sm:text-lg">{children}</p>}
        </div>
    );
}

export default function GrabCam({ models, brands, grabcam, seo }: { models: Model[]; brands: string[]; grabcam: { messenger_url?: string; facebook_url?: string }; seo?: { title: string; description: string } }) {
    const today = todayInManila();
    const chat = grabcam.messenger_url || grabcam.facebook_url || null;
    const [brand, setBrand] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [month, setMonth] = useState(today.slice(0, 7));
    const [open, setOpen] = useState<Model | null>(null);
    const chosen = !!from && to > from;
    const freeDays = [...new Set(models.map((m) => m.free_day_from))];
    const freeFrom = freeDays.length === 1 ? freeDays[0] : 0;
    const lowest = Math.min(...models.flatMap((m) => m.rates.map((r) => r.daily_cents)));
    // The hero prints show the cameras themselves, as the suites page shows its suites.
    const shots = models.map((m) => m.images[0]).filter((i) => i && !i.default).map((i) => i.url);

    useEffect(() => { if (from) setMonth(from.slice(0, 7)); }, [from]);

    const shown = models.filter((m) => (!brand || m.brand === brand) && (!chosen || datesAreFree(m, from, to)));
    const field = 'w-full min-w-0 rounded-xl border-0 bg-white/10 px-4 py-3 text-sm text-white ring-1 ring-white/15 [color-scheme:dark] focus:ring-2 focus:ring-white/60 focus:outline-none sm:w-44';

    return (
        <div className="min-h-dvh overflow-x-clip bg-[#0b1d3a] font-['Poppins',ui-sans-serif,system-ui,sans-serif] text-white antialiased">
            <Head title={seo?.title ?? 'Grab Cam Rental | Camera rental'}>
                <link rel="icon" type="image/png" href="/brand/grabcam-logo.png" />
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;700;800;900&family=Poppins:wght@400;500;600&display=swap" />
                <meta name="description" content={seo?.description ?? 'Rent a camera from Grab Cam Rental. See per-day rates and free days, then inquire on Messenger.'} />
                <meta property="og:title" content={seo?.title ?? 'Grab Cam Rental'} />
                <meta property="og:description" content={seo?.description ?? ''} />
            </Head>

            {/* Header */}
            <header className="sticky top-0 z-30 border-b border-white/10 bg-[#0b1d3a]/75 backdrop-blur-xl">
                <div className={cn(WIDTH, 'flex h-16 items-center justify-between gap-4 sm:h-20')}>
                    <a href="#top" aria-label="Grab Cam Rental" className="flex items-center gap-2.5"><img src="/brand/grabcam-logo.png" alt="" width={256} height={256} className="size-10 sm:size-12" /><Wordmark /></a>
                    <nav aria-label="Sections" className="hidden items-center gap-7 text-sm text-white/70 md:flex">
                        {[['Cameras', '#cameras'], ['How it works', '#how'], ['Terms', '#terms']].map(([label, href]) => <a key={href} href={href} className="transition hover:text-white">{label}</a>)}
                    </nav>
                    {chat && <a href={messengerUrl(chat)} target="_blank" rel="noreferrer" className={cn(BTN_LIGHT, 'min-h-10 px-5 text-xs sm:min-h-11 sm:text-sm')}><MessageCircle size={16} aria-hidden /> Message us</a>}
                </div>
            </header>

            {/* Hero */}
            <section id="top" className="relative isolate overflow-hidden">
                <BrandArcs className="-top-40 -right-56 -z-10 size-[900px] text-white/[0.035]" />
                <div aria-hidden className="absolute inset-0 -z-10 bg-[radial-gradient(60%_50%_at_75%_35%,rgba(120,160,230,0.28),transparent_70%),radial-gradient(40%_40%_at_10%_90%,rgba(243,230,201,0.10),transparent_70%)]" />
                <div aria-hidden className="absolute top-1/3 -left-20 -z-10 h-px w-[140%] -rotate-12 bg-gradient-to-r from-transparent via-white/40 to-transparent blur-[1px]" />
                <div className={cn(WIDTH, 'grid items-center gap-12 py-14 sm:py-20 lg:grid-cols-[1.05fr_1fr] lg:py-28')}>
                    <Reveal>
                        <p className="mb-5 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-medium tracking-wide text-white/80"><span className="size-1.5 rounded-full bg-emerald-400" aria-hidden /> Open daily, 8:00 AM – 6:00 PM</p>
                        <h1 className={cn(DISPLAY, 'text-[2.6rem] leading-[1.02] uppercase sm:text-6xl lg:text-7xl')}>
                            <span className="block font-extrabold">Rent the camera.</span>
                            <span className="block font-light text-white/90">Keep the moments.</span>
                        </h1>
                        <p className="mt-6 max-w-lg text-base leading-relaxed text-white/70 sm:text-lg">Canon, Fujifilm, Ricoh, iPhone and DJI, ready for your trip, event or everyday content. The longer you rent, the less you pay per day{freeFrom > 0 ? `, and from ${freeFrom} days you get 1 day free` : ''}.</p>
                        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                            <a href="#cameras" className={BTN_LIGHT}>Browse cameras <ArrowRight size={16} aria-hidden /></a>
                            <a href="#terms" className={BTN_GHOST}>Rental terms</a>
                        </div>
                        <dl className="mt-10 grid max-w-lg grid-cols-3 gap-4 border-t border-white/10 pt-6">
                            {[[`${models.length}`, 'cameras & devices'], [Number.isFinite(lowest) ? pesoWhole(lowest) : '—', 'per day, from'], ['₱0', 'security deposit']].map(([value, label]) => (
                                <div key={label}><dt className="sr-only">{label}</dt><dd className={cn(DISPLAY, 'text-2xl font-extrabold sm:text-3xl')}>{value}</dd><p className="mt-0.5 text-xs text-white/55">{label}</p></div>
                            ))}
                        </dl>
                    </Reveal>
                    <Reveal delay={150} className="relative mx-auto h-[340px] w-full max-w-[520px] sm:h-[440px]">
                        <Print shots={shots} offset={0} caption="point & shoot" className="absolute top-10 left-0 w-[42%] -rotate-[8deg] hover:-rotate-[4deg]" />
                        <Print shots={shots} offset={4} caption="on the go" className="absolute top-14 right-0 w-[42%] rotate-[7deg] hover:rotate-[3deg]" />
                        <Print shots={shots} offset={2} caption="grab cam" className="absolute top-0 left-1/2 z-10 w-[48%] -translate-x-1/2 -rotate-1 hover:rotate-0" />
                        <Paperclip className="absolute top-[-14px] right-[30%] z-20 h-24 w-8 rotate-12 drop-shadow" />
                        <div className="absolute inset-x-6 bottom-0 z-20 rounded-2xl border border-white/10 bg-[#0c1f3d]/80 p-4 backdrop-blur-md sm:inset-x-10">
                            <p className="text-xs font-semibold tracking-[0.25em] text-[#f3e6c9] uppercase">Rates per day</p>
                            <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-white/75">
                                {['1–2 days', '3–5 days', '6 days or more'].map((label, i) => <span key={label} className="inline-flex items-center gap-1.5"><span className={cn('size-2.5 rounded-full', TIER_DOT[i])} aria-hidden />{label}</span>)}
                            </div>
                        </div>
                    </Reveal>
                </div>
            </section>

            {/* Cameras */}
            <section id="cameras" className="relative scroll-mt-20 bg-gradient-to-b from-[#0b1d3a] via-[#0e2547] to-[#0b1d3a] py-16 sm:py-24">
                <div className={WIDTH}>
                    <Reveal><Heading eyebrow="The lineup" bold="Our cameras" light="& availability">Crossed-out days are fully booked. Pick your days to see only the cameras free for all of them.</Heading></Reveal>

                    <Reveal delay={100} className="mt-8 rounded-3xl border border-white/10 bg-white/[0.04] p-4 backdrop-blur sm:p-5">
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="flex min-w-0 flex-1 flex-col gap-1.5 text-[11px] font-semibold tracking-[0.2em] text-white/60 uppercase sm:flex-none">Pickup
                                <input type="date" min={today} value={from} onChange={(e) => { setFrom(e.target.value); if (to && e.target.value >= to) setTo(''); }} className={field} />
                            </label>
                            <label className="flex min-w-0 flex-1 flex-col gap-1.5 text-[11px] font-semibold tracking-[0.2em] text-white/60 uppercase sm:flex-none">Return
                                <input type="date" min={from ? addDays(from, 1) : today} value={to} onChange={(e) => setTo(e.target.value)} className={field} />
                            </label>
                            <div className="ml-auto flex items-center rounded-full bg-white/5 ring-1 ring-white/10">
                                <button type="button" aria-label="Previous month" disabled={month <= today.slice(0, 7)} onClick={() => setMonth(shiftMonth(month, -1))} className="flex size-11 items-center justify-center rounded-full hover:bg-white/10 disabled:opacity-30"><ChevronLeft size={18} /></button>
                                <p className="min-w-32 text-center text-sm font-semibold" aria-live="polite">{monthLabel(month)}</p>
                                <button type="button" aria-label="Next month" onClick={() => setMonth(shiftMonth(month, 1))} className="flex size-11 items-center justify-center rounded-full hover:bg-white/10"><ChevronRight size={18} /></button>
                            </div>
                        </div>
                        <div role="radiogroup" aria-label="Brand" className="mt-4 flex gap-2 overflow-x-auto pb-1">
                            {['', ...brands].map((b) => (
                                <button key={b || 'all'} type="button" role="radio" aria-checked={brand === b} onClick={() => setBrand(b)}
                                    className={cn('min-h-10 shrink-0 rounded-full px-5 text-sm font-medium transition', brand === b ? 'bg-white text-[#0c1f3d]' : 'bg-white/5 text-white/75 ring-1 ring-white/15 hover:bg-white/10 hover:text-white')}>
                                    {b || 'All brands'}
                                </button>
                            ))}
                            {(from || to || brand) && <button type="button" onClick={() => { setFrom(''); setTo(''); setBrand(''); }} className="min-h-10 shrink-0 px-3 text-sm font-medium text-[#f3e6c9] underline-offset-4 hover:underline">Clear</button>}
                        </div>
                    </Reveal>

                    <p role="status" className="mt-6 text-sm text-white/60">{shown.length} camera{shown.length === 1 ? '' : 's'}{chosen ? ` free from ${shortDate(from)} to ${shortDate(to)}` : ''}</p>

                    {shown.length === 0 ? (
                        <p className="mt-4 rounded-3xl border border-white/10 bg-white/5 p-10 text-center text-white/70">{models.length === 0 ? 'Our cameras will be listed here soon.' : 'No camera is free for all of those days. Please try other days.'}</p>
                    ) : (
                        <div className="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {shown.map((m, i) => <Reveal key={m.slug} delay={(i % 3) * 80} className="h-full"><CameraCard model={m} month={month} from={chosen ? from : undefined} to={chosen ? to : undefined} onInquire={() => setOpen(m)} /></Reveal>)}
                        </div>
                    )}
                </div>
            </section>

            {/* How it works */}
            <section id="how" className="relative isolate scroll-mt-20 overflow-hidden py-16 sm:py-24">
                <BrandArcs className="-bottom-72 -left-72 -z-10 size-[760px] text-white/[0.03]" />
                <div className={WIDTH}>
                    <Reveal><Heading eyebrow="Easy as 1, 2, 3" bold="How renting" light="works" center /></Reveal>
                    <ol className="mt-12 grid gap-5 md:grid-cols-3">
                        {STEPS.map(([title, body, Icon], i) => (
                            <li key={title}>
                                <Reveal delay={i * 100} className="h-full">
                                    <div className="relative h-full rounded-3xl border border-white/10 bg-gradient-to-b from-white/[0.07] to-white/[0.02] p-7">
                                        <span className={cn(DISPLAY, 'absolute top-5 right-6 text-5xl font-black text-white/[0.06]')} aria-hidden>0{i + 1}</span>
                                        <span className="flex size-12 items-center justify-center rounded-2xl bg-white text-[#0c1f3d]"><Icon size={22} aria-hidden /></span>
                                        <h3 className={cn(DISPLAY, 'mt-5 text-lg font-bold')}>{title}</h3>
                                        <p className="mt-2 leading-relaxed text-white/65">{body}</p>
                                    </div>
                                </Reveal>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            {/* Terms */}
            <section id="terms" className="relative isolate scroll-mt-20 overflow-hidden py-16 sm:py-24">
                <BrandArcs className="-top-40 -right-64 -z-10 size-[800px] text-white/[0.03]" />
                <div className={WIDTH}>
                    <Reveal><Heading eyebrow="Before you rent" bold="Terms & conditions" center>Simple rules that keep every camera ready for the next renter.</Heading></Reveal>
                    <div className="mt-12 columns-1 gap-5 md:columns-2 [&>*]:mb-5">
                        {TERMS.map((t, i) => (
                            <Reveal key={t.title} delay={(i % 2) * 80} className="break-inside-avoid">
                                <article className="rounded-[28px] bg-white p-6 text-[#0c1f3d] shadow-[0_25px_50px_-30px_rgba(0,0,0,0.7)] sm:p-7">
                                    <div className="flex items-start justify-between gap-4">
                                        <h3 className={cn(DISPLAY, 'text-base font-extrabold tracking-wide uppercase sm:text-lg')}>{t.title}</h3>
                                        <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-[#0c1f3d]/5"><t.icon size={22} strokeWidth={1.75} aria-hidden /></span>
                                    </div>
                                    {t.lead && <p className="mt-3 leading-relaxed text-slate-600">{t.lead}</p>}
                                    {t.items && <ul className="mt-3 space-y-1.5">{t.items.map((item) => <li key={item} className="flex gap-2.5 leading-relaxed text-slate-600"><span className="mt-2.5 size-1.5 shrink-0 rounded-full bg-[#0c1f3d]" aria-hidden />{item}</li>)}</ul>}
                                    {t.groups?.map((g) => (
                                        <div key={g.lead} className="mt-3">
                                            <p className="leading-relaxed text-slate-600">{g.lead}</p>
                                            <ul className="mt-1.5 space-y-1.5">{g.items.map((item) => <li key={item} className="flex gap-2.5 leading-relaxed text-slate-600"><span className="mt-2.5 size-1.5 shrink-0 rounded-full bg-[#0c1f3d]" aria-hidden />{item}</li>)}</ul>
                                        </div>
                                    ))}
                                </article>
                            </Reveal>
                        ))}
                    </div>
                </div>
            </section>

            {/* Closing call */}
            <section className="pb-16 sm:pb-24">
                <div className={WIDTH}>
                    <Reveal className="relative isolate overflow-hidden rounded-[36px] bg-white px-6 py-14 text-center text-[#0c1f3d] sm:px-12">
                        <BrandArcs className="-top-32 -right-40 -z-10 size-[520px] text-[#0c1f3d]/[0.05]" />
                        <ShieldCheck size={36} className="mx-auto" aria-hidden />
                        <h2 className={cn(DISPLAY, 'mx-auto mt-4 max-w-2xl text-3xl leading-tight uppercase sm:text-5xl')}><span className="font-extrabold">Your next shot</span> <span className="font-light">starts with a message.</span></h2>
                        <p className="mx-auto mt-4 max-w-md text-slate-500">Tell us the camera and the days. We reply on Facebook and confirm your pickup time.</p>
                        <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                            {chat && <a href={messengerUrl(chat)} target="_blank" rel="noreferrer" className={BTN_NAVY}><MessageCircle size={17} aria-hidden /> Message Grab Cam</a>}
                            <a href="#cameras" className={cn(BTN, 'border border-[#0c1f3d]/20 hover:border-[#0c1f3d]/50')}>See cameras</a>
                        </div>
                    </Reveal>
                </div>
            </section>

            <footer className="border-t border-white/10 py-10">
                <div className={cn(WIDTH, 'flex flex-col items-center justify-between gap-5 text-sm text-white/55 sm:flex-row')}>
                    <div className="flex items-center gap-3"><img src="/brand/grabcam-logo.png" alt="" width={256} height={256} className="size-12" /><Wordmark /><span className="hidden border-l border-white/15 pl-3 sm:inline">Open daily, 8:00 AM – 6:00 PM</span></div>
                    {grabcam.facebook_url && <a href={grabcam.facebook_url} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center gap-2 transition hover:text-white">Grab Cam Rental on Facebook <ArrowRight size={14} aria-hidden /></a>}
                </div>
            </footer>

            {open && <Sheet title={`Rent the ${open.name}`} onClose={() => setOpen(null)}><InquirePanel model={open} chat={chat} initialFrom={chosen ? from : ''} initialTo={chosen ? to : ''} /></Sheet>}
        </div>
    );
}
