import { BottomSheet } from '@/components/solo/bottom-sheet';
import { HeaderAction } from '@/components/solo/client-header';
import { HoursBar } from '@/components/solo/hours-bar';
import { KvRow } from '@/components/solo/kv-row';
import { BodyLink, SectionAction, SectionHead, SectionLink, SoloButton } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { WaitingRow } from '@/components/solo/waiting-row';
import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { useLiveReload } from '@/hooks/use-live-reload';
import { monthGenitive, monthName, shortDate } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import clients from '@/routes/clients';
import monthClose from '@/routes/month-close';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { type ClientCard, ClientFieldsDialog, MessageList, type MessageRow, ResendAction, useFailureReason } from '../client-fields';
import { hoursNumber, localeOf } from '../client-list/format';
import { CloseMonthDialog } from '../month-settlement';
import styles from './client-overview.module.css';
import { nextPeriod } from './format';
import { OrderSection } from './order-section';
import { OrderWaitingRows } from './order-waiting';
import type { ClientOverview, OverviewHours, OverviewSettlement } from './types';

/** Where the report flow lives until Rozliczenia takes it over. */
export function reportFlowUrl(clientId: number): string {
    return `${clients.edit(clientId).url}#reports`;
}

/** Figma: Klient / Przegląd. The agent questions of Czeka na ciebie wait for `crm ask`. */
export function ClientOverviewTab({ clientId, overview }: { clientId: number; overview: ClientOverview }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const { report_due: report, month_close: close, doing_count: doing, journal } = overview;
    const orderWaiting = overview.order_waiting;
    const failedSms = (overview.sms ?? []).filter((row) => row.status === 'failed');
    const waiting = (report ? 1 : 0) + (close ? 1 : 0) + (orderWaiting?.task ? 1 : 0) + (orderWaiting?.invoice ? 1 : 0) + failedSms.length;
    const since = shortDate(journal.since, locale);
    useLiveReload(['overview'], !overview.sms_live);

    return (
        <div className={`${splitClass} ${styles.body}`}>
            <section className={`${splitMainClass} ${styles.main}`}>
                <SectionHead kind="body" phone="plain" label={t('Waiting for you')} meta={String(waiting)} />
                {waiting === 0 ? (
                    <p className={styles.empty}>{t('Nothing is waiting.')}</p>
                ) : (
                    <ul className={styles.waiting}>
                        {failedSms.map((row) => (
                            <FailedSmsRow key={row.id} row={row} />
                        ))}
                        {orderWaiting && (
                            <OrderWaitingRows clientId={clientId} task={orderWaiting.task} invoice={orderWaiting.invoice} locale={locale} />
                        )}
                        {report && (
                            <WaitingRow
                                title={t(reportTitle(report.status), { month: monthName(report.period, locale) })}
                                link={{
                                    href: report.report_id !== null ? clientReports.edit([clientId, report.report_id]).url : reportFlowUrl(clientId),
                                    label: report.report_id !== null ? t('Open') : t('Generate'),
                                }}
                            />
                        )}
                        {close && (
                            <WaitingRow
                                title={t('{{month}} close, {{done}} of {{total}}', {
                                    month: monthGenitive(close.period, locale),
                                    done: close.done,
                                    total: close.total,
                                })}
                                link={{ href: monthClose.index({ query: { period: close.period } }).url, label: t('Open') }}
                            />
                        )}
                    </ul>
                )}
                <div className={styles.links}>
                    <BodyLink href={clients.show({ client: clientId, tab: 'zadania' }).url}>
                        {doing > 0 ? t('{{count}} in progress', { count: doing }) : t('Nothing in progress')} →
                    </BodyLink>
                    <BodyLink href={clients.show({ client: clientId, tab: 'dziennik' }).url}>
                        {journal.since_today
                            ? journal.count > 0
                                ? t('{{count}} new since today', { count: journal.count })
                                : t('Nothing new since today')
                            : journal.count > 0
                              ? t('{{count}} new since {{date}}', { count: journal.count, date: since })
                              : t('Nothing new since {{date}}', { date: since })}{' '}
                        →
                    </BodyLink>
                </div>
            </section>

            {overview.card !== null ? (
                <CardSection
                    clientId={clientId}
                    card={overview.card}
                    sms={overview.sms ?? []}
                    pending={overview.sms_pending ?? 0}
                    failed={overview.sms_failed ?? 0}
                />
            ) : overview.order !== null ? (
                <OrderSection order={overview.order} locale={locale} />
            ) : (
                overview.hours !== null &&
                overview.settlement !== null && (
                    <HoursSection clientId={clientId} hours={overview.hours} settlement={overview.settlement} locale={locale} />
                )
            )}
        </div>
    );
}

