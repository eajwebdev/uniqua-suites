import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/components/ui';

export type QuickItem = { label: string; href: string; icon: any; group: string };

/** Find any page or person by typing, so nobody has to remember where things live. Ctrl+K opens it anywhere. */
export default function QuickFind({ items, searches }: { items: QuickItem[]; searches: { label: string; href: (q: string) => string; icon: any }[] }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const [term, setTerm] = useState('');
    const [index, setIndex] = useState(0);

    const open = () => {
        setTerm('');
        setIndex(0);
        dialog.current?.showModal();
        input.current?.focus();
    };

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                open();
            }
        };
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const results = useMemo(() => {
        const q = term.trim().toLowerCase();
        const pages = items.filter((i) => !q || i.label.toLowerCase().includes(q) || i.group.toLowerCase().includes(q)).map((i) => ({ ...i, href: i.href }));
        const people = q ? searches.map((s) => ({ label: `${s.label} “${term.trim()}”`, href: s.href(term.trim()), icon: s.icon, group: 'Search' })) : [];

        return [...pages, ...people];
    }, [term, items, searches]);

    const go = (href: string) => {
        dialog.current?.close();
        router.visit(href);
    };

    const current = Math.min(index, Math.max(results.length - 1, 0));

    return (
        <>
            <button type="button" onClick={open} className="flex h-9 min-w-0 items-center gap-2 rounded-lg border border-line bg-ivory/60 px-3 text-sm text-muted transition-colors hover:border-gold pointer-coarse:h-11 sm:w-80">
                <Search size={15} className="shrink-0" aria-hidden />
                <span className="hidden truncate sm:inline">Find a page, guest or booking</span>
                <span className="sm:hidden">Find</span>
                <kbd className="ml-auto hidden rounded border border-line bg-white px-1.5 font-sans text-[11px] font-medium whitespace-nowrap lg:inline">Ctrl K</kbd>
            </button>

            <dialog ref={dialog} aria-label="Find a page, guest or booking" onClick={(e) => e.target === dialog.current && dialog.current?.close()} className="mx-auto mt-[10vh] w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-xl border border-line bg-white p-0 text-chocolate shadow-xl">
                <div className="flex items-center gap-2 border-b border-line px-4">
                    <Search size={17} className="shrink-0 text-muted" aria-hidden />
                    <input
                        ref={input}
                        value={term}
                        onChange={(e) => {
                            setTerm(e.target.value);
                            setIndex(0);
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'ArrowDown') {
                                e.preventDefault();
                                setIndex((current + 1) % Math.max(results.length, 1));
                            }
                            if (e.key === 'ArrowUp') {
                                e.preventDefault();
                                setIndex((current - 1 + results.length) % Math.max(results.length, 1));
                            }
                            if (e.key === 'Enter' && results[current]) go(results[current].href);
                        }}
                        role="combobox"
                        aria-expanded
                        aria-controls="quick-find-results"
                        aria-activedescendant={results[current] ? `quick-find-${current}` : undefined}
                        aria-label="Type what you are looking for"
                        placeholder="Type what you are looking for…"
                        className="min-h-12 w-full bg-transparent text-base [outline:none]"
                    />
                </div>
                <ul id="quick-find-results" role="listbox" aria-label="Results" className="max-h-[50vh] overflow-y-auto p-2">
                    {results.length === 0 && <li className="p-4 text-center text-sm text-muted">Nothing matches that.</li>}
                    {results.map((r, i) => {
                        const Icon = r.icon;

                        return (
                            <li key={r.href} id={`quick-find-${i}`} role="option" aria-selected={i === current}>
                                <button type="button" tabIndex={-1} onMouseEnter={() => setIndex(i)} onClick={() => go(r.href)} className={cn('flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-left text-sm', i === current && 'bg-beige')}>
                                    <Icon size={16} strokeWidth={1.75} className="shrink-0 text-muted" aria-hidden />
                                    <span className="min-w-0 flex-1 truncate">{r.label}</span>
                                    <span className="text-xs text-muted">{r.group}</span>
                                    {i === current && <CornerDownLeft size={13} className="text-muted" aria-hidden />}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </dialog>
        </>
    );
}
