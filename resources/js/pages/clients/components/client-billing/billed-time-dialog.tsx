import { fieldControl, SoloField, SoloInput, SoloTextarea } from '@/components/solo/field';
import { KvRow } from '@/components/solo/kv-row';
import { ListRowStatic } from '@/components/solo/list-row';
import { SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { capitalize, monthName, shortDate } from '@/lib/dates';
import clientBilled from '@/routes/client-billed';
import { router } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, hoursTerms, localeOf } from '../client-list/format';
import { bothValues, fieldHours, parseHours, taskTip } from './billed-format';
import styles from './client-billing.module.css';
import type { BilledMonth, BilledTaskRow } from './types';

type Loaded = { state: 'loading' } | { state: 'failed' } | { state: 'ready'; month: BilledMonth };

async function fetchMonth(url: string, signal: AbortSignal): Promise<BilledMonth> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
    if (!response.ok || !(response.headers.get('content-type') ?? '').includes('application/json')) {
        throw new Error(`billed month ${response.status}`);
    }

    return (await response.json()) as BilledMonth;
}

/** Why a read-only month cannot change, in the owner's words: a closure, then the report's delivery, else the server's reason. */
export function readOnlyLine(month: BilledMonth, locale: string, t: TFunction): string {
    const name = capitalize(monthName(month.period, locale));
    const sent = month.report !== null && month.report.status === 'sent' && month.report.sent_on !== null ? month.report.sent_on : null;
    if (month.closed && month.closed_on !== null) {
        return sent !== null
            ? t('billed_locked_closed_sent', { month: name, date: shortDate(month.closed_on, locale), sent: shortDate(sent, locale) })
            : t('billed_locked_closed', { month: name, date: shortDate(month.closed_on, locale) });
    }
    if (month.refusal_code === 'not_a_draft' && month.report !== null) {
        return t(month.report.status === 'sent' ? 'billed_locked_report_sent' : 'billed_locked_report_finalized', {
            month: monthName(month.period, locale),
        });
    }

    return month.refusal ?? '';
}

/**
 * The month as it would read after one task's figure changes, from the live task figures in whole minutes as the
 * server adds them (a draft's stored hours may lag behind its tasks), and the balance that leaves of the available hours.
 */
export function afterTaskChange(month: Pick<BilledMonth, 'tasks' | 'available'>, task: Pick<BilledTaskRow, 'task_id' | 'real'>, figure: number | null) {
    const minutes = (hours: number) => Math.round(hours * 60);
    const hours = (value: number) => Math.round((value / 60) * 100) / 100;
    const now = month.tasks.reduce((sum, row) => sum + minutes(row.billed), 0);
    const current = month.tasks.find((row) => row.task_id === task.task_id)?.billed ?? 0;
    const total = hours(now - minutes(current) + minutes(figure ?? task.real));

    return { now: hours(now), total, closing: Math.round((month.available - total) * 100) / 100 };
}

/**
 * Figma 998:34714 (phone 999:35058), one task 1000:35298 (phone 1000:35796), read only 1002:36842, empty 1002:38402: a
 * month's tasks in both values; a task's billed hours are set one at a time and the month is their sum.
 */
