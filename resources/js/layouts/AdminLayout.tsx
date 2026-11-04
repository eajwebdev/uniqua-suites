import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bell, BookOpen, Building2, CalendarDays, Car, ChevronsLeft, ChevronsRight, ClipboardList, Coins, FileSpreadsheet, HandCoins, LayoutDashboard, LogOut, Menu, MessageSquareText, Package, Plus,
    ScrollText, Settings, Sparkles, Users, UsersRound, Wallet, Wrench, BarChart3, X,
} from 'lucide-react';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import QuickFind, { QuickItem } from '@/components/QuickFind';
import { cn } from '@/components/ui';

const NAV_GROUPS: { label: string; items: { label: string; href: string; icon: any; any: string[] }[] }[] = [
    {
        label: 'Bookings',
        items: [
            { label: 'Dashboard', href: '/admin', icon: LayoutDashboard, any: ['dashboard.view'] },
            { label: 'Booking Calendar', href: '/admin/calendar', icon: CalendarDays, any: ['bookings.view'] },
            { label: 'Reservations', href: '/admin/bookings', icon: ClipboardList, any: ['bookings.view'] },
            { label: 'Quotes & Messages', href: '/admin/quotes', icon: MessageSquareText, any: ['quotes.manage'] },
            { label: 'Guests', href: '/admin/guests', icon: Users, any: ['guests.view'] },
        ],
    },
    {
        label: 'Property',
        items: [
            { label: 'Rooms & Properties', href: '/admin/rooms', icon: Building2, any: ['rooms.view'] },
            { label: 'Housekeeping', href: '/admin/housekeeping', icon: Sparkles, any: ['housekeeping.own', 'housekeeping.manage', 'housekeeping.inspect'] },
            { label: 'Maintenance & Damage', href: '/admin/work-orders', icon: Wrench, any: ['maintenance.own', 'maintenance.manage'] },
            { label: 'Damage Claims', href: '/admin/incidents', icon: BookOpen, any: ['__incidents_only'] },
            { label: 'Inventory', href: '/admin/inventory', icon: Package, any: ['inventory.view'] },
            { label: 'Parking', href: '/admin/parking', icon: Car, any: ['parking.view'] },
        ],
    },
    {
        label: 'Money',
        items: [
            { label: 'Payments', href: '/admin/payments', icon: Wallet, any: ['payments.view'] },
            { label: 'Expenses & Cashbook', href: '/admin/expenses', icon: Coins, any: ['expenses.view'] },
            { label: 'Reports', href: '/admin/reports', icon: BarChart3, any: ['reports.view'] },
            { label: 'Staff Advances', href: '/admin/advances', icon: HandCoins, any: ['advances.manage'] },
        ],
    },
    {
        label: 'Admin',
        items: [
            { label: 'Import & Data Quality', href: '/admin/imports', icon: FileSpreadsheet, any: ['imports.manage'] },
            { label: 'Team & Permissions', href: '/admin/team', icon: UsersRound, any: ['team.manage'] },
            { label: 'Settings', href: '/admin/settings', icon: Settings, any: ['settings.manage'] },
            { label: 'Audit Log', href: '/admin/audit', icon: ScrollText, any: ['audit.view'] },
        ],
    },
];

// The pages staff use most get a thumb-reach button on phones. Everything else stays in Menu.
const PHONE_TABS = ['/admin', '/admin/calendar', '/admin/bookings', '/admin/quotes', '/admin/housekeeping', '/admin/work-orders', '/admin/advances'];

const SHORT_LABELS: Record<string, string> = { '/admin': 'Home', '/admin/calendar': 'Calendar', '/admin/bookings': 'Bookings', '/admin/quotes': 'Quotes', '/admin/housekeeping': 'Cleaning', '/admin/work-orders': 'Repairs', '/admin/advances': 'Advances' };

const SIDEBAR_KEY = 'uniqa.sidebar.collapsed';

