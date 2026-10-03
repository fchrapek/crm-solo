import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Check, ExternalLink, Loader2, RefreshCw, X } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { FormInput } from '@/components/form/form-input';
import { FormLabel } from '@/components/form/form-label';
import { FormMessage } from '@/components/form/form-message';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { InfoHint } from '@/components/ui/info-hint';
import { usePageActions } from '@/contexts/page-context';
import { useReverbNotification } from '@/contexts/reverb-context';
import integrations from '@/routes/integrations';
import { BreadcrumbItem, SharedData } from '@/types';

import styles from './edit.module.css';

interface Integration {
    provider: string;
    name: string;
    description: string;
    website: string;
    features: string[];
    auth_type: 'api_key' | 'oauth2';
    is_enabled: boolean;
    has_api_key: boolean;
    has_trello_api_key: boolean;
    has_unreadable_credentials: boolean;
    is_configured: boolean;
    last_synced_at: string | null;
    last_sync_error: string | null;
    connected_email: string | null;
    /** Infakt clients the last sync could not link; kept until a sync finds none. */
    client_conflicts: string[];
}

interface FlashData {
    success?: string;
    error?: string;
    syncUuid?: string;
}

interface EditPageProps extends SharedData {
    integration: Integration;
    flash: FlashData;
}

