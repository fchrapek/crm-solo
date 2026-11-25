import InertiaPagination from '@/components/inertia-pagination';
import { Chip } from '@/components/solo/chip';
import { useLinkPrefetch } from '@/components/solo/link-prefetch';
import { ListTable, ListTableCell, ListTableHead, ListTableRow } from '@/components/solo/list-table';
import { Poster, SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileInset } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import clientsRoute from '@/routes/clients';
import contactsRoute from '@/routes/contacts';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { rangeOf } from '../clients/components/client-list/format';
import { ContactDialog, type ContactRecord } from '../clients/components/details-tab/contact-dialog';
import styles from './index.module.css';
import { contactsPlace, listQuery, listUrl, type ContactFilters } from './place';

export interface ContactRow extends ContactRecord {
    name: string;
    client: { id: number; name: string } | null;
}

interface ContactsPageProps {
    filters: ContactFilters;
    contacts: {
        data: ContactRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    clients: { id: number; name: string }[];
    opened: 'new' | ContactRecord | null;
    [key: string]: unknown;
}

type Open = { open: boolean; contact: ContactRecord | null };

const keyOf = (opened: ContactsPageProps['opened']) => (opened === null ? null : opened === 'new' ? 'new' : opened.id);

const restore = (contact: ContactRecord) => router.put(contactsRoute.restore(contact.id).url, {}, { preserveScroll: true });

/**
 * Figma: Contacts (1088:4520, phone 1088:4837). The list is one tile: a table from 768 px, the person and the client
 * on a phone. The command bar narrows it; create and edit are ContactDialog over the list.
 */
export default function ContactsIndex() {
    const { filters, contacts, clients, opened } = usePage<SharedData & ContactsPageProps>().props;
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<Open>({ open: opened !== null, contact: opened === null || opened === 'new' ? null : opened });
    const [openedKey, setOpenedKey] = useState(keyOf(opened));
    const [removing, setRemoving] = useState<ContactRecord | null>(null);

    // A visit that names a contact (?contact=) opens it; the same answer again (after a save) does not.
    if (keyOf(opened) !== openedKey) {
        setOpenedKey(keyOf(opened));
        if (opened !== null) setDialog({ open: true, contact: opened === 'new' ? null : opened });
    }

    const close = () => {
        setDialog((was) => ({ ...was, open: false }));
        if (new URL(window.location.href).searchParams.has('contact')) {
            router.get(contactsRoute.index.url(), listQuery(filters), { preserveState: true, preserveScroll: true, replace: true });
        }
    };
    const remove = (contact: ContactRecord) => {
        close();
        if (contact.deleted_at === null) {
            setRemoving(contact);
        } else {
            restore(contact);
        }
    };
    const create = () => setDialog({ open: true, contact: null });
    const narrowed = filters.search !== '';

    return (
        <SoloSurface day="paper">
            <Head title={t('Contacts')} />
            <main className={styles.page}>
                <header className={styles.head}>
                    <Poster>{t('Contacts')}</Poster>
                    <SoloButton size="control" onClick={create}>
                        {t('New contact')}
                    </SoloButton>
                </header>
                {narrowed && (
                    <Tile kind="list" as="div">
                        <Row
                            as="div"
                            rule={false}
                            title={t('Results for "{{query}}"', { query: filters.search })}
                            meta={contacts.total}
                            action={
                                <SoloButtonLink href={listUrl({ ...filters, search: '' })} variant="secondary" size="control">
                                    {t('Clear')}
                                </SoloButtonLink>
                            }
                        />
                    </Tile>
                )}
                <Tile kind="list" as="div">
                    {contacts.data.length === 0 ? (
                        <TileInset>
                            <p className={styles.empty}>{narrowed ? t('No contacts found.') : t('No contacts yet.')}</p>
                            {!narrowed && (
                                <SoloButton size="control" variant="secondary" onClick={create}>
                                    {t('New contact')}
                                </SoloButton>
                            )}
                        </TileInset>
                    ) : (
                        <div className={styles.table}>
                            <ContactTable rows={contacts.data} onOpen={(contact) => setDialog({ open: true, contact })} onRestore={restore} />
                        </div>
                    )}
                    <div className={styles.foot}>
                        <SoloButtonLink
                            href={listUrl({ ...filters, trashed: filters.trashed === null ? 'with' : null })}
                            variant="quiet"
                            size="control"
                            preserveScroll
                            replace
                        >
                            {filters.trashed === null ? t('Show deleted') : t('Hide deleted')}
                        </SoloButtonLink>
                    </div>
                    <InertiaPagination
                        look="tile"
                        links={contacts.links}
                        preserveScroll
                        summary={t('{{range}} of {{total}}', { range: rangeOf(contacts.from ?? 0, contacts.to ?? 0), total: contacts.total })}
                    />
                </Tile>
            </main>

            <ContactDialog open={dialog.open} onOpenChange={(open) => !open && close()} clients={clients} contact={dialog.contact} onRemove={remove} />
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(value) => !value && setRemoving(null)}
                title={t('Delete contact')}
                description={t('Are you sure you want to delete "{{name}}"?', {
                    name: removing === null ? '' : `${removing.first_name} ${removing.last_name}`,
                })}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() => {
                    if (removing !== null) router.delete(contactsRoute.destroy(removing.id).url, { preserveScroll: true });
                    setRemoving(null);
                }}
            />
        </SoloSurface>
    );
}

