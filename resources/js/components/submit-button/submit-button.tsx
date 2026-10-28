import { getButtonStyles, type ButtonSize, type ButtonVariant } from '@/components/ui/button';
import { Loader2 } from 'lucide-react';
import React from 'react';
import styles from './submit-button.module.css';

export interface SubmitButtonProps extends Omit<React.ComponentProps<'button'>, 'type'> {
    processing: boolean;
    variant?: ButtonVariant;
    size?: ButtonSize;
}

/** A submit whose label gives way to a spinner while the form submits. */
export function SubmitButton({ processing, disabled, children, className, variant, size, ...props }: SubmitButtonProps) {
    return (
        <button type="submit" disabled={disabled || processing} className={getButtonStyles({ variant, size, className })} aria-busy={processing || undefined} {...props}>
            <span className={styles.content}>
                <Loader2 className={styles.spinner} data-visible={processing || undefined} aria-hidden="true" />
                <span className={styles.label}>{children}</span>
            </span>
        </button>
    );
}
