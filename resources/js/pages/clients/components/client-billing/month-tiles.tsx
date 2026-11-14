import { Chip } from '@/components/solo/chip';
import { KvRow } from '@/components/solo/kv-row';
import { Notice } from '@/components/solo/notice';
import { SoloButton, SoloButtonLink, Stat } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { Tile, TileActions, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { monthGenitive, monthName, shortDate } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import clientSummaryEmails from '@/routes/client-summary-emails';
import monthClose from '@/routes/month-close';
import { router } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { emailStep, type SummaryEmailState } from '../../../month-close/steps';
import { SummaryEmailDialog } from '../../../month-close/summary-email-dialog';
import { hoursNumber, hoursTerms } from '../client-list/format';
import { nextPeriod } from '../client-overview/format';
import { GenerateReportDialog } from '../generate-report-dialog';
import { taskTip } from './billed-format';
import { readOnlyLine } from './billed-time-dialog';
import styles from './client-billing.module.css';
import { balanceCaption, billedCaption, forfeitedOf, moneyOf, positionSummary, reportChip, reportState, usedSourceKey } from './format';
import type { BilledTaskRow, BillingMonth, LedgerRow, RetainerBilling } from './types';

const hours = (value: number, locale: string, signed = false) => `${hoursNumber(value, locale, signed)}\u00a0h`;

/** Figma: the four number tiles of the month. A month outside the bank (no package) has no pool and no balance to show. */
export function MonthStats({ month, running, locale }: { month: BillingMonth; running: boolean; locale: string }) {
    const { t } = useTranslation();
    const figures = month.figures;

    return (
        <div className={styles.stats} data-count={figures.has_retainer ? 4 : 2}>
            {figures.has_retainer && (
                <Tile kind="plain" as="div">
                    <Stat
                        kind="tile"
                        label={t('Package')}
                        value={figures.contracted !== null ? `${hoursTerms(figures.contracted, locale)}\u00a0h` : '·'}
                        sub={t('stat_per_month')}
                    />
                </Tile>
            )}
            <Tile kind="plain" as="div">
                <Stat kind="tile" label={t('Real time')} value={hours(figures.real, locale)} sub={t(running ? 'stat_real_so_far' : 'stat_real_logged')} />
            </Tile>
            <Tile kind="plain" as="div">
                <Stat kind="tile" label={t('Billed time')} value={hours(figures.billed ?? figures.used, locale)} sub={billedCaption(figures, locale, t)} />
            </Tile>
            {figures.has_retainer && (
                <Tile kind="plain" as="div">
                    <Stat
                        kind="tile"
                        label={t(running ? 'stat_balance_today' : 'stat_balance_after')}
                        value={hours(figures.closing, locale, true)}
                        tone={figures.closing < 0 ? 'over' : 'neutral'}
                        sub={balanceCaption(figures, locale, t)}
                    />
                </Tile>
            )}
        </div>
    );
}

export function taskTitle(task: Pick<BilledTaskRow, 'task'>, t: (key: string) => string): string {
    return task.task !== '' ? task.task : t('Time without a task');
}

/** Figma: Zadania września. The month's tasks in both values; Zmień opens the task's billed time, and a month that cannot change says what locks it. */
export function MonthTasks({
    month,
    locale,
    onChange,
    onReopen,
}: {
    month: BillingMonth;
    locale: string;
    onChange: (task: BilledTaskRow) => void;
    /** The existing reopen of what locks the month, when the page has one to offer. */
    onReopen?: () => void;
}) {
    const { t } = useTranslation();
    const figures = month.figures;
    const editable = figures.refusal === null;
    const both = (real: number, billed: number) => `${hours(real, locale)} · ${hours(billed, locale)}`;

    return (
        <Tile kind="list">
            <TileHeader
                label={t('Tasks of {{month}}', { month: monthGenitive(month.period, locale) })}
                count={figures.tasks.length > 0 ? figures.tasks.length : undefined}
                end={
                    <span className={styles.caption}>
                        <span className={styles.wideLabel}>{t('billed_tasks_meta')}</span>
                        <span className={styles.phoneLabel}>{t('real · billed')}</span>
                    </span>
                }
            />
            {figures.tasks.length === 0 ? (
                <TileInset>
                    <TileNote>{figures.real > 0 ? t('month_tasks_frozen') : t('No time on report tasks this month yet.')}</TileNote>
                </TileInset>
            ) : (
                <TileRows>
                    {figures.tasks.map((task, index) => {
                        const tip = taskTip(task, locale, t);

                        return (
                            <Row
                                key={task.task_id}
                                rule={index > 0}
                                title={taskTitle(task, t)}
                                time={
                                    <>
                                        {both(task.real, task.billed)}
                                        {tip !== null && (
                                            <>
                                                {' '}
                                                <InfoHint label={t('Your figure')}>{tip}</InfoHint>
                                            </>
                                        )}
                                    </>
                                }
                                action={
                                    editable ? (
                                        <SoloButton
                                            variant="secondary"
                                            size="control"
                                            aria-haspopup="dialog"
                                            aria-label={`${t('Change billed time')}: ${taskTitle(task, t)}`}
                                            onClick={() => onChange(task)}
                                        >
                                            <Icon name="pencil" size={16} className={styles.phoneLabel} />
                                            <span className={styles.wideLabel}>{t('Change')}</span>
                                        </SoloButton>
                                    ) : undefined
                                }
                            />
                        );
                    })}
                    <Row title={t('Total')} time={both(figures.real, figures.billed ?? figures.used)} className={styles.total} />
                </TileRows>
            )}
            {!editable && (
                <TileInset className={styles.locked}>
                    <Notice kind="lock">{readOnlyLine(figures, locale, t)}</Notice>
                    {onReopen !== undefined && (
                        <SoloButton variant="secondary" size="control" onClick={onReopen}>
                            {figures.closure_id !== null ? t('Reopen this month') : t('Reopen the report')}
                        </SoloButton>
                    )}
                </TileInset>
            )}
        </Tile>
    );
}

/** Figma: Raport, the month's report in its states: to generate, a draft, to recalculate, ready to send, sent. */
export function ReportTile({ clientId, month, running, locale }: { clientId: number; month: BillingMonth; running: boolean; locale: string }) {
    const { t } = useTranslation();
    const [generating, setGenerating] = useState(false);
    const [confirm, setConfirm] = useState<'regenerate' | 'sent' | null>(null);
    const [busy, setBusy] = useState(false);
    const report = month.report;
    const state = reportState(month, running);
    const chip = reportChip(month, running, locale, t);
    const name = monthName(month.period, locale);
    const open = report !== null ? clientReports.edit([clientId, report.id]).url : '';

    function regenerate() {
        if (report === null) {
            return;
        }
        setConfirm(null);
        setBusy(true);
        // A hand-written report's key has no composer behind it, so the server picks its default.
        const runnable = month.composers.some((composer) => composer.key === report.composer_key);
        router.post(clientReports.regenerate([clientId, report.id]).url, runnable ? { composer_key: report.composer_key } : {}, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
            onSuccess: (page) => {
                if (!(page.props.flash as { error?: string | null } | undefined)?.error) {
                    toast.success(t('Report regenerated.'));
                }
            },
        });
    }

    function markSent() {
        if (report === null) {
            return;
        }
        setConfirm(null);
        router.post(clientReports.markSent([clientId, report.id]).url, {}, { preserveScroll: true });
    }

    return (
        <Tile kind="plain">
            <TileHeader label={t('Report')} end={<Chip status={chip.status}>{chip.label}</Chip>} />
            <p className={styles.tileTitle}>{t('Report for {{month}}', { month: name })}</p>
            {state === 'running' && <TileNote>{t('report_tile_running')}</TileNote>}
            {state === 'to_generate' && (
                <TileNote>{t('report_tile_to_generate', { hours: hoursNumber(month.figures.billed ?? month.figures.used, locale) })}</TileNote>
            )}
            {state === 'stale' && <Notice kind="warn">{t('report_tile_stale')}</Notice>}
            {state === 'finalized' && <Notice kind="lock">{t('report_tile_finalized')}</Notice>}
            <TileActions>
                {(state === 'running' || state === 'to_generate') && (
                    <SoloButton variant={state === 'running' ? 'secondary' : 'primary'} size="control" aria-haspopup="dialog" onClick={() => setGenerating(true)}>
                        {t('Generate a report')}
                    </SoloButton>
                )}
                {state === 'draft' && (
                    <>
                        <SoloButtonLink variant="primary" size="control" href={open}>
                            {t('Open report')}
                        </SoloButtonLink>
                        <SoloButton variant="quiet" size="control" disabled={busy} onClick={() => setConfirm('regenerate')}>
                            {busy ? t('Regenerating…') : t('Regenerate')}
                        </SoloButton>
                    </>
                )}
                {state === 'stale' && (
                    <>
                        <SoloButton variant="primary" size="control" disabled={busy} onClick={() => setConfirm('regenerate')}>
                            {busy ? t('Regenerating…') : t('Regenerate')}
                        </SoloButton>
                        <SoloButtonLink variant="quiet" size="control" href={open}>
                            {t('Open report')}
                        </SoloButtonLink>
                    </>
                )}
                {state === 'finalized' && (
                    <>
                        <SoloButton variant="primary" size="control" onClick={() => setConfirm('sent')}>
                            {t('Mark as sent')}
                        </SoloButton>
                        <SoloButtonLink variant="secondary" size="control" href={open}>
                            {t('Open report')}
                        </SoloButtonLink>
                    </>
                )}
                {state === 'sent' && (
                    <SoloButtonLink variant="secondary" size="control" href={open}>
                        {t('Open report')}
                    </SoloButtonLink>
                )}
            </TileActions>
            <GenerateReportDialog open={generating} onOpenChange={setGenerating} clientId={clientId} composers={month.composers} period={month.period} />
            <ConfirmDialog
                open={confirm === 'regenerate'}
                onOpenChange={(next) => !next && setConfirm(null)}
                title={t('Regenerate the report?')}
                description={t(
                    'Your current edits to the body will be archived as a revision and replaced. The hours snapshot also refreshes from current time entries.',
                )}
                confirmLabel={t('Regenerate')}
                onConfirm={regenerate}
            />
            <ConfirmDialog
                open={confirm === 'sent'}
                onOpenChange={(next) => !next && setConfirm(null)}
                title={t('Mark the report as sent?')}
                description={t('Records today as the day the report went to the client. Reopening it as a draft clears that date.')}
                confirmLabel={t('Mark as sent')}
                onConfirm={markSent}
            />
        </Tile>
    );
}

