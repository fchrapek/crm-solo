import { Icon } from '@/components/ui/icon';
import { fullText } from '@/lib/plain-text';
import { Link, type InertiaLinkProps } from '@inertiajs/react';
import { type CSSProperties, Fragment, type ReactNode, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Elapsed } from './elapsed';
import { Fold } from './fold';
import { useLinkPrefetch } from './link-prefetch';
import styles from './row.module.css';

export interface RowCheck {
    done: boolean;
    /** Open and being worked on: the half-filled mark. */
    progress?: boolean;
    /** What a click does now; omitted when the tick cannot change (a card completed on its board). */
    onToggle?: () => void;
    label: string;
}

/** One label over value pair of a row's details, in the fixed column its place in the list gives it; a value that does not exist leaves its column empty. */
export interface RowDetail {
    label: string;
    value: ReactNode;
    /** The value takes the whole line, such as a note's full text. */
    wide?: boolean;
    /** A short value such as a date or a duration: a narrow fixed column rather than a share of the width. */
    short?: boolean;
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
    /** quiet is a logged time beside a running one's place, in the secondary ink. */
    timeTone?: 'neutral' | 'over' | 'quiet';
    /** ISO start of the timer running on the row: the dot and the live time in place of `time`, at all times. */
    running?: string | null;
    /** The figure is one a phone has no room for, such as a due date. */
    timeWide?: boolean;
    /** The end meta, on every width. */
    meta?: ReactNode;
    /** End meta a phone has no room for. */
    wideMeta?: ReactNode;
    /** A line under the title on a phone only, wrapping rather than cut, such as client · project for a row whose end meta shows from 768 px. */
    context?: ReactNode;
    chip?: ReactNode;
    /** One button; two only with `stack`, the second a quiet one. */
    action?: ReactNode;
    /** The title wraps from 768 px too, for a row in the narrow side column. */
    wrap?: boolean;
    /** On a phone the chip and the buttons take their own line under the title, for a row that carries two buttons; always keeps them there on every width, for a row in the narrow side column. */
    stack?: boolean | 'always';
    /** The "more" button with its menu, for a row's secondary actions. */
    more?: ReactNode;
    /** The keyboard or current row. */
    selected?: boolean;
    /** No line above: the first row under a tile's top. */
    rule?: boolean;
    as?: 'li' | 'div';
    className?: string;
    /** Folded under the row and opened by a chevron before the action. */
    details?: RowDetail[];
    detailsOpen?: boolean;
}

const shown = (value: ReactNode) => value !== undefined && value !== null && value !== false && value !== '';

/** The details of a list's rows without the columns no row of the list has a value for, so the rest line up with no dead track. */
export function listDetails(rows: RowDetail[][]): RowDetail[][] {
    const used = new Set(rows.flatMap((details) => details.filter((detail) => shown(detail.value)).map((detail) => detail.label)));

    return rows.map((details) => details.filter((detail) => used.has(detail.label)));
}

/**
 * Figma: Row. Title plus end meta; a phone may add the context line under the title (`context`). The kinds are what a row is given: meta only, an action,
 * a checkbox, a chip, a checkbox with an action, a "more" menu. On a phone the title wraps and the row grows.
 * Figma: Shared · Row details (toggle). A row that packs detail stays one line and folds its label over value pairs under it, opened by a chevron before the action.
 */
