import { router } from '@inertiajs/react';
import { RoomCard, SearchBar } from '@/components/public';
import { Input, Select } from '@/components/ui';
import PublicLayout from '@/layouts/PublicLayout';

export default function Suites({ rooms, filters, properties, types }: any) {
    const apply = (changes: any) => {
        const next = Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false));
        router.get('/suites', next as any, { preserveScroll: true, preserveState: true, replace: true });
    };
    const stay = new URLSearchParams(Object.entries({ check_in: filters.check_in, check_out: filters.check_out, guests: filters.guests }).filter(([, v]) => v) as any).toString();
    const hasDates = filters.check_in && filters.check_out;

    return (
        <PublicLayout title="Suites" description="Browse UNIQA SUITES studios, one-bedroom and two-bedroom suites in Cagayan de Oro. Filter by location, guests, balcony and budget, and see estimated rates.">
            <section className="bg-chocolate">
                <div className="mx-auto max-w-6xl px-4 py-6">
                    <h1 className="mb-4 font-serif text-3xl font-medium text-ivory">Find your suite</h1>
                    <SearchBar filters={filters} properties={properties} types={types} />
                </div>
            </section>
            <section className="mx-auto max-w-6xl px-4 py-8">
                <div className="mb-5 flex flex-wrap items-center gap-4 text-sm">
                    <label className="flex items-center gap-2"><input type="checkbox" checked={!!Number(filters.balcony)} onChange={(e) => apply({ balcony: e.target.checked ? 1 : undefined })} /> Balcony</label>
                    <label className="flex items-center gap-2">Bathrooms
                        <Select aria-label="Bathrooms" className="w-auto py-1" placeholder="Any" options={[{ value: 1, label: '1+' }, { value: 2, label: '2+' }]} value={filters.bathrooms ?? ''} onChange={(e) => apply({ bathrooms: e.target.value })} />
                    </label>
                    <label className="flex items-center gap-2">Weekday rate up to ₱
                        <Input aria-label="Maximum weekday rate" type="number" min={0} step={100} className="w-28 py-1" defaultValue={filters.max_price ?? ''} onBlur={(e) => apply({ max_price: e.target.value })} />
                    </label>
                    <span className="ml-auto text-muted" aria-live="polite">{rooms.length} suite{rooms.length === 1 ? '' : 's'}</span>
                </div>
                {hasDates && <p className="mb-5 rounded-md bg-beige px-3 py-2 text-sm">Availability shown is indicative. Some bookings are made on other channels, so please confirm with us. Nothing is reserved until our staff confirm it.</p>}
                {rooms.length === 0 ? (
                    <p className="rounded-lg border border-line bg-white p-8 text-center text-muted">No suites match these filters. Try fewer filters or different dates.</p>
                ) : (
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{rooms.map((r: any) => <RoomCard key={r.slug} room={r} query={stay} />)}</div>
                )}
            </section>
        </PublicLayout>
    );
}
