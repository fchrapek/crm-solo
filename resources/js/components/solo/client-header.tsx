import { Icon } from '@/components/ui/icon';
import { type InertiaLinkProps, Link } from '@inertiajs/react';
import { type ButtonHTMLAttributes, type CSSProperties, type MouseEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu, ActionMenuItem, ActionMenuLink } from './action-menu';
import { Chip } from './chip';
import styles from './client-header.module.css';
import { HeaderActionsScope, useHeaderPlace } from './header-place';
import { IconButton } from './icon-button';
import { soloButtonClass, soloPillClass } from './primitives';
import { RecordFrame } from './record-frame';

export { HeaderActionsScope, useInHeaderMenu, useInRecordFrame } from './header-place';

export const CLIENT_TABS = ['overview', 'tasks', 'journal', 'billing', 'details'] as const;

export type ClientTab = (typeof CLIENT_TABS)[number];

export interface ClientHeaderData {
    id: number;
    name: string;
    short_name: string;
    billing_mode: 'retainer' | 'order';
    /** False when the account has billing off: no Billing tab. */
    billing?: boolean;
    /** Overview's Hours tile has its Retainer button; the helper offers the window only then. */
    retainer_terms?: boolean;
    is_archived: boolean;
    is_pinned: boolean;
    open_tasks_count: number;
    live_agent_minutes: number | null;
}

export const CLIENT_TAB_LABEL: Record<ClientTab, string> = {
    overview: 'Overview',
    tasks: 'Tasks',
    journal: 'Journal',
    billing: 'Billing',
    details: 'Data',
};

export interface PhoneNavBarProps {
    back?: { href: string; label: string; onClick?: (event: MouseEvent) => void };
    /** The agent chip (centre) and the ••• menu (end). */
    children?: ReactNode;
}

/** Figma: Mobile / Nav bar, sticky under the safe area; hidden from 768 px with the record header. */
export function PhoneNavBar({ back, children }: PhoneNavBarProps) {
    return (
        <div className={styles.navBar}>
            {back !== undefined && (
                <Link href={back.href} className={styles.back} onClick={back.onClick}>
                    <Icon name="chevron-left" size={24} className={styles.backIcon} aria-hidden="true" />
                    <span className={styles.backLabel} title={back.label}>
                        {back.label}
                    </span>
                </Link>
            )}
            {children}
        </div>
    );
}

export interface RecordHeaderProps {
    size: 'poster' | 'compact';
    /** Phones: none on a top-level page (Settings). */
    back?: PhoneNavBarProps['back'];
    title: string;
    /** Phones: another title, such as the open Settings tab under the SETTINGS poster. */
    phoneTitle?: string;
    /** The full name when the title is a short form. */
    titleAttr?: string;
    /** Phones: the agent chip in the nav bar. */
    chip?: ReactNode;
    /** Phones: the actions of the ••• menu, as they render on desktop (they turn into menu items). */
    menu?: ReactNode;
    /** From 768 px: the live agent line at the end of the trail row. */
    live?: ReactNode;
    /** Under the title, before the row the record adds (tabs, meta). */
    afterTitle?: ReactNode;
    children?: ReactNode;
}

/** The record header both the Client and the Task views use: nav bar below 768 px and the title heading. Where the record is, the page says through its place. */
export function RecordHeader({ size, back, title, phoneTitle, titleAttr, chip, menu, live, afterTitle, children }: RecordHeaderProps) {
    const { t } = useTranslation();

    return (
        <header className={styles.header} data-size={size}>
            <PhoneNavBar back={back}>
                {chip}
                {menu !== undefined && (
                    <span className={styles.menu}>
                        <ActionMenu trigger={<IconButton icon="more" label={t('More actions')} />}>
                            <HeaderActionsScope place="menu">{menu}</HeaderActionsScope>
                        </ActionMenu>
                    </span>
                )}
            </PhoneNavBar>
            <div className={styles.trailRow}>{live !== undefined && <span className={styles.live}>{live}</span>}</div>
            <div className={styles.titleBox}>
                <h1 className={styles.name} title={titleAttr} style={{ '--record-title-chars': title.length } as CSSProperties}>
                    {phoneTitle === undefined ? (
                        title
                    ) : (
                        <>
                            <span className={styles.wideTitle}>{title}</span>
                            <span className={styles.phoneTitle}>{phoneTitle}</span>
                        </>
                    )}
                </h1>
            </div>
            {afterTitle}
            {children}
        </header>
    );
}

