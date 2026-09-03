import { Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';

import styles from './polish-button.module.css';

interface Props {
    taskId: number;
    field: 'title' | 'description' | 'success_criteria';
    value: string;
    onAccept: (polished: string) => void;
    disabled?: boolean;
}

interface PolishResponse {
    field: string;
    original: string;
    polished: string;
}

/**
 * "✨ Polish" button that fires a clarity/typo-only AI rewrite of the linked
 * field's current value. On success, renders a side-by-side accept/reject
 * panel directly below the button so the user sees the proposed change before
 * committing - never auto-applies.
 *
 * Polish prompt is clarity + structure + typos only; no gap-filling. See
 * `TaskTextPolisherService` for the contract.
 */
export function PolishButton({ taskId, field, value, onAccept, disabled }: Props) {
    const { t } = useTranslation();
    const [loading, setLoading] = useState(false);
    const [polished, setPolished] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const csrfToken = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '');
    const xsrfCookie = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '';

    const handleClick = async () => {
        if (!value.trim()) return;
        setLoading(true);
        setError(null);
        setPolished(null);
        try {
            const res = await fetch(`/tasks/${taskId}/polish`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ field, text: value }),
            });
            if (!res.ok) {
                const body = (await res.json().catch(() => ({}))) as { error?: string };
                throw new Error(body.error ?? `HTTP ${res.status}`);
            }
            const data = (await res.json()) as PolishResponse;
            setPolished(data.polished);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className={styles.root}>
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={handleClick}
                disabled={disabled || loading || !value.trim()}
                className={styles.button}
                title={t('Clarity / typos / structure only - no content guessed.')}
            >
                {loading ? <Loader2 size={12} className={styles.spinning} /> : <Sparkles size={12} />}
                {loading ? t('Polishing…') : t('Polish with AI')}
            </Button>

            {error !== null && (
                <div className={styles.error} role="alert">{error}</div>
            )}

            {polished !== null && (
                <div className={styles.diffPanel}>
                    <div className={styles.diffHeader}>
                        <span className={styles.diffLabel}>{t('AI suggestion')}</span>
                        <div className={styles.diffActions}>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setPolished(null)}
                            >
                                {t('Reject')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => {
                                    onAccept(polished);
                                    setPolished(null);
                                }}
                            >
                                {t('Accept')}
                            </Button>
                        </div>
                    </div>
                    <pre className={styles.diffBody}>{polished}</pre>
                </div>
            )}
        </div>
    );
}
