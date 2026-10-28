import * as ToggleGroupPrimitive from '@radix-ui/react-toggle-group';
import * as React from 'react';

import { cn } from '@/lib/utils';
import { getToggleStyles, type ToggleVariant, type ToggleSize } from '@/components/ui/toggle';
import styles from './toggle-group.module.css';

interface ToggleGroupContextValue {
    size?: ToggleSize;
    variant?: ToggleVariant;
}

const ToggleGroupContext = React.createContext<ToggleGroupContextValue>({
    size: 'default',
    variant: 'default',
});

type ToggleGroupProps = React.ComponentProps<typeof ToggleGroupPrimitive.Root> & {
    variant?: ToggleVariant;
    size?: ToggleSize;
    /** segmented: the Solo segmented control (Figma Mobile / Segmented control), 44 px pill segments. */
    look?: 'default' | 'segmented';
};

function ToggleGroup({ className, variant, size, look = 'default', children, ...props }: ToggleGroupProps) {
    return (
        <ToggleGroupPrimitive.Root
            data-slot="toggle-group"
            data-variant={variant}
            data-size={size}
            className={cn(styles.group, look === 'segmented' && styles.segmented, className)}
            {...props}
        >
            <ToggleGroupContext.Provider value={{ variant, size }}>
                {children}
            </ToggleGroupContext.Provider>
        </ToggleGroupPrimitive.Root>
    );
}

interface ToggleGroupItemProps extends React.ComponentProps<typeof ToggleGroupPrimitive.Item> {
    variant?: ToggleVariant;
    size?: ToggleSize;
}

function ToggleGroupItem({ className, children, variant, size, ...props }: ToggleGroupItemProps) {
    const context = React.useContext(ToggleGroupContext);
    const finalVariant = context.variant || variant;
    const finalSize = context.size || size;

    return (
        <ToggleGroupPrimitive.Item
            data-slot="toggle-group-item"
            data-variant={finalVariant}
            data-size={finalSize}
            className={cn(
                getToggleStyles({ variant: finalVariant, size: finalSize }),
                styles.item,
                className,
            )}
            {...props}
        >
            {children}
        </ToggleGroupPrimitive.Item>
    );
}

/** The segmented look for a group of links (a nav with aria-current), which Radix's toggle state does not fit. */
const segmentedLinkClasses = {
    group: cn(styles.group, styles.segmented),
    item: cn(getToggleStyles(), styles.item),
};

export { ToggleGroup, ToggleGroupItem, segmentedLinkClasses };
