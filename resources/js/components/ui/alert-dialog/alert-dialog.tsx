import * as AlertDialogPrimitive from '@radix-ui/react-alert-dialog';
import * as React from 'react';

import { getButtonStyles } from '@/components/ui/button';
import { soloButtonClass } from '@/components/solo/primitives';
import { useInSoloSurface, useSoloPortalSurface } from '@/components/solo/surface';
import { cn } from '@/lib/utils';
import styles from './alert-dialog.module.css';

function AlertDialog({ ...props }: React.ComponentProps<typeof AlertDialogPrimitive.Root>) {
    return <AlertDialogPrimitive.Root data-slot="alert-dialog" {...props} />;
}

function AlertDialogTrigger({ ...props }: React.ComponentProps<typeof AlertDialogPrimitive.Trigger>) {
    return <AlertDialogPrimitive.Trigger data-slot="alert-dialog-trigger" {...props} />;
}

function AlertDialogPortal({ ...props }: React.ComponentProps<typeof AlertDialogPrimitive.Portal>) {
    return <AlertDialogPrimitive.Portal data-slot="alert-dialog-portal" {...props} />;
}

function AlertDialogOverlay({
    className,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Overlay>) {
    return (
        <AlertDialogPrimitive.Overlay
            data-slot="alert-dialog-overlay"
            className={cn(styles.overlay, className)}
            {...props}
        />
    );
}

function AlertDialogContent({
    className,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Content>) {
    const container = useSoloPortalSurface();

    return (
        <AlertDialogPortal container={container}>
            <AlertDialogOverlay />
            <AlertDialogPrimitive.Content
                data-slot="alert-dialog-content"
                className={cn(styles.content, className)}
                {...props}
            />
        </AlertDialogPortal>
    );
}

function AlertDialogHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="alert-dialog-header" className={cn(styles.header, className)} {...props} />;
}

function AlertDialogFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="alert-dialog-footer" className={cn(styles.footer, className)} {...props} />;
}

function AlertDialogTitle({
    className,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Title>) {
    return (
        <AlertDialogPrimitive.Title
            data-slot="alert-dialog-title"
            className={cn(styles.title, className)}
            {...props}
        />
    );
}

function AlertDialogDescription({
    className,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Description>) {
    return (
        <AlertDialogPrimitive.Description
            data-slot="alert-dialog-description"
            className={cn(styles.description, className)}
            {...props}
        />
    );
}

function AlertDialogAction({
    className,
    tone,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Action> & { tone?: 'destructive' }) {
    const solo = useInSoloSurface();
    const destructive = tone === 'destructive';

    return (
        <AlertDialogPrimitive.Action
            className={
                solo
                    ? cn(soloButtonClass({ variant: 'ink', size: 'md' }), destructive && styles.soloDestructive, className)
                    : getButtonStyles({ variant: destructive ? 'destructive' : 'default', className })
            }
            {...props}
        />
    );
}

function AlertDialogCancel({
    className,
    ...props
}: React.ComponentProps<typeof AlertDialogPrimitive.Cancel>) {
    const solo = useInSoloSurface();

    return (
        <AlertDialogPrimitive.Cancel
            className={solo ? cn(soloButtonClass({ variant: 'pill' }), className) : getButtonStyles({ variant: 'outline', className })}
            {...props}
        />
    );
}

export {
    AlertDialog,
    AlertDialogPortal,
    AlertDialogOverlay,
    AlertDialogTrigger,
    AlertDialogContent,
    AlertDialogHeader,
    AlertDialogFooter,
    AlertDialogTitle,
    AlertDialogDescription,
    AlertDialogAction,
    AlertDialogCancel,
};