interface EmailLook {
    chip: { status: 'waiting' | 'done' | 'open'; label: string };
    action: string;
    primary?: boolean;
    notice?: string;
}

/** The email tile's chip, button and notice for a month's summary email, worded as the month close's step row words them; null where the month has no email to prepare. */
export function emailLook(state: SummaryEmailState | undefined, due: boolean, locale: string, t: TFunction): EmailLook | null {
    const status = state?.status ?? null;
    if (state === undefined || status === null) {
        return due ? { chip: { status: 'waiting', label: t('email_chip_to_prepare') }, action: t('Prepare draft'), primary: true } : null;
    }

    const step = emailStep(state, locale, t);
    const notice = status === 'test_sent' ? t('email_notice_test') : status === 'failed' ? t('email_notice_failed') : status === 'unconfirmed' ? t('email_notice_unconfirmed') : undefined;

    return {
        chip: { status: status === 'sent' ? 'done' : step.tone !== undefined ? 'waiting' : 'open', label: step.meta },
        action: step.action,
        notice,
    };
}

/** Figma: E-mail, the month's summary email for a client who gets one instead of a report. The dialog is the month close's. */
export function EmailTile({
    clientId,
    clientName,
    period,
    state,
    due,
    locale,
}: {
    clientId: number;
    clientName: string;
    period: string;
    state: SummaryEmailState | undefined;
    /** The month ended and is not closed: an email not started yet can be prepared. */
    due: boolean;
    locale: string;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const look = emailLook(state, due, locale, t);
    if (look === null) {
        return null;
    }

    const start = () =>
        state?.status === undefined || state.status === null
            ? router.post(clientSummaryEmails.store({ client: clientId, period }).url, {}, { preserveScroll: true, preserveState: true, onSuccess: () => setOpen(true) })
            : setOpen(true);

    return (
        <Tile kind="plain">
            <TileHeader label={t('E-mail')} end={<Chip status={look.chip.status}>{look.chip.label}</Chip>} />
            <p className={styles.tileTitle}>{t('Email for {{month}}', { month: monthName(period, locale) })}</p>
            {look.notice !== undefined && <Notice kind="warn">{look.notice}</Notice>}
            <TileActions>
                <SoloButton variant={look.primary ? 'primary' : 'secondary'} size="control" aria-haspopup="dialog" onClick={start}>
                    {look.action}
                </SoloButton>
            </TileActions>
            <SummaryEmailDialog
                clientId={clientId}
                clientName={clientName}
                period={period}
                open={open}
                onClose={() => setOpen(false)}
                onChanged={() => router.reload({ only: ['billing_emails'] })}
            />
        </Tile>
    );
}

/** Figma: Miesiąc. The month's lines of the hours bank, then closing it, reopening it and the way to its checklist. */
export function MonthTile({
    clientId,
    month,
    row,
    running,
    behind,
    locale,
    onClose,
    onReopen,
}: {
    clientId: number;
    month: BillingMonth;
    /** The month's row of the bank when the loaded pages hold it: it says whether the month owes a close and can reopen. */
    row: LedgerRow | undefined;
    running: boolean;
    /** The month still open behind the one in progress. */
    behind: string | null;
    locale: string;
    onClose: () => void;
    onReopen: () => void;
}) {
    const { t } = useTranslation();
    const figures = month.figures;
    const forfeited = forfeitedOf(figures);
    const source = usedSourceKey(month);
    const closable = !running && !figures.closed && row?.settlement === 'open';
    const reopenable = figures.closed && row !== undefined && row.closure_id !== null;
    const chip = figures.closed
        ? { status: 'done' as const, label: figures.closed_on !== null ? t('ledger_closed', { date: shortDate(figures.closed_on, locale) }) : t('ledger_closed_undated') }
        : running
          ? { status: 'open' as const, label: t('month_running') }
          : row?.settlement === 'open'
            ? { status: 'open' as const, label: t('ledger_open') }
            : null;
    const checklist = month.checklist;

    return (
        <Tile kind="plain" className={styles.fill}>
            <TileHeader label={t('Month')} end={chip !== null ? <Chip status={chip.status}>{chip.label}</Chip> : undefined} />
            {figures.has_retainer ? (
                <dl className={styles.ledger}>
                    <KvRow layout="tile" label={t('Package')}>
                        {hours(figures.contracted ?? 0, locale, true)}
                    </KvRow>
                    <KvRow layout="tile" label={t('From previous months')} tone={figures.opening < 0 ? 'over' : undefined}>
                        {hours(figures.opening, locale, true)}
                    </KvRow>
                    {forfeited > 0 && (
                        <KvRow layout="tile" label={t('Over the rollover cap')}>
                            {hours(-forfeited, locale, true)}
                        </KvRow>
                    )}
                    <KvRow layout="tile" label={source !== null ? `${t('Worked')} · ${t(source)}` : t('Worked')}>
                        {hours(-figures.used, locale, true)}
                    </KvRow>
                    <KvRow
                        layout="tile"
                        total
                        label={running ? t('stat_balance_today') : t('Balance → opening of {{month}}', { month: monthGenitive(nextPeriod(month.period), locale) })}
                        tone={figures.closing < 0 ? 'over' : undefined}
                    >
                        {hours(figures.closing, locale, true)}
                    </KvRow>
                </dl>
            ) : (
                <TileNote>{t('No retainer, so every logged hour counts.')}</TileNote>
            )}
            {running && figures.has_retainer && (
                <TileNote>
                    {behind !== null
                        ? t('month_tile_close_behind', { month: monthName(behind, locale) })
                        : t('month_tile_running')}
                </TileNote>
            )}
            {figures.closed && <TileNote>{t('month_tile_frozen')}</TileNote>}
            {(closable || reopenable || checklist !== null) && (
                <TileActions className={styles.atEnd}>
                    {closable && (
                        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onClose}>
                            {t('Close the month')}
                        </SoloButton>
                    )}
                    {reopenable && (
                        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onReopen}>
                            {t('Reopen the month')}
                        </SoloButton>
                    )}
                    {checklist !== null && (
                        <SoloButtonLink variant="quiet" size="control" href={monthClose.show(clientId, { query: { period: month.period } }).url}>
                            {t('checklist_progress', { done: checklist.done, total: checklist.total })}
                        </SoloButtonLink>
                    )}
                </TileActions>
            )}
        </Tile>
    );
}

