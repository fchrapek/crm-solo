import { destroy, restore } from '@/actions/App/Http/Controllers/ClientsController';
import { ActionMenu, ActionMenuHeading, ActionMenuItem } from '@/components/solo/action-menu';
import { type ClientHeaderData, useInHeaderMenu } from '@/components/solo/client-header';
import { DangerZone } from '@/components/solo/danger-zone';
import { fieldControl, SoloField, SoloTextarea } from '@/components/solo/field';
import { IconButton } from '@/components/solo/icon-button';
import { KvRow } from '@/components/solo/kv-row';
import { SoloButton, SoloButtonLink, touchTargetClass } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Row } from '@/components/solo/row';
import { SegmentedControl } from '@/components/solo/segmented-control';
import { Tile, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { fullDate, shortDate } from '@/lib/dates';
import { localeOf } from '@/lib/hours-text';
import { LIFECYCLE_STAGES, lifecycleStageLabelKey } from '@/lib/lifecycle-stage';
import clients, { transitionStage } from '@/routes/clients';
import contactRoutes from '@/routes/contacts';
import { type LifecycleStage } from '@/types';
import { router } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { type ClientCard, ClientFieldsDialog } from '../client-fields';
import { BaselineDialog } from './baseline-dialog';
import { ClientDetailsDialog } from './client-details-dialog';
import { ContactDialog } from './contact-dialog';
import styles from './details-tab.module.css';
import formStyles from './dialog-form.module.css';
import { DocumentsSection } from './documents-section';
import { addressLine, cooperationLine, taxIdLine } from './format';
import { MONTH_CLOSE_LABEL, MonthCloseDialog, REPORT_MODE_LABEL } from './month-close-dialog';
import type { ClientContactRow, ClientDetails, ClientDocumentRow, ClientStage, DetailsProject } from './types';

export type DetailsDialog = 'details' | 'contact' | 'month-close' | 'baseline' | 'archive' | 'fields' | 'stage' | null;

/** A tile's own button, which says it opens a dialog. */
function TileEdit({ onClick, children }: { onClick: () => void; children: ReactNode }) {
    return (
        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onClick}>
            {children}
        </SoloButton>
    );
}

/**
 * Figma 11A: Client · Details (1427:19499, phone 1427:19921). The company or the person, contacts, the account's own
 * fields and the danger zone; beside them the stage, the month close while its module is on and used, and documents.
 * The DOM follows the phone's one column, so the Tab order is the phone's on every width.
 */