/** A text that failed waits here until it is sent again or cancelled; why sits in the hint. */
function FailedSmsRow({ row }: { row: MessageRow }) {
    const { t } = useTranslation();
    const reason = useFailureReason();

    return (
        <WaitingRow
            title={
                <>
                    {t('Failed SMS: {{template}}', { template: row.template ?? t('Text message') })}{' '}
                    <InfoHint label={t('Why it failed')}>{reason(row)}</InfoHint>
                </>
            }
            action={<ResendAction message={row} />}
        />
    );
}

/** Figma: Klient / Przegląd · pola (601:16381): the fields marked for Przegląd, the assignee, then what SMS failed or waits. */
function CardSection({
    clientId,
    card,
    sms,
    pending,
    failed,
}: {
    clientId: number;
    card: ClientCard;
    sms: MessageRow[];
    pending: number;
    failed: number;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const scheduled = t(card.sms_opt_out ? 'held_count' : 'scheduled_count', { count: pending });
    // On a phone the failed count alone, so the head leaves room for Dziennik.
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
        <section className={`${splitSideClass} ${styles.hours}`}>
            <SectionHead
                kind="body"
                label={t('Client fields')}
                action={
                    <SectionAction aria-haspopup="dialog" onClick={() => setEditing(true)}>
                        {t('Change')}
                    </SectionAction>
                }
            />
            <dl className={styles.ledger}>
                {card.fields.map((field) => (
                    <KvRow key={field.key} layout="ledger" label={field.label} tone={field.display === null ? 'muted' : undefined}>
                        {field.display ?? '—'}
                    </KvRow>
                ))}
                <KvRow layout="ledger" label={t('Assignee')} tone={card.assignee === null ? 'muted' : undefined}>
                    {card.assignee?.name ?? '—'}
                </KvRow>
            </dl>
            {sms.length > 0 && (
                <div className={styles.cardSms}>
                    <SectionHead
                        kind="body"
                        label={head}
                        action={<SectionLink href={clients.show({ client: clientId, tab: 'dziennik' }).url}>{t('Journal')} →</SectionLink>}
                    />
                    <MessageList rows={sms} stacked />
                </div>
            )}
            <ClientFieldsDialog clientId={clientId} card={card} open={editing} onOpenChange={setEditing} />
        </section>
    );
}

function reportTitle(status: 'draft' | 'finalized' | null): string {
    if (status === 'draft') {
        return 'Report for {{month}}, draft';
    }

    return status === 'finalized' ? 'Report for {{month}}, ready to send' : 'Report for {{month}}';
}

