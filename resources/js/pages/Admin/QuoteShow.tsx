import { Link } from '@inertiajs/react';
import { Download, Pencil, Printer } from 'lucide-react';
import { Button, buttonClass, Card, CopyButton } from '@/components/ui';
import { dateOnly, dateTime, peso } from '@/lib/format';
import AdminLayout from '@/layouts/AdminLayout';

const pesos = (cents?: number) => (cents ? (Math.abs(cents) / 100).toFixed(2) : undefined);

/** Everything that made this quote, so an update only has to change what changed. */
function updateLink(q: any): string {
    const amount = (type: string) => q.items.filter((i: any) => i.type === type).reduce((sum: number, i: any) => sum + Number(i.amount_cents), 0);
    const params = Object.entries({
        reference: q.reference, room_id: q.room_id, check_in: q.check_in, check_out: q.check_out, guests: q.guest_count, guest_name: q.guest_name,
        source_id: q.source_id, early_time: q.early_time, parking: pesos(amount('parking')), discount: pesos(amount('discount')),
    }).filter(([, v]) => v !== null && v !== undefined && v !== '');

    return `/admin/quotes/create?${new URLSearchParams(params.map(([k, v]) => [k, String(v)])).toString()}`;
}

export default function QuoteShow({ quote: q, history, copyText }: any) {
    return (
        <AdminLayout
            title={`Quote ${q.reference} v${q.version}`}
            actions={
                <>
                    <CopyButton text={copyText} label="Copy for Messenger" small={false} />
                    <Link href={updateLink(q)} className={buttonClass('secondary')}><Pencil size={15} aria-hidden /> Update quote</Link>
                    <Button onClick={() => window.print()}><Printer size={15} /> Print</Button>
                    {!q.booking_id && <Link href={`/admin/bookings/create?room_id=${q.room_id}&check_in=${q.check_in}&check_out=${q.check_out}`} className={buttonClass('secondary')}>Create booking</Link>}
                    <a href={`/admin/quotes/${q.id}/pdf`} className={buttonClass('primary')}><Download size={15} /> PDF</a>
                </>
            }
        >
            <div className="grid gap-4 lg:grid-cols-3">
                <Card title="Quotation" className="lg:col-span-2">
                    <p className="mb-3 text-sm">
                        {q.guest_name && <>{q.guest_name} · </>}{q.room}, {q.property}<br />
                        {dateOnly(q.check_in)} to {dateOnly(q.check_out)}, {q.guest_count} guest(s)
                    </p>
                    <table className="w-full text-sm">
                        <tbody>
                            {q.items.map((i: any) => (
                                <tr key={i.id} className="border-b border-line"><td className="py-1.5 pr-3">{i.description}</td><td className="py-1.5 text-right tabular-nums">{peso(i.amount_cents)}</td></tr>
                            ))}
                            <tr className="font-semibold"><td className="py-2">Total</td><td className="py-2 text-right tabular-nums">{peso(q.total_cents)}</td></tr>
                        </tbody>
                    </table>
                    {q.warnings.map((w: string) => <p key={w} className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">{w}</p>)}
                    <p className="mt-3 text-xs text-muted">This quotation does not reserve the suite.</p>
                </Card>
                <div className="space-y-4">
                    <Card title="Message preview">
                        <p className="text-sm whitespace-pre-wrap">{copyText}</p>
                        <p className="mt-2 text-xs text-muted">Nothing has been sent. Copy this and send it yourself.</p>
                    </Card>
                    <Card title="Versions">
                        <ul className="space-y-1 text-sm">
                            {history.map((h: any) => (
                                <li key={h.id}>
                                    <Link href={`/admin/quotes/${h.id}`} className={h.id === q.id ? 'font-semibold' : 'underline'}>v{h.version}</Link>: {peso(h.total_cents)} <span className="text-xs text-muted">{dateTime(h.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
