import { fieldControl, SoloField } from '@/components/solo/field';
import { SectionAction, SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { shortDate } from '@/lib/dates';
import { localeOf } from '@/lib/hours-text';
import clientDocuments from '@/routes/client-documents';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './details-tab.module.css';
import formStyles from './dialog-form.module.css';
import type { ClientDocumentRow } from './types';

const CATEGORY_LABEL: Record<string, string> = { umowa: 'Contract', podpowierzenie: 'Sub-processing', rodo: 'GDPR', oferta: 'Offer', inne: 'Other' };

/** Figma: Dokumenty · 4 on Dane (578:11582): name, category and date, Otwórz; + Dokument uploads one file with its category. */
export function DocumentsSection({ clientId, documents }: { clientId: number; documents: ClientDocumentRow[] }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<ClientDocumentRow | null>(null);

    return (
        <section>
            <SectionHead
                kind="body"
                label={`${t('Documents')} · ${documents.length}`}
                action={
                    <SectionAction aria-haspopup="dialog" onClick={() => setAdding(true)}>
                        {t('New document')}
                    </SectionAction>
                }
            />
            {documents.length === 0 ? (
                <p className={styles.empty}>{t('No documents yet.')}</p>
            ) : (
                <ul className={styles.documents}>
                    {documents.map((document) => (
                        <li key={document.id} className={styles.document}>
                            <span className={styles.documentText}>
                                <span className={styles.documentName}>{document.label || document.original_name}</span>
                                <span className={styles.documentSub}>
                                    {t(CATEGORY_LABEL[document.category ?? 'inne'] ?? 'Other')} · {shortDate(document.created_on, locale)}
                                </span>
                            </span>
                            <span className={styles.documentActions}>
                                <a href={document.url} target="_blank" rel="noopener noreferrer" className={styles.documentLink}>
                                    {t('Open')} ↗
                                </a>
                                <button type="button" className={styles.documentRemove} onClick={() => setRemoving(document)}>
                                    {t('Delete')}
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <AddDocumentDialog clientId={clientId} open={adding} onOpenChange={setAdding} />
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={t('Delete document?')}
                description={removing?.original_name ?? ''}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() =>
                    removing &&
                    router.delete(clientDocuments.destroy({ client: clientId, document: removing.id }).url, {
                        preserveScroll: true,
                        onFinish: () => setRemoving(null),
                    })
                }
            />
        </section>
    );
}

function AddDocumentDialog({ clientId, open, onOpenChange }: { clientId: number; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const [category, setCategory] = useState('umowa');
    const [file, setFile] = useState<File | null>(null);
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    // A cancelled pick must not ride along with the next upload: every opening starts empty.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setFile(null);
            setCategory('umowa');
            setError(undefined);
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        if (file === null) return;
        setProcessing(true);
        router.post(
            clientDocuments.store(clientId).url,
            { file, category },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    setFile(null);
                    onOpenChange(false);
                },
                onError: (errs) => setError(errs.file ?? errs.category),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('New document')}
            sheetTitle={t('New document')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={t('Add')}
            saving={processing}
            footerClassName={formStyles.actions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing || file === null}>
                        {processing ? t('Uploading…') : t('Add')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Category')} htmlFor="document-category">
                <ResponsiveSelect
                    label={t('Category')}
                    value={category}
                    onValueChange={setCategory}
                    disabled={processing}
                    control={fieldControl('document-category')}
                    options={Object.entries(CATEGORY_LABEL).map(([value, label]) => ({ value, label: t(label) }))}
                />
            </SoloField>
            <SoloField label={t('File')} htmlFor="document-file" error={error}>
                <input
                    id="document-file"
                    type="file"
                    className={styles.file}
                    accept=".pdf,.doc,.docx,.odt,.rtf,.png,.jpg,.jpeg,.webp,.txt,.md,.csv,.xls,.xlsx"
                    onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    disabled={processing}
                />
            </SoloField>
            <p className={formStyles.hint}>{t('Stored locally, max 50 MB.')}</p>
        </ResponsiveDialog>
    );
}
