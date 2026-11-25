import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { type HTMLAttributes, type ReactNode, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Fold } from './fold';
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
    hint,
    as: Label = 'h2',
}: {
    label: ReactNode;
    count?: ReactNode;
    end?: ReactNode;
    /** What the tile's figures follow, behind an (i) after the label and count named "{label}: explanation". */
    hint?: { label: string; text: ReactNode };
    as?: 'h2' | 'h3' | 'span';
}) {
    return (
        <div className={styles.header}>
            <span className={styles.headerStart}>
                <Label className={styles.label}>{label}</Label>
                {count !== undefined && <span className={styles.count}>{count}</span>}
                {hint !== undefined && <HeaderHint label={hint.label}>{hint.text}</HeaderHint>}
            </span>
            {end !== undefined && <span className={styles.headerEnd}>{end}</span>}
        </div>
    );
}

function HeaderHint({ label, children }: { label: string; children: ReactNode }) {
    const { t } = useTranslation();

    return <InfoHint label={t('hint_label', { label })}>{children}</InfoHint>;
}

interface GroupHeadingProps {
    label: ReactNode;
    /** The date or the count, quiet beside the label. */
    note?: ReactNode;
    /** One word beside the note for the group that is current (today); the label and its rule turn cobalt. */
    current?: ReactNode;
    end?: ReactNode;
    /** The group folds: the heading is its button, with a chevron after the note. */
    toggle?: { open: boolean; onToggle: () => void; controls: string };
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
export function GroupHeading({
    label,
    note,
    current,
    end,
    toggle,
    size = 'regular',
    quiet = false,
    flush = false,
    as: Label = 'h2',
}: GroupHeadingProps) {
    const start = (
        <>
            <Label className={styles.groupLabel}>{label}</Label>
            {note !== undefined && <span className={styles.groupNote}>{note}</span>}
            {current !== undefined && <span className={styles.groupCurrent}>{current}</span>}
        </>
    );

    return (
        <div
            className={styles.group}
            data-size={size}
            data-quiet={quiet ? '' : undefined}
            data-current={current !== undefined ? '' : undefined}
            data-flush={flush ? '' : undefined}
        >
            {toggle === undefined ? (
                <span className={styles.groupStart}>{start}</span>
            ) : (
                <Label className={`${styles.groupStart} ${styles.groupLabel}`}>
                    <button type="button" className={styles.groupToggle} aria-expanded={toggle.open} aria-controls={toggle.controls} onClick={toggle.onToggle}>
                        {label}
                        {note !== undefined && <span className={styles.groupNote}>{note}</span>}
                        <Icon name="chevron-down" size={16} className={styles.groupCaret} data-open={toggle.open || undefined} />
                    </button>
                </Label>
            )}
            {end !== undefined && <span className={styles.groupEnd}>{end}</span>}
        </div>
    );
}

/** A group of rows that folds under its heading, closed until asked for. */
export function FoldingGroup({ label, note, children }: Pick<GroupHeadingProps, 'label' | 'note'> & { children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const id = useId();

    return (
        <>
            <GroupHeading label={label} note={note} toggle={{ open, onToggle: () => setOpen((value) => !value), controls: id }} />
            <Fold id={id} open={open}>
                {children}
            </Fold>
        </>
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

/** A tile's buttons: side by side, sharing the width on a phone; apart sets them off from the last row, a step of their own such as closing the day. */
export function TileActions({ apart = false, className, ...rest }: HTMLAttributes<HTMLDivElement> & { apart?: boolean }) {
    return <div className={[styles.actions, className].filter(Boolean).join(' ')} data-apart={apart ? '' : undefined} {...rest} />;
}

/**
 * A rebuilt page's columns, one below 1100 px and main beside the 380 px side above, where the side (its aside) sticks
 * while a long main column scrolls; stickySide={false} keeps it in the page's flow. reflow orders TileSlots across both
 * columns on a phone; phoneOrder keeps the phone's order in the DOM and places its TileStacks by their place from 1100 px.
 */
export function TileGrid({
    reflow = false,
    phoneOrder = false,
    stickySide = true,
    className,
    ...rest
}: HTMLAttributes<HTMLDivElement> & { reflow?: boolean; phoneOrder?: boolean; stickySide?: boolean }) {
    return (
        <div
            className={[styles.grid, className].filter(Boolean).join(' ')}
            data-reflow={reflow ? '' : undefined}
            data-phone-order={phoneOrder ? '' : undefined}
            data-static-side={stickySide ? undefined : ''}
            {...rest}
        />
    );
}

/** A block of a reflowing TileGrid: its place (1 to 12) in the one column below 1100 px; an empty slot takes no room. */
export function TileSlot({ order, className, ...rest }: HTMLAttributes<HTMLDivElement> & { order: number }) {
    return <div className={[styles.slot, className].filter(Boolean).join(' ')} data-order={order} {...rest} />;
}

/**
 * One column of tiles, 12 apart on a phone and the shell's gap from 768 px. In a phoneOrder grid, place puts it from
 * 1100 px: lead tops the side column, main fills the main column, side follows lead and tail closes the main column.
 */
export function TileStack({
    as: Tag = 'div',
    place,
    className,
    ...rest
}: HTMLAttributes<HTMLElement> & { as?: 'div' | 'section' | 'aside'; place?: 'lead' | 'main' | 'side' | 'tail' }) {
    return <Tag className={[styles.stack, className].filter(Boolean).join(' ')} data-place={place} {...rest} />;
}

/** A line of secondary text inside a tile: an empty state or a note under figures. */
export function TileNote({ className, ...rest }: HTMLAttributes<HTMLParagraphElement>) {
    return <p className={[styles.note, className].filter(Boolean).join(' ')} {...rest} />;
}
