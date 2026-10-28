import { destroy, restore } from '@/actions/App/Http/Controllers/ClientsController';
import { MarkdownView } from '@/components/markdown-view';
import { ActionMenuItem } from '@/components/solo/action-menu';
import { type ClientHeaderData, useInHeaderMenu } from '@/components/solo/client-header';
import { KvRow } from '@/components/solo/kv-row';
import { ListRow } from '@/components/solo/list-row';
import { BodyLink, SectionAction, SectionHead, SectionLink, touchTargetClass } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { localeOf } from '@/lib/hours-text';
import clients from '@/routes/clients';
import contactRoutes from '@/routes/contacts';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { type ClientCard, ClientFieldsDialog, type FieldEvent, FieldHistory } from '../client-fields';
import { BaselineDialog } from './baseline-dialog';
import { ClientDetailsDialog } from './client-details-dialog';
import { ContactDialog } from './contact-dialog';
import styles from './details-tab.module.css';
import { DocumentsSection } from './documents-section';
import { addressLine, cooperationLine, taxIdLine } from './format';
import { MONTH_CLOSE_LABEL, MonthCloseDialog, REPORT_MODE_LABEL } from './month-close-dialog';
import type { ClientContactRow, ClientDetails, ClientDocumentRow } from './types';

export type DetailsDialog = 'details' | 'contact' | 'month-close' | 'baseline' | 'archive' | 'fields' | null;

