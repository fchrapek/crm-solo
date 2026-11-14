import { ReverbNotificationListener } from '@/components/reverb-notification-listener';
import { Poster, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileGrid, TileHeader, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { ReverbExampleNotificationProvider } from '@/contexts/reverb-context';
import { useAppearance } from '@/hooks/use-appearance';
import { localeOf } from '@/lib/hours-text';
import { logout } from '@/routes';
import settings from '@/routes/settings';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountTab } from './components/account-tab';
import { AgentsTab } from './components/agents-tab';
import { APPEARANCE_LABEL, AppearanceTab, LANGUAGES } from './components/appearance-tab';
import { ClientFieldsTab } from './components/client-fields-tab';
import { EmailTab } from './components/email-tab';
import { IntegrationsTab, ServerTile } from './components/integrations-tab';
import { LeadsTab } from './components/leads-tab';
import { QueueTile, SmsTab } from './components/sms-tab';
import styles from './index.module.css';
import { MEMBER_TABS, SETTINGS_TABS, TAB_LABEL, type Me, type SettingsProps, type SettingsSummary, type SettingsTab } from './types';

/** The tabs this user may open: a member has Konto and Wygląd, and an account with billing off has no e-mail tab. */
export function settingsTabs(isOwner: boolean, billing: boolean): SettingsTab[] {
    return SETTINGS_TABS.filter((key) => (isOwner || MEMBER_TABS.includes(key)) && (key !== 'email' || billing));
}

/**
 * Figma: Ustawienia (1090:9949, phone list 1090:10145). The tabs are the page's pills; /settings is the phone's
 * section list and Konto from 768 px.
 */
export default function Settings() {
    const props = usePage<SharedData & SettingsProps>().props;
    const { t } = useTranslation();
    const billing = props.auth.user?.account.features.billing !== false;
    const active: SettingsTab = props.tab ?? 'konto';
    const { me } = props;

    const side: Partial<Record<SettingsTab, ReactNode>> = {
        konto: me && <SessionTile me={me} />,
        wyglad: (
            <Tile kind="plain">
                <TileHeader label={t('Preview')} />
                <TileNote>{t('appearance_note')}</TileNote>
            </Tile>
        ),
        sms: <QueueTile />,
        integracje: props.server && <ServerTile server={props.server} />,
    };

    return (
        <SoloSurface day="paper">
            <Head title={props.tab === null ? t('Settings') : `${t(TAB_LABEL[props.tab])} · ${t('Settings')}`} />
            <ReverbExampleNotificationProvider>
                <main className={styles.page}>
                    {props.tab === null && props.summary !== undefined && (
                        <div className={styles.hub}>
                            <Poster>{t('Settings')}</Poster>
                            <SectionList tabs={settingsTabs(props.is_owner, billing)} summary={props.summary} me={me} />
                        </div>
                    )}
                    <div className={styles.tab} data-index={props.tab === null || undefined}>
                        <TileGrid>
                            <TileStack>
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
                                {active === 'email' && props.email_sending && (
                                    <EmailTab sending={props.email_sending} templates={props.email_templates ?? []} />
                                )}
                                {active === 'integracje' && (
                                    <IntegrationsTab integrations={props.integrations ?? []} demo={props.demo ?? false} today={props.today ?? ''} />
                                )}
                                {active === 'agenci' && (
                                    <AgentsTab
                                        tokens={props.tokens ?? []}
                                        abilities={props.abilities ?? []}
                                        today={props.today ?? ''}
                                        demo={props.demo ?? false}
                                    />
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

const signOut = (label: string) => (
    <SoloButtonLink href={logout()} method="post" as="button" variant="secondary" size="control">
        {label}
    </SoloButtonLink>
);

/** Figma: Sesja. Who is signed in, and the way out. */
function SessionTile({ me }: { me: Me }) {
    const { t } = useTranslation();

    return (
        <Tile kind="list">
            <TileHeader label={t('Session')} />
            <TileRows>
                <Row rule={false} wrap title={`${me.first_name} ${me.last_name}`} action={signOut(t('Log out'))} />
            </TileRows>
        </Tile>
    );
}

/** What each tab holds, in a few words: the meta of its row on the phone's list. */
export function useTabSummary(summary: SettingsSummary): Record<SettingsTab, string> {
    const { t, i18n } = useTranslation();
    const { appearance } = useAppearance();
    const language = LANGUAGES.find((option) => i18n.language.startsWith(option.code))?.name ?? i18n.language;

    return {
        konto: t('user_count', { count: summary.users }),
        wyglad: [language, t(APPEARANCE_LABEL[appearance]).toLocaleLowerCase(i18n.language)].join(' · '),
        leady: t('source_summary', { defaults: summary.default_sources, count: summary.custom_sources }),
        'pola-klienta': t('field_count', { count: summary.client_fields }),
        sms: t('active_rule_count', { count: summary.message_rules }),
        email: t('template_count', { count: summary.email_templates }),
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
}

/** Figma: Ustawienia, lista sekcji (1090:10145), phones only: one row per tab with what it holds, then the session. */
function SectionList({ tabs, summary, me }: { tabs: SettingsTab[]; summary: SettingsSummary; me?: Me }) {
    const { t } = useTranslation();
    const meta = useTabSummary(summary);

    return (
        <Tile kind="list" as="div">
            <TileRows aria-label={t('Settings')}>
                {tabs.map((key, index) => (
                    <Row key={key} rule={index > 0} title={t(TAB_LABEL[key])} href={settings.show(key)} meta={meta[key] || undefined} />
                ))}
                <Row title={me ? `${me.first_name} ${me.last_name}` : t('Session')} action={signOut(t('Log out'))} />
            </TileRows>
        </Tile>
    );
}

/** The token is the tab; the tabs are the pills, which a phone's /settings lists itself. */
Settings.place = (({ tab, is_owner: isOwner, auth }, { t }) => ({
    tokens: tab === null ? [] : [{ label: t(TAB_LABEL[tab]) }],
    pills: settingsTabs(isOwner, auth?.user?.account.features.billing !== false).map((key) => ({
        label: t(TAB_LABEL[key]),
        href: settings.show(key).url,
        active: key === (tab ?? 'konto'),
    })),
    pillsFrom: tab === null ? 'wide' : undefined,
})) satisfies PlaceResolver<SettingsProps & Pick<SharedData, 'auth'>>;
