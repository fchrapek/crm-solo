import { useTranslation } from 'react-i18next';

/** slug => display label, straight from a config/leadgen.php label map. */
export type LabelMap = Record<string, string>;

/** Optional per-pipeline label maps shipped by LeadsController::pipelineMeta(). */
export interface PipelineLabels {
    stages?: LabelMap;
    factors?: LabelMap;
    categories?: LabelMap;
    routing?: LabelMap;
}

export type LeadLabelKind = 'pipeline' | 'stage' | 'source' | 'factor' | 'category' | 'routing' | 'tier';

/** The historical i18n key prefixes - kept so every existing EN/PL translation keeps working. */
const I18N_PREFIX: Record<LeadLabelKind, string> = {
    pipeline: 'lead_pipeline_',
    stage: 'lead_stage_',
    source: 'lead_source_',
    factor: 'lead_factor_',
    category: 'lead_score_category_',
    routing: 'lead_routing_',
    tier: 'lead_tier_',
};

/** 'offer_sent' -> 'Offer sent', 'ads-google' -> 'Ads google'. */
export function humanizeSlug(slug: string): string {
    const words = slug.replace(/[-_]+/g, ' ').trim();
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : slug;
}

/**
 * The single label-resolution chain for lead vocabulary: explicit label from
 * config/leadgen.php -> app translation (lang/en.json + pl.json) -> humanized
 * slug. A slug never renders raw, so a custom pipeline/stage/source/factor
 * added in config needs no frontend change to stay readable.
 */
export function useLeadLabel() {
    const { t, i18n } = useTranslation();

    return (kind: LeadLabelKind, slug: string, explicit?: string | null): string => {
        if (explicit) return explicit;
        const key = I18N_PREFIX[kind] + slug;
        return i18n.exists(key) ? t(key) : humanizeSlug(slug);
    };
}
