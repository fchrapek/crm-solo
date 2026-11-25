import { Chip } from '@/components/solo/chip';
import { KvRow } from '@/components/solo/kv-row';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { RecordFrame } from '@/components/solo/record-frame';
import { Row } from '@/components/solo/row';
import { type PlaceContext, type PlaceLinkPill, type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { GroupHeading, Tile, TileActions, TileGrid, TileHeader, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { InfoHint } from '@/components/ui/info-hint';
import { monthGenitive, monthName, shortDate } from '@/lib/dates';
import clientSummaryEmails from '@/routes/client-summary-emails';
import monthClose from '@/routes/month-close';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { monthUrl } from '../clients/components/client-billing/format';
import { hoursNumber, localeOf } from '../clients/components/client-list/format';
import { nextPeriod } from '../clients/components/client-overview/format';
import { CloseMonthAction, closeListUrl } from './index';
import styles from './index.module.css';
import { emailStep, monthLabel, moveStep, stepLabel, type Settlement, type Step, type SummaryEmailState } from './steps';
import { SummaryEmailDialog } from './summary-email-dialog';

interface CardProps extends SharedData {
    period: string;
    client: {
        id: number;
        name: string;
        short_name: string;
        type: 'maintenance' | 'gig' | null;
        report_mode: 'report' | 'summary_email' | 'none' | null;
        ssh: string | null;
    };
    sites: { project_id: number; name: string; steps: Step[] }[];
    client_steps: Step[];
    settlement: Settlement | null;
    /** The month's summary email as the step row tells it; null when the client sends none or the viewer is not the owner. */
    summary_email: SummaryEmailState | null;
    /** The month's clients in list order, with their progress. */
    clients: { id: number; name: string; resolved: number; total: number }[];
}

const TYPE_LABEL = { maintenance: 'Maintenance sites', gig: 'Agency gigs' } as const;
const REPORT_LABEL = { report: 'PDF report', summary_email: 'Summary email', none: 'No report' } as const;

const resolved = (steps: Step[]) => steps.filter((step) => step.state !== 'pending').length;

/** Where a step's work is looked at: the report and the invoices both live on the month's Billing. */
export function stepLink(step: Pick<Step, 'key'>, clientId: number, period: string, t: TFunction): { label: string; href: string } | null {
    if (step.key === 'report') {
        return { label: t('Report'), href: monthUrl(clientId, period) };
    }
    if (step.key === 'draft_invoice') {
        return { label: t('Invoices'), href: monthUrl(clientId, period) };
    }

    return null;
}

/** The chip of the hours close: waiting for the click, closed on a day, or held back by an earlier month. */
export function settlementChip(settlement: Settlement, locale: string, t: TFunction): { status: 'open' | 'done' | 'waiting'; label: string } {
    if (settlement.state === 'closed') {
        return { status: 'done', label: settlement.closed_on !== null ? t('ledger_closed', { date: shortDate(settlement.closed_on, locale) }) : t('ledger_closed_undated') };
    }

    return settlement.state === 'ready'
        ? { status: 'open', label: t('hours_chip_ready') }
        : { status: 'waiting', label: t('close {{month}} first', { month: monthName(settlement.active_period, locale) }) };
}

/** Figma: Month close · client card (1094:34969, phone 1094:35188): the steps per site, the client's own, then the hours. */
export default function Show() {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const { period, client, sites, client_steps: clientSteps, settlement, summary_email: summaryEmail } = usePage<CardProps>().props;
    const all = [...sites.flatMap((site) => site.steps), ...clientSteps];
    const month = monthName(period, locale);

    return (
        <SoloSurface day="paper">
            <Head title={`${client.short_name} · ${monthLabel(period, locale)}`} />
            <main className={styles.page}>
                <RecordFrame
                    title={client.short_name}
                    titleAttr={client.name}
                    noteKind="line"
                    note={[client.type && t(TYPE_LABEL[client.type]), month, t('{{done}} of {{count}} steps', { done: resolved(all), count: all.length })]
                        .filter(Boolean)
                        .join(' · ')}
                />
                <TileGrid>
                    <TileStack>
                        {sites.map((site) => (
                            <StepBlock key={site.project_id} label={site.name} steps={site.steps} clientId={client.id} period={period} />
                        ))}
                        {clientSteps.length > 0 && (
                            <StepBlock
                                label={t('Client level')}
                                steps={clientSteps}
                                clientId={client.id}
                                period={period}
                                email={summaryEmail === null ? undefined : { state: summaryEmail, clientName: client.short_name }}
                            />
                        )}
                    </TileStack>
                    <TileStack as="aside">
                        {settlement !== null && <HoursTile clientId={client.id} period={period} settlement={settlement} locale={locale} />}
                        <Tile kind="plain">
                            <TileHeader label={t('Client details')} />
                            <dl className={styles.facts}>
                                {client.report_mode !== null && (
                                    <KvRow layout="tile" label={t('Report')}>
                                        {t(REPORT_LABEL[client.report_mode]).toLocaleLowerCase(locale)}
                                    </KvRow>
                                )}
                                {client.type !== null && (
                                    <KvRow layout="tile" label={t('Type')}>
                                        {t(TYPE_LABEL[client.type]).toLocaleLowerCase(locale)}
                                    </KvRow>
                                )}
                                {client.ssh !== null && (
                                    <KvRow layout="tile" label="SSH">
                                        {client.ssh}
                                    </KvRow>
                                )}
                            </dl>
                            <SoloButtonLink variant="quiet" size="control" href={monthUrl(client.id, period)}>
                                {t("Client's billing")} →
                            </SoloButtonLink>
                        </Tile>
                    </TileStack>
                </TileGrid>
            </main>
        </SoloSurface>
    );
}

/** The financial close beside the checklist: never a step, never tickable. */
function HoursTile({ clientId, period, settlement, locale }: { clientId: number; period: string; settlement: Settlement; locale: string }) {
    const { t } = useTranslation();
    const chip = settlementChip(settlement, locale, t);

    return (
        <Tile kind="plain">
            <TileHeader label={t('Hours')} end={<Chip status={chip.status}>{chip.label}</Chip>} />
            {settlement.closing !== null && settlement.state !== 'blocked' && (
                <dl className={styles.facts}>
                    <KvRow
                        layout="tile"
                        total
                        label={t('Balance → opening of {{month}}', { month: monthGenitive(nextPeriod(period), locale) })}
                        tone={settlement.closing < 0 ? 'over' : undefined}
                    >
                        {`${hoursNumber(settlement.closing, locale, true)} h`}
                    </KvRow>
                </dl>
            )}
            <TileNote>{t('hours_close_note')}</TileNote>
            {settlement.state === 'ready' && (
                <TileActions>
                    <CloseMonthAction clientId={clientId} period={settlement.period} primary />
                </TileActions>
            )}
        </Tile>
    );
}

function StepBlock({ label, steps, clientId, period, email }: { label: string; steps: Step[]; clientId: number; period: string; email?: EmailRow }) {
    const { t } = useTranslation();

    return (
        <Tile kind="list">
            <GroupHeading label={label} end={t('{{done}} of {{total}}', { done: resolved(steps), total: steps.length })} />
            <TileRows>
                {steps.map((step, index) => (
                    <StepRow
                        key={`${step.project_id}-${step.key}`}
                        step={step}
                        clientId={clientId}
                        period={period}
                        rule={index > 0}
                        email={step.key === 'summary_email' ? email : undefined}
                    />
                ))}
            </TileRows>
        </Tile>
    );
}

interface EmailRow {
    state: SummaryEmailState;
    clientName: string;
}

/** A checklist step: the checkbox is done, Skip skips, Restore brings a skipped step back; the report and the invoice step also lead to their work. */
export function StepRow({ step, clientId, period, email, rule = true }: { step: Step; clientId: number; period: string; email?: EmailRow; rule?: boolean }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const move = (state: Step['state']) => () => moveStep(clientId, period, step, state);
    const who = step.via === 'agent' ? t('agent') : step.via === 'web' ? t('You') : null;
    const [open, setOpen] = useState(false);
    const name = stepLabel(step.key, t);
    const done = step.state === 'done';
    const check = { done, onToggle: move(done ? 'pending' : 'done'), label: done ? `${t('Mark as not done')}: ${name}` : `${t('Mark as done')}: ${name}` };

    if (email !== undefined && step.state !== 'skipped') {
        const shown = emailStep(email.state, locale, t);
        // Ticked by hand with no email from the CRM: the row says when, as every other step does.
        const handDone = email.state.status === null && done && step.completed_on !== null;
        const meta = handDone ? [shortDate(step.completed_on!, locale), who].filter(Boolean).join(' · ') : shown.meta;
        const short = handDone ? shortDate(step.completed_on!, locale) : shown.short;
        const start = () =>
            email.state.status === null
                ? router.post(
                      clientSummaryEmails.store({ client: clientId, period }).url,
                      {},
                      { preserveScroll: true, preserveState: true, onSuccess: () => setOpen(true) },
                  )
                : setOpen(true);

        return (
            <>
                <Row
                    rule={rule}
                    check={check}
                    title={name}
                    wideMeta={shown.tone === undefined ? meta : undefined}
                    meta={shown.tone === undefined ? <span className={styles.narrow}>{short}</span> : undefined}
                    chip={shown.tone !== undefined ? <Chip status="waiting">{short}</Chip> : undefined}
                    action={
                        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={start}>
                            {shown.action}
                        </SoloButton>
                    }
                />
                <SummaryEmailDialog
                    clientId={clientId}
                    clientName={email.clientName}
                    period={period}
                    open={open}
                    onClose={() => setOpen(false)}
                    onChanged={() => router.reload({ only: ['summary_email', 'client_steps', 'sites', 'clients'] })}
                    onSkip={() => {
                        setOpen(false);
                        moveStep(clientId, period, step, 'skipped');
                    }}
                />
            </>
        );
    }

    const link = stepLink(step, clientId, period, t);

    return (
        <Row
            rule={rule}
            check={check}
            stack={link !== null && step.state !== 'done'}
            title={
                <>
                    {name}
                    {step.note && (
                        <>
                            {' '}
                            <InfoHint label={t('Note')} side="bottom">
                                {step.note}
                            </InfoHint>
                        </>
                    )}
                </>
            }
            meta={
                step.state === 'skipped'
                    ? t('Skipped')
                    : done && step.completed_on !== null
                      ? [shortDate(step.completed_on, locale), who].filter(Boolean).join(' · ')
                      : step.owner === 'you'
                        ? t('You')
                        : t('agent')
            }
            action={
                <>
                    {link !== null && (
                        <SoloButtonLink variant="quiet" size="control" href={link.href}>
                            {link.label}
                        </SoloButtonLink>
                    )}
                    {step.state === 'skipped' ? (
                        <SoloButton variant="secondary" size="control" onClick={move('pending')}>
                            {t('Restore')}
                        </SoloButton>
                    ) : step.state === 'pending' ? (
                        <SoloButton variant="secondary" size="control" onClick={move('skipped')}>
                            {t('Skip')}
                        </SoloButton>
                    ) : null}
                </>
            }
        />
    );
}

/** The card's pills: the month's clients with their progress, then the way back to everyone. */
export function clientPills({ period, client, clients }: Pick<CardProps, 'period' | 'client' | 'clients'>, t: PlaceContext['t']): PlaceLinkPill[] {
    return [
        ...clients
            .filter((row) => row.total > 0 || row.id === client.id)
            .map((row) => ({
                label: row.name,
                href: monthClose.show(row.id, { query: { period } }).url,
                active: row.id === client.id,
                note: t('{{done}} of {{total}}', { done: row.resolved, total: row.total }),
            })),
        { label: t('All clients'), href: closeListUrl(period), count: clients.length },
    ];
}

Show.place = ((props, { t, locale }) => ({
    tokens: [
        { label: t('Month close'), href: monthClose.index().url },
        { label: monthLabel(props.period, locale), href: closeListUrl(props.period) },
        { label: props.client.short_name },
    ],
    pills: clientPills(props, t),
})) satisfies PlaceResolver<CardProps>;
