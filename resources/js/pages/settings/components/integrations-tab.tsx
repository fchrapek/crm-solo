import { fieldControl, SoloField, SoloInput } from '@/components/solo/field';
import { ListRow } from '@/components/solo/list-row';
import { SectionAction, SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { useReverbNotification } from '@/contexts/reverb-context';
import integrations from '@/routes/integrations';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { IntegrationRow, ServerConfig } from '../types';
import { useStampLabel } from './format';
import styles from './tab.module.css';

type State = 'connected' | 'off' | 'none';

const STATE_LABEL: Record<State, string> = { connected: 'connected', off: 'turned off', none: 'not connected' };

const NOTE: Record<string, string> = {
    trello: 'Card content comes from Trello; you tick tasks off in the CRM',
    infakt: 'Invoices are only ever created as drafts',
    smsapi: 'Sends the text messages your rules schedule, once chosen in SMS settings',
};

function stateOf(integration: IntegrationRow): State {
    if (integration.is_enabled && integration.is_configured) return 'connected';

    return integration.has_api_key || integration.has_trello_api_key ? 'off' : 'none';
}

/** Figma: Ustawienia / Integracje (206:5280), phones 473:8726. The old integration pages' form lives in Konfiguruj. */
export function IntegrationsTab({
    integrations: rows,
    demo,
    server,
    today,
}: {
    integrations: IntegrationRow[];
    demo: boolean;
    server: ServerConfig | null;
    today: string;
}) {
    const { t } = useTranslation();
    const stamp = useStampLabel(today);
    const { flash } = usePage<SharedData & { flash?: { syncUuid?: string } }>().props;
    const { addUuid } = useReverbNotification();
    const [configuring, setConfiguring] = useState<string | null>(null);
    const [syncing, setSyncing] = useState<IntegrationRow | null>(null);
    const connected = rows.filter((row) => stateOf(row) === 'connected').length;

    useEffect(() => {
        if (flash?.syncUuid) addUuid(flash.syncUuid);
    }, [flash?.syncUuid, addUuid]);

    if (demo) {
        return (
            <section className={styles.section}>
                <SectionHead kind="body" label={t('Connections')} />
                <p className={styles.note}>
                    {t('Integrations are not part of the demo. In your own install they connect Trello, Infakt and your lead forms.')}
                </p>
            </section>
        );
    }

    const detail = (row: IntegrationRow) => {
        const what =
            row.provider === 'trello'
                ? t('board_count', { count: row.boards ?? 0 })
                : row.provider === 'infakt'
                  ? t('Invoices and clients')
                  : row.provider === 'smsapi'
                    ? t('Text messages')
                    : null;
        const when = !row.syncs ? null : row.last_synced === null ? t('never synced') : t('last sync {{when}}', { when: stamp(row.last_synced) });

        return [what, when].filter(Boolean).join(', ');
    };
    const canSync = (row: IntegrationRow) => row.syncs && stateOf(row) === 'connected';
    const configured = rows.find((row) => row.provider === configuring) ?? null;

    return (
        <section className={styles.section}>
            <SectionHead kind="body" label={`${t('Connections')} · ${connected}`} />
            <ul className={`${styles.list} ${styles.phone}`}>
                {rows.map((row) => (
                    <li key={row.provider}>
                        <ListRow
                            title={row.name}
                            sub={`${t(STATE_LABEL[stateOf(row)])} · ${detail(row)}`}
                            onClick={() => setConfiguring(row.provider)}
                        />
                    </li>
                ))}
            </ul>
            <ul className={`${styles.list} ${styles.wide}`}>
                {rows.map((row) => (
                    <li key={row.provider} className={styles.row}>
                        <span className={styles.rowText}>
                            <span className={styles.rowTitle}>
                                {row.name}
                                <span className={`${styles.rowSub} ${styles.state}`} data-state={stateOf(row)}>
                                    {t(STATE_LABEL[stateOf(row)])}
                                </span>
                            </span>
                            <span className={styles.rowSub}>{detail(row)}</span>
                            {NOTE[row.provider] && <span className={styles.rowSub}>{t(NOTE[row.provider])}</span>}
                            {row.last_sync_error && <span className={`${styles.rowSub} ${styles.accent}`}>{row.last_sync_error}</span>}
                        </span>
                        <span className={styles.rowEnd}>
                            <SectionAction onClick={() => setConfiguring(row.provider)}>{t('Configure')}</SectionAction>
                            {canSync(row) && (
                                <SoloButton variant="outline" size="sm" onClick={() => setSyncing(row)}>
                                    {t('Sync')}
                                </SoloButton>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
            {server !== null && <ServerLine server={server} />}

            <IntegrationDialog
                integration={configured}
                onClose={() => setConfiguring(null)}
                onSync={(row) => {
                    setConfiguring(null);
                    setSyncing(row);
                }}
            />
            <ConfirmDialog
                open={syncing !== null}
                onOpenChange={(value) => !value && setSyncing(null)}
                title={t('Confirm Sync')}
                description={
                    syncing?.provider === 'infakt'
                        ? t(
                              'This syncs every client from Infakt. Clients matched by NIP or external ID are updated with its data, which may overwrite what the CRM holds.',
                          )
                        : t('This will sync all Trello boards and their cards as projects and tasks.')
                }
                confirmLabel={t('Sync Now')}
                onConfirm={() => {
                    if (syncing !== null) router.post(integrations.sync(syncing.provider).url, {}, { preserveScroll: true });
                    setSyncing(null);
                }}
            />
        </section>
    );
}

/** Values set on the server (.env), shown as set or not, never as values. */
function ServerLine({ server }: { server: ServerConfig }) {
    const { t } = useTranslation();
    const forms = server.kiwwwi_forms;
    const partial = forms.total > 0 && forms.polled < forms.total;
    const openai = server.openai ? t('key set') : t('no key');

    return (
        <>
            <p className={`${styles.note} ${styles.wide}`}>
                {t('From the server configuration, read only')}
                {'  '}
                <span className={styles.serverValue}>OpenAI: {openai}</span>
                {forms.total > 0 && (
                    <>
                        {' · '}
                        <span className={styles.serverValue}>
                            {t('Kiwwwi forms')}:{' '}
                            <span className={partial ? styles.accent : undefined}>{t('{{polled}} of {{total}} sites', forms)}</span>
                        </span>
                    </>
                )}
            </p>
            <div className={styles.phone}>
                {forms.total > 0 && (
                    <p className={`${styles.note} ${partial ? styles.dot : ''}`}>
                        {t('Kiwwwi forms')}: {t('{{polled}} of {{total}} sites', forms)}
                    </p>
                )}
                <p className={styles.note}>OpenAI: {openai}</p>
            </div>
        </>
    );
}

/** Konfiguruj: credentials (blank keeps the stored value), on or off, the last error and unlinked clients, and Odłącz. */
function IntegrationDialog({
    integration,
    onClose,
    onSync,
}: {
    integration: IntegrationRow | null;
    onClose: () => void;
    onSync: (row: IntegrationRow) => void;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState({ api_key: '', trello_api_key: '', is_enabled: false });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);
    const [shown, setShown] = useState(integration);
    if (integration?.provider !== shown?.provider) {
        setShown(integration);
        if (integration !== null) {
            setDraft({ api_key: '', trello_api_key: '', is_enabled: integration.is_enabled });
            setErrors({});
        }
    }

    const row = integration ?? shown;
    if (row === null) return null;

    const trello = row.provider === 'trello';
    const token = trello || row.provider === 'smsapi';
    const hasCredentials = row.has_api_key || row.has_trello_api_key || row.has_unreadable_credentials;
    const stored = '••••••••••••••••';

    function submit(event: FormEvent) {
        event.preventDefault();
        if (row === null) return;
        setProcessing(true);
        router.put(integrations.update(row.provider).url, draft, {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (errs) => setErrors(errs),
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <>
            <ResponsiveDialog
                open={integration !== null}
                onOpenChange={(open) => !open && onClose()}
                title={row.name}
                onSubmit={submit}
                sheetAction="save"
                fields
                saving={processing}
                footerClassName={styles.dialogActions}
                actions={(size) => (
                    <>
                        {hasCredentials && (
                            <SoloButton variant="text" size={size} data-start="" onClick={() => setDisconnecting(true)} disabled={processing}>
                                {t('Disconnect')}
                            </SoloButton>
                        )}
                        <SoloButton variant="pill" onClick={onClose} disabled={processing}>
                            {t('Cancel')}
                        </SoloButton>
                        <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                            {processing ? t('Saving…') : t('Save')}
                        </SoloButton>
                    </>
                )}
            >
                {row.has_unreadable_credentials && (
                    <p className={styles.message} data-tone="error">
                        {t('A stored credential cannot be decrypted with this app key. Enter it again, or restore the app key it was saved with.')}
                    </p>
                )}
                {row.last_sync_error && (
                    <p className={styles.message} data-tone="error">
                        {row.last_sync_error}
                    </p>
                )}
                {trello && (
                    <SoloField label={t('API Key')} htmlFor="integration-trello-key" error={errors.trello_api_key}>
                        <SoloInput
                            {...fieldControl('integration-trello-key', errors.trello_api_key)}
                            type="password"
                            value={draft.trello_api_key}
                            placeholder={row.has_trello_api_key ? stored : t('Enter your API key')}
                            onChange={(e) => setDraft((was) => ({ ...was, trello_api_key: e.target.value }))}
                            autoComplete="off"
                            disabled={processing}
                        />
                    </SoloField>
                )}
                <SoloField label={token ? t('API Token') : t('API Key')} htmlFor="integration-key" error={errors.api_key}>
                    <SoloInput
                        {...fieldControl('integration-key', errors.api_key)}
                        type="password"
                        value={draft.api_key}
                        placeholder={row.has_api_key ? stored : token ? t('Enter your API token') : t('Enter your API key')}
                        onChange={(e) => setDraft((was) => ({ ...was, api_key: e.target.value }))}
                        autoComplete="off"
                        disabled={processing}
                    />
                </SoloField>
                <label className={styles.check}>
                    <input
                        type="checkbox"
                        checked={draft.is_enabled}
                        onChange={(e) => setDraft((was) => ({ ...was, is_enabled: e.target.checked }))}
                        disabled={processing}
                    />
                    {t('Enable this integration')}
                </label>
                {row.client_conflicts.length > 0 && (
                    <div>
                        <p className={styles.message} data-tone="warn">
                            {t('Unlinked Infakt clients')} ({row.client_conflicts.length})
                        </p>
                        <p className={styles.note}>
                            {t(
                                'Their NIP belongs to a CRM client already linked to another Infakt client. Link or merge them by hand; this list clears on the first sync that finds none.',
                            )}
                        </p>
                        <ul className={styles.note}>
                            {row.client_conflicts.map((conflict) => (
                                <li key={conflict}>{conflict}</li>
                            ))}
                        </ul>
                    </div>
                )}
                <div className={styles.sheetEnd}>
                    {row.syncs && stateOf(row) === 'connected' && (
                        <SoloButton variant="outline" size="touch" onClick={() => onSync(row)} disabled={processing}>
                            {t('Sync')}
                        </SoloButton>
                    )}
                    {hasCredentials && (
                        <SoloButton variant="text" size="touch" onClick={() => setDisconnecting(true)} disabled={processing}>
                            {t('Disconnect')}
                        </SoloButton>
                    )}
                </div>
            </ResponsiveDialog>
            <ConfirmDialog
                open={disconnecting}
                onOpenChange={setDisconnecting}
                title={t('Disconnect integration')}
                description={t('Remove the stored credentials and turn this integration off? Data already synced stays.')}
                confirmLabel={t('Disconnect')}
                variant="destructive"
                onConfirm={() => {
                    setDisconnecting(false);
                    router.delete(integrations.disconnect(row.provider).url, { preserveScroll: true, onSuccess: onClose });
                }}
            />
        </>
    );
}
