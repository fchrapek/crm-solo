import { KvRow } from '@/components/solo/kv-row';
import { SectionLink } from '@/components/solo/primitives';
import { Tile, TileHeader } from '@/components/solo/tile';
import styles from '@/pages/settings/components/tab.module.css';
import settings from '@/routes/settings';
import { useTranslation } from 'react-i18next';
import type { ProfileProps } from '../types';

/** Figma: Mój profil, Konto (1556:19970; a member's 1557:346862). The account and my role; an owner goes on to its users, a member reads who owns it. */
export function AccountTile({ account, owner }: { account: ProfileProps['account']; owner: boolean }) {
    const { t } = useTranslation();

    return (
        <Tile kind="plain">
            <TileHeader label={t('Account')} end={owner ? <SectionLink href={settings.show('general')}>{t('Users')}</SectionLink> : undefined} />
            <dl className={styles.kv}>
                <KvRow layout="tile" label={t('Name')}>
                    {account.name}
                </KvRow>
                <KvRow layout="tile" label={t('Role')}>
                    {owner ? t('owner') : t('user')}
                </KvRow>
                {account.owners.length > 0 && (
                    <KvRow layout="tile" label={t('Owner')}>
                        {account.owners.join(', ')}
                    </KvRow>
                )}
            </dl>
        </Tile>
    );
}