function storedCollapsed(): boolean {
    try {
        return localStorage.getItem(SIDEBAR_KEY) === '1';
    } catch {
        return false;
    }
}

/** Stays on screen when a page is scrolled, so the result of an action far down a list is seen. Errors stay until dismissed. */
function FlashMessage({ flash }: { flash: any }) {
    const [visible, setVisible] = useState(true);
    const message = flash?.error ?? flash?.success;
    const isError = !!flash?.error;

    useEffect(() => {
        setVisible(true);
        if (message && !isError) {
            const timer = setTimeout(() => setVisible(false), 5000);
            return () => clearTimeout(timer);
        }
    }, [flash, message, isError]);

    if (!message || !visible) return null;

    return (
        <div role={isError ? 'alert' : 'status'} className={cn('sticky top-16 z-[25] mb-4 flex items-start justify-between gap-2 rounded-lg border px-4 py-2.5 text-sm shadow-md', isError ? 'border-red-200 bg-red-50 text-red-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900')}>
            <span className="min-w-0">{message}</span>
            <button aria-label="Dismiss message" className="-my-1 -mr-2 flex size-8 shrink-0 items-center justify-center rounded pointer-coarse:size-11" onClick={() => setVisible(false)}>
                <X size={15} aria-hidden />
            </button>
        </div>
    );
}

