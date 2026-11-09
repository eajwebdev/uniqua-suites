import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { Check, Copy, MessageCircleQuestion, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button, Card, cn, confirmDialog, copyToClipboard, Empty, Field, Input, Modal } from '@/components/ui';
import AdminLayout from '@/layouts/AdminLayout';

type Faq = { id: number; topic: string | null; question: string; answer: string; times_copied: number; updated_by: string | null; updated_at: string };

const area = 'w-full rounded-lg border border-line bg-white px-3 py-2 text-sm shadow-xs focus:border-gold-dark focus:ring-2 focus:ring-gold/30 focus:outline-none pointer-coarse:text-base';

/** Copies the answer exactly as written, ready to paste in Messenger, and counts it. */
function CopyAnswer({ faq }: { faq: Faq }) {
    const [copied, setCopied] = useState(false);
    async function copy() {
        if (!(await copyToClipboard(faq.answer))) return;
        setCopied(true);
        setTimeout(() => setCopied(false), 1800);
        axios.post(`/admin/faqs/${faq.id}/copied`).catch(() => undefined);
    }
    return (
        <Button small variant="primary" onClick={copy} aria-label={`Copy the answer to: ${faq.question}`}>
            {copied ? <Check size={14} aria-hidden /> : <Copy size={14} aria-hidden />}
            <span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span>
        </Button>
    );
}

function FaqModal({ faq, topics, onClose }: { faq?: Faq; topics: string[]; onClose: () => void }) {
    const form = useForm({ topic: faq?.topic ?? '', question: faq?.question ?? '', answer: faq?.answer ?? '' });

    return (
        <Modal open wide title={faq ? 'Edit question' : 'New question'} onClose={onClose}>
            <form onSubmit={(ev) => { ev.preventDefault(); form.post(faq ? `/admin/faqs/${faq.id}` : '/admin/faqs', { preserveScroll: true, onSuccess: onClose }); }} className="space-y-3">
                <Field label="Topic" help="Optional. Groups similar questions, e.g. Check-in, Payment, Parking." error={form.errors.topic}>
                    <Input list="faq-topics" maxLength={60} value={form.data.topic} onChange={(ev) => form.setData('topic', ev.target.value)} />
                    <datalist id="faq-topics">{topics.map((t) => <option key={t} value={t} />)}</datalist>
                </Field>
                <Field label="Question" required error={form.errors.question}>
                    <Input required autoFocus maxLength={255} placeholder="What time is check-in?" value={form.data.question} onChange={(ev) => form.setData('question', ev.target.value)} />
                </Field>
                <Field label="Answer" required help="Write it the way you would send it. Line breaks and emoji are kept when pasted." error={form.errors.answer}>
                    <textarea required rows={8} maxLength={5000} className={area} placeholder={'Hi! Check-in is from 2:00 PM. 😊\nEarly check-in depends on availability.'} value={form.data.answer} onChange={(ev) => form.setData('answer', ev.target.value)} />
                </Field>
                <div className="flex items-center justify-between gap-2">
                    <span className="text-xs text-muted">{form.data.answer.length.toLocaleString()} / 5,000</span>
                    <div className="flex gap-2"><Button onClick={onClose}>Cancel</Button><Button type="submit" variant="primary" disabled={form.processing}>Save</Button></div>
                </div>
            </form>
        </Modal>
    );
}

export default function Faqs({ faqs, cameras }: { faqs: Faq[]; cameras: boolean }) {
    const [query, setQuery] = useState('');
    const [topic, setTopic] = useState<string | null>(null);
    const [modal, setModal] = useState<{ faq?: Faq } | null>(null);

    const topics = useMemo(() => [...new Set(faqs.map((f) => f.topic).filter((t): t is string => !!t))], [faqs]);
    const words = query.trim().toLowerCase();
    const shown = faqs.filter((f) => (topic === null || f.topic === topic) && (!words || `${f.question} ${f.answer} ${f.topic ?? ''}`.toLowerCase().includes(words)));

    async function remove(faq: Faq) {
        if (await confirmDialog({ title: 'Delete this question?', message: `"${faq.question}" and its answer will be removed.`, confirmLabel: 'Delete', danger: true })) {
            router.post(`/admin/faqs/${faq.id}/delete`, {}, { preserveScroll: true });
        }
    }

    return (
        <AdminLayout title="FAQ" subtitle={`Ready answers to what ${cameras ? 'renters' : 'guests'} ask. Copy one and paste it in Messenger.`}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Button variant="primary" onClick={() => setModal({})}><Plus size={16} aria-hidden /> New question</Button>
                <span className="relative ml-auto w-full sm:w-72">
                    <Search size={15} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" aria-hidden />
                    <Input type="search" aria-label="Search questions and answers" placeholder="Search questions and answers" className="w-full pl-9" value={query} onChange={(ev) => setQuery(ev.target.value)} />
                </span>
            </div>

            {topics.length > 0 && (
                <div className="mb-4 flex flex-wrap gap-1.5" role="group" aria-label="Topic">
                    {[null, ...topics].map((t) => (
                        <button key={t ?? '__all'} type="button" aria-pressed={topic === t} onClick={() => setTopic(t)}
                            className={cn('rounded-full border px-3 py-1 text-xs font-medium transition-colors pointer-coarse:py-2', topic === t ? 'border-chocolate bg-chocolate text-ivory' : 'border-line bg-white text-espresso hover:bg-ivory')}>
                            {t ?? `All (${faqs.length})`}
                        </button>
                    ))}
                </div>
            )}

            {faqs.length === 0 ? (
                <Card><Empty>No questions yet. Add the ones guests ask most, like check-in time, payment and parking, then copy the answer whenever someone asks.</Empty></Card>
            ) : shown.length === 0 ? (
                <Card><Empty>Nothing matches. Try other words or another topic.</Empty></Card>
            ) : (
                <div className="grid gap-3 lg:grid-cols-2">
                    {shown.map((faq) => (
                        <article key={faq.id} className="flex flex-col rounded-xl border border-line bg-white p-4 shadow-xs">
                            <div className="mb-2 flex items-start gap-2">
                                <MessageCircleQuestion size={18} className="mt-0.5 shrink-0 text-gold-dark" aria-hidden />
                                <div className="min-w-0 flex-1">
                                    {faq.topic && <span className="mb-1 inline-block rounded-full bg-beige px-2 py-0.5 text-[11px] font-medium text-espresso">{faq.topic}</span>}
                                    <h2 className="font-semibold text-chocolate">{faq.question}</h2>
                                </div>
                            </div>
                            <p className="mb-3 flex-1 rounded-lg bg-ivory/70 px-3 py-2 text-sm whitespace-pre-line text-espresso">{faq.answer}</p>
                            <div className="flex flex-wrap items-center gap-2">
                                <CopyAnswer faq={faq} />
                                <Button small onClick={() => setModal({ faq })} aria-label={`Edit: ${faq.question}`}><Pencil size={14} aria-hidden /> Edit</Button>
                                <Button small onClick={() => remove(faq)} aria-label={`Delete: ${faq.question}`}><Trash2 size={14} className="shrink-0" aria-hidden /></Button>
                                <span className="ml-auto text-xs text-muted">{faq.times_copied > 0 ? `Copied ${faq.times_copied.toLocaleString()}×` : 'Not copied yet'}</span>
                            </div>
                        </article>
                    ))}
                </div>
            )}

            {modal && <FaqModal faq={modal.faq} topics={topics} onClose={() => setModal(null)} />}
        </AdminLayout>
    );
}
