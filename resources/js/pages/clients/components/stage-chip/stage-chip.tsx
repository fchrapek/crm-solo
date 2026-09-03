import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { LIFECYCLE_STAGES, lifecycleStageLabelKey, lifecycleStageTone } from '@/lib/lifecycle-stage';
import { transitionStage } from '@/routes/clients';
import { LifecycleStage } from '@/types';

import styles from './stage-chip.module.css';

interface Props {
    clientId: number;
    currentStage: LifecycleStage;
}

/**
 * Inert stage display for the client header - the pill informs, the actual
 * stage control (<StageChip>) lives in the Etap relacji card on Aktywność.
 */
export function StagePill({ stage }: { stage: LifecycleStage }) {
    const { t } = useTranslation();

    return (
        <span className={styles.pill}>
            <span className={styles.dot} data-tone={lifecycleStageTone(stage)} />
            {t(lifecycleStageLabelKey(stage))}
        </span>
    );
}

export function StageChip({ clientId, currentStage }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    function pick(option: LifecycleStage) {
        if (option === currentStage || submitting) {
            setOpen(false);
            return;
        }
        setSubmitting(true);
        router.patch(
            transitionStage(clientId).url,
            { stage: option },
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
                onSuccess: () => setOpen(false),
            },
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button type="button" className={styles.trigger} aria-label={t('Change lifecycle stage')}>
                    <span className={styles.dot} data-tone={lifecycleStageTone(currentStage)} />
                    {t(lifecycleStageLabelKey(currentStage))}
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className={styles.popover}>
                <p className={styles.heading}>{t('Stage')}</p>
                <div className={styles.stageList}>
                    {LIFECYCLE_STAGES.map((option) => (
                        <button
                            key={option}
                            type="button"
                            className={styles.stageOption}
                            data-selected={currentStage === option}
                            onClick={() => pick(option)}
                            disabled={submitting}
                        >
                            <span className={styles.dot} data-tone={lifecycleStageTone(option)} />
                            {t(lifecycleStageLabelKey(option))}
                        </button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}
