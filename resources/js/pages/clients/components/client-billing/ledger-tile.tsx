import { ActionMenuItem, ActionMenuLink } from '@/components/solo/action-menu';
import { ListTable, ListTableCell, ListTableHead, ListTableRow } from '@/components/solo/list-table';
import { BodyLink, SoloButton } from '@/components/solo/primitives';
import { RowMenu } from '@/components/solo/row-menu';
import { Tile, TileHeader, TileInset, TileNote } from '@/components/solo/tile';
import { InfoHint } from '@/components/ui/info-hint';
import { shortDate } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import clients from '@/routes/clients';
import { Link } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, hoursTerms } from '../client-list/format';
import { billedTip, bothValues } from './billed-format';
import styles from './client-billing.module.css';
import { ledgerStates, monthUrl, periodLabel } from './format';
import { emailLook } from './month-tiles';
import type { SummaryEmails } from './summary-emails';
import type { BillingLedger, LedgerRow } from './types';

const h = (value: number, locale: string, signed = false) => `${hoursNumber(value, locale, signed)}\u00a0h`;

/** The opening was set by hand when it does not continue the month before; the step keeps the balance column adding up. */
export function correctionOf(row: LedgerRow, older: LedgerRow | undefined): number | null {
    return older !== undefined && Math.abs(row.opening - older.closing) >= 0.005 ? row.opening - older.closing : null;
}

/** "otwarty · raport do wygenerowania": the month's settlement, its report and, for a summary-email client, its email. */
export function ledgerState(row: LedgerRow, emails: SummaryEmails | null, locale: string, t: TFunction): string {
    const states = ledgerStates(row, locale, t);
    const email = emails !== null ? emailLook(emails[row.period], false, locale, t) : null;

    return [states.settlement, states.report, email !== null ? `${t('E-mail').toLocaleLowerCase(locale)} ${email.chip.label}` : null].filter(Boolean).join(' · ');
}

/**
 * Figma: Bank godzin (LedgerRow). The months of the bank, newest first, with the month shown marked; a phone keeps the
 * month, "real · billed" and the balance. A month's name opens it, the rest sits in the row's menu.
 */
