import { KvRow } from '@/components/solo/kv-row';
import { Tile, TileHeader } from '@/components/solo/tile';
import { TileButton } from '@/pages/settings/components/parts';
import styles from '@/pages/settings/components/tab.module.css';
import { UserDialog, type UserDialogMode } from '@/pages/settings/components/user-dialog';
import type { Me } from '@/pages/settings/types';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

/** Figma: Mój profil, Profil (1556:19970). Name, e-mail and password, changed in the profile form. */
export function ProfileTile({ me, owner }: { me: Me; owner: boolean }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<UserDialogMode | null>(null);

    return (
        <>
            <Tile kind="plain">
                <TileHeader
                    label={t('Profile')}
                    end={<TileButton onClick={() => setDialog({ kind: 'profile', user: { ...me, owner } })}>{t('Edit')}</TileButton>}
                />
                <dl className={styles.kv}>
                    <KvRow layout="tile" label={t('Full name')}>
                        {me.first_name} {me.last_name}
                    </KvRow>
                    <KvRow layout="tile" label={t('Email')}>
                        {me.email}
                    </KvRow>
                    <KvRow layout="tile" label={t('Password')}>
                        ••••••••••
                    </KvRow>
                </dl>
            </Tile>
            <UserDialog mode={dialog} onClose={() => setDialog(null)} onRemove={() => setDialog(null)} />
        </>
    );
}
