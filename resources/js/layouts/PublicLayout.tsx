import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Menu, X } from 'lucide-react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { MessageButton, PAGE_WIDTH } from '@/components/public';
import { cn } from '@/components/ui';

const NAV = [
    { label: 'Suites', href: '/#availability' },
    { label: 'The Loop', href: '/?location=the-loop#availability' },
    { label: 'Mesaverte', href: '/?location=mesaverte#availability' },
    { label: 'Policies & FAQ', href: '/policies' },
];

const FOCUSABLE = 'a[href], button:not([disabled])';
const SWIPE_TO_CLOSE_PX = 60;
const DESKTOP_MENU = '(min-width: 64rem)';

/**
 * Stays mounted so it can slide out as well as in. While closed it is inert, so its links cannot
 * be reached by keyboard or screen reader. The hamburger sits on the right, so it opens from there.
 */
function SideMenu({ open, onClose, contact }: { open: boolean; onClose: () => void; contact: any }) {
    const panel = useRef<HTMLDivElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const touchStart = useRef<number | null>(null);
    const current = usePage().url;

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
            opener?.focus?.();
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
                        {[{ label: 'Home', href: '/' }, ...NAV].map((n) => {
                            const here = current === n.href;

                            return (
                                <li key={n.href}>
                                    <Link href={n.href} onClick={onClose} aria-current={here ? 'page' : undefined} className={cn('flex min-h-14 items-center justify-between gap-3 font-serif text-2xl font-light transition-colors active:text-gold', here && 'text-gold')}>
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
                    {(contact.messenger_url || contact.facebook_url) && (
                        <div className="flex justify-center gap-6 text-sm text-beige">
                            {contact.messenger_url && <a href={contact.messenger_url} target="_blank" rel="noreferrer" className="flex min-h-11 items-center">Messenger</a>}
                            {contact.facebook_url && <a href={contact.facebook_url} target="_blank" rel="noreferrer" className="flex min-h-11 items-center">Facebook</a>}
                        </div>
                    )}
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
    const { contact } = usePage<any>().props as any;
    const [open, setOpen] = useState(false);

    return (
        <div className="flex min-h-dvh flex-col">
            <a href="#main" className="skip-link">Skip to main content</a>
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={title ? `${title} | UNIQA SUITES` : 'UNIQA SUITES'} />
                <meta property="og:description" content={description} />
            </Head>
            <header className="sticky top-0 z-30 border-b border-gold/20 bg-chocolate text-ivory">
                <div className={cn(PAGE_WIDTH, 'flex h-16 items-center justify-between gap-3')}>
                    <Link href="/" aria-label="UNIQA SUITES home" className="flex min-h-11 shrink-0 items-center"><img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-8 w-auto sm:h-12" /></Link>
                    <nav aria-label="Main" className="hidden items-center gap-8 text-xs font-medium tracking-[0.18em] uppercase lg:flex">
                        {NAV.map((n) => <Link key={n.href} href={n.href} className="transition-colors hover:text-gold">{n.label}</Link>)}
                    </nav>
                    <div className="flex items-center gap-2">
                        <MessageButton tone="outline" small hideIfMissing className="max-md:hidden" />
                        <button type="button" className="-mr-2 flex size-11 items-center justify-center lg:hidden" aria-label="Open menu" aria-haspopup="dialog" aria-expanded={open} aria-controls="side-menu" onClick={() => setOpen(true)}><Menu size={24} strokeWidth={1.5} aria-hidden /></button>
                    </div>
                </div>
            </header>
            <SideMenu open={open} onClose={() => setOpen(false)} contact={contact} />
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
                            {NAV.map((n) => <li key={n.href}><Link href={n.href} className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">{n.label}</Link></li>)}
                            <li><Link href="/policies#privacy" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Privacy notice</Link></li>
                        </ul>
                    </div>
                    <div>
                        <h2 className="mb-3 text-xs font-medium tracking-[0.22em] text-gold uppercase">Contact</h2>
                        <ul className="space-y-2">
                            {contact.messenger_url && <li><a href={contact.messenger_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Messenger</a></li>}
                            {contact.instagram_url && <li><a href={contact.instagram_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Instagram</a></li>}
                            {contact.facebook_url && <li><a href={contact.facebook_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Facebook</a></li>}
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
