import { useNow } from '@/hooks/use-now';

/** A running timer's hours, minutes and seconds, ticking each second; zero until the browser clock is read. */
export function useElapsed(startedAt: string) {
    const now = useNow(1000) ?? Date.parse(startedAt);
    const secs = Math.max(0, Math.floor((now - new Date(startedAt).getTime()) / 1000));

    return { h: Math.floor(secs / 3600), m: Math.floor((secs % 3600) / 60), s: secs % 60 };
}

/** 1:12, the way every running timer reads. */
export function elapsedClock({ h, m }: { h: number; m: number }): string {
    return `${h}:${String(m).padStart(2, '0')}`;
}
