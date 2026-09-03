import { router } from '@inertiajs/react';
import { Archive, Database, Download, Eye, FileText, Image as ImageIcon, Trash, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { ConfirmDialog } from '@/components/ui/confirm-dialog';

import { ContextPreviewDialog, type ContextItem } from './context-preview-dialog';
import styles from './task-context.module.css';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

interface AttachmentSummary {
    id: number;
    url: string;
    original_name: string;
    mime: string;
    size: number;
    label: string | null;
}

interface Props {
    taskId: number;
    description: string | null;
    attachments: AttachmentSummary[];
}

const ACCEPTED_TYPES = '.jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,.md,.txt,.doc,.docx,.sql,.gz,.zip';

export function TaskContext({ taskId, description, attachments }: Props) {
    const { t } = useTranslation();
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const [uploading, setUploading] = useState(false);
    const [previewItem, setPreviewItem] = useState<ContextItem | null>(null);
    const [pendingDelete, setPendingDelete] = useState<AttachmentSummary | null>(null);
    // Drag counter avoids the dragenter/dragleave flicker when the cursor
    // crosses a child element - each enter increments, each leave decrements,
    // we only consider "not dragging" when the counter returns to zero.
    const [dragDepth, setDragDepth] = useState(0);
    const isDragging = dragDepth > 0;

    const items = useMemo<ContextItem[]>(() => {
        const list: ContextItem[] = [];
        if (description && description.trim() !== '') {
            list.push({
                id: 'description',
                kind: 'description',
                name: t('Description'),
                meta: t('markdown'),
                content: description,
            });
        }
        for (const att of attachments) {
            list.push(mapAttachment(att, t));
        }
        return list;
    }, [description, attachments, t]);

    const handleUpload = async (file: File) => {
        setUploading(true);
        const formData = new FormData();
        formData.append('file', file);
        try {
            const res = await fetch(`/tasks/${taskId}/attachments`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-XSRF-TOKEN': readXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: formData,
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({ message: t('Upload failed') }));
                const firstError = body?.errors ? Object.values(body.errors).flat()[0] : null;
                toast.error((firstError as string | undefined) ?? body.message ?? t('Upload failed'));
                return;
            }
            toast.success(t('Uploaded {{name}}', { name: file.name }));
            router.reload({ only: ['task'] });
        } catch {
            toast.error(t('Upload failed'));
        } finally {
            setUploading(false);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    };

    const handleDragEnter = (e: React.DragEvent) => {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        setDragDepth((d) => d + 1);
    };

    const handleDragLeave = (e: React.DragEvent) => {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        setDragDepth((d) => Math.max(0, d - 1));
    };

    const handleDragOver = (e: React.DragEvent) => {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    };

    const handleDrop = (e: React.DragEvent) => {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        setDragDepth(0);
        const file = e.dataTransfer.files?.[0];
        if (file) void handleUpload(file);
    };

    const handleDelete = async (id: number) => {
        setPendingDelete(null);
        try {
            const res = await fetch(`/task-attachments/${id}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'X-XSRF-TOKEN': readXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });
            if (!res.ok) {
                toast.error(t('Delete failed'));
                return;
            }
            router.reload({ only: ['task'] });
        } catch {
            toast.error(t('Delete failed'));
        }
    };

    return (
        <section
            className={`${styles.section} ${isDragging ? styles.sectionDragging : ''}`}
            onDragEnter={handleDragEnter}
            onDragLeave={handleDragLeave}
            onDragOver={handleDragOver}
            onDrop={handleDrop}
        >
            <h2 className={styles.title}>{t('Context')}</h2>

            {items.length === 0 && <div className={styles.empty}>{t('No context yet. Add a file or drop one below.')}</div>}

            <ul className={styles.list}>
                {items.map((item) => (
                    <li key={item.id}>
                        <button type="button" className={styles.row} onClick={() => setPreviewItem(item)}>
                            <ItemIcon kind={item.kind} />
                            <span className={styles.rowText}>
                                <span className={styles.name}>{item.name}</span>
                                {item.meta && <span className={styles.meta}>{item.meta}</span>}
                            </span>
                            <Eye size={14} className={styles.openIndicator} aria-hidden />
                            {item.attachment && (
                                <span className={styles.actions} onClick={(e) => e.stopPropagation()}>
                                    <a
                                        href={item.attachment.url}
                                        download={item.attachment.original_name}
                                        target="_blank"
                                        rel="noreferrer"
                                        className={styles.actionButton}
                                        aria-label={t('Download')}
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <Download size={14} />
                                    </a>
                                    <button
                                        type="button"
                                        className={styles.actionButton}
                                        aria-label={t('Delete attachment')}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setPendingDelete(item.attachment!);
                                        }}
                                    >
                                        <Trash size={14} />
                                    </button>
                                </span>
                            )}
                        </button>
                    </li>
                ))}
            </ul>

            <button
                type="button"
                className={`${styles.dropzone} ${isDragging ? styles.dropzoneActive : ''}`}
                onClick={() => fileInputRef.current?.click()}
                disabled={uploading}
            >
                <Upload size={14} />
                {uploading ? t('Uploading…') : isDragging ? t('Drop to upload') : t('Drop a file or click to upload')}
            </button>
            <input
                ref={fileInputRef}
                type="file"
                className={styles.hiddenInput}
                accept={ACCEPTED_TYPES}
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) void handleUpload(file);
                }}
            />

            <ContextPreviewDialog
                item={previewItem}
                onOpenChange={(open) => {
                    if (!open) setPreviewItem(null);
                }}
            />

            <ConfirmDialog
                open={pendingDelete !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingDelete(null);
                }}
                title={t('Delete attachment?')}
                description={t('Permanently delete {{name}}? This cannot be undone.', { name: pendingDelete?.original_name ?? '' })}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() => pendingDelete && handleDelete(pendingDelete.id)}
            />
        </section>
    );
}

function ItemIcon({ kind }: { kind: ContextItem['kind'] }) {
    switch (kind) {
        case 'image':
            return <ImageIcon size={16} className={styles.icon} />;
        case 'archive':
            return <Database size={16} className={styles.icon} />;
        case 'doc':
            return <Archive size={16} className={styles.icon} />;
        case 'description':
        case 'pdf':
        case 'text':
        default:
            return <FileText size={16} className={styles.icon} />;
    }
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function mapAttachment(att: AttachmentSummary, t: (k: string) => string): ContextItem {
    const ext = att.original_name.toLowerCase().split('.').pop() ?? '';
    const mime = att.mime.toLowerCase();
    const sizeLabel = formatBytes(att.size);
    const metaParts = [sizeLabel];
    if (att.label) metaParts.push(att.label);
    const meta = metaParts.join(' · ');

    let kind: ContextItem['kind'] = 'archive';
    if (mime.startsWith('image/')) kind = 'image';
    else if (mime === 'application/pdf' || ext === 'pdf') kind = 'pdf';
    else if (ext === 'md' || ext === 'txt') kind = 'text';
    else if (ext === 'doc' || ext === 'docx') kind = 'doc';
    else if (ext === 'sql' || ext === 'gz' || ext === 'zip') kind = 'archive';
    // Hint for archives: most .gz here are DB dumps, so prefer database wording
    // only when paired with an .sql implication (label says so OR name includes 'sql').
    const isDbDump =
        kind === 'archive' && (att.original_name.toLowerCase().includes('sql') || (att.label?.toLowerCase().includes('database') ?? false));
    if (isDbDump && !att.label) metaParts.push(t('database dump'));

    return {
        id: `attachment-${att.id}`,
        kind,
        name: att.original_name,
        meta,
        attachment: att,
    };
}
