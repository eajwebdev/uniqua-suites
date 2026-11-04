import { Head, usePage } from '@inertiajs/react';
import { CalendarDays, ChartLine, MessageSquareText } from 'lucide-react';
import { ReactNode, useState } from 'react';

const POINTS = [
    { icon: CalendarDays, text: 'See every room and stay on one calendar' },
    { icon: MessageSquareText, text: 'Find vacant units and send a quote in minutes' },
    { icon: ChartLine, text: 'Follow bookings, cleaning and money from anywhere' },
];

export default function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    const { flash } = usePage<any>().props as any;
    const [photo, setPhoto] = useState(true);

    return (
        <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
            <Head title={title} />

            <aside className="relative isolate hidden flex-col justify-between overflow-hidden bg-chocolate p-12 text-ivory lg:flex">
                {photo && <img src="/storage/rooms/seed/paris/04-living-area.jpg" alt="" onError={() => setPhoto(false)} className="absolute inset-0 -z-10 size-full scale-110 object-cover" />}
                <div className="absolute inset-0 -z-10 bg-gradient-to-t from-chocolate via-chocolate/70 to-chocolate/40" />
                {/* The logo file has a solid background, so the photo fades to that exact colour behind it. */}
                <div className="absolute inset-x-0 top-0 -z-10 h-64 bg-gradient-to-b from-chocolate from-55% to-transparent" />
                <img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-12 w-auto self-start" />
                <div>
                    <p className="max-w-lg font-serif text-5xl leading-[1.08] font-light">Everything your suites need, in one place.</p>
                    <ul className="mt-8 space-y-3 text-beige">
                        {POINTS.map((p) => (
                            <li key={p.text} className="flex items-center gap-3 text-sm">
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full border border-gold/40 text-gold"><p.icon size={17} strokeWidth={1.5} aria-hidden /></span>
                                {p.text}
                            </li>
                        ))}
                    </ul>
                </div>
            </aside>

            <main className="flex flex-col bg-ivory">
                <div className="flex justify-center bg-chocolate py-5 lg:hidden">
                    <img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-11 w-auto" />
                </div>
                <div className="flex flex-1 items-center justify-center p-5 sm:p-10">
                    <div className="w-full max-w-sm">
                        <h1 className="text-3xl font-semibold tracking-tight">{title}</h1>
                        {subtitle && <p className="mt-1.5 text-sm text-muted">{subtitle}</p>}
                        <div className="mt-6">
                            {flash?.success && <p role="status" className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{flash.success}</p>}
                            {flash?.error && <p role="alert" className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900">{flash.error}</p>}
                            {children}
                        </div>
                    </div>
                </div>
                <p className="pb-5 text-center text-xs text-muted">For UNIQA SUITES staff only</p>
            </main>
        </div>
    );
}
