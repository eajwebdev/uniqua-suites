import { Link, router, useForm } from '@inertiajs/react';
import { Check, Copy, X } from 'lucide-react';
import { ButtonHTMLAttributes, FormEvent, ReactNode, useEffect, useRef, useState } from 'react';
import { humanize } from '@/lib/format';

export function cn(...parts: (string | false | null | undefined)[]) {
    return parts.filter(Boolean).join(' ');
}

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost';

const variants: Record<Variant, string> = {
    primary: 'bg-chocolate text-ivory hover:bg-espresso',
    secondary: 'border border-line bg-white text-chocolate hover:bg-beige',
    danger: 'border border-red-300 bg-white text-red-800 hover:bg-red-50',
    ghost: 'text-chocolate hover:bg-beige',
};

export function buttonClass(variant: Variant = 'secondary', small = false) {
    return cn('inline-flex items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-50', small ? 'px-2.5 py-1 text-xs' : 'px-3.5 py-2 text-sm', variants[variant]);
}

export function Button({ variant = 'secondary', small, className, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; small?: boolean }) {
    return <button type="button" className={cn(buttonClass(variant, small), className)} {...props} />;
}

export function Card({ title, action, children, className }: { title?: ReactNode; action?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('rounded-lg border border-line bg-white', className)}>
            {(title || action) && (
                <header className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {action}
                </header>
            )}
            <div className="p-4">{children}</div>
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
    return <span className={cn('inline-block rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap', tones[statusTone[value] ?? 'neutral'])}>{label ?? humanize(value)}</span>;
}

export type FieldDef = {
    name: string;
    label: string;
    type?: 'text' | 'textarea' | 'number' | 'money' | 'date' | 'datetime' | 'time' | 'select' | 'file' | 'checkbox';
    options?: { value: string | number; label: string }[];
    required?: boolean;
    help?: string;
    default?: any;
    accept?: string;
};

const inputBase = 'rounded-md border border-line bg-white px-3 py-2 text-sm placeholder:text-muted/70';
const inputClass = `w-full ${inputBase}`;

/** Full width unless the caller sets its own width, since w-full would win over w-auto in the generated CSS. */
function controlClass(className?: string) {
    return /(^|\s)w-/.test(className ?? '') ? cn(inputBase, className) : cn(inputClass, className);
}

export function Input(props: React.InputHTMLAttributes<HTMLInputElement>) {
    return <input {...props} className={controlClass(props.className)} />;
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
    const common = { id: field.name, required: field.required, name: field.name };
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
                <Button variant="ghost" small aria-label="Close" onClick={onClose}>
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

    function submit(e: FormEvent) {
        e.preventDefault();
        if (confirm && !window.confirm(confirm)) return;
        form.post(url, { forceFormData: hasFile, preserveScroll: true, onSuccess: () => onClose() });
    }

    return (
        <Modal open title={title} onClose={onClose}>
            <form onSubmit={submit} className="space-y-3">
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

export function CopyButton({ text, label = 'Copy', small = true }: { text: string; label?: string; small?: boolean }) {
    const [copied, setCopied] = useState(false);
    async function copy() {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // Clipboard API needs HTTPS or localhost; fall back for plain-HTTP local hosts.
            const area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
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
    return <p className="py-6 text-center text-sm text-muted">{children}</p>;
}

export function Stat({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
    return (
        <div className="rounded-lg border border-line bg-white p-4" title={hint}>
            <div className="text-xs text-muted">{label}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums">{value}</div>
            {hint && <div className="mt-1 text-xs text-muted">{hint}</div>}
        </div>
    );
}

export function Pagination({ links }: { links?: { url: string | null; label: string; active: boolean }[] }) {
    if (!links || links.length <= 3) return null;
    return (
        <nav aria-label="Pages" className="mt-3 flex flex-wrap gap-1">
            {links.map((l, i) =>
                l.url ? (
                    <Link key={i} href={l.url} preserveScroll className={cn('rounded px-2.5 py-1 text-xs', l.active ? 'bg-chocolate text-ivory' : 'border border-line bg-white')} dangerouslySetInnerHTML={{ __html: l.label }} />
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
                <Link key={t.href} href={t.href} className={cn('px-3 py-2 text-sm whitespace-nowrap', current.startsWith(t.href) ? 'border-b-2 border-chocolate font-semibold' : 'text-muted')}>
                    {t.label}
                </Link>
            ))}
        </div>
    );
}
