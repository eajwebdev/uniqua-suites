import { useForm } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, CheckCircle2, Clock, Lock, ReceiptText, Settings2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Button, Card, cn, Empty, Field, FormModal, Input, tableClass, tdClass, theadClass, thClass, trClass } from '@/components/ui';
import AdminLayout from '@/layouts/AdminLayout';
import { peso } from '@/lib/format';

type Summary = {
    status: 'trial' | 'active' | 'due' | 'locked' | 'not_set'; label: string; fee_cents: number;
    trial_start: string | null; trial_end: string | null; start: string | null; end: string | null; grace_days: number;
    paid_through: string | null; days_left: number | null; locks_on: string | null; days_to_lock: number | null; message: string | null;
};
type Bill = { id: number; period_start: string; period_end: string; due_on: string; amount_cents: number; status: 'paid' | 'unpaid'; paid_on: string | null; paid_amount_cents: number | null; note: string | null; recorded_by: string | null };

const TONE: Record<Summary['status'], { box: string; icon: any }> = {
    trial: { box: 'border-sky-200 bg-sky-50 text-sky-950', icon: Clock },
    active: { box: 'border-emerald-200 bg-emerald-50 text-emerald-950', icon: CheckCircle2 },
    due: { box: 'border-amber-200 bg-amber-50 text-amber-950', icon: AlertTriangle },
    locked: { box: 'border-red-200 bg-red-50 text-red-950', icon: Lock },
    not_set: { box: 'border-line bg-beige text-espresso', icon: Settings2 },
};

