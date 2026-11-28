import { Link, router } from '@inertiajs/react';
import { BedDouble, Camera, CalendarDays, ChevronRight, Clock, Eye, EyeOff, ImageOff, LogIn, LogOut, Pencil, Search, ShieldCheck, Users, Wrench, X } from 'lucide-react';
import { ReactNode, useEffect, useMemo, useState } from 'react';
import { ActionButton, buttonClass, cn, confirmDialog, Empty, Modal, StatusChip } from '@/components/ui';
import { pesoWhole, shortDate, time12 } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

type Stay = { booking_id: number; reference: string; guest: string; check_out: string; return_at: string | null };
type Next = { booking_id: number; reference: string; guest: string; check_in: string };
type Room = {
    id: number; name: string; unit: string; property: string; type: string; photo: string | null; photos: number; specs: string | null;
    included_guests: number; max_guests: number; weekday_cents: number | null; weekend_cents: number | null;
    catalog: string; published: boolean; housekeeping: string; operational: string;
    state: 'occupied' | 'arriving' | 'vacant' | 'maintenance' | 'blocked'; stay: Stay | null; next: Next | null;
};
type Can = { manage: boolean; block: boolean; bookings: boolean };

const todayInManila = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());

const STATE_TONE: Record<Room['state'], string> = {
    occupied: 'bg-sky-50 text-sky-900 border-sky-200',
    arriving: 'bg-amber-50 text-amber-900 border-amber-200',
    vacant: 'bg-emerald-50 text-emerald-900 border-emerald-200',
    maintenance: 'bg-red-50 text-red-900 border-red-200',
    blocked: 'bg-red-50 text-red-900 border-red-200',
};

function stateLabel(state: Room['state'], cameras: boolean) {
    return {
        occupied: cameras ? 'Out on rental' : 'Occupied',
        arriving: cameras ? 'Pickup today' : 'Arriving today',
        vacant: cameras ? 'Available' : 'Vacant',
        maintenance: 'Maintenance',
        blocked: 'Blocked today',
    }[state];
}

type FilterKey = 'all' | 'occupied' | 'leaving' | 'arriving' | 'vacant' | 'cleaning' | 'hidden' | 'review' | 'maintenance';

const FILTER_KEY = 'uniqa.rooms.filter';

function readFilter(): FilterKey {
    try {
        return (sessionStorage.getItem(FILTER_KEY) as FilterKey) || 'all';
    } catch {
        return 'all';
    }
}

/** When the guest leaves (or the camera is due back), and whether that is today. */
function leaving(room: Room, checkOutTime: string | null, today: string): { label: string; today: boolean } | null {
    if (!room.stay) return null;
    if (room.stay.return_at) {
        const [date, time] = room.stay.return_at.split(' ');
        return { label: `${date === today ? 'today' : shortDate(date)} ${time12(time)}`, today: date <= today };
    }
    const date = room.stay.check_out;
    return { label: `${date === today ? 'today' : shortDate(date)}${checkOutTime ? ` ${time12(checkOutTime)}` : ''}`, today: date <= today };
}

function Chip({ children, className }: { children: ReactNode; className?: string }) {
    return <span className={cn('inline-flex items-center gap-1 rounded-full border border-line bg-white px-2 py-0.5 text-xs whitespace-nowrap text-espresso', className)}>{children}</span>;
}

const blockFields = [
    { name: 'start_date', label: 'From', type: 'date' as const, required: true },
    { name: 'end_date', label: 'To (the day it is free again)', type: 'date' as const, required: true },
    { name: 'kind', label: 'Why', type: 'select' as const, required: true, options: [{ value: 'repair', label: 'Repair' }, { value: 'owner_use', label: 'Owner use' }, { value: 'technical', label: 'Technical downtime' }, { value: 'third_party', label: 'Unavailable third-party inventory' }] },
    { name: 'reason', label: 'Note', type: 'text' as const, required: true },
];

