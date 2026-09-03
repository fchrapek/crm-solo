import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, Trash, Trash2 } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import AnchorLink from '@/components/anchor-link';
import InertiaPagination from '@/components/inertia-pagination';
import SearchFilter from '@/components/search-filter';
import { TableContainer } from '@/components/table-container';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePageActions } from '@/contexts/page-context';
import contactsRoute from '@/routes/contacts';
import { BreadcrumbItem, Contact, PaginatedData, SharedData } from '@/types';

import styles from './index.module.css';

interface IndexPageProps extends SharedData {
    contacts: PaginatedData<Contact>;
}

export default function Index() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Contact',
                count: 2,
                href: contactsRoute.index().url,
            },
        ],
        [],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const { contacts: contactsData } = usePage<IndexPageProps>().props;
    const {
        data,
        meta: { links },
    } = contactsData;

    const [deleteTarget, setDeleteTarget] = useState<Contact | null>(null);

    const handleDelete = () => {
        if (!deleteTarget) return;

        router.delete(contactsRoute.destroy(deleteTarget.id).url, {
            preserveScroll: true,
            onFinish: () => setDeleteTarget(null),
        });
    };

    return (
        <>
            <Head title={t('Contact', { count: 2 })} />

            <div className="pageContainer">
                <div className="headerBar">
                    <SearchFilter />

                    <div className="createButtonContainer">
                        <AnchorLink href={contactsRoute.create().url}>
                            <span className="mobileOnly">{t('Create')}</span>
                            <span className="desktopOnly">{t('Create Contact')}</span>
                        </AnchorLink>
                    </div>
                </div>

                <TableContainer>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('Name')}</TableHead>
                            <TableHead>{t('Client', { count: 1 })}</TableHead>
                            <TableHead>{t('City')}</TableHead>
                            <TableHead>{t('Phone')}</TableHead>
                            <TableHead style={{ width: '50px' }}></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.map((contact) => (
                            <TableRow key={contact.id}>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={contactsRoute.edit(contact.id)} prefetch className="cellLink">
                                            <span className="srOnly">Edit {contact.name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">
                                        {contact.name}
                                        {contact.deleted_at && <Trash className="trashIcon" />}
                                    </div>
                                </TableCell>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={contactsRoute.edit(contact.id)} prefetch tabIndex={-1} className="cellLink">
                                            <span className="srOnly">Edit {contact.name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">{contact.client ? contact.client.name : ''}</div>
                                </TableCell>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={contactsRoute.edit(contact.id)} prefetch tabIndex={-1} className="cellLink">
                                            <span className="srOnly">Edit {contact.name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">{contact.city}</div>
                                </TableCell>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={contactsRoute.edit(contact.id)} prefetch tabIndex={-1} className="cellLink">
                                            <span className="srOnly">Edit {contact.name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">{contact.phone}</div>
                                </TableCell>
                                <TableCell style={{ width: '1px' }}>
                                    <div className={styles.actionsCell}>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title={t('Delete')}
                                            onClick={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                setDeleteTarget(contact);
                                            }}
                                        >
                                            <Trash2 size={16} style={{ color: 'var(--color-muted-foreground)' }} />
                                        </Button>
                                        <Button asChild variant="ghost" size="icon">
                                            <Link tabIndex={-1} href={contactsRoute.edit(contact.id)} prefetch>
                                                <ChevronRight className="cellIcon" style={{ color: 'var(--color-muted-foreground)' }} />
                                            </Link>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                        {data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={5} className="emptyCell">
                                    {t('No contacts found.')}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </TableContainer>

                <div className="paginationContainer">
                    <InertiaPagination links={links} />
                </div>
            </div>

            <AlertDialog open={!!deleteTarget} onOpenChange={(open) => !open && setDeleteTarget(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete contact')}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('Are you sure you want to delete "{{name}}"?', { name: deleteTarget?.name })}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={handleDelete} className={styles.deleteButton}>
                            {t('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
