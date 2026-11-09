import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Camera, CheckCircle2, ChevronLeft, ChevronRight, ClipboardList, Clock, DoorOpen, Eye, ImagePlus, Images, Loader2, Sparkles, Trash2, UserPlus } from 'lucide-react';
import { ChangeEvent, useEffect, useMemo, useRef, useState } from 'react';
import { ActionButton, Button, Card, cn, Empty, Field, FieldDef, Input, Modal, Select, Tabs, buttonClass } from '@/components/ui';
import { fileSize, shrinkPhoto } from '@/lib/images';
import { dateOnly, shortDate, time12 } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';
import { useLiveRefresh } from '@/lib/useLiveRefresh';

type Row = any;

const TZ = 'Asia/Manila';
const AXIS_START = 8 * 60;
const AXIS_END = 19 * 60;
const AXIS_HOURS = Array.from({ length: 12 }, (_, i) => 8 + i);
const percent = (minute: number) => ((Math.min(AXIS_END, Math.max(AXIS_START, minute)) - AXIS_START) / (AXIS_END - AXIS_START)) * 100;
const hourLabel = (h: number) => (h === 12 ? '12nn' : h < 12 ? `${h}am` : `${h - 12}pm`);
const clock = (iso?: string | null) => (iso ? new Date(iso).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', timeZone: TZ }) : '—');

const BUCKETS: Record<string, { label: string; bar: string; dot: string }> = {
    done: { label: 'Done', bar: 'bg-emerald-600', dot: 'bg-emerald-600' },
    in_progress: { label: 'In progress', bar: 'bg-gold-dark', dot: 'bg-gold-dark' },
    not_started: { label: 'Not started', bar: 'border-2 border-dashed border-amber-600 bg-amber-50', dot: 'bg-amber-500' },
    at_risk: { label: 'Late or due soon', bar: 'bg-red-700', dot: 'bg-red-700' },
    waiting: { label: 'Guest still in', bar: 'bg-line', dot: 'bg-line' },
};

const STATUS: Record<string, { label: string; tone: string }> = {
    waiting: { label: 'Guest still in', tone: 'bg-beige text-espresso border-line' },
    pending: { label: 'Not started', tone: 'bg-amber-50 text-amber-900 border-amber-200' },
    assigned: { label: 'Not started', tone: 'bg-amber-50 text-amber-900 border-amber-200' },
    in_progress: { label: 'In progress', tone: 'bg-sky-50 text-sky-900 border-sky-200' },
    for_inspection: { label: 'For inspection', tone: 'bg-sky-50 text-sky-900 border-sky-200' },
    rejected: { label: 'Clean again', tone: 'bg-red-50 text-red-900 border-red-200' },
    accepted: { label: 'Finished', tone: 'bg-emerald-50 text-emerald-900 border-emerald-200' },
};

function Timeline({ rows, window, nowMinute }: { rows: Row[]; window: { check_out: string | null; check_in: string }; nowMinute: number | null }) {
    const toMinute = (time: string) => { const [h, m] = time.split(':').map(Number); return h * 60 + m; };
    const inAt = percent(toMinute(window.check_in));
    const outAt = window.check_out ? percent(toMinute(window.check_out)) : null;
    const labelWidth = 'w-24 shrink-0';

    return (
        <Card title="Schedule for the day" className="hidden md:block">
            <ul className="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                {Object.entries(BUCKETS).map(([key, b]) => <li key={key} className="flex items-center gap-1.5"><span className={cn('h-2.5 w-4 rounded-full', b.bar)} aria-hidden />{b.label}</li>)}
            </ul>
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Schedule for the day, scrolls sideways">
                <div className="min-w-[44rem] pr-5">
                    <div className="flex">
                        <div className={labelWidth} aria-hidden />
                        <div className="relative h-5 flex-1 text-[11px] text-muted" aria-hidden>
                            {AXIS_HOURS.map((h) => <span key={h} className="absolute -translate-x-1/2" style={{ left: `${percent(h * 60)}%` }}>{hourLabel(h)}</span>)}
                        </div>
                    </div>
                    <div className="flex">
                        <div className={labelWidth}>
                            {rows.length > 0 && <div className="h-7" aria-hidden />}
                            {rows.map((r) => <div key={r.key} className="flex h-8 items-center truncate pr-2 text-xs font-medium">{r.room}</div>)}
                        </div>
                        <div className="relative min-h-24 flex-1 border-b border-line/70">
                            {AXIS_HOURS.map((h) => <span key={h} className="absolute inset-y-0 w-px bg-line/60" style={{ left: `${percent(h * 60)}%` }} aria-hidden />)}
                            {outAt !== null && <span className="absolute inset-y-0 border-x border-dashed border-gold-dark/50 bg-beige/50" style={{ left: `${outAt}%`, width: `${inAt - outAt}%` }} aria-hidden />}
                            {outAt === null && <span className="absolute inset-y-0 border-r border-dashed border-gold-dark/50" style={{ left: 0, width: `${inAt}%` }} aria-hidden />}
                            {nowMinute !== null && nowMinute >= AXIS_START && nowMinute <= AXIS_END && <span className="absolute inset-y-0 z-10 w-0.5 bg-chocolate" style={{ left: `${percent(nowMinute)}%` }} title="Now" aria-hidden />}
                            <div className="relative h-7">
                                {window.check_out && <span className="absolute top-1 left-0 z-10 rounded-full border border-gold-dark/50 bg-white px-2 py-0.5 text-[11px] font-medium whitespace-nowrap shadow-xs" style={{ left: `${outAt}%` }}>Check-out {time12(window.check_out)}</span>}
                                <span className="absolute top-1 z-10 rounded-full border border-gold-dark/50 bg-white px-2 py-0.5 text-[11px] font-medium whitespace-nowrap shadow-xs" style={{ left: `${inAt}%` }}>Check-in {time12(window.check_in)}</span>
                            </div>
                            {rows.length === 0 && <p className="relative py-5 text-center text-sm text-muted">No rooms scheduled.</p>}
                            {rows.map((r) => {
                                const left = percent(r.bar.start);
                                const width = Math.max(percent(r.bar.end) - left, 1.5);
                                const bucket = BUCKETS[r.bucket];

                                return (
                                    <div key={r.key} className="relative h-8">
                                        <span title={`${r.room}: ${bucket.label}. Ready by ${clock(r.ready_by)}${r.assignee ? `. ${r.assignee}` : ''}`} className={cn('absolute top-1.5 flex h-5 items-center overflow-hidden rounded-md px-1.5 text-[11px] whitespace-nowrap', bucket.bar, ['done', 'in_progress', 'at_risk'].includes(r.bucket) ? 'text-white' : 'text-chocolate')} style={{ left: `${left}%`, width: `${width}%` }}>
                                            {r.assignee ?? ''}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
            {!window.check_out && <p className="mt-2 text-xs text-muted">Set a standard check-out time under Settings to mark it here.</p>}
        </Card>
    );
}

/** Choosing a person on a planned room also creates its task, so there is one control for both. */
function AssignSelect({ row, staff, date }: { row: Row; staff: any[]; date: string }) {
    const label = `Assign ${row.room}`;
    const send = (userId: string) => {
        if (!userId) return;
        if (row.id) router.post(`/admin/tasks/${row.id}/assign`, { user_id: userId }, { preserveScroll: true });
        else router.post('/admin/tasks/plan', { booking_id: row.booking_id, room_id: row.room_id, user_id: userId, date }, { preserveScroll: true });
    };

    return <Select aria-label={label} className="w-36 py-0.5! text-xs pointer-coarse:w-full pointer-coarse:py-2! pointer-coarse:text-base" placeholder="Assign…" options={staff.map((s) => ({ value: s.id, label: s.name }))} value={row.assigned_to ?? ''} onChange={(e) => send(e.target.value)} />;
}

function PhotoPicker({ photos, onChange, room }: { photos: File[]; onChange: (files: File[]) => void; room?: number }) {
    const [busy, setBusy] = useState(false);
    const previews = useMemo(() => photos.map((p) => URL.createObjectURL(p)), [photos]);
    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    async function add(e: ChangeEvent<HTMLInputElement>) {
        const chosen = Array.from(e.target.files ?? []);
        e.target.value = '';
        if (!chosen.length) return;
        setBusy(true);
        const shrunk = await Promise.all(chosen.map(shrinkPhoto));
        onChange([...photos, ...shrunk].slice(0, room ?? 10));
        setBusy(false);
    }

    return (
        <div>
            <div className="flex flex-wrap gap-2">
                {previews.map((url, i) => (
                    <span key={url} className="relative">
                        <img src={url} alt={`Photo ${i + 1}`} className="size-20 rounded-lg border border-line object-cover" />
                        <button type="button" aria-label={`Remove photo ${i + 1}`} onClick={() => onChange(photos.filter((_, n) => n !== i))} className="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full border border-line bg-white shadow-xs pointer-coarse:size-8"><Trash2 size={12} aria-hidden /></button>
                    </span>
                ))}
                {/* Keep this visible on every device. `capture` opens the rear camera where the browser supports it,
                    and safely falls back to the platform's image chooser elsewhere. */}
                <label className="flex size-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-gold-dark bg-chocolate text-xs font-medium text-ivory transition-colors hover:bg-espresso">
                    {busy ? <Loader2 size={18} className="animate-spin" aria-hidden /> : <Camera size={18} aria-hidden />}
                    {busy ? 'Working…' : 'Take photo'}
                    <input type="file" accept="image/*" capture="environment" aria-label="Take a photo with the camera" className="sr-only" onChange={add} disabled={busy} />
                </label>
                <label className="flex size-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-gold-dark/60 bg-ivory text-xs font-medium text-bronze transition-colors hover:bg-beige">
                    <Images size={18} aria-hidden />
                    <span>Choose photo</span>
                    <input type="file" accept="image/*" multiple aria-label="Choose photos from this device" className="sr-only" onChange={add} disabled={busy} />
                </label>
            </div>
            {photos.length > 0 && <p className="mt-1.5 text-xs text-muted">{photos.length} photo{photos.length === 1 ? '' : 's'} ready ({fileSize(photos.reduce((total, photo) => total + photo.size, 0))}). Compressed again when saved.</p>}
        </div>
    );
}

function CompleteModal({ task, items, onClose }: any) {
    const form = useForm<any>({ checklist: task.checklist, notes: '', photos: [] as File[], supplies: [] });
    const errors = form.errors as any;
    const toggle = (i: number) => form.setData('checklist', form.data.checklist.map((c: any, n: number) => (n === i ? { ...c, done: !c.done } : c)));
    const setSupply = (i: number, key: string, value: string) => form.setData('supplies', form.data.supplies.map((s: any, n: number) => (n === i ? { ...s, [key]: value } : s)));
    const photoError = errors.photos ?? Object.entries(errors).find(([key]) => key.startsWith('photos.'))?.[1];

    return (
        <Modal open title={task.title ? `Finish: ${task.title}, ${task.room}` : `Finish cleaning: ${task.room}`} onClose={onClose}>
            <form onSubmit={(e) => { e.preventDefault(); form.post(`/admin/tasks/${task.id}/complete`, { forceFormData: true, preserveScroll: true, onSuccess: onClose }); }} className="space-y-4">
                {task.instructions && <p className="rounded-lg bg-ivory px-3 py-2 text-sm">{task.instructions}</p>}
                {form.data.checklist.length > 0 && (
                    <fieldset>
                        <legend className="mb-1 text-sm font-medium">Checklist</legend>
                        {form.data.checklist.map((c: any, i: number) => (
                            <label key={c.label} className="flex items-center gap-2 py-1.5 text-sm"><input type="checkbox" className="size-4" checked={c.done} onChange={() => toggle(i)} /> {c.label}</label>
                        ))}
                    </fieldset>
                )}
                <div>
                    <p className="mb-1.5 text-sm font-medium">{task.manual ? 'Photos of the finished work' : 'Photos of the finished room'}</p>
                    <PhotoPicker photos={form.data.photos} onChange={(files) => form.setData('photos', files)} />
                    {photoError && <p role="alert" className="mt-1 text-xs text-red-800">{photoError as string}</p>}
                </div>
                {items.length > 0 && (
                    <fieldset>
                        <legend className="mb-1 text-sm font-medium">Supplies used</legend>
                        <p className="mb-2 text-xs text-muted">What you record here is taken from the warehouse for this suite.</p>
                        <div className="space-y-2">
                            {form.data.supplies.map((s: any, i: number) => (
                                <div key={i} className="flex items-center gap-2">
                                    <Select aria-label="Supply" required className="min-w-0 flex-1" placeholder="Select…" options={items.map((it: any) => ({ value: it.id, label: `${it.name} (${it.on_hand} ${it.unit} in warehouse)`, disabled: it.on_hand <= 0 }))} value={s.inventory_item_id} onChange={(e) => setSupply(i, 'inventory_item_id', e.target.value)} />
                                    <Input aria-label="Quantity" required type="number" min={1} className="w-20" value={s.quantity} onChange={(e) => setSupply(i, 'quantity', e.target.value)} />
                                    <Button small aria-label="Remove supply" onClick={() => form.setData('supplies', form.data.supplies.filter((_: any, n: number) => n !== i))}>Remove</Button>
                                </div>
                            ))}
                            <Button small onClick={() => form.setData('supplies', [...form.data.supplies, { inventory_item_id: '', quantity: '1' }])}>Add supply</Button>
                        </div>
                        {(errors as any).supplies && <p role="alert" className="mt-1.5 text-xs text-red-800">{(errors as any).supplies}</p>}
                    </fieldset>
                )}
                <Field label="Notes" error={errors.notes}>
                    <textarea rows={2} className="w-full rounded-md border border-line px-3 py-2 text-sm pointer-coarse:text-base" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </Field>
                {!task.manual || task.blocks_room ? <p className="text-xs text-muted">The room stays not-ready until it passes inspection.</p> : null}
                <div className="flex justify-end gap-2">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="primary" disabled={form.processing}>{form.processing ? 'Sending…' : task.requires_inspection ? 'Send for inspection' : 'Mark as done'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function PhotosModal({ task, canAdd, onClose }: { task: Row; canAdd: boolean; onClose: () => void }) {
    const form = useForm<any>({ photos: [] as File[] });
    const errors = form.errors as any;
    const photoError = errors.photos ?? Object.entries(errors).find(([key]) => key.startsWith('photos.'))?.[1];

    return (
        <Modal open title={`Photos: ${task.room}`} onClose={onClose}>
            {task.photo_ids.length === 0 && !canAdd && <Empty>No photos were added.</Empty>}
            {task.photo_ids.length > 0 && (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {task.photo_ids.map((id: number, i: number) => (
                        <a key={id} href={`/admin/files/task/${id}`} target="_blank" rel="noreferrer" title="Open full photo">
                            <img src={`/admin/files/task/${id}`} alt={`${task.room}, photo ${i + 1}`} loading="lazy" className="aspect-square w-full rounded-lg border border-line object-cover" />
                            {task.photo_sizes?.[i] != null && <span className="mt-0.5 block text-center text-[11px] text-muted tabular-nums">{fileSize(task.photo_sizes[i])}</span>}
                        </a>
                    ))}
                </div>
            )}
            {task.notes && <p className="mt-3 text-sm"><span className="font-medium">Cleaner's note:</span> {task.notes}</p>}
            {canAdd && (
                <form className="mt-4 border-t border-line/70 pt-4" onSubmit={(e) => { e.preventDefault(); form.post(`/admin/tasks/${task.id}/photos`, { forceFormData: true, preserveScroll: true, onSuccess: onClose }); }}>
                    <p className="mb-1.5 text-sm font-medium">{task.photo_ids.length > 0 ? 'Add more' : 'Add a photo'}</p>
                    <PhotoPicker photos={form.data.photos} onChange={(files) => form.setData('photos', files)} room={Math.max(1, 10 - task.photo_ids.length)} />
                    {photoError && <p role="alert" className="mt-1 text-xs text-red-800">{photoError as string}</p>}
                    {form.data.photos.length > 0 && <div className="mt-3 flex justify-end"><Button type="submit" variant="primary" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save photos'}</Button></div>}
                </form>
            )}
        </Modal>
    );
}

/** What a row means for whoever is reading it, in the order the work moves. */
const STAGES = [
    { key: 'todo', label: 'To do', icon: ClipboardList, statuses: ['pending', 'assigned', 'rejected'], hint: 'Ready to start.' },
    { key: 'doing', label: 'In progress', icon: Sparkles, statuses: ['in_progress'], hint: 'Someone is working on these now.' },
    { key: 'inspect', label: 'For inspection', icon: Eye, statuses: ['for_inspection'], hint: 'The work is finished and needs checking. Approve it, or send it back with a note.' },
    { key: 'waiting', label: 'Guest still in', icon: DoorOpen, statuses: ['waiting'], hint: 'Cleaning can start once the guest has checked out. You can line up a cleaner now.' },
    { key: 'done', label: 'Finished', icon: CheckCircle2, statuses: ['accepted'], hint: 'All done.' },
];

const stageOf = (row: Row) => STAGES.find((s) => s.statuses.includes(row.status))?.key;

function hasTaskActions(row: Row, can: any, userId: number): boolean {
    if (!row.id) return false;
    const mine = can.manage || row.assigned_to === userId || !row.assigned_to;

    return (can.work && mine && ['pending', 'assigned', 'rejected', 'in_progress', 'for_inspection'].includes(row.status))
        || (can.inspect && row.status === 'for_inspection')
        || row.photos > 0;
}

function TaskActions({ row, can, items, userId }: { row: Row; can: any; items: any[]; userId: number }) {
    const [completing, setCompleting] = useState(false);
    const [viewing, setViewing] = useState(false);
    if (!row.id) return null;
    const mine = can.manage || row.assigned_to === userId || !row.assigned_to;
    const canAddPhotos = can.work && mine && ['in_progress', 'for_inspection'].includes(row.status);

    return (
        <div className="flex flex-wrap items-center gap-2">
            {can.work && mine && ['pending', 'assigned', 'rejected'].includes(row.status) && <ActionButton small={false} action={{ label: row.status === 'rejected' ? 'Start again' : 'Start', url: `/admin/tasks/${row.id}/start`, variant: 'primary' }} />}
            {can.work && mine && row.status === 'in_progress' && <Button variant="primary" onClick={() => setCompleting(true)}>Finish</Button>}
            {can.inspect && row.status === 'for_inspection' && (
                <>
                    <ActionButton small={false} action={{ label: 'Approve', url: `/admin/tasks/${row.id}/inspect`, data: { accepted: true }, variant: 'primary' }} />
                    <ActionButton small={false} action={{ label: 'Send back', url: `/admin/tasks/${row.id}/inspect`, data: { accepted: false }, fields: [{ name: 'note', label: 'What needs redoing?', type: 'text', required: true }] }} />
                </>
            )}
            {(row.photos > 0 || canAddPhotos) && (
                <Button aria-label={`Photos for ${row.room}`} onClick={() => setViewing(true)}>
                    <ImagePlus size={14} aria-hidden /> {row.photos > 0 ? `Photos (${row.photos})` : 'Add photo'}
                </Button>
            )}
            {completing && <CompleteModal task={row} items={items} onClose={() => setCompleting(false)} />}
            {viewing && <PhotosModal task={row} canAdd={canAddPhotos} onClose={() => setViewing(false)} />}
        </div>
    );
}

/** The one line that says when this matters and why. */
function whenLine(row: Row, date: string): string {
    const at = (iso?: string | null) => {
        if (!iso) return '';
        const day = new Date(iso).toLocaleDateString('en-CA', { timeZone: TZ });

        return day === date ? clock(iso) : `${shortDate(day)}, ${clock(iso)}`;
    };

    if (row.status === 'accepted') return row.completed_at ? `Finished at ${at(row.completed_at)}` : 'Finished';
    if (row.status === 'waiting') return row.has_arrival ? `Next guest arrives ${at(row.ready_by)}` : 'No guest arriving after';
    if (row.has_arrival) return `Finish by ${at(row.ready_by)}, next guest arriving`;
    if (!row.manual) return 'No guest arriving after';

    return row.ready_by ? `Due ${at(row.ready_by)}` : 'No due time';
}

function TaskCard({ row, can, staff, date, items, userId }: { row: Row; can: any; staff: any[]; date: string; items: any[]; userId: number }) {
    const canAssign = can.manage && (!row.id || ['waiting', 'pending', 'assigned', 'rejected'].includes(row.status));
    const hasActions = hasTaskActions(row, can, userId);
    const canCancel = can.manage && row.manual && row.status !== 'accepted';
    const actions = <TaskActions row={row} can={can} items={items} userId={userId} />;

    return (
        <li data-task className={cn('flex flex-col gap-2 rounded-xl border bg-white p-3 shadow-xs sm:p-4', row.urgency === 'late' ? 'border-red-300' : 'border-line/80')}>
            <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                <p className="min-w-0"><span className="font-semibold tracking-wide">{row.room}</span> <span className="text-xs text-muted">{row.unit}</span></p>
                <div className="flex flex-wrap gap-1">
                    {row.urgency === 'late' && <span className="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-xs font-medium text-red-900">Late</span>}
                    {row.urgency === 'soon' && <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-900">Due soon</span>}
                    {row.status === 'rejected' && <span className="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-xs font-medium text-red-900">Redo</span>}
                    {row.priority === 'high' && <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-900">High priority</span>}
                </div>
            </div>

            {/* Wraps by itself: one button sits beside the text, several drop to their own row. */}
            <div className="flex flex-wrap items-end gap-x-3 gap-y-2">
                <div className="min-w-0 flex-[1_1_11rem] text-sm">
                    <p className="font-medium">{row.title ?? 'Clean after check-out'}</p>
                    <p className="mt-0.5 flex items-start gap-1.5 text-xs text-muted">
                        <Clock size={13} className="mt-px shrink-0" aria-hidden />
                        <span className="min-w-0">{whenLine(row, date)}{!canAssign && <> · <span className="font-medium text-chocolate">{row.assignee ?? 'Not assigned yet'}</span></>}</span>
                        {canCancel && (
                            <span className="-my-2 ml-auto shrink-0">
                                {/* Quiet on purpose: rarely needed, and it must not compete with Start or Finish. */}
                                <ActionButton className="min-h-8! border-transparent! bg-transparent! px-1! py-0! font-normal! text-muted! underline-offset-2 hover:text-red-800! hover:underline" action={{ label: 'Cancel task', url: `/admin/tasks/${row.id}/cancel`, variant: 'danger', confirm: `Cancel "${row.title}" at ${row.room}?${row.assignee ? ` ${row.assignee} will be told.` : ''}` }} />
                            </span>
                        )}
                    </p>
                </div>
                {!canAssign && hasActions && <div className="ml-auto">{actions}</div>}
            </div>

            {(row.redo_note || row.instructions || (row.manual && row.guest_in) || row.blocks_room) && (
                <div className="space-y-1 text-xs">
                    {row.redo_note && <p className="rounded-md bg-red-50 px-2 py-1 text-red-900"><strong className="font-semibold">Redo:</strong> {row.redo_note}</p>}
                    {row.instructions && <p className="rounded-md bg-ivory px-2 py-1">{row.instructions}</p>}
                    {row.manual && row.guest_in && <p className="font-medium">A guest is staying in this room.</p>}
                    {row.blocks_room && <p className="font-medium text-red-800">No check-in allowed until this passes inspection.</p>}
                </div>
            )}

            {canAssign && (
                <div className="mt-auto flex flex-wrap items-center gap-2">
                    <div className="min-w-0 flex-[1_1_8rem] text-xs"><AssignSelect row={row} staff={staff} date={date} /></div>
                    {hasActions && <div className="ml-auto">{actions}</div>}
                </div>
            )}

        </li>
    );
}

function Board({ rows, can, staff, date, items, userId }: { rows: Row[]; can: any; staff: any[]; date: string; items: any[]; userId: number }) {
    const [stage, setStage] = useState('');
    const [person, setPerson] = useState('');

    const needsCleaner = rows.filter((r) => !r.assigned_to && r.status !== 'accepted');
    const late = rows.filter((r) => r.urgency === 'late');
    const count = (key: string) => rows.filter((r) => stageOf(r) === key).length;

    const shown = rows.filter((r) => (person === '' ? true : person === 'none' ? !r.assigned_to : String(r.assigned_to) === person) && (stage !== 'late' || r.urgency === 'late'));
    const rank = (r: Row) => (r.urgency === 'late' ? 0 : r.urgency === 'soon' ? 1 : 2);
    const inStage = (key: string) => shown.filter((r) => stageOf(r) === key).sort((a, b) => rank(a) - rank(b) || Number(b.assigned_to === userId) - Number(a.assigned_to === userId) || String(a.ready_by).localeCompare(String(b.ready_by)));
    const stages = STAGES.filter((s) => (stage && stage !== 'late' ? s.key === stage : true) && inStage(s.key).length > 0);
    const filtered = stage !== '' || person !== '';

    const card = (r: Row) => <TaskCard key={r.key} row={r} can={can} staff={staff} date={date} items={items} userId={userId} />;
    const grid = 'grid gap-2 sm:grid-cols-2 sm:gap-3 xl:grid-cols-3';

    if (rows.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-line/80 bg-white px-6 py-12 text-center shadow-xs">
                <span className="flex size-11 items-center justify-center rounded-xl bg-beige text-gold-dark"><Sparkles size={20} strokeWidth={1.75} aria-hidden /></span>
                <p className="mt-1 font-semibold">No cleaning tasks this day.</p>
                <p className="max-w-sm text-sm text-muted">Cleaning appears here automatically when a guest is due to check out.{can.manage ? ' You can also add a task for any room.' : ''}</p>
            </div>
        );
    }

    return (
        <div className="space-y-4 sm:space-y-5">
            <div role="group" aria-label="Show tasks by stage" className="grid grid-cols-5 gap-1.5 sm:gap-2">
                {STAGES.map((s) => (
                    <button key={s.key} type="button" aria-pressed={stage === s.key} onClick={() => setStage(stage === s.key ? '' : s.key)} className={cn('flex flex-col items-center gap-3 rounded-xl border px-1 py-2 text-center shadow-xs transition-colors pointer-coarse:min-h-11 lg:flex-row lg:p-3 lg:text-left', stage === s.key ? 'border-chocolate bg-chocolate text-ivory' : 'border-line/80 bg-white hover:border-gold')}>
                        <s.icon size={18} strokeWidth={1.75} className={cn('hidden shrink-0 lg:block', stage === s.key ? 'text-gold' : 'text-bronze')} aria-hidden />
                        <span className="min-w-0">
                            <span className="block text-xl leading-6 font-semibold tabular-nums">{count(s.key)}</span>
                            <span className={cn('mt-0.5 block text-[11px] leading-tight lg:mt-0 lg:truncate lg:text-xs', stage === s.key ? 'text-ivory/80' : 'text-muted')}>{s.label}</span>
                        </span>
                    </button>
                ))}
            </div>

            {(late.length > 0 || (can.manage && needsCleaner.length > 0)) && (
                <div className="flex flex-wrap gap-2">
                    {late.length > 0 && (
                        <button type="button" aria-pressed={stage === 'late'} onClick={() => setStage(stage === 'late' ? '' : 'late')} className={cn('inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors pointer-coarse:min-h-11', stage === 'late' ? 'border-red-800 bg-red-800 text-white' : 'border-red-200 bg-red-50 text-red-950 hover:border-red-400')}>
                            <AlertTriangle size={15} aria-hidden /> {late.length} late{stage === 'late' ? ', showing only these' : ''}
                        </button>
                    )}
                    {can.manage && needsCleaner.length > 0 && (
                        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm text-amber-950">
                            <UserPlus size={15} aria-hidden />
                            <button type="button" onClick={() => setPerson(person === 'none' ? '' : 'none')} aria-pressed={person === 'none'} className="font-medium underline-offset-2 hover:underline pointer-coarse:min-h-11">{needsCleaner.length} with no cleaner</button>
                            {staff.some((s) => s.cleaner) && <ActionButton action={{ label: 'Assign all for me', url: '/admin/tasks/auto-assign', data: { date }, confirm: 'Give each task to the cleaner with the fewest open tasks? Each person is notified.' }} />}
                        </div>
                    )}
                </div>
            )}

            {can.manage && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <label htmlFor="cleaner-filter" className="sr-only text-muted sm:not-sr-only">Show tasks for</label>
                    <Select id="cleaner-filter" className="min-w-0 flex-1 py-1! text-sm sm:w-48 sm:flex-none pointer-coarse:py-2!" options={[{ value: '', label: 'Everyone' }, { value: 'none', label: 'No cleaner yet' }, ...staff.map((s) => ({ value: String(s.id), label: s.name }))]} value={person} onChange={(e) => setPerson(e.target.value)} />
                    {filtered && <button type="button" onClick={() => { setStage(''); setPerson(''); }} className="text-xs font-medium text-muted underline-offset-2 hover:text-chocolate hover:underline pointer-coarse:min-h-11">Clear filters</button>}
                </div>
            )}

            {stages.length === 0 && <Empty>No tasks match. Try clearing the filters.</Empty>}

            {stages.map((s) => {
                const rowsHere = inStage(s.key);
                const body = <ul className={grid}>{rowsHere.map(card)}</ul>;
                const heading = <><span className="font-semibold">{s.label}</span> <span className="rounded-full bg-beige px-2 text-xs font-medium">{rowsHere.length}</span></>;

                // Finished work stays out of the way until someone asks for it.
                if (s.key === 'done' && stage !== 'done') {
                    return (
                        <details key={s.key}>
                            <summary className="cursor-pointer text-base">{heading}</summary>
                            <div className="mt-3">{body}</div>
                        </details>
                    );
                }

                return (
                    <section key={s.key} aria-label={s.label}>
                        <h2 className="mb-1.5 text-base sm:mb-0">{heading}</h2>
                        <p className="mb-2 hidden text-xs text-muted sm:block">{s.key === 'inspect' && !can.inspect ? 'The work is finished and waiting for someone to check it.' : s.key === 'waiting' && !can.manage ? 'Cleaning can start once the guest has checked out.' : s.hint}</p>
                        {body}
                    </section>
                );
            })}
        </div>
    );
}

function Workload({ rows, staff, date, can }: { rows: Row[]; staff: any[]; date: string; can: any }) {
    const openStatuses = ['waiting', 'pending', 'assigned', 'rejected', 'in_progress'];
    // Managers can take a room themselves, but only appear here once they have.
    const people = staff.map((s) => {
        const theirs = rows.filter((r) => r.assigned_to === s.id);

        return { ...s, rows: theirs, open: theirs.filter((r) => openStatuses.includes(r.status)).length, doing: theirs.filter((r) => r.status === 'in_progress').length, done: theirs.filter((r) => ['accepted', 'for_inspection'].includes(r.status)).length };
    }).filter((p) => p.cleaner || p.rows.length > 0);
    const hasCleaners = staff.some((s) => s.cleaner);
    const busiest = Math.max(1, ...people.map((p) => p.open + p.done));
    const unassigned = rows.filter((r) => !r.assigned_to && r.status !== 'accepted');

    return (
        <div className="space-y-4">
            {!hasCleaners && (
                <Card>
                    <div className="flex flex-col items-center gap-2 py-6 text-center">
                        <span className="flex size-11 items-center justify-center rounded-xl bg-beige text-gold-dark"><UserPlus size={20} strokeWidth={1.75} aria-hidden /></span>
                        <p className="mt-1 font-semibold">No cleaning staff yet.</p>
                        <p className="max-w-sm text-sm text-muted">Add each cleaner as a team member. They get their own sign-in and see only their rooms.</p>
                        {can.invite && <Link href="/admin/team/create" className={cn(buttonClass('primary'), 'mt-2')}>Add a cleaner</Link>}
                    </div>
                </Card>
            )}
            {unassigned.length > 0 && (
                <Card title={`Needs a cleaner (${unassigned.length})`} action={hasCleaners ? <ActionButton action={{ label: 'Assign all for me', url: '/admin/tasks/auto-assign', data: { date }, confirm: 'Give each task to the cleaner with the fewest open tasks? Each person is notified.' }} /> : undefined}>
                    <ul className="divide-y divide-line/70">
                        {unassigned.map((r) => (
                            <li key={r.key} className="flex flex-wrap items-center justify-between gap-2 py-1.5 text-sm">
                                <span><span className="font-medium">{r.room}</span> <span className="text-xs text-muted">{r.title ?? 'Clean after check-out'}</span></span>
                                {staff.length > 0 && <AssignSelect row={r} staff={staff} date={date} />}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
            {people.length > 0 && (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {people.map((p) => (
                        <section key={p.id} className="rounded-xl border border-line/80 bg-white p-4 shadow-xs">
                            <div className="flex items-center gap-3">
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-chocolate text-xs font-semibold text-gold" aria-hidden>{p.name.split(' ').map((w: string) => w[0]).slice(0, 2).join('').toUpperCase()}</span>
                                <div className="min-w-0">
                                    <h3 className="truncate text-sm font-semibold">{p.name}</h3>
                                    <p className="text-xs text-muted">{p.open - p.doing} to do · {p.doing} in progress · {p.done} finished</p>
                                </div>
                            </div>
                            <div className="mt-3 flex h-1.5 overflow-hidden rounded-full bg-beige" aria-hidden>
                                <span className="bg-emerald-600" style={{ width: `${(p.done / busiest) * 100}%` }} />
                                <span className="bg-gold-dark" style={{ width: `${(p.open / busiest) * 100}%` }} />
                            </div>
                            {p.rows.length > 0 ? (
                                <ul className="mt-3 flex flex-wrap gap-1">
                                    {p.rows.map((r: Row) => <li key={r.key} title={STATUS[r.status]?.label} className={cn('rounded-full border px-2 py-0.5 text-xs font-medium', STATUS[r.status]?.tone)}>{r.room}</li>)}
                                </ul>
                            ) : <p className="mt-3 text-xs text-muted">Nothing assigned this day.</p>}
                        </section>
                    ))}
                </div>
            )}
        </div>
    );
}

/** Any job on any room, whether or not a guest has just left. Sits with the checkout cleanings on one list. */
function addTaskFields({ rooms, taskTypes, staff, date, isToday }: any): FieldDef[] {
    return [
        { name: 'room_id', label: 'Room', type: 'select', required: true, options: rooms },
        { name: 'title', label: 'What needs doing?', type: 'text', required: true, help: 'For example: Replace the bathroom curtain.' },
        { name: 'type', label: 'Type', type: 'select', required: true, options: taskTypes, default: 'cleaning' },
        { name: 'date', label: 'Due date', type: 'date', required: true, default: date },
        { name: 'time', label: 'Due time', type: 'time', default: '17:00' },
        { name: 'user_id', label: 'Assign to', type: 'select', options: staff.map((s: any) => ({ value: s.id, label: s.name })), help: 'They are notified straight away. You can also assign later.' },
        { name: 'priority', label: 'Priority', type: 'select', required: true, default: 'normal', options: [{ value: 'normal', label: 'Normal' }, { value: 'high', label: 'High' }] },
        { name: 'instructions', label: 'Instructions', type: 'textarea' },
        { name: 'requires_inspection', label: 'Someone must inspect this when it is finished', type: 'checkbox', default: true },
        ...(isToday ? [{ name: 'blocks_room', label: 'Do not allow check-in until this passes inspection', type: 'checkbox' as const, default: false }] : []),
    ];
}

const VIEWS = [
    { key: 'tasks', label: 'Tasks' },
    { key: 'workload', label: 'By cleaner' },
    { key: 'schedule', label: 'Schedule', desktopOnly: true },
];

export default function Housekeeping({ tasks, planned = [], date, isToday, prevDate, nextDate, nowMinute, window: turnWindow, staff, can, tabs, items, rooms = [], taskTypes = [] }: any) {
    useLiveRefresh(['tasks', 'planned', 'nowMinute'], 20_000);
    const page = usePage<any>();
    const path = page.url.split('?')[0];
    const unread = page.props.auth?.user?.unread;
    const userId = page.props.auth?.user?.id;
    const [view, setView] = useState('tasks');
    const rows: Row[] = useMemo(() => [...tasks, ...planned], [tasks, planned]);
    const finished = rows.filter((r) => r.status === 'accepted').length;
    const go = (target: string) => router.get('/admin/housekeeping', { date: target }, { preserveScroll: true });

    // A new notice usually means new work, so the list follows without a manual refresh.
    const lastUnread = useRef(unread);
    useEffect(() => {
        if (unread === lastUnread.current) return;
        lastUnread.current = unread;
        router.reload({ only: ['tasks', 'planned', 'nowMinute'] });
    }, [unread]);

    const nav = (
        <div className="flex flex-wrap items-center gap-2">
            {can.manage && <ActionButton small={false} action={{ label: 'Add task', url: '/admin/tasks', variant: 'primary', fields: addTaskFields({ rooms, taskTypes, staff, date, isToday }) }} />}
            <div className="flex items-center gap-1">
                <button type="button" aria-label="Previous day" onClick={() => go(prevDate)} className={buttonClass('secondary')}><ChevronLeft size={16} aria-hidden /></button>
                <button type="button" onClick={() => go(new Date().toLocaleDateString('en-CA', { timeZone: TZ }))} aria-current={isToday ? 'date' : undefined} title={isToday ? undefined : 'Back to today'} className={cn(buttonClass('ghost'), 'min-w-28 font-semibold')}>{isToday ? 'Today' : dateOnly(date)}</button>
                <button type="button" aria-label="Next day" onClick={() => go(nextDate)} className={buttonClass('secondary')}><ChevronRight size={16} aria-hidden /></button>
            </div>
        </div>
    );

    return (
        <AdminLayout title="Cleaning" subtitle={rows.length ? `${finished} of ${rows.length} finished${isToday ? ' today' : ''}` : undefined} actions={nav}>
            <Tabs tabs={tabs} current={path} />
            {can.manage && (
                <div className="mb-4 flex justify-end">
                    <div role="group" aria-label="View" className="flex rounded-lg border border-line bg-white p-0.5 shadow-xs">
                        {VIEWS.map((v) => (
                            <button key={v.key} type="button" aria-pressed={view === v.key} onClick={() => setView(v.key)} className={cn('rounded-md px-3 py-1 text-sm font-medium transition-colors pointer-coarse:min-h-11', v.desktopOnly && 'hidden md:block', view === v.key ? 'bg-chocolate text-ivory' : 'text-muted hover:text-chocolate')}>{v.label}</button>
                        ))}
                    </div>
                </div>
            )}
            {view === 'tasks' || !can.manage ? <Board rows={rows} can={can} staff={staff} date={date} items={items} userId={userId} /> : null}
            {view === 'workload' && can.manage && <Workload rows={rows} staff={staff} date={date} can={can} />}
            {view === 'schedule' && can.manage && <Timeline rows={rows} window={turnWindow} nowMinute={nowMinute} />}
        </AdminLayout>
    );
}
