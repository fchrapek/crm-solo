import * as TogglePrimitive from '@radix-ui/react-toggle';
import * as React from 'react';

import { cn } from '@/lib/utils';
import styles from './toggle.module.css';

type ToggleVariant = 'default' | 'outline';
type ToggleSize = 'default' | 'sm' | 'lg';

const variantStyles: Record<ToggleVariant, string> = {
    default: styles.default,
    outline: styles.outline,
};

const sizeStyles: Record<ToggleSize, string> = {
    default: styles.sizeDefault,
    sm: styles.sizeSm,
    lg: styles.sizeLg,
};

interface ToggleProps extends React.ComponentProps<typeof TogglePrimitive.Root> {
    variant?: ToggleVariant;
    size?: ToggleSize;
}

function getToggleStyles(options?: { variant?: ToggleVariant; size?: ToggleSize; className?: string }) {
    const { variant = 'default', size = 'default', className } = options || {};
    return cn(styles.toggle, variantStyles[variant], sizeStyles[size], className);
}

function Toggle({ className, variant = 'default', size = 'default', ...props }: ToggleProps) {
    return (
        <TogglePrimitive.Root
            data-slot="toggle"
            className={getToggleStyles({ variant, size, className })}
            {...props}
        />
    );
}

export { Toggle, getToggleStyles };
export type { ToggleVariant, ToggleSize };
