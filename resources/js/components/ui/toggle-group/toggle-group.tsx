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
};

function ToggleGroup({ className, variant, size, children, ...props }: ToggleGroupProps) {
    return (
        <ToggleGroupPrimitive.Root
            data-slot="toggle-group"
            data-variant={variant}
            data-size={size}
            className={cn(styles.group, className)}
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

export { ToggleGroup, ToggleGroupItem };
