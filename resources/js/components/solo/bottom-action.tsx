import type { ReactNode } from 'react';
import styles from './bottom-action.module.css';

interface BottomActionProps {
    /** The screen's main action, as it renders on desktop. */
    children: ReactNode;
    /** A small action that sits above the main one on phones, after it on desktop. */
    secondary?: ReactNode;
    /** For a page whose desktop already shows the action elsewhere (a section head's Nowe zadanie). */
    phoneOnly?: boolean;
}

/**
 * Figma: A2f / Bottom action (848:20926). Below 768 px the main action is a full-width 48 px button fixed above the tab
 * bar in the surface's main look, and the page clears it (solo.css); from 768 px both actions render inline where the page puts them.
 */
export function BottomAction({ children, secondary, phoneOnly = false }: BottomActionProps) {
    return (
        <div
            className={[styles.bar, phoneOnly ? styles.phoneOnly : ''].filter(Boolean).join(' ')}
            data-solo-bottom-action=""
            data-secondary={secondary !== undefined ? '' : undefined}
        >
            <div className={styles.main}>{children}</div>
            {secondary !== undefined && <div className={styles.secondary}>{secondary}</div>}
        </div>
    );
}
