import { SoloButton } from '@/components/solo/primitives';
import { type ReactNode } from 'react';
import styles from './tab.module.css';

/** A tile's own button, which opens a dialog. */
export function TileButton({ onClick, disabled, children }: { onClick: () => void; disabled?: boolean; children: ReactNode }) {
    return (
        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onClick} disabled={disabled}>
            {children}
        </SoloButton>
    );
}

/** A row's one quiet action, which opens the row's dialog. */
export function RowButton({ onClick, children }: { onClick: () => void; children: ReactNode }) {
    return (
        <SoloButton variant="quiet" size="control" aria-haspopup="dialog" className={styles.rowAction} onClick={onClick}>
            {children}
        </SoloButton>
    );
}
