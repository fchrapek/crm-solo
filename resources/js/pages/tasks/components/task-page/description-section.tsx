import { MarkdownView } from '@/components/markdown-view';
import { fieldControl, SoloField, SoloTextarea } from '@/components/solo/field';
import { SoloButton, soloButtonClass } from '@/components/solo/primitives';
import { Tile, TileHeader, TileNote } from '@/components/solo/tile';
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
 * Figma: Zadanie, the Opis tile (1094:40618; in edit 1094:41102). A CRM task edits in place (Edytuj, then Podgląd of
 * the draft, or Dodaj opis while there is none); a card's description is Trello's, read here and edited there.
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

    const quiet = { variant: 'quiet', size: 'control', className: styles.headAction } as const;
    const actions = card ? (
        task.trello_url !== null ? (
            <a href={task.trello_url} target="_blank" rel="noopener noreferrer" className={soloButtonClass(quiet)}>
                {t('Edit in Trello')} ↗
            </a>
        ) : undefined
    ) : empty ? (
        <SoloButton {...quiet} onClick={startEditing}>
            {t('Add description')}
        </SoloButton>
    ) : !editing ? (
        <SoloButton {...quiet} onClick={startEditing}>
            {t('Edit')}
        </SoloButton>
    ) : (
        <SoloButton {...quiet} onClick={() => setMode(mode === 'edit' ? 'preview' : 'edit')}>
            {mode === 'edit' ? t('Preview') : t('Edit')}
        </SoloButton>
    );

    return (
        <Tile kind="plain" aria-labelledby="task-description">
            <TileHeader label={<span id="task-description">{t('Description')}</span>} end={actions} />
            {editing && mode === 'edit' ? (
                <div data-fill="">
                    <SoloField label={t('Description (Markdown)')} htmlFor="task-description-text" error={error}>
                        <SoloTextarea
                            {...fieldControl('task-description-text', error)}
                            value={draft ?? ''}
                            onChange={(event) => setDraft(event.target.value)}
                            rows={8}
                            maxLength={50000}
                            disabled={saving}
                            autoFocus
                        />
                    </SoloField>
                </div>
            ) : shown.trim() === '' ? (
                <TileNote>{t('No description yet.')}</TileNote>
            ) : (
                <div className={styles.descriptionBody} data-clamped={(long && !expanded) || undefined} data-fill="">
                    <MarkdownPreview markdown={shown} />
                    {long && (
                        <button type="button" className={styles.moreToggle} aria-expanded={expanded} onClick={() => setExpanded((open) => !open)}>
                            {expanded ? `${t('Less')} ↑` : `${t('More')} ↓`}
                        </button>
                    )}
                </div>
            )}
            {editing && (
                <div className={styles.editActions} data-fill="">
                    <SoloButton variant="quiet" size="control" onClick={() => setDraft(null)} disabled={saving}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton size="control" onClick={save} disabled={saving}>
                        {saving ? t('Saving…') : t('Save')}
                    </SoloButton>
                </div>
            )}
        </Tile>
    );
}
