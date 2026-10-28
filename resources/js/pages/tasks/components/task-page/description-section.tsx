import { MarkdownView } from '@/components/markdown-view';
import { fieldControl, SoloTextarea } from '@/components/solo/field';
import { EmptyBlock, SectionAction, SectionHead, SoloButton } from '@/components/solo/primitives';
import tasks from '@/routes/tasks';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Components } from 'react-markdown';
import { isLongDescription } from './format';
import styles from './task-page.module.css';
import type { TaskData } from './types';

/** Links in a description open outside the app; card text is third-party, so nothing it links to is trusted. */
const MARKDOWN: Components = {
    a: ({ href, children }) => (
        <a href={href} target="_blank" rel="noopener noreferrer nofollow">
            {children}
        </a>
    ),
};

export function MarkdownPreview({ markdown }: { markdown: string }) {
    return (
        <MarkdownView className={styles.markdown} components={MARKDOWN}>
            {markdown}
        </MarkdownView>
    );
}

/**
 * Figma: Opis (770:56741; phones 849:20996; empty 910:59811). A CRM task edits in place (Edytuj, then Podgląd
 * of the draft, or Dodaj opis while there is none); a card's description is Trello's, read here and edited there.
 */
export function DescriptionSection({ task }: { task: TaskData }) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<string | null>(null);
    const [mode, setMode] = useState<'edit' | 'preview'>('edit');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [expanded, setExpanded] = useState(false);
    const editing = draft !== null;
    const card = task.has_trello_card;

    const startEditing = () => {
        setDraft(task.description ?? '');
        setMode('edit');
        setError(undefined);
    };

    const save = () => {
        if (draft === null) return;
        setSaving(true);
        router.patch(
            tasks.description(task.id).url,
            { description: draft },
            {
                preserveScroll: true,
                onSuccess: () => setDraft(null),
                onError: (errors) => setError(errors.description ?? t('The description was not saved.')),
                onFinish: () => setSaving(false),
            },
        );
    };

    const shown = editing ? (draft ?? '') : task.description_markdown;
    const empty = !editing && shown.trim() === '';
    const long = !editing && isLongDescription(shown);

    const actions = card ? (
        <span className={styles.cardActions}>
            {task.trello_url !== null && (
                <a href={task.trello_url} target="_blank" rel="noopener noreferrer" className={styles.trelloAction}>
                    {t('Edit in Trello')} ↗
                </a>
            )}
            {!empty && <span className={styles.previewLabel}>{t('Preview')}</span>}
        </span>
    ) : empty ? (
        <SectionAction onClick={startEditing}>{t('Add description')}</SectionAction>
    ) : (
        <>
            <SectionAction aria-pressed={editing && mode === 'edit'} onClick={() => (editing ? setMode('edit') : startEditing())}>
                {t('Edit')}
            </SectionAction>
            <SectionAction aria-pressed={!editing || mode === 'preview'} onClick={() => editing && setMode('preview')}>
                {t('Preview')}
            </SectionAction>
        </>
    );

    return (
        <section className={styles.description} aria-labelledby="task-description">
            <SectionHead kind="body" label={<span id="task-description">{t('Description')}</span>} action={actions} />
            {editing && mode === 'edit' ? (
                <div className={styles.descriptionEdit}>
                    <SoloTextarea
                        {...fieldControl('task-description-text', error)}
                        aria-label={t('Description')}
                        value={draft ?? ''}
                        onChange={(event) => setDraft(event.target.value)}
                        rows={12}
                        maxLength={50000}
                        disabled={saving}
                        autoFocus
                    />
                    {error && <p className={styles.fieldError}>{error}</p>}
                </div>
            ) : shown.trim() === '' ? (
                <EmptyBlock>{t('No description yet.')}</EmptyBlock>
            ) : (
                <div className={styles.descriptionBody} data-clamped={(long && !expanded) || undefined}>
                    <MarkdownPreview markdown={shown} />
                    {long && (
                        <button type="button" className={styles.moreToggle} aria-expanded={expanded} onClick={() => setExpanded((open) => !open)}>
                            {expanded ? `${t('Less')} ↑` : `${t('More')} ↓`}
                        </button>
                    )}
                </div>
            )}
            {editing && (
                <div className={styles.editActions}>
                    <SoloButton variant="outline" size="touch" className={styles.editButton} onClick={() => setDraft(null)} disabled={saving}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton variant="ink" size="touch" className={styles.editButton} onClick={save} disabled={saving}>
                        {saving ? t('Saving…') : t('Save')}
                    </SoloButton>
                </div>
            )}
        </section>
    );
}
