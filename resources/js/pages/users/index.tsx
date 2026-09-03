import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Trash } from 'lucide-react';
import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import AnchorLink from '@/components/anchor-link';
import InertiaPagination from '@/components/inertia-pagination';
import SearchFilter from '@/components/search-filter';
import { TableContainer } from '@/components/table-container';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePageActions } from '@/contexts/page-context';
import users from '@/routes/users';
import { BreadcrumbItem, PaginatedData, SharedData, User } from '@/types';

interface IndexPageProps extends SharedData {
    users: PaginatedData<User>;
}

export default function Index() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'User',
                count: 2,
                href: users.index().url,
            },
        ],
        [],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const { users: usersData } = usePage<IndexPageProps>().props;
    const {
        data,
        meta: { links },
    } = usersData;

    return (
        <>
            <Head title={t('User', { count: 2 })} />

            <div className="pageContainer">
                <div className="headerBar">
                    <SearchFilter />

                    <div className="createButtonContainer">
                        <AnchorLink href={users.create().url}>
                            <span className="mobileOnly">{t('Create')}</span>
                            <span className="desktopOnly">{t('Create User')}</span>
                        </AnchorLink>
                    </div>
                </div>

                <TableContainer>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('Name')}</TableHead>
                            <TableHead>{t('Email')}</TableHead>
                            <TableHead>{t('Role')}</TableHead>
                            <TableHead style={{ width: '50px' }}></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.map(({ id, name, email, owner, deleted_at }) => (
                            <TableRow key={id}>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={users.edit(id)} prefetch className="cellLink">
                                            <span className="srOnly">Modifier {name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">
                                        {name}
                                        {deleted_at && <Trash className="trashIcon" />}
                                    </div>
                                </TableCell>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={users.edit(id)} prefetch tabIndex={-1} className="cellLink">
                                            <span className="srOnly">Modifier {name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">{email}</div>
                                </TableCell>
                                <TableCell className="cellRelative">
                                    <div className="cellOverlay">
                                        <Link href={users.edit(id)} prefetch tabIndex={-1} className="cellLink">
                                            <span className="srOnly">Modifier {name}</span>
                                        </Link>
                                    </div>
                                    <div className="cellContent">{owner ? t('Owner') : t('User')}</div>
                                </TableCell>
                                <TableCell style={{ width: '1px' }}>
                                    <Button asChild variant="ghost" size="icon">
                                        <Link tabIndex={-1} href={users.edit(id)} prefetch>
                                            <ChevronRight className="cellIcon" style={{ color: 'var(--color-muted-foreground)' }} />
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                        {data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={4} className="emptyCell">
                                    {t('No users found.')}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </TableContainer>

                <div className="paginationContainer">
                    <InertiaPagination links={links} />
                </div>
            </div>
        </>
    );
}
