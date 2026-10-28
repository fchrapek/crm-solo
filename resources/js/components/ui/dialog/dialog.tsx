import * as DialogPrimitive from '@radix-ui/react-dialog';
import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { useSoloPortalSurface } from '@/components/solo/surface';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import styles from './dialog.module.css';

function Dialog({ ...props }: React.ComponentProps<typeof DialogPrimitive.Root>) {
    return <DialogPrimitive.Root data-slot="dialog" {...props} />;
}

function DialogTrigger({ ...props }: React.ComponentProps<typeof DialogPrimitive.Trigger>) {
    return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />;
}

function DialogPortal({ ...props }: React.ComponentProps<typeof DialogPrimitive.Portal>) {
    return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />;
}

function DialogClose({ ...props }: React.ComponentProps<typeof DialogPrimitive.Close>) {
    return <DialogPrimitive.Close data-slot="dialog-close" {...props} />;
}

function DialogOverlay({
    className,
    ...props
}: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
    return (
        <DialogPrimitive.Overlay
            data-slot="dialog-overlay"
            className={cn(styles.overlay, className)}
            {...props}
        />
    );
}

function DialogContent({
    className,
    children,
    closeButton = true,
    ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & {
    /** false: no × in the corner, for a dialog that closes another way (the ⌘K palette's Esc and Anuluj). */
    closeButton?: boolean;
}) {
    const { t } = useTranslation();
    const container = useSoloPortalSurface();

    return (
        <DialogPortal data-slot="dialog-portal" container={container}>
            <DialogOverlay />
            <DialogPrimitive.Content
                data-slot="dialog-content"
                className={cn(styles.content, className)}
                {...props}
            >
                <div className={styles.scroll}>{children}</div>
                {closeButton && (
                    <DialogPrimitive.Close className={styles.closeButton}>
                        <Icon name="close" size={16} />
                        <span className="sr-only">{t('Close')}</span>
                    </DialogPrimitive.Close>
                )}
            </DialogPrimitive.Content>
        </DialogPortal>
    );
}

/** Must be the first child of DialogContent: the close button is pinned beside the title, and the header's right padding keeps the title clear of it. */
function DialogHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="dialog-header" className={cn(styles.header, className)} {...props} />;
}

/** A form spanning the body and the footer; it stays inside the dialog's height so only DialogBody scrolls. */
function DialogForm({ className, ...props }: React.ComponentProps<'form'>) {
    return <form data-slot="dialog-form" className={cn(styles.form, className)} {...props} />;
}

function DialogBody({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="dialog-body" className={cn(styles.body, className)} {...props} />;
}

function DialogFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="dialog-footer" className={cn(styles.footer, className)} {...props} />;
}

function DialogTitle({ className, ...props }: React.ComponentProps<typeof DialogPrimitive.Title>) {
    return (
        <DialogPrimitive.Title
            data-slot="dialog-title"
            className={cn(styles.title, className)}
            {...props}
        />
    );
}

function DialogDescription({
    className,
    ...props
}: React.ComponentProps<typeof DialogPrimitive.Description>) {
    return (
        <DialogPrimitive.Description
            data-slot="dialog-description"
            className={cn(styles.description, className)}
            {...props}
        />
    );
}

export {
    Dialog,
    DialogBody,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogForm,
    DialogHeader,
    DialogOverlay,
    DialogPortal,
    DialogTitle,
    DialogTrigger,
};