export function BilledTimeDialog({
    clientId,
    period,
    open,
    onOpenChange,
    onReopen,
    onSaved,
    task: direct = null,
}: {
    clientId: number;
    period: string;
    /** Opens straight on this task's figure (Change on a task of the month); a month that cannot change shows its list read only instead. */
    task?: number | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The existing reopen flow for what locks the month (the closure or the report), when the caller has one. */
    onReopen?: (month: BilledMonth) => void;
    /** The page after a save the server took, for a caller holding its own copy of the report. */
    onSaved?: (page: { props: Record<string, unknown> }) => void;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const wide = useSoloWide();
    const [loaded, setLoaded] = useState<Loaded>({ state: 'loading' });
    const [attempt, setAttempt] = useState(0);
    const [taskId, setTaskId] = useState<number | null>(null);
    const [hours, setHours] = useState('');
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const [serverError, setServerError] = useState<string | null>(null);

    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setLoaded({ state: 'loading' });
            setTaskId(null);
            setAttempt(0);
        }
    }

    useEffect(() => {
        if (!open) {
            return;
        }
        const controller = new AbortController();
        fetchMonth(clientBilled.show([clientId, period]).url, controller.signal)
            .then((month) => {
                setLoaded({ state: 'ready', month });
                const row = attempt === 0 && month.refusal === null ? month.tasks.find((candidate) => candidate.task_id === direct) : undefined;
                if (row !== undefined) {
                    openTask(row);
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setLoaded({ state: 'failed' });
                }
            });

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- the task to open is read once, when the month first arrives
    }, [open, clientId, period, attempt]);

    const month = loaded.state === 'ready' ? loaded.month : null;
    const editable = month !== null && month.refusal === null;
    const bank = month?.has_retainer ?? false;
    // A month that turned read only shows its list and lock line, never a task form that cannot save.
    const task = month !== null && editable && taskId !== null ? (month.tasks.find((row) => row.task_id === taskId) ?? null) : null;

    function openTask(row: BilledTaskRow) {
        setServerError(null);
        setTaskId(row.task_id);
        setHours(row.stated !== null ? fieldHours(row.stated, locale) : '');
        setNote(row.note ?? '');
    }

    const typed = parseHours(hours);
    const error =
        typed !== null && Number.isNaN(typed)
            ? t('Enter a number of hours.')
            : typed !== null && typed < 0
              ? t('Hours cannot be negative.')
              : (serverError ?? undefined);

    // Saved or refused, the list reads the month again; a refusal (the month closed meanwhile) shows it read only, naming what locks it.
    const options = {
        preserveScroll: true,
        onSuccess: (page: { props: Record<string, unknown> }) => {
            const flash = page.props.flash as { error?: string | null } | undefined;
            if (!flash?.error) {
                onSaved?.(page);
                if (direct !== null) {
                    onOpenChange(false);
                    return;
                }
            }
            setTaskId(null);
            setLoaded({ state: 'loading' });
            setAttempt((n) => n + 1);
        },
        onError: (errors: Record<string, string>) => setServerError(errors.hours ?? errors.note ?? Object.values(errors)[0] ?? null),
        onFinish: () => setProcessing(false),
    };

    function clear() {
        if (task === null) {
            return;
        }
        setProcessing(true);
        router.delete(clientBilled.task.destroy([clientId, period, task.task_id]).url, options);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        if (task === null) {
            onOpenChange(false);
            return;
        }
        if (!editable || error !== undefined) {
            return;
        }
        if (typed === null) {
            if (task.stated === null) {
                setTaskId(null);
            } else {
                clear();
            }
            return;
        }
        setProcessing(true);
        router.put(clientBilled.task.update([clientId, period, task.task_id]).url, { hours: typed, note }, options);
    }

    const reopenButton =
        month !== null && !editable && onReopen !== undefined && (month.closure_id !== null || month.refusal_code === 'not_a_draft') ? (
            <SoloButton variant="text" size={wide ? 'md' : 'touch'} className={styles.restore} onClick={() => onReopen(month)}>
                {month.closure_id !== null ? t('Reopen this month') : t('Reopen the report')}
            </SoloButton>
        ) : null;

    const restore = (size: 'md' | 'touch') => (
        <SoloButton variant="text" size={size} className={styles.restore} onClick={clear} disabled={processing}>
            {t('Back to real time')}
        </SoloButton>
    );

    if (task !== null && month !== null) {
        const after = afterTaskChange(month, task, typed === null || Number.isNaN(typed) ? null : typed);

        return (
            <ResponsiveDialog
                open={open}
                onOpenChange={onOpenChange}
                title={taskTitle(task, t)}
                sheetTitle={t('Billed time')}
                description={
                    wide
                        ? [capitalize(monthName(month.period, locale)), month.client, task.project].filter(Boolean).join(' · ')
                        : `${taskTitle(task, t)} · ${monthName(month.period, locale)}`
                }
                onSubmit={submit}
                fields
                sheetAction="save"
                saveLabel={processing ? t('Saving…') : t('Save')}
                saving={processing || error !== undefined}
                footerClassName={styles.billedActions}
                actions={(size) => (
                    <>
                        {task.stated !== null && restore(size)}
                        <SoloButton variant="pill" onClick={() => (direct !== null ? onOpenChange(false) : setTaskId(null))} disabled={processing}>
                            {t('Cancel')}
                        </SoloButton>
                        <SoloButton type="submit" variant="ink" size={size} disabled={processing || error !== undefined}>
                            {processing ? t('Saving…') : t('Save')}
                        </SoloButton>
                    </>
                )}
            >
                <dl className={styles.list}>
                    <KvRow layout="split" label={t('Real time')}>{`${hoursNumber(task.real, locale)} h`}</KvRow>
                    <KvRow layout="split" label={t('Billed now')}>{`${hoursNumber(task.billed, locale)} h`}</KvRow>
                </dl>
                <SoloField label={t('Billed hours')} htmlFor="billed-hours" error={error}>
                    <SoloInput
                        {...fieldControl('billed-hours', error)}
                        inputMode="decimal"
                        value={hours}
                        onChange={(e) => {
                            setServerError(null);
                            setHours(e.target.value);
                        }}
                        placeholder={fieldHours(task.real, locale)}
                        disabled={processing}
                        autoFocus={wide}
                    />
                </SoloField>
                <p className={styles.hint}>{t('An empty field bills the task its real time.')}</p>
                {error === undefined && (
                    <p className={styles.hint}>
                        {bank
                            ? t('billed_after_save_bank', {
                                  total: hoursNumber(after.total, locale),
                                  now: hoursNumber(after.now, locale),
                                  closing: hoursNumber(after.closing, locale, true),
                              })
                            : t('billed_after_save', { total: hoursNumber(after.total, locale), now: hoursNumber(after.now, locale) })}
                    </p>
                )}
                <SoloField label={t('Note (only for you)')} htmlFor="billed-note">
                    <SoloTextarea
                        id="billed-note"
                        rows={3}
                        value={note}
                        onChange={(e) => {
                            setServerError(null);
                            setNote(e.target.value);
                        }}
                        placeholder={t('For example: an hour fixing my own mistake')}
                        disabled={processing}
                    />
                </SoloField>
                {!wide && task.stated !== null && restore('touch')}
            </ResponsiveDialog>
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Billed time · {{month}}', { month: monthName(period, locale) })}
            sheetTitle={t('Billed time')}
            description={
                month !== null
                    ? subtitle(month, editable, wide, locale, t)
                    : loaded.state === 'loading'
                      ? t('Reading the figures…')
                      : t('Could not read the figures. Close the dialog and try again.')
            }
            onSubmit={submit}
            sheetAction="close"
            footerClassName={styles.billedActions}
            actions={(size) => (
                <>
                    {reopenButton}
                    <SoloButton variant="ink" size={size} onClick={() => onOpenChange(false)}>
                        {t('Close')}
                    </SoloButton>
                </>
            )}
        >
            {month !== null && (
                <>
                    <dl className={styles.list}>
                        <KvRow layout="split" label={t('Real time')}>{`${hoursNumber(month.real, locale)} h`}</KvRow>
                        {bank && (
                            <KvRow layout="split" label={wide ? t('Opening balance for the month') : t('Opening balance')}>
                                {`${hoursNumber(month.opening, locale, true)} h`}
                            </KvRow>
                        )}
                    </dl>
                    {/* A month closed before figures were per task froze no task rows, only its totals. */}
                    {(month.tasks.length > 0 || month.real === 0) && (
                        <>
                            <SectionHead kind="body" label={`${t('Tasks')} · ${month.tasks.length}`} meta={t('billed_tasks_meta')} />
                            {month.tasks.length === 0 ? (
                                <p className={styles.hint}>{t('No time on report tasks this month yet.')}</p>
                            ) : (
                                <ul className={styles.taskList}>
                                    {month.tasks.map((row) => (
                                        <li key={row.task_id}>
                                            <TaskRow row={row} editable={editable} locale={locale} onOpen={() => openTask(row)} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                    {month.billed !== null && (
                        <dl className={styles.list}>
                            <KvRow layout="split" label={t('Billed hours')}>{`${hoursNumber(month.billed, locale)} h`}</KvRow>
                            {bank && (
                                <KvRow layout="split" label={wide ? t('Balance at the end of the month') : t('Balance at the end')}>
                                    {`${hoursNumber(month.closing, locale, true)} h`}
                                </KvRow>
                            )}
                        </dl>
                    )}
                    {!editable && (
                        <p className={styles.lockLine}>
                            <Icon name="lock" size={16} />
                            <span>{readOnlyLine(month, locale, t)}</span>
                        </p>
                    )}
                </>
            )}
        </ResponsiveDialog>
    );
}

/** A task row: title and "real · billed", the (i) on a stated figure; an editable row's title opens the task. */
function TaskRow({ row, editable, locale, onOpen }: { row: BilledTaskRow; editable: boolean; locale: string; onOpen: () => void }) {
    const { t } = useTranslation();
    const tip = taskTip(row, locale, t);
    const title = taskTitle(row, t);

    return (
        <ListRowStatic
            className={styles.billedRow}
            title={
                editable ? (
                    <button type="button" className={styles.billedRowTitle} onClick={onOpen}>
                        {title}
                    </button>
                ) : (
                    title
                )
            }
            value={
                <span className={styles.billedCell}>
                    {tip !== null && <InfoHint label={t('Your figure')}>{tip}</InfoHint>}
                    {bothValues(row, locale)}
                </span>
            }
            trailing={editable ? <Icon name="chevron-right" size={16} /> : null}
        />
    );
}

function taskTitle(task: BilledTaskRow, t: TFunction): string {
    return task.task !== '' ? task.task : t('Time without a task');
}

function subtitle(month: BilledMonth, editable: boolean, wide: boolean, locale: string, t: TFunction): string {
    return [
        wide ? null : capitalize(monthName(month.period, locale)),
        month.client,
        month.contracted !== null ? t('package {{hours}} h', { hours: hoursTerms(month.contracted, locale) }) : null,
        editable && month.report?.status === 'draft' ? t('report in draft') : null,
        editable ? null : t('read only'),
    ]
        .filter(Boolean)
        .join(' · ');
}
