import * as React from 'react';
import Markdown from 'react-markdown';
import { useTranslation } from 'react-i18next';

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
}

/**
 * Textarea with a top-right Markdown / Preview tab switcher. Renders the
 * textarea while in Write mode and a react-markdown preview while in Preview.
 * The control surface stays the same height in both modes.
 */
export function MarkdownTextarea({ value, onChange, trailing, className, rows = 6, disabled, ...rest }: Props) {
    const { t } = useTranslation();
    const [tab, setTab] = React.useState<Tab>('write');

    return (
        <div className={styles.root}>
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
                <Textarea
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    rows={rows}
                    disabled={disabled}
                    className={cn(styles.textarea, className)}
                    {...rest}
                />
            ) : (
                <div
                    className={cn(styles.preview, className)}
                    style={{ minHeight: `${(rows ?? 6) * 1.5}rem` }}
                >
                    {value.trim() === '' ? (
                        <span className={styles.placeholder}>{t('Nothing to preview yet.')}</span>
                    ) : (
                        <Markdown>{value}</Markdown>
                    )}
                </div>
            )}
        </div>
    );
}
