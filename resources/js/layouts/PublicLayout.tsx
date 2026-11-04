import { Head, Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { ReactNode, useState } from 'react';
import { MessageButton, PAGE_WIDTH } from '@/components/public';
import { cn } from '@/components/ui';

const NAV = [
    { label: 'Suites', href: '/suites' },
    { label: 'The Loop', href: '/suites?location=the-loop' },
    { label: 'Mesaverte', href: '/suites?location=mesaverte' },
    { label: 'Policies & FAQ', href: '/policies' },
];

export default function PublicLayout({ title, description, children }: { title?: string; description: string; children: ReactNode }) {
    const { contact } = usePage<any>().props as any;
    const [open, setOpen] = useState(false);

    return (
        <div className="flex min-h-dvh flex-col">
            <a href="#main" className="skip-link">Skip to main content</a>
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={title ? `${title} | UNIQA SUITES` : 'UNIQA SUITES'} />
                <meta property="og:description" content={description} />
            </Head>
            <header className="sticky top-0 z-30 border-b border-gold/20 bg-chocolate text-ivory">
                <div className={cn(PAGE_WIDTH, 'flex h-16 items-center justify-between gap-3')}>
                    <Link href="/" aria-label="UNIQA SUITES home" className="flex min-h-11 shrink-0 items-center"><img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-8 w-auto sm:h-12" /></Link>
                    <nav aria-label="Main" className="hidden items-center gap-8 text-xs font-medium tracking-[0.18em] uppercase md:flex">
                        {NAV.map((n) => <Link key={n.href} href={n.href} className="transition-colors hover:text-gold">{n.label}</Link>)}
                    </nav>
                    <div className="flex items-center gap-2">
                        <MessageButton tone="outline" small hideIfMissing className="max-sm:[&>svg]:hidden" />
                        <button className="flex size-11 items-center justify-center md:hidden" aria-label={open ? 'Close menu' : 'Open menu'} aria-expanded={open} onClick={() => setOpen(!open)}>{open ? <X size={22} strokeWidth={1.5} /> : <Menu size={22} strokeWidth={1.5} />}</button>
                    </div>
                </div>
                {open && (
                    <nav aria-label="Mobile" className="border-t border-gold/20 px-5 py-2 md:hidden">
                        {NAV.map((n) => <Link key={n.href} href={n.href} onClick={() => setOpen(false)} className="flex min-h-11 items-center text-sm tracking-[0.12em] uppercase">{n.label}</Link>)}
                    </nav>
                )}
            </header>
            <main id="main" tabIndex={-1} className="flex-1 outline-none">{children}</main>
            <footer className="border-t border-gold/20 bg-chocolate text-beige">
                <div className={cn(PAGE_WIDTH, 'grid gap-10 py-14 text-sm sm:grid-cols-3')}>
                    <div>
                        <img src="/brand/uniqa-monogram.png" alt="" className="mb-3 h-14 w-auto" />
                        <p className="max-w-xs leading-relaxed">Suites in Cagayan de Oro, at The Loop, Limketkai and Mesaverte Residences.</p>
                    </div>
                    <div>
                        <h2 className="mb-3 text-xs font-medium tracking-[0.22em] text-gold uppercase">Explore</h2>
                        <ul className="space-y-2">
                            {NAV.map((n) => <li key={n.href}><Link href={n.href} className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">{n.label}</Link></li>)}
                            <li><Link href="/policies#privacy" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Privacy notice</Link></li>
                        </ul>
                    </div>
                    <div>
                        <h2 className="mb-3 text-xs font-medium tracking-[0.22em] text-gold uppercase">Contact</h2>
                        <ul className="space-y-2">
                            {contact.messenger_url && <li><a href={contact.messenger_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Messenger</a></li>}
                            {contact.instagram_url && <li><a href={contact.instagram_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Instagram</a></li>}
                            {contact.facebook_url && <li><a href={contact.facebook_url} target="_blank" rel="noreferrer" className="inline-block transition-colors hover:text-gold pointer-coarse:py-3">Facebook</a></li>}
                            {contact.phone && <li>{contact.phone}</li>}
                            {contact.email && <li>{contact.email}</li>}
                            {!contact.messenger_url && !contact.phone && !contact.email && <li>Official contact details coming soon.</li>}
                        </ul>
                    </div>
                </div>
            </footer>
        </div>
    );
}
