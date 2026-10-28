import { Toaster } from '@/components/ui/sonner';
import type { ToasterProps } from 'sonner';
import styles from './toaster.module.css';

export const SOLO_TOAST_OFFSET = { bottom: 'var(--solo-toast-offset)' };

/** Its own paper surface keeps toasts readable on vermilion and cobalt; on phones they sit above the tab bar. */
export function SoloToaster(props: ToasterProps) {
    return (
        <div data-day="paper" className={styles.surface}>
            <Toaster className={styles.toasts} position="bottom-right" offset={SOLO_TOAST_OFFSET} mobileOffset={SOLO_TOAST_OFFSET} {...props} />
        </div>
    );
}
