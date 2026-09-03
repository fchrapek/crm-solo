import { LifecycleStage } from '@/types';

// Pickable relationship states. The LifecycleStage TYPE keeps the retired
// 'prospect'/'offer_sent' values (and the label/tone maps below cover them)
// because append-only history events still carry those slugs.
export const LIFECYCLE_STAGES: LifecycleStage[] = ['active', 'paused', 'churned'];

const STAGE_LABEL_KEY: Record<LifecycleStage, string> = {
    prospect: 'Prospect',
    offer_sent: 'Offer sent',
    active: 'Active',
    paused: 'Paused',
    churned: 'Churned',
};

export function lifecycleStageLabelKey(stage: LifecycleStage): string {
    return STAGE_LABEL_KEY[stage];
}

const STAGE_TONE: Record<LifecycleStage, 'neutral' | 'blue' | 'green' | 'amber' | 'grey'> = {
    prospect: 'neutral',
    offer_sent: 'blue',
    active: 'green',
    paused: 'amber',
    churned: 'grey',
};

export function lifecycleStageTone(stage: LifecycleStage): 'neutral' | 'blue' | 'green' | 'amber' | 'grey' {
    return STAGE_TONE[stage];
}
