const TZ = 'Asia/Manila';

export function peso(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) return '—';
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    const whole = Math.floor(abs / 100).toLocaleString('en-PH');
    return `${sign}₱${whole}.${String(abs % 100).padStart(2, '0')}`;
}

/** Date-only values are local calendar dates and must not shift across timezones. */
export function dateOnly(value: string | null | undefined): string {
    if (!value) return '—';
    const [y, m, d] = value.slice(0, 10).split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
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