export function DetailsTab({
    client,
    details,
    contacts,
    documents = [],
    card = null,
    stage = null,
    projects = [],
    owner = false,
    dialog,
    onDialogChange,
}: {
    client: ClientHeaderData;
    details: ClientDetails;
    contacts: ClientContactRow[];
    documents?: ClientDocumentRow[];
    card?: ClientCard | null;
    stage?: ClientStage | null;
    projects?: DetailsProject[];
    /** Archiving and restoring a client are the account owner's. */
    owner?: boolean;
    dialog: DetailsDialog;
    onDialogChange: (dialog: DetailsDialog) => void;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const open = (next: Exclude<DetailsDialog, null>) => () => onDialogChange(next);
    const toggle = (name: Exclude<DetailsDialog, null>) => (shown: boolean) => onDialogChange(shown ? name : null);
    const notSet = t('Not set');
    const address = addressLine(details);
    const cooperation = cooperationLine(details, t);
    const individual = details.type === 'individual';
    const billing = client.billing !== false;
    const connected = projects.filter((project) => !project.is_private).length;
    const monthCloseTile = details.modules.month_close && details.month_close_type !== null;
    const reach = (
        <>
            <KvRow layout="tile" label={t('Phone')} tone={details.phone ? undefined : 'muted'}>
                {details.phone ? (
                    <a href={`tel:${details.phone}`} className={`${styles.contactLink} ${touchTargetClass}`}>
                        {details.phone}
                    </a>
                ) : (
                    notSet
                )}
            </KvRow>
            <KvRow layout="tile" label={t('Email')} tone={details.email ? undefined : 'muted'}>
                {details.email ? (
                    <a href={`mailto:${details.email}`} className={`${styles.contactLink} ${touchTargetClass}`}>
                        {details.email}
                    </a>
                ) : (
                    notSet
                )}
            </KvRow>
        </>
    );
    const edit = <TileEdit onClick={open('details')}>{t('Edit')}</TileEdit>;

    return (
        <TileGrid phoneOrder>
            {stage !== null && (
                <TileStack place="lead">
                    <Tile kind="plain">
                        <TileHeader label={t('Relationship stage')} end={<TileEdit onClick={open('stage')}>{t('Change stage')}</TileEdit>} />
                        <dl className={styles.list}>
                            <KvRow layout="tile" label={t('Stage')}>
                                {t(lifecycleStageLabelKey(stage.stage))}
                            </KvRow>
                            {stage.since !== null && (
                                <KvRow layout="tile" label={t('Stage since')}>
                                    {fullDate(stage.since, locale)}
                                </KvRow>
                            )}
                            {billing && stage.retainer_from !== null && (
                                <KvRow layout="tile" label={t('Retainer since')}>
                                    {shortDate(stage.retainer_from, locale)}
                                </KvRow>
                            )}
                        </dl>
                    </Tile>
                </TileStack>
            )}
            <TileStack place="main">
                <Tile kind="plain">
                    <TileHeader label={individual ? t('Individual') : t('Company')} end={edit} />
                    <dl className={styles.list}>
                        <KvRow
                            layout="tile"
                            label={
                                individual ? (
                                    <>
                                        <span className={styles.wideOnly}>{t('Name')}</span>
                                        <span className={styles.phoneOnly}>{t('Full name')}</span>
                                    </>
                                ) : (
                                    t('Name')
                                )
                            }
                        >
                            {details.name}
                        </KvRow>
                        {!individual && (
                            <KvRow layout="tile" label={t('Tax ID (NIP)')} tone={details.tax_id ? undefined : 'muted'}>
                                {taxIdLine(details.tax_id) || notSet}
                            </KvRow>
                        )}
                        <KvRow layout="tile" label={t('Address')} tone={address ? undefined : 'muted'}>
                            {address || notSet}
                        </KvRow>
                        {!individual && details.business_type && (
                            <KvRow layout="tile" label={t('Business Type')}>
                                {details.business_type}
                            </KvRow>
                        )}
                        {!individual && reach}
                        {billing && (
                            <KvRow layout="tile" label={t('Cooperation')} tone={cooperation ? undefined : 'muted'}>
                                {cooperation || notSet}
                            </KvRow>
                        )}
                        {billing && details.cooperation_type === 'hourly' && details.hourly_rate !== null && (
                            <KvRow layout="tile" label={t('Rate')}>
                                {t('{{amount}} net / h', { amount: money(details.hourly_rate, details.currency, locale) })}
                            </KvRow>
                        )}
                        {details.segment !== null && (
                            <KvRow layout="tile" label={t('Segment')}>
                                {t(SEGMENT_LABEL[details.segment] ?? details.segment)}
                            </KvRow>
                        )}
                        {billing && (
                            <KvRow layout="tile" label="Infakt" tone={details.infakt_id ? undefined : 'muted'}>
                                {details.infakt_id ? t('client {{id}}', { id: details.infakt_id }) : t('Not linked')}
                            </KvRow>
                        )}
                        {projects.length > 0 && (
                            <KvRow layout="tile" label="Trello" tone={connected > 0 ? undefined : 'muted'}>
                                {t('trello_projects_connected', { count: connected, total: projects.length })}
                            </KvRow>
                        )}
                    </dl>
                </Tile>
                {individual && (
                    <Tile kind="plain">
                        <TileHeader label={t('Contact details')} end={edit} />
                        <dl className={styles.list}>{reach}</dl>
                    </Tile>
                )}
                <Tile kind="list">
                    <TileHeader
                        label={t('Contacts')}
                        count={contacts.length}
                        end={<TileEdit onClick={open('contact')}>{t('New contact')}</TileEdit>}
                    />
                    {contacts.length === 0 ? (
                        <TileInset>
                            <TileNote>{t('No contacts yet.')}</TileNote>
                        </TileInset>
                    ) : (
                        <TileRows>
                            {contacts.map((contact, index) => (
                                <Row
                                    key={contact.id}
                                    rule={index > 0}
                                    title={contact.name}
                                    href={contactRoutes.edit(contact.id)}
                                    wideMeta={
                                        contact.email ? (
                                            <a href={`mailto:${contact.email}`}>{contact.email}</a>
                                        ) : contact.phone ? (
                                            <a href={`tel:${contact.phone}`}>{contact.phone}</a>
                                        ) : (
                                            notSet
                                        )
                                    }
                                    action={
                                        <SoloButtonLink
                                            href={contactRoutes.edit(contact.id)}
                                            variant="quiet"
                                            size="control"
                                            aria-label={`${t('Edit')}: ${contact.name}`}
                                        >
                                            {t('Edit')}
                                        </SoloButtonLink>
                                    }
                                />
                            ))}
                        </TileRows>
                    )}
                </Tile>
                {card !== null && (
                    <Tile kind="plain">
                        <TileHeader
                            label={t('Client fields')}
                            count={card.fields.filter((field) => !field.archived).length}
                            end={
                                <span className={styles.headerActions}>
                                    <SoloButtonLink
                                        href={clients.show({ client: client.id, tab: 'journal' }).url}
                                        variant="quiet"
                                        size="control"
                                    >
                                        {t('Change history')}
                                    </SoloButtonLink>
                                    <TileEdit onClick={open('fields')}>{t('Edit')}</TileEdit>
                                </span>
                            }
                        />
                        <dl className={styles.list}>
                            <KvRow layout="tile" label={t('Assignee')} tone={card.assignee ? undefined : 'muted'}>
                                {card.assignee?.name ?? notSet}
                            </KvRow>
                            <KvRow layout="tile" label={t('Send SMS')}>
                                {card.sms_opt_out ? t('No') : t('Yes')}
                            </KvRow>
                            {card.fields.map((field) => (
                                <KvRow
                                    key={field.key}
                                    layout="tile"
                                    label={field.archived ? t('{{label}} (archived)', { label: field.label }) : field.label}
                                    tone={field.display === null || field.archived ? 'muted' : undefined}
                                >
                                    {field.display === null ? (
                                        notSet
                                    ) : field.type === 'url' ? (
                                        <a href={field.display} target="_blank" rel="noopener noreferrer">
                                            {field.display.replace(/^https?:\/\//, '')} ↗
                                        </a>
                                    ) : (
                                        field.display
                                    )}
                                </KvRow>
                            ))}
                        </dl>
                    </Tile>
                )}
            </TileStack>
            <TileStack as="aside" place="side">
                {monthCloseTile && (
                    <Tile kind="plain">
                        <TileHeader label={t('Month close')} end={<TileEdit onClick={open('month-close')}>{t('Edit')}</TileEdit>} />
                        <dl className={styles.list}>
                            <KvRow layout="tile" label={t('Close type')}>
                                {t(MONTH_CLOSE_LABEL[details.month_close_type ?? '__none__'])}
                            </KvRow>
                            <KvRow layout="tile" label={t('Deliverable')}>
                                {t(REPORT_MODE_LABEL[details.report_mode])}
                            </KvRow>
                            <KvRow layout="tile" label={t('In the close')}>
                                {details.include_in_month_close ? t('Yes, every month') : t('No')}
                            </KvRow>
                            <KvRow layout="tile" label="SSH" tone={details.ssh_config ? undefined : 'muted'}>
                                {details.ssh_config || notSet}
                            </KvRow>
                            <KvRow layout="tile" label={t('Invoice line')} tone={details.maintenance_invoice_description ? undefined : 'muted'}>
                                {details.maintenance_invoice_description || notSet}
                            </KvRow>
                        </dl>
                    </Tile>
                )}
                <DocumentsSection clientId={client.id} documents={documents} />
            </TileStack>
            {owner && (
                <TileStack place="tail">
                    <DangerZone
                        label={t('Danger zone')}
                        title={details.archived ? t('Restore from the archive') : t('Archive client')}
                        description={details.archived ? t('danger_restore_client') : t('danger_archive_client')}
                        action={details.archived ? t('Restore') : t('Archive')}
                        onAction={open('archive')}
                    />
                </TileStack>
            )}
            <ClientDetailsDialog clientId={client.id} details={details} open={dialog === 'details'} onOpenChange={toggle('details')} />
            <ContactDialog clientId={client.id} open={dialog === 'contact'} onOpenChange={toggle('contact')} />
            <MonthCloseDialog clientId={client.id} details={details} open={dialog === 'month-close'} onOpenChange={toggle('month-close')} />
            {details.modules.report_baseline && (
                <BaselineDialog clientId={client.id} baseline={null} open={dialog === 'baseline'} onOpenChange={toggle('baseline')} />
            )}
            {card !== null && <ClientFieldsDialog clientId={client.id} card={card} open={dialog === 'fields'} onOpenChange={toggle('fields')} />}
            {stage !== null && <StageDialog clientId={client.id} current={stage.stage} open={dialog === 'stage'} onOpenChange={toggle('stage')} />}
            <ConfirmDialog
                open={dialog === 'archive'}
                onOpenChange={toggle('archive')}
                title={details.archived ? t('Restore client?') : t('Archive client?')}
                description={
                    details.archived
                        ? t('The client comes back to the list with its tasks, time and history.')
                        : t('The client leaves the list and the month close; its tasks, time and history stay and it can be restored.')
                }
                confirmLabel={details.archived ? t('Restore') : t('Archive')}
                variant={details.archived ? 'default' : 'destructive'}
                onConfirm={() => (details.archived ? router.put(restore(client.id).url) : router.delete(destroy(client.id).url))}
            />
        </TileGrid>
    );
}

/** Figma: Change lifecycle stage. The stage and an optional note; the change lands in the journal. */
export function StageDialog({
    clientId,
    current,
    open,
    onOpenChange,
}: {
    clientId: number;
    current: LifecycleStage;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [stage, setStage] = useState(current);
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setStage(current);
            setNote('');
            setError(undefined);
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        router.patch(
            transitionStage(clientId).url,
            { stage, note: note.trim() },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) => setError(errors.stage ?? errors.note),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Change lifecycle stage')}
            sheetTitle={t('Relationship stage')}
            onSubmit={submit}
            sheetAction="save"
            saving={processing}
            fields
            footerClassName={formStyles.actions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SegmentedControl
                label={t('Stage')}
                value={stage}
                onChange={setStage}
                options={LIFECYCLE_STAGES.map((option) => ({ value: option, label: t(lifecycleStageLabelKey(option)) }))}
            />
            <SoloField label={t('Note')} htmlFor="stage-note" error={error}>
                <SoloTextarea
                    {...fieldControl('stage-note', error)}
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    maxLength={2000}
                    rows={3}
                    disabled={processing}
                />
            </SoloField>
        </ResponsiveDialog>
    );
}

const SEGMENT_LABEL: Record<string, string> = { agency: 'Agency', smb: 'Small / mid business', enterprise: 'Enterprise', individual: 'Individual' };

function money(amount: number, currency: string, locale: string): string {
    return new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: 2 }).format(amount);
}

export type DetailsAction = Exclude<DetailsDialog, null>;

/** The ⋯ menu's module entries: only modules the account has on, and only what the client does not use yet. */
export function moduleActions(details: Pick<ClientDetails, 'modules' | 'month_close_type' | 'has_report_baseline'>): DetailsAction[] {
    return [
        ...(details.modules.month_close && details.month_close_type === null ? (['month-close'] as const) : []),
        ...(details.modules.report_baseline && !details.has_report_baseline ? (['baseline'] as const) : []),
    ];
}

/** Figma 11A (1427:20350, phone 1427:20483): the ⋯ menu of Dane, the modules a client can join, then the client itself. */
export function DetailsActions({ details, owner, onOpen }: { details: ClientDetails; owner: boolean; onOpen: (action: DetailsAction) => void }) {
    const { t } = useTranslation();
    const inMenu = useInHeaderMenu();
    const modules = moduleActions(details);
    const items = (
        <>
            {modules.length > 0 && <ActionMenuHeading>{t('Modules')}</ActionMenuHeading>}
            {modules.map((action) => (
                <ActionMenuItem key={action} onSelect={() => onOpen(action)}>
                    {action === 'month-close' ? t('Add to the month close') : t('Turn on the report baseline')}
                </ActionMenuItem>
            ))}
            <ActionMenuHeading>{t('menu_heading_client')}</ActionMenuHeading>
            <ActionMenuItem onSelect={() => onOpen('details')}>
                {details.type === 'individual' ? t('Edit details') : t('Edit company data')}
            </ActionMenuItem>
            {inMenu && <ActionMenuItem onSelect={() => onOpen('contact')}>{t('New contact')}</ActionMenuItem>}
            {owner && (
                <ActionMenuItem onSelect={() => onOpen('archive')}>{details.archived ? t('Restore client') : t('Archive client')}</ActionMenuItem>
            )}
        </>
    );

    return inMenu ? items : <ActionMenu trigger={<IconButton icon="more" look="outline" label={t('More actions')} />}>{items}</ActionMenu>;
}
