import FlashMessages from '@/components/flash-messages';
import InertiaPagination from '@/components/inertia-pagination';
import { IconButton } from '@/components/solo/icon-button';
import { ListRow } from '@/components/solo/list-row';
import { Masthead } from '@/components/solo/masthead';
import { Poster, SectionAction } from '@/components/solo/primitives';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import clientsRoute from '@/routes/clients';
import contactsRoute from '@/routes/contacts';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { rangeOf } from '../clients/components/client-list/format';
import { ContactDialog, type ContactRecord } from '../clients/components/details-tab/contact-dialog';
import styles from './index.module.css';

export interface ContactRow extends ContactRecord {
    name: string;
    client: { id: number; name: string } | null;
}

interface ContactsPageProps {
    filters: { search: string; trashed: 'with' | null };
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

const SEARCH_DEBOUNCE_MS = 250;

type Open = { open: boolean; contact: ContactRecord | null };

/** The list's query string: the search, and the deleted contacts when they are shown. */
const listQuery = (search: string, trashed: 'with' | null) => ({ ...(search === '' ? {} : { search }), ...(trashed === null ? {} : { trashed }) });

const keyOf = (opened: ContactsPageProps['opened']) => (opened === null ? null : opened === 'new' ? 'new' : opened.id);

/** Figma: Kontakty (177:74), phones 473:13791; create and edit are ContactDialog over the list. */
export default function ContactsIndex() {
    const { filters, contacts, clients, opened } = usePage<SharedData & ContactsPageProps>().props;
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.search);
    const sent = useRef(filters.search);
    const [dialog, setDialog] = useState<Open>({ open: opened !== null, contact: opened === null || opened === 'new' ? null : opened });
    const [openedKey, setOpenedKey] = useState(keyOf(opened));
    const [removing, setRemoving] = useState<ContactRecord | null>(null);

    // A visit that names a contact (?kontakt=) opens it; the same answer again (after a save) does not.
    if (keyOf(opened) !== openedKey) {
        setOpenedKey(keyOf(opened));
        if (opened !== null) setDialog({ open: true, contact: opened === 'new' ? null : opened });
    }

    useEffect(() => {
        const next = search.trim();
        if (next === filters.search || next === sent.current) return;
        const timer = window.setTimeout(() => {
            sent.current = next;
            router.get(contactsRoute.index.url(), listQuery(next, filters.trashed), { preserveState: true, preserveScroll: true, replace: true });
        }, SEARCH_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [search, filters.search, filters.trashed]);

    const close = () => {
        setDialog((was) => ({ ...was, open: false }));
        if (new URL(window.location.href).searchParams.has('kontakt')) {
            router.get(contactsRoute.index.url(), listQuery(filters.search, filters.trashed), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }
    };
    const remove = (contact: ContactRecord) => {
        close();
        if (contact.deleted_at === null) {
            setRemoving(contact);
        } else {
            router.put(contactsRoute.restore(contact.id).url, {}, { preserveScroll: true });
        }
    };

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={t('Contacts')} />
            <div className={styles.frame}>
                <Masthead current="contacts" />
                <main>
                    <div className={styles.navBar}>
                        <IconButton icon="plus" label={t('New contact')} onClick={() => setDialog({ open: true, contact: null })} />
                    </div>
                    <header className={styles.head}>
                        <div className={styles.actionRow}>
                            <SectionAction onClick={() => setDialog({ open: true, contact: null })}>{t('New contact')}</SectionAction>
                        </div>
                        <Poster>{t('Contacts')}</Poster>
                        <div className={styles.searchBar} data-solo-fab="">
                            <label className={styles.searchField}>
                                <Icon name="search" size={20} className={styles.searchIcon} />
                                <input
                                    type="search"
                                    className={styles.search}
                                    value={search}
                                    placeholder={t('Search a person, company or email')}
                                    aria-label={t('Search contacts')}
                                    onChange={(event) => setSearch(event.target.value)}
                                />
                            </label>
                        </div>
                    </header>

                    <table className={styles.table} aria-label={t('Contacts')}>
                        <colgroup>
                            <col className={styles.colPerson} />
                            <col className={styles.colClient} />
                            <col />
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">{t('Person')}</th>
                                <th scope="col">{t('Client name')}</th>
                                <th scope="col">{t('Email')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {contacts.data.map((contact) => (
                                <tr key={contact.id} data-deleted={contact.deleted_at !== null || undefined}>
                                    <td>
                                        <button type="button" className={styles.rowButton} onClick={() => setDialog({ open: true, contact })}>
                                            {contact.name}
                                        </button>
                                    </td>
                                    <td>
                                        {contact.client !== null && (
                                            <Link
                                                href={clientsRoute.show({ client: contact.client.id, tab: 'przeglad' })}
                                                className={styles.clientLink}
                                            >
                                                {contact.client.name}
                                            </Link>
                                        )}
                                    </td>
                                    <td className={styles.muted}>{contact.emails[0] ?? ''}</td>
                                </tr>
                            ))}
                            {contacts.data.length === 0 && (
                                <tr>
                                    <td colSpan={3} className={styles.empty}>
                                        {t('No contacts found.')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>

                    <ul className={styles.phoneList} aria-label={t('Contacts')}>
                        {contacts.data.map((contact) => (
                            <li key={contact.id}>
                                <ListRow
                                    title={contact.name}
                                    sub={
                                        contact.deleted_at !== null
                                            ? t('deleted, can be restored')
                                            : (contact.client?.name ?? contact.emails[0] ?? undefined)
                                    }
                                    onClick={() => setDialog({ open: true, contact })}
                                />
                            </li>
                        ))}
                        {contacts.data.length === 0 && <li className={styles.empty}>{t('No contacts found.')}</li>}
                    </ul>

                    <SectionAction
                        className={styles.trashed}
                        onClick={() =>
                            router.get(contactsRoute.index.url(), listQuery(filters.search, filters.trashed === null ? 'with' : null), {
                                preserveScroll: true,
                                preserveState: true,
                                replace: true,
                            })
                        }
                    >
                        {filters.trashed === null ? t('Show deleted') : t('Hide deleted')}
                    </SectionAction>

                    <InertiaPagination
                        look="solo"
                        links={contacts.links}
                        preserveScroll
                        summary={t('{{range}} of {{total}}', { range: rangeOf(contacts.from ?? 0, contacts.to ?? 0), total: contacts.total })}
                    />
                </main>
            </div>

            <ContactDialog
                open={dialog.open}
                onOpenChange={(open) => !open && close()}
                clients={clients}
                contact={dialog.contact}
                onRemove={remove}
            />
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
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}
