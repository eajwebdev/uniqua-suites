import { router } from '@inertiajs/react';
import { DateFilter, datesAreFree, Eyebrow, PAGE_WIDTH, RoomCard, useStayDates } from '@/components/public';
import { cn, Input, Select } from '@/components/ui';
import PublicLayout from '@/layouts/PublicLayout';

const FILTER_LABEL = 'flex flex-col gap-1.5 text-xs font-medium tracking-[0.14em] text-muted uppercase [&>*]:font-normal [&>*]:tracking-normal [&>*]:normal-case';

export default function Suites({ rooms, filters, properties, types }: any) {
    const stay = useStayDates();
    const shown = (stay.chosen ? rooms.filter((room: any) => datesAreFree(room, stay.checkIn, stay.checkOut)) : rooms) as any[];
    const apply = (changes: any) => {
        const next = Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false));
        router.get('/suites', next as any, { preserveScroll: true, preserveState: true, replace: true });
    };

    return (
        <PublicLayout title="Suites" description="Browse UNIQA SUITES studios, one-bedroom and two-bedroom suites in Cagayan de Oro, then book in a Messenger chat with our staff.">
            <section className="border-b border-line pt-16 pb-10 sm:pt-24">
                <div className={PAGE_WIDTH}>
                    <Eyebrow className="mb-3">The collection</Eyebrow>
                    <h1 className="font-serif text-5xl font-light sm:text-7xl">Our suites</h1>
                    <p className="mt-4 max-w-xl text-lg leading-relaxed text-muted">Find the suite you like and press Book Now to choose free dates. It then opens a Messenger chat, where our staff arrange your stay.</p>

                    <DateFilter stay={stay} className="mt-10" />
                    <div className="mt-5 flex flex-wrap items-end gap-x-6 gap-y-4">
                        <label className={FILTER_LABEL}>Location
                            <Select className="w-auto min-w-44" placeholder="Any" options={properties.map((p: any) => ({ value: p.slug, label: p.name }))} value={filters.location ?? ''} onChange={(e) => apply({ location: e.target.value })} />
                        </label>
                        <label className={FILTER_LABEL}>Type
                            <Select className="w-auto min-w-36" placeholder="Any" options={types.map((t: any) => ({ value: t.slug, label: t.name }))} value={filters.type ?? ''} onChange={(e) => apply({ type: e.target.value })} />
                        </label>
                        <label className={FILTER_LABEL}>Guests
                            <Input type="number" min={1} max={20} className="w-24" defaultValue={filters.guests ?? ''} onBlur={(e) => apply({ guests: e.target.value })} />
                        </label>
                        <label className={FILTER_LABEL}>Bathrooms
                            <Select className="w-auto min-w-24" placeholder="Any" options={[{ value: 1, label: '1+' }, { value: 2, label: '2+' }]} value={filters.bathrooms ?? ''} onChange={(e) => apply({ bathrooms: e.target.value })} />
                        </label>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={!!Number(filters.balcony)} onChange={(e) => apply({ balcony: e.target.checked ? 1 : undefined })} /> Balcony</label>
                        <span className="ml-auto self-center text-sm text-muted" aria-live="polite">{shown.length} suite{shown.length === 1 ? '' : 's'}{stay.chosen ? ' free' : ''}</span>
                    </div>
                </div>
            </section>

            <section className={cn(PAGE_WIDTH, 'py-14 sm:py-20')}>
                <h2 className="sr-only">Suites</h2>
                {shown.length === 0 ? (
                    <p className="border border-line p-10 text-center text-muted">{stay.chosen ? 'No suite is free for all of those dates. Please try other dates.' : 'No suites match these filters. Try fewer filters.'}</p>
                ) : (
                    <div className="grid gap-x-8 gap-y-14 sm:grid-cols-2 lg:grid-cols-3">{[...shown].reverse().map((r: any) => <RoomCard key={r.slug} room={r} checkIn={stay.chosen ? stay.checkIn : undefined} checkOut={stay.chosen ? stay.checkOut : undefined} />)}</div>
                )}
            </section>
        </PublicLayout>
    );
}
