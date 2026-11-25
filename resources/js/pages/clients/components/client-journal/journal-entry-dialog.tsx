import { fieldControl, SoloField, SoloTextarea } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import lifecycleEvents from '@/routes/lifecycle-events';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './client-journal.module.css';

export interface EditedEntry {
    id: number;
    note: string;
    /** A stage change stands without a note, so its note may be emptied. */
    optional: boolean;
}

interface Props {
    clientId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** An existing entry whose note is edited; a new entry when absent. */
    entry?: EditedEntry | null;
}

/** New entry and Edit entry: the journal note the journal and `crm note` write; a bottom sheet on phones. */
export function JournalEntryDialog({ clientId, open, onOpenChange, entry = null }: Props) {
    const { t } = useTranslation();
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setNote(entry?.note ?? '');
            setError(undefined);
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        if (note.trim() === '' && entry?.optional !== true) {
            setError(t('Write the entry first.'));

            return;
        }
        setProcessing(true);
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (errors: Record<string, string>) => setError(errors.note),
            onFinish: () => setProcessing(false),
        };
        if (entry !== null) {
            router.patch(lifecycleEvents.updateNote(entry.id).url, { note: note.trim() }, options);
        } else {
            router.post(lifecycleEvents.store(clientId).url, { note: note.trim() }, options);
        }
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={entry !== null ? t('Edit entry') : t('Add log entry')}
            sheetTitle={entry !== null ? t('Edit entry') : t('New journal entry')}
            onSubmit={submit}
            sheetAction="save"
            saving={processing}
            fields
            footerClassName={styles.dialogActions}
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
            <SoloField label={t('Entry')} htmlFor="journal-entry" error={error}>
                <SoloTextarea
                    {...fieldControl('journal-entry', error)}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder={t('What did you work on? Notes, decisions, links…')}
                    maxLength={2000}
                    rows={6}
                    disabled={processing}
                    autoFocus
                />
            </SoloField>
        </ResponsiveDialog>
    );
}
