import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowDownToLine, ArrowUpFromLine, ClipboardCheck, Package, Pencil, Plus, Search, Trash2, Warehouse as WarehouseIcon } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import { Button, Card, cn, Empty, Field, Input, Modal, Select, Stat, tableClass, tdClass, theadClass, thClass, trClass } from '@/components/ui';
import AdminLayout from '@/layouts/AdminLayout';
import { dateTime, peso } from '@/lib/format';

type Item = { id: number; name: string; sku: string; unit: string; reorder_level: number; unit_cost_cents: number | null; on_hand: number; out_30_days: number; last_purchase: string | null };
type Option = { value: number; label: string };
type Movement = { id: number; type: string; item: string; unit: string; quantity: number; room: string | null; note: string | null; by: string | null; at: string; task: number | null };

const KIND: Record<string, { label: string; tone: string }> = {
    purchase: { label: 'Purchase', tone: 'bg-emerald-50 text-emerald-800' },
    receipt: { label: 'Received', tone: 'bg-emerald-50 text-emerald-800' },
    receive: { label: 'Received', tone: 'bg-emerald-50 text-emerald-800' },
    stock_out: { label: 'Stock out', tone: 'bg-sky-50 text-sky-800' },
    issue: { label: 'Stock out', tone: 'bg-sky-50 text-sky-800' },
    adjustment: { label: 'Count', tone: 'bg-amber-50 text-amber-800' },
};

const isLow = (i: Item) => i.on_hand <= 0 || (i.reorder_level > 0 && i.on_hand <= i.reorder_level);

function StockBadge({ item }: { item: Item }) {
    if (item.on_hand <= 0) return <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800">Out of stock</span>;
    if (isLow(item)) return <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800">Low</span>;
    return <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800">In stock</span>;
}

/** Errors for lines arrive as "lines.0.quantity"; this shows the first one per line. */
function lineError(errors: Record<string, string>, i: number) {
    return Object.entries(errors).find(([k]) => k.startsWith(`lines.${i}.`))?.[1];
}

