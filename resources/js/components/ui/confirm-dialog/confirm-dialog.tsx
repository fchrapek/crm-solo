import { AlertTriangle } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

import styles from './confirm-dialog.module.css';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    children?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: 'default' | 'destructive';
    confirmDisabled?: boolean;
    onConfirm: () => void;
}

export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    children,
    confirmLabel,
    cancelLabel,
    variant = 'default',
    confirmDisabled = false,
    onConfirm,
}: Props) {
    const { t } = useTranslation();

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle className={styles.title}>
                        <AlertTriangle size={20} className={styles.icon} />
                        {title}
                    </AlertDialogTitle>
                    {description && (
                        <AlertDialogDescription>{description}</AlertDialogDescription>
                    )}
                </AlertDialogHeader>
                {children}
                <AlertDialogFooter>
                    <AlertDialogCancel>{cancelLabel ?? t('Cancel')}</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={onConfirm}
                        disabled={confirmDisabled}
                        className={variant === 'destructive' ? styles.destructiveAction : undefined}
                    >
                        {confirmLabel ?? t('Confirm')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