/** Figma: Klient / Dane, and below 768 px 335:4147 (a private person 347:5178), where the ••• menu edits the data. */
export function DetailsTab({
    client,
    details,
    contacts,
    documents = [],
    card = null,
    fieldHistory = [],
    fieldHistoryCount = fieldHistory.length,
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
    dialog: DetailsDialog;
    onDialogChange: (dialog: DetailsDialog) => void;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const openDialog = (next: Exclude<DetailsDialog, null>) => () => onDialogChange(next);
    const notSet = t('Not set');
    const address = addressLine(details);
    const cooperation = cooperationLine(details, t);
    const individual = details.type === 'individual';
    const billing = client.billing !== false;
    const reach = (
        <>
            <KvRow label={t('Phone')} weight="regular" tone={details.phone ? undefined : 'muted'}>
                {details.phone ? (
                    <a href={`tel:${details.phone}`} className={`${styles.contactLink} ${touchTargetClass}`}>
                        {details.phone}
                    </a>
                ) : (
                    notSet
                )}
            </KvRow>
            <KvRow label={t('Email')} weight="regular" tone={details.email ? undefined : 'muted'}>
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
    const edit = (
        <SectionAction className={styles.wideOnly} aria-haspopup="dialog" onClick={() => onDialogChange('details')}>
            {t('Edit')}
        </SectionAction>
    );

    return (
        <div className={`${splitClass} ${styles.body}`}>
            <div className={`${splitMainClass} ${styles.main}`}>
                <section>
                    <SectionHead kind="body" label={individual ? t('Individual') : t('Company')} action={edit} />
                    <dl className={styles.list}>
                        <KvRow
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
                            <KvRow label={t('Tax ID (NIP)')} tone={details.tax_id ? undefined : 'muted'}>
                                {taxIdLine(details.tax_id) || notSet}
                            </KvRow>
                        )}
                        <KvRow label={t('Address')} tone={address ? undefined : 'muted'}>
                            {address || notSet}
                        </KvRow>
                        {!individual && details.business_type && <KvRow label={t('Business Type')}>{details.business_type}</KvRow>}
                        {!individual && reach}
                        {billing && (
                            <KvRow label={t('Cooperation')} tone={cooperation ? undefined : 'muted'}>
                                {cooperation || notSet}
                            </KvRow>
                        )}
                        {billing && details.hourly_rate !== null && (
                            <KvRow label={t('Rate')}>
                                {t('{{amount}} net / h', { amount: money(details.hourly_rate, details.currency, locale) })}
                            </KvRow>
                        )}
                        {details.segment !== null && <KvRow label={t('Segment')}>{t(SEGMENT_LABEL[details.segment] ?? details.segment)}</KvRow>}
                        {billing && (
                            <KvRow label="Infakt" tone={details.infakt_id ? 'agent' : 'muted'}>
                                {details.infakt_id ? t('client {{id}}', { id: details.infakt_id }) : t('Not linked')}
                            </KvRow>
                        )}
                    </dl>
                </section>

                {individual && (
                    <section className={styles.next}>
                        <SectionHead kind="body" label={t('Contact details')} action={edit} />
                        <dl className={styles.list}>{reach}</dl>
                    </section>
                )}

                {card !== null && (
                    <section className={styles.next}>
                        <SectionHead
                            kind="body"
                            label={`${t('Client fields')} · ${card.fields.filter((field) => !field.archived).length}`}
                            action={
                                <SectionAction aria-haspopup="dialog" onClick={openDialog('fields')}>
                                    {t('Edit')}
                                </SectionAction>
                            }
                        />
                        <dl className={styles.list}>
                            <KvRow label={t('Assignee')} tone={card.assignee ? undefined : 'muted'}>
                                {card.assignee?.name ?? notSet}
                            </KvRow>
                            <KvRow label={t('Send SMS')}>{card.sms_opt_out ? t('No') : t('Yes')}</KvRow>
                            {card.fields.map((field) => (
                                <KvRow
                                    key={field.key}
                                    label={field.archived ? t('{{label}} (archived)', { label: field.label }) : field.label}
                                    tone={field.display === null || field.archived ? 'muted' : undefined}
                                >
                                    {field.display === null ? (
                                        notSet
                                    ) : field.type === 'url' ? (
                                        <a href={field.display} target="_blank" rel="noopener noreferrer" className={styles.contactLink}>
                                            {field.display.replace(/^https?:\/\//, '')}
                                        </a>
                                    ) : (
                                        field.display
                                    )}
                                </KvRow>
                            ))}
                        </dl>
                    </section>
                )}

                <section className={styles.next}>
                    {/* A private person without contacts ends at Kontakt on phones (347:5178); Nowy kontakt stays in the menu. */}
                    <div className={individual && contacts.length === 0 ? styles.wideOnly : styles.contents}>
                        <SectionHead
                            kind="body"
                            label={`${t('Contacts')} · ${contacts.length}`}
                            action={
                                <SectionAction aria-haspopup="dialog" onClick={() => onDialogChange('contact')}>
                                    {t('New contact')}
                                </SectionAction>
                            }
                        />
                        {contacts.length === 0 ? (
                            <p className={styles.empty}>{t('No contacts yet.')}</p>
                        ) : (
                            <>
                                <dl className={`${styles.list} ${styles.wideOnly}`}>
                                    {contacts.map((contact) => (
                                        <KvRow key={contact.id} label={contact.name} weight="regular">
                                            {contact.email ? (
                                                <a href={`mailto:${contact.email}`}>{contact.email}</a>
                                            ) : contact.phone ? (
                                                <a href={`tel:${contact.phone}`}>{contact.phone}</a>
                                            ) : (
                                                <span className={styles.muted}>{notSet}</span>
                                            )}
                                        </KvRow>
                                    ))}
                                </dl>
                                <ul className={`${styles.list} ${styles.phoneOnly}`}>
                                    {contacts.map((contact) => (
                                        <li key={contact.id}>
                                            <ListRow
                                                href={contactRoutes.edit(contact.id).url}
                                                title={contact.name}
                                                sub={contact.email ?? contact.phone ?? notSet}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>
                </section>

                {billing && (
                    <section className={styles.next}>
                        <SectionHead
                            kind="body"
                            label={t('Month close')}
                            action={
                                <SectionAction aria-haspopup="dialog" onClick={openDialog('month-close')}>
                                    {t('Edit')}
                                </SectionAction>
                            }
                        />
                        <dl className={styles.list}>
                            <KvRow label={t('Close type')}>{t(MONTH_CLOSE_LABEL[details.month_close_type ?? '__none__'])}</KvRow>
                            <KvRow label={t('Deliverable')}>{t(REPORT_MODE_LABEL[details.report_mode])}</KvRow>
                            <KvRow label={t('In the close')}>{details.include_in_month_close ? t('Yes, every month') : t('No')}</KvRow>
                            <KvRow label="SSH" tone={details.ssh_config ? undefined : 'muted'}>
                                {details.ssh_config || notSet}
                            </KvRow>
                            <KvRow label={t('Invoice line')} tone={details.maintenance_invoice_description ? undefined : 'muted'}>
                                {details.maintenance_invoice_description || notSet}
                            </KvRow>
                        </dl>
                    </section>
                )}

                {billing && (
                    <section className={styles.next}>
                        <SectionHead
                            kind="body"
                            label={t('Report baseline')}
                            action={
                                <SectionAction aria-haspopup="dialog" onClick={openDialog('baseline')}>
                                    {t('Edit')}
                                </SectionAction>
                            }
                        />
                        {details.report_baseline_markdown ? (
                            <div className={styles.baseline}>
                                <MarkdownView>{details.report_baseline_markdown}</MarkdownView>
                            </div>
                        ) : (
                            <p className={styles.empty}>{t('Not set')}</p>
                        )}
                    </section>
                )}
            </div>

            <aside className={`${splitSideClass} ${styles.side}`}>
                <DocumentsSection clientId={client.id} documents={documents} />
                {card !== null && (
                    <section className={styles.next}>
                        <SectionHead
                            kind="body"
                            label={`${t('Field history')} · ${fieldHistoryCount}`}
                            action={<SectionLink href={clients.show({ client: client.id, tab: 'dziennik' }).url}>{t('Journal')} →</SectionLink>}
                        />
                        <FieldHistory events={fieldHistory} />
                    </section>
                )}
            </aside>

            <div className={styles.end}>
                <BodyLink href={`${clients.edit(client.id).url}#data`} className={touchTargetClass}>
                    {t('More settings')} →
                </BodyLink>
                <button type="button" className={styles.archive} onClick={openDialog('archive')}>
                    {details.archived ? t('Restore client') : t('Archive client…')}
                </button>
            </div>

            <ClientDetailsDialog
                clientId={client.id}
                details={details}
                open={dialog === 'details'}
                onOpenChange={(open) => onDialogChange(open ? 'details' : null)}
            />
            <ContactDialog clientId={client.id} open={dialog === 'contact'} onOpenChange={(open) => onDialogChange(open ? 'contact' : null)} />
            <MonthCloseDialog
                clientId={client.id}
                details={details}
                open={dialog === 'month-close'}
                onOpenChange={(open) => onDialogChange(open ? 'month-close' : null)}
            />
            <BaselineDialog
                clientId={client.id}
                baseline={details.report_baseline_markdown}
                open={dialog === 'baseline'}
                onOpenChange={(open) => onDialogChange(open ? 'baseline' : null)}
            />
            {card !== null && (
                <ClientFieldsDialog
                    clientId={client.id}
                    card={card}
                    open={dialog === 'fields'}
                    onOpenChange={(open) => onDialogChange(open ? 'fields' : null)}
                />
            )}
            <ConfirmDialog
                open={dialog === 'archive'}
                onOpenChange={(open) => onDialogChange(open ? 'archive' : null)}
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
        </div>
    );
}

const SEGMENT_LABEL: Record<string, string> = { agency: 'Agency', smb: 'Small / mid business', enterprise: 'Enterprise', individual: 'Individual' };

function money(amount: number, currency: string, locale: string): string {
    return new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: 2 }).format(amount);
}

export type DetailsAction = Exclude<DetailsDialog, null>;

/** The phone ••• menu (Figma 347:5037); desktop keeps Edytuj and Nowy kontakt on the section heads. */
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
