import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, ChevronDown, ChevronLeft, ChevronRight, CircleHelp, Copy, Eye, EyeOff, Inbox, Search, X } from 'lucide-react';
import { ButtonHTMLAttributes, FormEvent, ReactNode, useEffect, useId, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { humanize } from '@/lib/format';

export function cn(...parts: (string | false | null | undefined)[]) {
    return parts.filter(Boolean).join(' ');
}

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost';

const variants: Record<Variant, string> = {
    primary: 'bg-chocolate text-ivory shadow-sm hover:bg-espresso',
    secondary: 'border border-line bg-white text-chocolate shadow-xs hover:bg-beige',
    danger: 'border border-red-300 bg-white text-red-800 hover:bg-red-50',
    ghost: 'text-chocolate hover:bg-beige',
};

export function buttonClass(variant: Variant = 'secondary', small = false) {
    return cn('inline-flex items-center justify-center gap-1.5 rounded-lg font-medium whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-50 active:opacity-80 pointer-coarse:min-h-11', small ? 'px-2.5 py-1 text-xs pointer-coarse:px-3.5 pointer-coarse:text-sm' : 'px-3.5 py-2 text-sm', variants[variant]);
}

export function Button({ variant = 'secondary', small, className, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; small?: boolean }) {
    return <button type="button" className={cn(buttonClass(variant, small), className)} {...props} />;
}

export function Card({ title, action, children, className }: { title?: ReactNode; action?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('rounded-xl border border-line/80 bg-white shadow-xs', className)}>
            {(title || action) && (
                <header className="flex flex-wrap items-center justify-between gap-2 px-5 pt-4">
                    <h2 className="flex items-center gap-2 text-[0.9375rem] font-semibold">{title}</h2>
                    {action}
                </header>
            )}
            <div className="p-5 pt-3">{children}</div>
        </section>
    );
}

const tones: Record<string, string> = {
    good: 'bg-emerald-50 text-emerald-900 border-emerald-200',
    warn: 'bg-amber-50 text-amber-900 border-amber-200',
    bad: 'bg-red-50 text-red-900 border-red-200',
    info: 'bg-sky-50 text-sky-900 border-sky-200',
    neutral: 'bg-beige text-espresso border-line',
};

const statusTone: Record<string, keyof typeof tones> = {
    confirmed: 'good', checked_in: 'info', checked_out: 'neutral', tentative: 'warn', inquiry: 'neutral', cancelled: 'bad', no_show: 'bad', expired_hold: 'neutral',
    verified: 'good', pending: 'warn', rejected: 'bad', paid: 'good', incurred: 'warn', ready: 'good', dirty: 'bad', assigned: 'warn', cleaning: 'warn', for_inspection: 'info', under_maintenance: 'bad',
    in_progress: 'warn', accepted: 'good', open: 'warn', resolved: 'good', closed: 'neutral', approved: 'good', proposed: 'warn', published: 'good', unpublished: 'neutral', active: 'good', deactivated: 'bad',
    critical: 'bad', high: 'warn', normal: 'neutral', low: 'neutral', staged: 'warn', committed: 'good', rolled_back: 'neutral', low_stock: 'bad', not_counted: 'warn', ok: 'good',
    suggested: 'warn', collected: 'info', processing: 'info', delivered: 'good', reported: 'warn', evidence_pending: 'warn', under_review: 'warn', approved_for_charge: 'info', disputed: 'bad',
    connected: 'good', degraded: 'warn', failed: 'bad', not_connected: 'neutral',
    remitted: 'good', to_remit: 'warn', not_owed: 'neutral', ours: 'good', borrowed: 'warn', condo_owned: 'good', third_party: 'warn', unavailable: 'warn', inactive: 'neutral',
    to_collect: 'bad', partially_paid: 'warn', no_fee: 'neutral', requested: 'neutral', pending_confirmation: 'warn', parking_assigned: 'good', occupied: 'info', upcoming: 'good', pending_slot: 'warn', ended: 'neutral',
};

export function StatusChip({ value, label }: { value?: string | null; label?: string }) {
    if (!value) return <span className="text-muted">—</span>;
    return <span className={cn('inline-block rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap', tones[statusTone[value] ?? 'neutral'])}>{label ?? (value === 'tentative' ? 'On hold' : humanize(value))}</span>;
}

