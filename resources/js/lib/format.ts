const TZ = 'Asia/Manila';

export function peso(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) return '—';
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    const whole = Math.floor(abs / 100).toLocaleString('en-PH');
    return `${sign}₱${whole}.${String(abs % 100).padStart(2, '0')}`;
}

/** For tiles and charts, where centavos are noise. Exact figures stay in the tables and reports. */
export function pesoWhole(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) return '—';
    return `${cents < 0 ? '-' : ''}₱${Math.round(Math.abs(cents) / 100).toLocaleString('en-PH')}`;
}

export function pesoCompact(pesos: number): string {
    const abs = Math.abs(pesos);
    const sign = pesos < 0 ? '-' : '';
    if (abs >= 1000000) return `${sign}₱${(abs / 1000000).toFixed(1).replace(/\.0$/, '')}M`;
    if (abs >= 1000) return `${sign}₱${(abs / 1000).toFixed(abs >= 10000 ? 0 : 1).replace(/\.0$/, '')}K`;
    return `${sign}₱${abs}`;
}

/** Date-only values are local calendar dates and must not shift across timezones. */
export function dateOnly(value: string | null | undefined): string {
    if (!value) return '—';
    const [y, m, d] = value.slice(0, 10).split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
}

export function shortDate(value: string): string {
    const [y, m, d] = value.slice(0, 10).split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', timeZone: 'UTC' });
}

export function dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: TZ });
}

export function humanize(value: string | null | undefined): string {
    if (!value) return '—';
    const text = value.replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function time12(value: string | null | undefined): string {
    if (!value) return '—';
    const [h, m] = value.split(':').map(Number);
    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}

/** Chart libraries animate in JavaScript, so the CSS reduced-motion rule does not reach them. */
export const chartsAnimate = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
