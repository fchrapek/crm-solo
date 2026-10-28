import { InputHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

import styles from './form-input.module.css';

interface FormInputProps extends InputHTMLAttributes<HTMLInputElement> {
    error?: string;
}

export function FormInput({ className, error, ...props }: FormInputProps) {
    return <input className={cn(styles.input, error && styles.error, className)} {...props} />;
}
