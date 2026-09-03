import * as CollapsiblePrimitive from '@radix-ui/react-collapsible';
import { ChevronDown, ChevronRight } from 'lucide-react';
import React, { useState } from 'react';

import styles from './collapsible-group.module.css';

interface CollapsibleGroupProps {
    /** Title text or element displayed in the header */
    title: React.ReactNode;
    /** Optional count badge (e.g. "3/5") shown next to title */
    count?: React.ReactNode;
    /** Optional icon shown before the title */
    icon?: React.ReactNode;
    /** Optional element shown on the right side of the header (e.g. external link) */
    trailing?: React.ReactNode;
    /** Whether the group starts expanded (default: true) */
    defaultOpen?: boolean;
    /** Content rendered when expanded */
    children: React.ReactNode;
}

function CollapsibleGroup({ title, count, icon, trailing, defaultOpen = true, children }: CollapsibleGroupProps) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <CollapsiblePrimitive.Root open={open} onOpenChange={setOpen} className={styles.group}>
            <div className={styles.header}>
                <CollapsiblePrimitive.Trigger asChild>
                    <button type="button" className={styles.headerTrigger}>
                        <span className={styles.chevron}>
                            {open ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                        </span>
                        {icon && <span className={styles.icon}>{icon}</span>}
                        <span className={styles.title}>{title}</span>
                        {count != null && <span className={styles.count}>{count}</span>}
                    </button>
                </CollapsiblePrimitive.Trigger>
                {trailing && (
                    <div className={styles.headerRight}>
                        {trailing}
                    </div>
                )}
            </div>
            <CollapsiblePrimitive.Content className={styles.content}>
                {children}
            </CollapsiblePrimitive.Content>
        </CollapsiblePrimitive.Root>
    );
}

export { CollapsibleGroup };
export type { CollapsibleGroupProps };
