import { SoloField, SoloInput } from '@/components/solo/field';
import { SoloButton, touchTargetClass } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { rowParts } from '@/components/solo/row';
import { type DayTask } from '@/components/solo/task-row';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { Icon } from '@/components/ui/icon';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { requestId } from '@/hooks/use-timer-actions';
import { parseDay } from '@/lib/dates';
import { jsonRequest, JsonRequestError } from '@/lib/json-request';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { type KeyboardEvent, type ReactNode, type RefObject, useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import styles from './add-or-pick.module.css';
import { type ProjectChoices, projectLabel, ProjectPicker } from './project-picker';

type Found = Pick<DayTask, 'task_id' | 'name' | 'client' | 'project'>;

interface Options {
    query: string;
    recent: boolean;
    tasks: Found[];
    total: number | null;
}

/** idle: too short to search; loading: the answer for this text is not in yet; error: it failed. */
export type SearchStatus = 'idle' | 'loading' | 'ready' | 'error';

export interface Search {
    status: SearchStatus;
    options: Options | null;
}

export type Item = { kind: 'task'; task: Found } | { kind: 'project'; projectId: number } | { kind: 'new' };

/** The server searches from two characters on. */
export const MIN_SEARCH = 2;

/**
 * The rows the field offers and whether the first one is selected without a key press. Typed text lists its matches
 * and then New; while the matches are not in, or failed, nothing is selected, so Enter never makes a task that may
 * already exist. Nothing typed lists the recent tasks and projects.
 */
export function addItems(typed: string, search: Search, recentProjects: number[]): { items: Item[]; preselect: boolean } {
    const found = search.status === 'ready' ? (search.options?.tasks ?? []).map((task): Item => ({ kind: 'task', task })) : [];

    if (typed === '') {
        return { items: [...found, ...recentProjects.map((projectId): Item => ({ kind: 'project', projectId }))], preselect: true };
    }

    return { items: [...found, { kind: 'new' }], preselect: search.status === 'ready' || search.status === 'idle' };
}

/** The selected row: the one the keys or the pointer chose, else the first when it may be preselected, else none. */
export function selectedIndex(count: number, chosen: number | null, preselect: boolean): number | null {
    if (count === 0) return null;
    if (chosen !== null) return Math.min(chosen, count - 1);

    return preselect ? 0 : null;
}

/** Polish needs the weekday in the accusative ("na środę"), so each day has its own line. */
const SHEET_TITLES = [
    'Add a task for Monday',
    'Add a task for Tuesday',
    'Add a task for Wednesday',
    'Add a task for Thursday',
    'Add a task for Friday',
    'Add a task for Saturday',
    'Add a task for Sunday',
] as const;

/** The text the new task gets, inside the low and high quotes; the lang files keep straight ones only. */
const quoted = (text: string) => `„${text}”`;

function useSearch(text: string, date: string): Search {
    const [answer, setAnswer] = useState<Options | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const query = text.trim();
    const searchable = query === '' || query.length >= MIN_SEARCH;

    useEffect(() => {
        if (!searchable) return;
        const controller = new AbortController();
        const timer = window.setTimeout(
            () => {
                jsonRequest<Options>(day.add.tasks.url({ query: { q: query || undefined, date } }), 'GET', undefined, controller.signal)
                    .then((options) => {
                        setAnswer(options);
                        setFailed(null);
                    })
                    .catch(() => {
                        if (!controller.signal.aborted) setFailed(query);
                    });
            },
            query === '' ? 0 : 150,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, date, searchable]);

    if (!searchable) return { status: 'idle', options: null };
    if (answer !== null && answer.query === query) return { status: 'ready', options: answer };

    return { status: failed === query ? 'error' : 'loading', options: null };
}

function useProjectChoices(): ProjectChoices | null {
    const [choices, setChoices] = useState<ProjectChoices | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        jsonRequest<ProjectChoices>(day.add.projects.url(), 'GET', undefined, controller.signal)
            .then(setChoices)
            .catch(() => undefined);

        return () => controller.abort();
    }, []);

    return choices;
}

interface AddOrPickProps {
    /** The day the task goes on. */
    date: string;
    picked: number;
    maxPicks: number;
    warnFull: boolean;
    /** The full list for this day, at the end of the menu. */
    listHref?: string;
    /** Quiet end meta of the resting row, such as the free places. */
    note?: ReactNode;
    /** Opened by the caller's own button (Week's Write in a task) instead of the resting row; closing calls onClose. */
    opened?: boolean;
    onClose?: () => void;
    /** The caller's button that opened the field, which gets the focus back when it closes. */
    opener?: RefObject<HTMLElement | null>;
}

