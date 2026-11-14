import { Chip, type ChipStatus } from '@/components/solo/chip';
import { fieldControl, SoloField, SoloInput } from '@/components/solo/field';
import { KvRow } from '@/components/solo/kv-row';
import { Notice } from '@/components/solo/notice';
import { SectionAction, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Tile, TileActions, TileHeader, TileNote } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { useReverbNotification } from '@/contexts/reverb-context';
import integrations from '@/routes/integrations';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { IntegrationRow, ServerConfig } from '../types';
import { useStampLabel } from './format';
import { TileButton } from './parts';
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

const WHAT: Record<string, string> = { infakt: 'Invoices and clients', smsapi: 'Text messages' };

const CHIP: Record<State, ChipStatus> = { connected: 'done', off: 'waiting', none: 'open' };

/** Figma: Ustawienia · Integracje (1091:30364, phone 1091:30683). One tile per connection; its form lives in Konfiguruj. */
export function IntegrationsTab({ integrations: rows, demo, today }: { integrations: IntegrationRow[]; demo: boolean; today: string }) {
    const { t } = useTranslation();
    const stamp = useStampLabel(today);
    const { flash } = usePage<SharedData & { flash?: { syncUuid?: string } }>().props;
    const { addUuid } = useReverbNotification();
    const [configuring, setConfiguring] = useState<string | null>(null);
    const [syncing, setSyncing] = useState<IntegrationRow | null>(null);

    useEffect(() => {
        if (flash?.syncUuid) addUuid(flash.syncUuid);
    }, [flash?.syncUuid, addUuid]);

    if (demo) {
        return (
            <Tile kind="plain">
                <TileHeader label={t('Connections')} />
                <TileNote>
                    {t('Integrations are not part of the demo. In your own install they connect Trello, Infakt and your lead forms.')}
                </TileNote>
            </Tile>
        );
    }

    const canSync = (row: IntegrationRow) => row.syncs && stateOf(row) === 'connected';
    const configured = rows.find((row) => row.provider === configuring) ?? null;

    return (
        <>
            {rows.map((row) => (
                <Tile key={row.provider} kind="plain">
                    <TileHeader
                        label={WHAT[row.provider] ? `${row.name} · ${t(WHAT[row.provider])}` : row.name}
                        end={<Chip status={CHIP[stateOf(row)]}>{t(STATE_LABEL[stateOf(row)])}</Chip>}
                    />
                    {NOTE[row.provider] && <TileNote>{t(NOTE[row.provider])}</TileNote>}
                    {row.last_sync_error && <Notice kind="warn">{row.last_sync_error}</Notice>}
                    {(row.syncs || row.provider === 'trello') && (
                        <dl className={styles.kv}>
                            {row.syncs && (
                                <KvRow layout="tile" label={t('Last sync')} tone={row.last_synced === null ? 'muted' : undefined}>
                                    {row.last_synced === null ? t('never synced') : stamp(row.last_synced)}
                                </KvRow>
                            )}
                            {row.provider === 'trello' && (
                                <KvRow layout="tile" label={t('Boards')}>
                                    {row.boards ?? 0}
                                </KvRow>
                            )}
                        </dl>
                    )}
                    <TileActions>
                        {canSync(row) && (
                            <SoloButton variant="secondary" size="control" onClick={() => setSyncing(row)}>
                                {t('Sync')}
                            </SoloButton>
                        )}
                        <TileButton onClick={() => setConfiguring(row.provider)}>{t('Configure')}</TileButton>
                    </TileActions>
                </Tile>
            ))}

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
        </>
    );
}

/** Figma: Z konfiguracji serwera. Values set on the server (.env), shown as set or not, never as values. */
export function ServerTile({ server }: { server: ServerConfig }) {
    const { t } = useTranslation();
    const forms = server.kiwwwi_forms;

    return (
        <Tile kind="plain">
            <TileHeader label={t('From the server configuration')} end={t('read only')} />
            <dl className={styles.kv}>
                <KvRow layout="tile" label="OpenAI" tone={server.openai ? undefined : 'muted'}>
                    {server.openai ? t('key set') : t('no key')}
                </KvRow>
                {forms.total > 0 && (
                    <KvRow layout="tile" label={t('Kiwwwi forms')}>
                        {t('{{polled}} of {{total}} sites', forms)}
                    </KvRow>
                )}
            </dl>
        </Tile>
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
    const [skipped, setSkipped] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);
    const [shown, setShown] = useState(integration);
    if (integration?.provider !== shown?.provider) {
        setShown(integration);
        if (integration !== null) {
            setDraft({ api_key: '', trello_api_key: '', is_enabled: integration.is_enabled });
            setSkipped(integration.skipped_lists ?? []);
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
        router.put(integrations.update(row.provider).url, trello ? { ...draft, settings: { skipped_lists: skipped } } : draft, {
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
                {trello && (
                    <SkippedColumns
                        seen={row.seen_lists ?? []}
                        value={skipped}
                        onChange={setSkipped}
                        error={Object.entries(errors).find(([key]) => key.startsWith('settings.skipped_lists'))?.[1]}
                        disabled={processing}
                    />
                )}
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

const columnKey = (name: string) => name.trim().replace(/\s+/g, ' ').toLowerCase();

/** Trello columns whose cards stay on the board but are not CRM tasks; the sync archives them, by name on every board. */
export function SkippedColumns({
    seen,
    value,
    onChange,
    error,
    disabled,
}: {
    seen: string[];
    value: string[];
    onChange: (names: string[]) => void;
    error?: string;
    disabled: boolean;
}) {
    const { t } = useTranslation();
    const [typed, setTyped] = useState('');
    const keys = new Set(value.map(columnKey));
    const seenKeys = new Set(seen.map(columnKey));
    const names = [...seen, ...value.filter((name) => !seenKeys.has(columnKey(name)))];
    const toggle = (name: string, on: boolean) => onChange(on ? [...value, name] : value.filter((held) => columnKey(held) !== columnKey(name)));
    const add = () => {
        const name = typed.trim().replace(/\s+/g, ' ');
        if (name !== '' && !keys.has(columnKey(name))) onChange([...value, name]);
        setTyped('');
    };

    return (
        <fieldset className={styles.checks}>
            <legend>{t('Skipped columns')}</legend>
            {names.map((name) => (
                <label key={columnKey(name)} className={styles.check}>
                    <input type="checkbox" checked={keys.has(columnKey(name))} disabled={disabled} onChange={(e) => toggle(name, e.target.checked)} />
                    {name}
                </label>
            ))}
            <span className={styles.addRow}>
                <SoloInput
                    id="integration-skipped-column"
                    aria-label={t('Add a column name')}
                    aria-invalid={error ? true : undefined}
                    value={typed}
                    placeholder={t('Add a column name')}
                    onChange={(e) => setTyped(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            add();
                        }
                    }}
                    maxLength={100}
                    disabled={disabled}
                />
                <SectionAction onClick={add} disabled={disabled || typed.trim() === ''}>
                    {t('Add')}
                </SectionAction>
            </span>
            <p className={styles.fieldNote}>{t('Cards in these columns stay on Trello and are not tasks in the CRM.')}</p>
            {error && (
                <p className={styles.message} data-tone="error">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