export default function Edit() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();
    const { integration, flash } = usePage<EditPageProps>().props;
    const { addUuid } = useReverbNotification();
    const [showSyncWarning, setShowSyncWarning] = useState(false);
    const [showDisconnect, setShowDisconnect] = useState(false);
    const hasCredentials = integration.has_api_key || integration.has_trello_api_key || integration.has_unreadable_credentials;

    const isOAuth = integration.auth_type === 'oauth2';

    // Register sync UUID with Reverb for real-time notifications
    useEffect(() => {
        if (flash?.syncUuid) {
            addUuid(flash.syncUuid);
        }
    }, [flash?.syncUuid, addUuid]);

    const form = useForm({
        api_key: '' as string,
        trello_api_key: '' as string,
        is_enabled: integration.is_enabled as boolean,
    });

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Integrations',
                href: integrations.index().url,
            },
            {
                title: integration.name,
                href: integrations.edit(integration.provider).url,
            },
        ],
        [integration.name, integration.provider],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(integrations.update(integration.provider).url);
    };

    const handleDisconnectConfirm = () => {
        setShowDisconnect(false);
        router.delete(integrations.disconnect(integration.provider).url, { preserveScroll: true });
    };

    const handleSyncClick = () => {
        setShowSyncWarning(true);
    };

    const handleSyncConfirm = () => {
        setShowSyncWarning(false);
        form.post(integrations.sync(integration.provider).url, {
            preserveState: true,
        });
    };

    return (
        <>
            <Head title={`${integration.name} - ${t('Integrations')}`} />

            <div className={styles.header}>
                <Button asChild variant="ghost" size="sm">
                    <Link href={integrations.index()}>
                        <ArrowLeft size={16} />
                        {t('Back to Integrations')}
                    </Link>
                </Button>
            </div>

            <div className={styles.container}>
                <div className={styles.titleRow}>
                    <div>
                        <h1 className={styles.title}>{integration.name}</h1>
                        <p className={styles.description}>{integration.description}</p>
                    </div>
                    <a href={integration.website} target="_blank" rel="noopener noreferrer" className={styles.websiteLink}>
                        <ExternalLink size={16} />
                        {t('Documentation')}
                    </a>
                </div>

                <div className={styles.statusCard}>
                    <div className={styles.statusRow}>
                        <span className={styles.statusLabel}>{t('Status')}</span>
                        <span className={`${styles.statusBadge} ${integration.is_enabled ? styles.statusEnabled : styles.statusDisabled}`}>
                            {integration.is_enabled ? (
                                <>
                                    <Check size={12} />
                                    {t('Enabled')}
                                </>
                            ) : (
                                <>
                                    <X size={12} />
                                    {t('Disabled')}
                                </>
                            )}
                        </span>
                    </div>

                    {isOAuth ? (
                        <div className={styles.statusRow}>
                            <span className={styles.statusLabel}>{t('Account')}</span>
                            <span className={integration.is_configured ? styles.configured : styles.notConfigured}>
                                {integration.connected_email ?? t('Not connected')}
                            </span>
                        </div>
                    ) : integration.provider === 'trello' ? (
                        <>
                            <div className={styles.statusRow}>
                                <span className={styles.statusLabel}>{t('API Key')}</span>
                                <span className={integration.has_trello_api_key ? styles.configured : styles.notConfigured}>
                                    {integration.has_trello_api_key ? t('Configured') : t('Not configured')}
                                </span>
                            </div>
                            <div className={styles.statusRow}>
                                <span className={styles.statusLabel}>{t('API Token')}</span>
                                <span className={integration.has_api_key ? styles.configured : styles.notConfigured}>
                                    {integration.has_api_key ? t('Configured') : t('Not configured')}
                                </span>
                            </div>
                        </>
                    ) : (
                        <div className={styles.statusRow}>
                            <span className={styles.statusLabel}>{t('API Key')}</span>
                            <span className={integration.has_api_key ? styles.configured : styles.notConfigured}>
                                {integration.has_api_key ? t('Configured') : t('Not configured')}
                            </span>
                        </div>
                    )}

                    {integration.last_synced_at && (
                        <div className={styles.statusRow}>
                            <span className={styles.statusLabel}>{t('Last synced')}</span>
                            <span>{new Date(integration.last_synced_at).toLocaleString()}</span>
                        </div>
                    )}

                    {integration.has_unreadable_credentials && (
                        <div className={styles.errorRow}>
                            <AlertTriangle size={14} className={styles.errorIcon} />
                            <span>
                                {t(
                                    'A stored credential cannot be decrypted with this app key. Enter it again, or restore the app key it was saved with.',
                                )}
                            </span>
                        </div>
                    )}

                    {integration.last_sync_error && (
                        <div className={styles.errorRow}>
                            <AlertTriangle size={14} className={styles.errorIcon} />
                            <span>{integration.last_sync_error}</span>
                        </div>
                    )}

                    {integration.client_conflicts.length > 0 && (
                        <div className={styles.conflicts}>
                            <div className={styles.conflictsHead}>
                                <AlertTriangle size={14} className={styles.errorIcon} />
                                <span>
                                    {t('Unlinked Infakt clients')} ({integration.client_conflicts.length})
                                </span>
                            </div>
                            <p className={styles.conflictsHint}>
                                {t(
                                    'Their NIP belongs to a CRM client already linked to another Infakt client. Link or merge them by hand; this list clears on the first sync that finds none.',
                                )}
                            </p>
                            <ul className={styles.conflictsList}>
                                {integration.client_conflicts.map((conflict) => (
                                    <li key={conflict}>{conflict}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>

                <form onSubmit={handleSubmit} className={styles.form}>
                    <div className={styles.formSection}>
                        <h2 className={styles.sectionTitle}>{t('Configuration')}</h2>

                        {integration.provider === 'trello' && (
                            <div className={styles.field}>
                                <span className={styles.labelRow}>
                                    <FormLabel htmlFor="trello_api_key" error={form.errors.trello_api_key}>
                                        {t('API Key')}
                                    </FormLabel>
                                    <InfoHint label={t('About the API key')}>
                                        {t('Find your API key at')}{' '}
                                        <a
                                            href="https://trello.com/power-ups/admin"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className={styles.hintLink}
                                        >
                                            trello.com/power-ups/admin
                                        </a>
                                    </InfoHint>
                                </span>
                                <FormInput
                                    id="trello_api_key"
                                    type="password"
                                    value={form.data.trello_api_key}
                                    onChange={(e) => form.setData('trello_api_key', e.target.value)}
                                    placeholder={integration.has_trello_api_key ? '••••••••••••••••' : t('Enter your API key')}
                                    disabled={form.processing}
                                />
                                <FormMessage error={form.errors.trello_api_key} />
                            </div>
                        )}

                        <div className={styles.field}>
                            <span className={styles.labelRow}>
                                <FormLabel htmlFor="api_key" error={form.errors.api_key}>
                                    {integration.provider === 'trello' ? t('API Token') : t('API Key')}
                                </FormLabel>
                                <InfoHint label={integration.provider === 'trello' ? t('About the API token') : t('About the API key')}>
                                    {integration.provider === 'trello'
                                        ? t('Generate a token from the same page using the Token link next to your API key.')
                                        : `${t('You can find your API key in your')} ${integration.name} ${t('account settings')}.`}
                                </InfoHint>
                            </span>
                            <FormInput
                                id="api_key"
                                type="password"
                                value={form.data.api_key}
                                onChange={(e) => form.setData('api_key', e.target.value)}
                                placeholder={
                                    integration.has_api_key
                                        ? '••••••••••••••••'
                                        : integration.provider === 'trello'
                                          ? t('Enter your API token')
                                          : t('Enter your API key')
                                }
                                disabled={form.processing}
                            />
                            <FormMessage error={form.errors.api_key} />
                        </div>

                        <div className={styles.field}>
                            <label className={styles.checkboxLabel}>
                                <input
                                    type="checkbox"
                                    checked={form.data.is_enabled}
                                    onChange={(e) => form.setData('is_enabled', e.target.checked)}
                                    disabled={form.processing}
                                    className={styles.checkbox}
                                />
                                <span>{t('Enable this integration')}</span>
                            </label>
                        </div>
                    </div>

                    <div className={styles.actions}>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2 className="spinner" />}
                            {t('Save')}
                        </Button>

                        {integration.is_enabled && integration.is_configured && (
                            <Button type="button" variant="outline" onClick={handleSyncClick} disabled={form.processing}>
                                <RefreshCw size={16} />
                                {t('Sync Now')}
                            </Button>
                        )}

                        {hasCredentials && (
                            <Button type="button" variant="outline" onClick={() => setShowDisconnect(true)} disabled={form.processing}>
                                {t('Disconnect')}
                            </Button>
                        )}
                    </div>
                </form>

                <ConfirmDialog
                    open={showDisconnect}
                    onOpenChange={setShowDisconnect}
                    title={t('Disconnect integration')}
                    description={t('Remove the stored credentials and turn this integration off? Data already synced stays.')}
                    confirmLabel={t('Disconnect')}
                    variant="destructive"
                    onConfirm={handleDisconnectConfirm}
                />

                <AlertDialog open={showSyncWarning} onOpenChange={setShowSyncWarning}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle className={styles.warningTitle}>
                                <AlertTriangle size={20} className={styles.warningIcon} />
                                {t('Confirm Sync')}
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {integration.provider === 'infakt' && (
                                    <>
                                        {t('This will sync all clients from')} {integration.name}.{' '}
                                        {t('Existing clients matched by NIP or external ID will be updated with the new data from the integration.')}
                                        <br />
                                        <br />
                                        <strong>{t('This action may overwrite existing client data.')}</strong>
                                    </>
                                )}
                                {integration.provider === 'trello' && t('This will sync all Trello boards and their cards as projects and tasks.')}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                            <AlertDialogAction onClick={handleSyncConfirm}>{t('Sync Now')}</AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                <div className={styles.features}>
                    <h2 className={styles.sectionTitle}>{t('Features')}</h2>
                    <ul className={styles.featureList}>
                        {integration.features.map((feature) => (
                            <li key={feature} className={styles.featureItem}>
                                <Check size={16} className={styles.featureIcon} />
                                <span>{t(`Sync ${feature}`)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </>
    );
}
