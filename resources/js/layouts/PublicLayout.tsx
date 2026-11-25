import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Menu, X } from 'lucide-react';
import { MouseEvent, ReactNode, useEffect, useRef, useState } from 'react';
import { MessageButton, PAGE_WIDTH } from '@/components/public';
import { cn } from '@/components/ui';

type NavItem = { label: string; href: string; section?: string };

// A `section` item is a part of the home page: on the home page it scrolls there, elsewhere it opens the home page at it.
const NAV: NavItem[] = [
    { label: 'Availability', href: '/#availability', section: 'availability' },
    { label: 'Our Rooms', href: '/#suites', section: 'suites' },
    { label: 'Policies & FAQ', href: '/policies' },
];

const SECTIONS = NAV.flatMap((n) => (n.section ? [n.section] : []));
const HEADER_PX = 64;

const FOCUSABLE = 'a[href], button:not([disabled])';
const SWIPE_TO_CLOSE_PX = 60;
const DESKTOP_MENU = '(min-width: 64rem)';

const pathOf = (url: string) => url.split(/[?#]/)[0] || '/';

/**
 * The menu item for where the guest is now. On the home page it follows the section being read:
 * the one crossing a line a third of the way down the screen. A suite's own page belongs to Our Rooms.
 */
function useActiveNav(): string | null {
    const path = pathOf(usePage().url);
    const [section, setSection] = useState<string | null>(null);

    useEffect(() => {
        if (path !== '/') {
            setSection(null);
            return;
        }
        const onScroll = () => {
            const line = HEADER_PX + (window.innerHeight - HEADER_PX) / 3;
            setSection(SECTIONS.find((id) => {
                const box = document.getElementById(id)?.getBoundingClientRect();
                return !!box && box.top <= line && box.bottom > line;
            }) ?? null);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);

        return () => {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
        };
    }, [path]);

    if (path === '/') return NAV.find((n) => n.section && n.section === section)?.href ?? null;
    if (path.startsWith('/suites')) return '/#suites';

    return NAV.find((n) => !n.section && pathOf(n.href) === path)?.href ?? null;
}

/** Already on the home page, a section link glides there instead of reloading, and keeps the guest's filters in the address. */
function scrollToSection(e: MouseEvent, item: NavItem, before?: () => void) {
    before?.();
    if (!item.section || window.location.pathname !== '/' || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const target = document.getElementById(item.section);
    if (!target) return;
    e.preventDefault();
    const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // After the side menu has closed and given the page its scrolling back.
    setTimeout(() => target.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto' }), 0);
    window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}#${item.section}`);
}

const ICON = { width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.5, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true } as const;

const SOCIALS = [
    { key: 'facebook_url', label: 'Facebook', icon: <svg {...ICON}><path d="M7 10v4h3v7h4v-7h3l1-4h-4V8a1 1 0 0 1 1-1h3V3h-3a5 5 0 0 0-5 5v2H7" /></svg> },
    { key: 'tiktok_url', label: 'TikTok', icon: <svg {...ICON}><path d="M21 7.917v4.034a9.948 9.948 0 0 1-5-1.951v4.5a6.5 6.5 0 1 1-8-6.326v4.326a2.5 2.5 0 1 0 4 2V3h4.083A6.005 6.005 0 0 0 21 7.917z" /></svg> },
    { key: 'instagram_url', label: 'Instagram', icon: <svg {...ICON}><rect x="4" y="4" width="16" height="16" rx="4" /><circle cx="12" cy="12" r="3" /><path d="M16.5 7.5v.01" /></svg> },
] as const;

/** Only the channels set in the environment file appear. */
function Socials({ contact, className }: { contact: any; className?: string }) {
    const links = SOCIALS.filter((s) => contact[s.key]);
    if (links.length === 0) return null;

    return (
        <ul className={cn('flex items-center', className)}>
            {links.map((s) => (
                <li key={s.key}>
                    <a href={contact[s.key]} target="_blank" rel="noreferrer" aria-label={`UNIQA SUITES on ${s.label}`} title={s.label} className="flex size-11 items-center justify-center transition-colors hover:text-gold">{s.icon}</a>
                </li>
            ))}
        </ul>
    );
}

/**
 * Stays mounted so it can slide out as well as in. While closed it is inert, so its links cannot
 * be reached by keyboard or screen reader. The hamburger sits on the right, so it opens from there.
 */
function SideMenu({ open, onClose, contact, active }: { open: boolean; onClose: () => void; contact: any; active: string | null }) {
    const panel = useRef<HTMLDivElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const touchStart = useRef<number | null>(null);
    const home = pathOf(usePage().url) === '/';

    useEffect(() => {
        panel.current?.toggleAttribute('inert', !open);
        if (!open) return;

        const opener = document.activeElement as HTMLElement | null;
        closeButton.current?.focus();
        document.body.style.overflow = 'hidden';

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            if (e.key !== 'Tab' || !panel.current) return;
            const items = Array.from(panel.current.querySelectorAll<HTMLElement>(FOCUSABLE));
            const [first, last] = [items[0], items[items.length - 1]];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        };
        // Turning a tablet can reach the desktop layout, where this menu does not exist to be closed.
        const wide = window.matchMedia(DESKTOP_MENU);
        const onWide = () => wide.matches && onClose();

        document.addEventListener('keydown', onKey);
        wide.addEventListener('change', onWide);

        return () => {
            document.removeEventListener('keydown', onKey);
            wide.removeEventListener('change', onWide);
            document.body.style.overflow = '';
            opener?.focus?.({ preventScroll: true });
        };
    }, [open]);

    return (
        <div className={cn('fixed inset-0 z-50 lg:hidden', !open && 'pointer-events-none')}>
            <div onClick={onClose} aria-hidden className={cn('absolute inset-0 bg-black/60 transition-opacity duration-300', open ? 'opacity-100' : 'opacity-0')} />
            <div
                ref={panel}
                id="side-menu"
                role="dialog"
                aria-modal="true"
                aria-label="Menu"
                onTouchStart={(e) => { touchStart.current = e.touches[0].clientX; }}
                onTouchEnd={(e) => {
                    if (touchStart.current !== null && e.changedTouches[0].clientX - touchStart.current > SWIPE_TO_CLOSE_PX) onClose();
                    touchStart.current = null;
                }}
                className={cn('absolute inset-y-0 right-0 flex w-[min(20rem,85vw)] flex-col overflow-y-auto bg-chocolate pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] text-ivory shadow-2xl duration-300 ease-out', open ? 'visible translate-x-0 transition-transform' : 'invisible translate-x-full transition-[transform,visibility]')}
            >
                <div className="flex h-16 shrink-0 items-center justify-between border-b border-gold/20 pr-2 pl-5">
                    <img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-8 w-auto" />
                    <button ref={closeButton} type="button" aria-label="Close menu" onClick={onClose} className="flex size-11 items-center justify-center"><X size={22} strokeWidth={1.5} aria-hidden /></button>
                </div>
                <nav aria-label="Mobile" className="flex-1 px-5 py-3">
                    <ul className="divide-y divide-gold/15">
                        {[{ label: 'Home', href: '/' } as NavItem, ...NAV].map((n) => {
                            // Home is lit only at the top of the home page, before any section is reached.
                            const here = n.href === '/' ? home && active === null : active === n.href;

                            return (
                                <li key={n.href}>
                                    <Link href={n.href} onClick={(e) => scrollToSection(e, n, onClose)} aria-current={here ? (n.section ? 'location' : 'page') : undefined} className={cn('relative flex min-h-14 items-center justify-between gap-3 font-serif text-2xl font-light transition-colors active:text-gold', here && 'text-gold before:absolute before:inset-y-3 before:-left-5 before:w-0.5 before:bg-gold')}>
                                        {n.label}
                                        <ChevronRight size={18} strokeWidth={1.25} className="shrink-0 text-gold" aria-hidden />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
                <div className="space-y-4 border-t border-gold/20 p-5">
                    <div onClick={onClose}><MessageButton hideIfMissing className="w-full" /></div>
                    <Socials contact={contact} className="justify-center gap-3 text-beige" />
                </div>
            </div>
        </div>
    );
}

/** Within thumb reach once the first screen has gone. It steps aside while the dates are already on screen. */
function StickyBook({ targetId }: { targetId: string }) {
    const [scrolled, setScrolled] = useState(false);
    const [targetVisible, setTargetVisible] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > window.innerHeight * 0.7);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        const target = document.getElementById(targetId);
        const observer = target ? new IntersectionObserver(([entry]) => setTargetVisible(entry.isIntersecting), { rootMargin: '-20% 0px -20% 0px' }) : null;
        if (target) observer?.observe(target);

        return () => {
            window.removeEventListener('scroll', onScroll);
            observer?.disconnect();
        };
    }, [targetId]);

    const shown = scrolled && !targetVisible;

    return (
        <div aria-hidden={!shown} className={cn('fixed inset-x-0 bottom-0 z-30 border-t border-gold/20 bg-chocolate/95 px-4 pt-2.5 pb-[max(0.625rem,env(safe-area-inset-bottom))] backdrop-blur transition-transform duration-300 md:hidden', shown ? 'translate-y-0' : 'invisible translate-y-full')}>
            <MessageButton hideIfMissing className="w-full" />
        </div>
    );
}

export default function PublicLayout({ title, description, stickyBookFor, children }: { title?: string; description: string; stickyBookFor?: string; children: ReactNode }) {
    const { contact, seo } = usePage<any>().props as any;
    const [open, setOpen] = useState(false);
    const active = useActiveNav();
    // The server's search title and description win, so crawlers and the browser agree.
    const fullTitle = seo?.title ?? (title ? `${title} | UNIQA SUITES` : 'UNIQA SUITES');
    const summary = seo?.description ?? description;

    return (
        <div className="flex min-h-dvh flex-col">
            <a href="#main" className="skip-link">Skip to main content</a>
            <Head title={fullTitle}>
                <meta name="description" content={summary} />
                <meta property="og:title" content={fullTitle} />
                <meta property="og:description" content={summary} />
            </Head>
            <header className="sticky top-0 z-30 border-b border-gold/20 bg-chocolate text-ivory">
                <div className={cn(PAGE_WIDTH, 'flex h-16 items-center justify-between gap-3')}>
                    <Link href="/" aria-label="UNIQA SUITES home" className="flex min-h-11 shrink-0 items-center"><img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-8 w-auto sm:h-12" /></Link>
                    <nav aria-label="Main" className="hidden h-full items-stretch gap-8 text-xs font-medium tracking-[0.18em] uppercase lg:flex">
                        {NAV.map((n) => {
                            const here = active === n.href;

                            return (
                                <Link key={n.href} href={n.href} onClick={(e) => scrollToSection(e, n)} aria-current={here ? (n.section ? 'location' : 'page') : undefined} className={cn('relative flex items-center transition-colors hover:text-gold after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:origin-center after:bg-gold after:transition-transform after:duration-300', here ? 'text-gold after:scale-x-100' : 'after:scale-x-0')}>
                                    {n.label}
                                </Link>
                            );
                        })}
                    </nav>
                    <div className="flex items-center gap-2">
                        <Socials contact={contact} className="-mr-1 text-beige max-lg:hidden" />
                        <MessageButton tone="outline" small hideIfMissing className="max-md:hidden" />
                        <button type="button" className="-mr-2 flex size-11 items-center justify-center lg:hidden" aria-label="Open menu" aria-haspopup="dialog" aria-expanded={open} aria-controls="side-menu" onClick={() => setOpen(true)}><Menu size={24} strokeWidth={1.5} aria-hidden /></button>
                    </div>
                </div>
            </header>
            <SideMenu open={open} onClose={() => setOpen(false)} contact={contact} active={active} />
            <main id="main" tabIndex={-1} className="flex-1 outline-none">{children}</main>
            {stickyBookFor && <StickyBook targetId={stickyBookFor} />}
            <footer className={cn('border-t border-gold/20 bg-chocolate text-beige', stickyBookFor && 'max-md:pb-20')}>
                <div className={cn(PAGE_WIDTH, 'grid gap-10 py-14 text-sm sm:grid-cols-3')}>
                    <div>
                        <img src="/brand/uniqa-monogram.png" alt="" className="mb-3 h-14 w-auto" />
                        <p className="max-w-xs leading-relaxed">Suites in Cagayan de Oro, at The Loop, Limketkai and Mesaverte Residences.</p>
                    </div>
                    <div>
                        <h2 className="mb-3 text-xs font-medium tracking-[0.22em] text-gold uppercase">Explore</h2>
                        <ul className="space-y-2">
                            {NAV.map((n) => <li key={n.href}><Link href={n.href} onClick={(e) => scrollToSection(e, n)} className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">{n.label}</Link></li>)}
                            <li><Link href="/policies#privacy" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Privacy notice</Link></li>
                        </ul>
                    </div>
                    <div>
                        <h2 className="mb-3 text-xs font-medium tracking-[0.22em] text-gold uppercase">Contact</h2>
                        <ul className="space-y-2">
                            {contact.messenger_url && <li><a href={contact.messenger_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Messenger</a></li>}
                            {contact.instagram_url && <li><a href={contact.instagram_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Instagram</a></li>}
                            {contact.facebook_url && <li><a href={contact.facebook_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Facebook</a></li>}
                            {contact.tiktok_url && <li><a href={contact.tiktok_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">TikTok</a></li>}
                            {contact.phone && <li>{contact.phone}</li>}
                            {contact.email && <li>{contact.email}</li>}
                            {!contact.messenger_url && !contact.phone && !contact.email && <li>Official contact details coming soon.</li>}
                        </ul>
                    </div>
                </div>
            </footer>
        </div>
    );
}
