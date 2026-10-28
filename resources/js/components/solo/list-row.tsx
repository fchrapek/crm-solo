import { Link, type InertiaLinkProps } from '@inertiajs/react';
import { Icon } from '@/components/ui/icon';
import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { CheckIcon } from './mobile-icons';
import styles from './mobile.module.css';

export interface ListRowMarks {
    pinned?: boolean;
    isNew?: boolean;
    /** The open month mark, such as WRZ. */
    openMonth?: string;
    /** The running agent's label. */
    agent?: string;
}

interface ListRowContent {
    title: ReactNode;
    sub?: ReactNode;
    /** Default is a record row (16 px), large a Lista client row (17 px). */
    size?: 'default' | 'large';
    leading?: ReactNode;
    value?: ReactNode;
    /** chevron by default on a link or button row; any node replaces it, null drops it. */
    trailing?: ReactNode;
    marks?: ListRowMarks;
}

interface TitleLink {
    /** The title as a link whose hit area is the whole text column; the leading and trailing controls stay their own. */
    titleHref?: InertiaLinkProps['href'];
}

type ListRowProps = ListRowContent &
    (
        | ({ href: InertiaLinkProps['href']; external?: false } & Omit<InertiaLinkProps, 'title' | 'href' | 'size' | 'value'>)
        | ({ href: string; external: true } & Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'title' | 'href'>)
        | ({ href?: undefined; external?: undefined } & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'title' | 'value'>)
    );

/** Figma: Mobile / List row. A link with href (external opens a new tab outside Inertia), otherwise a button. */
export function ListRow({ title, sub, size = 'default', leading, value, trailing, marks, className, external, ...rest }: ListRowProps) {
    const body = (
        <ListRowBody
            title={title}
            sub={sub}
            leading={leading}
            value={value}
            trailing={trailing === undefined ? <Icon name="chevron-right" size={16} /> : trailing}
            marks={marks}
        />
    );
    const classes = [styles.listRow, className].filter(Boolean).join(' ');

    if (external) {
        return (
            <a className={classes} data-size={size} target="_blank" rel="noopener noreferrer" {...(rest as AnchorHTMLAttributes<HTMLAnchorElement>)}>
                {body}
            </a>
        );
    }

    if (rest.href !== undefined) {
        return (
            <Link className={classes} data-size={size} {...(rest as InertiaLinkProps)}>
                {body}
            </Link>
        );
    }

    return (
        <button type="button" className={classes} data-size={size} {...(rest as ButtonHTMLAttributes<HTMLButtonElement>)}>
            {body}
        </button>
    );
}

/** For a row whose controls live in its trailing slot. */
export function ListRowStatic({ className, size = 'default', ...content }: ListRowContent & TitleLink & { className?: string }) {
    return (
        <div className={[styles.listRow, className].filter(Boolean).join(' ')} data-size={size}>
            <ListRowBody {...content} trailing={content.trailing ?? null} />
        </div>
    );
}

function ListRowBody({ title, sub, leading, value, trailing, marks = {}, titleHref }: ListRowContent & TitleLink) {
    const { t } = useTranslation();
    const hasSub = sub !== undefined || marks.openMonth !== undefined || marks.agent !== undefined;

    return (
        <>
            {leading !== undefined && <span className={styles.listLeading}>{leading}</span>}
            <span className={styles.listText} data-linked={titleHref !== undefined || undefined}>
                <span className={styles.listTitleRow}>
                    <span className={styles.listTitle}>
                        {titleHref !== undefined ? (
                            <Link href={titleHref} className={styles.listTitleLink}>
                                {title}
                            </Link>
                        ) : (
                            title
                        )}
                    </span>
                    {marks.pinned && <Icon name="pin" size={16} filled className={styles.listPin} aria-label={t('Pinned')} role="img" />}
                    {marks.isNew && <span className={styles.listMark}>{t('New since last visit')}</span>}
                </span>
                {hasSub && (
                    <span className={styles.listSub}>
                        {sub !== undefined && <span className={styles.listSubText}>{sub}</span>}
                        {marks.openMonth !== undefined && <span className={styles.listMark}>{marks.openMonth}</span>}
                        {marks.agent !== undefined && (
                            <span className={styles.listAgent}>
                                <span className={styles.listAgentMark} aria-hidden="true" />
                                <span className={styles.listAgentText}>{marks.agent}</span>
                            </span>
                        )}
                    </span>
                )}
            </span>
            {(value !== undefined || trailing !== null) && (
                <span className={styles.listTrailing}>
                    {value !== undefined && <span className={styles.listValue}>{value}</span>}
                    {trailing}
                </span>
            )}
        </>
    );
}

/** Figma: Mobile / Icon / Checkbox, a 20 px box in its 44 px target, for a row's leading slot. */
export function ListCheck({ checked, className, ...rest }: { checked: boolean } & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'>) {
    return (
        <button
            type="button"
            aria-pressed={checked}
            data-checked={checked || undefined}
            className={[styles.check, className].filter(Boolean).join(' ')}
            {...rest}
        >
            <span className={styles.checkBox}>{checked && <CheckIcon width={12} height={9} />}</span>
        </button>
    );
}