/** Everything else for one room, in big buttons: made for a thumb. */
function ManageSheet({ room, cameras, can, onClose }: { room: Room; cameras: boolean; can: Can; onClose: () => void }) {
    const post = (url: string, data: Record<string, any> = {}) => router.post(url, data, { preserveScroll: true, onSuccess: onClose });
    const row = 'flex min-h-12 w-full items-center gap-3 rounded-lg border border-line px-4 text-left text-sm font-medium transition-colors hover:bg-beige/60 active:bg-beige';

    return (
        <Modal open title={`${room.name}${room.unit ? ` · ${room.unit}` : ''}`} onClose={onClose}>
            <div className="space-y-2">
                {can.manage && (
                    <Link href={`/admin/rooms/${room.id}/edit`} className={row}><Pencil size={17} aria-hidden /> Edit details, {cameras ? 'photos and notes' : 'rates, photos and Wi-Fi'}<ChevronRight size={16} className="ml-auto text-muted" aria-hidden /></Link>
                )}
                {room.stay && can.bookings && (
                    <Link href={`/admin/bookings/${room.stay.booking_id}`} className={row}><LogOut size={17} aria-hidden /> Open current {cameras ? 'rental' : 'stay'} ({room.stay.reference})<ChevronRight size={16} className="ml-auto text-muted" aria-hidden /></Link>
                )}
                {room.next && can.bookings && (
                    <Link href={`/admin/bookings/${room.next.booking_id}`} className={row}><LogIn size={17} aria-hidden /> Open next {cameras ? 'rental' : 'arrival'} ({room.next.reference})<ChevronRight size={16} className="ml-auto text-muted" aria-hidden /></Link>
                )}
                {can.bookings && (
                    <Link href="/admin/calendar" className={row}><CalendarDays size={17} aria-hidden /> Booking calendar<ChevronRight size={16} className="ml-auto text-muted" aria-hidden /></Link>
                )}
                {can.manage && !cameras && room.catalog !== 'approved' && (
                    <button type="button" className={row} onClick={async () => { if (await confirmDialog({ title: `Approve ${room.name}?`, message: 'Its details and rates become the current ones, and it can be published.', confirmLabel: 'Approve' })) post(`/admin/rooms/${room.id}/approve`); }}>
                        <ShieldCheck size={17} aria-hidden /> Approve details and rates
                    </button>
                )}
                {can.manage && (
                    <button type="button" className={row} onClick={() => post(`/admin/rooms/${room.id}/publish`)}>
                        {room.published ? <><EyeOff size={17} aria-hidden /> Hide from the public website</> : <><Eye size={17} aria-hidden /> Show on the public website</>}
                    </button>
                )}
                {can.manage && room.operational !== 'maintenance' && (
                    <button type="button" className={row} onClick={async () => { if (await confirmDialog({ title: `Put ${room.name} in maintenance?`, message: `It cannot be booked until it is back in service.${room.stay ? ' The current guest is not affected.' : ''}`, confirmLabel: 'Put in maintenance', danger: true })) post(`/admin/rooms/${room.id}/status`, { operational_status: 'maintenance' }); }}>
                        <Wrench size={17} aria-hidden /> Put in maintenance
                    </button>
                )}
                {can.manage && room.operational !== 'active' && (
                    <button type="button" className={row} onClick={() => post(`/admin/rooms/${room.id}/status`, { operational_status: 'active' })}>
                        <ShieldCheck size={17} aria-hidden /> Back in service
                    </button>
                )}
            </div>
        </Modal>
    );
}

