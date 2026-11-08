import { Head, router } from '@inertiajs/react';
import { ExternalLink, Lock, LogOut } from 'lucide-react';
import { Button, buttonClass, cn } from '@/components/ui';
import { peso } from '@/lib/format';

/** Everyone but the Administrator sees only this once an unpaid subscription passes its grace period. */
export default function Locked({ subscription, contact }: { subscription: { message: string; fee_cents: number; paid_through: string | null }; contact: { name: string; person?: string; phone?: string | null; email?: string | null } }) {
    return (
        <main className="flex min-h-dvh items-center justify-center bg-ivory p-4">
            <Head title="System locked" />
            <section className="w-full max-w-lg overflow-hidden rounded-2xl border border-line/80 bg-white shadow-xl" aria-labelledby="locked-title">
                <div className="flex flex-col items-center gap-3 bg-chocolate px-6 py-8 text-center text-ivory">
                    <span className="flex size-14 items-center justify-center rounded-full bg-red-800/90"><Lock size={26} aria-hidden /></span>
                    <img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-9 w-auto opacity-90" />
                </div>
                <div className="space-y-4 p-6">
                    <h1 id="locked-title" className="text-xl font-semibold">The system is locked</h1>
                    <p className="text-sm leading-relaxed text-muted">{subscription.message}</p>
                    <div className="rounded-xl border border-line/80 bg-ivory/60 p-4 text-sm">
                        <p className="text-xs text-muted">Amount to pay</p>
                        <p className="text-2xl font-semibold">{peso(subscription.fee_cents)}</p>
                        <p className="mt-2">Pay {contact.name}{contact.person ? ` (${contact.person})` : ''}.{contact.phone ? ` Phone ${contact.phone}.` : ''}{contact.email ? ` Email ${contact.email}.` : ''} The system unlocks as soon as the payment is recorded.</p>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <a href="/" target="_blank" rel="noreferrer" className={cn(buttonClass('secondary'), 'flex-1 justify-center')}><ExternalLink size={15} aria-hidden /> Open the website</a>
                        <Button type="button" variant="primary" className="flex-1 justify-center" onClick={() => router.post('/admin/logout')}><LogOut size={15} aria-hidden /> Sign out</Button>
                    </div>
                </div>
            </section>
        </main>
    );
}
