/**
 * Camera rentals: the same rule as RentalPeriod on the server. Billed days are whole 24-hour blocks
 * (at least 1); time past them is shown but never charged automatically.
 */
export function rentalLength(pickupDate: string, pickupTime: string, returnDate: string, returnTime: string) {
    if (!pickupDate || !pickupTime || !returnDate || !returnTime) return null;
    const minutes = Math.round((Date.parse(`${returnDate}T${returnTime}:00Z`) - Date.parse(`${pickupDate}T${pickupTime}:00Z`)) / 60000);
    if (!Number.isFinite(minutes) || minutes <= 0) return { valid: false as const, minutes: 0, days: 0, extraMinutes: 0 };
    const days = Math.max(1, Math.floor(minutes / 1440));

    return { valid: true as const, minutes, days, extraMinutes: Math.max(0, minutes - days * 1440) };
}

/** 75 → "1 h 15 min", 1440 → "24 h". */
export function duration(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return [h ? `${h} h` : '', m || !h ? `${m} min` : ''].filter(Boolean).join(' ');
}

export const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;