/**
 * Figma: 1F (1412:21781, typing 1412:21953, phone sheet 1413:23155). One field that picks an open task or adds a new
 * one to the day: at rest a quiet Add a task row; typing lists the matching open tasks in the plan's order and,
 * last, New with the project it goes to. From 768 px the field takes the row and its menu opens under it; below
 * 768 px the row stays and the field and the results fill a sheet.
 */
export function AddOrPick({ opened, onClose, opener, note, ...field }: AddOrPickProps) {
    const { t } = useTranslation();
    const wide = useSoloWide();
    const resting = useRef<HTMLButtonElement>(null);
    const [ownOpen, setOwnOpen] = useState(false);
    const open = opened ?? ownOpen;

    function close(refocus: boolean) {
        if (opened !== undefined) onClose?.();
        else setOwnOpen(false);
        // The opener comes back to the page as the field leaves it, so it is focused once that render is done.
        if (refocus) window.setTimeout(() => (opener?.current ?? resting.current)?.focus());
    }

    return (
        <>
            {opened === undefined && !(open && wide) && (
                <li className={styles.resting}>
                    <button ref={resting} type="button" className={styles.restingButton} onClick={() => setOwnOpen(true)}>
                        <Icon name="plus" size={20} className={styles.plus} />
                        <span>{t('Add a task')}</span>
                        {note !== undefined && <span className={styles.restingNote}>{note}</span>}
                    </button>
                </li>
            )}
            {open && <AddField {...field} sheet={!wide} onClose={close} />}
        </>
    );
}

