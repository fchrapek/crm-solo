import * as React from 'react';

import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import styles from './alert.module.css';

/** warn, error and ok are the Solo notice (Figma: Notice / warn|error|ok): a plain statement on the block colour with a tinted icon. */
type AlertVariant = 'default' | 'destructive' | 'warn' | 'error' | 'ok';

const variantStyles: Record<AlertVariant, string> = {
    default: styles.default,
    destructive: styles.destructive,
    warn: styles.notice,
    error: styles.notice,
    ok: styles.notice,
};

interface AlertProps extends React.ComponentProps<'div'> {
    variant?: AlertVariant;
}

function Alert({ className, variant = 'default', children, ...props }: AlertProps) {
    const notice = variant === 'warn' || variant === 'error' || variant === 'ok';

    return (
        <div
            data-slot="alert"
            data-tone={notice ? variant : undefined}
            role={notice && variant !== 'error' ? 'status' : 'alert'}
            className={cn(styles.alert, variantStyles[variant], className)}
            {...props}
        >
            {notice && <Icon name={variant === 'ok' ? 'check' : 'warning'} size={16} className={styles.noticeIcon} />}
            {notice ? <div className={styles.noticeText}>{children}</div> : children}
        </div>
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
