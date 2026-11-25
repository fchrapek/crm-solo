import { useTranslation } from 'react-i18next';

import { Stat } from '@/components/solo/primitives';
import { Tile } from '@/components/solo/tile';
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
}

/**
 * Figma: Score. FIT / BEHAVIOUR / TRIGGER checkboxes. Points come from the server's copy of
 * the config map - never a duplicate table in the frontend - so a re-tuned
 * plan changes these labels without a code edit here.
 *
 * The running total is computed locally purely as immediate feedback; the
 * server's derivation stays the source of truth and is what the badge shows
 * once saved.
 */
export function ScorePanel({ scoring, value, onChange, savedTier, savedRouting, dirty, thresholds, labels }: Props) {
    const { t } = useTranslation();
    const label = useLeadLabel();

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

    return (
        <Tile kind="plain">
            <Stat
                kind="tile"
                label={t('Score')}
                value={`${liveTotal} ${t('pts')}`}
                sub={t('Hot ≥ {{gold}} · Warm {{oak}} - {{oakMax}} · Cold below', {
                    gold: thresholds.gold_min,
                    oak: thresholds.oak_min,
                    oakMax: thresholds.gold_min - 1,
                })}
            />
            <div className={styles.verdict}>
                {dirty ? (
                    // The tier is the server's call: no stale tier beside edited checkboxes.
                    <span className={styles.routing}>{t('Save to update tier')}</span>
                ) : (
                    <>
                        <TierBadge tier={savedTier} />
                        {savedRouting && <span className={styles.routing}>{label('routing', savedRouting, labels?.routing?.[savedRouting])}</span>}
                    </>
                )}
            </div>
            <div className={styles.categories} data-fill="">
                {Object.entries(scoring).map(([category, factors]) => (
                    <fieldset key={category} className={styles.category}>
                        <legend className={styles.legend}>{label('category', category, labels?.categories?.[category])}</legend>
                        {Object.entries(factors).map(([factor, points]) => {
                            const id = `${category}-${factor}`;
                            const checked = (value[category] ?? []).includes(factor);
                            return (
                                <label key={factor} className={styles.factor} htmlFor={id}>
                                    <input id={id} type="checkbox" checked={checked} onChange={(event) => toggle(category, factor, event.target.checked)} />
                                    <span className={styles.factorLabel}>{label('factor', factor, labels?.factors?.[factor])}</span>
                                    <span className={styles.points}>{points > 0 ? `+${points}` : String(points).replace('-', '−')}</span>
                                </label>
                            );
                        })}
                    </fieldset>
                ))}
            </div>
        </Tile>
    );
}