export type FieldDef = {
    name: string;
    label: string;
    type?: 'text' | 'textarea' | 'number' | 'money' | 'date' | 'datetime' | 'time' | 'select' | 'multicheck' | 'file' | 'checkbox';
    options?: { value: string | number; label: string }[];
    required?: boolean;
    help?: string;
    default?: any;
    accept?: string;
};

// 16px on touch screens: iPhones zoom the whole page when focusing anything smaller.
const inputBase = 'rounded-lg border border-line bg-white px-3 py-2 text-sm shadow-xs transition-shadow placeholder:text-muted/70 focus:border-gold-dark focus:ring-2 focus:ring-gold/30 focus:outline-none pointer-coarse:min-h-11 pointer-coarse:text-base';
const inputClass = `w-full ${inputBase}`;

/** Full width unless the caller sets its own width, since w-full would win over w-auto in the generated CSS. */
function controlClass(className?: string) {
    return /(^|\s)w-/.test(className ?? '') ? cn(inputBase, className) : cn(inputClass, className);
}

export function Input(props: React.InputHTMLAttributes<HTMLInputElement>) {
    return <input {...props} className={controlClass(props.className)} />;
}

/** Lets staff check what they typed, which saves most failed sign-ins. */
export function PasswordInput(props: React.InputHTMLAttributes<HTMLInputElement>) {
    const [shown, setShown] = useState(false);

    return (
        <span className="relative block">
            <Input {...props} type={shown ? 'text' : 'password'} className={cn('w-full pr-11', props.className)} />
            <button type="button" aria-label={shown ? 'Hide password' : 'Show password'} aria-pressed={shown} onClick={() => setShown(!shown)} className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted hover:text-chocolate">
                {shown ? <EyeOff size={17} aria-hidden /> : <Eye size={17} aria-hidden />}
            </button>
        </span>
    );
}

export function Select({ options, placeholder, ...props }: React.SelectHTMLAttributes<HTMLSelectElement> & { options: { value: string | number; label: string; disabled?: boolean }[]; placeholder?: string }) {
    return (
        <select {...props} className={controlClass(props.className)}>
            {placeholder !== undefined && <option value="">{placeholder}</option>}
            {options.map((o) => (
                <option key={o.value} value={o.value} disabled={o.disabled}>
                    {o.label}
                </option>
            ))}
        </select>
    );
}

export type SearchOption = { value: string | number; label: string; hint?: string; keywords?: string };

/**
 * A dropdown with its own search box: type part of a name, phone or Messenger name, then pick with the
 * mouse, or the arrow keys and Enter. Esc or a click outside closes it. Use it for long lists such as guests.
 */
