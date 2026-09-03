import * as React from 'react';

import { cn } from '@/lib/utils';
import styles from './alert.module.css';

type AlertVariant = 'default' | 'destructive';

const variantStyles: Record<AlertVariant, string> = {
    default: styles.default,
    destructive: styles.destructive,
};

interface AlertProps extends React.ComponentProps<'div'> {
    variant?: AlertVariant;
}

function Alert({ className, variant = 'default', ...props }: AlertProps) {
    return (
        <div
            data-slot="alert"
            role="alert"
            className={cn(styles.alert, variantStyles[variant], className)}
            {...props}
        />
    );
}

function AlertTitle({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="alert-title" className={cn(styles.title, className)} {...props} />;
}

function AlertDescription({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="alert-description" className={cn(styles.description, className)} {...props} />;
}

export { Alert, AlertTitle, AlertDescription };
export type { AlertVariant };
