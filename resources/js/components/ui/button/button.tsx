import { Slot } from '@radix-ui/react-slot';
import * as React from 'react';

import { cn } from '@/lib/utils';
import styles from './button.module.css';

type ButtonVariant = 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
type ButtonSize = 'default' | 'sm' | 'lg' | 'icon';

interface ButtonProps extends React.ComponentProps<'button'> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    asChild?: boolean;
}

const variantStyles: Record<ButtonVariant, string> = {
    default: styles.default,
    destructive: styles.destructive,
    outline: styles.outline,
    secondary: styles.secondary,
    ghost: styles.ghost,
    link: styles.link,
};

const sizeStyles: Record<ButtonSize, string> = {
    default: styles.sizeDefault,
    sm: styles.sizeSm,
    lg: styles.sizeLg,
    icon: styles.sizeIcon,
};

/**
 * Get button class names for a given variant and size.
 * Use this when you need button styling on non-Button elements (e.g., Link).
 */
function getButtonStyles(options?: { variant?: ButtonVariant; size?: ButtonSize; className?: string }) {
    const { variant = 'default', size = 'default', className } = options || {};
    return cn(styles.button, variantStyles[variant], sizeStyles[size], className);
}

function Button({
    className,
    variant = 'default',
    size = 'default',
    asChild = false,
    ...props
}: ButtonProps) {
    const Comp = asChild ? Slot : 'button';

    return (
        <Comp
            data-slot="button"
            className={getButtonStyles({ variant, size, className })}
            {...props}
        />
    );
}

export { Button, getButtonStyles };
export type { ButtonProps, ButtonVariant, ButtonSize };
