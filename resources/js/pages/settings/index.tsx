import { Head, router } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { InfoHint } from '@/components/ui/info-hint';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs } from '@/components/ui/tabs';
import { usePageActions } from '@/contexts/page-context';
import { ACCENTS, THEMES, useAccent, useTheme } from '@/hooks/use-theme';
import { BreadcrumbItem, SharedData } from '@/types';

import { useLeadLabel } from '../leads/lib/labels';
import styles from './index.module.css';

interface LeadgenSettings {
    default_sources: string[];
    custom_sources: string[];
    source_labels: Record<string, string>;
}

interface SettingsPageProps extends SharedData {
    leadgen: LeadgenSettings;
}

interface CustomSourceRow {
    slug: string;
    label: string;
}

const LANGUAGES = [
    { code: 'en', name: 'English' },
    { code: 'pl', name: 'Polski' },
    { code: 'fr', name: 'Français' },
] as const;

export default function Index({ leadgen }: SettingsPageProps) {
    const { t, i18n } = useTranslation();
    const label = useLeadLabel();
    const { setBreadcrumbs } = usePageActions();
    const { theme, updateTheme } = useTheme();
    const { accent, updateAccent } = useAccent();
    const [saving, setSaving] = React.useState(false);
    const [rows, setRows] = React.useState<CustomSourceRow[]>(
        leadgen.custom_sources.map((slug) => ({ slug, label: leadgen.source_labels[slug] ?? '' })),
    );

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(() => [{ title: 'Settings', href: '/settings' }], []);
    React.useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const updateRow = (index: number, patch: Partial<CustomSourceRow>) => {
        setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    const save = () => {
        setSaving(true);
        const clean = rows.filter((row) => row.slug.trim() !== '');
        router.patch(
            '/settings/leadgen',
            {
                custom_sources: clean.map((row) => row.slug.trim()),
                source_labels: Object.fromEntries(clean.filter((row) => row.label.trim() !== '').map((row) => [row.slug.trim(), row.label.trim()])),
            },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    const display = (
        <div className={styles.tabPanel}>
            <div className={styles.field}>
                <Label>{t('Language')}</Label>
                <div className={styles.optionRow}>
                    {LANGUAGES.map((language) => (
                        <button
                            key={language.code}
                            type="button"
                            className={`${styles.option} ${i18n.language === language.code ? styles.optionActive : ''}`}
                            onClick={() => void i18n.changeLanguage(language.code)}
                        >
                            {language.name}
                        </button>
                    ))}
                </div>
            </div>

            <div className={styles.field}>
                <span className={styles.labelRow}>
                    <Label>{t('Theme')}</Label>
                    <InfoHint label={t('About themes')}>{t('settings_theme_hint')}</InfoHint>
                </span>
                <div className={styles.themeGrid}>
                    {THEMES.map((option) => (
                        <button
                            key={option.id}
                            type="button"
                            className={`${styles.themeCard} ${theme === option.id ? styles.themeCardActive : ''}`}
                            onClick={() => updateTheme(option.id)}
                            title={option.blurb}
                        >
                            <span className={styles.themeSwatches} aria-hidden="true">
                                {option.swatches.map((colour, i) => (
                                    <span key={i} className={styles.themeSwatch} style={{ background: colour }} />
                                ))}
                            </span>
                            <span className={styles.themeName}>{option.label}</span>
                            {theme === option.id && <Check size={13} className={styles.themeCheck} />}
                        </button>
                    ))}
                </div>
            </div>

            <div className={styles.field}>
                <span className={styles.labelRow}>
                    <Label>{t('Accent')}</Label>
                    <InfoHint label={t('About accents')}>{t('settings_accent_hint')}</InfoHint>
                </span>
                <div className={styles.optionRow}>
                    {ACCENTS.map((option) => (
                        <button
                            key={option.id}
                            type="button"
                            className={`${styles.accentOption} ${accent === option.id ? styles.optionActive : ''}`}
                            onClick={() => updateAccent(option.id)}
                        >
                            <span className={styles.accentSwatch} style={{ background: option.swatch }} />
                            {option.label}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );

    const leads = (
        <div className={styles.tabPanel}>
            <div className={styles.field}>
                <span className={styles.labelRow}>
                    <Label>{t('Default sources')}</Label>
                    <InfoHint label={t('About default sources')}>{t('settings_default_sources_hint')}</InfoHint>
                </span>
                <div className={styles.chipRow}>
                    {leadgen.default_sources.map((slug) => (
                        <span key={slug} className={styles.chip}>
                            {label('source', slug)}
                        </span>
                    ))}
                </div>
            </div>

            <div className={styles.field}>
                <span className={styles.labelRow}>
                    <Label>{t('Custom sources')}</Label>
                    <InfoHint label={t('About custom sources')}>{t('settings_custom_sources_hint')}</InfoHint>
                </span>
                {rows.length === 0 && <p className={styles.hint}>{t('No custom sources yet.')}</p>}
                {rows.map((row, index) => (
                    <div key={index} className={styles.sourceRow}>
                        <Input
                            value={row.slug}
                            placeholder={t('slug (e.g. conference-booth)')}
                            onChange={(e) =>
                                updateRow(index, {
                                    slug: e.target.value
                                        .toLowerCase()
                                        .replace(/[^a-z0-9-]+/g, '-')
                                        .replace(/^-+/, ''),
                                })
                            }
                        />
                        <Input value={row.label} placeholder={t('Label (optional)')} onChange={(e) => updateRow(index, { label: e.target.value })} />
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={t('Remove source')}
                            onClick={() => setRows((prev) => prev.filter((_, i) => i !== index))}
                        >
                            <Trash2 size={14} />
                        </Button>
                    </div>
                ))}
                <div>
                    <Button variant="outline" size="sm" onClick={() => setRows((prev) => [...prev, { slug: '', label: '' }])}>
                        <Plus size={14} />
                        {t('Add source')}
                    </Button>
                </div>
            </div>

            <div className={styles.actions}>
                <Button onClick={save} disabled={saving}>
                    {saving ? t('Saving…') : t('Save')}
                </Button>
            </div>
        </div>
    );

    // Tab ids double as deep-link targets: /settings#leads (the gear on the
    // Leads page) lands on the right tab because Tabs reads the URL hash.
    const tabs = [
        { id: 'display', label: t('Display'), content: display },
        { id: 'leads', label: t('Leads'), content: leads },
    ];

    return (
        <>
            <Head title={t('Settings')} />

            <div className={styles.page}>
                <span className={styles.titleRow}>
                    <h1 className={styles.title}>{t('Settings')}</h1>
                    <InfoHint label={t('About settings')}>{t('settings_intro_hint')}</InfoHint>
                </span>

                <Tabs tabs={tabs} defaultTab="display" />
            </div>
        </>
    );
}