function HoursSection({
    clientId,
    hours,
    settlement,
    locale,
}: {
    clientId: number;
    hours: OverviewHours;
    settlement: OverviewSettlement;
    locale: string;
}) {
    const { t } = useTranslation();
    const [closing, setClosing] = useState(false);
    const [details, setDetails] = useState(false);
    const wide = useSoloWide();
    const behind = settlement.open_behind && hours.period === settlement.active_period;
    const h = (value: number, signed = false) => `${hoursNumber(value, locale, signed)} h`;
    const over = hours.remaining < 0;
    const month = monthName(hours.period, locale);
    const next = monthName(nextPeriod(hours.period), locale);

    if (!hours.has_retainer) {
        return (
            <section className={`${splitSideClass} ${styles.hours}`}>
                <SectionHead kind="body" phone="plain" label={t('Hours')} meta={month} />
                <dl className={styles.ledger}>
                    <KvRow layout="ledger" label={t('Worked')}>
                        {h(hours.logged)}
                    </KvRow>
                </dl>
                <p className={styles.note}>{t('No retainer, so every logged hour counts.')}</p>
            </section>
        );
    }

    const detail = (
        <>
            <dl className={styles.ledger}>
                <KvRow layout="ledger" label={t('Package')}>
                    {h(hours.contracted ?? 0, true)}
                </KvRow>
                <KvRow layout="ledger" label={t('From previous months')} tone={hours.carried < 0 ? 'accent' : undefined}>
                    {h(hours.carried, true)}
                </KvRow>
                {hours.forfeited > 0 && (
                    <KvRow layout="ledger" label={t('Over the rollover cap')}>
                        {h(-hours.forfeited, true)}
                    </KvRow>
                )}
                <KvRow layout="ledger" label={t('Worked')}>
                    {h(-hours.used, true)}
                </KvRow>
            </dl>
            <div className={styles.bar}>
                <HoursBar used={hours.used} available={hours.available} size="block" />
                <p className={styles.caption}>
                    <span>{t('{{hours}} h available', { hours: hoursNumber(hours.available, locale) })}</span>
                    <span data-tone={over ? 'over' : undefined}>
                        {over
                            ? t('{{hours}} h over', { hours: hoursNumber(-hours.remaining, locale) })
                            : t('{{hours}} h free', { hours: hoursNumber(hours.remaining, locale) })}
                    </span>
                </p>
            </div>
        </>
    );

    return (
        <section className={`${splitSideClass} ${styles.hours}`}>
            <SectionHead className={styles.hoursHead} kind="body" label={t('Hours')} meta={month} />
            <div className={styles.hoursDetail}>{wide && detail}</div>
            <div className={styles.balance}>
                <p className={styles.balanceValue} data-tone={over ? 'over' : undefined}>
                    {h(hours.remaining, true)}
                </p>
                <p className={styles.balanceLabel}>{t('Balance for {{month}}', { month: next })}</p>
                <BottomSheet
                    open={details && !wide}
                    onOpenChange={setDetails}
                    title={`${t('Hours')} · ${month}`}
                    trigger={
                        <button type="button" className={styles.balanceLink}>
                            {t('balance for {{month}}', { month: next })}
                            <Icon name="chevron-right" size={16} />
                        </button>
                    }
                >
                    {!wide && (
                        <>
                            {detail}
                            <p className={styles.sheetTotal}>
                                <span className={styles.sheetTotalLabel}>{t('Balance for {{month}}', { month: next })}</span>
                                <span className={styles.sheetTotalValue} data-tone={over ? 'over' : undefined}>
                                    {h(hours.remaining, true)}
                                </span>
                            </p>
                        </>
                    )}
                </BottomSheet>
                {behind && (
                    <div className={styles.settle}>
                        <p className={styles.settleNote}>
                            {t('It is {{current}} now · {{month}} still open', {
                                current: monthName(settlement.current_period, locale),
                                month: monthName(settlement.active_period, locale),
                            })}
                        </p>
                        <HeaderAction className={styles.closeWide} onClick={() => setClosing(true)}>
                            {t('Close the month')}
                        </HeaderAction>
                        <SoloButton className={styles.closePhone} variant="outline" size="touch" onClick={() => setClosing(true)}>
                            {t('Close the month')}
                        </SoloButton>
                        <CloseMonthDialog clientId={clientId} period={settlement.active_period} open={closing} onOpenChange={setClosing} />
                    </div>
                )}
            </div>
        </section>
    );
}
