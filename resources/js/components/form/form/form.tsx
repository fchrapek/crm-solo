import { FormEvent, ReactNode } from 'react';

import { cn } from '@/lib/utils';

import styles from './form.module.css';

interface FormProps {
    children: ReactNode;
    onSubmit: (e: FormEvent<HTMLFormElement>) => void;
    className?: string;
}

export function Form({ children, onSubmit, className }: FormProps) {
    return (
        <form onSubmit={onSubmit} className={cn(styles.form, className)}>
            {children}
        </form>
    );
}
