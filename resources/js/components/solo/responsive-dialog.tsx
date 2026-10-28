import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogForm, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type FormEvent, type KeyboardEvent, type ReactNode, useState } from 'react';
import { BottomSheet } from './bottom-sheet';
import styles from './mobile.module.css';
import { useSoloWide } from './use-solo-wide';

export type ResponsiveDialogSize = 'md' | 'touch';

interface ResponsiveDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    /** A shorter title for the sheet header, where Anuluj and the submit share the line. */
    sheetTitle?: ReactNode;
    description?: ReactNode;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    /** Secondary first, primary last, at the size the dialog or the sheet gives them. */
    actions: (size: ResponsiveDialogSize) => ReactNode;
    /** False keeps the dialog on phones too, for a caller outside the Solo pages. */
    sheet?: boolean;
    /** save: the sheet puts Anuluj and the submit in its header instead of the footer (Figma 347:5096). */
    sheetAction?: 'close' | 'save';
    saveLabel?: ReactNode;
    saving?: boolean;
    /** Form fields: the sheet spaces its children as the dialog body does (Figma 347:5096). */
    fields?: boolean;
    footerClassName?: string;
    children: ReactNode;
}

/** A form dialog: the themed Dialog from 768 px, a bottom sheet below it, the same form and actions in both. */
export function ResponsiveDialog({
    open,
    onOpenChange,
    title,
    sheetTitle,
    description,
    onSubmit,
    actions,
    sheet = true,
    sheetAction = 'close',
    saveLabel,
    saving,
    fields,
    footerClassName,
    children,
}: ResponsiveDialogProps) {
    const wide = useSoloWide();
    // Without a DialogTrigger Radix has nothing to refocus, so the opener is read while rendering the open, before an autoFocus field moves the focus.
    const [opener, setOpener] = useState<HTMLElement | null>(null);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open && typeof document !== 'undefined') {
            setOpener(returnTarget(document.activeElement));
        }
    }
    const restoreFocus = (event: Event) => {
        if (opener?.isConnected) {
            event.preventDefault();
            opener.focus();
        }
    };

    if (sheet && !wide) {
        return (
            <BottomSheet
                open={open}
                onOpenChange={onOpenChange}
                title={sheetTitle ?? title}
                description={description}
                action={sheetAction}
                saveLabel={saveLabel}
                saving={saving}
                actions={sheetAction === 'save' ? undefined : actions('touch')}
                onSubmit={onSubmit}
                onCloseAutoFocus={restoreFocus}
            >
                {fields ? <div className={styles.sheetFields}>{children}</div> : children}
            </BottomSheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                onCloseAutoFocus={restoreFocus}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description !== undefined && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                <DialogForm onSubmit={onSubmit} onKeyDown={submitOnCommandEnter} noValidate>
                    <DialogBody>{children}</DialogBody>
                    <DialogFooter className={[styles.dialogFooter, footerClassName].filter(Boolean).join(' ')}>{actions('md')}</DialogFooter>
                </DialogForm>
            </DialogContent>
        </Dialog>
    );
}

/** The control to refocus on close: an item of the ••• menu is gone by then, so its menu's trigger stands in. */
export function returnTarget(active: Element | null, controls: () => Iterable<Element> = () => document.querySelectorAll('[aria-controls]')): HTMLElement | null {
    if (active === null || !('focus' in active)) return null;

    const menu = active.closest('[role="menu"]');
    if (menu === null) return active as HTMLElement;

    for (const control of controls()) {
        if (menu.id !== '' && control.getAttribute('aria-controls') === menu.id) return control as HTMLElement;
    }

    return null;
}

/**
 * ⌘↵ (Ctrl+Enter elsewhere) sends the form from any field, the textarea included, as the primary button's hint says.
 * Like Enter, it does nothing while the form's submit button is disabled, so a pending save is never sent twice.
 */
export function submitOnCommandEnter(event: Pick<KeyboardEvent<HTMLFormElement>, 'key' | 'metaKey' | 'ctrlKey' | 'currentTarget' | 'preventDefault'>) {
    if (event.key !== 'Enter' || !(event.metaKey || event.ctrlKey)) return;
    event.preventDefault();
    const form = event.currentTarget;
    const submitter = Array.from(form.elements).find((element) => (element as HTMLButtonElement).type === 'submit') as HTMLButtonElement | undefined;
    if (submitter?.disabled) return;
    form.requestSubmit();
}