const fmt = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '—');
const pesos = (cents: number) => (cents / 100).toFixed(2);
const plural = (n: number | null, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

export default function Subscription({ subscription: s, canManage, bills, today }: { subscription: Summary; canManage: boolean; bills: Bill[]; today: string }) {
    const tone = TONE[s.status];
    const Icon = tone.icon;
    const open = bills.filter((b) => b.status === 'unpaid').sort((a, b) => a.period_start.localeCompare(b.period_start))[0];
    const [paying, setPaying] = useState<Bill | null>(null);

    const headline = {
        trial: s.trial_end === today ? 'The free trial ends today.' : `Free trial until ${fmt(s.trial_end)}.`,
        active: s.days_left === 0 ? 'Paid through today.' : `Paid, ${plural(s.days_left, 'day')} left.`,
        due: `Payment due. The system locks in ${plural(s.days_to_lock, 'day')}.`,
        locked: 'The system is locked for everyone except the Administrator.',
        not_set: 'No trial or subscription dates are set, so the system never locks.',
    }[s.status];

    return (
        <AdminLayout title="Subscription" subtitle={canManage ? 'The monthly system subscription. Only the Administrator can change it.' : 'Your monthly system subscription with EAJ Web Development Services.'}>
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <section className={cn('flex gap-4 rounded-xl border p-5', tone.box)} aria-live="polite">
                        <Icon size={28} strokeWidth={1.75} className="mt-0.5 shrink-0" aria-hidden />
                        <div className="min-w-0">
                            <p className="text-xs font-semibold tracking-wider uppercase">{s.label}</p>
                            <p className="mt-0.5 text-lg font-semibold">{headline}</p>
                            {s.message && <p className="mt-1 text-sm">{s.message}</p>}
                        </div>
                    </section>

                    <div className="grid gap-3 sm:grid-cols-4">
                        {[
                            ['Monthly fee', peso(s.fee_cents)],
                            ['Paid through', fmt(s.paid_through)],
                            ['Grace period', plural(s.grace_days, 'day')],
                            ['Locks on', s.locks_on ? fmt(s.locks_on) : 'Never'],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl border border-line/80 bg-white px-4 py-3">
                                <p className="text-xs text-muted">{label}</p>
                                <p className="mt-0.5 font-semibold">{value}</p>
                            </div>
                        ))}
                    </div>

                    <Card title={<><ReceiptText size={17} aria-hidden /> Bills</>}>
                        {bills.length === 0 ? <Empty>No bills yet. The first one is created from the subscription start date.</Empty> : (
                            <div className="-mx-5 overflow-x-auto">
                                <table className={tableClass}>
                                    <thead className={theadClass}><tr><th className={thClass}>Period</th><th className={thClass}>Due</th><th className={thClass}>Amount</th><th className={thClass}>Status</th><th className={thClass}>Paid</th>{canManage && <th className={thClass}><span className="sr-only">Action</span></th>}</tr></thead>
                                    <tbody>
                                        {bills.map((b) => (
                                            <tr key={b.id} className={trClass}>
                                                <td className={tdClass}>{fmt(b.period_start)} to {fmt(b.period_end)}</td>
                                                <td className={cn(tdClass, b.status === 'unpaid' && b.due_on <= today && 'font-semibold text-red-800')}>{fmt(b.due_on)}</td>
                                                <td className={tdClass}>{peso(b.amount_cents)}</td>
                                                <td className={tdClass}>
                                                    <span className={cn('inline-flex rounded-full border px-2 py-0.5 text-xs font-medium', b.status === 'paid' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900')}>{b.status === 'paid' ? 'Paid' : 'Unpaid'}</span>
                                                </td>
                                                <td className={cn(tdClass, 'text-muted')}>{b.paid_on ? `${fmt(b.paid_on)} · ${peso(b.paid_amount_cents ?? 0)}${b.note ? ` · ${b.note}` : ''}` : '—'}</td>
                                                {canManage && <td className={cn(tdClass, 'text-right')}>{b.status === 'unpaid' && <Button small variant="primary" onClick={() => setPaying(b)}>Mark paid</Button>}</td>}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <Card title={<><CalendarClock size={17} aria-hidden /> Dates</>}>
                        {canManage ? <DatesForm key={[s.trial_start, s.trial_end, s.start, s.end, s.fee_cents, s.grace_days].join('|')} s={s} /> : (
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                {[['Free trial', s.trial_end ? `${fmt(s.trial_start)} to ${fmt(s.trial_end)}` : 'None'], ['Subscription starts', fmt(s.start)], ['Grace period', `${plural(s.grace_days, 'day')} after the paid-through date`], ['Monthly fee', peso(s.fee_cents)]].map(([k, v]) => (
                                    <div key={k}><dt className="text-xs text-muted">{k}</dt><dd className="font-medium">{v}</dd></div>
                                ))}
                            </dl>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card title="Next bill">
                        {open ? (
                            <div className="space-y-3 text-sm">
                                <div>
                                    <p className="text-2xl font-semibold">{peso(open.amount_cents)}</p>
                                    <p className="text-muted">For {fmt(open.period_start)} to {fmt(open.period_end)}</p>
                                    <p className={cn('mt-1 font-medium', open.due_on <= today ? 'text-red-800' : '')}>Due {fmt(open.due_on)}</p>
                                </div>
                                {canManage
                                    ? <Button variant="primary" className="w-full" onClick={() => setPaying(open)}>Mark paid</Button>
                                    : <p className="text-muted">Send the payment to EAJ Web Development Services by the due date. It shows here as paid once received.</p>}
                                <p className="rounded-lg bg-ivory px-3 py-2 text-xs text-muted">Once paid, the system is paid through {fmt(open.period_end)} and the bill for the following month is created automatically.</p>
                            </div>
                        ) : <p className="text-sm text-muted">No bill is open. Set the subscription start date to begin monthly billing.</p>}
                    </Card>
                    <Card title="What happens when it ends">
                        <ol className="list-decimal space-y-1.5 pl-5 text-sm text-muted">
                            <li>3 days and 1 day before the paid-through date, the Owner and Administrator get a reminder in the bell.</li>
                            <li>For {plural(s.grace_days, 'day')} after it, a reminder comes every day and a banner shows on every page.</li>
                            <li>After that, the admin side locks for everyone but the Administrator. The public website stays online and no data is lost.</li>
                            <li>Marking the bill paid unlocks the system immediately and creates next month's bill.</li>
                        </ol>
                    </Card>
                </div>
            </div>

            {paying && (
                <FormModal
                    title={`Mark paid: ${fmt(paying.period_start)} to ${fmt(paying.period_end)}`}
                    url={`/admin/subscription/bills/${paying.id}/pay`}
                    submitLabel="Mark paid"
                    confirm={`The system will be paid through ${fmt(paying.period_end)}, and the next month's bill will be created.`}
                    fields={[
                        { name: 'paid_on', label: 'Paid on', type: 'date', required: true },
                        { name: 'amount', label: 'Amount received (PHP)', type: 'money', required: true },
                        { name: 'note', label: 'Note', type: 'text', help: 'For example the GCash reference number.' },
                    ]}
                    data={{ paid_on: today, amount: pesos(paying.amount_cents), note: '' }}
                    onClose={() => setPaying(null)}
                />
            )}
        </AdminLayout>
    );
}

/** Re-created (by its key) whenever the saved values change, so a save after marking a bill paid never sends old dates. */
function DatesForm({ s }: { s: Summary }) {
    const plan = useForm({ monthly_fee: pesos(s.fee_cents), trial_start: s.trial_start ?? '', trial_end: s.trial_end ?? '', start: s.start ?? '', end: s.end ?? '', grace_days: String(s.grace_days) });
    const pe = plan.errors as Record<string, string>;

    function savePlan(e: FormEvent) {
        e.preventDefault();
        plan.post('/admin/subscription', { preserveScroll: true });
    }

    return (
        <form onSubmit={savePlan} className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Trial starts" error={pe.trial_start}><Input type="date" value={plan.data.trial_start} onChange={(e) => plan.setData('trial_start', e.target.value)} /></Field>
                    <Field label="Trial ends" error={pe.trial_end} help="Last day of the free trial."><Input type="date" value={plan.data.trial_end} onChange={(e) => plan.setData('trial_end', e.target.value)} /></Field>
                    <Field label="Subscription starts" error={pe.start} help="The first monthly bill starts on this day."><Input type="date" value={plan.data.start} onChange={(e) => plan.setData('start', e.target.value)} /></Field>
                    <Field label="Paid through" error={pe.end} help="Moves forward by itself each time a bill is marked paid. Change it only to correct a mistake."><Input type="date" value={plan.data.end} onChange={(e) => plan.setData('end', e.target.value)} /></Field>
                    <Field label="Monthly fee (PHP)" required error={pe.monthly_fee} help="Used for the open bill and every new one. Paid bills never change."><Input inputMode="decimal" required value={plan.data.monthly_fee} onChange={(e) => plan.setData('monthly_fee', e.target.value)} /></Field>
                    <Field label="Grace period (days)" required error={pe.grace_days} help="Days after the paid-through date before the system locks. A reminder goes out on each of them."><Input type="number" min={0} max={60} required value={plan.data.grace_days} onChange={(e) => plan.setData('grace_days', e.target.value)} /></Field>
                </div>
                <div className="flex justify-end"><Button type="submit" variant="primary" disabled={plan.processing || !plan.isDirty}>{plan.processing ? 'Saving…' : 'Save dates'}</Button></div>
            </form>
    );
}
