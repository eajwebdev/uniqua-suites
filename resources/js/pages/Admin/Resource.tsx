import { Link, router, usePage } from '@inertiajs/react';
import { ColumnDef, flexRender, getCoreRowModel, getSortedRowModel, SortingState, useReactTable } from '@tanstack/react-table';
import { ArrowLeft, ArrowUpDown, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ActionButton, Button, buttonClass, Empty, FieldDef, FormModal, Input, Pagination, RowAction, Select, Stat, StatusChip, Tabs } from '@/components/ui';
import { dateOnly, dateTime, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

type Column = { key: string; label: string; type?: 'money' | 'date' | 'datetime' | 'status' | 'link' };
type CreateDef = { label: string; url: string; fields: FieldDef[]; confirm?: string };

type Props = {
    title: string;
    subtitle?: string;
    note?: string | null;
    backHref?: string;
    tabs?: { label: string; href: string }[];
    columns: Column[];
    rows: any[] | { data: any[]; links: any[] };
    create?: CreateDef | null;
    secondaryCreate?: CreateDef | null;
    headerLink?: { label: string; href: string };
    search?: { value: string | null; placeholder: string; param?: string };
    filters?: { name: string; label: string; value: any; options: { value: any; label: string }[] }[];
    stats?: { label: string; value: number; type?: 'money' }[];
    dateRange?: { from: string; to: string };
};

function Cell({ column, value }: { column: Column; value: any }) {
    switch (column.type) {
        case 'money':
            return <span className="tabular-nums">{peso(value)}</span>;
        case 'date':
            return <>{dateOnly(value)}</>;
        case 'datetime':
            return <>{dateTime(value)}</>;
        case 'status':
            return <StatusChip value={value} />;
        case 'link':
            if (!value?.href) return <span className="text-muted">{value?.label ?? '—'}</span>;
            return value.external ? (
                <a href={value.href} target="_blank" rel="noreferrer" className="inline-block font-medium underline pointer-coarse:py-3">{value.label}</a>
            ) : (
                <Link href={value.href} className="inline-block font-medium underline pointer-coarse:py-3">{value.label}</Link>
            );
        default:
            return <span className="break-words">{value ?? '—'}</span>;
    }
}

export default function Resource(props: Props) {
    const path = usePage().url.split('?')[0];
    const [modal, setModal] = useState<CreateDef | null>(null);
    const [sorting, setSorting] = useState<SortingState>([]);
    const [term, setTerm] = useState(props.search?.value ?? '');
    const rows = Array.isArray(props.rows) ? props.rows : props.rows.data;
    const links = Array.isArray(props.rows) ? undefined : props.rows.links;
    const hasActions = rows.some((r) => r._actions?.length);

    const columns = useMemo<ColumnDef<any>[]>(() => {
        const defs: ColumnDef<any>[] = props.columns.map((c) => ({
            id: c.key,
            accessorFn: (r) => (c.type === 'link' ? r[c.key]?.label : r[c.key]),
            header: c.label,
            cell: (info) => <Cell column={c} value={info.row.original[c.key]} />,
        }));
        if (hasActions) {
            defs.push({
                id: '_actions',
                header: () => <span className="sr-only">Actions</span>,
                enableSorting: false,
                cell: (info) => (
                    <div className="flex justify-end gap-1.5">
                        {(info.row.original._actions as RowAction[] | undefined)?.map((a, i) => <ActionButton key={i} action={a} />)}
                    </div>
                ),
            });
        }
        return defs;
    }, [props.columns, hasActions]);

    const table = useReactTable({ data: rows, columns, state: { sorting }, onSortingChange: setSorting, getCoreRowModel: getCoreRowModel(), getSortedRowModel: getSortedRowModel() });

    function applyFilter(name: string, value: string) {
        router.get(path, { ...Object.fromEntries(new URLSearchParams(window.location.search)), [name]: value || undefined, page: undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }

    const actions = (
        <>
            {props.secondaryCreate && <Button onClick={() => setModal(props.secondaryCreate!)}>{props.secondaryCreate.label}</Button>}
            {props.create && <Button variant="primary" onClick={() => setModal(props.create!)}>{props.create.label}</Button>}
            {props.headerLink && <Link href={props.headerLink.href} className={buttonClass('primary')}>{props.headerLink.label}</Link>}
        </>
    );

    return (
        <AdminLayout title={props.title} actions={props.create || props.secondaryCreate || props.headerLink ? actions : undefined}>
            {props.backHref && (
                <Link href={props.backHref} className="mb-3 inline-flex items-center gap-1 text-sm text-muted">
                    <ArrowLeft size={14} /> Back
                </Link>
            )}
            <Tabs tabs={props.tabs} current={path} />
            {props.subtitle && <p className="mb-4 max-w-3xl text-sm text-muted">{props.subtitle}</p>}
            {props.note && <p className="mb-4 rounded-lg bg-beige px-3 py-2 text-sm">{props.note}</p>}

            {props.stats && (
                <div className="mb-4 grid gap-3 sm:grid-cols-3">
                    {props.stats.map((s) => <Stat key={s.label} label={s.label} value={s.type === 'money' ? peso(s.value) : s.value} />)}
                </div>
            )}

            {(props.search || props.filters || props.dateRange) && (
                <div className="mb-4 flex flex-wrap items-end gap-2">
                    {props.search && (
                        <form onSubmit={(e) => { e.preventDefault(); applyFilter(props.search?.param ?? 'q', term); }} className="relative min-w-52 flex-1 sm:max-w-sm">
                            <Search size={15} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" aria-hidden />
                            <Input type="search" aria-label="Search" placeholder={props.search.placeholder} className="w-full pl-9" value={term} onChange={(e) => setTerm(e.target.value)} />
                        </form>
                    )}
                    {props.filters?.map((f) => (
                        <Select key={f.name} aria-label={f.label} className="w-auto" placeholder={`All: ${f.label}`} options={f.options} value={f.value ?? ''} onChange={(e) => applyFilter(f.name, e.target.value)} />
                    ))}
                    {props.dateRange && (
                        <>
                            <Input aria-label="From" type="date" className="w-auto" defaultValue={props.dateRange.from} onChange={(e) => e.target.value && applyFilter('from', e.target.value)} />
                            <Input aria-label="To" type="date" className="w-auto" defaultValue={props.dateRange.to} onChange={(e) => e.target.value && applyFilter('to', e.target.value)} />
                        </>
                    )}
                </div>
            )}

            <div className="relative overflow-x-auto rounded-xl border border-line/80 bg-white shadow-xs">
                {rows.length === 0 ? (
                    <Empty>Nothing here yet.</Empty>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="bg-ivory/70 text-xs text-muted">
                            {table.getHeaderGroups().map((hg) => (
                                <tr key={hg.id}>
                                    {hg.headers.map((h) => (
                                        <th key={h.id} scope="col" aria-sort={h.column.getIsSorted() === 'asc' ? 'ascending' : h.column.getIsSorted() === 'desc' ? 'descending' : undefined} className="px-4 py-2.5 font-medium whitespace-nowrap">
                                            {h.column.getCanSort() ? (
                                                <button className="inline-flex items-center gap-1 pointer-coarse:min-h-11" onClick={h.column.getToggleSortingHandler()}>
                                                    {flexRender(h.column.columnDef.header, h.getContext())} <ArrowUpDown size={11} aria-hidden />
                                                </button>
                                            ) : (
                                                flexRender(h.column.columnDef.header, h.getContext())
                                            )}
                                        </th>
                                    ))}
                                </tr>
                            ))}
                        </thead>
                        <tbody>
                            {table.getRowModel().rows.map((row) => (
                                <tr key={row.id} className="border-t border-line/70 align-top transition-colors hover:bg-ivory/60">
                                    {row.getVisibleCells().map((cell) => (
                                        <td key={cell.id} className="max-w-xs px-4 py-2.5">{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
            <Pagination links={links} />

            {modal && <FormModal title={modal.label} url={modal.url} fields={modal.fields} confirm={modal.confirm} onClose={() => setModal(null)} />}
        </AdminLayout>
    );
}
