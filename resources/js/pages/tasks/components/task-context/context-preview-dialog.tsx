import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ReactMarkdown from 'react-markdown';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

import styles from './context-preview-dialog.module.css';

export type ContextItemKind = 'description' | 'image' | 'pdf' | 'text' | 'archive' | 'doc';

export interface ContextItemAttachment {
    id: number;
    url: string;
    original_name: string;
    mime: string;
    size: number;
    label: string | null;
}

export interface ContextItem {
    id: string;
    kind: ContextItemKind;
    name: string;
    meta: string | null;
    /** Inline content - used for the synthetic description item. */
    content?: string;
    /** Attached file reference - used for everything else. */
    attachment?: ContextItemAttachment;
}

interface Props {
    item: ContextItem | null;
    onOpenChange: (open: boolean) => void;
}

const TEXT_PREVIEW_MAX_BYTES = 50 * 1024; // first 50 KB of large text files

/**
 * Renders a context item - task description or any attached file - in a
 * popup sized to its content (images + PDFs go wide, text stays narrow).
 * Binary archives (gz/zip/sql.gz) show metadata + a Download button rather
 * than an in-browser preview that would mostly hang on multi-MB files.
 */
export function ContextPreviewDialog({ item, onOpenChange }: Props) {
    const { t } = useTranslation();

    if (!item) return null;

    const sizeClass =
        item.kind === 'image' || item.kind === 'pdf'
            ? styles.contentLarge
            : item.kind === 'description' || item.kind === 'text'
              ? styles.contentMedium
              : styles.contentSmall;

    return (
        <Dialog open={item !== null} onOpenChange={onOpenChange}>
            <DialogContent className={sizeClass}>
                <DialogHeader>
                    <DialogTitle>{item.name}</DialogTitle>
                </DialogHeader>
                <div className={styles.body}>
                    <PreviewBody item={item} t={t} />
                </div>
                {item.attachment && (
                    <div className={styles.actions}>
                        <Button asChild variant="outline" size="sm">
                            <a href={item.attachment.url} download={item.attachment.original_name} target="_blank" rel="noreferrer">
                                <Download size={14} /> {t('Download')}
                            </a>
                        </Button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function PreviewBody({ item, t }: { item: ContextItem; t: (key: string, opts?: Record<string, unknown>) => string }) {
    if (item.kind === 'description' && item.content) {
        return (
            <div className={styles.markdown}>
                <ReactMarkdown>{item.content}</ReactMarkdown>
            </div>
        );
    }

    if (item.kind === 'image' && item.attachment) {
        return (
            <div className={styles.imageWrap}>
                <img className={styles.image} src={item.attachment.url} alt={item.attachment.original_name} />
            </div>
        );
    }

    if (item.kind === 'pdf' && item.attachment) {
        return <iframe className={styles.pdfFrame} src={item.attachment.url} title={item.attachment.original_name} />;
    }

    if (item.kind === 'text' && item.attachment) {
        return <TextPreview attachment={item.attachment} t={t} />;
    }

    // archive / doc / unknown
    if (item.attachment) {
        return <BinaryPreview attachment={item.attachment} t={t} />;
    }

    return null;
}

function TextPreview({ attachment, t }: { attachment: ContextItemAttachment; t: (key: string, opts?: Record<string, unknown>) => string }) {
    const [content, setContent] = useState<string | null>(null);
    const [truncated, setTruncated] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        setContent(null);
        setError(null);
        setTruncated(false);
        fetch(attachment.url, { credentials: 'same-origin' })
            .then(async (res) => {
                if (!res.ok) throw new Error('fetch failed');
                const blob = await res.blob();
                // Cap to TEXT_PREVIEW_MAX_BYTES so huge files don't crash the
                // browser. We slice the blob, not the post-decoded string -
                // safer for unicode boundary edge cases.
                const slice = blob.size > TEXT_PREVIEW_MAX_BYTES ? blob.slice(0, TEXT_PREVIEW_MAX_BYTES) : blob;
                const text = await slice.text();
                if (cancelled) return;
                setContent(text);
                setTruncated(blob.size > TEXT_PREVIEW_MAX_BYTES);
            })
            .catch(() => {
                if (!cancelled) setError(t('Failed to load preview'));
            });
        return () => {
            cancelled = true;
        };
    }, [attachment.url, t]);

    if (error) return <div className={styles.note}>{error}</div>;
    if (content === null) return <div className={styles.loading}>{t('Loading…')}</div>;

    // .md → render as markdown; .txt → preformatted (preserves whitespace).
    const ext = attachment.original_name.toLowerCase().split('.').pop() ?? '';
    if (ext === 'md') {
        return (
            <>
                <div className={styles.markdown}>
                    <ReactMarkdown>{content}</ReactMarkdown>
                </div>
                {truncated && <div className={styles.note}>{t('Preview truncated - download for the full file.')}</div>}
            </>
        );
    }

    return (
        <>
            <pre className={styles.preformatted}>{content}</pre>
            {truncated && <div className={styles.note}>{t('Preview truncated - download for the full file.')}</div>}
        </>
    );
}

function BinaryPreview({ attachment, t }: { attachment: ContextItemAttachment; t: (key: string, opts?: Record<string, unknown>) => string }) {
    return (
        <div className={styles.binaryCard}>
            <div className={styles.binaryRow}>
                <span className={styles.binaryLabel}>{t('Type')}</span>
                <span className={styles.binaryValue}>{attachment.mime}</span>
            </div>
            <div className={styles.binaryRow}>
                <span className={styles.binaryLabel}>{t('Size')}</span>
                <span className={styles.binaryValue}>{formatBytes(attachment.size)}</span>
            </div>
            {attachment.label && (
                <div className={styles.binaryRow}>
                    <span className={styles.binaryLabel}>{t('Label')}</span>
                    <span className={styles.binaryValue}>{attachment.label}</span>
                </div>
            )}
            <div className={styles.note}>
                {t('Binary file - download to inspect locally. The agent reads it directly by absolute path during the session.')}
            </div>
        </div>
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
