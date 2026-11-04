import { router } from '@inertiajs/react';
import { ActionButton, Card, Empty, Pagination, Select, Stat, StatusChip } from '@/components/ui';
import { dateOnly, humanize, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

export default function ImportShow({ batch, summary, future, counts, categories, blockAnomalies, monthly, mappings, rooms, rows, filters, sheets }: any) {
    const go = (changes: any) => router.get(`/admin/imports/${batch.id}`, { ...filters, ...changes, page: undefined }, { preserveState: true, preserveScroll: true, replace: true });
    const roomOptions = rooms.map((r: any) => ({ value: r.id, label: `${r.name} (${r.unit_code})` }));
    const totals = (summary?.block_totals ?? []) as any[];

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
                    <div className="max-h-96 overflow-auto">
                        <table className="w-full text-xs">
                            <thead className="sticky top-0 bg-white text-muted"><tr><th className="py-1 text-left font-medium">Sheet / block</th><th className="text-right font-medium">Sheet total</th><th className="text-right font-medium">Rows sum</th><th className="text-right font-medium">Diff</th></tr></thead>
                            <tbody>
                                {totals.map((t, i) => (
                                    <tr key={i} className="border-t border-line">
                                        <td className="py-1 pr-2">{t.sheet}<span className="block text-muted">{t.room}</span></td>
                                        <td className="text-right tabular-nums">{peso(t.legacy_cents)}</td>
                                        <td className="text-right tabular-nums">{peso(t.imported_cents)}</td>
                                        <td className={`text-right tabular-nums ${t.difference_cents ? 'font-semibold text-red-800' : ''}`}>{t.difference_cents ? peso(t.difference_cents) : '0'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-2 text-xs text-muted">A difference means the sheet's own total does not equal its rows (a formula range, a manual entry or a blank total). It is reported, not corrected.</p>
                </Card>

                <Card title="Provisional sales by sheet">
                    <table className="w-full text-sm">
                        <thead className="text-xs text-muted"><tr><th className="py-1 text-left font-medium">Sheet</th><th className="text-right font-medium">Rows</th><th className="text-right font-medium">Nightly</th><th className="text-right font-medium">Extra guests</th></tr></thead>
                        <tbody>
                            {monthly.map((m: any) => (
                                <tr key={m.sheet} className="border-t border-line"><td className="py-1">{m.sheet}</td><td className="text-right tabular-nums">{m.n}</td><td className="text-right tabular-nums">{peso(Number(m.rate_cents))}</td><td className="text-right tabular-nums">{peso(Number(m.extra_cents))}</td></tr>
                            ))}
                        </tbody>
                    </table>
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
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="text-muted"><tr>{['Source cell', 'Type', 'Room label', 'Room', 'Date', 'Guest', 'Channel', 'Paid via', 'Amount', 'Extra', 'Conf.', 'Flags', 'Review', ''].map((h) => <th key={h} scope="col" className="py-1 pr-2 font-medium whitespace-nowrap">{h}</th>)}</tr></thead>
                            <tbody>
                                {rows.data.map((r: any) => (
                                    <tr key={r.id} className="border-t border-line align-top">
                                        <td className="py-1 pr-2 whitespace-nowrap">{r.sheet}!{r.cell}</td>
                                        <td className="pr-2">{humanize(r.type)}</td>
                                        <td className="max-w-40 pr-2">{r.room_label}</td>
                                        <td className="pr-2">{r.room ?? <span className="text-red-800">Unlinked</span>}</td>
                                        <td className="pr-2 whitespace-nowrap">{dateOnly(r.date)}</td>
                                        <td className="pr-2">{r.guest}</td>
                                        <td className="pr-2">{r.source}</td>
                                        <td className="pr-2">{r.payment}</td>
                                        <td className="pr-2 tabular-nums">{peso(r.amount_cents)}</td>
                                        <td className="pr-2 tabular-nums">{peso(r.extra_cents)}</td>
                                        <td className="pr-2 tabular-nums">{r.confidence}</td>
                                        <td className="pr-2 tabular-nums">{r.flags}</td>
                                        <td className="pr-2"><StatusChip value={r.review} /></td>
                                        <td className="whitespace-nowrap">
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
                )}
                <Pagination links={rows.links} />
            </Card>
        </AdminLayout>
    );
}
