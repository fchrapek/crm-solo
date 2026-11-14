import { Chip, type ChipStatus } from '@/components/solo/chip';
import { ListRow } from '@/components/solo/list-row';
import { SoloButton, soloButtonClass } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Row } from '@/components/solo/row';
import { Tile, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { InfoHint } from '@/components/ui/info-hint';
import { monthName, shortDate } from '@/lib/dates';
import { jsonRequest } from '@/lib/json-request';
import clientInvoiceDrafts from '@/routes/client-invoice-drafts';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './client-billing.module.css';
import { invoiceChip, moneyOf } from './format';
import type { BillingInvoice, InvoiceDraftGroup, InvoiceDrafts, InvoiceSource } from './types';

type Translate = ReturnType<typeof useTranslation>['t'];

type Loaded = { state: 'loading' } | { state: 'failed'; message: string } | { state: 'ready'; invoices: InvoiceSource[] };

const netOf = ({ net }: InvoiceDraftGroup): number | null => net;

export function groupTitle(group: InvoiceDraftGroup, t: Translate): string {
    const names = group.projects.filter((name) => name !== '');

    return names.length > 0 ? names.join(', ') : t('Invoice {{group}}', { group: group.group });
}

/** What the (i) says a row would copy, or where its draft is. */
export function groupHint(group: InvoiceDraftGroup, period: string, locale: string, t: Translate): string {
    const target = monthName(period, locale);
    if (group.state === 'drafted') {
        return t('The draft for {{month}} is in Infakt; you issue it there.', { month: target });
    }
    if (group.state === 'needs_source') {
        return group.reason !== null
            ? reasonText(group.reason, t)
            : t('No earlier invoice is recorded for this invoice group. Pick the invoice to copy once; later months follow it.');
    }
    if (group.state === 'blocked') {
        return t('{{month}} was drafted as a copy before drafts were kept per project, so it is not drafted again. Check that month in Infakt.', {
            month: target,
        });
    }
    if (group.state === 'unconfirmed') {
        return t('Infakt did not confirm this draft, so it may or may not be there. Check Infakt before trying again.');
    }
    if (group.source !== null && group.source.number !== null && netOf(group) !== null) {
        return t('Copy of invoice {{number}} for {{source}}, {{money}} net, with new dates for {{month}}. It becomes a draft; you issue it in Infakt.', {
            number: group.source.number,
            source: group.source.sale_date !== null ? monthName(group.source.sale_date.slice(0, 7), locale) : '',
            money: moneyOf((netOf(group) ?? 0) / 100, group.currency, locale, true),
            month: target,
        });
    }

    return group.chained
        ? t('Copy of the invoice drafted for this group last month, with new dates for {{month}}. It becomes a draft; you issue it in Infakt.', { month: target })
        : t('Copy of the latest issued VAT invoice, with new dates for {{month}}. It becomes a draft; you issue it in Infakt.', { month: target });
}

function reasonText(reason: NonNullable<InvoiceDraftGroup['reason']>, t: Translate): string {
    switch (reason) {
        case 'gone':
            return t('The invoice recorded for this group last time is no longer in Infakt. Pick the invoice to copy.');
        case 'not_vat':
            return t('The invoice recorded for this group last time is not a VAT invoice. Pick the invoice to copy.');
        case 'draft':
            return t('The invoice recorded for this group last time is still a draft in Infakt. Issue it there or pick another invoice.');
        case 'advance':
            return t('The invoice recorded for this group last time is an advance, final or correction invoice. Pick the invoice to copy.');
        case 'settles_advance':
            return t('The invoice recorded for this group last time settles an advance invoice. Pick the invoice to copy.');
        case 'unusable':
            return t('The invoice recorded for this group last time cannot be copied as it is (its payment term or fields). Pick the invoice to copy.');
        case 'projects_changed':
            return t('The projects of this invoice group changed since its last invoice. Pick the invoice to copy.');
    }
}

/** The chip of a group whose state is not an action: its draft, a draft on the way, one to check, or no invoice to copy yet. */
export function groupChip(group: InvoiceDraftGroup, t: Translate): { status: ChipStatus; label: string } | null {
    switch (group.state) {
        case 'drafted':
            return { status: 'open', label: t('invoice_line_draft') };
        case 'processing':
            return { status: 'open', label: t('draft_chip_processing') };
        case 'unconfirmed':
        case 'blocked':
            return { status: 'waiting', label: t('draft_chip_check') };
        case 'needs_source':
            return { status: 'waiting', label: t('draft_chip_no_source') };
        default:
            return null;
    }
}

/**
 * Figma: Faktury za wrzesień. For the month Infakt drafts are made for, a row per invoice group with its amount, the
 * (i) naming the source and its action; for any other month, the invoices booked in it.
 */
export function MonthInvoices({
    clientId,
    clientName,
    period,
    drafts,
    invoices,
    locale,
}: {
    clientId: number;
    clientName: string;
    period: string;
    /** The groups of the drafted month, when it is the month shown. */
    drafts: InvoiceDrafts | null;
    invoices: BillingInvoice[];
    locale: string;
}) {
    const { t } = useTranslation();
    const [picking, setPicking] = useState<InvoiceDraftGroup | null>(null);
    const [sending, setSending] = useState(false);
    const month = monthName(period, locale);
    const label = t('Invoices for {{month}}', { month });

    if (drafts === null) {
        return (
            <Tile kind="list">
                <TileHeader label={label} count={invoices.length > 0 ? invoices.length : undefined} />
                {invoices.length === 0 ? (
                    <TileInset>
                        <TileNote>{t('No invoices for this month.')}</TileNote>
                    </TileInset>
                ) : (
                    <TileRows>
                        {invoices.map((invoice, index) => {
                            const chip = invoiceChip(invoice, locale, t);
                            const number = invoice.number ?? t('No number');

                            return (
                                <Row
                                    key={invoice.id}
                                    rule={index > 0}
                                    title={
                                        invoice.external_url !== null ? (
                                            <a href={invoice.external_url} target="_blank" rel="noopener noreferrer" title={t('Open in Infakt')} className={styles.plainLink}>
                                                {number}
                                            </a>
                                        ) : (
                                            number
                                        )
                                    }
                                    time={moneyOf(invoice.net, invoice.currency, locale, true)}
                                    chip={<Chip status={chip.status}>{chip.label}</Chip>}
                                />
                            );
                        })}
                    </TileRows>
                )}
                {invoices.length > 0 && (
                    <TileInset>
                        <TileNote>{t('Net amounts.')}</TileNote>
                    </TileInset>
                )}
            </Tile>
        );
    }

    const copyable = drafts.groups.some((group) => group.state === 'ready');

    function copy(group: number | null, like: string) {
        router.post(
            clientInvoiceDrafts.store(clientId).url,
            { period: drafts?.period, group, like },
            { preserveScroll: true, onStart: () => setSending(true), onFinish: () => setSending(false) },
        );
    }

    return (
        <Tile kind="list">
            <TileHeader
                label={label}
                count={drafts.groups.length}
                end={
                    copyable ? (
                        <SoloButton variant="secondary" size="control" onClick={() => copy(null, 'last')} disabled={sending}>
                            {t('Copy all')}
                        </SoloButton>
                    ) : undefined
                }
            />
            <TileRows>
                {drafts.groups.map((group, index) => {
                    const chip = groupChip(group, t);

                    return (
                        <Row
                            key={group.group}
                            rule={index > 0}
                            stack
                            title={
                                <>
                                    {groupTitle(group, t)} <InfoHint>{groupHint(group, drafts.period, locale, t)}</InfoHint>
                                </>
                            }
                            time={netOf(group) !== null && group.state !== 'needs_source' ? moneyOf((netOf(group) ?? 0) / 100, group.currency, locale, true) : undefined}
                            chip={chip !== null ? <Chip status={chip.status}>{chip.label}</Chip> : undefined}
                            action={<GroupAction group={group} sending={sending} onCopy={() => copy(group.group, 'last')} onPick={() => setPicking(group)} />}
                        />
                    );
                })}
            </TileRows>
            <TileInset>
                <TileNote>{t('month_invoices_note', { month })}</TileNote>
            </TileInset>
            <PickInvoiceDialog
                clientId={clientId}
                clientName={clientName}
                period={drafts.period}
                group={picking}
                locale={locale}
                onOpenChange={(open) => !open && setPicking(null)}
                onPick={(number) => {
                    if (picking !== null) {
                        copy(picking.group, number);
                    }
                    setPicking(null);
                }}
            />
        </Tile>
    );
}

/** A group's buttons: its draft in Infakt, or Kopiuj fakturę with Wybierz fakturę beside it; a pick is always offered while nothing is drafted. */
function GroupAction({ group, sending, onCopy, onPick }: { group: InvoiceDraftGroup; sending: boolean; onCopy: () => void; onPick: () => void }) {
    const { t } = useTranslation();

    if (group.state === 'drafted') {
        return group.draft_url !== null ? (
            <a href={group.draft_url} target="_blank" rel="noopener noreferrer" className={soloButtonClass({ variant: 'secondary', size: 'control' })}>
                {t('Open in Infakt')}
            </a>
        ) : null;
    }
    if (group.state === 'processing' || group.state === 'unconfirmed' || group.state === 'blocked') {
        return null;
    }
    if (group.state === 'needs_source') {
        return (
            <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onPick} disabled={sending}>
                {t('Pick invoice')}
            </SoloButton>
        );
    }

    return (
        <>
            <SoloButton variant="secondary" size="control" onClick={onCopy} disabled={sending}>
                {t('Copy invoice')}
            </SoloButton>
            <SoloButton variant="quiet" size="control" aria-haspopup="dialog" onClick={onPick} disabled={sending}>
                {t('Pick invoice')}
            </SoloButton>
        </>
    );
}

