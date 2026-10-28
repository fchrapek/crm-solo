import { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import styles from './form-label.module.css';

interface FormLabelProps {
    children: ReactNode;
    htmlFor?: string;
    error?: string;
    className?: string;
}

export function FormLabel({ children, htmlFor, error, className }: FormLabelProps) {
    return (
        <label htmlFor={htmlFor} className={cn(styles.label, error && styles.error, className)}>
            {children}
        </label>
    );
}