function RoomCard({ room, cameras, can, checkOutTime, today, onManage }: { room: Room; cameras: boolean; can: Can; checkOutTime: string | null; today: string; onManage: () => void }) {
    const out = leaving(room, checkOutTime, today);
    const Icon = cameras ? Camera : BedDouble;

    return (
        <article className="flex flex-col rounded-xl border border-line/80 bg-white shadow-xs">
            <div className="flex gap-3 p-3 sm:p-4">
                <div className="size-16 shrink-0 overflow-hidden rounded-lg bg-beige">
                    {room.photo ? <img src={room.photo} alt="" loading="lazy" className="size-full object-cover" /> : <span className="flex size-full items-center justify-center text-muted"><Icon size={22} strokeWidth={1.5} aria-hidden /></span>}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <h3 className="min-w-0 truncate text-base leading-tight font-semibold">
                            {can.manage ? <Link href={`/admin/rooms/${room.id}/edit`} className="hover:underline">{room.name}</Link> : room.name}
                            {room.unit && <span className="ml-1.5 text-xs font-normal text-muted">{room.unit}</span>}
                        </h3>
                        <span className={cn('shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap', STATE_TONE[room.state])}>{stateLabel(room.state, cameras)}</span>
                    </div>
                    <p className="truncate text-xs text-muted">{cameras ? room.type : `${room.property} · ${room.type}`}</p>

                    {room.stay ? (
                        <p className={cn('mt-1.5 flex items-center gap-1.5 text-sm', out?.today ? 'font-medium text-amber-900' : 'text-espresso')}>
                            <Clock size={14} className="shrink-0" aria-hidden />
                            <span className="min-w-0 truncate">{cameras ? 'Due back' : 'Checks out'} {out?.label} · {room.stay.guest}</span>
                        </p>
                    ) : room.next ? (
                        <p className={cn('mt-1.5 flex items-center gap-1.5 text-sm', room.next.check_in === today ? 'font-medium text-amber-900' : 'text-muted')}>
                            <LogIn size={14} className="shrink-0" aria-hidden />
                            <span className="min-w-0 truncate">{room.next.check_in === today ? 'Today' : `Next ${shortDate(room.next.check_in)}`} · {room.next.guest}</span>
                        </p>
                    ) : null}
                </div>
            </div>

            <div className="flex flex-wrap gap-1.5 px-3 pb-3 sm:px-4">
                {!cameras && <StatusChip value={room.housekeeping} label={room.housekeeping === 'ready' ? 'Clean' : undefined} />}
                {!room.published && <StatusChip value="unpublished" label="Not public" />}
                {!cameras && room.catalog !== 'approved' && <StatusChip value="proposed" label="Needs approval" />}
                {room.operational === 'retired' && <StatusChip value="closed" label="Retired" />}
                {cameras ? (
                    <>
                        {room.specs && <Chip>{room.specs}</Chip>}
                        <Chip className={room.photos ? undefined : 'text-muted'}>{room.photos ? `${room.photos} photo${room.photos === 1 ? '' : 's'}` : <><ImageOff size={12} aria-hidden /> No photos</>}</Chip>
                    </>
                ) : (
                    <>
                        <Chip><Users size={12} aria-hidden /> {room.included_guests}–{room.max_guests}</Chip>
                        {room.weekday_cents !== null && <Chip>{pesoWhole(room.weekday_cents)}{room.weekend_cents !== null && room.weekend_cents !== room.weekday_cents ? ` · ${pesoWhole(room.weekend_cents)} wknd` : ''}</Chip>}
                    </>
                )}
            </div>

            <div className="mt-auto grid grid-cols-2 gap-px border-t border-line/80 bg-line/80">
                {can.block ? (
                    <ActionButton className="w-full rounded-none rounded-bl-xl border-0 bg-white hover:bg-beige/60" small={false} action={{ label: 'Block dates', url: `/admin/rooms/${room.id}/blocks`, fields: blockFields }} />
                ) : <span className="bg-white" />}
                <button type="button" onClick={onManage} className={cn(buttonClass('secondary'), 'w-full rounded-none rounded-br-xl border-0 bg-white hover:bg-beige/60')}>Manage <ChevronRight size={15} aria-hidden /></button>
            </div>
        </article>
    );
}

