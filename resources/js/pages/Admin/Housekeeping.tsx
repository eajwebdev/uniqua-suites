import { router, useForm, usePage } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { useState } from 'react';
import { ActionButton, Button, cn, Empty, Field, Input, Modal, Select, StatusChip, Tabs } from '@/components/ui';
import { dateTime } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

const COLUMNS = [
    { key: 'todo', label: 'To do', statuses: ['pending', 'assigned', 'rejected'] },
    { key: 'doing', label: 'In progress', statuses: ['in_progress'] },
    { key: 'inspect', label: 'Awaiting inspection', statuses: ['for_inspection'] },
];

function CompleteModal({ task, onClose }: any) {
    const form = useForm<any>({ checklist: task.checklist, notes: '', photos: [] });
    const toggle = (i: number) => form.setData('checklist', form.data.checklist.map((c: any, n: number) => (n === i ? { ...c, done: !c.done } : c)));

    return (
        <Modal open title={`Finish cleaning: ${task.room}`} onClose={onClose}>
            <form onSubmit={(e) => { e.preventDefault(); form.post(`/admin/tasks/${task.id}/complete`, { forceFormData: true, preserveScroll: true, onSuccess: onClose }); }} className="space-y-3">
                <fieldset>
                    <legend className="mb-1 text-sm font-medium">Checklist</legend>
                    {form.data.checklist.map((c: any, i: number) => (
                        <label key={c.label} className="flex items-center gap-2 py-1.5 text-sm"><input type="checkbox" className="size-4" checked={c.done} onChange={() => toggle(i)} /> {c.label}</label>
                    ))}
                </fieldset>
                <Field label="Before and after photos" error={(form.errors as any).photos}>
                    <Input type="file" accept="image/*" multiple capture="environment" onChange={(e) => form.setData('photos', Array.from(e.target.files ?? []))} />
                </Field>
                <Field label="Notes" error={(form.errors as any).notes}>
                    <textarea rows={2} className="w-full rounded-md border border-line px-3 py-2 text-sm" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </Field>
                <p className="text-xs text-muted">The room stays not-ready until a manager inspects it.</p>
                <div className="flex justify-end gap-2">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="primary" disabled={form.processing}>Send for inspection</Button>
                </div>
            </form>
        </Modal>
    );
}

function TaskCard({ task, staff, can }: any) {
    const [completing, setCompleting] = useState(false);
    const done = task.checklist.filter((c: any) => c.done).length;

    return (
        <li className={cn('rounded-lg border bg-white p-3', task.overdue ? 'border-red-300' : 'border-line')}>
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className="font-medium">{task.room} <span className="text-xs text-muted">{task.unit}</span></div>
                    <div className="text-xs text-muted">{task.type === 'turnover' ? 'Turnover clean' : task.type}</div>
                </div>
                <StatusChip value={task.status} />
            </div>
            {task.due_at && <p className={cn('mt-1 flex items-center gap-1 text-xs', task.overdue ? 'font-medium text-red-800' : 'text-muted')}><Clock size={12} aria-hidden /> {task.overdue ? 'Overdue: ' : 'Due '}{dateTime(task.due_at)}</p>}
            {task.instructions && <p className="mt-1 text-sm">{task.instructions}</p>}
            {task.status === 'for_inspection' && <p className="mt-1 text-xs text-muted">Checklist {done}/{task.checklist.length} · {task.photos} photo(s){task.notes ? ` · ${task.notes}` : ''}</p>}
            <p className="mt-1 text-xs text-muted">{task.assignee ? `Assigned: ${task.assignee}` : 'Unassigned'}</p>

            <div className="mt-2 flex flex-wrap gap-1.5">
                {can.manage && ['pending', 'assigned', 'rejected'].includes(task.status) && (
                    <Select aria-label={`Assign ${task.room}`} className="w-auto py-1 text-xs" placeholder="Assign…" options={staff.map((s: any) => ({ value: s.id, label: s.name }))} value={task.assigned_to ?? ''} onChange={(e) => e.target.value && router.post(`/admin/tasks/${task.id}/assign`, { user_id: e.target.value }, { preserveScroll: true })} />
                )}
                {['pending', 'assigned', 'rejected'].includes(task.status) && <ActionButton action={{ label: 'Start', url: `/admin/tasks/${task.id}/start`, variant: 'primary' }} />}
                {task.status === 'in_progress' && <Button small variant="primary" onClick={() => setCompleting(true)}>Finish</Button>}
                {can.inspect && task.status === 'for_inspection' && (
                    <>
                        <ActionButton action={{ label: 'Pass: mark ready', url: `/admin/tasks/${task.id}/inspect`, data: { accepted: true }, variant: 'primary' }} />
                        <ActionButton action={{ label: 'Fail', url: `/admin/tasks/${task.id}/inspect`, data: { accepted: false }, variant: 'danger', fields: [{ name: 'note', label: 'What needs redoing?', type: 'text', required: true }] }} />
                    </>
                )}
            </div>
            {completing && <CompleteModal task={task} onClose={() => setCompleting(false)} />}
        </li>
    );
}

export default function Housekeeping({ tasks, staff, can, tabs }: any) {
    const path = usePage().url.split('?')[0];

    return (
        <AdminLayout title="Housekeeping">
            <Tabs tabs={tabs} current={path} />
            <div className="grid gap-4 md:grid-cols-3">
                {COLUMNS.map((col) => {
                    const items = tasks.filter((t: any) => col.statuses.includes(t.status));
                    return (
                        <section key={col.key}>
                            <h2 className="mb-2 text-sm font-semibold">{col.label} ({items.length})</h2>
                            {items.length === 0 ? <Empty>Nothing here.</Empty> : (
                                <ul className="space-y-2">{items.map((t: any) => <TaskCard key={t.id} task={t} staff={staff} can={can} />)}</ul>
                            )}
                        </section>
                    );
                })}
            </div>
        </AdminLayout>
    );
}
