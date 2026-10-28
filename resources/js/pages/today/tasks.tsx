import { BottomSheet } from '@/components/solo/bottom-sheet';
import { RecordHeader } from '@/components/solo/client-header';
import { Masthead } from '@/components/solo/masthead';
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
import { SectionAction, SectionHead } from '@/components/solo/primitives';
import { splitClass, splitMainClass } from '@/components/solo/split';
import { SoloSurface } from '@/components/solo/surface';
import { TaskRow, type DayTask } from '@/components/solo/task-row';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { Icon } from '@/components/ui/icon';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { parseDay, shortDate, weekdayName } from '@/lib/dates';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './today.module.css';

type Target = 'today' | 'tomorrow' | 'date';

interface TasksProps {
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

/** How many days the row's day menu offers: today, tomorrow and the next working days. */
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

/** A pick keeps the page's state, so a group opened with Pokaż więcej stays open. */
export function pickTask(taskId: number, date: string): void {
    router.post(day.picks.store().url, { task_id: taskId, date }, { preserveScroll: true, preserveState: true });
}

function actionLabel(target: Target, t: ReturnType<typeof useTranslation>['t']): string {
    return target === 'tomorrow' ? t('For tomorrow') : target === 'date' ? t('Choose') : t('For today');
}

/** Figma: Popover / Na który dzień (922:20867), beside the row's Na dziś on desktop. */
function DayMenu({ task, today, last }: { task: DayTask; today: string; last: string }) {
    const { t, i18n } = useTranslation();
    const [open, setOpen] = useState(false);
    const label = (day: string, index: number) => {
        const date = `${weekdayName(parseDay(day), i18n.language)} ${shortDate(day, i18n.language)}`;

        return index === 0 ? `${t('Today')} · ${date}` : index === 1 ? `${t('Tomorrow')} · ${date}` : date;
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button type="button" className={`${styles.dayMenu} ${styles.wideOnly}`} aria-label={`${t('Which day')}: ${task.name}`}>
                    <Icon name="chevron-down" size={16} />
                </button>
            </PopoverTrigger>
            <PopoverContent look="solo-menu" align="end" sideOffset={8} aria-label={t('Which day')}>
                <p className={menuHeadClass}>{t('Which day')}</p>
                {dayChoices(today, last).map((choice, index) => (
                    <button
                        key={choice}
                        type="button"
                        className={menuRowClass}
                        onClick={() => {
                            setOpen(false);
                            pickTask(task.task_id, choice);
                        }}
                    >
                        <span className={menuLabelClass}>{label(choice, index)}</span>
                    </button>
                ))}
            </PopoverContent>
        </Popover>
    );
}

/**
 * Figma: Wszystkie zadania, one client. The count follows the name on phones and ends the head from 768 px; rows are
 * the title and the project, Na dziś on desktop. On phones the whole row picks the task and a picked row takes the
 * List row Active fill instead of the word.
 */
export function ClientGroup({
    client,
    tasks,
    date,
    target,
    full,
    lastPlannable,
}: {
    client: string;
    tasks: DayTask[];
    date: string;
    target: Target;
    full: boolean;
    /** With it, a row on today's list also offers other days. */
    lastPlannable?: string;
}) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);
    const shown = expanded ? tasks : tasks.filter((task, i) => i < GROUP_PREVIEW || task.picked);
    const hidden = tasks.length - shown.length;

    return (
        <section className={styles.group}>
            <SectionHead
                quiet
                label={
                    <>
                        {client}
                        <span className={styles.countInline}> · {tasks.length}</span>
                    </>
                }
                meta={<span className={styles.countEnd}>{tasks.length}</span>}
            />
            <ul className={styles.list}>
                {shown.map((task) => (
                    <TaskRow
                        key={task.task_id}
                        compact
                        className={styles.pickRow}
                        task={{ ...task, client: null }}
                        trailing={
                            task.picked ? (
                                <span className={styles.picked}>{t('Picked')}</span>
                            ) : (
                                <span className={styles.pickActions}>
                                    <SectionAction
                                        className={target === 'date' ? `${styles.pickAction} ${styles.named}` : styles.pickAction}
                                        disabled={full}
                                        aria-label={`${actionLabel(target, t)}: ${task.name}`}
                                        onClick={() => pickTask(task.task_id, date)}
                                    >
                                        {actionLabel(target, t)}
                                    </SectionAction>
                                    {target === 'today' && lastPlannable !== undefined && <DayMenu task={task} today={date} last={lastPlannable} />}
                                </span>
                            )
                        }
                    />
                ))}
            </ul>
            {hidden > 0 && (
                <button type="button" className={styles.more} onClick={() => setExpanded(true)}>
                    {t('Show {{count}} more', { count: hidden })}
                </button>
            )}
        </section>
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
            onClick={() => router.post(day.hidden.toggle().url, { project_id: group.project_id, list_name: group.list_name }, { preserveScroll: true, preserveState: true })}
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
                        onClick={() => router.post(day.hidden.client().url, { client_id: first.client_id }, { preserveScroll: true, preserveState: true })}
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
    const trigger = <SectionAction className={styles.subAction}>{t('Hide groups')}</SectionAction>;

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
            <PopoverContent look="solo-menu" align="start" sideOffset={8} collisionPadding={16} className={menuWideClass} aria-label={t('Hide groups')}>
                <HideGroupsBody groups={groups} {...hiddenProps} />
            </PopoverContent>
        </Popover>
    );
}

