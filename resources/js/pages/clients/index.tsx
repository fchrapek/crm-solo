import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, Pin, Trash, Trash2 } from 'lucide-react';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePageActions } from '@/contexts/page-context';
import { lifecycleStageLabelKey, lifecycleStageTone } from '@/lib/lifecycle-stage';
import clients from '@/routes/clients';
import { BreadcrumbItem, Client, PaginatedData, SharedData } from '@/types';

import styles from './index.module.css';

interface IndexPageProps extends SharedData {
    clients: PaginatedData<Client>;
}

export default function Index() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Client',
                count: 2,
                href: clients.index().url,
            },
        ],
        [],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const { clients: clientsData } = usePage<IndexPageProps>().props;
    const {
        data,
        meta: { links },
    } = clientsData;

    const [deleteTarget, setDeleteTarget] = useState<Client | null>(null);
    const [deleteContacts, setDeleteContacts] = useState(false);

    const handleDelete = () => {
        if (!deleteTarget) return;

        router.delete(clients.destroy(deleteTarget.id).url, {
            data: { delete_contacts: deleteContacts },
            preserveScroll: true,
            onFinish: () => {
                setDeleteTarget(null);
                setDeleteContacts(false);
            },
        });
    };

    return (
        <>
            <Head title={t('Client', { count: 2 })} />

            <div className="pageContainer">
                <div className="headerBar">
                    <SearchFilter />

                    <div className="createButtonContainer">
                        <AnchorLink href={clients.create().url}>
                            <span className="mobileOnly">{t('Create')}</span>
                            <span className="desktopOnly">{t('Create Client')}</span>
                        </AnchorLink>
                    </div>
                </div>

                <TableContainer>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('Name')}</TableHead>
                            <TableHead>{t('Stage')}</TableHead>
                            <TableHead>{t('City')}</TableHead>
                            <TableHead>{t('Phone')}</TableHead>
                            <TableHead style={{ width: '50px' }}></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.map((client) => (
                            <TableRow key={client.id}>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={clients.edit(client.id)} prefetch className="cellLink">
                                            <span className="srOnly">Edit {client.name}</span>
                                        </Link>
                                    </div>
                                    <div className={styles.nameCell}>
                                        <button
                                            className={styles.pinButton}
                                            data-pinned={client.is_pinned}
                                            title={client.is_pinned ? t('Unpin') : t('Pin')}
                                            onClick={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                router.put(clients.pin(client.id).url, {}, { preserveScroll: true });
                                            }}
                                        >
                                            <Pin size={14} />
                                        </button>
                                        <span className="cellContent">
                                            {client.name}
                                            {client.deleted_at && <Trash className="trashIcon" />}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={clients.edit(client.id)} prefetch tabIndex={-1} className="cellLink">
                                            <span className="srOnly">Edit {client.name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">
                                        <span className={styles.stageBadge} data-tone={lifecycleStageTone(client.lifecycle_stage)}>
                                            {t(lifecycleStageLabelKey(client.lifecycle_stage))}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={clients.edit(client.id)} prefetch tabIndex={-1} className="cellLink">
                                            <span className="srOnly">Edit {client.name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">{client.city}</div>
                                </TableCell>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={clients.edit(client.id)} prefetch tabIndex={-1} className="cellLink">
                                            <span className="srOnly">Edit {client.name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">{client.phone}</div>
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
                                                setDeleteTarget(client);
                                                setDeleteContacts(false);
                                            }}
                                        >
                                            <Trash2 size={16} style={{ color: 'var(--color-muted-foreground)' }} />
                                        </Button>
                                        <Button asChild variant="ghost" size="icon">
                                            <Link tabIndex={-1} href={clients.edit(client.id)} prefetch>
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
                                    {t('No clients found.')}
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
                        <AlertDialogTitle>{t('Delete client')}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('Are you sure you want to delete "{{name}}"?', { name: deleteTarget?.name })}
                        </AlertDialogDescription>
                    </AlertDialogHeader>

                    {deleteTarget && deleteTarget.contacts_count > 0 && (
                        <div className={styles.checkboxRow}>
                            <Checkbox
                                id="delete-contacts"
                                checked={deleteContacts}
                                onCheckedChange={(checked) => setDeleteContacts(Boolean(checked))}
                            />
                            <Label htmlFor="delete-contacts">
                                {t('Also delete {{count}} connected contact(s)', { count: deleteTarget.contacts_count })}
                            </Label>
                        </div>
                    )}

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
