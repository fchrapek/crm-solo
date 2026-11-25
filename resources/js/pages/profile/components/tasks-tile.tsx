import { Tile, TileHeader, TileRows } from '@/components/solo/tile';
import { SettingSwitch } from '@/pages/settings/components/setting-switch';
import profile from '@/routes/profile';
import { useTranslation } from 'react-i18next';
import type { TaskSettings } from '../types';

/** Figma: Mój profil, Zadania (1556:19970). What a user sets for their own tasks and day; saved on the switch. */
export function TasksTile({ settings: saved }: { settings: TaskSettings }) {
    const { t } = useTranslation();

    return (
        <Tile kind="list">
            <TileHeader label={t('Tasks')} />
            <TileRows>
                <SettingSwitch
                    name="warn_full_day"
                    label={t('Warn when a day has more than 5 tasks')}
                    value={saved.warn_full_day}
                    url={profile.tasks.update().url}
                />
                {saved.show_billed_on_tasks !== null && (
                    <SettingSwitch
                        name="show_billed_on_tasks"
                        label={t('Show billed time on tasks')}
                        value={saved.show_billed_on_tasks}
                        url={profile.tasks.update().url}
                        rule
                    />
                )}
            </TileRows>
        </Tile>
    );
}
