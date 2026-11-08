import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/** Quiet partial refresh for operational screens. It pauses while hidden or while a dialog is open. */
export function useLiveRefresh(only: string[], intervalMs = 30_000) {
    const running = useRef(false);
    const key = only.join('|');

    useEffect(() => {
        if (only.length === 0) return;
        const refresh = () => {
            if (running.current || document.visibilityState !== 'visible' || document.querySelector('dialog[open]')) return;
            running.current = true;
            router.reload({
                only,
                onFinish: () => { running.current = false; },
            });
        };
        const timer = window.setInterval(refresh, intervalMs);
        const onVisible = () => { if (document.visibilityState === 'visible') refresh(); };
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [intervalMs, key]);
}
