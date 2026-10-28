import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { MarkdownView } from '@/components/markdown-view';
import { fieldBoxClass, SoloTextarea } from '@/components/solo/field';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import styles from './markdown-textarea.module.css';

type Tab = 'write' | 'preview';

interface Props extends Omit<React.ComponentProps<'textarea'>, 'value' | 'onChange'> {
    value: string;
    onChange: (value: string) => void;
    /**
     * Optional control rendered in the top-right corner next to the tab
     * switcher (e.g. a "Polish with AI" button). Caller is responsible for
     * sizing.
     */
    trailing?: React.ReactNode;
    /** solo: the white field box and quiet tabs of the Solo dialogs (Figma A2f / Input). */
    look?: 'default' | 'solo';
}

/**
 * Textarea with a top-right Markdown / Preview tab switcher. Renders the
 * textarea while in Write mode and a MarkdownView preview while in Preview.
 * The control surface stays the same height in both modes.
 */
export function MarkdownTextarea({ value, onChange, trailing, className, rows = 6, disabled, look = 'default', ...rest }: Props) {
    const { t } = useTranslation();
    const [tab, setTab] = React.useState<Tab>('write');
    const solo = look === 'solo';
    const Field = solo ? SoloTextarea : Textarea;

    return (
        <div className={cn(styles.root, solo && styles.solo)}>
            <div className={styles.toolbar}>
                <div className={styles.tabs} role="tablist" aria-label="markdown view">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'write'}
                        className={cn(styles.tab, tab === 'write' && styles.tabActive)}
                        onClick={() => setTab('write')}
                    >
                        {t('Markdown')}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'preview'}
                        className={cn(styles.tab, tab === 'preview' && styles.tabActive)}
                        onClick={() => setTab('preview')}
                    >
                        {t('Preview')}
                    </button>
                </div>
                {trailing !== undefined && (
                    <div className={styles.trailing}>{trailing}</div>
                )}
            </div>

            {tab === 'write' ? (
                <Field
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    rows={rows}
                    disabled={disabled}
                    className={cn(!solo && styles.textarea, className)}
                    {...rest}
                />
            ) : (
                <div
                    className={cn(styles.markdown, solo ? [fieldBoxClass, styles.soloPreview] : styles.preview, className)}
                    style={{ minHeight: `${(rows ?? 6) * 1.5}rem` }}
                >
                    {value.trim() === '' ? (
                        <span className={styles.placeholder}>{t('Nothing to preview yet.')}</span>
                    ) : (
                        <MarkdownView>{value}</MarkdownView>
                    )}
                </div>
            )}
        </div>
    );
}
