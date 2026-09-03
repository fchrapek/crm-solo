import { useLeadLabel } from '../../lib/labels';

import styles from './tier-badge.module.css';

interface Props {
    tier: string;
    /** Optional score shown alongside, e.g. "Hot · 7" */
    total?: number;
    /** Optional routing slug - surfaces WHAT the tier means as a tooltip. */
    routing?: string | null;
}

const TONE: Record<string, string> = {
    gold: styles.gold,
    oak: styles.oak,
    rowan: styles.rowan,
};

/**
 * Hot / Warm / Cold (slugs stay gold/oak/rowan - they key config's
 * tier_routing). The tier is routing, not decoration - Hot means a personal
 * reply inside 24h, Cold means no minutes spent - so it earns colour, and the
 * tooltip states the consequence so colour never carries meaning alone.
 */
export function TierBadge({ tier, total, routing }: Props) {
    const label = useLeadLabel();

    return (
        <span className={`${styles.badge} ${TONE[tier] ?? styles.rowan}`} title={routing ? label('routing', routing) : undefined}>
            {label('tier', tier)}
            {total !== undefined && <span className={styles.total}>{total}</span>}
        </span>
    );
}