export function LedgerTile({
    clientId,
    ledger,
    cap,
    shown,
    openingReportId,
    emails,
    locale,
    onOpening,
    onReopen,
}: {
    clientId: number;
    ledger: BillingLedger;
    cap: number | null;
    /** The month the page is on. */
    shown: string;
    /** The report whose opening balance may change, when its row is loaded. */
    openingReportId: number | null;
    emails: SummaryEmails | null;
    locale: string;
    onOpening: () => void;
    onReopen: () => void;
}) {
    const { t } = useTranslation();
    const { rows, start, next } = ledger;
    const label = t('Hours bank');

    return (
        <Tile kind="list">
            <TileHeader
                label={label}
                end={
                    openingReportId !== null ? (
                        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onOpening}>
                            {t('Change opening balance')}
                        </SoloButton>
                    ) : undefined
                }
            />
            {rows.length === 0 ? (
                <TileInset>
                    <TileNote>{t('bank_empty')}</TileNote>
                </TileInset>
            ) : (
                <>
                    <TileInset className={styles.tableInset}>
                        <ListTable label={label}>
                            <thead>
                                <tr>
                                    <ListTableHead>{t('ledger_month')}</ListTableHead>
                                    <ListTableHead align="end" full>
                                        {t('ledger_package')}
                                    </ListTableHead>
                                    <ListTableHead align="end" wide>
                                        {t('ledger_real')}
                                    </ListTableHead>
                                    <ListTableHead align="end" wide>
                                        {t('ledger_billed')}
                                    </ListTableHead>
                                    <ListTableHead align="end" narrow>
                                        {t('billed_tasks_meta')}
                                    </ListTableHead>
                                    <ListTableHead align="end" full>
                                        {t('ledger_change')}
                                    </ListTableHead>
                                    <ListTableHead align="end">{t('ledger_balance')}</ListTableHead>
                                    <ListTableHead wide>{t('ledger_state')}</ListTableHead>
                                    <ListTableHead aria-label={t('More actions')} />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, index) => {
                                    const correction = correctionOf(row, rows[index + 1]);
                                    const name = periodLabel(row, rows[index - 1], locale);
                                    const tip = row.billed !== null ? billedTip(row.billed, locale, t) : null;
                                    const reportUrl = row.report_id !== null ? clientReports.edit([clientId, row.report_id]).url : null;
                                    const menu = row.whole_month || reportUrl !== null || row.closure_id !== null;

                                    return (
                                        <Fragment key={row.key}>
                                            <ListTableRow current={row.whole_month && row.period === shown}>
                                                <ListTableCell kind="title">
                                                    {row.whole_month ? (
                                                        <Link href={monthUrl(clientId, row.period)} className={styles.plainLink} preserveScroll={false}>
                                                            {name}
                                                        </Link>
                                                    ) : (
                                                        name
                                                    )}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" full>
                                                    {row.contracted !== null ? `${hoursTerms(row.contracted, locale)}\u00a0h` : ''}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" wide>
                                                    {row.billed !== null ? h(row.billed.real, locale) : ''}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" wide>
                                                    <span className={styles.figure}>
                                                        {tip !== null && <InfoHint label={t('Your figure')}>{tip}</InfoHint>}
                                                        {h(row.used, locale)}
                                                    </span>
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" narrow tone="muted">
                                                    {row.billed !== null ? bothValues(row.billed, locale) : h(row.used, locale)}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" full>
                                                    {hoursNumber(row.change, locale, true)}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" tone={row.closing < 0 ? 'over' : undefined}>
                                                    {h(row.closing, locale, true)}
                                                </ListTableCell>
                                                <ListTableCell wide>{ledgerState(row, emails, locale, t)}</ListTableCell>
                                                <ListTableCell align="end" className={styles.menuCell}>
                                                    {menu && (
                                                        <RowMenu name={name}>
                                                            {row.whole_month && <ActionMenuLink href={monthUrl(clientId, row.period)}>{t('Show the month')}</ActionMenuLink>}
                                                            {reportUrl !== null && <ActionMenuLink href={reportUrl}>{t('Open report')}</ActionMenuLink>}
                                                            {row.report_id !== null && row.report_id === openingReportId && (
                                                                <ActionMenuItem onSelect={onOpening}>{t('Change opening balance')}</ActionMenuItem>
                                                            )}
                                                            {row.closure_id !== null && <ActionMenuItem onSelect={onReopen}>{t('Reopen the month')}</ActionMenuItem>}
                                                        </RowMenu>
                                                    )}
                                                </ListTableCell>
                                            </ListTableRow>
                                            {correction !== null && (
                                                <ListTableRow minor>
                                                    <ListTableCell kind="title">{t('Correction {{date}}', { date: shortDate(row.period_start, locale) })}</ListTableCell>
                                                    <ListTableCell full />
                                                    <ListTableCell wide />
                                                    <ListTableCell wide />
                                                    <ListTableCell narrow />
                                                    <ListTableCell kind="number" align="end" full>
                                                        {hoursNumber(correction, locale, true)}
                                                    </ListTableCell>
                                                    <ListTableCell kind="number" align="end">
                                                        {h(row.opening, locale, true)}
                                                    </ListTableCell>
                                                    <ListTableCell wide>{t('ledger_correction')}</ListTableCell>
                                                    <ListTableCell />
                                                </ListTableRow>
                                            )}
                                        </Fragment>
                                    );
                                })}
                                {start !== null && (
                                    <ListTableRow minor>
                                        <ListTableCell kind="title">{t('Start {{date}}', { date: shortDate(start.date, locale) })}</ListTableCell>
                                        <ListTableCell full />
                                        <ListTableCell wide />
                                        <ListTableCell wide />
                                        <ListTableCell narrow />
                                        <ListTableCell full />
                                        <ListTableCell kind="number" align="end">
                                            {h(start.balance, locale, true)}
                                        </ListTableCell>
                                        <ListTableCell wide>{t('ledger_start')}</ListTableCell>
                                        <ListTableCell />
                                    </ListTableRow>
                                )}
                            </tbody>
                        </ListTable>
                    </TileInset>
                    <TileInset>
                        <TileNote>{cap !== null ? t('billed_footnote_cap', { cap: hoursTerms(cap, locale) }) : t('billed_footnote')}</TileNote>
                    </TileInset>
                    {next !== null && (
                        <TileInset>
                            <BodyLink
                                href={clients.show({ client: clientId, tab: 'rozliczenia' }).url}
                                data={{ miesiace: next }}
                                only={['billing_ledger']}
                                preserveUrl
                                preserveScroll
                                preserveState
                            >
                                {t('Older')} →
                            </BodyLink>
                        </TileInset>
                    )}
                </>
            )}
        </Tile>
    );
}
