import { Head, Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { ReactNode, useState } from 'react';

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
        <div className="flex min-h-screen flex-col">
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={title ? `${title} | UNIQA SUITES` : 'UNIQA SUITES'} />
                <meta property="og:description" content={description} />
            </Head>
            <header className="sticky top-0 z-30 bg-chocolate text-ivory">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-2">
                    <Link href="/" aria-label="UNIQA SUITES home"><img src="/brand/uniqa-wordmark.png" alt="UNIQA SUITES" className="h-11 w-auto sm:h-12" /></Link>
                    <nav aria-label="Main" className="hidden items-center gap-6 text-sm md:flex">
                        {NAV.map((n) => <Link key={n.href} href={n.href} className="hover:text-gold">{n.label}</Link>)}
                    </nav>
                    <button className="p-2 md:hidden" aria-label={open ? 'Close menu' : 'Open menu'} aria-expanded={open} onClick={() => setOpen(!open)}>{open ? <X size={22} /> : <Menu size={22} />}</button>
                </div>
                {open && (
                    <nav aria-label="Mobile" className="border-t border-espresso px-4 py-2 md:hidden">
                        {NAV.map((n) => <Link key={n.href} href={n.href} className="block py-2.5 text-sm">{n.label}</Link>)}
                    </nav>
                )}
            </header>
            <main className="flex-1">{children}</main>
            <footer className="bg-chocolate text-beige">
                <div className="mx-auto grid max-w-6xl gap-6 px-4 py-10 text-sm sm:grid-cols-3">
                    <div>
                        <img src="/brand/uniqa-monogram.png" alt="" className="mb-2 h-12 w-auto" />
                        <p>Serviced suites in Cagayan de Oro, at The Loop, Limketkai and Mesaverte Residences.</p>
                    </div>
                    <div>
                        <h2 className="mb-2 font-medium text-ivory">Explore</h2>
                        <ul className="space-y-1.5">
                            {NAV.map((n) => <li key={n.href}><Link href={n.href} className="hover:text-gold">{n.label}</Link></li>)}
                            <li><Link href="/policies#privacy" className="hover:text-gold">Privacy notice</Link></li>
                        </ul>
                    </div>
                    <div>
                        <h2 className="mb-2 font-medium text-ivory">Contact</h2>
                        <ul className="space-y-1.5">
                            {contact.messenger_url && <li><a href={contact.messenger_url} target="_blank" rel="noreferrer" className="hover:text-gold">Messenger</a></li>}
                            {contact.instagram_url && <li><a href={contact.instagram_url} target="_blank" rel="noreferrer" className="hover:text-gold">Instagram</a></li>}
                            {contact.facebook_url && <li><a href={contact.facebook_url} target="_blank" rel="noreferrer" className="hover:text-gold">Facebook</a></li>}
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
