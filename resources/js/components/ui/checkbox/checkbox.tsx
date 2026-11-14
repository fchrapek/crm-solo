import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import * as React from 'react';

import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import styles from './checkbox.module.css';

/** variant="switch" is Figma A2f / Switch: the same control announced as a switch, for a setting its label turns on. */
function Checkbox({ className, variant = 'box', ...props }: React.ComponentProps<typeof CheckboxPrimitive.Root> & { variant?: 'box' | 'switch' }) {
    if (variant === 'switch') {
        return (
            <CheckboxPrimitive.Root data-slot="switch" role="switch" className={cn(styles.switch, className)} {...props}>
                <span className={styles.knob} aria-hidden="true" />
            </CheckboxPrimitive.Root>
        );
    }

    return (
        <CheckboxPrimitive.Root
            data-slot="checkbox"
            className={cn(styles.checkbox, className)}
            {...props}
        >
            <CheckboxPrimitive.Indicator data-slot="checkbox-indicator" className={styles.indicator}>
                <Icon name="check" size={16} className={styles.icon} />
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    );
}

export { Checkbox };
