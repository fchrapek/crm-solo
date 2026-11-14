import { useTranslation } from 'react-i18next';

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
 * Figma: Options (track), Etap. The pipeline as a row of pills: moving stage is the primary verb on a lead, so it
 * stands in the head, in the board's order.
 */
export function StageStepper({ stages, current, stageLabels, onSelect, disabled }: Props) {
    const { t } = useTranslation();
    const label = useLeadLabel();

    return (
        <div className={styles.track} role="group" aria-label={t('Stage')}>
            {stages.map((stage) => (
                <button
                    key={stage}
                    type="button"
                    className={styles.step}
                    onClick={() => stage !== current && onSelect(stage)}
                    disabled={disabled && stage !== current}
                    aria-pressed={stage === current}
                >
                    {label('stage', stage, stageLabels?.[stage])}
                </button>
            ))}
        </div>
    );
}
