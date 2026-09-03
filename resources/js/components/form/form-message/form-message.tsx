import { useProcessingContext } from '@/contexts/processing-context';
import { cn } from '@/lib/utils';

import styles from './form-message.module.css';

interface FormMessageProps {
    error?: string;
    className?: string;
}

export function FormMessage({ error, className }: FormMessageProps) {
    const { isProcessing } = useProcessingContext();

    if (!error || isProcessing) return null;

    return <p className={cn(styles.message, className)}>{error}</p>;
}
