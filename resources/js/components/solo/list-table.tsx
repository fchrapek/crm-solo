import type { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import styles from './list-table.module.css';

/**
 * Figma: the list table inside a tile (TableHeaderCell, TableCell). Rows are 56 px with a 1 px line between them; a
 * phone hides the captions and every cell marked wide, so a row reads as a title and its end figures.
 */
export function ListTable({ label, className, ...rest }: { label: string } & HTMLAttributes<HTMLTableElement>) {
    return <table aria-label={label} className={[styles.table, className].filter(Boolean).join(' ')} {...rest} />;
}

interface CellLook {
    /** end: over figures. */
    align?: 'start' | 'end';
    /** From 768 px only. */
    wide?: boolean;
    /** Below 768 px only: the one cell that stands for several wide ones. */
    narrow?: boolean;
    /** From 1100 px only: a column the narrower desktop has no room for. */
    full?: boolean;
}

export function ListTableHead({ align = 'start', wide = false, narrow = false, full = false, className, ...rest }: CellLook & Omit<ThHTMLAttributes<HTMLTableCellElement>, 'align'>) {
    return <th scope="col" data-align={align} data-wide={wide ? '' : undefined} data-narrow={narrow ? '' : undefined} data-full={full ? '' : undefined} className={[styles.head, className].filter(Boolean).join(' ')} {...rest} />;
}

interface CellProps extends CellLook, Omit<TdHTMLAttributes<HTMLTableCellElement>, 'align'> {
    /** title: the row's name; text: plain; meta: secondary; number: a figure, right aligned. */
    kind?: 'title' | 'text' | 'meta' | 'number';
    /** over: a figure past its limit; muted: nothing to say. */
    tone?: 'over' | 'muted';
    /** A name that may run long: it gives up its width to the table and its content cuts itself with an ellipsis. */
    truncate?: boolean;
}

export function ListTableCell({ kind = 'text', tone, align = 'start', wide = false, narrow = false, full = false, truncate = false, className, children, ...rest }: CellProps) {
    return (
        <td
            data-kind={kind}
            data-tone={tone}
            data-align={align}
            data-wide={wide ? '' : undefined}
            data-narrow={narrow ? '' : undefined}
            data-full={full ? '' : undefined}
            className={[styles.cell, className].filter(Boolean).join(' ')}
            {...rest}
        >
            {truncate ? <div className={styles.truncate}>{children}</div> : children}
        </td>
    );
}

/** Figma: LedgerRow. A row of the table; current is the one the page is on, minor a line between rows (a correction, the start). */
export function ListTableRow({ current = false, minor = false, className, ...rest }: { current?: boolean; minor?: boolean } & HTMLAttributes<HTMLTableRowElement>) {
    return (
        <tr
            aria-current={current ? 'true' : undefined}
            data-minor={minor ? '' : undefined}
            className={[styles.row, className].filter(Boolean).join(' ')}
            {...rest}
        />
    );
}
