import { Icon } from '@/components/ui/icon';
import clients from '@/routes/clients';
import { type InertiaLinkProps, Link } from '@inertiajs/react';
import {
    type ButtonHTMLAttributes,
    createContext,
    type CSSProperties,
    Fragment,
    type MouseEvent,
    type ReactNode,
    useContext,
    useEffect,
    useRef,
} from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu, ActionMenuItem, ActionMenuLink } from './action-menu';
import styles from './client-header.module.css';
import { IconButton } from './icon-button';
import { LiveLine } from './live-line';
import { soloPillClass } from './primitives';

export const CLIENT_TABS = ['przeglad', 'zadania', 'dziennik', 'rozliczenia', 'dane'] as const;

export type ClientTab = (typeof CLIENT_TABS)[number];

export interface ClientHeaderData {
    id: number;
    name: string;
    short_name: string;
    billing_mode: 'retainer' | 'order';
    /** False when the account has billing off: no Rozliczenia tab. */
    billing?: boolean;
    is_archived: boolean;
    is_pinned: boolean;
    open_tasks_count: number;
    live_agent_minutes: number | null;
}

const TAB_LABEL: Record<ClientTab, string> = {
    przeglad: 'Overview',
    zadania: 'Tasks',
    dziennik: 'Journal',
    rozliczenia: 'Billing',
    dane: 'Data',
};

export function clientHeaderSize(tab: ClientTab): 'poster' | 'compact' {
    return tab === 'przeglad' || tab === 'zadania' ? 'poster' : 'compact';
}

const InHeaderMenu = createContext(false);

/** True inside the phone ••• menu, where a header action renders as a menu item. */
export function useInHeaderMenu(): boolean {
    return useContext(InHeaderMenu);
}

/** The active tab scrolls into the strip when it overflows; the strip's scroll padding keeps it off the edge. */
function useActiveTabInView(tab: ClientTab) {
    const strip = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const { scrollX, scrollY } = window;
        strip.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        // scrollIntoView also moves the page when the strip is off screen; only the strip may move.
        window.scrollTo(scrollX, scrollY);
    }, [tab]);

    return strip;
}

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
                    <span className={styles.backLabel}>{back.label}</span>
                </Link>
            )}
            {children}
        </div>
    );
}

export interface TrailItem {
    label: string;
    /** A crumb that links; the item without one is the current page. */
    href?: string;
    /** A long current item (a task title) ends with an ellipsis at a fixed width. */
    clip?: boolean;
}

