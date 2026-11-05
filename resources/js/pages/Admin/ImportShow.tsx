import { router } from '@inertiajs/react';
import { ActionButton, Card, ClientPager, Empty, Pagination, Select, Stat, StatusChip, tableClass, tdClass, thClass, theadClass, trClass, usePaged } from '@/components/ui';
import { dateOnly, humanize, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function ImportShow({ batch, summary, future, counts, categories, blockAnomalies, monthly, mappings, rooms, rows, filters, sheets }: any) {
    const go = (changes: any) => router.get(`/admin/imports/${batch.id}`, { ...filters, ...changes, page: undefined }, { preserveState: true, preserveScroll: true, replace: true });
    const roomOptions = rooms.map((r: any) => ({ value: r.id, label: `${r.name} (${r.unit_code})` }));
    const totals = usePaged((summary?.block_totals ?? []) as any[], { size: 10, id: 'totals' });
    const sales = usePaged(monthly as any[], { size: 10, id: 'sales' });

    return (
        <AdminLayout
            title={batch.file_name}
            actions={
                <>
                    {batch.status === 'staged' && <ActionButton small={false} action={{ label: 'Commit clean rows', url: `/admin/imports/${batch.id}/commit`, variant: 'primary', confirm: 'Accept unflagged rows as Unverified Historical? Bookings and live reports are not affected.' }} />}
                    {batch.status !== 'rolled_back' && <ActionButton small={false} action={{ label: 'Roll back', url: `/admin/imports/${batch.id}/rollback`, variant: 'danger', confirm: "Remove this batch's staged rows?" }} />}
                </>
            }
        >
            <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <StatusChip value={batch.status} />
                <span className="text-muted">SHA-256 {batch.checksum.slice(0, 16)}… · {batch.transformation_version}</span>
            </div>
            <p className="mb-4 max-w-3xl rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">All amounts here are <strong>Provisional / Unreconciled</strong>. They come from sheet cells without proof of receipt. They appear only in the <strong>Sales recording</strong> report, labelled unverified, and never feed bookings, receipts or verified totals.</p>

            {batch.status !== 'rolled_back' && future.nights > 0 && (
                <div className={`mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm ${future.unmatched > 0 || future.held === 0 ? 'border-red-200 bg-red-50 text-red-950' : 'border-emerald-200 bg-emerald-50 text-emerald-950'}`}>
                    <div className="min-w-0">
                        <p className="font-semibold">Future stays in this workbook: {future.nights} night(s)</p>
                        <p>{future.held === 0 ? 'Not held yet, so guests and staff can still see these dates as free.' : `${future.held} stay(s) are held on the calendar.`}{future.unmatched > 0 && ` ${future.unmatched} night(s) cannot be held until their room label is matched below.`}</p>
                    </div>
                    <ActionButton small={false} action={{ label: 'Hold future dates', url: `/admin/imports/${batch.id}/hold-future`, variant: 'primary', confirm: 'Block these dates on the calendar and website? No bookings are created. Staff still enter each real booking.' }} />
                </div>
            )}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label="Rows staged" value={summary?.rows ?? 0} />
                <Stat label="Pending review" value={counts.pending} />
                <Stat label="Accepted" value={counts.approved} />
                <Stat label="Rejected" value={counts.rejected} />
                <Stat label="No room link" value={counts.unlinked} />
            </div>
            {summary?.duplicates > 0 && <p className="mb-4 text-sm text-muted">{summary.duplicates} row(s) were skipped because they already exist from an earlier upload.</p>}

            <div className="mb-4 grid gap-4 lg:grid-cols-2">
                <Card title="Room label decisions" action={<span className="text-xs text-muted">Resolve once; applies to every row</span>}>
                    <div className="max-h-80 overflow-y-auto">
                        <ul className="divide-y divide-line text-sm">
                            {mappings.map((m: any) => (
                                <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                                    <span className="min-w-0">{m.label} <StatusChip value={m.status === 'confirmed' ? 'approved' : m.status === 'rejected' ? 'rejected' : 'pending'} label={m.status === 'confirmed' ? `→ ${m.room}` : humanize(m.status)} /></span>
                                    <span className="flex gap-1">
                                        <ActionButton action={{ label: 'Map', url: `/admin/imports/mappings/${m.id}`, data: { decision: 'confirmed' }, fields: [{ name: 'room_id', label: 'This label is the same unit as', type: 'select', required: true, options: roomOptions, default: m.room_id ?? '' }] }} />
                                        <ActionButton action={{ label: 'Separate', url: `/admin/imports/mappings/${m.id}`, data: { decision: 'rejected' }, confirm: 'Keep this label separate from every current room?' }} />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </Card>

                <Card title="Flags by category">
                    <ul className="space-y-1 text-sm">
                        {categories.map((c: any) => (
                            <li key={c.category} className="flex justify-between gap-2">
                                <button className="text-left underline" onClick={() => go({ category: c.category })}>{humanize(c.category)}</button>
                                <span className="tabular-nums">{c.n}</span>
                            </li>
                        ))}
                    </ul>
                    {filters.category && <button className="mt-2 text-xs underline" onClick={() => go({ category: undefined })}>Clear filter</button>}
                </Card>
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-2">
                <Card title="Sheet total vs imported, per month and room">
                    <div className="overflow-hidden rounded-lg border border-line/70">
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead className={theadClass}><tr><th scope="col" className={thClass}>Sheet / block</th><th scope="col" className={`${thClass} text-right`}>Sheet total</th><th scope="col" className={`${thClass} text-right`}>Rows sum</th><th scope="col" className={`${thClass} text-right`}>Diff</th></tr></thead>
                                <tbody>
                                    {totals.rows.map((t, i) => (
                                        <tr key={i} className={trClass}>
                                            <td className={tdClass}>{t.sheet}<span className="block text-xs text-muted">{t.room}</span></td>
                                            <td className={`${tdClass} text-right tabular-nums`}>{peso(t.legacy_cents)}</td>
                                            <td className={`${tdClass} text-right tabular-nums`}>{peso(t.imported_cents)}</td>
                                            <td className={`${tdClass} text-right tabular-nums ${t.difference_cents ? 'font-semibold text-red-800' : ''}`}>{t.difference_cents ? peso(t.difference_cents) : '0'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <ClientPager pager={totals} />
                    </div>
                    <p className="mt-2 text-xs text-muted">A difference means the sheet's own total does not equal its rows (a formula range, a manual entry or a blank total). It is reported, not corrected.</p>
                </Card>

                <Card title="Provisional sales by sheet">
                    <div className="overflow-hidden rounded-lg border border-line/70">
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead className={theadClass}><tr><th scope="col" className={thClass}>Sheet</th><th scope="col" className={`${thClass} text-right`}>Rows</th><th scope="col" className={`${thClass} text-right`}>Nightly</th><th scope="col" className={`${thClass} text-right`}>Extra guests</th></tr></thead>
                                <tbody>
                                    {sales.rows.map((m: any) => (
                                        <tr key={m.sheet} className={trClass}><td className={tdClass}>{m.sheet}</td><td className={`${tdClass} text-right tabular-nums`}>{m.n}</td><td className={`${tdClass} text-right tabular-nums`}>{peso(Number(m.rate_cents))}</td><td className={`${tdClass} text-right tabular-nums`}>{peso(Number(m.extra_cents))}</td></tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <ClientPager pager={sales} />
                    </div>
                    {blockAnomalies.length > 0 && (
                        <details className="mt-3 text-xs">
                            <summary className="cursor-pointer text-muted">Sheet-level notes ({blockAnomalies.length})</summary>
                            <ul className="mt-1 space-y-1">{blockAnomalies.map((a: any) => <li key={a.id}>{a.detail}</li>)}</ul>
                        </details>
                    )}
                </Card>
            </div>

            <Card title="Staged rows" action={
                <div className="flex flex-wrap gap-2">
                    <Select aria-label="Sheet" className="w-auto" placeholder="All sheets" options={sheets.map((s: string) => ({ value: s, label: s }))} value={filters.sheet ?? ''} onChange={(e) => go({ sheet: e.target.value || undefined })} />
                    <Select aria-label="Type" className="w-auto" placeholder="All types" options={['daily_sale', 'no_booking', 'cleaning', 'laundry', 'utility', 'damage'].map((s) => ({ value: s, label: humanize(s) }))} value={filters.type ?? ''} onChange={(e) => go({ type: e.target.value || undefined })} />
                </div>
            }>
                {rows.data.length === 0 ? <Empty>No rows match.</Empty> : (
                    <div className="overflow-hidden rounded-lg border border-line/70">
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead className={theadClass}><tr>{['Source cell', 'Type', 'Room label', 'Room', 'Date', 'Guest', 'Channel', 'Paid via', 'Amount', 'Extra', 'Conf.', 'Flags', 'Review', ''].map((h) => <th key={h} scope="col" className={thClass}>{h}</th>)}</tr></thead>
                                <tbody>
                                    {rows.data.map((r: any) => (
                                        <tr key={r.id} className={trClass}>
                                            <td className={`${tdClass} whitespace-nowrap`}>{r.sheet}!{r.cell}</td>
                                            <td className={tdClass}>{humanize(r.type)}</td>
                                            <td className={`${tdClass} max-w-40 whitespace-normal`}>{r.room_label}</td>
                                            <td className={tdClass}>{r.room ?? <span className="text-red-800">Unlinked</span>}</td>
                                            <td className={`${tdClass} whitespace-nowrap`}>{dateOnly(r.date)}</td>
                                            <td className={tdClass}>{r.guest}</td>
                                            <td className={tdClass}>{r.source}</td>
                                            <td className={tdClass}>{r.payment}</td>
                                            <td className={`${tdClass} tabular-nums`}>{peso(r.amount_cents)}</td>
                                            <td className={`${tdClass} tabular-nums`}>{peso(r.extra_cents)}</td>
                                            <td className={`${tdClass} tabular-nums`}>{r.confidence}</td>
                                            <td className={`${tdClass} tabular-nums`}>{r.flags}</td>
                                            <td className={tdClass}><StatusChip value={r.review} /></td>
                                            <td className={`${tdClass} whitespace-nowrap`}>
                                                {r.review === 'pending' && (
                                                    <span className="flex gap-1">
                                                        <ActionButton action={{ label: 'Accept', url: `/admin/imports/rows/${r.id}/review`, data: { decision: 'approved' } }} />
                                                        <ActionButton action={{ label: 'Reject', url: `/admin/imports/rows/${r.id}/review`, data: { decision: 'rejected' }, variant: 'danger' }} />
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={rows.links} from={rows.from} to={rows.to} total={rows.total} />
                    </div>
                )}
            </Card>
        </AdminLayout>
    );
}
