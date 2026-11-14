import type { ReactNode } from 'react';
import styles from './kv-row.module.css';

/** Figma: KV row (Column, Split, Ledger); tile is the redesign's KvRow, a label and its value at the row's ends inside a tile; field is a secondary label with a longer text (above it on a phone, beside it from 768 px). Renders dt and dd, so it sits inside a dl. */
export function KvRow({
    label,
    children,
    weight = 'medium',
    tone,
    layout = 'column',
    total = false,
}: {
    label: ReactNode;
    children: ReactNode;
    weight?: 'medium' | 'regular';
    tone?: 'agent' | 'muted' | 'accent' | 'over';
    layout?: 'column' | 'split' | 'ledger' | 'tile' | 'field';
    /** The row the rows above add up to: ink label and a heavier line over it; tile only. */
    total?: boolean;
}) {
    return (
        <div className={styles.row} data-layout={layout} data-total={total ? '' : undefined}>
            <dt className={styles.label}>{label}</dt>
            <dd className={styles.value} data-weight={weight} data-tone={tone}>
                {children}
            </dd>
        </div>
    );
}
