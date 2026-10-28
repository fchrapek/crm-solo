import { Link, type InertiaLinkProps } from '@inertiajs/react';
import { Icon } from '@/components/ui/icon';
import { type ReactNode, useId } from 'react';
import { soloButtonClass } from './primitives';
import styles from './waiting-row.module.css';

export interface WaitingLink {
    href: string;
    /** The desktop text action, such as Otwórz; on a phone the whole row is the link, named by its title. */
    label: string;
    external?: boolean;
}

/** Figma: Waiting row (CRM) from 768 px, Mobile / List row with the late dot below it. The agent question variant waits for `crm ask`. */
export function WaitingRow({
    title,
    titleHref,
    action,
    phoneAction,
    link,
}: {
    title: ReactNode;
    /** The title as a link beside the row's own actions, its hit area the title column. */
    titleHref?: InertiaLinkProps['href'];
    action?: ReactNode;
    phoneAction?: ReactNode;
    link?: WaitingLink;
}) {
    const titleId = useId();

    return (
        <li className={styles.row}>
            <span className={styles.source} aria-hidden="true" />
            <span className={styles.title} id={titleId} data-linked={titleHref !== undefined || undefined}>
                {titleHref !== undefined ? (
                    <Link href={titleHref} className={styles.titleLink}>
                        {title}
                    </Link>
                ) : (
                    title
                )}
            </span>
            {action !== undefined && (phoneAction !== undefined ? <span className={styles.desktopOnly}>{action}</span> : action)}
            {phoneAction !== undefined && <span className={styles.phoneOnly}>{phoneAction}</span>}
            {link !== undefined && (
                <>
                    <RowLink link={link} className={[soloButtonClass({ variant: 'text', size: 'sm' }), styles.desktopOnly].join(' ')}>
                        {link.label}
                    </RowLink>
                    <RowLink link={link} className={styles.phoneLink} aria-labelledby={titleId}>
                        <Icon name="chevron-right" size={16} />
                    </RowLink>
                </>
            )}
        </li>
    );
}

function RowLink({ link, className, children, ...rest }: { link: WaitingLink; className: string; children: ReactNode; 'aria-labelledby'?: string }) {
    if (link.external) {
        return (
            <a className={className} href={link.href} target="_blank" rel="noopener noreferrer" {...rest}>
                {children}
            </a>
        );
    }

    return (
        <Link className={className} href={link.href} {...rest}>
            {children}
        </Link>
    );
}
