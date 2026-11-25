import { DropdownMenuGroup, DropdownMenuLabel } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ActionMenu, ActionMenuLink } from '../action-menu';
import { useLinkPrefetch } from '../link-prefetch';
import { menuGroupLabelClass, menuHeadClass } from '../menu';
import type { PlaceLinkPill, PlaceMenuPill, PlacePill } from './place';
import styles from './pills.module.css';

/** Figma: Pill. Count is optional; Selected is the current place. */
export function Pill({ label, href, active = false, count, note, done, visit }: PlaceLinkPill) {
    const prefetch = useLinkPrefetch();

    return (
        <Link href={href} prefetch={visit === undefined && prefetch(href)} className={styles.pill} aria-current={active ? 'page' : undefined} {...visit}>
            {label}
            {count !== undefined && <span className={styles.count}>{count}</span>}
            {note !== undefined && <span className={styles.count}>{note}</span>}
            {done !== undefined && <Icon name="check" size={16} aria-label={done} />}
        </Link>
    );
}

/** The pill look for a control that opens its own panel, such as a picker with a search. */
export const pillClass = styles.pill;

/** Choices in a row with the same group, in order, each run under its group's name. */
function groupsOf(menu: PlaceLinkPill[]): { group: string | undefined; choices: PlaceLinkPill[] }[] {
    return menu.reduce<{ group: string | undefined; choices: PlaceLinkPill[] }[]>((groups, choice) => {
        const last = groups[groups.length - 1];
        if (last !== undefined && last.group === choice.group) {
            last.choices.push(choice);
        } else {
            groups.push({ group: choice.group, choices: [choice] });
        }

        return groups;
    }, []);
}

function MenuChoice({ choice }: { choice: PlaceLinkPill }) {
    return (
        <ActionMenuLink
            href={choice.href}
            {...choice.visit}
            aria-current={choice.active ? 'true' : undefined}
            icon={choice.active ? <Icon name="check" size={16} /> : undefined}
        >
            {choice.count === undefined ? choice.label : `${choice.label} · ${choice.count}`}
        </ActionMenuLink>
    );
}

/** Figma: Pill, Kind=Menu. A pill that opens its choices as a menu, always with the chevron, under an optional heading and groups; each choice is a link, the current one carries the check. */
export function MenuPill({ label, active = false, heading, menu }: PlaceMenuPill) {
    return (
        <ActionMenu
            align="start"
            trigger={
                <button type="button" className={styles.pill} data-active={active ? '' : undefined}>
                    {label}
                    <Icon name="chevron-down" size={16} className={styles.chevron} />
                </button>
            }
        >
            {heading !== undefined && <DropdownMenuLabel className={menuHeadClass}>{heading}</DropdownMenuLabel>}
            {groupsOf(menu).map(({ group, choices }, index) =>
                group === undefined ? (
                    choices.map((choice) => <MenuChoice key={choice.label} choice={choice} />)
                ) : (
                    <DropdownMenuGroup key={`${group}-${index}`} aria-label={group}>
                        <DropdownMenuLabel className={menuGroupLabelClass} aria-hidden="true">
                            {group}
                        </DropdownMenuLabel>
                        {choices.map((choice) => (
                            <MenuChoice key={choice.label} choice={choice} />
                        ))}
                    </DropdownMenuGroup>
                ),
            )}
        </ActionMenu>
    );
}

/** Figma: Pills. The siblings of the last place level, under the command bar; on phones the row scrolls sideways. A page may draw a second row of its own (the months of Billing), named by `label`. */
export function Pills({ items, label, from }: { items: PlacePill[]; label?: string; from?: 'wide' }) {
    const { t } = useTranslation();
    if (items.length === 0) return null;

    return (
        <nav className={styles.pills} data-from={from} aria-label={label ?? t('Places beside this one')}>
            {items.map((item) => ('menu' in item ? <MenuPill key={item.label} {...item} /> : <Pill key={item.label} {...item} />))}
        </nav>
    );
}
