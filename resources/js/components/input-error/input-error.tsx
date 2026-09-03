import { type HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

import styles from './input-error.module.css';

export default function InputError({ message, className = '', ...props }: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p {...props} className={cn(styles.error, className)}>
            {message}
        </p>
    ) : null;
}
