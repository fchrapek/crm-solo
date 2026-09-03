import { Slot } from '@radix-ui/react-slot';
import * as React from 'react';

import { cn } from '@/lib/utils';
import styles from './badge.module.css';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const variantStyles: Record<BadgeVariant, string> = {
    default: styles.default,
    secondary: styles.secondary,
    destructive: styles.destructive,
    outline: styles.outline,
};

interface BadgeProps extends React.ComponentProps<'span'> {
    variant?: BadgeVariant;
    asChild?: boolean;
}

function Badge({ className, variant = 'default', asChild = false, ...props }: BadgeProps) {
    const Comp = asChild ? Slot : 'span';

    return (
        <Comp
            data-slot="badge"
            className={cn(styles.badge, variantStyles[variant], className)}
            {...props}
        />
    );
}

export { Badge };
export type { BadgeVariant };
