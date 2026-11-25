import { ManualTimeEntryDialog } from '@/components/manual-time-entry-dialog';
import { useHelperAction } from '@/components/solo/shell/helper/actions';
import { BottomAction } from '@/components/solo/bottom-action';
import { Notice } from '@/components/solo/notice';
import { SoloButton, Stat } from '@/components/solo/primitives';
import { Tile, TileActions } from '@/components/solo/tile';
import { Checkbox } from '@/components/ui/checkbox';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { JsonRequestError, jsonRequest } from '@/lib/json-request';
import { monthName, statDuration } from '@/lib/dates';
import tasks from '@/routes/tasks';
import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { liveMinutes } from './format';
import styles from './task-page.module.css';
import { BilledTimeDialog } from '../../../clients/components/client-billing/billed-time-dialog';
import { LIVE_PROPS, type TaskBilled, type TaskTime } from './types';

/** Why the report switch did not move: the server's own sentence for the field, else its message. */
export function reportableRefusal(error: unknown): string | null {
    if (error instanceof JsonRequestError) return error.errors.is_reportable?.[0] ?? error.message;

    return error instanceof Error ? error.message : null;
}

/**
 * Counts in the report: the CRM's own flag, a card's too. The switch moves at once and goes back when the server
 * refuses, which it does while a finalized or sent report counts the task.
 */
function ReportSwitch({ taskId, reportable }: { taskId: number; reportable: boolean }) {
    const { t } = useTranslation();
    const [sending, setSending] = useState<boolean | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);

    async function change(next: boolean) {
        setSending(next);
        setRefusal(null);
        try {
            await jsonRequest(tasks.reportable(taskId).url, 'PATCH', { is_reportable: next });
        } catch (error) {
            setRefusal(reportableRefusal(error) ?? t('Something went wrong.'));
        }
        router.reload({ only: LIVE_PROPS, onFinish: () => setSending(null) });
    }

    return (
        <div className={styles.report} data-fill="">
            <label className={styles.reportRow}>
                <span>{t('Counts in the report')}</span>
                <Checkbox
                    variant="switch"
                    checked={sending ?? reportable}
                    disabled={sending !== null}
                    onCheckedChange={(checked) => void change(checked === true)}
                />
            </label>
            {refusal !== null && (
                <Notice kind="lock" role="alert">
                    {refusal}
                </Notice>
            )}
        </div>
    );
}

/** Figma: Billed time on the task page (1156:59625, locked 1156:60200). The month's billed hours beside the real time, changed in the Billing dialog. */
function BilledRow({ taskId, billed }: { taskId: number; billed: TaskBilled }) {
    const { t, i18n } = useTranslation();
    const [open, setOpen] = useState(false);
    const hours = `${new Intl.NumberFormat(i18n.language, { maximumFractionDigits: 2 }).format(billed.hours)} h`;

    return (
        <div className={styles.reportRow} data-fill="" data-plain="">
            <span>
                {t('Billed time')} · {monthName(billed.period, i18n.language)}
            </span>
            <span className={styles.billedEnd}>
                <span className={styles.billedHours}>{hours}</span>
                {!billed.locked && (
                    <SoloButton variant="secondary" size="control" onClick={() => setOpen(true)}>
                        {t('Change')}
                    </SoloButton>
                )}
            </span>
            {!billed.locked && (
                <BilledTimeDialog
                    clientId={billed.client_id}
                    period={billed.period}
                    task={taskId}
                    open={open}
                    onOpenChange={setOpen}
                    onSaved={() => router.reload({ only: LIVE_PROPS })}
                />
            )}
        </div>
    );
}

interface TimeSectionProps {
    task: { id: number; name: string; client?: string | null; is_reportable: boolean };
    time: TaskTime;
    billed?: TaskBilled | null;
    now: number;
}

/**
 * Figma: Task, the Time tile (1094:40618): today and the total with running timers counted in, the owner's own
 * Start or Stop, Log time and the report switch. A phone also keeps Start fixed at the bottom while no timer of
 * the owner's runs.
 */
export function TimeSection({ task, time, now, billed }: TimeSectionProps) {
    const { t } = useTranslation();
    const timer = useTimerActions();
    const [logging, setLogging] = useState(false);
    useHelperAction('log-time', useCallback(() => setLogging(true), []));
    const minutes = liveMinutes(time, now);
    const own = time.own_running;
    const running = time.running_since !== null;
    const start = () => void timer.start(task.id);

    return (
        <Tile kind="plain" aria-label={t('Time')}>
            <div className={styles.timeStats} data-fill="">
                <Stat
                    kind="tile"
                    tone={running ? 'over' : 'neutral'}
                    label={t('Today')}
                    value={statDuration(minutes.today, t)}
                    sub={
                        running
                            ? t('timer running since {{time}}', { time: time.running_since })
                            : minutes.today > 0
                              ? t('logged today')
                              : t('no entries')
                    }
                />
                <Stat kind="tile" label={t('In total')} value={statDuration(minutes.total, t)} sub={t('all entries')} />
            </div>
            <TileActions>
                {own !== null ? (
                    <SoloButton variant="stop" size="control" disabled={timer.pending} onClick={() => void timer.stop(own.id)}>
                        {t('Stop')}
                    </SoloButton>
                ) : (
                    <SoloButton size="control" disabled={timer.pending} onClick={start}>
                        {t('Start')}
                    </SoloButton>
                )}
                <SoloButton variant="secondary" size="control" onClick={() => setLogging(true)}>
                    {t('Log time')}
                </SoloButton>
            </TileActions>
            <ReportSwitch taskId={task.id} reportable={task.is_reportable} />
            {billed && <BilledRow taskId={task.id} billed={billed} />}
            {own === null && (
                <BottomAction phoneOnly>
                    <SoloButton size="control" disabled={timer.pending} onClick={start}>
                        {t('Start')}
                    </SoloButton>
                </BottomAction>
            )}
            <ManualTimeEntryDialog open={logging} onOpenChange={setLogging} task={task} onSaved={() => router.reload({ only: LIVE_PROPS })} />
        </Tile>
    );
}