/** Figma: Abonament. The positions in force today, what happens past the package and since when; Zmień opens the retainer dialog. */
export function RetainerTile({ retainer, locale, onChange }: { retainer: RetainerBilling['retainer']; locale: string; onChange: () => void }) {
    const { t } = useTranslation();
    const several = retainer.active.length > 1;
    const since = retainer.positions
        .filter((position) => retainer.active_ids.includes(position.id))
        .map((position) => position.effective_from)
        .sort()[0];

    return (
        <Tile kind="plain">
            <TileHeader
                label={t('Retainer')}
                end={
                    <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onChange}>
                        {retainer.positions.length > 0 ? t('Change') : t('Add')}
                    </SoloButton>
                }
            />
            {retainer.active.length === 0 ? (
                <TileNote>{t('No retainer, so every logged hour counts.')}</TileNote>
            ) : (
                <dl className={styles.ledger}>
                    {retainer.active.map((position) => (
                        <KvRow key={position.id} layout="tile" label={positionSummary(position, several, locale, t)}>
                            {position.monthly_fee !== null
                                ? t('{{money}} net / mo', { money: moneyOf(position.monthly_fee, position.currency, locale, true) })
                                : '·'}
                        </KvRow>
                    ))}
                    <KvRow layout="tile" label={t('Over the package')}>
                        {t('retainer_over_package')}
                    </KvRow>
                    {since !== undefined && (
                        <KvRow layout="tile" label={t('From')}>
                            {shortDate(since, locale)}
                        </KvRow>
                    )}
                </dl>
            )}
        </Tile>
    );
}