function AddField({
    date,
    picked,
    maxPicks,
    warnFull,
    listHref,
    sheet,
    onClose,
}: Omit<AddOrPickProps, 'opened' | 'onClose' | 'opener' | 'note'> & { sheet: boolean; onClose: (refocus: boolean) => void }) {
    const { t } = useTranslation();
    const { timers } = usePage<SharedData>().props;
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const menu = useRef<HTMLDivElement>(null);
    // One submit's id: kept for a retry after a lost answer or a server failure, dropped once the server refused it or the payload changed.
    const intent = useRef<string | null>(null);
    const [menuOpen, setMenuOpen] = useState(true);
    const [text, setText] = useState('');
    const [target, setTarget] = useState<number | null>(null);
    const [picking, setPicking] = useState(false);
    const [chosen, setChosen] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const search = useSearch(text, date);
    const choices = useProjectChoices();
    const typed = text.trim();

    // Read once per opening: only the one running timer's project, never a guess between several.
    const [preset, setPreset] = useState(false);
    if (!preset && choices !== null) {
        const only = timers.length === 1 ? (timers[0].project_id ?? null) : null;
        setPreset(true);
        if (only !== null && choices.projects.some((project) => project.id === only)) setTarget(only);
    }

    const { items, preselect } = addItems(typed, search, typed === '' && choices !== null ? choices.recent : []);
    const at = selectedIndex(items.length, chosen, preselect);
    const optionId = (index: number) => `${id}-option-${index}`;
    const full = warnFull && picked >= maxPicks;
    const close = (refocus = false) => onClose(refocus);

    function chooseProject(projectId: number) {
        if (projectId !== target) intent.current = null;
        setTarget(projectId);
    }

    function take(item: Item | undefined) {
        if (item === undefined || busy) return;
        if (item.kind === 'project') {
            chooseProject(item.projectId);
            input.current?.focus();

            return;
        }
        if (item.kind === 'task') {
            setBusy(true);
            router.post(
                day.picks.store().url,
                { task_id: item.task.task_id, date },
                {
                    preserveScroll: true,
                    onSuccess: () => close(true),
                    onError: (errors) => setError(errors.task_id ?? Object.values(errors)[0] ?? t('Something went wrong.')),
                    onFinish: () => setBusy(false),
                },
            );

            return;
        }
        void create();
    }

    async function create() {
        if (typed === '') return;
        if (target === null) {
            setPicking(true);

            return;
        }
        intent.current ??= requestId();
        setBusy(true);
        setError(null);
        try {
            const made = await jsonRequest<{ task: Found }>(day.add.store().url, 'POST', {
                name: typed,
                project_id: target,
                date,
                request_id: intent.current,
            });
            const taskId = made.task.task_id;
            close(true);
            toast.success(
                t('Added: {{name}}, in {{place}}.', {
                    name: made.task.name,
                    place: projectLabel({ client: made.task.client, name: made.task.project ?? '' }),
                }),
                {
                    action: { label: t('Change'), onClick: () => router.visit(tasks.show(taskId, { query: { change: 1 } }).url) },
                },
            );
            router.reload();
        } catch (failure) {
            const refused = failure instanceof JsonRequestError && failure.status >= 400 && failure.status < 500;
            if (refused) intent.current = null;
            setError(refused ? (Object.values(failure.errors)[0]?.[0] ?? failure.message) : t('Something went wrong.'));
        } finally {
            setBusy(false);
        }
    }

    /** From 768 px the menu is a portal: Tab from the field moves into it, to Goes to and the full list. */
    function tabIntoMenu(event: KeyboardEvent<HTMLInputElement>): boolean {
        if (sheet || event.shiftKey) return false;
        const first = menu.current?.querySelector<HTMLElement>('button, a[href]');
        if (!first) return false;
        event.preventDefault();
        first.focus();

        return true;
    }

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            setMenuOpen(true);
            take(at === null ? undefined : items[at]);
        } else if (event.key === 'Tab') {
            tabIntoMenu(event);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setMenuOpen(true);
            if (items.length === 0) return;
            const down = event.key === 'ArrowDown';
            const next = at === null ? (down ? 0 : items.length - 1) : (at + (down ? 1 : -1) + items.length) % items.length;
            setChosen(next);
            document.getElementById(optionId(next))?.scrollIntoView({ block: 'nearest' });
        }
    }

    const field = (inSheet: boolean) => (
        <SoloInput
            ref={input}
            id={`${id}-field`}
            value={text}
            role="combobox"
            aria-expanded={inSheet || menuOpen}
            aria-controls={`${id}-list`}
            aria-autocomplete="list"
            aria-activedescendant={at === null ? undefined : optionId(at)}
            aria-label={inSheet ? undefined : t('Add a task')}
            enterKeyHint="go"
            autoComplete="off"
            autoFocus
            maxLength={500}
            className={inSheet ? undefined : styles.input}
            onChange={(event) => {
                if (event.target.value.trim() !== typed) intent.current = null;
                setText(event.target.value);
                setChosen(null);
                setError(null);
                setMenuOpen(true);
            }}
            onFocus={() => setMenuOpen(true)}
            onKeyDown={onKeyDown}
        />
    );

    const panel = (inSheet: boolean) => (
        <Panel
            listId={`${id}-list`}
            optionId={optionId}
            items={items}
            at={at}
            search={search}
            typed={typed}
            choices={choices}
            full={full ? { count: picked, max: maxPicks } : null}
            error={error}
            onHover={setChosen}
            onTake={take}
            target={
                typed !== '' && (
                    <div className={styles.target}>
                        <span className={styles.targetCaption}>{t('Goes to')}</span>
                        <ProjectPicker
                            choices={choices}
                            value={target}
                            onChange={(projectId) => {
                                chooseProject(projectId);
                                // a project was picked for the new task, so New becomes the selected row
                                setChosen(items.length - 1);
                                setError(null);
                                input.current?.focus();
                            }}
                            open={picking}
                            onOpenChange={setPicking}
                            inline={inSheet}
                        />
                    </div>
                )
            }
            footer={
                (!inSheet || listHref !== undefined) && (
                    <div className={styles.footer}>
                        {!inSheet && (
                            <p className={styles.hints} aria-hidden="true">
                                {t('↑ ↓ choose · Enter takes the selected · Esc closes')}
                            </p>
                        )}
                        {listHref !== undefined && (
                            <Link href={listHref} className={`${styles.footerLink} ${touchTargetClass}`}>
                                {t('All tasks')}
                            </Link>
                        )}
                    </div>
                )
            }
        />
    );

    if (sheet) {
        const selected = at === null ? undefined : items[at];

        return (
            <ResponsiveDialog
                open
                onOpenChange={(next) => !next && close(true)}
                title={t(SHEET_TITLES[(parseDay(date).getDay() + 6) % 7])}
                onSubmit={(event) => {
                    event.preventDefault();
                    take(selected);
                }}
                actions={() => (
                    <SoloButton type="submit" variant="ink" size="touch" block disabled={busy || selected === undefined}>
                        {selected?.kind === 'new' && target === null ? t('Choose a project') : t('Add the selected')}
                    </SoloButton>
                )}
            >
                <div className={styles.sheet}>
                    <SoloField label={t('Task')} htmlFor={`${id}-field`}>
                        {field(true)}
                    </SoloField>
                    {panel(true)}
                </div>
            </ResponsiveDialog>
        );
    }

    return (
        <li className={styles.open}>
            <Icon name="plus" size={20} className={styles.plus} />
            <Popover open={menuOpen} onOpenChange={setMenuOpen}>
                <PopoverAnchor asChild>{field(false)}</PopoverAnchor>
                <PopoverContent
                    ref={menu}
                    align="start"
                    sideOffset={8}
                    className={styles.menu}
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    onCloseAutoFocus={(event) => event.preventDefault()}
                    onInteractOutside={(event) => {
                        if (event.target instanceof Node && input.current?.contains(event.target)) {
                            event.preventDefault();

                            return;
                        }
                        // Clicked away with nothing typed: the field goes back to its resting row.
                        if (typed === '') close();
                    }}
                    onEscapeKeyDown={(event) => {
                        event.preventDefault();
                        close(true);
                    }}
                    onKeyDown={(event) => {
                        // Shift+Tab from the menu's first control goes back to the field the menu belongs to.
                        if (event.key !== 'Tab' || !event.shiftKey) return;
                        if (document.activeElement === menu.current?.querySelector('button, a[href]')) {
                            event.preventDefault();
                            input.current?.focus();
                        }
                    }}
                >
                    {panel(false)}
                </PopoverContent>
            </Popover>
        </li>
    );
}

