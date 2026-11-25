import { ActionMenuChip, ActionMenuGroup, ActionMenuItem } from '@/components/solo/action-menu';
import { BottomSheet } from '@/components/solo/bottom-sheet';
import { IconButton } from '@/components/solo/icon-button';
import calendar from '@/components/solo/month-card.module.css';
import { localToday, MonthCalendar } from '@/components/solo/month-card';
import { RowMenu } from '@/components/solo/row-menu';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { capitalize, parseDay } from '@/lib/dates';
import day from '@/routes/day';
import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './pick-menu.module.css';

const keep = { preserveScroll: true } as const;

export interface MoveDay {
    date: string;
    picks: number;
}

/** "Śr 18": the short weekday and the day of the month. */
export function dayChip(date: string, locale: string): string {
    const weekday = new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(parseDay(date)).replace('.', '');

    return `${capitalize(weekday)} ${Number(date.slice(8))}`;
}

export function dayAfter(date: string): string {
    const next = parseDay(date);
    next.setDate(next.getDate() + 1);

    return localToday(next);
}

/**
 * Figma: the row menu, version 2 (1163:69771, calendar 1260:8200, sheet 1260:8463; back 1483:12922, sheet 1483:13273). A
 * pick moves to tomorrow, to a day of this week or to any day ahead, and leaves the day it was on; or it is taken off the
 * plan. Choose a date is a second step: its back arrow, and Escape, return to the menu's items.
 */
export function PickMenu({ pickId, taskId, name, date, days, max }: { pickId: number; taskId: number; name: string; date: string; days: MoveDay[]; max: number }) {
    const { t, i18n } = useTranslation();
    const wide = useSoloWide();
    const [menuOpen, setMenuOpen] = useState(false);
    const [choosing, setChoosing] = useState(false);
    const returning = useRef(false);
    const tomorrow = dayAfter(date);
    const move = (to: string) => router.post(day.picks.store().url, { task_id: taskId, date: to }, keep);
    const choose = (to: string) => {
        setChoosing(false);
        move(to);
    };
    const back = () => {
        returning.current = true;
        setChoosing(false);
    };
    // The menu opens once the calendar has given the focus back, so the menu keeps it.
    const reopenMenu = (event: Event) => {
        if (!returning.current) return;
        returning.current = false;
        event.preventDefault();
        setMenuOpen(true);
    };
    const picker = choosing && <MonthCalendar pick={{ value: null, onPick: choose, min: tomorrow, today: date }} />;

    return (
        <Popover open={wide && choosing} onOpenChange={setChoosing}>
            <PopoverAnchor asChild>
                <span className={styles.anchor}>
                    <RowMenu name={name} open={menuOpen} onOpenChange={setMenuOpen}>
                        <ActionMenuItem onSelect={() => move(tomorrow)}>{t('Move to tomorrow')}</ActionMenuItem>
                        {days.length > 0 && (
                            <ActionMenuGroup label={t('Later this week')}>
                                {days.map((entry) => (
                                    <ActionMenuChip key={entry.date} value={`${entry.picks}/${max}`} onSelect={() => move(entry.date)}>
                                        {dayChip(entry.date, i18n.language)}
                                    </ActionMenuChip>
                                ))}
                            </ActionMenuGroup>
                        )}
                        <ActionMenuItem onSelect={() => setChoosing(true)}>{t('Choose a date...')}</ActionMenuItem>
                        <ActionMenuItem onSelect={() => router.delete(day.picks.destroy(pickId).url, keep)}>{t('Remove from today')}</ActionMenuItem>
                    </RowMenu>
                </span>
            </PopoverAnchor>
            <PopoverContent
                align="end"
                sideOffset={8}
                className={calendar.monthPopover}
                aria-label={t('Move to')}
                // The closing menu hands the focus back to its button, which is outside this popover.
                onFocusOutside={(event) => event.preventDefault()}
                onEscapeKeyDown={(event) => {
                    event.preventDefault();
                    back();
                }}
                onCloseAutoFocus={reopenMenu}
            >
                <div className={styles.head}>
                    <IconButton icon="chevron-left" look="quiet" label={t('Back')} onClick={back} />
                    <p className={styles.heading}>{t('Move to')}</p>
                </div>
                {picker}
            </PopoverContent>
            {!wide && (
                <BottomSheet open={choosing} onOpenChange={setChoosing} title={t('Move to')} onBack={back} onCloseAutoFocus={reopenMenu}>
                    {picker}
                </BottomSheet>
            )}
        </Popover>
    );
}
