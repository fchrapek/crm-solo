import { ChevronDown } from 'lucide-react';
import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Checkbox } from '@/components/ui/checkbox';
import { useLeadLabel, type PipelineLabels } from '../../lib/labels';
import { TierBadge } from '../tier-badge';

import styles from './score-panel.module.css';

/** category -> factor slug -> points, straight from config('leadgen.*'). */
export type ScoringMap = Record<string, Record<string, number>>;
export type ScoreFactors = Record<string, string[]>;

interface Props {
    scoring: ScoringMap;
    value: ScoreFactors;
    onChange: (next: ScoreFactors) => void;
    /** Tier + total as the server derives them - shown until the next save. */
    savedTier: string;
    savedRouting: string | null;
    dirty: boolean;
    /** Thresholds come from config too - the frontend never restates them. */
    thresholds: { gold_min: number; oak_min: number };
    /** Optional config-declared labels for factors / categories / routing. */
    labels?: PipelineLabels;
    /**
     * Collapsed-by-default mode for the record view: the header (score, tier,
     * routing - the DECISION) stays visible; the factor checklist (the
     * WORKING) expands on demand. Progressive disclosure - the conclusion is
     * primary, its derivation secondary.
     */
    collapsible?: boolean;
}

/**
 * FIT / BEHAVIOUR / TRIGGER checkboxes. Points come from the server's copy of
 * the config map - never a duplicate table in the frontend - so a re-tuned
 * plan changes these labels without a code edit here.
 *
 * The running total is computed locally purely as immediate feedback; the
 * server's derivation stays the source of truth and is what the badge shows
 * once saved.
 */
export function ScorePanel({ scoring, value, onChange, savedTier, savedRouting, dirty, thresholds, labels, collapsible = false }: Props) {
    const { t } = useTranslation();
    const label = useLeadLabel();
    const [open, setOpen] = React.useState(!collapsible);
    const showBody = !collapsible || open;

    const toggle = (category: string, factor: string, checked: boolean) => {
        const current = value[category] ?? [];
        const next = checked ? [...current, factor] : current.filter((f) => f !== factor);
        const merged = { ...value, [category]: next };
        if (next.length === 0) delete merged[category];
        onChange(merged);
    };

    const liveTotal = Object.entries(value).reduce(
        (sum, [category, factors]) => sum + factors.reduce((s, f) => s + (scoring[category]?.[f] ?? 0), 0),
        0,
    );

    const headerInner = (
        <>
            <h2 className={styles.title}>{t('Score')}</h2>
            <div className={styles.scoreLine}>
                <span className={styles.total}>
                    {liveTotal} {t('pts')}
                </span>
                {dirty ? (
                    // Don't show a stale tier next to edited checkboxes -
                    // the tier is the server's call, so say so plainly.
                    <span className={styles.pending}>{t('Save to update tier')}</span>
                ) : (
                    <>
                        <TierBadge tier={savedTier} />
                        {savedRouting && <span className={styles.routing}>{label('routing', savedRouting, labels?.routing?.[savedRouting])}</span>}
                    </>
                )}
                {collapsible && <ChevronDown size={16} className={`${styles.chevron} ${open ? styles.chevronOpen : ''}`} />}
            </div>
        </>
    );

    return (
        <section className={styles.panel}>
            {collapsible ? (
                <button type="button" className={`${styles.header} ${styles.headerToggle}`} onClick={() => setOpen((v) => !v)} aria-expanded={open}>
                    {headerInner}
                </button>
            ) : (
                <div className={styles.header}>{headerInner}</div>
            )}

            {showBody && (
                <div className={styles.categories}>
                    {Object.entries(scoring).map(([category, factors]) => (
                        <fieldset key={category} className={styles.category}>
                            <legend className={styles.legend}>{label('category', category, labels?.categories?.[category])}</legend>
                            {Object.entries(factors).map(([factor, points]) => {
                                const id = `${category}-${factor}`;
                                const checked = (value[category] ?? []).includes(factor);
                                return (
                                    <label key={factor} className={styles.factor} htmlFor={id}>
                                        <Checkbox id={id} checked={checked} onCheckedChange={(c) => toggle(category, factor, c === true)} />
                                        <span className={styles.factorLabel}>{label('factor', factor, labels?.factors?.[factor])}</span>
                                        <span className={points < 0 ? styles.pointsNegative : styles.points}>
                                            {points > 0 ? `+${points}` : points}
                                        </span>
                                    </label>
                                );
                            })}
                        </fieldset>
                    ))}
                </div>
            )}

            {showBody && (
                <p className={styles.hint}>
                    {t('Hot ≥ {{gold}} · Warm {{oak}} - {{oakMax}} · Cold below', {
                        gold: thresholds.gold_min,
                        oak: thresholds.oak_min,
                        oakMax: thresholds.gold_min - 1,
                    })}
                </p>
            )}
        </section>
    );
}