export function Row({
    title,
    href,
    visit,
    check,
    time,
    timeTone = 'neutral',
    running,
    timeWide = false,
    meta,
    wideMeta,
    context,
    chip,
    action,
    stack = false,
    wrap = false,
    more,
    selected = false,
    rule = true,
    as: Tag = 'li',
    className,
    details,
    detailsOpen = false,
}: RowProps) {
    const { t } = useTranslation();
    const prefetch = useLinkPrefetch();
    const id = useId();
    const [open, setOpen] = useState(detailsOpen);
    const facts = details?.filter((detail) => shown(detail.value)) ?? [];
    const columns = details?.filter((detail) => !detail.wide) ?? [];
    const folding = facts.length > 0;
    const cut = details !== undefined && href !== undefined;
    const name = fullText(title);
    const titleText = (
        <span className={styles.title} title={name}>
            {href === undefined ? (
                title
            ) : (
                <Link href={href} prefetch={visit === undefined && prefetch(href)} className={styles.link} data-cut={cut || undefined} {...visit}>
                    {cut ? <span className={styles.titleText}>{title}</span> : title}
                </Link>
            )}
        </span>
    );
    const toggle = folding && (
        <button
            type="button"
            className={styles.toggle}
            aria-expanded={open}
            aria-controls={`${id}-details`}
            aria-label={`${open ? t('Hide details') : t('Show details')}${name ? `: ${name}` : ''}`}
            onClick={() => setOpen((value) => !value)}
        >
            <Icon name="chevron-down" size={16} className={styles.caret} data-open={open || undefined} />
        </button>
    );
    const Head = folding ? 'div' : Tag;
    const head = (
        <Head
            className={[styles.row, rule && !folding ? styles.ruled : '', check?.done ? styles.done : '', folding ? '' : className].filter(Boolean).join(' ')}
            data-selected={selected ? '' : undefined}
            data-stack={stack === 'always' ? 'always' : stack ? 'phone' : undefined}
            data-wrap={wrap ? '' : undefined}
            aria-current={selected ? 'true' : undefined}
        >
            {check && (
                <button
                    type="button"
                    className={styles.check}
                    aria-label={check.label}
                    aria-pressed={check.done}
                    data-progress={!check.done && check.progress ? '' : undefined}
                    disabled={check.onToggle === undefined}
                    onClick={check.onToggle}
                >
                    <Icon name={check.done ? 'done' : check.progress ? 'circle-half' : 'circle'} size={20} filled={check.done} />
                </button>
            )}
            {context === undefined || context === null ? (
                titleText
            ) : (
                <span className={styles.lead}>
                    {titleText}
                    <span className={styles.context}>{context}</span>
                </span>
            )}
            {typeof running === 'string' && running !== '' ? (
                <span className={styles.time} data-tone="over" data-running="">
                    <span className={styles.dot} aria-hidden="true" />
                    <Elapsed startedAt={running} />
                </span>
            ) : (
                time !== undefined &&
                time !== null && (
                    <span className={timeWide ? `${styles.time} ${styles.wide}` : styles.time} data-tone={timeTone} title={fullText(time)}>
                        {time}
                    </span>
                )
            )}
            {meta !== undefined && meta !== null && (
                <span className={styles.meta} title={fullText(meta)}>
                    {meta}
                </span>
            )}
            {wideMeta !== undefined && wideMeta !== null && (
                <span className={`${styles.meta} ${styles.wide}`} title={fullText(wideMeta)}>
                    {wideMeta}
                </span>
            )}
            {stack ? (
                <span className={styles.end}>
                    {chip}
                    {toggle}
                    {action}
                </span>
            ) : (
                <>
                    {chip}
                    {toggle}
                    {action}
                </>
            )}
            {more !== undefined && more !== null && <span className={styles.more}>{more}</span>}
        </Head>
    );

    if (!folding) {
        return head;
    }

    return (
        <Tag className={[styles.item, rule ? styles.ruled : '', className].filter(Boolean).join(' ')} data-open={open || undefined}>
            {head}
            <Fold id={`${id}-details`} open={open}>
                <dl
                    className={styles.details}
                    data-checked={check ? '' : undefined}
                    style={{ '--detail-columns': columns.map((detail) => (detail.short ? 'var(--role-detail-short)' : 'var(--role-detail-text)')).join(' ') } as CSSProperties}
                >
                    {facts.map((detail) => (
                        <div
                            key={detail.label}
                            className={styles.detail}
                            data-wide={detail.wide || undefined}
                            style={detail.wide ? undefined : ({ '--detail-column': columns.indexOf(detail) + 1 } as CSSProperties)}
                        >
                            <dt>{detail.label}</dt>
                            <dd title={detail.wide ? undefined : fullText(detail.value)}>{detail.value}</dd>
                        </div>
                    ))}
                </dl>
            </Fold>
        </Tag>
    );
}

/** End meta of several names, such as client and project: each keeps its share of a tight row and cuts itself, so a long first name never hides the next. */
export function rowParts(parts: (string | null | undefined)[]): ReactNode {
    const shown = parts.filter((part): part is string => typeof part === 'string' && part !== '');

    if (shown.length < 2) {
        return shown[0];
    }

    return (
        <span className={styles.parts}>
            {shown.map((part, index) => (
                <Fragment key={index}>
                    {index > 0 && <span className={styles.sep}> · </span>}
                    <span className={styles.part}>{part}</span>
                </Fragment>
            ))}
        </span>
    );
}
