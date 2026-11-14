import type { HTMLAttributes, ReactNode } from 'react';
import styles from './tile.module.css';

interface TileProps extends HTMLAttributes<HTMLElement> {
    /** list holds rows and a little else; plain holds any content, a measured figure too; timer is the running timer and nothing else. No tile is outlined. */
    kind?: 'list' | 'plain' | 'timer';
    as?: 'section' | 'div' | 'aside';
}

/** Figma: Tile. The one surface content sits on: white, radius 20; rows bring their own 12 px inset, so a list tile pads less. */
export function Tile({ kind = 'list', as: Tag = 'section', className, ...rest }: TileProps) {
    return <Tag className={[styles.tile, styles[kind], className].filter(Boolean).join(' ')} {...rest} />;
}

/** Figma: TileHeader. The caps label that names a whole tile, with an optional count and one thing at the end. A group of rows inside a tile takes GroupHeading. */
export function TileHeader({
    label,
    count,
    end,
    as: Label = 'h2',
}: {
    label: ReactNode;
    count?: ReactNode;
    end?: ReactNode;
    as?: 'h2' | 'h3' | 'span';
}) {
    return (
        <div className={styles.header}>
            <span className={styles.headerStart}>
                <Label className={styles.label}>{label}</Label>
                {count !== undefined && <span className={styles.count}>{count}</span>}
            </span>
            {end !== undefined && <span className={styles.headerEnd}>{end}</span>}
        </div>
    );
}

interface GroupHeadingProps {
    label: ReactNode;
    /** The date or the count, quiet beside the label. */
    note?: ReactNode;
    /** One word beside the note for the group that is current (today); the label and its rule turn cobalt. */
    current?: ReactNode;
    end?: ReactNode;
    /** compact is one step down, for a menu or a board column, where the rows are smaller too. */
    size?: 'regular' | 'compact';
    /** A group that is over (a past day) reads a step quieter. */
    quiet?: boolean;
    /** The container brings its own inset (a menu, a board column). */
    flush?: boolean;
    /** h3 under a tile that has its own name. */
    as?: 'h2' | 'h3' | 'p';
}

/**
 * The heading of a group of rows (a weekday, a lane, a search group): a label smaller and lighter than a row title
 * with the row rule under it, so the rows carry the weight and space parts the groups.
 */
export function GroupHeading({ label, note, current, end, size = 'regular', quiet = false, flush = false, as: Label = 'h2' }: GroupHeadingProps) {
    return (
        <div
            className={styles.group}
            data-size={size}
            data-quiet={quiet ? '' : undefined}
            data-current={current !== undefined ? '' : undefined}
            data-flush={flush ? '' : undefined}
        >
            <span className={styles.groupStart}>
                <Label className={styles.groupLabel}>{label}</Label>
                {note !== undefined && <span className={styles.groupNote}>{note}</span>}
                {current !== undefined && <span className={styles.groupCurrent}>{current}</span>}
            </span>
            {end !== undefined && <span className={styles.groupEnd}>{end}</span>}
        </div>
    );
}

/** The rows of a list tile. */
export function TileRows({ className, ...rest }: HTMLAttributes<HTMLUListElement>) {
    return <ul className={[styles.rows, className].filter(Boolean).join(' ')} {...rest} />;
}

/** Anything in a list tile that is not a row sits 12 px in, like the rows' own text. */
export function TileInset({ className, ...rest }: HTMLAttributes<HTMLDivElement>) {
    return <div className={[styles.inset, className].filter(Boolean).join(' ')} {...rest} />;
}

/** A tile's buttons: side by side, sharing the width on a phone. */
export function TileActions({ className, ...rest }: HTMLAttributes<HTMLDivElement>) {
    return <div className={[styles.actions, className].filter(Boolean).join(' ')} {...rest} />;
}

/** A rebuilt page's columns: one on a phone, main and the 380 px side from 1100 px. Main blocks come first in the DOM. */
export function TileGrid({ className, ...rest }: HTMLAttributes<HTMLDivElement>) {
    return <div className={[styles.grid, className].filter(Boolean).join(' ')} {...rest} />;
}

/** One column of tiles, 12 apart on a phone and the shell's gap from 768 px. */
export function TileStack({ as: Tag = 'div', className, ...rest }: HTMLAttributes<HTMLElement> & { as?: 'div' | 'section' | 'aside' }) {
    return <Tag className={[styles.stack, className].filter(Boolean).join(' ')} {...rest} />;
}

/** A line of secondary text inside a tile: an empty state or a note under figures. */
export function TileNote({ className, ...rest }: HTMLAttributes<HTMLParagraphElement>) {
    return <p className={[styles.note, className].filter(Boolean).join(' ')} {...rest} />;
}
