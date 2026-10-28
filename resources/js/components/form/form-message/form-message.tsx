import { cn } from '@/lib/utils';

import styles from './form-message.module.css';

interface FormMessageProps {
    error?: string;
    className?: string;
}

export function FormMessage({ error, className }: FormMessageProps) {
    if (!error) return null;

    return <p className={cn(styles.message, className)}>{error}</p>;
}
