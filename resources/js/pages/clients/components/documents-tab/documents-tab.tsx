import { router } from '@inertiajs/react';
import { Download, FileText, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog/confirm-dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import clientDocuments from '@/routes/client-documents';

import styles from './documents-tab.module.css';

export interface ClientDocument {
    id: number;
    url: string;
    original_name: string;
    mime: string;
    size: number;
    category: string | null;
    label: string | null;
    created_at: string;
}

interface Props {
    clientId: number;
    documents: ClientDocument[];
}

const CATEGORIES = ['umowa', 'podpowierzenie', 'rodo', 'oferta', 'inne'] as const;

function formatSize(bytes: number): string {
    if (bytes >= 1_048_576) {
        return `${(bytes / 1_048_576).toFixed(1)} MB`;
    }
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export function DocumentsTab({ clientId, documents }: Props) {
    const { t } = useTranslation();
    const fileRef = useRef<HTMLInputElement>(null);
    const [category, setCategory] = useState<string>('umowa');
    const [filter, setFilter] = useState<string>('all');
    const [uploading, setUploading] = useState(false);
    const [pendingDelete, setPendingDelete] = useState<ClientDocument | null>(null);

    const visible = filter === 'all' ? documents : documents.filter((d) => (d.category ?? 'inne') === filter);

    const categoryLabel = (c: string | null): string => {
        switch (c) {
            case 'umowa':
                return t('Contract');
            case 'podpowierzenie':
                return t('Sub-processing');
            case 'rodo':
                return t('GDPR');
            case 'oferta':
                return t('Offer');
            default:
                return t('Other');
        }
    };

    const onPick = (files: FileList | null) => {
        const file = files?.[0];
        if (!file) return;

        setUploading(true);
        router.post(
            clientDocuments.store(clientId).url,
            { file, category },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    setUploading(false);
                    if (fileRef.current) fileRef.current.value = '';
                },
            },
        );
    };

    const confirmDelete = () => {
        if (!pendingDelete) return;
        router.delete(clientDocuments.destroy({ client: clientId, document: pendingDelete.id }).url, {
            preserveScroll: true,
            onFinish: () => setPendingDelete(null),
        });
    };

    return (
        <div className={styles.wrap}>
            <div className={styles.uploadRow}>
                <Select value={category} onValueChange={setCategory}>
                    <SelectTrigger className={styles.categorySelect}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {CATEGORIES.map((c) => (
                            <SelectItem key={c} value={c}>
                                {categoryLabel(c)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <input
                    ref={fileRef}
                    type="file"
                    className={styles.hiddenFile}
                    onChange={(e) => onPick(e.target.files)}
                    accept=".pdf,.doc,.docx,.odt,.rtf,.png,.jpg,.jpeg,.webp,.txt,.md,.csv,.xls,.xlsx"
                />
                <Button type="button" variant="outline" disabled={uploading} onClick={() => fileRef.current?.click()}>
                    <Upload size={15} />
                    {uploading ? t('Uploading…') : t('Upload document')}
                </Button>
                <span className={styles.hint}>{t('Stored locally, max 50 MB.')}</span>
            </div>

            {documents.length > 0 && (
                <div className={styles.filterRow}>
                    <span className={styles.filterLabel}>{t('Show')}</span>
                    <Select value={filter} onValueChange={setFilter}>
                        <SelectTrigger className={styles.categorySelect}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{t('All')}</SelectItem>
                            {CATEGORIES.map((c) => (
                                <SelectItem key={c} value={c}>
                                    {categoryLabel(c)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}

            {documents.length === 0 ? (
                <p className={styles.empty}>{t('No documents yet.')}</p>
            ) : visible.length === 0 ? (
                <p className={styles.empty}>{t('No documents in this category.')}</p>
            ) : (
                <ul className={styles.list}>
                    {visible.map((doc) => (
                        <li key={doc.id} className={styles.row}>
                            <FileText size={18} className={styles.fileIcon} />
                            <div className={styles.meta}>
                                <a href={doc.url} target="_blank" rel="noreferrer" className={styles.name}>
                                    {doc.original_name}
                                </a>
                                <span className={styles.sub}>
                                    {doc.category && <span className={styles.badge}>{categoryLabel(doc.category)}</span>}
                                    {formatSize(doc.size)} · {new Date(doc.created_at).toLocaleDateString()}
                                </span>
                            </div>
                            <a href={doc.url} target="_blank" rel="noreferrer" className={styles.iconBtn} aria-label={t('Open')}>
                                <Download size={16} />
                            </a>
                            <button type="button" className={styles.iconBtn} onClick={() => setPendingDelete(doc)} aria-label={t('Delete document')}>
                                <Trash2 size={16} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDialog
                open={pendingDelete !== null}
                onOpenChange={(o) => !o && setPendingDelete(null)}
                title={t('Delete this document?')}
                description={pendingDelete?.original_name ?? ''}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={confirmDelete}
            />
        </div>
    );
}
