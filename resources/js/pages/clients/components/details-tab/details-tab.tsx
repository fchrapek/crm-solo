import { destroy, restore } from '@/actions/App/Http/Controllers/ClientsController';
import { MarkdownView } from '@/components/markdown-view';
import { ActionMenuItem } from '@/components/solo/action-menu';
import { type ClientHeaderData, useInHeaderMenu } from '@/components/solo/client-header';
import { fieldControl, SoloField, SoloTextarea } from '@/components/solo/field';
import { KvRow } from '@/components/solo/kv-row';
import { SoloButton, SoloButtonLink, touchTargetClass } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Row } from '@/components/solo/row';
import { SegmentedControl } from '@/components/solo/segmented-control';
import { Tile, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { fullDate, shortDate } from '@/lib/dates';
import { localeOf } from '@/lib/hours-text';
import { LIFECYCLE_STAGES, lifecycleStageLabelKey } from '@/lib/lifecycle-stage';
import clients, { transitionStage } from '@/routes/clients';
import contactRoutes from '@/routes/contacts';
import projectBoard from '@/routes/project-board';
import { type LifecycleStage } from '@/types';
import { router } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { type ClientCard, ClientFieldsDialog, type FieldEvent, FieldHistory } from '../client-fields';
import { ProjectFormDialog } from '../project-form-dialog';
import { BaselineDialog } from './baseline-dialog';
import { ClientDetailsDialog } from './client-details-dialog';
import { ContactDialog } from './contact-dialog';
import styles from './details-tab.module.css';
import formStyles from './dialog-form.module.css';
import { DocumentsSection } from './documents-section';
import { addressLine, cooperationLine, taxIdLine } from './format';
import { MONTH_CLOSE_LABEL, MonthCloseDialog, REPORT_MODE_LABEL } from './month-close-dialog';
import type { ClientContactRow, ClientDetails, ClientDocumentRow, ClientStage, DetailsProject } from './types';

export type DetailsDialog = 'details' | 'contact' | 'month-close' | 'baseline' | 'archive' | 'delete' | 'fields' | 'stage' | 'project' | null;

/** A tile's own button, which says it opens a dialog. */
function TileEdit({ onClick, children }: { onClick: () => void; children: ReactNode }) {
    return (
        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onClick}>
            {children}
        </SoloButton>
    );
}

/**
 * Figma: Klient · Dane (1093:30852, phone 1093:31255). Everything the record holds about the client: the company or
 * the person, the relationship stage, contacts, the account's own fields, projects, the month close and, beside
 * them, documents, the field history and what ends the cooperation.
 */
