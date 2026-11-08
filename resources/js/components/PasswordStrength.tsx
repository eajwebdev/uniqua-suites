import { Check, Circle } from 'lucide-react';
import { cn } from '@/components/ui';

// Mirrors App\Support\PasswordPolicy; the server has the final say.
const MIN = 8;
const MAX = 72;
const COMMON = [
    'password@123', 'password123', 'password1', 'passw0rd', 'p@ssw0rd', 'p@ssword1', 'qwerty123', 'qwerty12',
    'abc12345', 'abcd1234', '1q2w3e4r', 'iloveyou1', 'welcome1', 'welcome123', 'admin123', 'admin1234',
    'letmein1', 'uniqa123', 'uniqasuites1', 'uniqasuites123', 'grabcam123', 'changeme1',
];

type Options = { current?: string; confirm: string };

export function passwordChecks(password: string, { current, confirm }: Options) {
    const checks = [
        { label: `At least ${MIN} characters`, ok: password.length >= MIN && password.length <= MAX },
        { label: 'A letter and a number', ok: /\p{L}/u.test(password) && /\d/.test(password) },
        { label: 'Not a common password', ok: password !== '' && !COMMON.includes(password.toLowerCase()) },
        ...(current !== undefined ? [{ label: 'Different from your current one', ok: password !== '' && password !== current }] : []),
        { label: 'Both new passwords match', ok: password !== '' && password === confirm },
    ];

    return { checks, ready: checks.every((c) => c.ok) };
}

/** 0 to 4. Length counts most; mixing kinds of characters adds a little. */
function score(password: string) {
    if (password.length < MIN || COMMON.includes(password.toLowerCase())) return password ? 1 : 0;
    const kinds = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(password)).length;
    let s = 1;
    if (password.length >= 12) s++;
    if (password.length >= 16) s++;
    if (kinds >= 3) s++;
    return Math.min(4, s);
}

const LEVELS = [
    { label: '', bar: 'bg-line', text: 'text-muted' },
    { label: 'Weak', bar: 'bg-red-600', text: 'text-red-800' },
    { label: 'Fair', bar: 'bg-amber-500', text: 'text-amber-800' },
    { label: 'Good', bar: 'bg-emerald-500', text: 'text-emerald-800' },
    { label: 'Strong', bar: 'bg-emerald-700', text: 'text-emerald-800' },
];

export function PasswordMeter({ password, current, confirm }: { password: string } & Options) {
    const { checks } = passwordChecks(password, { current, confirm });
    const level = LEVELS[score(password)];
    const filled = score(password);

    return (
        <div className="space-y-2" aria-live="polite">
            <div className="flex items-center gap-2">
                <div className="grid flex-1 grid-cols-4 gap-1" aria-hidden>
                    {[1, 2, 3, 4].map((i) => <span key={i} className={cn('h-1.5 rounded-full transition-colors', i <= filled ? level.bar : 'bg-line')} />)}
                </div>
                <span className={cn('w-12 text-right text-xs font-medium', level.text)}>{level.label}</span>
            </div>
            <ul className="grid gap-1 text-xs sm:grid-cols-2">
                {checks.map((c) => (
                    <li key={c.label} className={cn('flex items-center gap-1.5', c.ok ? 'text-emerald-800' : 'text-muted')}>
                        {c.ok ? <Check size={14} aria-hidden /> : <Circle size={10} className="mx-0.5" aria-hidden />}
                        <span>{c.label}<span className="sr-only">{c.ok ? ': done' : ': not yet'}</span></span>
                    </li>
                ))}
            </ul>
            {password.length >= MIN && score(password) < 3 && <p className="text-xs text-muted">Tip: a short phrase of 3–4 unrelated words with a number is strong and easy to remember.</p>}
        </div>
    );
}
