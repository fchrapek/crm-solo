import { InputHTMLAttributes } from 'react';

import { useProcessingContext } from '@/contexts/processing-context';
import { cn } from '@/lib/utils';

import styles from './form-input.module.css';

interface FormInputProps extends InputHTMLAttributes<HTMLInputElement> {
    error?: string;
}

export function FormInput({ className, error, ...props }: FormInputProps) {
    const { isProcessing } = useProcessingContext();
    const showError = error && !isProcessing;

    return <input className={cn(styles.input, showError && styles.error, className)} {...props} />;
}
