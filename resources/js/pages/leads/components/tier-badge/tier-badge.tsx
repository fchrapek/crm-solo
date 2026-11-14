import { Chip, type ChipStatus } from '@/components/solo/chip';
import { useLeadLabel } from '../../lib/labels';

interface Props {
    tier: string;
    /** Optional score shown alongside, e.g. "Hot · 7" */
    total?: number;
    /** Optional routing slug - surfaces WHAT the tier means as a tooltip. */
    routing?: string | null;
}

const DOT: Record<string, ChipStatus> = { gold: 'hot', oak: 'warm', rowan: 'cold' };

/**
 * Figma: TierChip. Hot / Warm / Cold (slugs stay gold/oak/rowan - they key config's tier_routing). The tier is
 * routing: Hot means a personal reply inside 24h, Cold means no minutes spent. The dot's shape tells it, not a colour.
 */
export function TierBadge({ tier, total, routing }: Props) {
    const label = useLeadLabel();

    return (
        <span title={routing ? label('routing', routing) : undefined}>
            <Chip status={DOT[tier] ?? 'cold'}>
                {label('tier', tier)}
                {total !== undefined && ` · ${total}`}
            </Chip>
        </span>
    );
}
