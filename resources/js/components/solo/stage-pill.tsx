import { lifecycleStageLabelKey, lifecycleStageTone } from '@/lib/lifecycle-stage';
import { LifecycleStage } from '@/types';
import { useTranslation } from 'react-i18next';
import styles from './stage-pill.module.css';

/** Figma: Stage pill. */
export function StagePill({ stage }: { stage: LifecycleStage }) {
    const { t } = useTranslation();

    return (
        <span className={styles.pill} data-tone={lifecycleStageTone(stage)}>
            <span className={styles.dot} aria-hidden="true" />
            {t(lifecycleStageLabelKey(stage))}
        </span>
    );
}