export function SearchSelect({ options, value, onChange, placeholder = 'Select…', searchPlaceholder = 'Type to search', empty = 'Nothing matches', disabled, className, 'aria-label': ariaLabel }: {
    options: SearchOption[]; value: string | number | null | undefined; onChange: (value: string) => void; placeholder?: string; searchPlaceholder?: string; empty?: string; disabled?: boolean; className?: string; 'aria-label'?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const search = useRef<HTMLInputElement>(null);
    const list = useRef<HTMLUListElement>(null);
    const listId = useId();
    const selected = options.find((o) => String(o.value) === String(value ?? ''));
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
    const shown = options.filter((o) => {
        const text = `${o.label} ${o.hint ?? ''} ${o.keywords ?? ''}`.toLowerCase();
        return words.every((w) => text.includes(w));
    });

    useEffect(() => {
        if (!open) return;
        setActive(Math.max(0, shown.findIndex((o) => String(o.value) === String(value ?? ''))));
        requestAnimationFrame(() => search.current?.focus());
        const outside = (e: MouseEvent) => { if (!root.current?.contains(e.target as Node)) setOpen(false); };
        document.addEventListener('mousedown', outside);
        return () => document.removeEventListener('mousedown', outside);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => { setActive(0); }, [query]);
    useEffect(() => { list.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' }); }, [active, open]);

    const choose = (o: SearchOption) => {
        onChange(String(o.value));
        setOpen(false);
        setQuery('');
    };

    const keys = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(shown.length - 1, i + 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(0, i - 1)); }
        else if (e.key === 'Enter') { e.preventDefault(); if (shown[active]) choose(shown[active]); }
        else if (e.key === 'Escape') { e.preventDefault(); setOpen(false); }
        else if (e.key === 'Tab') setOpen(false);
    };

    return (
        <div ref={root} className={cn('relative', className)}>
            <button type="button" disabled={disabled} aria-label={ariaLabel} aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen(!open)}
                onKeyDown={(e) => { if (!open && (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); setOpen(true); } }}
                className={cn(inputClass, 'flex items-center justify-between gap-2 text-left disabled:cursor-not-allowed disabled:opacity-60')}>
                <span className={cn('min-w-0 truncate', !selected && 'text-muted')}>
                    {selected ? <>{selected.label}{selected.hint && <span className="text-muted"> ({selected.hint})</span>}</> : placeholder}
                </span>
                <ChevronDown size={16} className={cn('shrink-0 text-muted transition-transform', open && 'rotate-180')} aria-hidden />
            </button>
            {open && (
                // Stops the click reaching a surrounding <label>, which would otherwise reopen or close the list.
                <div onClick={(e) => e.preventDefault()} className="absolute inset-x-0 top-full z-50 mt-1 overflow-hidden rounded-lg border border-line bg-white shadow-lg">
                    <div className="relative border-b border-line/70 p-2">
                        <Search size={15} className="pointer-events-none absolute top-1/2 left-4.5 -translate-y-1/2 text-muted" aria-hidden />
                        <input ref={search} type="search" role="combobox" aria-expanded aria-controls={listId} aria-activedescendant={shown[active] ? `${listId}-${active}` : undefined} aria-autocomplete="list"
                            placeholder={searchPlaceholder} value={query} onChange={(e) => setQuery(e.target.value)} onKeyDown={keys}
                            className="w-full rounded-md border border-line bg-ivory/40 py-1.5 pr-2 pl-8 text-sm focus:border-gold-dark focus:ring-2 focus:ring-gold/30 focus:outline-none pointer-coarse:text-base" />
                    </div>
                    <ul ref={list} id={listId} role="listbox" className="max-h-64 overflow-y-auto py-1 text-sm">
                        {shown.length === 0 ? <li className="px-3 py-2 text-muted">{empty}</li> : shown.map((o, i) => {
                            const isSelected = String(o.value) === String(value ?? '');
                            return (
                                <li key={o.value} id={`${listId}-${i}`} data-index={i} role="option" aria-selected={isSelected} onMouseEnter={() => setActive(i)} onMouseDown={(e) => e.preventDefault()} onClick={() => choose(o)}
                                    className={cn('flex cursor-pointer items-center gap-2 px-3 py-2 pointer-coarse:py-3', i === active && 'bg-ivory', isSelected && 'font-medium')}>
                                    <Check size={14} className={cn('shrink-0 text-gold-dark', !isSelected && 'invisible')} aria-hidden />
                                    <span className="min-w-0 truncate">{o.label}{o.hint && <span className="text-muted"> ({o.hint})</span>}</span>
                                </li>
                            );
                        })}
                    </ul>
                    <p className="border-t border-line/70 px-3 py-1.5 text-[11px] text-muted">{shown.length} of {options.length}{words.length ? ' match' : ''}</p>
                </div>
            )}
        </div>
    );
}

export function Field({ label, error, help, children, required }: { label: string; error?: string; help?: string; required?: boolean; children: ReactNode }) {
    return (
        <label className="block text-sm">
            <span className="mb-1 block font-medium">
                {label}
                {required && <span className="text-red-800"> *</span>}
            </span>
            {children}
            {help && <span className="mt-1 block text-xs text-muted">{help}</span>}
            {error && <span role="alert" className="mt-1 block text-xs text-red-800">{error}</span>}
        </label>
    );
}

export function DynamicField({ field, value, error, onChange }: { field: FieldDef; value: any; error?: string; onChange: (v: any) => void }) {
    const type = field.type ?? 'text';
    if (type === 'checkbox') {
        return (
            <label className="flex items-start gap-2 text-sm">
                <input type="checkbox" className="mt-0.5" checked={!!value} onChange={(e) => onChange(e.target.checked)} />
                <span>{field.label}</span>
                {error && <span role="alert" className="block text-xs text-red-800">{error}</span>}
            </label>
        );
    }
    if (type === 'multicheck') {
        const selected: any[] = value ?? [];
        return (
            <fieldset className="text-sm">
                <legend className="mb-1 font-medium">{field.label}</legend>
                <div className="grid grid-cols-2 gap-1">
                    {field.options?.map((o) => (
                        <label key={o.value} className="flex items-center gap-2">
                            <input type="checkbox" checked={selected.includes(o.value)} onChange={(e) => onChange(e.target.checked ? [...selected, o.value] : selected.filter((v) => v !== o.value))} />
                            {o.label}
                        </label>
                    ))}
                </div>
                {field.help && <span className="mt-1 block text-xs text-muted">{field.help}</span>}
                {error && <span role="alert" className="mt-1 block text-xs text-red-800">{error}</span>}
            </fieldset>
        );
    }
    const common = { id: field.name, required: field.required, name: field.name, 'aria-invalid': error ? true : undefined };
    let control: ReactNode;
    if (type === 'textarea') control = <textarea {...common} rows={3} className={inputClass} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
    else if (type === 'select') control = <Select {...common} placeholder={field.required ? 'Select…' : 'None'} options={field.options ?? []} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
    else if (type === 'file') control = <input {...common} type="file" accept={field.accept ?? 'image/*,application/pdf'} className="w-full text-sm" onChange={(e) => onChange(e.target.files?.[0] ?? null)} />;
    else {
        const htmlType = type === 'money' ? 'text' : type === 'datetime' ? 'datetime-local' : type;
        control = <Input {...common} type={htmlType} inputMode={type === 'money' ? 'decimal' : undefined} pattern={type === 'money' ? '\\d+(\\.\\d{1,2})?' : undefined} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
    }
    return (
        <Field label={field.label} required={field.required} help={field.help} error={error}>
            {control}
        </Field>
    );
}

export function Modal({ open, onClose, title, children, wide }: { open: boolean; onClose: () => void; title: string; children: ReactNode; wide?: boolean }) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        if (open && !ref.current?.open) ref.current?.showModal();
        if (!open && ref.current?.open) ref.current?.close();
    }, [open]);
    return (
        <dialog ref={ref} onClose={onClose} className={cn('dialog-pop m-auto w-[calc(100%-2rem)] overflow-hidden rounded-2xl border border-line/80 bg-white p-0 text-chocolate shadow-2xl', wide ? 'max-w-4xl' : 'max-w-lg')}>
            <header className="flex items-center justify-between gap-3 border-b border-line/70 bg-ivory/60 px-5 py-3.5">
                <h2 className="text-[0.9375rem] font-semibold">{title}</h2>
                <Button variant="ghost" small aria-label="Close dialog" onClick={onClose}>
                    <X size={16} />
                </Button>
            </header>
            <div className="max-h-[75vh] overflow-y-auto p-5">{children}</div>
        </dialog>
    );
}