/** Figma: the agent chip in the phone nav bar ("claude · 14 min"). */
export function AgentChip({ children }: { children: ReactNode }) {
    return (
        <span className={styles.agentChip}>
            <span className={styles.agentMark} aria-hidden="true" />
            {children}
        </span>
    );
}

/** Figma: the client record's head: the working agent's chip, the short name as the poster, the tab's actions. The tabs are the page's pills. */
export function ClientHeader({
    client,
    title,
    actions,
    main,
    phoneMenu,
}: {
    client: ClientHeaderData;
    /** A sub-view's own name as the poster, such as Client relationship; the client's short name otherwise. */
    title?: string;
    actions?: ReactNode;
    main?: ReactNode;
    phoneMenu?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <RecordFrame
            title={title ?? client.short_name}
            titleAttr={title ?? client.name}
            titleSize="record"
            chip={
                client.live_agent_minutes !== null ? (
                    <Chip status="agent">{t('{{agent}} working · {{minutes}} min', { agent: 'claude', minutes: client.live_agent_minutes })}</Chip>
                ) : undefined
            }
            note={client.is_archived ? t('Archived client') : undefined}
            actions={actions}
            main={main}
            phoneMenu={phoneMenu}
        />
    );
}

type HeaderActionProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    /** The trailing icon when the action shows in the phone ••• menu. */
    icon?: ReactNode;
    /** In the record frame: the one action the tab is for. */
    primary?: boolean;
};

const frameButtonClass = (primary: boolean | undefined) => soloButtonClass({ variant: primary ? 'primary' : 'secondary', size: 'control' });

export function HeaderAction({ className, icon, primary, ...rest }: HeaderActionProps) {
    const place = useHeaderPlace();
    if (place === 'menu') {
        return <HeaderMenuItem icon={icon} {...rest} />;
    }
    if (place === 'frame') {
        return <button type="button" className={[frameButtonClass(primary), className].filter(Boolean).join(' ')} {...rest} />;
    }

    return <button type="button" className={[soloPillClass, className].filter(Boolean).join(' ')} {...rest} />;
}

/** Figma: Brief chip, a command in the header actions. */
export function HeaderChip({ className, icon, primary, ...rest }: HeaderActionProps) {
    const place = useHeaderPlace();
    if (place === 'menu') {
        return <HeaderMenuItem icon={icon} {...rest} />;
    }
    if (place === 'frame') {
        return <button type="button" className={[frameButtonClass(primary), className].filter(Boolean).join(' ')} {...rest} />;
    }

    return <button type="button" className={[soloPillClass, styles.chip, className].filter(Boolean).join(' ')} {...rest} />;
}

export function HeaderActionLink({ className, icon, primary, children, ...rest }: InertiaLinkProps & { icon?: ReactNode; primary?: boolean }) {
    const place = useHeaderPlace();
    if (place === 'menu') {
        return (
            <ActionMenuLink icon={icon} {...rest}>
                {children}
            </ActionMenuLink>
        );
    }
    if (place === 'frame') {
        return (
            <Link className={[frameButtonClass(primary), className].filter(Boolean).join(' ')} {...rest}>
                {children}
            </Link>
        );
    }

    return (
        <Link className={[soloPillClass, className].filter(Boolean).join(' ')} {...rest}>
            {children}
        </Link>
    );
}

function HeaderMenuItem({ icon, children, onClick, disabled, title }: Omit<HeaderActionProps, 'className' | 'primary'>) {
    return (
        <ActionMenuItem icon={icon} disabled={disabled} title={title} onSelect={() => onClick?.({} as MouseEvent<HTMLButtonElement>)}>
            {children}
        </ActionMenuItem>
    );
}
