import { ReactNode } from 'react';

import { useProcessingContext } from '@/contexts/processing-context';
import { cn } from '@/lib/utils';

import styles from './form-label.module.css';

interface FormLabelProps {
    children: ReactNode;
    htmlFor?: string;
    error?: string;
    className?: string;
}

export function FormLabel({ children, htmlFor, error, className }: FormLabelProps) {
    const { isProcessing } = useProcessingContext();
    const showError = error && !isProcessing;

    return (
        <label htmlFor={htmlFor} className={cn(styles.label, showError && styles.error, className)}>
            {children}
        </label>
    );
}