export default function AdminLayout({ title, heading, subtitle, showSection = true, children, actions }: { title: string; heading?: string; subtitle?: ReactNode; showSection?: boolean; children: ReactNode; actions?: ReactNode }) {
    const { auth, flash } = usePage<any>().props as any;
    const path = usePage().url.split('?')[0];
    const [open, setOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(storedCollapsed);
    const menuButton = useRef<HTMLButtonElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const perms: string[] = auth.user?.permissions ?? [];
    const can = (p: string) => perms.includes(p);

    useEffect(() => {
        if (!open) return;
        const opener = menuButton.current;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        closeButton.current?.focus();
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
            opener?.focus();
        };
    }, [open]);

    const toggleCollapsed = () => {
        setCollapsed(!collapsed);
        try {
            localStorage.setItem(SIDEBAR_KEY, collapsed ? '0' : '1');
        } catch {
            // Only a convenience: without storage the sidebar simply opens expanded next time.
        }
    };

    const groups = NAV_GROUPS.map((g) => ({
        ...g,
        items: g.items.filter((n) => {
            if (n.any[0] === '__incidents_only') return can('incidents.view') && !can('maintenance.own') && !can('maintenance.manage');
            return n.any.some(can);
        }),
    })).filter((g) => g.items.length > 0);

    const allItems = groups.flatMap((g) => g.items.map((i) => ({ ...i, group: g.label })));
    const isActive = (href: string) => (href === '/admin' ? path === '/admin' : path.startsWith(href));
    const section = allItems.find((i) => isActive(i.href))?.group;
    const phoneTabs = PHONE_TABS.map((href) => allItems.find((i) => i.href === href)).filter(Boolean).slice(0, 4) as typeof allItems;

    const quickItems = useMemo<QuickItem[]>(() => [
        ...(perms.includes('bookings.manage') ? [{ label: 'New booking', href: '/admin/bookings/create', icon: Plus, group: 'Create' }] : []),
        ...(perms.includes('quotes.manage') ? [{ label: 'New quote: find vacant units and prices', href: '/admin/quotes/create', icon: Plus, group: 'Create' }] : []),
        ...allItems.map((i) => ({ label: i.label, href: i.href, icon: i.icon, group: i.group })),
        { label: 'My account and notifications', href: '/admin/account', icon: Bell, group: 'Account' },
    ], [perms.join(',')]);

    const quickSearches = useMemo(() => [
        ...(perms.includes('bookings.view') ? [{ label: 'Find bookings for', href: (q: string) => `/admin/bookings?q=${encodeURIComponent(q)}`, icon: ClipboardList }] : []),
        ...(perms.includes('guests.view') ? [{ label: 'Find guests named', href: (q: string) => `/admin/guests?q=${encodeURIComponent(q)}`, icon: Users }] : []),
    ], [perms.join(',')]);

    const initials = (auth.user?.name ?? '?').split(' ').map((w: string) => w[0]).slice(0, 2).join('').toUpperCase();
    const role = auth.user?.roles?.[0];

    const nav = (rail: boolean) => (
        <nav aria-label="Main" className={cn('sidebar-scroll flex-1 overflow-y-auto py-2', rail ? 'sidebar-scroll-hidden px-2' : 'px-3')}>
            {groups.map((g) => (
                <div key={g.label} className="mb-3 last:mb-0">
                    {groups.length > 1 && (rail ? <div className="mx-2 mb-2 border-t border-espresso first:hidden" aria-hidden /> : <p className="px-3 pb-1 text-[11px] font-medium tracking-wider text-gold uppercase">{g.label}</p>)}
                    <div className="space-y-0.5">
                        {g.items.map((n) => {
                            const active = isActive(n.href);
                            const Icon = n.icon;
                            return (
                                <Link key={n.href} href={n.href} onClick={() => setOpen(false)} aria-current={active ? 'page' : undefined} title={rail ? n.label : undefined} className={cn('relative flex min-h-9 items-center gap-3 rounded-lg text-sm transition-colors pointer-coarse:min-h-11', rail ? 'justify-center' : 'px-3', active ? 'bg-espresso font-medium text-ivory' : 'text-beige hover:bg-espresso/60 hover:text-ivory')}>
                                    {active && <span className="absolute inset-y-2 left-0 w-0.5 rounded-full bg-gold" aria-hidden />}
                                    <Icon size={17} strokeWidth={1.75} className={cn('shrink-0', active && 'text-gold')} aria-hidden />
                                    <span className={rail ? 'sr-only' : undefined}>{n.label}</span>
                                </Link>
                            );
                        })}
                    </div>
                </div>
            ))}
        </nav>
    );

    return (
        <div className="min-h-dvh lg:flex">
            <a href="#main" className="skip-link">Skip to main content</a>
            <Head title={title} />
            <aside className={cn('sticky top-0 hidden h-screen shrink-0 flex-col bg-chocolate transition-[width] duration-200 lg:flex', collapsed ? 'w-[4.25rem]' : 'w-64')}>
                <Link href="/admin" aria-label="UNIQA SUITES dashboard" className={cn('flex h-16 shrink-0 items-center border-b border-espresso', collapsed ? 'justify-center' : 'px-4')}>
                    <img src={collapsed ? '/brand/uniqa-monogram.png' : '/brand/uniqa-wordmark.png'} alt="" className={collapsed ? 'h-9 w-auto' : 'h-10 w-auto'} />
                </Link>
                {nav(collapsed)}
                <button type="button" onClick={toggleCollapsed} aria-label={collapsed ? 'Expand menu' : 'Collapse menu'} title={collapsed ? 'Expand menu' : undefined} className={cn('flex h-11 shrink-0 items-center gap-3 border-t border-espresso text-sm text-beige transition-colors hover:text-gold', collapsed ? 'justify-center' : 'px-6')}>
                    {collapsed ? <ChevronsRight size={17} aria-hidden /> : <><ChevronsLeft size={17} aria-hidden /> Collapse menu</>}
                </button>
            </aside>

            {open && (
                <div role="dialog" aria-modal="true" aria-label="Menu" className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-chocolate/60" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 flex w-72 max-w-[85%] flex-col bg-chocolate">
                        <div className="flex items-center justify-between border-b border-espresso px-3 py-2">
                            <img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-9 w-auto" />
                            <button ref={closeButton} aria-label="Close menu" className="flex size-11 items-center justify-center text-ivory" onClick={() => setOpen(false)}>
                                <X size={18} />
                            </button>
                        </div>
                        {nav(false)}
                    </aside>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-line bg-white/95 px-4 backdrop-blur sm:gap-3 sm:px-6 lg:h-16">
                    <button ref={phoneTabs.length < 2 ? menuButton : undefined} aria-label="Open menu" className={cn('-ml-2 size-11 items-center justify-center', phoneTabs.length < 2 ? 'flex lg:hidden' : 'hidden')} onClick={() => setOpen(true)}>
                        <Menu size={20} />
                    </button>
                    <QuickFind items={quickItems} searches={quickSearches} />
                    <div className="ml-auto flex items-center gap-1 sm:gap-2">
                        <Link href="/admin/account" aria-label={`Notifications, ${auth.user?.unread ?? 0} unread`} className="relative flex size-9 items-center justify-center rounded-lg transition-colors hover:bg-beige pointer-coarse:size-11">
                            <Bell size={18} strokeWidth={1.75} />
                            {auth.user?.unread > 0 && <span className="absolute top-0.5 right-0.5 min-w-4 rounded-full bg-red-800 px-1 text-center text-[10px] leading-4 font-semibold text-white">{auth.user.unread}</span>}
                        </Link>
                        <Link href="/admin/account" aria-label={`Your account, ${auth.user?.name ?? ''}`} className="flex items-center gap-2 rounded-lg px-1 py-1 transition-colors hover:bg-beige pointer-coarse:min-h-11 sm:px-2">
                            <span className="flex size-8 items-center justify-center rounded-full bg-chocolate text-xs font-semibold text-gold" aria-hidden>{initials}</span>
                            <span className="hidden text-left leading-tight lg:block">
                                <span className="block text-sm font-medium">{auth.user?.name}</span>
                                {role && <span className="block text-[11px] text-muted">{role}</span>}
                            </span>
                        </Link>
                        <button type="button" aria-label="Sign out" title="Sign out" onClick={() => router.post('/admin/logout')} className="flex size-9 items-center justify-center rounded-lg text-muted transition-colors hover:bg-beige hover:text-chocolate pointer-coarse:size-11">
                            <LogOut size={17} strokeWidth={1.75} />
                        </button>
                    </div>
                </header>

                <main id="main" tabIndex={-1} className={cn('mx-auto w-full max-w-[90rem] flex-1 p-4 outline-none sm:p-6 lg:p-8', phoneTabs.length >= 2 && 'pb-24 lg:pb-8')}>
                    <FlashMessage flash={flash} />
                    <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                        <div className="min-w-0">
                            {section && showSection && <p className="text-xs font-medium tracking-wide text-muted">{section}</p>}
                            <h1 className="text-2xl font-semibold tracking-tight">{heading ?? title}</h1>
                            {subtitle && <p className="mt-0.5 text-sm text-muted">{subtitle}</p>}
                        </div>
                        {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                    </div>
                    {children}
                </main>
            </div>

            {phoneTabs.length >= 2 && (
                <nav aria-label="Quick" className="fixed inset-x-0 bottom-0 z-30 grid auto-cols-fr grid-flow-col border-t border-line bg-white pb-[env(safe-area-inset-bottom)] lg:hidden">
                    {phoneTabs.map((t) => {
                        const active = isActive(t.href);
                        const Icon = t.icon;
                        return (
                            <Link key={t.href} href={t.href} aria-current={active ? 'page' : undefined} className={cn('flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px]', active ? 'font-semibold text-chocolate' : 'text-muted')}>
                                <Icon size={19} strokeWidth={active ? 2 : 1.75} className={active ? 'text-gold-dark' : undefined} aria-hidden />
                                {SHORT_LABELS[t.href]}
                            </Link>
                        );
                    })}
                    <button ref={phoneTabs.length >= 2 ? menuButton : undefined} type="button" aria-label="Open menu" onClick={() => setOpen(true)} className="flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] text-muted">
                        <Menu size={19} strokeWidth={1.75} aria-hidden />
                        Menu
                    </button>
                </nav>
            )}
        </div>
    );
}
