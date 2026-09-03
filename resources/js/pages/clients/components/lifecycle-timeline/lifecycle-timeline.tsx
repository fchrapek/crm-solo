import { router } from '@inertiajs/react';
import { StickyNote } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { lifecycleStageLabelKey, lifecycleStageTone } from '@/lib/lifecycle-stage';
import { updateNote as updateNoteRoute } from '@/routes/lifecycle-events';
import { LifecycleStage } from '@/types';

import styles from './lifecycle-timeline.module.css';

interface LifecycleEvent {
    id: number;
    from_stage: LifecycleStage | null;
    to_stage: LifecycleStage;
    note: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
}

interface Props {
    events: LifecycleEvent[];
    clientId: number;
}

// A same-stage event (from === to) carries no transition - it's a free-form
// work-log note. Rendered without the stage pills.
function isLogNote(event: LifecycleEvent): boolean {
    return event.from_stage !== null && event.from_stage === event.to_stage;
}

function formatDate(iso: string, locale: string): string {
    return new Date(iso).toLocaleDateString(locale, { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * Note text lives in a dialog, not inline - the timeline stays a compact
 * sequence of dated rows in the rail; one dialog serves both the "+ Add log
 * entry" composer and per-event note viewing/editing.
 */
function NoteDialog({
    open,
    onOpenChange,
    title,
    initial,
    placeholder,
    onSave,
    saving,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    initial: string;
    placeholder: string;
    onSave: (note: string) => void;
    saving: boolean;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState(initial);

    useEffect(() => {
        if (open) setDraft(initial);
    }, [open, initial]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={styles.noteDialog}>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>
                <Textarea
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    placeholder={placeholder}
                    maxLength={2000}
                    rows={6}
                    autoFocus
                    disabled={saving}
                />
                <div className={styles.dialogActions}>
                    <Button variant="ghost" type="button" onClick={() => onOpenChange(false)} disabled={saving}>
                        {t('Cancel')}
                    </Button>
                    <Button type="button" onClick={() => onSave(draft)} disabled={saving}>
                        {t('Save')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export function LifecycleTimeline({ events, clientId }: Props) {
    const { t, i18n } = useTranslation();
    const [composerOpen, setComposerOpen] = useState(false);
    const [editingEvent, setEditingEvent] = useState<LifecycleEvent | null>(null);
    const [saving, setSaving] = useState(false);

    const createEntry = (note: string) => {
        const trimmed = note.trim();
        if (!trimmed) return;
        setSaving(true);
        router.post(
            `/clients/${clientId}/lifecycle-events`,
            { note: trimmed },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
                onSuccess: () => setComposerOpen(false),
            },
        );
    };

    const saveNote = (note: string) => {
        if (!editingEvent) return;
        setSaving(true);
        router.patch(
            updateNoteRoute(editingEvent.id).url,
            { note: note.trim() || null },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
                onSuccess: () => setEditingEvent(null),
            },
        );
    };

    return (
        <div className={styles.wrap}>
            <button type="button" className={styles.addEntryButton} onClick={() => setComposerOpen(true)}>
                + {t('Add log entry')}
            </button>

            {events.length === 0 ? (
                <p className={styles.empty}>{t('No lifecycle changes yet.')}</p>
            ) : (
                <ol className={styles.list}>
                    {events.map((event) => (
                        <li key={event.id} className={styles.item}>
                            <span className={styles.dot} data-tone={isLogNote(event) ? 'neutral' : lifecycleStageTone(event.to_stage)} />
                            <div className={styles.body}>
                                <div className={styles.transition}>
                                    <span className={styles.timestamp}>{formatDate(event.created_at, i18n.language)}</span>
                                    {isLogNote(event) ? (
                                        <span className={styles.noteBadge}>
                                            <StickyNote size={12} /> {t('Log entry')}
                                        </span>
                                    ) : (
                                        <>
                                            {event.from_stage ? (
                                                <>
                                                    <span className={styles.stagePill} data-tone={lifecycleStageTone(event.from_stage)}>
                                                        {t(lifecycleStageLabelKey(event.from_stage))}
                                                    </span>
                                                    <span className={styles.arrow}>→</span>
                                                </>
                                            ) : null}
                                            <span className={styles.stagePill} data-tone={lifecycleStageTone(event.to_stage)}>
                                                {t(lifecycleStageLabelKey(event.to_stage))}
                                            </span>
                                        </>
                                    )}
                                    {!event.note && (
                                        <button
                                            type="button"
                                            className={styles.noteIconButton}
                                            onClick={() => setEditingEvent(event)}
                                            aria-label={t('Add note')}
                                            title={t('Add note')}
                                        >
                                            <StickyNote size={12} />
                                        </button>
                                    )}
                                </div>
                                {event.note && (
                                    <button type="button" className={styles.notePreview} onClick={() => setEditingEvent(event)}>
                                        {event.note}
                                    </button>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
            )}

            <NoteDialog
                open={composerOpen}
                onOpenChange={setComposerOpen}
                title={t('Add log entry')}
                initial=""
                placeholder={t('What did you work on? Notes, decisions, links…')}
                onSave={createEntry}
                saving={saving}
            />
            <NoteDialog
                open={editingEvent !== null}
                onOpenChange={(open) => !open && setEditingEvent(null)}
                title={editingEvent ? formatDate(editingEvent.created_at, i18n.language) : ''}
                initial={editingEvent?.note ?? ''}
                placeholder={t('Add context - what was discussed, what you sent, links…')}
                onSave={saveNote}
                saving={saving}
            />
        </div>
    );
}
