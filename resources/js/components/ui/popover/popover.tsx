import * as PopoverPrimitive from '@radix-ui/react-popover';
import * as React from 'react';

import { menuBoxClass } from '@/components/solo/menu';
import { useSoloPortalSurface } from '@/components/solo/surface';
import { cn } from '@/lib/utils';
import styles from './popover.module.css';

function Popover({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Root>) {
    return <PopoverPrimitive.Root data-slot="popover" {...props} />;
}

function PopoverTrigger({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Trigger>) {
    return <PopoverPrimitive.Trigger data-slot="popover-trigger" {...props} />;
}

function PopoverContent({
    className,
    align = 'center',
    sideOffset = 4,
    look = 'default',
    ...props
}: React.ComponentProps<typeof PopoverPrimitive.Content> & {
    /** solo-menu: Figma Menu / Filter, the select menu's panel for choices that are not one value. */
    look?: 'default' | 'solo-menu';
}) {
    const container = useSoloPortalSurface();

    return (
        <PopoverPrimitive.Portal container={container}>
            <PopoverPrimitive.Content
                data-slot="popover-content"
                align={align}
                sideOffset={sideOffset}
                className={cn(styles.content, look === 'solo-menu' && [styles.soloMenu, menuBoxClass], className)}
                {...props}
            />
        </PopoverPrimitive.Portal>
    );
}

function PopoverAnchor({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Anchor>) {
    return <PopoverPrimitive.Anchor data-slot="popover-anchor" {...props} />;
}

export { Popover, PopoverTrigger, PopoverContent, PopoverAnchor };
