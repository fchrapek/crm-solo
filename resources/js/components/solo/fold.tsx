import type { ReactNode } from 'react';
import styles from './fold.module.css';

/** The body of whatever opens and closes in place; its button owns `aria-expanded` and names `id` in `aria-controls`. */
export function Fold({ id, open, children }: { id: string; open: boolean; children: ReactNode }) {
    return (
        <div id={id} className={styles.body} data-fold="" data-closed={!open || undefined} inert={!open}>
            <div className={styles.fold}>{children}</div>
        </div>
    );
}
