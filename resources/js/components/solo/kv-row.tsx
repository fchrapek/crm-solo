import type { ReactNode } from 'react';
import styles from './kv-row.module.css';

/** Figma: KV row (Column, Split, Ledger). Renders dt and dd, so it sits inside a dl. */
export function KvRow({
    label,
    children,
    weight = 'medium',
    tone,
    layout = 'column',
}: {
    label: ReactNode;
    children: ReactNode;
    weight?: 'medium' | 'regular';
    tone?: 'agent' | 'muted' | 'accent';
    layout?: 'column' | 'split' | 'ledger';
}) {
    return (
        <div className={styles.row} data-layout={layout}>
            <dt className={styles.label}>{label}</dt>
            <dd className={styles.value} data-weight={weight} data-tone={tone}>
                {children}
            </dd>
        </div>
    );
}
