import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import * as React from 'react';

import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import styles from './checkbox.module.css';

function Checkbox({ className, ...props }: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
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
