import * as SeparatorPrimitive from '@radix-ui/react-separator';
import * as React from 'react';

import { cn } from '@/lib/utils';
import styles from './separator.module.css';

function Separator({
    className,
    orientation = 'horizontal',
    decorative = true,
    ...props
}: React.ComponentProps<typeof SeparatorPrimitive.Root>) {
    return (
        <SeparatorPrimitive.Root
            data-slot="separator-root"
            decorative={decorative}
            orientation={orientation}
            className={cn(styles.separator, className)}
            {...props}
        />
    );
}

export { Separator };
