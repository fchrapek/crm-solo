import { fieldControl, SoloField, SoloTextarea } from '@/components/solo/field';
import { KvRow } from '@/components/solo/kv-row';
import { SectionAction, SoloButton, touchTargetClass } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { SectionToggle } from '@/components/solo/section-toggle';
import tasks from '@/routes/tasks';
import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { actorName, briefFieldLabel, whenLabel } from './format';
import styles from './task-page.module.css';
import type { BriefField, TaskBriefData } from './types';

const FIELDS: BriefField[] = ['where', 'done_when', 'constraints', 'notes'];

function BriefSummary({ brief, now, today, locale }: { brief: TaskBriefData; now: number; today: string; locale: string }) {
    const { t } = useTranslation();
    const change = brief.last_change;
    const count = t('{{count}} fields', { count: brief.filled });
    if (change === null) {
        return <>{brief.filled === 0 ? t('empty') : count}</>;
    }
    const when = whenLabel(change, now, today, locale, t);

    return (
        <>
            {count} ·{' '}
            {change.actor.kind === 'owner' ? (
                t('you changed {{when}}', { when })
            ) : (
                <>
                    <span className={change.actor.kind === 'agent' ? styles.agentName : undefined}>{actorName(change.actor, t)}</span>{' '}
                    {t('changed {{when}}', { when })}
                </>
            )}
        </>
    );
}

function BriefDialog({
    taskId,
    brief,
    open,
    onOpenChange,
}: {
    taskId: number;
    brief: TaskBriefData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [values, setValues] = useState<Record<BriefField, string>>(() => blank(brief));
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Partial<Record<BriefField, string>>>({});

    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setValues(blank(brief));
            setErrors({});
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        router.put(tasks.brief(taskId).url, values, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (e) => setErrors(e as Partial<Record<BriefField, string>>),
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Brief for the agent')}
            onSubmit={submit}
            fields
            sheetAction="save"
            saving={processing}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            {FIELDS.map((field, index) => (
                <SoloField key={field} label={briefFieldLabel(field, t)} htmlFor={`brief-${field}`} error={errors[field]}>
                    <SoloTextarea
                        {...fieldControl(`brief-${field}`, errors[field])}
                        value={values[field]}
                        onChange={(event) => setValues((current) => ({ ...current, [field]: event.target.value }))}
                        rows={3}
                        maxLength={5000}
                        disabled={processing}
                        autoFocus={index === 0}
                    />
                </SoloField>
            ))}
        </ResponsiveDialog>
    );
}

function blank(brief: TaskBriefData): Record<BriefField, string> {
    return {
        where: brief.fields.where ?? '',
        done_when: brief.fields.done_when ?? '',
        constraints: brief.fields.constraints ?? '',
        notes: brief.fields.notes ?? '',
    };
}

/** Figma: Brief dla agenta (425:1171; phones 440:6985): the four fields, written by agents and by the owner here. */
export function BriefSection({
    taskId,
    brief,
    now,
    today,
    locale,
}: {
    taskId: number;
    brief: TaskBriefData;
    now: number;
    today: string;
    locale: string;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);

    return (
        <>
            <SectionToggle
                label={t('Brief for the agent')}
                summary={<BriefSummary brief={brief} now={now} today={today} locale={locale} />}
                phoneSummary={brief.filled === 0 ? t('empty') : t('{{count}} fields', { count: brief.filled })}
                extra={<SectionAction onClick={() => setEditing(true)}>{t('Edit')}</SectionAction>}
                phoneExtra={
                    brief.filled === 0 ? (
                        <SectionAction className={touchTargetClass} onClick={() => setEditing(true)}>
                            {t('Edit')}
                        </SectionAction>
                    ) : undefined
                }
                empty={brief.filled === 0}
            >
                <dl className={styles.briefList}>
                    {FIELDS.map((field) => (
                        <KvRow key={field} label={briefFieldLabel(field, t)} tone={brief.fields[field] === null ? 'muted' : undefined}>
                            {brief.fields[field] ?? t('not set')}
                        </KvRow>
                    ))}
                </dl>
                <button type="button" className={styles.phoneAction} onClick={() => setEditing(true)}>
                    {t('Edit brief')}
                </button>
            </SectionToggle>
            <BriefDialog taskId={taskId} brief={brief} open={editing} onOpenChange={setEditing} />
        </>
    );
}
