import type { ReactNode } from 'react';
import styles from './chip.module.css';

export type ChipStatus = 'waiting' | 'done' | 'open' | 'agent' | 'hot' | 'warm' | 'cold';

/**
 * Figma: the status dot. Amber waits, green is done, neutral is open or a draft; an agent at work is the ink dot.
 * Figma: TierChip. A lead's tier is told by shape, in ink: hot is full, warm a thick ring, cold a thin one.
 */
export function StatusDot({ status }: { status: ChipStatus }) {
    return <span className={styles.dot} data-status={status} aria-hidden="true" />;
}

/** Figma: Chip. A state in a word; the dot carries the colour, the label stays ink. */
export function Chip({ status, children }: { status: ChipStatus; children: ReactNode }) {
    return (
        <span className={styles.chip}>
            <StatusDot status={status} />
            {children}
        </span>
    );
}
