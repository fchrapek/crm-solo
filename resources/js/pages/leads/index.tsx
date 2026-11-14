import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

import { ListTable, ListTableCell, ListTableHead, ListTableRow } from '@/components/solo/list-table';
import { Poster, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { Pills } from '@/components/solo/shell/pills';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileInset } from '@/components/solo/tile';
import { localeOf } from '@/lib/hours-text';
import leads from '@/routes/leads';
import settings from '@/routes/settings';
import { SharedData } from '@/types';

import { LeadKanban, type LeadCard } from './components/lead-kanban';
import { TierBadge } from './components/tier-badge';
import styles from './index.module.css';
import { useLeadLabel, type LabelMap, type PipelineLabels } from './lib/labels';
import { filterPill, leadsPlace, leadsUrl, leadView, type LeadFilters } from './place';

interface PipelineMeta {
    key: string;
    label: string | null;
    stages: string[];
    won_stage: string;
    labels?: PipelineLabels;
}

interface LeadsPageProps extends SharedData {
    filters: LeadFilters;
    pipelines: PipelineMeta[];
    stages: string[];
    stage_labels: LabelMap;
    sources: string[];
    source_labels: LabelMap;
    tiers: string[];
    leads: { data: LeadCard[] };
}

const narrowed = (filters: LeadFilters) =>
    Boolean(filters.search) || Boolean(filters.source) || filters.pipeline !== 'all' || filters.stage !== 'all' || filters.tier !== 'all';

/**
 * Figma: Leady (1093:29084, phone 1093:29331). The board or the list, picked by a pill and kept in the URL; the
 * filters are menu pills beside it and the command bar narrows by words.
 */
