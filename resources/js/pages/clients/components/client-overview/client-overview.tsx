import { HoursBar } from '@/components/solo/hours-bar';
import { KvRow } from '@/components/solo/kv-row';
import { SoloButton, soloButtonClass, SoloButtonLink, Stat } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { useHelperAction } from '@/components/solo/shell/helper/actions';
import { Tile, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { InfoHint } from '@/components/ui/info-hint';
import { useLiveReload } from '@/hooks/use-live-reload';
import { monthGenitive, monthName, shortDate } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import clients from '@/routes/clients';
import monthClose from '@/routes/month-close';
import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { monthUrl } from '../client-billing/format';
import { type ClientCard, ClientFieldsDialog, MessageList, type MessageRow, ResendAction, useFailureReason } from '../client-fields';
import { hoursNumber, localeOf } from '../client-list/format';
import { CloseMonthDialog } from '../month-settlement';
import { RetainerDetailsDialog, RetainerDialog } from '../retainer-chip';
import styles from './client-overview.module.css';
import { nextPeriod } from './format';
import { OrderSection } from './order-section';
import { OrderWaitingRows } from './order-waiting';
import type { ClientOverview, OverviewHours, OverviewSettlement } from './types';

/** Where a report is generated: Billing on the report's month, or on the month the client owes next. */
export function reportFlowUrl(clientId: number, period?: string): string {
    return period !== undefined ? monthUrl(clientId, period) : clients.show({ client: clientId, tab: 'billing' }).url;
}

const openButton = soloButtonClass({ variant: 'secondary', size: 'control' });

/**
 * Figma: Client · Overview (1089:10002, phone 1089:10236). What waits for you, the way into Tasks and Journal, and
 * beside them the month's hours (or the order, or the account's own fields).
 */
/** `manage` is false for a member, who reads the client's text messages but cannot cancel or resend one. */
export function ClientOverviewTab({
    clientId,
    clientName = '',
    overview,
    retainerTerms = false,
    manage = true,
}: {
    clientId: number;
    clientName?: string;
    overview: ClientOverview;
    /** The header's `retainer_terms`: the Hours tile shows its Retainer button. */
    retainerTerms?: boolean;
    manage?: boolean;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const { report_due: report, month_close: close, doing_count: doing, journal } = overview;
    const orderWaiting = overview.order_waiting;
    const failedSms = (overview.sms ?? []).filter((row) => row.status === 'failed');
    const waiting = (report ? 1 : 0) + (close ? 1 : 0) + (orderWaiting?.task ? 1 : 0) + (orderWaiting?.invoice ? 1 : 0) + failedSms.length;
    const since = shortDate(journal.since, locale);
    useLiveReload(['overview'], !overview.sms_live);

    return (
        <TileGrid>
            <TileStack>
                <Tile kind="list">
                    <TileHeader label={t('Waiting for you')} count={waiting} />
                    {waiting === 0 ? (
                        <TileInset>
                            <TileNote>{t('Nothing is waiting.')}</TileNote>
                        </TileInset>
                    ) : (
                        <TileRows>
                            {failedSms.map((row) => (
                                <FailedSmsRow key={row.id} row={row} manage={manage} />
                            ))}
                            {orderWaiting && (
                                <OrderWaitingRows clientId={clientId} task={orderWaiting.task} invoice={orderWaiting.invoice} locale={locale} />
                            )}
                            {report && (
                                <Row
                                    title={t(reportTitle(report.status), { month: monthName(report.period, locale) })}
                                    href={report.report_id !== null ? clientReports.edit([clientId, report.report_id]).url : undefined}
                                    action={
                                        report.report_id === null ? (
                                            <SoloButtonLink href={reportFlowUrl(clientId, report.period)} variant="secondary" size="control">
                                                {t('Generate')}
                                            </SoloButtonLink>
                                        ) : undefined
                                    }
                                />
                            )}
                            {close && (
                                <Row
                                    title={t('{{month}} close, {{done}} of {{total}}', {
                                        month: monthGenitive(close.period, locale),
                                        done: close.done,
                                        total: close.total,
                                    })}
                                    href={monthClose.index({ query: { period: close.period } }).url}
                                />
                            )}
                        </TileRows>
                    )}
                </Tile>
                <Tile kind="list">
                    <TileHeader label={t('Tasks')} />
                    <TileRows>
                        <Row
                            title={doing > 0 ? t('{{count}} in progress', { count: doing }) : t('Nothing in progress')}
                            href={clients.show({ client: clientId, tab: 'tasks' }).url}
                        />
                    </TileRows>
                </Tile>
                <Tile kind="list">
                    <TileHeader label={t('Journal')} />
                    <TileRows>
                        <Row
                            title={
                                journal.since_today
                                    ? journal.count > 0
                                        ? t('{{count}} new since today', { count: journal.count })
                                        : t('Nothing new since today')
                                    : journal.count > 0
                                      ? t('{{count}} new since {{date}}', { count: journal.count, date: since })
                                      : t('Nothing new since {{date}}', { date: since })
                            }
                            href={clients.show({ client: clientId, tab: 'journal' }).url}
                        />
                    </TileRows>
                </Tile>
            </TileStack>
            <TileStack as="aside">
                {overview.card !== null ? (
                    <CardSection
                        clientId={clientId}
                        card={overview.card}
                        sms={overview.sms ?? []}
                        pending={overview.sms_pending ?? 0}
                        failed={overview.sms_failed ?? 0}
                        manage={manage}
                    />
                ) : overview.order !== null ? (
                    <OrderSection order={overview.order} locale={locale} />
                ) : (
                    overview.hours !== null &&
                    overview.settlement !== null && (
                        <HoursSection
                            clientId={clientId}
                            clientName={clientName}
                            hours={overview.hours}
                            settlement={overview.settlement}
                            retainer={overview.retainer}
                            terms={retainerTerms}
                            locale={locale}
                        />
                    )
                )}
            </TileStack>
        </TileGrid>
    );
}

/** A text that failed waits here until it is sent again or cancelled; why sits in the hint. */
function FailedSmsRow({ row, manage }: { row: MessageRow; manage: boolean }) {
    const { t } = useTranslation();
    const reason = useFailureReason();

    return (
        <Row
            title={
                <>
                    {t('Failed SMS: {{template}}', { template: row.template ?? t('Text message') })}{' '}
                    <InfoHint label={t('Why it failed')}>{reason(row)}</InfoHint>
                </>
            }
            action={manage ? <ResendAction message={row} className={openButton} /> : undefined}
        />
    );
}

/** The account's fields marked for Overview, the assignee, then what SMS failed or waits. */
function CardSection({
    clientId,
    card,
    sms,
    pending,
    failed,
    manage,
}: {
    clientId: number;
    card: ClientCard;
    sms: MessageRow[];
    pending: number;
    failed: number;
    manage: boolean;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const scheduled = t(card.sms_opt_out ? 'held_count' : 'scheduled_count', { count: pending });
    // On a phone the failed count alone, so the head leaves room for Journal.
    const head =
        failed > 0 ? (
            <>
                SMS · {t('failed_count', { count: failed })}
                {pending > 0 && <span className={styles.smsHeadRest}> · {scheduled}</span>}
            </>
        ) : (
            `SMS · ${scheduled}`
        );

    return (
        <>
            <Tile kind="plain">
                <TileHeader
                    label={t('Client fields')}
                    end={
                        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={() => setEditing(true)}>
                            {t('Change')}
                        </SoloButton>
                    }
                />
                <dl className={styles.ledger}>
                    {card.fields.map((field) => (
                        <KvRow key={field.key} layout="tile" label={field.label} tone={field.display === null ? 'muted' : undefined}>
                            {field.display ?? '—'}
                        </KvRow>
                    ))}
                    <KvRow layout="tile" label={t('Assignee')} tone={card.assignee === null ? 'muted' : undefined}>
                        {card.assignee?.name ?? '—'}
                    </KvRow>
                </dl>
                <ClientFieldsDialog clientId={clientId} card={card} open={editing} onOpenChange={setEditing} />
            </Tile>
            {sms.length > 0 && (
                <Tile kind="list">
                    <TileHeader
                        label={head}
                        end={
                            <SoloButtonLink href={clients.show({ client: clientId, tab: 'journal' }).url} variant="secondary" size="control">
                                {t('Journal')}
                            </SoloButtonLink>
                        }
                    />
                    <MessageList rows={sms} stacked manage={manage} />
                </Tile>
            )}
        </>
    );
}

function reportTitle(status: 'draft' | 'finalized' | null): string {
    if (status === 'draft') {
        return 'Report for {{month}}, draft';
    }

    return status === 'finalized' ? 'Report for {{month}}, ready to send' : 'Report for {{month}}';
}

/** Figma: Tile · Hours. The month's hours against what the client can draw on, the ledger, and the balance the next month starts with. */
function HoursSection({
    clientId,
    clientName,
    hours,
    settlement,
    retainer,
    terms,
    locale,
}: {
    clientId: number;
    clientName: string;
    hours: OverviewHours;
    settlement: OverviewSettlement;
    retainer: ClientOverview['retainer'];
    terms: boolean;
    locale: string;
}) {
    const { t } = useTranslation();
    const [closing, setClosing] = useState(false);
    const [dialog, setDialog] = useState<'details' | 'edit' | null>(null);
    useHelperAction(
        'retainer-terms',
        useCallback(() => {
            if (terms) setDialog('details');
        }, [terms]),
    );
    const behind = settlement.open_behind && hours.period === settlement.active_period;
    const h = (value: number, signed = false) => `${hoursNumber(value, locale, signed)} h`;
    const over = hours.remaining < 0;
    const month = monthName(hours.period, locale);
    const next = monthName(nextPeriod(hours.period), locale);
    const label = `${t('Hours')} · ${month}`;

    if (!hours.has_retainer) {
        return (
            <Tile kind="plain">
                <Stat kind="tile" label={label} value={h(hours.logged)} sub={t('No retainer, so every logged hour counts.')} />
            </Tile>
        );
    }

    return (
        <Tile kind="plain">
            <Stat
                kind="tile"
                label={label}
                value={h(hours.used)}
                sub={[
                    t('{{hours}} h available', { hours: hoursNumber(hours.available, locale) }),
                    over
                        ? t('{{hours}} h over', { hours: hoursNumber(-hours.remaining, locale) })
                        : t('{{hours}} h free', { hours: hoursNumber(hours.remaining, locale) }),
                ].join(' · ')}
            />
            <div data-fill="">
                <HoursBar used={hours.used} available={hours.available} size="tile" />
            </div>
            <dl className={styles.ledger}>
                <KvRow layout="tile" figure label={t('Package')}>
                    {h(hours.contracted ?? 0, true)}
                </KvRow>
                <KvRow layout="tile" figure label={t('From previous months')}>
                    {h(hours.carried, true)}
                </KvRow>
                {hours.forfeited > 0 && (
                    <KvRow layout="tile" figure label={t('Over the rollover cap')}>
                        {h(-hours.forfeited, true)}
                    </KvRow>
                )}
                <KvRow layout="tile" figure label={t('Worked')}>
                    {h(-hours.used, true)}
                </KvRow>
                <KvRow layout="tile" figure label={t('Balance for {{month}}', { month: next })} tone={over ? 'over' : undefined}>
                    {h(hours.remaining, true)}
                </KvRow>
            </dl>
            {behind && (
                <>
                    <TileNote>
                        {t('It is {{current}} now · {{month}} still open', {
                            current: monthName(settlement.current_period, locale),
                            month: monthName(settlement.active_period, locale),
                        })}
                    </TileNote>
                    <CloseMonthDialog clientId={clientId} period={settlement.active_period} open={closing} onOpenChange={setClosing} />
                </>
            )}
            <div className={styles.hoursActions}>
                {behind && (
                    <SoloButton variant="secondary" size="control" onClick={() => setClosing(true)}>
                        {t('Close the month')}
                    </SoloButton>
                )}
                {terms && (
                    <SoloButton variant="secondary" size="control" onClick={() => setDialog('details')}>
                        {t('Retainer')}
                    </SoloButton>
                )}
            </div>
            {retainer && (
                <>
                    <RetainerDetailsDialog
                        open={dialog === 'details'}
                        onOpenChange={(open) => setDialog(open ? 'details' : null)}
                        clientName={clientName}
                        retainers={retainer.positions}
                        activeIds={retainer.active_ids}
                        onEdit={() => setDialog('edit')}
                    />
                    <RetainerDialog
                        open={dialog === 'edit'}
                        onOpenChange={(open) => setDialog(open ? 'edit' : null)}
                        clientId={clientId}
                        clientCurrency={retainer.currency}
                        retainers={retainer.positions}
                        activeIds={retainer.active_ids}
                        sheet
                    />
                </>
            )}
        </Tile>
    );
}
