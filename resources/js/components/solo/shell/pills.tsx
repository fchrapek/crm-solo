import { Icon } from '@/components/ui/icon';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ActionMenu, ActionMenuLink } from '../action-menu';
import type { PlaceLinkPill, PlaceMenuPill, PlacePill } from './place';
import styles from './pills.module.css';

/** Figma: Pill. Count is optional; Selected is the current place. */
export function Pill({ label, href, active = false, count, note, done, visit }: PlaceLinkPill) {
    return (
        <Link href={href} className={styles.pill} aria-current={active ? 'page' : undefined} {...visit}>
            {label}
            {count !== undefined && <span className={styles.count}>{count}</span>}
            {note !== undefined && <span className={styles.count}>{note}</span>}
            {done !== undefined && <Icon name="check" size={16} aria-label={done} />}
        </Link>
    );
}

/** A pill that opens its choices as a menu; each choice is a link, the current one carries the check. */
export function MenuPill({ label, active = false, menu }: PlaceMenuPill) {
    return (
        <ActionMenu
            align="start"
            trigger={
                <button type="button" className={styles.pill} data-active={active ? '' : undefined}>
                    {label}
                </button>
            }
        >
            {menu.map((choice) => (
                <ActionMenuLink
                    key={choice.label}
                    href={choice.href}
                    {...choice.visit}
                    aria-current={choice.active ? 'true' : undefined}
                    icon={choice.active ? <Icon name="check" size={16} /> : undefined}
                >
                    {choice.count === undefined ? choice.label : `${choice.label} · ${choice.count}`}
                </ActionMenuLink>
            ))}
        </ActionMenu>
    );
}

/** Figma: Pills. The siblings of the last place level, under the command bar; on phones the row scrolls sideways. A page may draw a second row of its own (the months of Rozliczenia), named by `label`. */
export function Pills({ items, label, from }: { items: PlacePill[]; label?: string; from?: 'wide' }) {
    const { t } = useTranslation();
    if (items.length === 0) return null;

    return (
        <nav className={styles.pills} data-from={from} aria-label={label ?? t('Places beside this one')}>
            {items.map((item) => ('menu' in item ? <MenuPill key={item.label} {...item} /> : <Pill key={item.label} {...item} />))}
        </nav>
    );
}
