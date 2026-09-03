import { getButtonStyles, type ButtonSize, type ButtonVariant } from '@/components/ui/button';
import { useProcessingContext } from '@/contexts/processing-context';
import { cn } from '@/lib/utils';
import { Check, Loader2 } from 'lucide-react';
import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import styles from './submit-button.module.css';

export interface SubmitButtonProps extends Omit<React.ComponentProps<'button'>, 'type'> {
    processing: boolean;
    variant?: ButtonVariant;
    size?: ButtonSize;
}

export function SubmitButton({ processing, disabled, children, className, variant, size, ...props }: SubmitButtonProps) {
    const { showSuccess, setShowSuccess } = useProcessingContext();
    const buttonRef = useRef<HTMLButtonElement>(null);
    const [measuredWidth, setMeasuredWidth] = useState<number | null>(null);
    const [isMeasuring, setIsMeasuring] = useState(true);
    const [enableTransitions, setEnableTransitions] = useState(false);
    const childrenRef = useRef(children);

    const showSpinner = isMeasuring || processing;
    const showCheck = showSuccess && !processing;

    // Re-measure when children change (e.g., language switch)
    useLayoutEffect(() => {
        if (childrenRef.current !== children) {
            childrenRef.current = children;
            setIsMeasuring(true);
            setMeasuredWidth(null);
            setEnableTransitions(false);
        }
    }, [children]);

    // Measure button width with spinner visible
    useLayoutEffect(() => {
        if (buttonRef.current && isMeasuring) {
            const width = buttonRef.current.getBoundingClientRect().width;
            setMeasuredWidth(width);
            setIsMeasuring(false);
        }
    }, [isMeasuring]);

    // Enable transitions after initial render to avoid flash
    useEffect(() => {
        if (!isMeasuring && !enableTransitions) {
            const timeout = setTimeout(() => setEnableTransitions(true), 0);
            return () => clearTimeout(timeout);
        }
    }, [isMeasuring, enableTransitions]);

    // Reset success when a new submission starts (clears any existing timer)
    useEffect(() => {
        if (processing) {
            setShowSuccess(false);
        }
    }, [processing, setShowSuccess]);

    useEffect(() => {
        if (showSuccess) {
            const timeout = setTimeout(() => setShowSuccess(false), 1500);
            return () => clearTimeout(timeout);
        }
    }, [showSuccess, setShowSuccess]);

    return (
        <button
            ref={buttonRef}
            type="submit"
            disabled={disabled || processing}
            className={getButtonStyles({ variant, size, className })}
            style={{
                visibility: isMeasuring ? 'hidden' : 'visible',
                minWidth: measuredWidth ? `${measuredWidth}px` : undefined,
            }}
            {...props}
        >
            <span className={cn(styles.content, enableTransitions && styles.withTransition, (showSpinner || showCheck) && styles.expanded)}>
                <span className={cn(styles.iconWrapper, (showSpinner || showCheck) && styles.iconVisible)}>
                    {showCheck ? (
                        <span className={styles.successIcon}>
                            <Check className={styles.checkIcon} />
                        </span>
                    ) : (
                        <Loader2 className={styles.spinner} />
                    )}
                </span>
                <span>{children}</span>
            </span>
        </button>
    );
}
