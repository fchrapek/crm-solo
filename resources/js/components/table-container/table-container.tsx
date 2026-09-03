import * as React from 'react';

import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';

import styles from './table-container.module.css';

function TableContainer({ className, ...props }: React.ComponentProps<'table'>) {
    return (
        <ScrollArea className={styles.scrollArea}>
            <table data-slot="table" className={cn(styles.table, className)} {...props} />
            <ScrollBar orientation="horizontal" />
            <ScrollBar orientation="vertical" />
        </ScrollArea>
    );
}

export { TableContainer };
