import { ReverbNotificationListener } from '@/components/reverb-notification-listener';
import { Notice } from '@/components/solo/notice';
import { Poster, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileGrid, TileRows, TileStack } from '@/components/solo/tile';
import { ReverbExampleNotificationProvider } from '@/contexts/reverb-context';
import { localeOf } from '@/lib/hours-text';
import { logout } from '@/routes';
import profile from '@/routes/profile';
import settings from '@/routes/settings';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { AgentsTab } from './components/agents-tab';
import { ClientFieldsTab, FieldsHelpTile } from './components/client-fields-tab';
import { EmailTab } from './components/email-tab';
import { FormsSide, FormsTab } from './components/forms-tab';
import { IntegrationsTab, ServerTile } from './components/integrations-tab';
import { LeadsTab } from './components/leads-tab';
import { ModulesTile } from './components/modules-tile';
import { QueueTile, SmsTab } from './components/sms-tab';
import { UsersTile } from './components/users-tile';
import styles from './index.module.css';
import { SETTINGS_TABS, TAB_LABEL, type SettingsProps, type SettingsSummary, type SettingsTab } from './types';

/** The tabs on one row: the demo has no website forms. */
export function settingsTabs(intake = true): SettingsTab[] {
    return SETTINGS_TABS.filter((key) => key !== 'forms' || intake);
}

/**
 * Figma: Settings · Ogólne (1557:345025), phone list 1557:346285. The account's settings, its owner's; the tabs are the
 * page's pills, and /settings is the phone's section list and General from 768 px.
 */
export default function Settings() {
    const props = usePage<SharedData & SettingsProps>().props;
    const { t } = useTranslation();
    const active: SettingsTab = props.tab ?? 'general';

    const side: Partial<Record<SettingsTab, ReactNode>> = {
        general: (
            <>
                <UsersTile users={props.users ?? []} />
                <FieldsHelpTile />
            </>
        ),
        messages: <QueueTile />,
        integrations: props.server && <ServerTile server={props.server} />,
        forms: props.intake && <FormsSide intake={props.intake} />,
    };

    return (
        <SoloSurface day="paper">
            <Head title={props.tab === null ? t('Settings') : `${t(TAB_LABEL[props.tab])} · ${t('Settings')}`} />
            <ReverbExampleNotificationProvider>
                <main className={styles.page}>
                    {props.tab === null && props.summary !== undefined && (
                        <div className={styles.hub}>
                            <Poster>{t('Settings')}</Poster>
                            <SectionList tabs={settingsTabs(props.intake_available !== false)} summary={props.summary} name={props.auth.user.name} />
                        </div>
                    )}
                    <div className={styles.tab} data-index={props.tab === null || undefined}>
                        <TileGrid>
                            <TileStack>
                                {props.read_only && (
                                    <Tile kind="plain">
                                        <Notice kind="lock">{t('Settings are read-only in the demo.')}</Notice>
                                    </Tile>
                                )}
                                {active === 'general' && (
                                    <>
                                        {props.modules && <ModulesTile modules={props.modules} />}
                                        {props.client_fields && (
                                            <ClientFieldsTab fields={props.client_fields} reserved={props.reserved_field_keys ?? []} />
                                        )}
                                    </>
                                )}
                                {active === 'leads' && props.leadgen && <LeadsTab leadgen={props.leadgen} />}
                                {active === 'forms' && props.intake && <FormsTab intake={props.intake} />}
                                {active === 'messages' && (
                                    <>
                                        {props.sending && (
                                            <SmsTab
                                                sending={props.sending}
                                                templates={props.templates ?? []}
                                                rules={props.rules ?? []}
                                                fields={props.fields ?? []}
                                            />
                                        )}
                                        {props.email_sending && <EmailTab sending={props.email_sending} templates={props.email_templates ?? []} />}
                                    </>
                                )}
                                {active === 'integrations' && (
                                    <>
                                        <IntegrationsTab
                                            integrations={props.integrations ?? []}
                                            demo={props.demo ?? false}
                                            today={props.today ?? ''}
                                        />
                                        <AgentsTab
                                            tokens={props.tokens ?? []}
                                            abilities={props.abilities ?? []}
                                            today={props.today ?? ''}
                                            demo={props.demo ?? false}
                                        />
                                    </>
                                )}
                            </TileStack>
                            {side[active] && <TileStack as="aside">{side[active]}</TileStack>}
                        </TileGrid>
                    </div>
                </main>
                <ReverbNotificationListener />
            </ReverbExampleNotificationProvider>
        </SoloSurface>
    );
}

