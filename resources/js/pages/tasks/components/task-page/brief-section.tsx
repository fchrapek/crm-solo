import { fieldControl, SoloField, SoloTextarea } from '@/components/solo/field';
import { KvRow } from '@/components/solo/kv-row';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Tile, TileHeader, TileNote } from '@/components/solo/tile';
import tasks from '@/routes/tasks';
import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { actorName, briefFieldLabel, whenLabel } from './format';
import styles from './task-page.module.css';
import type { BriefField, TaskBriefData } from './types';

const FIELDS: BriefField[] = ['where', 'done_when', 'constraints', 'notes'];

type Change = NonNullable<TaskBriefData['last_change']>;

/** "zmieniono dziś 11:20 · Codex": when the brief last changed and, unless it was the owner, who did it. */
function BriefChange({ change, now, today, locale }: { change: Change; now: number; today: string; locale: string }) {
    const { t } = useTranslation();
    const when = whenLabel(change, now, today, locale, t);

    return change.actor.kind === 'owner' ? (
        <>{t('you changed {{when}}', { when })}</>
    ) : (
        <>
            {t('you changed {{when}}', { when })} · {actorName(change.actor, t)}
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

/**
 * Figma: Task, the Brief for the agent tile (1094:40618; empty 1094:41102): the four fields, written by agents and by
 * the owner in the dialog, and who changed them last.
 */
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
        <Tile kind="plain" aria-labelledby="task-brief">
            <TileHeader
                label={<span id="task-brief">{t('Brief for the agent')}</span>}
                count={brief.filled === 0 ? t('empty') : t('{{count}} fields', { count: brief.filled })}
                end={
                    <SoloButton variant="quiet" size="control" className={styles.headAction} onClick={() => setEditing(true)}>
                        {t('Edit')}
                    </SoloButton>
                }
            />
            <dl className={styles.briefList}>
                {FIELDS.map((field) => (
                    <KvRow key={field} layout="field" label={briefFieldLabel(field, t)} tone={brief.fields[field] === null ? 'muted' : undefined}>
                        {brief.fields[field] ?? t('not set')}
                    </KvRow>
                ))}
            </dl>
            {brief.last_change !== null && (
                <TileNote>
                    <BriefChange change={brief.last_change} now={now} today={today} locale={locale} />
                </TileNote>
            )}
            <BriefDialog taskId={taskId} brief={brief} open={editing} onOpenChange={setEditing} />
        </Tile>
    );
}