export default function Index({ filters, pipelines, stages, stage_labels, sources, source_labels, tiers, leads: leadRows }: LeadsPageProps) {
    const { t, i18n } = useTranslation();
    const label = useLeadLabel();
    const view = leadView(usePage().url);

    // Brand is an attribute, not a board split - the filter only exists when more than one brand is configured.
    const multiBrand = pipelines.length > 1;
    const pipelineLabels = Object.fromEntries(pipelines.map((p) => [p.key, label('pipeline', p.key, p.label)]));
    const to = (next: Partial<LeadFilters>) => leadsUrl({ ...filters, ...next }, view);

    const choices = [
        { label: t('Kanban'), href: leadsUrl(filters, 'kanban'), active: view === 'kanban' },
        { label: t('List'), href: leadsUrl(filters, 'list'), active: view === 'list' },
        ...(multiBrand
            ? [
                  filterPill(
                      t('Brand'),
                      filters.pipeline,
                      [{ value: 'all', label: t('All brands') }, ...pipelines.map((p) => ({ value: p.key, label: pipelineLabels[p.key] }))],
                      (pipeline) => to({ pipeline }),
                  ),
              ]
            : []),
        filterPill(
            t('Source'),
            filters.source,
            [{ value: 'all', label: t('All sources') }, ...sources.map((source) => ({ value: source, label: label('source', source, source_labels?.[source]) }))],
            (source) => to({ source: source === 'all' ? undefined : source }),
        ),
        // The tier filter works on both views: "who gets my minutes today" is the question the board is for.
        filterPill(t('Tier'), filters.tier, [{ value: 'all', label: t('All tiers') }, ...tiers.map((tier) => ({ value: tier, label: label('tier', tier) }))], (tier) =>
            to({ tier }),
        ),
        ...(view === 'list'
            ? [
                  filterPill(
                      t('Stage'),
                      filters.stage,
                      [{ value: 'all', label: t('All stages') }, ...stages.map((stage) => ({ value: stage, label: label('stage', stage, stage_labels?.[stage]) }))],
                      (stage) => to({ stage }),
                  ),
              ]
            : []),
    ];

    return (
        <SoloSurface day="paper">
            <Head title={t('Leads')} />
            <main className={styles.page}>
                <header className={styles.head}>
                    <Poster>{t('Leads')}</Poster>
                    <div className={styles.headActions}>
                        <SoloButtonLink href={settings.show('leady')} variant="quiet" size="control" prefetch>
                            {t('Lead settings')}
                        </SoloButtonLink>
                        <SoloButtonLink href={leads.create().url} size="control">
                            {t('Capture lead')}
                        </SoloButtonLink>
                    </div>
                </header>
                <div className={styles.choices}>
                    <Pills items={choices} label={t('View and filters')} />
                </div>
                {filters.search && (
                    <Tile kind="list" as="div">
                        <Row
                            as="div"
                            rule={false}
                            title={t('Results for "{{query}}"', { query: filters.search })}
                            meta={leadRows.data.length}
                            action={
                                <SoloButtonLink href={to({ search: undefined })} variant="secondary" size="control">
                                    {t('Clear')}
                                </SoloButtonLink>
                            }
                        />
                    </Tile>
                )}
                {leadRows.data.length === 0 ? (
                    <Tile kind="list" as="div">
                        <TileInset>
                            <p className={styles.empty}>{narrowed(filters) ? t('No leads match these filters.') : t('No leads captured yet.')}</p>
                            {narrowed(filters) ? (
                                <SoloButtonLink href={leadsUrl({}, view)} variant="secondary" size="control">
                                    {t('Clear filters')}
                                </SoloButtonLink>
                            ) : (
                                <SoloButtonLink href={leads.create().url} variant="secondary" size="control">
                                    {t('Capture lead')}
                                </SoloButtonLink>
                            )}
                        </TileInset>
                    </Tile>
                ) : view === 'kanban' ? (
                    <LeadKanban
                        leads={leadRows.data}
                        stages={stages}
                        stageLabels={stage_labels}
                        sourceLabels={source_labels}
                        brandLabels={multiBrand ? pipelineLabels : undefined}
                    />
                ) : (
                    <Tile kind="list" as="div">
                        <div className={styles.table}>
                            <ListTable label={t('Leads')}>
                                <thead>
                                    <tr>
                                        <ListTableHead>{t('Name')}</ListTableHead>
                                        <ListTableHead wide>{t('Company')}</ListTableHead>
                                        {multiBrand && <ListTableHead full>{t('Brand')}</ListTableHead>}
                                        <ListTableHead>{t('Tier')}</ListTableHead>
                                        <ListTableHead wide>{t('Stage')}</ListTableHead>
                                        <ListTableHead full>{t('Source')}</ListTableHead>
                                        <ListTableHead full align="end">
                                            {t('Captured')}
                                        </ListTableHead>
                                    </tr>
                                </thead>
                                <tbody>
                                    {leadRows.data.map((lead) => (
                                        <ListTableRow key={lead.id}>
                                            <ListTableCell kind="title" truncate>
                                                <Link className={styles.rowLink} href={leads.edit(lead.id).url}>
                                                    {lead.name}
                                                </Link>
                                            </ListTableCell>
                                            <ListTableCell wide>
                                                {lead.company ?? ''}
                                            </ListTableCell>
                                            {multiBrand && <ListTableCell full>{pipelineLabels[lead.pipeline] ?? lead.pipeline}</ListTableCell>}
                                            <ListTableCell>
                                                <TierBadge tier={lead.tier} total={lead.score_total} />
                                            </ListTableCell>
                                            <ListTableCell wide>{label('stage', lead.stage, stage_labels?.[lead.stage])}</ListTableCell>
                                            <ListTableCell kind="meta" full>
                                                {label('source', lead.source, source_labels?.[lead.source])}
                                            </ListTableCell>
                                            <ListTableCell kind="number" full align="end">
                                                {lead.captured_at ? new Date(lead.captured_at).toLocaleDateString(localeOf(i18n.language)) : ''}
                                            </ListTableCell>
                                        </ListTableRow>
                                    ))}
                                </tbody>
                            </ListTable>
                        </div>
                    </Tile>
                )}
            </main>
        </SoloSurface>
    );
}

Index.place = leadsPlace satisfies PlaceResolver<LeadsPageProps>;