type ConfirmOptions = { title?: string; message: string; confirmLabel?: string; danger?: boolean };

function ConfirmDialog({ title = 'Please confirm', message, confirmLabel = 'Confirm', danger = false, onDone }: ConfirmOptions & { onDone: (answer: boolean) => void }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const confirmButton = useRef<HTMLButtonElement>(null);
    const cancelButton = useRef<HTMLButtonElement>(null);
    const Icon = danger ? AlertTriangle : CircleHelp;

    useEffect(() => {
        dialog.current?.showModal();
        // Enter should never carry out something destructive by accident.
        (danger ? cancelButton : confirmButton).current?.focus();
    }, [danger]);

    // close() hands focus back to the button that opened the dialog.
    function finish(answer: boolean) {
        dialog.current?.close();
        onDone(answer);
    }

    return (
        <dialog
            ref={dialog}
            role="alertdialog"
            aria-labelledby="confirm-title"
            aria-describedby="confirm-message"
            onCancel={(e) => { e.preventDefault(); finish(false); }}
            onClick={(e) => e.target === dialog.current && finish(false)}
            className="dialog-pop m-auto w-[calc(100%-2rem)] max-w-md overflow-hidden rounded-2xl border border-line/80 bg-white p-0 text-chocolate shadow-2xl"
        >
            <div className="flex gap-4 p-6">
                <span className={cn('flex size-11 shrink-0 items-center justify-center rounded-full', danger ? 'bg-red-50 text-red-800' : 'bg-beige text-gold-dark')}>
                    <Icon size={22} strokeWidth={1.75} aria-hidden />
                </span>
                <div className="min-w-0 pt-0.5">
                    <h2 id="confirm-title" className="text-base font-semibold">{title}</h2>
                    <p id="confirm-message" className="mt-1.5 text-sm leading-relaxed text-muted">{message}</p>
                </div>
            </div>
            <div className="flex flex-col-reverse gap-2 border-t border-line/70 bg-ivory/60 px-6 py-3.5 sm:flex-row sm:justify-end">
                <button ref={cancelButton} type="button" className={buttonClass('secondary')} onClick={() => finish(false)}>Cancel</button>
                <button ref={confirmButton} type="button" className={danger ? cn(buttonClass('primary'), 'bg-red-800 hover:bg-red-900') : buttonClass('primary')} onClick={() => finish(true)}>{confirmLabel}</button>
            </div>
        </dialog>
    );
}

