import { type ReactNode, useState } from 'react';
import { BottomSheet, type BottomSheetPanelProps } from './bottom-sheet';
import { ListRow } from './list-row';
import styles from './mobile.module.css';
import { useSoloWide } from './use-solo-wide';

interface ResponsiveSectionProps extends Pick<BottomSheetPanelProps, 'action' | 'actions' | 'description'> {
    /** The phone row's title, and the sheet title unless sheetTitle is set. */
    label: ReactNode;
    /** The phone row's trailing value, such as a balance. */
    summary?: ReactNode;
    sub?: ReactNode;
    sheetTitle?: ReactNode;
    className?: string;
    children: ReactNode;
}

/** Inline from 768 px, a row opening a bottom sheet below it; CSS shows the branch, so nothing shifts. */
export function ResponsiveSection({ label, summary, sub, sheetTitle, action, actions, description, className, children }: ResponsiveSectionProps) {
    const wide = useSoloWide();
    const [open, setOpen] = useState(false);

    return (
        <div className={className}>
            {/* Radix keeps a closing sheet's content for its exit animation, so each copy is gated by width, not by open. */}
            <div className={styles.sectionInline}>{wide && children}</div>
            <div className={styles.sectionPhone}>
                <BottomSheet
                    open={open && !wide}
                    onOpenChange={setOpen}
                    title={sheetTitle ?? label}
                    action={action}
                    actions={actions}
                    description={description}
                    trigger={<ListRow title={label} sub={sub} value={summary} aria-haspopup="dialog" />}
                >
                    {!wide && children}
                </BottomSheet>
            </div>
        </div>
    );
}
