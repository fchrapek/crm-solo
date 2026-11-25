import { Poster, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileGrid, TileHeader, TileRows, TileStack } from '@/components/solo/tile';
import { logout } from '@/routes';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { AccountTile } from './components/account-tile';
import { AppearanceTiles } from './components/appearance-tiles';
import { ProfileTile } from './components/profile-tile';
import { TasksTile } from './components/tasks-tile';
import styles from './index.module.css';
import { type ProfileProps } from './types';

/**
 * Figma: Mój profil (1556:19970, phone 1556:20199; a member's 1557:346862, phone 1557:347077). The signed-in person's
 * own settings, for an owner and a member alike; the account's settings are Settings, the owner's.
 */
export default function Profile() {
    const { t } = useTranslation();
    const { me, is_owner: owner, account, task_settings: tasks, helper_settings: helper } = usePage<SharedData & ProfileProps>().props;

    return (
        <SoloSurface day="paper">
            <Head title={t('My profile')} />
            <main className={styles.page}>
                <div className={styles.poster}>
                    <Poster>{t('My profile')}</Poster>
                </div>
                <TileGrid>
                    <TileStack>
                        <ProfileTile me={me} owner={owner} />
                        <AppearanceTiles helper={helper} />
                        <TasksTile settings={tasks} />
                    </TileStack>
                    <TileStack as="aside">
                        <AccountTile account={account} owner={owner} />
                        <Tile kind="list">
                            <TileHeader label={t('Session')} />
                            <TileRows>
                                <Row
                                    rule={false}
                                    title={`${me.first_name} ${me.last_name}`}
                                    action={
                                        <SoloButtonLink href={logout()} method="post" as="button" variant="secondary" size="control">
                                            {t('Log out')}
                                        </SoloButtonLink>
                                    }
                                />
                            </TileRows>
                        </Tile>
                    </TileStack>
                </TileGrid>
            </main>
        </SoloSurface>
    );
}

Profile.place = ((_props, { t }) => ({ tokens: [{ label: t('My profile') }] })) satisfies PlaceResolver<ProfileProps>;
