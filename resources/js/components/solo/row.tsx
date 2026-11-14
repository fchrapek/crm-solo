import { Icon } from '@/components/ui/icon';
import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import styles from './row.module.css';

export interface RowCheck {
    done: boolean;
    /** What a click does now; omitted when the tick cannot change (a card completed on its board). */
    onToggle?: () => void;
    label: string;
}

interface RowProps {
    title: ReactNode;
    /** Where the title leads. */
    href?: InertiaLinkProps['href'];
    /** How the title's visit goes, such as a partial reload that keeps the scroll. */
    visit?: Pick<InertiaLinkProps, 'only' | 'preserveScroll' | 'preserveState'>;
    check?: RowCheck;
    /** A figure in ink, before the meta; over turns it vermilion (running time, past the limit). */
    time?: ReactNode;
    timeTone?: 'neutral' | 'over';
    /** The figure is one a phone has no room for, such as a due date. */
    timeWide?: boolean;
    /** The end meta, on every width. */
    meta?: ReactNode;
    /** End meta a phone has no room for. */
    wideMeta?: ReactNode;
    chip?: ReactNode;
    /** One button; two only with `stack`, the second a quiet one. */
    action?: ReactNode;
    /** The title wraps from 768 px too, for a row in the narrow side column. */
    wrap?: boolean;
    /** On a phone the chip and the buttons take their own line under the title, for a row that carries two buttons. */
    stack?: boolean;
    /** The "more" button with its menu, for a row's secondary actions. */
    more?: ReactNode;
    /** The keyboard or current row. */
    selected?: boolean;
    /** No line above: the first row under a tile's top. */
    rule?: boolean;
    as?: 'li' | 'div';
    className?: string;
}

/**
 * Figma: Row. Title plus end meta, never a second grey line. The kinds are what a row is given: meta only, an action,
 * a checkbox, a chip, a checkbox with an action, a "more" menu. On a phone the title wraps and the row grows.
 */
export function Row({ title, href, visit, check, time, timeTone = 'neutral', timeWide = false, meta, wideMeta, chip, action, stack = false, wrap = false, more, selected = false, rule = true, as: Tag = 'li', className }: RowProps) {
    return (
        <Tag
            className={[styles.row, rule ? styles.ruled : '', check?.done ? styles.done : '', className].filter(Boolean).join(' ')}
            data-selected={selected ? '' : undefined}
            data-stack={stack ? '' : undefined}
            data-wrap={wrap ? '' : undefined}
            aria-current={selected ? 'true' : undefined}
        >
            {check && (
                <button
                    type="button"
                    className={styles.check}
                    aria-label={check.label}
                    aria-pressed={check.done}
                    disabled={check.onToggle === undefined}
                    onClick={check.onToggle}
                >
                    <Icon name={check.done ? 'done' : 'circle'} size={20} filled={check.done} />
                </button>
            )}
            <span className={styles.title}>
                {href === undefined ? (
                    title
                ) : (
                    <Link href={href} className={styles.link} {...visit}>
                        {title}
                    </Link>
                )}
            </span>
            {time !== undefined && time !== null && (
                <span className={timeWide ? `${styles.time} ${styles.wide}` : styles.time} data-tone={timeTone}>
                    {time}
                </span>
            )}
            {meta !== undefined && meta !== null && <span className={styles.meta}>{meta}</span>}
            {wideMeta !== undefined && wideMeta !== null && <span className={`${styles.meta} ${styles.wide}`}>{wideMeta}</span>}
            {stack ? (
                <span className={styles.end}>
                    {chip}
                    {action}
                </span>
            ) : (
                <>
                    {chip}
                    {action}
                </>
            )}
            {more}
        </Tag>
    );
}