function Panel({
    listId,
    optionId,
    items,
    at,
    search,
    typed,
    choices,
    full,
    error,
    onHover,
    onTake,
    target,
    footer,
}: {
    listId: string;
    optionId: (index: number) => string;
    items: Item[];
    at: number | null;
    search: Search;
    typed: string;
    choices: ProjectChoices | null;
    full: { count: number; max: number } | null;
    error: string | null;
    onHover: (index: number) => void;
    onTake: (item: Item) => void;
    target: ReactNode;
    footer: ReactNode;
}) {
    const { t } = useTranslation();
    const found = items.flatMap((item, index) => (item.kind === 'task' ? [{ task: item.task, index }] : []));
    const option = (item: Item, index: number, children: ReactNode, className?: string) => (
        <div
            key={index}
            id={optionId(index)}
            role="option"
            aria-selected={index === at}
            data-highlighted={index === at ? '' : undefined}
            className={[styles.option, className].filter(Boolean).join(' ')}
            onMouseMove={() => onHover(index)}
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => onTake(item)}
        >
            {children}
        </div>
    );
    const taskOption = (task: Found, index: number) =>
        option(
            { kind: 'task', task },
            index,
            <>
                <span className={styles.optionTitle} title={task.name}>
                    {task.name}
                </span>
                {(task.client || task.project) && (
                    <span className={styles.optionMeta} title={[task.client, task.project].filter(Boolean).join(' · ')}>
                        {rowParts([task.client, task.project])}
                    </span>
                )}
            </>,
            styles.taskOption,
        );
    const listHead =
        search.status === 'loading'
            ? t('From the list: searching')
            : search.status === 'error'
              ? t('From the list: the search failed. Keep typing to try again.')
              : search.options?.total === 0
                ? t('From the list (0): nothing matches')
                : t('From the list ({{count}})', { count: search.options?.total ?? found.length });

    return (
        <>
            {full !== null && (
                <p className={styles.warn}>
                    <Icon name="warning" size={20} />
                    <span>{t('The day is full: {{count}} of {{max}}. One more is added too.', full)}</span>
                </p>
            )}
            <div id={listId} role="listbox" aria-label={t('Add a task')} aria-busy={search.status === 'loading'} className={styles.list}>
                {typed === '' ? (
                    <>
                        {found.length > 0 && (
                            <div role="group" aria-labelledby={`${listId}-recent`}>
                                <p id={`${listId}-recent`} className={styles.head}>
                                    {t('Recent tasks ({{count}})', { count: found.length })}
                                </p>
                                {found.map(({ task, index }) => taskOption(task, index))}
                            </div>
                        )}
                        {choices !== null && choices.recent.length > 0 && (
                            <div role="group" aria-labelledby={`${listId}-projects`}>
                                <p id={`${listId}-projects`} className={styles.head}>
                                    {t('Recent projects: a new task goes here')}
                                </p>
                                {items.map((item, index) => {
                                    if (item.kind !== 'project') return null;
                                    const project = choices.projects.find((choice) => choice.id === item.projectId);

                                    return project === undefined
                                        ? null
                                        : option(item, index, <span className={styles.optionTitle}>{projectLabel(project)}</span>);
                                })}
                            </div>
                        )}
                    </>
                ) : (
                    <>
                        {search.status !== 'idle' && (
                            <div role="group" aria-labelledby={`${listId}-list`}>
                                <p id={`${listId}-list`} className={styles.head} aria-live="polite">
                                    {listHead}
                                </p>
                                {found.map(({ task, index }) => taskOption(task, index))}
                            </div>
                        )}
                        <div role="group" aria-labelledby={`${listId}-new`}>
                            <p id={`${listId}-new`} className={styles.head}>
                                {t('New task')}
                            </p>
                            {option(
                                { kind: 'new' },
                                items.length - 1,
                                <>
                                    <Icon name="plus" size={20} className={styles.newIcon} />
                                    <span className={styles.optionTitle}>{t('New: {{text}}', { text: quoted(typed) })}</span>
                                </>,
                                styles.newOption,
                            )}
                        </div>
                    </>
                )}
            </div>
            {target}
            {error !== null && (
                <p className={styles.error} role="alert">
                    {error}
                </p>
            )}
            {footer}
        </>
    );
}
