import { BottomSheet } from '@/components/solo/bottom-sheet';
import {
    menuBoxClass,
    menuFillClass,
    menuHeadClass,
    menuLabelClass,
    menuNestedClass,
    menuRowClass,
    menuValueClass,
    menuWideClass,
} from '@/components/solo/menu';
import { Poster, SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { type Place, type PlaceContext, type PlaceLinkPill } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { type DayTask } from '@/components/solo/task-row';
import { GroupHeading, Tile, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { parseDay, shortDate } from '@/lib/dates';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './day.module.css';

type Target = 'today' | 'tomorrow' | 'date';

export interface TasksProps {
    /** Today in the display timezone. */
    today: string;
    /** The day picks are chosen for. */
    date: string;
    closed: boolean;
    target: Target;
    lastPlannable: string;
    maxPicks: number;
    pickedCount: number;
    groups: { client: string; tasks: DayTask[] }[];
    hideable: HideableGroup[];
    hidden: { groups: number; tasks: number };
    showHidden: boolean;
    [key: string]: unknown;
}

export interface HideableGroup {
    project_id: number;
    list_name: string | null;
    label: string;
    client_id: number | null;
    client: string | null;
    count: number;
    hidden: boolean;
}

/** How many rows a client group shows before "Pokaż N więcej"; picked rows always show. */
export const GROUP_PREVIEW = 3;

/** How many days the day pills offer: today, tomorrow and the next working days. */
const DAY_CHOICES = 5;

function addDays(day: string, days: number): string {
    const date = parseDay(day);
    date.setDate(date.getDate() + days);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Today, tomorrow, then working days up to the last plannable one; a weekend pick goes through Tydzień. */
export function dayChoices(today: string, last: string): string[] {
    const days = [today];
    for (let day = addDays(today, 1); day <= last && days.length < DAY_CHOICES; day = addDays(day, 1)) {
        const weekday = parseDay(day).getDay();
        if (days.length === 1 || (weekday !== 0 && weekday !== 6)) days.push(day);
    }

    return days;
}

const listUrl = (date: string, todayDate: string, hidden = false) =>
    today.tasks({
        query: {
            ...(date === todayDate ? {} : { na: date === addDays(todayDate, 1) ? 'jutro' : date }),
            ...(hidden ? { ukryte: 1 } : {}),
        },
    }).url;

/**
 * The days picks can be chosen for, as pills: today, tomorrow and the next working days, plus the day the list is on
 * when Tydzień sent a weekend. Each is a link to the same list for that day.
 */
export function dayPillsFor(
    { today: todayDate, date, lastPlannable, showHidden }: Pick<TasksProps, 'today' | 'date' | 'lastPlannable' | 'showHidden'>,
    { t, locale }: Pick<PlaceContext, 't' | 'locale'>,
): PlaceLinkPill[] {
    const days = dayChoices(todayDate, lastPlannable);
    if (!days.includes(date)) days.push(date);

    return days.sort().map((choice, index) => {
        const name = `${new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(parseDay(choice))} ${shortDate(choice, locale)}`;

        return {
            label:
                choice === todayDate
                    ? `${t('Today')} · ${name}`
                    : index === 1 && choice === addDays(todayDate, 1)
                      ? `${t('Tomorrow')} · ${name}`
                      : name,
            href: listUrl(choice, todayDate, showHidden),
            active: choice === date,
        };
    });
}

/** A pick keeps the page's state, so a group opened with Pokaż więcej stays open. */
export function pickTask(taskId: number, date: string): void {
    router.post(day.picks.store().url, { task_id: taskId, date }, { preserveScroll: true, preserveState: true });
}

function actionLabel(target: Target, t: ReturnType<typeof useTranslation>['t']): string {
    return target === 'tomorrow' ? t('For tomorrow') : target === 'date' ? t('Choose') : t('For today');
}

/**
 * Figma: Wszystkie zadania, one client (1087:5502). The client and its count, then the tasks: the title, the project
 * from 768 px and the pick button; a picked row takes the selection fill and says Wybrane.
 */
export function ClientGroup({
    client,
    tasks: rows,
    date,
    target,
    full,
}: {
    client: string;
    tasks: DayTask[];
    date: string;
    target: Target;
    full: boolean;
}) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);
    const shown = expanded ? rows : rows.filter((task, i) => i < GROUP_PREVIEW || task.picked);
    const hidden = rows.length - shown.length;

    return (
        <>
            <GroupHeading label={client} note={rows.length} />
            <TileRows>
                {shown.map((task) => (
                    <Row
                        key={task.task_id}
                        title={task.name}
                        href={tasks.show(task.task_id)}
                        selected={task.picked}
                        meta={task.picked ? t('Picked') : undefined}
                        wideMeta={task.picked ? undefined : task.project}
                        action={
                            task.picked ? undefined : (
                                <SoloButton
                                    variant="secondary"
                                    size="control"
                                    disabled={full}
                                    aria-label={`${actionLabel(target, t)}: ${task.name}`}
                                    onClick={() => pickTask(task.task_id, date)}
                                >
                                    {actionLabel(target, t)}
                                </SoloButton>
                            )
                        }
                    />
                ))}
            </TileRows>
            {hidden > 0 && (
                <TileInset className={styles.moreRow}>
                    <SoloButton variant="quiet" size="control" className={styles.moreAction} onClick={() => setExpanded(true)}>
                        {t('Show {{count}} more', { count: hidden })}
                    </SoloButton>
                </TileInset>
            )}
        </>
    );
}

interface HiddenProps {
    hidden: { groups: number; tasks: number };
    showHidden: boolean;
    onReveal: () => void;
}

/**
 * Figma: Ukryj grupy (946:22628, phone 934:23342). A client row hides every group it has now, its groups follow it
 * indented and hide one at a time; a hidden row is red.
 */
function HideGroupsBody({ groups, hidden, showHidden, onReveal, head = true }: HiddenProps & { groups: HideableGroup[]; head?: boolean }) {
    const { t } = useTranslation();
    const clients = [...Map.groupBy(groups, (group) => group.client_id).values()];
    const row = (group: HideableGroup, nested: boolean) => (
        <button
            key={`${group.project_id}|${group.list_name ?? ''}`}
            type="button"
            className={nested ? `${menuRowClass} ${menuNestedClass}` : menuRowClass}
            aria-pressed={group.hidden}
            onClick={() =>
                router.post(
                    day.hidden.toggle().url,
                    { project_id: group.project_id, list_name: group.list_name },
                    { preserveScroll: true, preserveState: true },
                )
            }
        >
            <span className={menuLabelClass}>{group.label}</span>
            <span className={menuValueClass}>{group.count}</span>
        </button>
    );

    return (
        <>
            {head && <p className={menuHeadClass}>{t('Hide from the list')}</p>}
            {clients.map((own) => {
                const first = own[0];
                if (first.client_id === null) return own.map((group) => row(group, false));

                return [
                    <button
                        key={`client-${first.client_id}`}
                        type="button"
                        className={menuRowClass}
                        aria-pressed={own.every((group) => group.hidden)}
                        onClick={() =>
                            router.post(day.hidden.client().url, { client_id: first.client_id }, { preserveScroll: true, preserveState: true })
                        }
                    >
                        <span className={menuLabelClass}>{first.client}</span>
                        <span className={menuValueClass}>{own.reduce((n, group) => n + group.count, 0)}</span>
                    </button>,
                    ...own.map((group) => row(group, true)),
                ];
            })}
            {hidden.groups > 0 && (
                <>
                    <p className={menuHeadClass}>{t('{{count}} hidden tasks', { count: hidden.tasks })}</p>
                    <button type="button" className={menuRowClass} onClick={onReveal}>
                        <span className={menuLabelClass}>{showHidden ? t('Hide them again') : t('Show hidden')}</span>
                    </button>
                    <button
                        type="button"
                        className={menuRowClass}
                        onClick={() => router.delete(day.hidden.restore().url, { preserveScroll: true, preserveState: true })}
                    >
                        <span className={menuLabelClass}>{t('Restore all')}</span>
                    </button>
                </>
            )}
        </>
    );
}

function HideGroups({ groups, ...hiddenProps }: HiddenProps & { groups: HideableGroup[] }) {
    const { t } = useTranslation();
    const wide = useSoloWide();
    const trigger = (
        <SoloButton variant="quiet" size="control">
            {t('Hide groups')}
        </SoloButton>
    );

    if (!wide) {
        return (
            <BottomSheet title={t('Hide from the list')} action="done" trigger={trigger}>
                <div className={`${menuBoxClass} ${menuFillClass}`}>
                    <HideGroupsBody groups={groups} {...hiddenProps} head={false} />
                </div>
            </BottomSheet>
        );
    }

    return (
        <Popover>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                look="solo-menu"
                align="start"
                sideOffset={8}
                collisionPadding={16}
                className={menuWideClass}
                aria-label={t('Hide groups')}
            >
                <HideGroupsBody groups={groups} {...hiddenProps} />
            </PopoverContent>
        </Popover>
    );
}

/**
 * Figma: Wszystkie zadania (1087:5502, drawn as a dialog; here the page it is today). Every open task grouped by
 * client, where picks are chosen; the day they are chosen for is a pill, and Gotowe leads back to that day's view.
 */
export default function TaskList() {
    const { today: todayDate, date, target, maxPicks, pickedCount, groups, hideable, hidden, showHidden } = usePage<SharedData & TasksProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t } = useTranslation();
    const full = pickedCount >= maxPicks;
    const total = groups.reduce((n, g) => n + g.tasks.length, 0);
    const back = target === 'tomorrow' ? today.plan().url : target === 'date' ? today.week().url : dashboard().url;
    const reveal = () => router.get(listUrl(date, todayDate, !showHidden), {}, { preserveScroll: true, preserveState: true });

    return (
        <SoloSurface day="paper">
            <Head title={t('All tasks')} />
            <main className={styles.summary}>
                <header className={styles.summaryHead}>
                    <Poster>{t('All tasks')}</Poster>
                    <div className={styles.listHead}>
                        <p className={styles.meta}>
                            {[
                                t('{{count}} of {{max}} picked', { count: pickedCount, max: maxPicks }),
                                hidden.groups > 0 ? t('{{count}} hidden tasks', { count: hidden.tasks }) : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                        <div className={styles.listActions}>
                            {hidden.groups > 0 && (
                                <SoloButton variant="quiet" size="control" onClick={reveal}>
                                    {showHidden ? t('Hide them again') : t('Show hidden')}
                                </SoloButton>
                            )}
                            {hideable.length > 0 && <HideGroups groups={hideable} hidden={hidden} showHidden={showHidden} onReveal={reveal} />}
                            <SoloButtonLink href={back} size="control" className={styles.listDone}>
                                {t('Done')}
                            </SoloButtonLink>
                        </div>
                    </div>
                </header>
                <Tile kind="list" className={styles.pool}>
                    {errors.task_id && (
                        <TileInset>
                            <p className={styles.error}>{errors.task_id}</p>
                        </TileInset>
                    )}
                    {groups.map((group) => (
                        <ClientGroup key={group.client} client={group.client} tasks={group.tasks} date={date} target={target} full={full} />
                    ))}
                    {total === 0 && (
                        <TileInset>
                            <TileNote>{t('No open tasks left to pick.')}</TileNote>
                        </TileInset>
                    )}
                </Tile>
            </main>
        </SoloSurface>
    );
}

/** One level below Dziś; its pills are the days a pick can go to. */
TaskList.place = (props: TasksProps, context: PlaceContext): Place => ({
    tokens: [{ label: context.t('All tasks') }],
    pills: dayPillsFor(props, context),
});
