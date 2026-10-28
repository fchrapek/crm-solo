import styles from './hours-bar.module.css';

export interface HoursBarSegments {
    /** Hours drawn inside the pool. */
    inPool: number;
    /** Free hours when under, hours past the pool when over. */
    rest: number;
    over: boolean;
}

export function hoursBarSegments(used: number, available: number): HoursBarSegments {
    const pool = Math.max(0, available);
    const spent = Math.max(0, used);

    return spent > pool ? { inPool: pool, rest: spent - pool, over: true } : { inPool: spent, rest: pool - spent, over: false };
}

/** Row is the list cell; block spans the hours section. */
export function HoursBar({ used, available, size = 'row' }: { used: number; available: number; size?: 'row' | 'block' }) {
    const { inPool, rest, over } = hoursBarSegments(used, available);
    const empty = inPool === 0 && rest === 0;

    return (
        <span className={styles.bar} data-state={over ? 'over' : 'under'} data-size={size} aria-hidden="true">
            {inPool > 0 && <span className={styles.inPool} style={{ flexGrow: inPool }} />}
            {(rest > 0 || empty) && <span className={styles.rest} style={{ flexGrow: empty ? 1 : rest }} />}
        </span>
    );
}
