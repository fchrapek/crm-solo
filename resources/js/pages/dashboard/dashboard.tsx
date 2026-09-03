import { Head, usePage } from '@inertiajs/react';
import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import { DailySessionCard, type DailySessionState } from '@/components/daily-session-card';
import { usePageActions } from '@/contexts/page-context';
import { dashboard } from '@/routes';
import { BreadcrumbItem, SharedData } from '@/types';

interface DashboardPageProps extends SharedData {
    dailySession: DailySessionState;
}

/**
 * Agent-first dashboard: the daily session (herdr agent states + optional
 * browser viewport) is the panel. Task attention lives in `crm today` and
 * the client record views - the old attention widget was removed 2026-07-29.
 */
export default function Dashboard() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();
    const { dailySession } = usePage<DashboardPageProps>().props;

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Dashboard',
                href: dashboard().url,
            },
        ],
        [],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    return (
        <>
            <Head title={t('Dashboard')} />

            <DailySessionCard session={dailySession} />
        </>
    );
}
