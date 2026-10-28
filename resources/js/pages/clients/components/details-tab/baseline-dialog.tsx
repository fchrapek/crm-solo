import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { MarkdownTextarea } from '@/components/ui/markdown-textarea';
import clients from '@/routes/clients';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './dialog-form.module.css';

/** Zakres bazowy raportu: the work every report lists, in markdown, saved through the report baseline endpoint. */
export function BaselineDialog({
    clientId,
    baseline,
    open,
    onOpenChange,
}: {
    clientId: number;
    baseline: string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState(baseline ?? '');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setDraft(baseline ?? '');
            setError(undefined);
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.put(
            clients.updateReportBaseline(clientId).url,
            { report_baseline_markdown: draft },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errs) => setError(errs.report_baseline_markdown),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Report baseline')}
            sheetTitle={t('Report baseline')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saving={processing}
            footerClassName={styles.actions}
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
            <MarkdownTextarea
                value={draft}
                onChange={setDraft}
                rows={8}
                disabled={processing}
                aria-label={t('Report baseline')}
                aria-invalid={error ? true : undefined}
            />
            {error && <p className={styles.error}>{error}</p>}
        </ResponsiveDialog>
    );
}
