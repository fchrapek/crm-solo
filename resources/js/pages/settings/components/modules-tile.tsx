import { soloButtonClass, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { Tile, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { InfoHint } from '@/components/ui/info-hint';
import settings from '@/routes/settings';
import { type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { AccountModules } from '../types';
import { SettingSwitch } from './setting-switch';

/** Figma: Settings · Ogólne, Moduły (1557:345025), a switch per module and a link to where a module is set up. */
export function ModulesTile({ modules }: { modules: AccountModules }) {
    const { t } = useTranslation();
    const url = settings.modules.update().url;
    const open = (label: string, action = t('Open tab')) => ({ 'aria-label': `${action}: ${label}`, children: action });
    const tab = (label: string, meta: string, hint: string, action: ReactNode, rule: boolean) => (
        <Row
            rule={rule}
            title={
                <>
                    {label} <InfoHint>{hint}</InfoHint>
                </>
            }
            meta={meta}
            action={action}
        />
    );

    return (
        <Tile kind="list">
            <TileHeader label={t('Modules')} />
            <TileRows>
                {modules.month_close !== null && (
                    <SettingSwitch
                        name="month_close"
                        label={t('Month close')}
                        hint={
                            modules.month_close && modules.month_close_in_use > 0
                                ? `${t('module_month_close_hint')} ${t('month_close_in_use_hint', { count: modules.month_close_in_use })}`
                                : t('module_month_close_hint')
                        }
                        value={modules.month_close}
                        url={url}
                    />
                )}
                {modules.report_baseline !== null && (
                    <SettingSwitch
                        name="report_baseline"
                        label={t('Report baseline')}
                        hint={t('module_report_baseline_hint')}
                        value={modules.report_baseline}
                        url={url}
                        rule={modules.month_close !== null}
                    />
                )}
                {tab(
                    t('Client fields'),
                    t('module_fields_count', { count: modules.client_fields }),
                    t('module_client_fields_hint'),
                    // The fields are the tiles under this one, on the same tab.
                    <a href="#client-fields" className={soloButtonClass({ variant: 'quiet', size: 'control' })} {...open(t('Client fields'), t('Show fields'))} />,
                    modules.month_close !== null || modules.report_baseline !== null,
                )}
                {tab(
                    t('Text messages'),
                    t('module_rules_count', { count: modules.sms_rules }),
                    t('module_sms_hint'),
                    <SoloButtonLink variant="quiet" size="control" href={settings.show('messages')} {...open(t('Text messages'))} />,
                    true,
                )}
            </TileRows>
            <TileInset>
                <TileNote>{t('modules_note')}</TileNote>
            </TileInset>
        </Tile>
    );
}
