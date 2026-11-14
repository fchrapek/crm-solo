import { Icon } from '@/components/ui/icon';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SoloButton } from '../../primitives';
import styles from './helper.module.css';
import { type HelperLink, type HelperWindowKind } from './hints';

const SUGGESTIONS = [
    ['helper_plan_task_overdue', 'helper_plan_reason_overdue'],
    ['helper_plan_task_waiting', 'helper_plan_reason_waiting'],
    ['helper_plan_task_report', 'helper_plan_reason_report'],
] as const;

export type HelperStepState = 'done' | 'current' | 'todo';

export const REPORT_STEPS: readonly (readonly [string, HelperStepState])[] = [
    ['helper_report_step_time', 'done'],
    ['helper_report_step_billed', 'done'],
    ['helper_report_step_text', 'current'],
    ['helper_report_step_balance', 'todo'],
    ['helper_report_step_draft', 'todo'],
];

const STEP_ICON = { done: 'done', current: 'arrow-right', todo: 'circle' } as const;

/** A button of the preview: it is drawn, it can be focused and read, and it does nothing. */
function PreviewButton({ children }: { children: ReactNode }) {
    const { t } = useTranslation();

    return (
        <SoloButton size="control" variant="secondary" aria-disabled="true" title={t('Preview: this button does nothing yet')} className={styles.inert}>
            {children}
        </SoloButton>
    );
}

function Example({ children }: { children: ReactNode }) {
    const { t } = useTranslation();

    return (
        <div className={styles.example}>
            <span className={styles.exampleLabel}>{t('Example')}</span>
            {children}
        </div>
    );
}

function Plan() {
    const { t } = useTranslation();

    return (
        <Example>
            <ul className={styles.cards}>
                {SUGGESTIONS.map(([title, reason]) => (
                    <li key={title} className={styles.card}>
                        <span className={styles.cardText}>
                            <span className={styles.cardTitle}>{t(title)}</span>
                            <span className={styles.cardReason}>{t(reason)}</span>
                        </span>
                        <PreviewButton>{t('Add')}</PreviewButton>
                    </li>
                ))}
            </ul>
        </Example>
    );
}

function Report() {
    const { t } = useTranslation();
    const done = REPORT_STEPS.filter(([, state]) => state === 'done').length;

    return (
        <Example>
            <ol className={styles.steps} aria-label={t('helper_report_progress', { done, total: REPORT_STEPS.length })}>
                {REPORT_STEPS.map(([text, state]) => (
                    <li key={text} className={styles.step} data-state={state} aria-current={state === 'current' ? 'step' : undefined}>
                        <Icon name={STEP_ICON[state]} size={20} filled={state === 'done'} />
                        {t(text)}
                    </li>
                ))}
            </ol>
            <span className={styles.progress}>{t('helper_report_progress', { done, total: REPORT_STEPS.length })}</span>
        </Example>
    );
}

function Client({ links }: { links: HelperLink[] }) {
    const { t } = useTranslation();

    return links.length === 0 ? (
        <Example>
            <p className={styles.plain}>{t('helper_client_no_record')}</p>
        </Example>
    ) : (
        <ul className={styles.cards}>
            {links.map((link) => (
                <li key={link.href}>
                    <Link href={link.href} className={styles.link}>
                        {link.label}
                        <Icon name="arrow-right" size={20} />
                    </Link>
                </li>
            ))}
        </ul>
    );
}

interface Talk {
    asked: string;
    said: string;
    /** A chip with show opens that window; one without is drawn only. */
    chips: { label: string; show?: HelperWindowKind }[];
}

const TALK: Record<HelperWindowKind, Talk> = {
    plan: { asked: 'Plan the day', said: 'helper_plan_said', chips: [{ label: 'Add all' }, { label: 'Prepare a report', show: 'report' }] },
    report: { asked: 'Prepare a report', said: 'helper_report_said', chips: [{ label: 'Pause' }, { label: 'What is going on at the client?', show: 'client' }] },
    client: {
        asked: 'What is going on at the client?',
        said: 'helper_client_said',
        chips: [
            { label: 'Prepare a report', show: 'report' },
            { label: 'Plan the day', show: 'plan' },
        ],
    },
};

/** The helper's window: example content only. Nothing here sends, answers or remembers. `line` is the page's hint, said first, so a touch screen that never shows the hint still reads it. */
export function HelperWindow({
    kind,
    links = [],
    line,
    onShow,
}: {
    kind: HelperWindowKind;
    links?: HelperLink[];
    line?: string;
    onShow?: (kind: HelperWindowKind) => void;
}) {
    const { t } = useTranslation();
    const talk = TALK[kind];

    return (
        <>
            <div className={styles.talk} data-helper-window={kind}>
                {line !== undefined && <p className={styles.said}>{line}</p>}
                <p className={`${styles.said} ${styles.mine}`}>{t(talk.asked)}</p>
                <p className={styles.said}>{t(talk.said)}</p>
                {kind === 'plan' ? <Plan /> : kind === 'report' ? <Report /> : <Client links={links} />}
                <div className={styles.ideas} role="group" aria-label={t('Example actions')}>
                    {talk.chips.map(({ label, show }) =>
                        show === undefined ? (
                            <PreviewButton key={label}>{t(label)}</PreviewButton>
                        ) : (
                            <SoloButton key={label} size="control" variant="secondary" onClick={() => onShow?.(show)}>
                                {t(label)}
                            </SoloButton>
                        ),
                    )}
                </div>
                <p className={styles.note}>{t('helper_preview_note')}</p>
            </div>
            <div className={styles.field}>
                <input type="text" className={styles.input} placeholder={t('Preview: writing does not work yet')} aria-label={t('Preview: writing does not work yet')} disabled />
                <span className={styles.send} aria-hidden="true">
                    <Icon name="arrow-right" size={20} />
                </span>
            </div>
        </>
    );
}