export function DetailsTab({
    client,
    details,
    contacts,
    documents = [],
    card = null,
    fieldHistory = [],
    fieldHistoryCount = fieldHistory.length,
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
    fieldHistory?: FieldEvent[];
    /** Every change; fieldHistory holds the latest few. */
    fieldHistoryCount?: number;
    stage?: ClientStage | null;
    projects?: DetailsProject[];
    /** Archiving, restoring and deleting a client are the account owner's. */
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
    const [deleteContacts, setDeleteContacts] = useState(false);
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
        <TileGrid>
            <TileStack>
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
                        {billing && details.hourly_rate !== null && (
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
                {stage !== null && (
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
                            end={<TileEdit onClick={open('fields')}>{t('Edit')}</TileEdit>}
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
                <Tile kind="list">
                    <TileHeader
                        label={t('Projects and repositories')}
                        count={projects.length}
                        end={<TileEdit onClick={open('project')}>{t('New project')}</TileEdit>}
                    />
                    {projects.length === 0 ? (
                        <TileInset>
                            <TileNote>{t('No projects yet.')}</TileNote>
                        </TileInset>
                    ) : (
                        <TileRows>
                            {projects.map((project, index) => (
                                <Row
                                    key={project.id}
                                    rule={index > 0}
                                    title={project.name}
                                    wideMeta={projectFacts(project, billing, t)}
                                    action={
                                        <SoloButtonLink
                                            href={projectBoard.show({ client: client.id, project: project.id })}
                                            variant="secondary"
                                            size="control"
                                            aria-label={`${t('Open')}: ${project.name}`}
                                        >
                                            {t('Open')}
                                        </SoloButtonLink>
                                    }
                                />
                            ))}
                        </TileRows>
                    )}
                </Tile>
                {billing && (
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
                {billing && (
                    <Tile kind="plain">
                        <TileHeader label={t('Report baseline')} end={<TileEdit onClick={open('baseline')}>{t('Edit')}</TileEdit>} />
                        {details.report_baseline_markdown ? (
                            <div className={styles.baseline} data-fill="">
                                <MarkdownView>{details.report_baseline_markdown}</MarkdownView>
                            </div>
                        ) : (
                            <TileNote>{t('Not set')}</TileNote>
                        )}
                    </Tile>
                )}
            </TileStack>
            <TileStack as="aside">
                <DocumentsSection clientId={client.id} documents={documents} />
                {card !== null && (
                    <Tile kind="plain">
                        <TileHeader
                            label={t('Field history')}
                            count={fieldHistoryCount}
                            end={
                                <SoloButtonLink href={clients.show({ client: client.id, tab: 'dziennik' }).url} variant="secondary" size="control">
                                    {t('Journal')}
                                </SoloButtonLink>
                            }
                        />
                        <div data-fill="">
                            <FieldHistory events={fieldHistory} />
                        </div>
                    </Tile>
                )}
                {owner && (
                    <Tile kind="list">
                        <TileHeader label={t('Client name')} />
                        <TileRows>
                            <Row
                                rule={false}
                                title={details.archived ? t('Restore client') : t('Archive client')}
                                action={<TileEdit onClick={open('archive')}>{details.archived ? t('Restore') : t('Archive')}</TileEdit>}
                            />
                            {!details.archived && (
                                <Row title={t('Delete client')} action={<TileEdit onClick={open('delete')}>{t('Delete')}</TileEdit>} />
                            )}
                        </TileRows>
                    </Tile>
                )}
            </TileStack>
            <ClientDetailsDialog clientId={client.id} details={details} open={dialog === 'details'} onOpenChange={toggle('details')} />
            <ContactDialog clientId={client.id} open={dialog === 'contact'} onOpenChange={toggle('contact')} />
            <MonthCloseDialog clientId={client.id} details={details} open={dialog === 'month-close'} onOpenChange={toggle('month-close')} />
            <BaselineDialog
                clientId={client.id}
                baseline={details.report_baseline_markdown}
                open={dialog === 'baseline'}
                onOpenChange={toggle('baseline')}
            />
            {card !== null && <ClientFieldsDialog clientId={client.id} card={card} open={dialog === 'fields'} onOpenChange={toggle('fields')} />}
            {stage !== null && <StageDialog clientId={client.id} current={stage.stage} open={dialog === 'stage'} onOpenChange={toggle('stage')} />}
            <ProjectFormDialog open={dialog === 'project'} onOpenChange={toggle('project')} clientId={client.id} />
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
            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={toggle('delete')}
                title={t('Delete client?')}
                description={t(
                    'The client leaves the list and the month close. Its tasks, time and history are kept, and an owner can restore it from the archived clients.',
                )}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() =>
                    router.delete(destroy(client.id).url, { data: deleteContacts && contacts.length > 0 ? { delete_contacts: true } : {} })
                }
            >
                {contacts.length > 0 && (
                    <label className={styles.check}>
                        <Checkbox checked={deleteContacts} onCheckedChange={(checked) => setDeleteContacts(checked === true)} />
                        <span>{t('Also delete {{count}} connected contact', { count: contacts.length })}</span>
                    </label>
                )}
            </ConfirmDialog>
        </TileGrid>
    );
}

/** "Trello · w zamknięciu · 1 repozytorium": what stands behind a project, in a few words. */
export function projectFacts(project: DetailsProject, billing: boolean, t: (key: string, options?: Record<string, unknown>) => string): string {
    return [
        project.is_private ? t('private') : 'Trello',
        billing && project.include_in_month_close ? t('in the close') : null,
        project.repositories > 0 ? t('repository_count', { count: project.repositories }) : t('no repository'),
    ]
        .filter(Boolean)
        .join(' · ');
}

/** Figma: Zmień etap relacji. The stage and an optional note; the change lands in the journal. */
function StageDialog({
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

/** The phone "more" menu: the two things added or edited most; from 768 px every tile has its own button. */
export function detailsActionOrder(inMenu: boolean): DetailsAction[] {
    return inMenu ? ['details', 'contact'] : [];
}

export function DetailsActions({ details, onOpen }: { details: ClientDetails; onOpen: (action: DetailsAction) => void }) {
    const { t } = useTranslation();

    return (
        <>
            {detailsActionOrder(useInHeaderMenu()).map((action) =>
                action === 'details' ? (
                    <ActionMenuItem key="details" icon={<Icon name="pencil" size={16} />} onSelect={() => onOpen('details')}>
                        {details.type === 'individual' ? t('Edit details') : t('Edit company data')}
                    </ActionMenuItem>
                ) : (
                    <ActionMenuItem key="contact" icon={<Icon name="plus" size={16} />} onSelect={() => onOpen('contact')}>
                        {t('New contact')}
                    </ActionMenuItem>
                ),
            )}
        </>
    );
}
