import { Ban, CalendarCheck, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button, cn } from '@/components/ui';

export type PickerRoom = { id: number; label: string; included?: number; max: number; category?: string };
type Availability = { state: 'waiting' | 'checking' | 'ready' | 'failed'; taken: Record<number, string> };

export const nextDay = (date: string) => new Date(Date.parse(`${date}T00:00:00Z`) + 86400000).toISOString().slice(0, 10);

/** Asks the server which rooms are taken for these dates, so what can be ticked always comes from live inventory. */
export function useRoomAvailability(checkIn: string, checkOut: string, ignoreBooking?: number) {
    const [availability, setAvailability] = useState<Availability>({ state: 'waiting', taken: {} });
    const [attempt, setAttempt] = useState(0);
    const valid = !!checkIn && !!checkOut && checkIn < checkOut;

    useEffect(() => {
        if (!valid) {
            setAvailability({ state: 'waiting', taken: {} });
            return;
        }
        const request = new AbortController();
        setAvailability((current) => ({ ...current, state: 'checking' }));
        const query = new URLSearchParams({ check_in: checkIn, check_out: checkOut, ...(ignoreBooking ? { ignore_booking: String(ignoreBooking) } : {}) });
        fetch(`/admin/bookings/availability?${query}`, { headers: { Accept: 'application/json' }, signal: request.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('check failed'))))
            .then((body) => setAvailability({ state: 'ready', taken: body.taken }))
            .catch((error) => {
                if (error.name !== 'AbortError') setAvailability({ state: 'failed', taken: {} });
            });

        return () => request.abort();
    }, [checkIn, checkOut, ignoreBooking, valid, attempt]);

    // Keep an open booking form current while another staff member may be changing the calendar.
    useEffect(() => {
        if (!valid) return;
        const refresh = () => { if (document.visibilityState === 'visible') setAttempt((n) => n + 1); };
        const timer = window.setInterval(refresh, 20_000);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [valid]);

    return { ...availability, retry: useCallback(() => setAttempt((n) => n + 1), []) };
}

/** Rooms can only be ticked once the dates are known, and a taken room can never be ticked. */
export function RoomPicker({ rooms, availability, value, onChange, error }: { rooms: PickerRoom[]; availability: ReturnType<typeof useRoomAvailability>; value: number[]; onChange: (ids: number[]) => void; error?: string }) {
    const { state, taken, retry } = availability;
    const [removed, setRemoved] = useState<string[]>([]);
    const ready = state === 'ready';

    // New dates can make a room already ticked unavailable: untick it and say so, rather than fail at Save.
    useEffect(() => {
        if (!ready) return;
        const clash = value.filter((id) => taken[id]);
        setRemoved(clash.map((id) => rooms.find((r) => r.id === id)?.label ?? ''));
        if (clash.length) onChange(value.filter((id) => !taken[id]));
    }, [ready, taken]);

    const free = rooms.filter((r) => !taken[r.id]);
    const unavailable = rooms.filter((r) => taken[r.id]);

    return (
        <fieldset>
            <legend className="mb-1 text-sm font-medium">Rooms <span className="text-red-800">*</span></legend>
            <p role="status" className="mb-1.5 flex items-center gap-1.5 text-xs text-muted">
                {state === 'waiting' && 'Choose check-in and check-out first. The rooms free for those dates can then be ticked.'}
                {state === 'checking' && <><Loader2 size={13} className="animate-spin" aria-hidden /> Checking which rooms are free…</>}
                {ready && <><CalendarCheck size={13} className="text-emerald-800" aria-hidden /> {free.length} free for these dates{unavailable.length > 0 && `, ${unavailable.length} not available`}</>}
            </p>
            {state === 'failed' && (
                <p role="alert" className="mb-1.5 flex flex-wrap items-center gap-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-900">
                    Could not check the rooms, so none can be chosen right now.
                    <Button small onClick={retry}>Try again</Button>
                </p>
            )}
            {removed.length > 0 && <p role="alert" className="mb-1.5 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-950">Removed because it is taken for these dates: {removed.join(', ')}.</p>}
            <div className="grid max-h-72 gap-1 overflow-y-auto rounded-lg border border-line p-2 text-sm">
                {free.map((r) => (
                    <label key={r.id} className={cn('flex items-center gap-2 rounded px-1 pointer-coarse:min-h-11', ready ? 'hover:bg-beige' : 'text-muted')}>
                        <input type="checkbox" disabled={!ready} checked={value.includes(r.id)} onChange={(ev) => onChange(ev.target.checked ? [...value, r.id] : value.filter((id) => id !== r.id))} />
                        <span>{r.label} {r.included !== undefined && r.category !== 'cameras' && <span className="text-xs text-muted">incl. {r.included}, max {r.max}</span>}</span>
                    </label>
                ))}
                {unavailable.map((r) => (
                    <div key={r.id} className="flex items-start gap-2 rounded bg-ivory px-1 py-0.5 text-muted">
                        <Ban size={14} className="mt-0.5 shrink-0 text-red-800" aria-hidden />
                        <span><span className="line-through">{r.label}</span> <span className="sr-only">not available.</span><span className="block text-xs">{taken[r.id]}</span></span>
                    </div>
                ))}
            </div>
            {error && <p role="alert" className="mt-1 text-xs text-red-800">{error}</p>}
        </fieldset>
    );
}