export default function Rooms({ business, rooms, can, checkOutTime, create }: { business: 'suites' | 'cameras'; rooms: Room[]; can: Can; checkOutTime: string | null; create: any }) {
    const cameras = business === 'cameras';
    const today = todayInManila();
    const [filter, setFilter] = useState<FilterKey>(readFilter);
    const [property, setProperty] = useState('');
    const [query, setQuery] = useState('');
    const [managing, setManaging] = useState<number | null>(null);

    useEffect(() => {
        try {
            sessionStorage.setItem(FILTER_KEY, filter);
        } catch {
            // Only remembers the filter for the next visit.
        }
    }, [filter]);

    const tests: Record<FilterKey, (r: Room) => boolean> = {
        all: () => true,
        occupied: (r) => r.state === 'occupied',
        leaving: (r) => !!r.stay && !!leaving(r, checkOutTime, today)?.today,
        arriving: (r) => r.state === 'arriving',
        vacant: (r) => r.state === 'vacant',
        cleaning: (r) => !cameras && r.housekeeping !== 'ready',
        hidden: (r) => !r.published,
        review: (r) => !cameras && r.catalog !== 'approved',
        maintenance: (r) => r.state === 'maintenance' || r.state === 'blocked' || r.operational === 'retired',
    };
    const filters: [FilterKey, string][] = [
        ['all', 'All'],
        ['occupied', cameras ? 'Out' : 'Occupied'],
        ['leaving', cameras ? 'Due back today' : 'Checking out today'],
        ['arriving', cameras ? 'Pickups today' : 'Arriving today'],
        ['vacant', cameras ? 'Available' : 'Vacant'],
        ...(!cameras ? [['cleaning', 'Needs cleaning'] as [FilterKey, string]] : []),
        ['maintenance', 'Maintenance'],
        ['hidden', 'Not public'],
        ...(!cameras ? [['review', 'Needs approval'] as [FilterKey, string]] : []),
    ];
    const properties = useMemo(() => [...new Set(rooms.map((r) => r.property))], [rooms]);

    const q = query.trim().toLowerCase();
    const inScope = rooms.filter((r) => (!property || r.property === property) && (!q || [r.name, r.unit, r.type, r.specs, r.stay?.guest, r.next?.guest].some((v) => v?.toLowerCase().includes(q))));
    const shown = inScope.filter(tests[filter]);
    const groups = cameras || property ? [[property || '', shown]] as [string, Room[]][] : properties.map((p) => [p, shown.filter((r) => r.property === p)] as [string, Room[]]).filter(([, list]) => list.length > 0);
    const managed = rooms.find((r) => r.id === managing) ?? null;

    return (
        <AdminLayout
            title={cameras ? 'Cameras' : 'Rooms & Properties'}
            subtitle={cameras ? 'Each physical camera is a unit. Rates are per model, under Camera Rates.' : 'What every suite is doing today. Tap Manage for everything else.'}
            actions={cameras ? <>
                <Link href="/admin/camera-rates" className={buttonClass('secondary', true)}>Camera Rates</Link>
                {create && <ActionButton action={{ label: create.label, url: create.url, fields: create.fields, variant: 'primary' }} />}
            </> : undefined}
        >
            <div className="sticky top-14 z-20 -mx-4 mb-4 space-y-2 border-b border-line/70 bg-ivory/95 px-4 pt-1 pb-3 backdrop-blur sm:-mx-6 sm:px-6 lg:static lg:top-auto lg:mx-0 lg:border-0 lg:bg-transparent lg:px-0 lg:pt-0 lg:pb-0 lg:backdrop-blur-none">
                <div className="flex gap-2">
                    <label className="relative min-w-0 flex-1">
                        <span className="sr-only">Search</span>
                        <Search size={16} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" aria-hidden />
                        <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={cameras ? 'Camera, unit or renter' : 'Suite, unit or guest'} className="h-11 w-full rounded-lg border border-line bg-white pr-9 pl-9 text-base sm:text-sm" />
                        {query && <button type="button" aria-label="Clear search" onClick={() => setQuery('')} className="absolute top-1/2 right-1 flex size-9 -translate-y-1/2 items-center justify-center text-muted"><X size={15} aria-hidden /></button>}
                    </label>
                    {!cameras && properties.length > 1 && (
                        <select aria-label="Location" value={property} onChange={(e) => setProperty(e.target.value)} className="h-11 max-w-[40%] rounded-lg border border-line bg-white px-2 text-base sm:text-sm">
                            <option value="">All locations</option>
                            {properties.map((p) => <option key={p} value={p}>{p}</option>)}
                        </select>
                    )}
                </div>
                <div role="group" aria-label="Show" className="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-0.5 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:px-0">
                    {filters.map(([key, label]) => {
                        const count = inScope.filter(tests[key]).length;
                        if (key !== 'all' && count === 0 && filter !== key) return null;
                        const on = filter === key;

                        return (
                            <button key={key} type="button" aria-pressed={on} onClick={() => setFilter(key)} className={cn('flex min-h-9 shrink-0 items-center gap-1.5 rounded-full border px-3 text-sm whitespace-nowrap transition-colors pointer-coarse:min-h-10', on ? 'border-chocolate bg-chocolate text-ivory' : 'border-line bg-white text-espresso hover:border-gold-dark')}>
                                {label}<span className={cn('text-xs tabular-nums', on ? 'text-gold' : 'text-muted')}>{count}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {shown.length === 0 ? (
                <Empty>{rooms.length === 0 ? (cameras ? 'No cameras yet.' : 'No suites yet.') : 'Nothing matches. Try another filter or search.'}</Empty>
            ) : (
                <div className="space-y-6">
                    {groups.map(([name, list]) => (
                        <section key={name || 'all'} aria-label={name || undefined}>
                            {name && groups.length > 1 && <h2 className="mb-2 text-xs font-medium tracking-wider text-muted uppercase">{name} <span className="tabular-nums">· {list.length}</span></h2>}
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {list.map((room) => <RoomCard key={room.id} room={room} cameras={cameras} can={can} checkOutTime={checkOutTime} today={today} onManage={() => setManaging(room.id)} />)}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            {managed && <ManageSheet room={managed} cameras={cameras} can={can} onClose={() => setManaging(null)} />}
            {!can.manage && !can.block && <p className="mt-6 text-xs text-muted">You can view rooms here. Ask a manager to change them.</p>}
        </AdminLayout>
    );
}
