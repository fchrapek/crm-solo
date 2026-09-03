import { Head, Link, usePage } from '@inertiajs/react';
import { Check, ExternalLink, X } from 'lucide-react';
import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { usePageActions } from '@/contexts/page-context';
import integrations from '@/routes/integrations';
import { BreadcrumbItem, SharedData } from '@/types';

import styles from './index.module.css';

interface Integration {
    provider: string;
    name: string;
    description: string;
    website: string;
    features: string[];
    is_enabled: boolean;
    is_configured: boolean;
    last_synced_at: string | null;
}

interface IndexPageProps extends SharedData {
    integrations: Integration[];
}

export default function Integrations() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();
    const { integrations: integrationsData } = usePage<IndexPageProps>().props;

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Integrations',
                href: integrations.index().url,
            },
        ],
        [],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    return (
        <>
            <Head title={t('Integrations')} />

            <h1 className={styles.title}>{t('Integrations')}</h1>
            <p className={styles.subtitle}>{t('Connect your CRM with external services')}</p>

            <div className={styles.grid}>
                {integrationsData.map((integration) => (
                    <div key={integration.provider} className={styles.card}>
                        <div className={styles.cardHeader}>
                            <h2 className={styles.cardTitle}>{integration.name}</h2>
                            <a href={integration.website} target="_blank" rel="noopener noreferrer" title={t('Visit website')}>
                                <ExternalLink size={16} style={{ color: 'var(--color-muted-foreground)' }} />
                            </a>
                        </div>

                        <p className={styles.cardDescription}>{integration.description}</p>

                        <div className={styles.features}>
                            {integration.features.map((feature) => (
                                <span key={feature} className={styles.featureBadge}>
                                    {t(feature.charAt(0).toUpperCase() + feature.slice(1))}
                                </span>
                            ))}
                        </div>

                        <div className={styles.cardFooter}>
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

                            <Button asChild variant="outline" size="sm">
                                <Link href={integrations.edit(integration.provider)}>{t('Configure')}</Link>
                            </Button>
                        </div>
                    </div>
                ))}
            </div>
        </>
    );
}
