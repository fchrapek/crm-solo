import { Icon } from '@/components/ui/icon';
import type { ReactNode } from 'react';
import styles from './notice.module.css';

const ICON = { lock: 'lock', warn: 'warning', info: 'info' } as const;

/**
 * Figma: Notice. A message inside a tile or a dialog: why figures are frozen (lock), what has to happen first (warn),
 * or a plain remark (info). Ink on the page ground, never a colour of its own.
 */
export function Notice({ kind = 'info', role, children }: { kind?: 'lock' | 'warn' | 'info'; role?: 'alert' | 'status'; children: ReactNode }) {
    return (
        <p className={styles.notice} role={role} data-fill="">
            <Icon name={ICON[kind]} size={16} className={styles.icon} />
            <span>{children}</span>
        </p>
    );
}
