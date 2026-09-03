import { Head, Link, router } from '@inertiajs/react';
import { Plus, Settings } from 'lucide-react';
import React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePageActions } from '@/contexts/page-context';
import leads from '@/routes/leads';
import { BreadcrumbItem, SharedData } from '@/types';

import { LeadKanban, type LeadCard } from './components/lead-kanban';
import { TierBadge } from './components/tier-badge';
import styles from './index.module.css';
import { useLeadLabel, type LabelMap, type PipelineLabels } from './lib/labels';

interface PipelineMeta {
    key: string;
    label: string | null;
    stages: string[];
    won_stage: string;
    labels?: PipelineLabels;
}

interface Filters {
    search?: string;
    source?: string;
    trashed?: string;
    pipeline: string;
    stage: string;
    tier: string;
}

interface LeadsPageProps extends SharedData {
    filters: Filters;
    pipelines: PipelineMeta[];
    stages: string[];
    stage_labels: LabelMap;
    sources: string[];
    source_labels: LabelMap;
    tiers: string[];
    leads: { data: LeadCard[] };
}

type View = 'kanban' | 'list';

export default function Index({ filters, pipelines, stages, stage_labels, sources, source_labels, tiers, leads: leadRows }: LeadsPageProps) {
    const { t } = useTranslation();
    const label = useLeadLabel();
    const { setBreadcrumbs } = usePageActions();
    const [view, setView] = React.useState<View>('kanban');
    const [search, setSearch] = React.useState(filters.search ?? '');

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(() => [{ title: 'Leads', href: leads.index().url }], []);
    React.useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    // Brand is an attribute, not a board split - the chip/filter only exist
    // when more than one brand is configured (progressive disclosure).
    const multiBrand = pipelines.length > 1;
    const pipelineLabels = React.useMemo(
        () => Object.fromEntries(pipelines.map((p) => [p.key, label('pipeline', p.key, p.label)])),
        [pipelines, label],
    );

    const apply = (next: Partial<Filters>) => {
        router.get(leads.index().url, { ...filters, ...next }, { preserveState: true, replace: true });
    };

    // Debounce the search box so each keystroke isn't a round-trip.
    React.useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = setTimeout(() => apply({ search: search || undefined }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <>
            <Head title={t('Leads')} />

            <div className={styles.page}>
                <div className={styles.header}>
                    <h1 className={styles.pageTitle}>{t('Leads')}</h1>

                    <div className={styles.headerActions}>
                        <Button asChild variant="ghost" size="icon" aria-label={t('Lead settings')}>
                            <Link href="/settings#leads" prefetch>
                                <Settings size={16} />
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={leads.create().url}>
                                <Plus size={16} />
                                {t('Capture lead')}
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className={styles.filters}>
                    <Input className={styles.search} placeholder={t('Search leads')} value={search} onChange={(e) => setSearch(e.target.value)} />

                    {multiBrand && (
                        <Select value={filters.pipeline} onValueChange={(v) => apply({ pipeline: v })}>
                            <SelectTrigger className={styles.select}>
                                <SelectValue placeholder={t('Brand')} />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{t('All brands')}</SelectItem>
                                {pipelines.map((pipeline) => (
                                    <SelectItem key={pipeline.key} value={pipeline.key}>
                                        {pipelineLabels[pipeline.key]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    <Select value={filters.source ?? 'all'} onValueChange={(v) => apply({ source: v === 'all' ? undefined : v })}>
                        <SelectTrigger className={styles.select}>
                            <SelectValue placeholder={t('Source')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{t('All sources')}</SelectItem>
                            {sources.map((source) => (
                                <SelectItem key={source} value={source}>
                                    {label('source', source, source_labels?.[source])}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {/* Tier filter works on both views - "who gets my minutes
                        today" is the question the board is for. */}
                    <Select value={filters.tier} onValueChange={(v) => apply({ tier: v })}>
                        <SelectTrigger className={styles.select}>
                            <SelectValue placeholder={t('Tier')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{t('All tiers')}</SelectItem>
                            {tiers.map((tier) => (
                                <SelectItem key={tier} value={tier}>
                                    {label('tier', tier)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {view === 'list' && (
                        <Select value={filters.stage} onValueChange={(v) => apply({ stage: v })}>
                            <SelectTrigger className={styles.select}>
                                <SelectValue placeholder={t('Stage')} />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{t('All stages')}</SelectItem>
                                {stages.map((stage) => (
                                    <SelectItem key={stage} value={stage}>
                                        {label('stage', stage, stage_labels?.[stage])}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    <div className={styles.viewToggle}>
                        <button type="button" className={view === 'list' ? styles.toggleActive : styles.toggle} onClick={() => setView('list')}>
                            {t('List')}
                        </button>
                        <button type="button" className={view === 'kanban' ? styles.toggleActive : styles.toggle} onClick={() => setView('kanban')}>
                            {t('Kanban')}
                        </button>
                    </div>
                </div>

                {leadRows.data.length === 0 ? (
                    <p className={styles.empty}>{t('No leads captured yet.')}</p>
                ) : view === 'kanban' ? (
                    <LeadKanban
                        leads={leadRows.data}
                        stages={stages}
                        stageLabels={stage_labels}
                        sourceLabels={source_labels}
                        brandLabels={multiBrand ? pipelineLabels : undefined}
                    />
                ) : (
                    <table className={styles.table}>
                        <thead>
                            <tr>
                                <th>{t('Name')}</th>
                                <th>{t('Company')}</th>
                                {multiBrand && <th>{t('Brand')}</th>}
                                <th>{t('Tier')}</th>
                                <th>{t('Stage')}</th>
                                <th>{t('Source')}</th>
                                <th>{t('Captured')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {leadRows.data.map((lead) => (
                                <tr key={lead.id}>
                                    <td>
                                        <Link className={styles.rowLink} href={leads.edit(lead.id).url}>
                                            {lead.name}
                                        </Link>
                                    </td>
                                    <td>{lead.company ?? ' - '}</td>
                                    {multiBrand && <td>{pipelineLabels[lead.pipeline] ?? lead.pipeline}</td>}
                                    <td>
                                        <TierBadge tier={lead.tier} total={lead.score_total} />
                                    </td>
                                    <td>{label('stage', lead.stage, stage_labels?.[lead.stage])}</td>
                                    <td>{label('source', lead.source, source_labels?.[lead.source])}</td>
                                    <td>{lead.captured_at ? new Date(lead.captured_at).toLocaleDateString() : ' - '}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </>
    );
}
