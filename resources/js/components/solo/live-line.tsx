import type { ReactNode } from 'react';
import styles from './live-line.module.css';

/** Figma: Live agent line. */
export function LiveLine({ children, size = 'md' }: { children: ReactNode; size?: 'md' | 'sm' }) {
    return (
        <span className={styles.line} data-size={size}>
            <span className={styles.mark} aria-hidden="true" />
            <span className={styles.label}>{children}</span>
        </span>
    );
}