interface RecordHeaderProps {
    size: 'poster' | 'compact';
    /** Phones: none on a top-level page (Ustawienia). */
    back?: PhoneNavBarProps['back'];
    trail: TrailItem[];
    title: string;
    /** Phones: another title, such as the open Ustawienia tab under the USTAWIENIA poster. */
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

/** The record header both the Klient and the Zadanie views use: nav bar below 768 px, trail and the title heading. */
export function RecordHeader({ size, back, trail, title, phoneTitle, titleAttr, chip, menu, live, afterTitle, children }: RecordHeaderProps) {
    const { t } = useTranslation();

    return (
        <header className={styles.header} data-size={size}>
            <PhoneNavBar back={back}>
                {chip}
                {menu !== undefined && (
                    <span className={styles.menu}>
                        <ActionMenu trigger={<IconButton icon="more" label={t('More actions')} />}>
                            <InHeaderMenu.Provider value={true}>{menu}</InHeaderMenu.Provider>
                        </ActionMenu>
                    </span>
                )}
            </PhoneNavBar>
            <div className={styles.trailRow}>
                <nav className={styles.trail} aria-label={t('Breadcrumb')}>
                    {trail.map((item, index) => (
                        <Fragment key={`${index}-${item.label}`}>
                            {index > 0 && (
                                <span className={styles.crumb} aria-hidden="true">
                                    →
                                </span>
                            )}
                            {item.href !== undefined ? (
                                <Link href={item.href} className={styles.crumb}>
                                    {item.label}
                                </Link>
                            ) : (
                                <span
                                    className={styles.current}
                                    aria-current="page"
                                    data-clip={item.clip || undefined}
                                    title={item.clip ? item.label : undefined}
                                >
                                    {item.label}
                                </span>
                            )}
                        </Fragment>
                    ))}
                </nav>
                {live !== undefined && <span className={styles.live}>{live}</span>}
            </div>
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

/** Figma: Client header (Poster, Compact) from 768 px; Mobile / Nav bar and Record header below it. */
export function ClientHeader({ client, tab, actions }: { client: ClientHeaderData; tab: ClientTab; actions?: ReactNode }) {
    const { t } = useTranslation();
    const strip = useActiveTabInView(tab);
    const agentLabel =
        client.live_agent_minutes !== null ? t('{{agent}} · {{minutes}} min', { agent: 'claude', minutes: client.live_agent_minutes }) : null;

    return (
        <RecordHeader
            size={clientHeaderSize(tab)}
            back={{ href: clients.index().url, label: t('Clients') }}
            trail={[{ label: t('Clients'), href: clients.index().url }, { label: client.short_name }]}
            title={client.short_name}
            titleAttr={client.name}
            chip={agentLabel !== null ? <AgentChip>{agentLabel}</AgentChip> : undefined}
            menu={actions}
            live={
                client.live_agent_minutes !== null ? (
                    <LiveLine>{t('{{agent}} working · {{minutes}} min', { agent: 'claude', minutes: client.live_agent_minutes })}</LiveLine>
                ) : undefined
            }
            afterTitle={client.is_archived ? <p className={styles.archivedPhone}>{t('Archived client')}</p> : undefined}
        >
            <div className={styles.tabsRow} ref={strip}>
                <nav className={styles.tabs} aria-label={t('Client record')}>
                    {CLIENT_TABS.filter((key) => key !== 'rozliczenia' || client.billing !== false).map((key) => (
                        <Link
                            key={key}
                            href={clients.show({ client: client.id, tab: key })}
                            className={styles.tab}
                            aria-current={key === tab ? 'page' : undefined}
                            preserveScroll
                        >
                            {t(TAB_LABEL[key])}
                            {key === 'zadania' && client.open_tasks_count > 0 && (
                                <span className={styles.count}>
                                    <span className={styles.countSep}> · </span>
                                    {client.open_tasks_count}
                                </span>
                            )}
                        </Link>
                    ))}
                </nav>
                <div className={styles.actions}>
                    {client.is_archived && <span className={styles.archived}>{t('Archived client')}</span>}
                    {actions}
                </div>
            </div>
        </RecordHeader>
    );
}

type HeaderActionProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    /** The trailing icon when the action shows in the phone ••• menu. */
    icon?: ReactNode;
};

export function HeaderAction({ className, icon, ...rest }: HeaderActionProps) {
    if (useInHeaderMenu()) {
        return <HeaderMenuItem icon={icon} {...rest} />;
    }

    return <button type="button" className={[soloPillClass, className].filter(Boolean).join(' ')} {...rest} />;
}

/** Figma: Brief chip, a mono command in the header actions. */
export function HeaderChip({ className, icon, ...rest }: HeaderActionProps) {
    if (useInHeaderMenu()) {
        return <HeaderMenuItem icon={icon} {...rest} />;
    }

    return <button type="button" className={[soloPillClass, styles.chip, className].filter(Boolean).join(' ')} {...rest} />;
}

export function HeaderActionLink({ className, icon, children, ...rest }: InertiaLinkProps & { icon?: ReactNode }) {
    if (useInHeaderMenu()) {
        return (
            <ActionMenuLink icon={icon} {...rest}>
                {children}
            </ActionMenuLink>
        );
    }

    return (
        <Link className={[soloPillClass, className].filter(Boolean).join(' ')} {...rest}>
            {children}
        </Link>
    );
}

function HeaderMenuItem({ icon, children, onClick, disabled, title }: Omit<HeaderActionProps, 'className'>) {
    return (
        <ActionMenuItem icon={icon} disabled={disabled} title={title} onSelect={() => onClick?.({} as MouseEvent<HTMLButtonElement>)}>
            {children}
        </ActionMenuItem>
    );
}