function PurchaseModal({ items, onClose }: { items: Item[]; onClose: () => void }) {
    const form = useForm<{ lines: { name: string; unit: string; quantity: string; unit_cost: string }[]; note: string }>({ lines: [{ name: '', unit: '', quantity: '', unit_cost: '' }], note: '' });
    const e = form.errors as Record<string, string>;
    const byName = useMemo(() => new Map(items.map((i) => [i.name.toLowerCase(), i])), [items]);
    const set = (i: number, key: string, value: string) => form.setData('lines', form.data.lines.map((l, n) => (n === i ? { ...l, [key]: value } : l)));
    const total = form.data.lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0), 0);

    function submit(ev: FormEvent) {
        ev.preventDefault();
        form.transform((d) => ({
            note: d.note,
            lines: d.lines.filter((l) => l.name.trim() || l.quantity).map((l) => {
                const known = byName.get(l.name.trim().toLowerCase());
                return known ? { item_id: known.id, quantity: l.quantity, unit_cost: l.unit_cost } : { name: l.name.trim(), unit: l.unit, quantity: l.quantity, unit_cost: l.unit_cost };
            }),
        }));
        form.post('/admin/inventory/purchases', { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal open wide title="Record a purchase" onClose={onClose}>
            <form onSubmit={submit} className="space-y-3">
                <p className="text-sm text-muted">Everything bought goes into the warehouse. Type a new name (for example “LED bulb”) and it is added as an item.</p>
                <datalist id="inventory-items">{items.map((i) => <option key={i.id} value={i.name} />)}</datalist>
                <div className="hidden grid-cols-[1fr_5rem_5rem_7rem_2.25rem] gap-2 text-xs font-medium text-muted sm:grid">
                    <span>Item</span><span>Qty</span><span>Unit</span><span>Cost each (₱)</span><span />
                </div>
                {form.data.lines.map((l, i) => {
                    const known = byName.get(l.name.trim().toLowerCase());
                    return (
                        <div key={i} className="space-y-1">
                            <div className="grid grid-cols-[1fr_5rem] gap-2 sm:grid-cols-[1fr_5rem_5rem_7rem_2.25rem]">
                                <Input list="inventory-items" aria-label="Item" placeholder="Item name" required value={l.name} onChange={(ev) => set(i, 'name', ev.target.value)} autoFocus={i === 0} />
                                <Input aria-label="Quantity" type="number" min={1} required placeholder="Qty" value={l.quantity} onChange={(ev) => set(i, 'quantity', ev.target.value)} />
                                <Input aria-label="Unit" placeholder="pc" value={known ? known.unit : l.unit} disabled={!!known} onChange={(ev) => set(i, 'unit', ev.target.value)} />
                                <Input aria-label="Cost each" type="number" min={0} step="0.01" placeholder="Optional" value={l.unit_cost} onChange={(ev) => set(i, 'unit_cost', ev.target.value)} />
                                <Button aria-label="Remove line" className="justify-center px-0" disabled={form.data.lines.length === 1} onClick={() => form.setData('lines', form.data.lines.filter((_, n) => n !== i))}><Trash2 size={15} className="shrink-0" aria-hidden /></Button>
                            </div>
                            <p className="text-xs text-muted">
                                {l.name.trim() === '' ? '' : known ? `In the warehouse now: ${known.on_hand} ${known.unit}` : <span className="text-sky-800">New item</span>}
                            </p>
                            {lineError(e, i) && <p role="alert" className="text-xs text-red-800">{lineError(e, i)}</p>}
                        </div>
                    );
                })}
                <Button small onClick={() => form.setData('lines', [...form.data.lines, { name: '', unit: '', quantity: '', unit_cost: '' }])}><Plus size={14} aria-hidden /> Add another item</Button>
                <Field label="Note" help="Store or receipt number, optional." error={e.note}>
                    <Input value={form.data.note} onChange={(ev) => form.setData('note', ev.target.value)} />
                </Field>
                {e.lines && <p role="alert" className="text-xs text-red-800">{e.lines}</p>}
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                    <span className="text-sm text-muted">{total > 0 ? <>Total <strong className="text-chocolate">{peso(Math.round(total * 100))}</strong></> : ''}</span>
                    <div className="flex gap-2">
                        <Button onClick={onClose}>Cancel</Button>
                        <Button type="submit" variant="primary" disabled={form.processing}><ArrowDownToLine size={15} aria-hidden /> Add to warehouse</Button>
                    </div>
                </div>
            </form>
        </Modal>
    );
}

function StockOutModal({ items, suites, preset, onClose }: { items: Item[]; suites: Option[]; preset?: Item; onClose: () => void }) {
    const form = useForm<{ room_id: string; lines: { inventory_item_id: string; quantity: string }[]; note: string }>({
        room_id: '', lines: [{ inventory_item_id: preset ? String(preset.id) : '', quantity: '1' }], note: '',
    });
    const e = form.errors as Record<string, string>;
    const byId = useMemo(() => new Map(items.map((i) => [String(i.id), i])), [items]);
    const set = (i: number, key: string, value: string) => form.setData('lines', form.data.lines.map((l, n) => (n === i ? { ...l, [key]: value } : l)));

    return (
        <Modal open title="Stock out to a suite" onClose={onClose}>
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/admin/inventory/stock-out', { preserveScroll: true, onSuccess: onClose }); }} className="space-y-3">
                <Field label="Suite" required error={e.room_id}>
                    <Select required placeholder="Choose a suite…" options={suites} value={form.data.room_id} onChange={(ev) => form.setData('room_id', ev.target.value)} />
                </Field>
                <div className="space-y-2">
                    <p className="text-sm font-medium">Items</p>
                    {form.data.lines.map((l, i) => {
                        const item = byId.get(l.inventory_item_id);
                        const over = item && Number(l.quantity) > item.on_hand;
                        return (
                            <div key={i} className="space-y-1">
                                <div className="flex gap-2">
                                    <Select aria-label="Item" required className="min-w-0 flex-1" placeholder="Choose an item…" value={l.inventory_item_id} onChange={(ev) => set(i, 'inventory_item_id', ev.target.value)}
                                        options={items.map((it) => ({ value: it.id, label: `${it.name} (${it.on_hand} ${it.unit} left)`, disabled: it.on_hand <= 0 }))} />
                                    <Input aria-label="Quantity" type="number" min={1} max={item?.on_hand} required className="w-20" value={l.quantity} onChange={(ev) => set(i, 'quantity', ev.target.value)} />
                                    <Button aria-label="Remove line" disabled={form.data.lines.length === 1} onClick={() => form.setData('lines', form.data.lines.filter((_, n) => n !== i))}><Trash2 size={15} aria-hidden /></Button>
                                </div>
                                {item && <p className={cn('text-xs', over ? 'text-red-800' : 'text-muted')}>{over ? `Only ${item.on_hand} ${item.unit} in the warehouse.` : `Warehouse after this: ${item.on_hand - (Number(l.quantity) || 0)} ${item.unit}`}</p>}
                                {lineError(e, i) && <p role="alert" className="text-xs text-red-800">{lineError(e, i)}</p>}
                            </div>
                        );
                    })}
                    <Button small onClick={() => form.setData('lines', [...form.data.lines, { inventory_item_id: '', quantity: '1' }])}><Plus size={14} aria-hidden /> Add another item</Button>
                </div>
                <Field label="Note" error={e.note}><Input placeholder="Optional" value={form.data.note} onChange={(ev) => form.setData('note', ev.target.value)} /></Field>
                {e.supplies && <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-900">{e.supplies}</p>}
                <div className="flex justify-end gap-2 border-t border-line pt-3">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="primary" disabled={form.processing}><ArrowUpFromLine size={15} aria-hidden /> Stock out</Button>
                </div>
            </form>
        </Modal>
    );
}

function CountModal({ item, onClose }: { item: Item; onClose: () => void }) {
    const form = useForm({ counted: String(item.on_hand), note: '' });
    const diff = (Number(form.data.counted) || 0) - item.on_hand;

    return (
        <Modal open title={`Count ${item.name}`} onClose={onClose}>
            <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/admin/inventory/items/${item.id}/count`, { preserveScroll: true, onSuccess: onClose }); }} className="space-y-3">
                <p className="text-sm text-muted">Enter how many are actually on the warehouse shelf. The system has {item.on_hand} {item.unit}.</p>
                <Field label={`Counted (${item.unit})`} error={form.errors.counted}><Input type="number" min={0} required autoFocus value={form.data.counted} onChange={(ev) => form.setData('counted', ev.target.value)} /></Field>
                {form.data.counted !== '' && diff !== 0 && <p className="text-sm">{diff > 0 ? `${diff} more than recorded` : `${-diff} missing`}. This difference is kept in the history.</p>}
                <Field label="Reason" error={form.errors.note}><Input placeholder="For example: monthly count, damaged" value={form.data.note} onChange={(ev) => form.setData('note', ev.target.value)} /></Field>
                <div className="flex justify-end gap-2"><Button onClick={onClose}>Cancel</Button><Button type="submit" variant="primary" disabled={form.processing}>Save count</Button></div>
            </form>
        </Modal>
    );
}

function ItemModal({ item, onClose }: { item?: Item; onClose: () => void }) {
    const form = useForm({ name: item?.name ?? '', unit: item?.unit ?? 'pc', reorder_level: String(item?.reorder_level ?? 0) });

    return (
        <Modal open title={item ? `Edit ${item.name}` : 'New item'} onClose={onClose}>
            <form onSubmit={(ev) => { ev.preventDefault(); form.post(item ? `/admin/inventory/items/${item.id}` : '/admin/inventory/items', { preserveScroll: true, onSuccess: onClose }); }} className="space-y-3">
                <Field label="Name" required error={form.errors.name}><Input required autoFocus value={form.data.name} onChange={(ev) => form.setData('name', ev.target.value)} /></Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Unit" help="pc, pack, roll, bottle…" error={form.errors.unit}><Input required value={form.data.unit} onChange={(ev) => form.setData('unit', ev.target.value)} /></Field>
                    <Field label="Warn when at or below" error={form.errors.reorder_level}><Input type="number" min={0} required value={form.data.reorder_level} onChange={(ev) => form.setData('reorder_level', ev.target.value)} /></Field>
                </div>
                <div className="flex justify-end gap-2"><Button onClick={onClose}>Cancel</Button><Button type="submit" variant="primary" disabled={form.processing}>Save</Button></div>
            </form>
        </Modal>
    );
}

export default function Inventory({ items, suites, movements, roomFilter, can }: { items: Item[]; suites: Option[]; movements: Movement[]; roomFilter: number | null; can: { manage: boolean; stockOut: boolean } }) {
    const [query, setQuery] = useState('');
    const [lowOnly, setLowOnly] = useState(false);
    const [modal, setModal] = useState<{ kind: 'purchase' | 'out' | 'count' | 'item'; item?: Item } | null>(null);

    const low = items.filter(isLow);
    const shown = items.filter((i) => (!lowOnly || isLow(i)) && i.name.toLowerCase().includes(query.trim().toLowerCase()));
    const units = items.reduce((s, i) => s + Math.max(0, i.on_hand), 0);
    const value = items.reduce((s, i) => s + Math.max(0, i.on_hand) * (i.unit_cost_cents ?? 0), 0);

    return (
        <AdminLayout title="Inventory" subtitle="One warehouse for all supplies. Purchases go in; stock-outs to a suite, and supplies cleaners record, come out.">
            <div className="mb-4 flex flex-wrap gap-2">
                {can.manage && <Button variant="primary" onClick={() => setModal({ kind: 'purchase' })}><ArrowDownToLine size={16} aria-hidden /> Record purchase</Button>}
                {can.stockOut && <Button variant={can.manage ? 'secondary' : 'primary'} onClick={() => setModal({ kind: 'out' })}><ArrowUpFromLine size={16} aria-hidden /> Stock out to suite</Button>}
                {can.manage && <Button onClick={() => setModal({ kind: 'item' })}><Plus size={16} aria-hidden /> New item</Button>}
            </div>

            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Items" value={items.length} icon={Package} />
                <Stat label="In the warehouse" value={`${units.toLocaleString()} units`} icon={WarehouseIcon} hint={can.manage && value > 0 ? `Worth about ${peso(value)}` : undefined} />
                <Stat label="Low or out of stock" value={low.length} icon={AlertTriangle} hint={low.length ? low.slice(0, 3).map((i) => i.name).join(', ') : 'All good'} />
                <Stat label="Stocked out, 30 days" value={`${items.reduce((s, i) => s + i.out_30_days, 0).toLocaleString()} units`} icon={ArrowUpFromLine} />
            </div>

            <Card title={<><WarehouseIcon size={17} aria-hidden /> Warehouse</>} action={
                <div className="flex flex-wrap items-center gap-2">
                    <label className="flex items-center gap-1.5 text-xs"><input type="checkbox" checked={lowOnly} onChange={(ev) => setLowOnly(ev.target.checked)} /> Low only</label>
                    <span className="relative">
                        <Search size={14} className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-muted" aria-hidden />
                        <Input aria-label="Search items" placeholder="Search items" className="h-8 w-44 pl-8 text-xs" value={query} onChange={(ev) => setQuery(ev.target.value)} />
                    </span>
                </div>
            }>
                {shown.length === 0 ? <Empty>{items.length ? 'No items match.' : 'No items yet. Record a purchase to start.'}</Empty> : (
                    <div className="-mx-5 overflow-x-auto">
                        <table className={tableClass}>
                            <thead className={theadClass}><tr>
                                <th className={thClass}>Item</th><th className={cn(thClass, 'text-right')}>On hand</th><th className={thClass}>Status</th>
                                <th className={cn(thClass, 'text-right')}>Warn at</th><th className={cn(thClass, 'text-right')}>Out, 30 days</th><th className={thClass}>Last bought</th><th className={thClass} />
                            </tr></thead>
                            <tbody>
                                {shown.map((i) => (
                                    <tr key={i.id} className={trClass}>
                                        <td className={cn(tdClass, 'py-2')}><span className="font-medium">{i.name}</span> <span className="text-xs text-muted">{i.unit}</span></td>
                                        <td className={cn(tdClass, 'text-right text-base font-semibold tabular-nums', i.on_hand <= 0 && 'text-red-800')}>{i.on_hand}</td>
                                        <td className={tdClass}><StockBadge item={i} /></td>
                                        <td className={cn(tdClass, 'text-right tabular-nums text-muted')}>{i.reorder_level || '—'}</td>
                                        <td className={cn(tdClass, 'text-right tabular-nums')}>{i.out_30_days || '—'}</td>
                                        <td className={cn(tdClass, 'text-muted')}>{i.last_purchase ? dateTime(i.last_purchase) : 'Never'}</td>
                                        <td className={cn(tdClass, 'text-right')}>
                                            <div className="flex justify-end gap-1">
                                                {can.stockOut && <Button small disabled={i.on_hand <= 0} onClick={() => setModal({ kind: 'out', item: i })}>Stock out</Button>}
                                                {can.manage && <Button small aria-label={`Count ${i.name}`} title="Count" onClick={() => setModal({ kind: 'count', item: i })}><ClipboardCheck size={14} aria-hidden /></Button>}
                                                {can.manage && <Button small aria-label={`Edit ${i.name}`} title="Edit" onClick={() => setModal({ kind: 'item', item: i })}><Pencil size={14} aria-hidden /></Button>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card className="mt-4" title="History" action={
                <Select aria-label="Filter by suite" className="h-8 text-xs" placeholder="All suites and purchases" options={suites} value={roomFilter ?? ''}
                    onChange={(ev) => router.get('/admin/inventory', ev.target.value ? { room: ev.target.value } : {}, { preserveScroll: true, preserveState: true, replace: true })} />
            }>
                {movements.length === 0 ? <Empty>Nothing recorded yet.</Empty> : (
                    <ul className="divide-y divide-line/70 text-sm">
                        {movements.map((m) => {
                            const kind = KIND[m.type] ?? { label: m.type, tone: 'bg-beige text-espresso' };
                            return (
                                <li key={m.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                    <span className={cn('w-20 shrink-0 rounded-full px-2 py-0.5 text-center text-xs font-medium', kind.tone)}>{kind.label}</span>
                                    <span className="min-w-0 flex-1">
                                        <span className="font-medium">{m.item}</span>
                                        {m.room && <span className="text-muted"> → {m.room}</span>}
                                        {m.task && <span className="text-muted"> (cleaning task #{m.task})</span>}
                                        {m.note && !m.task && <span className="block text-xs text-muted">{m.note}</span>}
                                    </span>
                                    <span className={cn('w-16 text-right font-semibold tabular-nums', m.quantity > 0 ? 'text-emerald-800' : 'text-chocolate')}>{m.quantity > 0 ? `+${m.quantity}` : m.quantity} <span className="text-xs font-normal text-muted">{m.unit}</span></span>
                                    <span className="w-full text-xs text-muted sm:w-48 sm:text-right">{dateTime(m.at)}{m.by ? ` · ${m.by}` : ''}</span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>

            {modal?.kind === 'purchase' && <PurchaseModal items={items} onClose={() => setModal(null)} />}
            {modal?.kind === 'out' && <StockOutModal items={items} suites={suites} preset={modal.item} onClose={() => setModal(null)} />}
            {modal?.kind === 'count' && modal.item && <CountModal item={modal.item} onClose={() => setModal(null)} />}
            {modal?.kind === 'item' && <ItemModal item={modal.item} onClose={() => setModal(null)} />}
        </AdminLayout>
    );
}
