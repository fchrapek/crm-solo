import { Check } from 'lucide-react';

import { useLeadLabel, type LabelMap } from '../../lib/labels';
import styles from './stage-stepper.module.css';

interface Props {
    stages: string[];
    current: string;
    /** Optional config-declared labels (slug => label). */
    stageLabels?: LabelMap;
    onSelect: (stage: string) => void;
    disabled?: boolean;
}

/**
 * The pipeline as a clickable progress bar - moving stage is the primary verb
 * on a lead, so it lives in the header instead of a Select buried mid-form,
 * and the horizontal layout mirrors the kanban's spatial model.
 */
export function StageStepper({ stages, current, stageLabels, onSelect, disabled }: Props) {
    const label = useLeadLabel();
    const currentIndex = stages.indexOf(current);

    return (
        <ol className={styles.stepper}>
            {stages.map((stage, index) => {
                const state = index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'upcoming';
                const stateClass = state === 'done' ? styles.done : state === 'current' ? styles.current : styles.upcoming;

                return (
                    <li key={stage} className={styles.step}>
                        <button
                            type="button"
                            className={`${styles.stepButton} ${stateClass}`}
                            onClick={() => state !== 'current' && onSelect(stage)}
                            disabled={disabled || state === 'current'}
                            aria-current={state === 'current' ? 'step' : undefined}
                        >
                            {state === 'done' && <Check size={12} />}
                            {label('stage', stage, stageLabels?.[stage])}
                        </button>
                        {index < stages.length - 1 && <span className={styles.connector} aria-hidden />}
                    </li>
                );
            })}
        </ol>
    );
}
