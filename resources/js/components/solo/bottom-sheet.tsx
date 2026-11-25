import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { fullText } from '@/lib/plain-text';
import { type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { IconButton } from './icon-button';
import styles from './mobile.module.css';
import { SoloButton, touchTargetClass } from './primitives';

export interface BottomSheetPanelProps {
    title: ReactNode;
    /** close: the round close button; done: a centred title and Done; save: the Cancel pill, the centred title and an ink submit (form sheets, Figma 747:18916). */
    action?: 'close' | 'done' | 'save';
    /** The save header's submit label, Save by default. */
    saveLabel?: ReactNode;
    /** Disables the save header's buttons while the form is submitting. */
    saving?: boolean;
    /** Adds Clear at the start of a done header. */
    onClear?: () => void;
    /** A sheet that is the second step of a menu: a back arrow before the title, and Escape goes back instead of closing. */
    onBack?: () => void;
    description?: ReactNode;
    /** The footer, outside the scrolling body: touch-size SoloButtons, secondary first. */
    actions?: ReactNode;
    /** Makes the sheet one form, so a submit button among the actions submits the body's fields. */
    onSubmit?: (event: FormEvent<HTMLFormElement>) => void;
    children: ReactNode;
}

interface BottomSheetProps extends BottomSheetPanelProps {
    /** Where the focus goes on close, for a sheet opened without its trigger (see ResponsiveDialog). */
    onCloseAutoFocus?: (event: Event) => void;
    open?: boolean;
    defaultOpen?: boolean;
    onOpenChange?: (open: boolean) => void;
    trigger?: ReactNode;
}

/** Figma: Mobile / Bottom sheet. */
export function BottomSheet({ open, defaultOpen, onOpenChange, trigger, description, onCloseAutoFocus, ...panel }: BottomSheetProps) {
    const { onBack } = panel;

    return (
        <Sheet open={open} defaultOpen={defaultOpen} onOpenChange={onOpenChange}>
            {trigger !== undefined && <SheetTrigger asChild>{trigger}</SheetTrigger>}
            <SheetContent
                side="bottom"
                showCloseButton={false}
                onCloseAutoFocus={onCloseAutoFocus}
                onEscapeKeyDown={
                    onBack === undefined
                        ? undefined
                        : (event) => {
                              event.preventDefault();
                              onBack();
                          }
                }
                {...(description === undefined ? { 'aria-describedby': undefined } : {})}
            >
                <BottomSheetPanel description={description} {...panel} />
            </SheetContent>
        </Sheet>
    );
}

export function BottomSheetPanel({
    title,
    action = 'close',
    onClear,
    onBack,
    saveLabel,
    saving,
    description,
    actions,
    onSubmit,
    children,
}: BottomSheetPanelProps) {
    const { t } = useTranslation();
    const centred = action !== 'close';
    const panel = (
        <>
            <div className={styles.sheetHeader} data-action={action}>
                {action === 'save' && (
                    <SheetClose asChild>
                        <SoloButton variant="pill" className={`${styles.sheetCancel} ${touchTargetClass}`} disabled={saving}>
                            {t('Cancel')}
                        </SoloButton>
                    </SheetClose>
                )}
                {action === 'done' &&
                    (onClear ? (
                        <SoloButton variant="text" size="touch" className={styles.sheetClear} onClick={onClear}>
                            {t('Clear')}
                        </SoloButton>
                    ) : (
                        <span className={styles.sheetSpacer} aria-hidden="true" />
                    ))}
                {onBack !== undefined && <IconButton icon="chevron-left" look="quiet" label={t('Back')} className={styles.sheetBack} onClick={onBack} />}
                <SheetTitle className={styles.sheetTitle} title={fullText(title)}>
                    {title}
                </SheetTitle>
                {action === 'save' ? (
                    <SoloButton type="submit" variant="ink" size="sm" className={`${styles.sheetSave} ${touchTargetClass}`} disabled={saving}>
                        {saveLabel ?? t('Save')}
                    </SoloButton>
                ) : centred ? (
                    <SheetClose asChild>
                        <SoloButton variant="text" size="touch" className={styles.sheetDone}>
                            {t('Done')}
                        </SoloButton>
                    </SheetClose>
                ) : (
                    <SheetClose asChild>
                        <IconButton icon="close" look="quiet" label={t('Close')} />
                    </SheetClose>
                )}
            </div>
            <div className={styles.sheetBody} data-slot="sheet-body">
                {description !== undefined && <SheetDescription className={styles.sheetDescription}>{description}</SheetDescription>}
                {children}
            </div>
            {actions !== undefined && <SheetActions>{actions}</SheetActions>}
        </>
    );

    return onSubmit === undefined ? (
        panel
    ) : (
        <form className={styles.sheetForm} onSubmit={onSubmit} noValidate>
            {panel}
        </form>
    );
}

/** Figma 347:5881: the actions sit side by side while they fit, else stack full width with the primary on top, on a tone band across the sheet. */
function SheetActions({ children }: { children: ReactNode }) {
    return (
        <div className={styles.sheetFoot}>
            <div className={styles.sheetActions}>{children}</div>
        </div>
    );
}
