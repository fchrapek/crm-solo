import { MarkdownView } from '@/components/markdown-view';
import { Chip } from '@/components/solo/chip';
import { KvRow } from '@/components/solo/kv-row';
import { Notice } from '@/components/solo/notice';
import { SoloButton, SoloButtonLink, Stat } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Row } from '@/components/solo/row';
import { useHelperAction } from '@/components/solo/shell/helper/actions';
import { Tile, TileActions, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { capitalize, monthGenitive, monthName, shortDate } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import clientSummaryEmails from '@/routes/client-summary-emails';
import monthClose from '@/routes/month-close';
import { router } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { emailStep, type SummaryEmailState } from '../../../month-close/steps';
import { SummaryEmailDialog } from '../../../month-close/summary-email-dialog';
import { hoursNumber, hoursTerms } from '../client-list/format';
import { nextPeriod } from '../client-overview/format';
import { BaselineDialog } from '../details-tab/baseline-dialog';
import { GenerateReportDialog } from '../generate-report-dialog';
import { taskTip } from './billed-format';
import { readOnlyLine } from './billed-time-dialog';
import styles from './client-billing.module.css';
import { billedCaption, moneyOf, monthNote, positionSummary, reportChip, reportState } from './format';
import { balanceSub, billedSub, carriedCaption, carriedOf } from './month-figures';
import type { BilledTaskRow, BillingMonth, ReportBaseline, RetainerBilling } from './types';

const hours = (value: number, locale: string, signed = false) => `${hoursNumber(value, locale, signed)}\u00a0h`;

export interface StatTile {
    label: string;
    value: string;
    tone: 'neutral' | 'over';
    /** What the (i) beside the label says: the caption a tile used to carry under its figure. */
    hint: string;
}

/** The month's number tiles, which add up to the balance; without a package, real and billed time only. */
export function statTiles(month: BillingMonth, running: boolean, locale: string, t: TFunction): StatTile[] {
    const figures = month.figures;
    const billed = figures.billed ?? figures.used;

    if (!figures.has_retainer) {
        return [
            {
                label: t('Real time'),
                value: hours(figures.real, locale),
                tone: 'neutral',
                hint: t(running ? 'stat_real_so_far' : 'stat_real_logged'),
            },
            { label: t('Billed time'), value: hours(billed, locale), tone: 'neutral', hint: billedCaption(figures, locale, t) },
        ];
    }
    const carried = carriedOf(figures);

    return [
        {
            label: t('Package'),
            value: figures.contracted !== null ? `${hoursTerms(figures.contracted, locale)}\u00a0h` : '·',
            tone: 'neutral',
            hint: t('stat_per_month'),
        },
        {
            label: t('From previous months'),
            value: hours(carried, locale, true),
            tone: carried < 0 ? 'over' : 'neutral',
            hint: carriedCaption(figures, locale, t),
        },
        { label: t('Billed time'), value: hours(billed, locale), tone: 'neutral', hint: billedSub(figures, locale, t) },
        {
            label: figures.closed ? t('stat_balance_opening', { month: monthGenitive(nextPeriod(month.period), locale) }) : t('Balance'),
            value: hours(figures.closing, locale, true),
            tone: figures.closing < 0 ? 'over' : 'neutral',
            hint: balanceSub(month.period, figures, locale, t),
        },
    ];
}

/** Figma 8C (1461:768): the number tiles, the figure alone with its caption behind the (i) by the label, opened by a tap on a phone. */
export function MonthStats({ month, running, locale }: { month: BillingMonth; running: boolean; locale: string }) {
    const { t } = useTranslation();
    const tiles = statTiles(month, running, locale, t);

    return (
        <div className={styles.stats} data-count={tiles.length}>
            {tiles.map((tile) => (
                <Tile key={tile.label} kind="plain" as="div">
                    <Stat kind="tile" label={tile.label} value={tile.value} tone={tile.tone} hint={{ label: tile.label, text: tile.hint }} />
                </Tile>
            ))}
        </div>
    );
}

export function taskTitle(task: Pick<BilledTaskRow, 'task'>, t: (key: string) => string): string {
    return task.task !== '' ? task.task : t('Time without a task');
}

/** Figma: Tasks of September, the month's tasks in both values, with the way back to a draft when the report locks them. */
export function MonthTasks({
    month,
    locale,
    onChange,
    onReopen,
}: {
    month: BillingMonth;
    locale: string;
    onChange: (task: BilledTaskRow) => void;
    /** Back to a draft for the report that locks the month, when the page has one to offer. */
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
            {!editable && figures.closure_id === null && (
                <TileInset className={styles.locked}>
                    <Notice kind="lock">{readOnlyLine(figures, locale, t)}</Notice>
                    {onReopen !== undefined && (
                        <SoloButton variant="secondary" size="control" onClick={onReopen}>
                            {t('Reopen the report')}
                        </SoloButton>
                    )}
                </TileInset>
            )}
        </Tile>
    );
}

/** Figma: Report, the month's report in its states: to generate, a draft, to recalculate, ready to send, sent. */
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
    const generates = state === 'running' || state === 'to_generate';
    useHelperAction(
        'client-report',
        useCallback(() => {
            if (generates) setGenerating(true);
        }, [generates]),
    );

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
                {generates && (
                    <SoloButton
                        variant={state === 'running' ? 'secondary' : 'primary'}
                        size="control"
                        aria-haspopup="dialog"
                        onClick={() => setGenerating(true)}
                    >
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
            <GenerateReportDialog
                open={generating}
                onOpenChange={setGenerating}
                clientId={clientId}
                composers={month.composers}
                period={month.period}
            />
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
    const notice =
        status === 'test_sent'
            ? t('email_notice_test')
            : status === 'failed'
              ? t('email_notice_failed')
              : status === 'unconfirmed'
                ? t('email_notice_unconfirmed')
                : undefined;

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
            ? router.post(
                  clientSummaryEmails.store({ client: clientId, period }).url,
                  {},
                  { preserveScroll: true, preserveState: true, onSuccess: () => setOpen(true) },
              )
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

/** The month's settlement as its chip reads: closed, in progress, or ended and still open; null for a month outside settlement. */
export function monthChip(
    month: Pick<BillingMonth, 'settlement'>,
    running: boolean,
    t: TFunction,
): { status: 'done' | 'open'; label: string } | null {
    if (month.settlement.state === 'closed') {
        return { status: 'done', label: t('ledger_closed_undated') };
    }
    if (running) {
        return { status: 'open', label: t('month_running') };
    }

    return month.settlement.state === 'open' ? { status: 'open', label: t('ledger_open') } : null;
}

/** Figma 8C: Month status, how the month stands under its name with its close, reopen and checklist. */
export function MonthStatus({
    clientId,
    month,
    running,
    current,
    earlier,
    locale,
    onClose,
    onReopen,
}: {
    clientId: number;
    month: BillingMonth;
    running: boolean;
    /** The month in progress. */
    current: string;
    /** An earlier month still open, which closes first. */
    earlier: string | null;
    locale: string;
    onClose: () => void;
    onReopen: () => void;
}) {
    const { t } = useTranslation();
    // Closing and reopening follow the month's settlement, not its package: a month can owe a close without a retainer.
    const closable = !running && month.settlement.state === 'open';
    const reopenable = month.settlement.closure_id !== null;
    const chip = monthChip(month, running, t);
    const checklist = month.checklist;
    const notice =
        earlier === null
            ? null
            : running
              ? t('month_tile_close_behind', { month: monthName(earlier, locale) })
              : closable
                ? t('month_close_earlier_first', {
                      earlier: capitalize(monthName(earlier, locale)),
                      earlierName: monthName(earlier, locale),
                      month: monthName(month.period, locale),
                  })
                : null;

    return (
        <div className={styles.status}>
            <div className={styles.statusLine}>
                <p className={styles.statusNote}>{monthNote(month, current, locale, t)}</p>
                {chip !== null && <Chip status={chip.status}>{chip.label}</Chip>}
                {closable && (
                    <SoloButton
                        variant="secondary"
                        size="control"
                        aria-haspopup="dialog"
                        disabled={notice !== null}
                        onClick={onClose}
                        className={styles.statusButton}
                    >
                        {t('Close the month')}
                    </SoloButton>
                )}
                {reopenable && (
                    <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onReopen} className={styles.statusButton}>
                        {t('Reopen the month')}
                    </SoloButton>
                )}
                {checklist !== null && (
                    <SoloButtonLink
                        variant="quiet"
                        size="control"
                        href={monthClose.show(clientId, { query: { period: month.period } }).url}
                        className={styles.statusButton}
                    >
                        {t('checklist_progress', { done: checklist.done, total: checklist.total })}
                    </SoloButtonLink>
                )}
            </div>
            {notice !== null && <Notice kind="warn">{notice}</Notice>}
        </div>
    );
}

/** Figma 11A: Report baseline, one row per section, read whole in Show and changed in Edit. */
export function BaselineTile({ clientId, baseline }: { clientId: number; baseline: ReportBaseline }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<'show' | 'edit' | null>(null);
    const written = baseline.markdown !== null && baseline.markdown.trim() !== '';

    return (
        <Tile kind="plain">
            <TileHeader
                label={t('Report baseline')}
                count={written ? baseline.sections.length : undefined}
                end={
                    written ? (
                        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={() => setDialog('show')}>
                            {t('Show')}
                        </SoloButton>
                    ) : undefined
                }
            />
            {written ? (
                <ul className={styles.baselineRows} data-fill="">
                    {baseline.sections.map((section, index) => (
                        <Row
                            key={index}
                            rule={index > 0}
                            wrap
                            title={section.title ?? t('Without a heading')}
                            meta={section.items > 0 ? t('baseline_items', { count: section.items }) : undefined}
                        />
                    ))}
                </ul>
            ) : (
                <TileNote>{t('Not set')}</TileNote>
            )}
            <TileActions>
                <SoloButton variant="quiet" size="control" aria-haspopup="dialog" onClick={() => setDialog('edit')}>
                    {t('Edit')}
                </SoloButton>
            </TileActions>
            <ResponsiveDialog
                open={dialog === 'show'}
                onOpenChange={(open) => setDialog(open ? 'show' : null)}
                title={t('Report baseline')}
                onSubmit={(event) => event.preventDefault()}
                actions={(size) => (
                    <>
                        <SoloButton variant="quiet" size={size === 'touch' ? 'touch' : 'control'} onClick={() => setDialog('edit')}>
                            {t('Edit')}
                        </SoloButton>
                        <SoloButton variant="ink" size={size === 'touch' ? 'touch' : 'control'} onClick={() => setDialog(null)}>
                            {t('Close')}
                        </SoloButton>
                    </>
                )}
            >
                <div className={styles.baselineText}>
                    <MarkdownView>{baseline.markdown ?? ''}</MarkdownView>
                </div>
            </ResponsiveDialog>
            <BaselineDialog
                clientId={clientId}
                baseline={baseline.markdown}
                open={dialog === 'edit'}
                onOpenChange={(open) => setDialog(open ? 'edit' : null)}
            />
        </Tile>
    );
}

/** Figma: Retainer. The positions in force today, what happens past the package and since when; Change opens the retainer dialog. */
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
                        <KvRow key={position.id} layout="tile" figure label={positionSummary(position, several, locale, t)}>
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
