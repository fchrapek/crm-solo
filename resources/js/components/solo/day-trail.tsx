import { Icon } from '@/components/ui/icon';
import { dashboard } from '@/routes';
import today from '@/routes/today';
import { useTranslation } from 'react-i18next';
import { SegmentedControl } from './segmented-control';

export type DayStep = 'today' | 'done' | 'tomorrow' | 'week';

/**
 * Figma: A2f / Day trail. The day's stages and the week ahead; every step is a link, and only the close button on the summary writes.
 * `current` is omitted off the stages. One set of links: the Width=Full segmented control below 768 px, the caps text
 * trail from 768 px.
 */
export function DayTrail({ current, closed = false }: { current?: DayStep; closed?: boolean }) {
    const { t } = useTranslation();
    const steps = [
        { key: 'today', label: t('Today'), href: dashboard({ query: { widok: 'dzis' } }) },
        { key: 'done', label: t('Summary'), href: dashboard({ query: { widok: 'gotowe' } }) },
        { key: 'tomorrow', label: t('Tomorrow'), href: today.plan() },
        { key: 'week', label: t('Week'), href: today.week() },
    ] as const;

    return (
        <SegmentedControl
            label={t('The day')}
            width="full"
            wide="trail"
            current="step"
            value={current}
            options={steps.map((step) => ({
                value: step.key,
                href: step.href,
                hug: step.key === 'done',
                label:
                    step.key === 'done' && closed ? (
                        <>
                            <Icon name="done" size={16} filled aria-label={t('Day closed')} />
                            <span>{step.label}</span>
                        </>
                    ) : (
                        <span>{step.label}</span>
                    ),
            }))}
        />
    );
}
