import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bell, BookOpen, Building2, CalendarDays, Car, ClipboardList, Coins, FileSpreadsheet, HandCoins, LayoutDashboard, LogOut, Menu, MessageSquareText, Package,
    ScrollText, Settings, Sparkles, Users, UsersRound, Wallet, Wrench, BarChart3, X,
} from 'lucide-react';
import { ReactNode, useState } from 'react';
import { Button, cn } from '@/components/ui';

const NAV: { label: string; href: string; icon: any; any: string[] }[] = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard, any: ['dashboard.view'] },
    { label: 'Booking Calendar', href: '/admin/calendar', icon: CalendarDays, any: ['bookings.view'] },
    { label: 'Reservations', href: '/admin/bookings', icon: ClipboardList, any: ['bookings.view'] },
    { label: 'Guests', href: '/admin/guests', icon: Users, any: ['guests.view'] },
    { label: 'Quotes & Messages', href: '/admin/quotes', icon: MessageSquareText, any: ['quotes.manage'] },
    { label: 'Rooms & Properties', href: '/admin/rooms', icon: Building2, any: ['rooms.view'] },
    { label: 'Payments', href: '/admin/payments', icon: Wallet, any: ['payments.view'] },
    { label: 'Expenses & Cashbook', href: '/admin/expenses', icon: Coins, any: ['expenses.view'] },
    { label: 'Reports', href: '/admin/reports', icon: BarChart3, any: ['reports.view'] },
    { label: 'Housekeeping', href: '/admin/housekeeping', icon: Sparkles, any: ['housekeeping.own', 'housekeeping.manage'] },
    { label: 'Maintenance & Damage', href: '/admin/work-orders', icon: Wrench, any: ['maintenance.own', 'maintenance.manage'] },
    { label: 'Damage Claims', href: '/admin/incidents', icon: BookOpen, any: ['__incidents_only'] },
    { label: 'Inventory', href: '/admin/inventory', icon: Package, any: ['inventory.view'] },
    { label: 'Parking', href: '/admin/parking', icon: Car, any: ['parking.view'] },
    { label: 'Staff Advances', href: '/admin/advances', icon: HandCoins, any: ['advances.manage'] },
    { label: 'Import & Data Quality', href: '/admin/imports', icon: FileSpreadsheet, any: ['imports.manage'] },
    { label: 'Team & Permissions', href: '/admin/team', icon: UsersRound, any: ['team.manage'] },
    { label: 'Settings', href: '/admin/settings', icon: Settings, any: ['settings.manage'] },
    { label: 'Audit Log', href: '/admin/audit', icon: ScrollText, any: ['audit.view'] },
];

export default function AdminLayout({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
    const { auth, flash, url } = usePage<any>().props as any;
    const path = usePage().url.split('?')[0];
    const [open, setOpen] = useState(false);
    const perms: string[] = auth.user?.permissions ?? [];
    const can = (p: string) => perms.includes(p);

    const items = NAV.filter((n) => {
        if (n.any[0] === '__incidents_only') return can('incidents.view') && !can('maintenance.own') && !can('maintenance.manage');
        return n.any.some(can);
    });

    const sidebar = (
        <nav aria-label="Main" className="flex-1 space-y-0.5 overflow-y-auto p-2">
            {items.map((n) => {
                const active = n.href === '/admin' ? path === '/admin' : path.startsWith(n.href);
                const Icon = n.icon;
                return (
                    <Link key={n.href} href={n.href} onClick={() => setOpen(false)} aria-current={active ? 'page' : undefined} className={cn('flex items-center gap-2.5 rounded-md px-3 py-2 text-sm', active ? 'bg-espresso font-medium text-ivory' : 'text-beige hover:bg-espresso/60')}>
                        <Icon size={16} aria-hidden /> {n.label}
                    </Link>
                );
            })}
        </nav>
    );

    return (
        <div className="min-h-screen lg:flex">
            <Head title={title} />
            <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col bg-chocolate lg:flex">
                <Link href="/admin" className="block border-b border-espresso px-4 py-3">
                    <img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-10 w-auto" />
                </Link>
                {sidebar}
            </aside>

            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-chocolate/60" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 flex w-64 flex-col bg-chocolate">
                        <div className="flex items-center justify-between border-b border-espresso px-3 py-2">
                            <img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-9 w-auto" />
                            <button aria-label="Close menu" className="p-2 text-ivory" onClick={() => setOpen(false)}>
                                <X size={18} />
                            </button>
                        </div>
                        {sidebar}
                    </aside>
                </div>
            )}

            <div className="min-w-0 flex-1">
                <header className="sticky top-0 z-30 flex items-center gap-2 border-b border-line bg-white px-4 py-2.5">
                    <button aria-label="Open menu" className="p-1.5 lg:hidden" onClick={() => setOpen(true)}>
                        <Menu size={20} />
                    </button>
                    <h1 className="min-w-0 flex-1 truncate font-semibold">{title}</h1>
                    <Link href="/admin/account" aria-label={`Account and notifications, ${auth.user?.unread ?? 0} unread`} className="relative rounded-md p-1.5 hover:bg-beige">
                        <Bell size={18} />
                        {auth.user?.unread > 0 && <span className="absolute -top-0.5 -right-0.5 rounded-full bg-red-800 px-1 text-[10px] leading-4 text-white">{auth.user.unread}</span>}
                    </Link>
                    <span className="hidden text-sm text-muted sm:inline">{auth.user?.name}</span>
                    <Button small variant="ghost" aria-label="Sign out" onClick={() => router.post('/admin/logout')}>
                        <LogOut size={16} />
                    </Button>
                </header>

                <main className="mx-auto max-w-7xl p-4 sm:p-6">
                    {flash?.success && <div role="status" className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900">{flash.success}</div>}
                    {flash?.error && <div role="alert" className="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-900">{flash.error}</div>}
                    {actions && <div className="mb-4 flex flex-wrap justify-end gap-2">{actions}</div>}
                    {children}
                </main>
            </div>
        </div>
    );
}