/** What each tab holds, in a few words: the meta of its row on the phone's list. */
export function useTabSummary(summary: SettingsSummary): Record<SettingsTab, string> {
    const { t, i18n } = useTranslation();
    const list = (parts: (string | null)[]) => parts.filter((part): part is string => part !== null && part !== '').join(', ');

    return {
        general: list([t('field_count', { count: summary.client_fields }), t('user_count', { count: summary.users })]),
        leads: t('source_summary', { defaults: summary.default_sources, count: summary.custom_sources }),
        forms:
            summary.forms === null
                ? ''
                : list([t('form_count', { count: summary.forms.count }), summary.forms.sender ? t('sender set') : t('no sender')]),
        messages: list([
            t('active_rule_count', { count: summary.message_rules }),
            summary.email_templates === null ? null : t('template_count', { count: summary.email_templates }),
        ]),
        integrations: list([
            summary.connected.length === 0
                ? t('Nothing connected')
                : t('{{names}} connected', {
                      names: new Intl.ListFormat(localeOf(i18n.language), { type: 'conjunction' }).format(summary.connected),
                  }),
            summary.tokens.soonest_days === null
                ? t('token_count', { count: summary.tokens.count })
                : t('{{tokens}}, the first expires in {{days}}', {
                      tokens: t('token_count', { count: summary.tokens.count }),
                      days: t('day_count', { count: summary.tokens.soonest_days }),
                  }),
        ]),
    };
}

/** Figma: Settings, section list (1557:346285), phones only: My profile on its own, one row per tab with what it holds, then the session. */
function SectionList({ tabs, summary, name }: { tabs: SettingsTab[]; summary: SettingsSummary; name: string }) {
    const { t } = useTranslation();
    const meta = useTabSummary(summary);

    return (
        <>
            <Tile kind="list" as="div">
                <TileRows aria-label={t('My profile')}>
                    <Row rule={false} title={t('My profile')} href={profile.show()} meta={name} />
                </TileRows>
            </Tile>
            <Tile kind="list" as="div">
                <TileRows aria-label={t('Settings')}>
                    {tabs.map((key, index) => (
                        <Row key={key} rule={index > 0} title={t(TAB_LABEL[key])} href={settings.show(key)} meta={meta[key] || undefined} />
                    ))}
                    <Row
                        title={name}
                        action={
                            <SoloButtonLink href={logout()} method="post" as="button" variant="secondary" size="control">
                                {t('Log out')}
                            </SoloButtonLink>
                        }
                    />
                </TileRows>
            </Tile>
        </>
    );
}

/** The token is the tab, then the form a form page shows; the tabs are the pills, which a phone's /settings lists itself. */
Settings.place = (({ tab, intake, intake_available: intakeAvailable }, { t }) => {
    const form = intake?.forms.find((row) => row.id === intake.form_id);

    return {
        tokens:
            tab === null
                ? []
                : form
                  ? [{ label: t(TAB_LABEL[tab]), href: settings.show(tab).url }, { label: form.name }]
                  : [{ label: t(TAB_LABEL[tab]) }],
        pills: settingsTabs(intakeAvailable !== false).map((key) => ({
            label: t(TAB_LABEL[key]),
            href: settings.show(key).url,
            active: key === (tab ?? 'general'),
        })),
        pillsFrom: tab === null ? 'wide' : undefined,
    };
}) satisfies PlaceResolver<SettingsProps>;