/** The app's own replacement for window.confirm. Resolves true only when the person confirms. */
export function confirmDialog(options: ConfirmOptions): Promise<boolean> {
    return new Promise((resolve) => {
        const host = document.createElement('div');
        document.body.appendChild(host);
        const root = createRoot(host);
        root.render(
            <ConfirmDialog
                {...options}
                onDone={(answer) => {
                    root.unmount();
                    host.remove();
                    resolve(answer);
                }}
            />,
        );
    });
}

/** Server-driven form: fields come from the controller, validation errors come back per field. */
export function FormModal({ title, url, fields, data, submitLabel, confirm, onClose }: { title: string; url: string; fields: FieldDef[]; data?: Record<string, any>; submitLabel?: string; confirm?: string; onClose: () => void }) {
    const initial = Object.fromEntries(fields.map((f) => [f.name, f.default ?? (f.type === 'checkbox' ? false : '')]));
    const form = useForm<Record<string, any>>({ ...initial, ...data });
    const hasFile = fields.some((f) => f.type === 'file');
    const formRef = useRef<HTMLFormElement>(null);

    // Runs once the errors are on screen, so the first bad field can take focus.
    useEffect(() => {
        if (Object.keys(form.errors).length > 0) {
            formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
        }
    }, [form.errors]);

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (confirm && !(await confirmDialog({ title, message: confirm, confirmLabel: submitLabel ?? 'Continue' }))) return;
        form.post(url, {
            forceFormData: hasFile,
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <Modal open title={title} onClose={onClose}>
            <form ref={formRef} onSubmit={submit} className="space-y-3">
                {fields.map((f) => (
                    <DynamicField key={f.name} field={f} value={form.data[f.name]} error={(form.errors as any)[f.name]} onChange={(v) => form.setData(f.name, v)} />
                ))}
                <div className="flex justify-end gap-2 pt-2">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="primary" disabled={form.processing}>
                        {form.processing ? 'Saving…' : (submitLabel ?? 'Save')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export type RowAction = { label: string; url: string; confirm?: string; fields?: FieldDef[]; data?: Record<string, any>; variant?: Variant };

export function ActionButton({ action, small = true, className }: { action: RowAction; small?: boolean; className?: string }) {
    const [open, setOpen] = useState(false);
    if (action.fields?.length) {
        return (
            <>
                <Button small={small} variant={action.variant} className={className} onClick={() => setOpen(true)}>
                    {action.label}
                </Button>
                {open && <FormModal title={action.label} url={action.url} fields={action.fields} data={action.data} confirm={action.confirm} onClose={() => setOpen(false)} />}
            </>
        );
    }
    return (
        <Button
            small={small}
            variant={action.variant}
            className={className}
            onClick={async () => {
                if (action.confirm && !(await confirmDialog({ title: action.label, message: action.confirm, confirmLabel: action.label, danger: action.variant === 'danger' }))) return;
                router.post(action.url, action.data ?? {}, { preserveScroll: true });
            }}
        >
            {action.label}
        </Button>
    );
}

export async function copyToClipboard(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        // Clipboard API needs HTTPS or localhost; fall back for plain-HTTP local hosts.
        const area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        const done = document.execCommand('copy');
        area.remove();
        return done;
    }
}

export function CopyButton({ text, label = 'Copy', small = true }: { text: string; label?: string; small?: boolean }) {
    const [copied, setCopied] = useState(false);
    async function copy() {
        await copyToClipboard(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 1800);
    }
    return (
        <Button small={small} onClick={copy}>
            {copied ? <Check size={14} /> : <Copy size={14} />}
            <span aria-live="polite">{copied ? 'Copied' : label}</span>
        </Button>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted">
            <Inbox size={22} strokeWidth={1.5} aria-hidden />
            <p>{children}</p>
        </div>
    );
}

export function Stat({ label, value, hint, icon: Icon, href }: { label: string; value: ReactNode; hint?: string; icon?: any; href?: string }) {
    const body = (
        <>
            <div className="flex items-center justify-between gap-2 text-xs font-medium text-muted">
                {label}
                {Icon && <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-beige text-espresso"><Icon size={16} strokeWidth={1.75} aria-hidden /></span>}
            </div>
            <div className="mt-1 text-2xl font-semibold">{value}</div>
            {hint && <div className="mt-1 text-xs text-muted">{hint}</div>}
        </>
    );
    const frame = 'block rounded-xl border border-line/80 bg-white p-4 shadow-xs';

    return href ? <Link href={href} className={cn(frame, 'transition-colors hover:border-gold')}>{body}</Link> : <div className={frame}>{body}</div>;
}

/** Shared look for compact tables, so every list in the admin reads the same. */
export const tableClass = 'w-full text-left text-[13px]';
export const theadClass = 'bg-ivory/70 text-[11px] font-medium tracking-wide text-muted uppercase';
export const thClass = 'px-3 py-2 font-medium whitespace-nowrap';
export const trClass = 'border-t border-line/70 transition-colors hover:bg-ivory/60';
// One line per row is what saves the space. Long text opts back in to wrapping where it is shown.
export const tdClass = 'px-3 py-1 whitespace-nowrap';

type PagerItem = { key: string | number; label: ReactNode; ariaLabel?: string; active?: boolean; href?: string | null; onClick?: () => void };

function PagerButton({ item, hideOnPhone }: { item: PagerItem; hideOnPhone?: boolean }) {
    const frame = cn('h-8 min-w-8 items-center justify-center rounded-lg px-1.5 text-xs font-medium tabular-nums transition-colors pointer-coarse:h-11 pointer-coarse:min-w-11', hideOnPhone ? 'hidden sm:flex' : 'flex');
    const state = item.active ? 'bg-chocolate text-ivory' : 'text-chocolate hover:bg-beige';
    const current = item.active ? ('page' as const) : undefined;

    if (item.href) return <Link href={item.href} preserveScroll aria-label={item.ariaLabel} aria-current={current} className={cn(frame, state)}>{item.label}</Link>;
    if (item.onClick) return <button type="button" onClick={item.onClick} aria-label={item.ariaLabel} aria-current={current} className={cn(frame, state)}>{item.label}</button>;

    return <span aria-hidden={item.ariaLabel ? undefined : true} aria-label={item.ariaLabel} className={cn(frame, item.active ? state : 'text-muted/50')}>{item.label}</span>;
}

/** The footer of a table: where you are on the left, how to move on the right. */
function PagerBar({ from, to, total, prev, next, pages, currentPage, lastPage }: { from?: number | null; to?: number | null; total?: number; prev: PagerItem; next: PagerItem; pages: PagerItem[]; currentPage?: number; lastPage?: number }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-line/70 bg-ivory/40 px-3 py-1.5 text-xs text-muted">
            <p aria-live="polite" className="tabular-nums">{total !== undefined ? (total === 0 ? 'No records' : <><span className="font-medium text-chocolate">{from}–{to}</span> of {total}</>) : null}</p>
            {pages.length > 1 && (
                <nav aria-label="Pages" className="flex items-center gap-0.5">
                    <PagerButton item={prev} />
                    {pages.map((p) => <PagerButton key={p.key} item={p} hideOnPhone />)}
                    {currentPage && lastPage && <span className="px-2 tabular-nums sm:hidden">Page {currentPage} of {lastPage}</span>}
                    <PagerButton item={next} />
                </nav>
            )}
        </div>
    );
}

const prevIcon = <ChevronLeft size={15} aria-hidden />;
const nextIcon = <ChevronRight size={15} aria-hidden />;

type ServerLink = { url: string | null; label: string; active: boolean };

/** For lists the server pages. Pass the paginator's numbers to show the "1–15 of 120" count. */
export function Pagination({ links, from, to, total }: { links?: ServerLink[]; from?: number | null; to?: number | null; total?: number }) {
    if (!links || links.length < 3) return null;

    const [first, last] = [links[0], links[links.length - 1]];
    const numbers = links.slice(1, -1);
    const currentIndex = numbers.findIndex((l) => l.active);
    const pages: PagerItem[] = numbers.map((l, i) => (l.url ? { key: i, label: l.label, ariaLabel: `Page ${l.label}`, active: l.active, href: l.url } : { key: i, label: '…' }));
    const lastPage = Number(numbers[numbers.length - 1]?.label);

    return (
        <PagerBar
            from={from}
            to={to}
            total={total}
            prev={{ key: 'prev', label: prevIcon, ariaLabel: 'Previous page', href: first.url }}
            next={{ key: 'next', label: nextIcon, ariaLabel: 'Next page', href: last.url }}
            pages={pages}
            currentPage={currentIndex >= 0 ? Number(numbers[currentIndex].label) : undefined}
            lastPage={Number.isNaN(lastPage) ? undefined : lastPage}
        />
    );
}

const storedPage = (key: string) => {
    try {
        return Number(window.sessionStorage.getItem(key)) || 1;
    } catch {
        return 1;
    }
};

/**
 * Pages a list the server sent in full. The page is remembered per address, because an action
 * such as Approve reloads the screen and would otherwise throw the person back to page one.
 */
export function usePaged<T>(rows: T[], options: { size?: number; id?: string; enabled?: boolean } = {}) {
    const { size = 15, id = 'table', enabled = true } = options;
    const key = `uniqa:page:${id}:${usePage().url}`;
    const [state, setState] = useState(() => ({ key, page: storedPage(key) }));
    const lastPage = Math.max(1, Math.ceil(rows.length / size));
    // A filter changes the address: start that new list from where it was last left.
    const wanted = state.key === key ? state.page : storedPage(key);
    const page = Math.min(wanted, lastPage);

    function setPage(next: number) {
        setState({ key, page: next });
        try {
            window.sessionStorage.setItem(key, String(next));
        } catch {
            // Private windows can refuse storage. Paging still works, it just isn't remembered.
        }
    }

    if (!enabled) return { rows, page: 1, lastPage: 1, setPage, from: rows.length ? 1 : 0, to: rows.length, total: rows.length };

    const start = (page - 1) * size;

    return { rows: rows.slice(start, start + size), page, lastPage, setPage, from: rows.length ? start + 1 : 0, to: Math.min(start + size, rows.length), total: rows.length };
}

/** 1 … 4 5 6 … 12: short enough to never wrap. */
function pageWindow(page: number, last: number): (number | null)[] {
    const wanted = [...new Set([1, page - 1, page, page + 1, last])].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b);

    return wanted.flatMap((n, i) => (i > 0 && n - wanted[i - 1] > 1 ? [null, n] : [n]));
}

export function ClientPager({ pager }: { pager: ReturnType<typeof usePaged<any>> }) {
    const { page, lastPage, setPage, from, to, total } = pager;

    return (
        <PagerBar
            from={from}
            to={to}
            total={total}
            prev={{ key: 'prev', label: prevIcon, ariaLabel: 'Previous page', onClick: page > 1 ? () => setPage(page - 1) : undefined }}
            next={{ key: 'next', label: nextIcon, ariaLabel: 'Next page', onClick: page < lastPage ? () => setPage(page + 1) : undefined }}
            pages={pageWindow(page, lastPage).map((n, i) => (n === null ? { key: `gap${i}`, label: '…' } : { key: n, label: n, ariaLabel: `Page ${n}`, active: n === page, onClick: () => setPage(n) }))}
            currentPage={page}
            lastPage={lastPage}
        />
    );
}

export function Tabs({ tabs, current }: { tabs?: { label: string; href: string }[]; current: string }) {
    if (!tabs?.length) return null;
    return (
        <div className="mb-4 flex gap-1 overflow-x-auto border-b border-line">
            {tabs.map((t) => (
                <Link key={t.href} href={t.href} aria-current={current === t.href ? 'page' : undefined} className={cn('flex items-center px-3 py-2 text-sm whitespace-nowrap pointer-coarse:min-h-11', current === t.href ? 'border-b-2 border-gold-dark font-semibold' : 'text-muted hover:text-chocolate')}>
                    {t.label}
                </Link>
            ))}
        </div>
    );
}
