import FlashMessages from '@/components/flash-messages';
import { ReverbNotificationListener } from '@/components/reverb-notification-listener';
import { RecordHeader } from '@/components/solo/client-header';
import { ListRow } from '@/components/solo/list-row';
import { Masthead } from '@/components/solo/masthead';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { ReverbExampleNotificationProvider } from '@/contexts/reverb-context';
import { useAppearance } from '@/hooks/use-appearance';
import { THEMES, useTheme } from '@/hooks/use-theme';
import { localeOf } from '@/lib/hours-text';
import { logout } from '@/routes';
import settings from '@/routes/settings';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { AccountTab } from './components/account-tab';
import { AgentsTab } from './components/agents-tab';
import { APPEARANCE_LABEL, AppearanceTab, LANGUAGES } from './components/appearance-tab';
import { ClientFieldsTab } from './components/client-fields-tab';
import { IntegrationsTab } from './components/integrations-tab';
import { LeadsTab } from './components/leads-tab';
import { SmsTab } from './components/sms-tab';
import styles from './index.module.css';
import { SETTINGS_TABS, TAB_LABEL, type SettingsProps, type SettingsSummary, type SettingsTab } from './types';

/** Figma: Ustawienia (461:7444). /settings is the phone's section list and Konto from 768 px. */
export default function Settings() {
    const props = usePage<SharedData & SettingsProps>().props;
    const { t } = useTranslation();
    const tabs = SETTINGS_TABS.filter((key) => key !== 'agenci' || props.is_owner);
    const active: SettingsTab = props.tab ?? 'konto';
    const { me } = props;

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={props.tab === null ? t('Settings') : `${t(TAB_LABEL[props.tab])} · ${t('Settings')}`} />
            <ReverbExampleNotificationProvider>
                <div className={styles.frame}>
                    <Masthead current="settings" />
                    <main>
                        <RecordHeader
                            size="compact"
                            back={props.tab === null ? undefined : { href: settings.index().url, label: t('Settings') }}
                            trail={[{ label: t('Settings'), href: settings.index().url }, { label: t(TAB_LABEL[active]) }]}
                            title={t('Settings')}
                            phoneTitle={props.tab === null ? undefined : t(TAB_LABEL[props.tab])}
                        />
                        {props.tab === null && props.summary !== undefined && <SectionList tabs={tabs} summary={props.summary} />}
                        <div className={styles.columns} data-index={props.tab === null || undefined}>
                            <nav className={styles.sectionNav} aria-label={t('Settings')}>
                                {tabs.map((key) => (
                                    <Link
                                        key={key}
                                        href={settings.show(key)}
                                        className={styles.sectionNavItem}
                                        aria-current={key === active ? 'page' : undefined}
                                        preserveScroll
                                    >
                                        {t(TAB_LABEL[key])}
                                    </Link>
                                ))}
                            </nav>
                            <div className={styles.main}>
                                {active === 'konto' && me && <AccountTab me={me} users={props.users ?? []} isOwner={props.is_owner} />}
                                {active === 'wyglad' && <AppearanceTab />}
                                {active === 'leady' && props.leadgen && <LeadsTab leadgen={props.leadgen} />}
                                {active === 'pola-klienta' && props.client_fields && (
                                    <ClientFieldsTab fields={props.client_fields} reserved={props.reserved_field_keys ?? []} />
                                )}
                                {active === 'sms' && props.sending && (
                                    <SmsTab
                                        sending={props.sending}
                                        templates={props.templates ?? []}
                                        rules={props.rules ?? []}
                                        fields={props.fields ?? []}
                                    />
                                )}
                                {active === 'integracje' && (
                                    <IntegrationsTab
                                        integrations={props.integrations ?? []}
                                        demo={props.demo ?? false}
                                        server={props.server ?? null}
                                        today={props.today ?? ''}
                                    />
                                )}
                                {active === 'agenci' && (
                                    <AgentsTab
                                        tokens={props.tokens ?? []}
                                        abilities={props.abilities ?? []}
                                        today={props.today ?? ''}
                                        demo={props.demo ?? false}
                                    />
                                )}
                            </div>
                        </div>
                    </main>
                </div>
                <ReverbNotificationListener />
            </ReverbExampleNotificationProvider>
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}

/** Figma: Ustawienia list (473:7725), phones only: one row per tab with what it holds, then Wyloguj (the tab bar has no Więcej sheet for now). */
function SectionList({ tabs, summary }: { tabs: SettingsTab[]; summary: SettingsSummary }) {
    const { t, i18n } = useTranslation();
    const { appearance } = useAppearance();
    const { theme } = useTheme();
    const { me } = usePage<SharedData & SettingsProps>().props;
    const language = LANGUAGES.find((option) => i18n.language.startsWith(option.code))?.name ?? i18n.language;

    const sub: Record<SettingsTab, string> = {
        konto: [me ? `${me.first_name} ${me.last_name}` : null, t('user_count', { count: summary.users })].filter(Boolean).join(' · '),
        wyglad: [
            language,
            THEMES.find((option) => option.id === theme)?.label,
            t(APPEARANCE_LABEL[appearance]).toLocaleLowerCase(i18n.language),
        ].join(' · '),
        leady: t('source_summary', { defaults: summary.default_sources, count: summary.custom_sources }),
        'pola-klienta': t('field_count', { count: summary.client_fields }),
        sms: t('active_rule_count', { count: summary.message_rules }),
        integracje:
            summary.connected.length === 0
                ? t('Nothing connected')
                : t('{{names}} connected', {
                      names: new Intl.ListFormat(localeOf(i18n.language), { type: 'conjunction' }).format(summary.connected),
                  }),
        agenci:
            summary.tokens === null
                ? ''
                : summary.tokens.soonest_days === null
                  ? t('token_count', { count: summary.tokens.count })
                  : t('{{tokens}}, the first expires in {{days}}', {
                        tokens: t('token_count', { count: summary.tokens.count }),
                        days: t('day_count', { count: summary.tokens.soonest_days }),
                    }),
    };

    return (
        <ul className={styles.sectionList} aria-label={t('Settings')}>
            {tabs.map((key) => (
                <li key={key}>
                    <ListRow href={settings.show(key)} title={t(TAB_LABEL[key])} sub={sub[key] || undefined} />
                </li>
            ))}
            <li>
                <ListRow title={t('Log out')} href={logout()} method="post" as="button" trailing={null} />
            </li>
        </ul>
    );
}
