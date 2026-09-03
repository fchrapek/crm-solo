import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

import { SharedData } from '@/types';

import styles from './test-account-banner.module.css';

export function TestAccountBanner() {
    const { t } = useTranslation();
    const { auth } = usePage<SharedData & { auth: { user: { account: { name: string; is_test: boolean } } | null } }>().props;

    if (!auth?.user?.account?.is_test) {
        return null;
    }

    return (
        <div className={styles.banner} role="alert">
            <span className={styles.label}>{t('test_account_banner', { name: auth.user.account.name })}</span>
        </div>
    );
}
