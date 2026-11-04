import { Link, router, useForm } from '@inertiajs/react';
import { Check, Copy, Eye, EyeOff, Inbox, X } from 'lucide-react';
import { ButtonHTMLAttributes, FormEvent, ReactNode, useEffect, useRef, useState } from 'react';
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

export function Select({ options, placeholder, ...props }: React.SelectHTMLAttributes<HTMLSelectElement> & { options: { value: string | number; label: string }[]; placeholder?: string }) {
    return (
        <select {...props} className={controlClass(props.className)}>
            {placeholder !== undefined && <option value="">{placeholder}</option>}
            {options.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </select>
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

export function Modal({ open, onClose, title, children }: { open: boolean; onClose: () => void; title: string; children: ReactNode }) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        if (open && !ref.current?.open) ref.current?.showModal();
        if (!open && ref.current?.open) ref.current?.close();
    }, [open]);
    return (
        <dialog ref={ref} onClose={onClose} className="m-auto w-[calc(100%-2rem)] max-w-lg rounded-lg border border-line bg-white p-0 text-chocolate">
            <header className="flex items-center justify-between border-b border-line px-4 py-3">
                <h2 className="font-semibold">{title}</h2>
                <Button variant="ghost" small aria-label="Close dialog" onClick={onClose}>
                    <X size={16} />
                </Button>
            </header>
            <div className="max-h-[75vh] overflow-y-auto p-4">{children}</div>
        </dialog>
    );
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

    function submit(e: FormEvent) {
        e.preventDefault();
        if (confirm && !window.confirm(confirm)) return;
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

export function ActionButton({ action, small = true }: { action: RowAction; small?: boolean }) {
    const [open, setOpen] = useState(false);
    if (action.fields?.length) {
        return (
            <>
                <Button small={small} variant={action.variant} onClick={() => setOpen(true)}>
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
            onClick={() => {
                if (action.confirm && !window.confirm(action.confirm)) return;
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

export function Pagination({ links }: { links?: { url: string | null; label: string; active: boolean }[] }) {
    if (!links || links.length <= 3) return null;
    return (
        <nav aria-label="Pages" className="mt-3 flex flex-wrap gap-1">
            {links.map((l, i) =>
                l.url ? (
                    <Link key={i} href={l.url} preserveScroll className={cn('flex items-center rounded px-2.5 py-1 text-xs pointer-coarse:min-h-11 pointer-coarse:min-w-11 pointer-coarse:justify-center', l.active ? 'bg-chocolate text-ivory' : 'border border-line bg-white')} aria-current={l.active ? 'page' : undefined} dangerouslySetInnerHTML={{ __html: l.label }} />
                ) : (
                    <span key={i} className="px-2.5 py-1 text-xs text-muted" dangerouslySetInnerHTML={{ __html: l.label }} />
                ),
            )}
        </nav>
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