/** The full list of open tasks, grouped by client, where picks are chosen; Propozycje on Jutro offer a few of them. */
export default function TaskList() {
    const { date, target, lastPlannable, maxPicks, pickedCount, groups, hideable, hidden, showHidden } = usePage<SharedData & TasksProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t, i18n } = useTranslation();
    const full = pickedCount >= maxPicks;
    const total = groups.reduce((n, g) => n + g.tasks.length, 0);
    const back = target === 'tomorrow' ? { href: today.plan().url, label: t('Tomorrow') } : { href: dashboard().url, label: t('Today') };
    const reveal = () =>
        router.get(
            today.tasks().url,
            { ...(target === 'tomorrow' ? { na: 'jutro' } : target === 'date' ? { na: date } : {}), ...(showHidden ? {} : { ukryte: 1 }) },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={t('All tasks')} />
            <div className={styles.frame}>
                <Masthead />
                <main>
                    <RecordHeader size="poster" back={back} trail={[back, { label: t('All tasks') }]} title={t('All tasks')} />
                    <div className={`${splitClass} ${styles.listGrid}`}>
                        <div className={`${splitMainClass} ${styles.main}`}>
                            <p className={`${styles.quiet} ${styles.subLine}`}>
                                {target === 'date' && <span>{`${weekdayName(parseDay(date), i18n.language)} ${shortDate(date, i18n.language)}`}</span>}
                                <span>{t('{{count}} of {{max}} picked', { count: pickedCount, max: maxPicks })}</span>
                                {hidden.groups > 0 && (
                                    <>
                                        <span>{t('{{count}} hidden tasks', { count: hidden.tasks })}</span>
                                        <SectionAction className={styles.subAction} onClick={reveal}>
                                            {showHidden ? t('Hide them again') : t('Show hidden')}
                                        </SectionAction>
                                    </>
                                )}
                                {hideable.length > 0 && <HideGroups groups={hideable} hidden={hidden} showHidden={showHidden} onReveal={reveal} />}
                            </p>
                            {errors.task_id && <p className={styles.error}>{errors.task_id}</p>}
                            {groups.map((group) => (
                                <ClientGroup
                                    key={group.client}
                                    client={group.client}
                                    tasks={group.tasks}
                                    date={date}
                                    target={target}
                                    full={full}
                                    lastPlannable={lastPlannable}
                                />
                            ))}
                            {total === 0 && <p className={styles.quiet}>{t('No open tasks left to pick.')}</p>}
                        </div>
                    </div>
                </main>
            </div>
        </SoloSurface>
    );
}