ContactsIndex.place = contactsPlace satisfies PlaceResolver<ContactsPageProps>;

/** Person, client, e-mail. A phone keeps the person and the client; a deleted contact carries its chip (from 768 px) and the way back. */
export function ContactTable({ rows, onOpen, onRestore }: { rows: ContactRow[]; onOpen: (contact: ContactRow) => void; onRestore: (contact: ContactRow) => void }) {
    const { t } = useTranslation();
    const prefetch = useLinkPrefetch();
    const clientHref = (id: number) => clientsRoute.show({ client: id, tab: 'overview' });
    const anyDeleted = rows.some((contact) => contact.deleted_at !== null);

    return (
        <ListTable label={t('Contacts')}>
            <thead>
                <tr>
                    <ListTableHead>{t('Person')}</ListTableHead>
                    <ListTableHead narrow />
                    <ListTableHead wide>{t('Client name')}</ListTableHead>
                    <ListTableHead wide>{t('Email')}</ListTableHead>
                    {anyDeleted && <ListTableHead align="end" wide aria-label={t('Status')} />}
                </tr>
            </thead>
            <tbody>
                {rows.map((contact) => (
                    <ListTableRow key={contact.id} minor={contact.deleted_at !== null}>
                        <ListTableCell kind="title">
                            <button type="button" className={styles.rowButton} title={contact.name} onClick={() => onOpen(contact)}>
                                {contact.name}
                            </button>
                        </ListTableCell>
                        <ListTableCell kind="meta" align="end" narrow>
                            {contact.deleted_at !== null ? (
                                <SoloButton size="control" variant="secondary" onClick={() => onRestore(contact)}>
                                    {t('Restore')}
                                </SoloButton>
                            ) : (
                                (contact.client?.name ?? t('No client'))
                            )}
                        </ListTableCell>
                        <ListTableCell kind={contact.client === null ? 'meta' : 'text'} wide>
                            {contact.client === null ? (
                                t('No client')
                            ) : (
                                <Link href={clientHref(contact.client.id)} prefetch={prefetch(clientHref(contact.client.id))} className={styles.rowLink} title={contact.client.name}>
                                    {contact.client.name}
                                </Link>
                            )}
                        </ListTableCell>
                        <ListTableCell kind="meta" wide>
                            {contact.emails[0] ?? ''}
                        </ListTableCell>
                        {anyDeleted && (
                            <ListTableCell align="end" wide>
                                {contact.deleted_at !== null && (
                                    <span className={styles.deleted}>
                                        <Chip status="open">{t('deleted_contact_chip')}</Chip>
                                        <SoloButton size="control" variant="secondary" onClick={() => onRestore(contact)}>
                                            {t('Restore')}
                                        </SoloButton>
                                    </span>
                                )}
                            </ListTableCell>
                        )}
                    </ListTableRow>
                ))}
            </tbody>
        </ListTable>
    );
}