/** Figma 986:25490 (phone 988:26198): the client's issued VAT invoices; a pick drafts its copy and becomes the group's source. */
function PickInvoiceDialog({
    clientId,
    clientName,
    period,
    group,
    locale,
    onOpenChange,
    onPick,
}: {
    clientId: number;
    clientName: string;
    period: string;
    group: InvoiceDraftGroup | null;
    locale: string;
    onOpenChange: (open: boolean) => void;
    onPick: (number: string) => void;
}) {
    const { t } = useTranslation();
    const open = group !== null;
    const [loaded, setLoaded] = useState<Loaded>({ state: 'loading' });
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setLoaded({ state: 'loading' });
        }
    }

    useEffect(() => {
        if (!open) {
            return;
        }
        const controller = new AbortController();
        jsonRequest<{ invoices: InvoiceSource[] }>(clientInvoiceDrafts.sources(clientId).url, 'GET', undefined, controller.signal)
            .then((data) => setLoaded({ state: 'ready', invoices: data.invoices }))
            .catch((error: Error) => {
                if (!controller.signal.aborted) {
                    setLoaded({ state: 'failed', message: error.message });
                }
            });

        return () => controller.abort();
    }, [open, clientId]);

    const name = group !== null ? groupTitle(group, t) : '';

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Pick the invoice to copy')}
            description={`${name} · ${clientName}`}
            onSubmit={(event) => event.preventDefault()}
            actions={(size) => (
                <SoloButton variant="secondary" size={size === 'touch' ? 'touch' : 'control'} onClick={() => onOpenChange(false)}>
                    {t('Cancel')}
                </SoloButton>
            )}
        >
            {loaded.state === 'loading' && <p className={styles.empty}>{t('Reading invoices from Infakt')}</p>}
            {loaded.state === 'failed' && <p className={styles.empty}>{loaded.message}</p>}
            {loaded.state === 'ready' &&
                (loaded.invoices.length === 0 ? (
                    <p className={styles.empty}>{t('Infakt holds no issued VAT invoice for this client.')}</p>
                ) : (
                    <ul className={styles.pickList}>
                        {loaded.invoices.map((invoice) => (
                            <li key={invoice.uuid ?? invoice.number}>
                                <ListRow
                                    title={[invoice.number, invoice.lines[0]].filter(Boolean).join(' · ')}
                                    value={
                                        <span className={styles.draftAmount}>
                                            {[
                                                invoice.invoice_date !== null ? shortDate(invoice.invoice_date, locale) : null,
                                                moneyOf(invoice.net_price / 100, invoice.currency, locale, true),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    }
                                    onClick={() => invoice.number !== null && onPick(invoice.number)}
                                />
                            </li>
                        ))}
                    </ul>
                ))}
            <p className={styles.pickNote}>
                {t('Net amounts. The copy gets the dates for {{month}} and lands in Infakt as a draft. The picked invoice becomes the template for {{group}} in the following months.', {
                    month: monthName(period, locale),
                    group: name,
                })}
            </p>
        </ResponsiveDialog>
    );
}
