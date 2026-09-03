import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

interface RunningEntry {
    id: number;
    description: string | null;
    start_time: string | null;
    source: 'clockify' | 'terminal_session' | 'manual';
    task: { id: number; name: string } | null;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    taskId: number;
    onSaved?: () => void;
}

type Step = 'checking' | 'conflict_warning' | 'form';

/**
 * Two-step manual time entry:
 *   1. On open we hit /time-entries/running. If any open entry exists, we
 *      surface a conflict warning ("session live on task X - adding manual
 *      time may overlap") and require explicit "Continue anyway" before the
 *      form. The auto-tracked session keeps running; this just makes the
 *      user conscious of the double-log.
 *   2. Form: description / start / end / billable. End time + duration are
 *      derived from each other; both submit cleanly.
 */
export function ManualTimeEntryDialog({ open, onOpenChange, taskId, onSaved }: Props) {
    const { t } = useTranslation();
    const [step, setStep] = useState<Step>('checking');
    const [running, setRunning] = useState<RunningEntry[]>([]);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [startTime, setStartTime] = useState('');
    const [endTime, setEndTime] = useState('');
    const [billable, setBillable] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!open) {
            setStep('checking');
            setRunning([]);
            setTitle('');
            setDescription('');
            setBillable(true);
            setErrors({});
            return;
        }

        const now = new Date();
        const oneHourAgo = new Date(now.getTime() - 60 * 60 * 1000);
        // Pre-fill with "1 hour ending now" - common shape for backfilled work.
        setStartTime(toLocalInput(oneHourAgo));
        setEndTime(toLocalInput(now));

        let cancelled = false;
        fetch('/time-entries/running', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (res) => {
                if (cancelled) return;
                if (!res.ok) {
                    setStep('form');
                    return;
                }
                const payload = await res.json();
                const entries: RunningEntry[] = payload.entries ?? [];
                setRunning(entries);
                setStep(entries.length > 0 ? 'conflict_warning' : 'form');
            })
            .catch(() => {
                if (!cancelled) setStep('form');
            });

        return () => {
            cancelled = true;
        };
    }, [open]);

    const submit = async () => {
        setSubmitting(true);
        setErrors({});
        try {
            const res = await fetch(`/tasks/${taskId}/time-entries`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    title: title.trim() || null,
                    description: description || null,
                    // Locale-aware <input type="datetime-local"> values come
                    // through as "2026-05-21T10:00" (no TZ) - Laravel's
                    // Carbon::parse handles either form.
                    start_time: localInputToIso(startTime),
                    end_time: localInputToIso(endTime),
                    billable,
                }),
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({ message: t('Failed to save entry') }));
                if (body?.errors) {
                    const flat: Record<string, string> = {};
                    for (const [key, value] of Object.entries(body.errors as Record<string, string[]>)) {
                        flat[key] = value[0] ?? '';
                    }
                    setErrors(flat);
                } else {
                    toast.error(body.message ?? t('Failed to save entry'));
                }
                return;
            }
            toast.success(t('Time entry saved'));
            onOpenChange(false);
            onSaved?.();
        } catch {
            toast.error(t('Failed to save entry'));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Log time')}</DialogTitle>
                </DialogHeader>

                {step === 'checking' && <p>{t('Checking for active sessions…')}</p>}

                {step === 'conflict_warning' && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--spacing-3)' }}>
                        <p>{t('A time entry is currently running. Adding a manual entry may overlap with it:')}</p>
                        <ul style={{ margin: 0, paddingLeft: 'var(--spacing-4)' }}>
                            {running.map((entry) => (
                                <li key={entry.id} style={{ fontSize: '0.875rem' }}>
                                    <strong>{entry.task?.name ?? entry.description ?? t('(no task)')}</strong>
                                    {' · '}
                                    {entry.source === 'terminal_session' ? t('Session') : entry.source === 'manual' ? t('Manual') : 'Clockify'}
                                    {entry.start_time && ` · ${t('Started')} ${new Date(entry.start_time).toLocaleString()}`}
                                </li>
                            ))}
                        </ul>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => onOpenChange(false)}>
                                {t('Cancel')}
                            </Button>
                            <Button onClick={() => setStep('form')}>{t('Continue anyway')}</Button>
                        </DialogFooter>
                    </div>
                )}

                {step === 'form' && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            void submit();
                        }}
                        style={{ display: 'flex', flexDirection: 'column', gap: 'var(--spacing-4)' }}
                    >
                        <div>
                            <FormLabel htmlFor="time-title">{t('Title')}</FormLabel>
                            <FormInput
                                id="time-title"
                                type="text"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                maxLength={255}
                                placeholder={t('Short label shown in the list')}
                                error={errors.title}
                            />
                            <FormMessage error={errors.title} />
                        </div>
                        <div>
                            <FormLabel htmlFor="time-description">{t('Description')}</FormLabel>
                            <Textarea
                                id="time-description"
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                maxLength={2000}
                                rows={4}
                                placeholder={t('Details shown when the entry is opened')}
                                aria-invalid={errors.description ? true : undefined}
                                style={{ resize: 'vertical' }}
                            />
                            <FormMessage error={errors.description} />
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--spacing-3)' }}>
                            <div>
                                <FormLabel htmlFor="time-start">{t('Start time')}</FormLabel>
                                <FormInput
                                    id="time-start"
                                    type="datetime-local"
                                    value={startTime}
                                    onChange={(e) => setStartTime(e.target.value)}
                                    required
                                    error={errors.start_time}
                                />
                                <FormMessage error={errors.start_time} />
                            </div>
                            <div>
                                <FormLabel htmlFor="time-end">{t('End time')}</FormLabel>
                                <FormInput
                                    id="time-end"
                                    type="datetime-local"
                                    value={endTime}
                                    onChange={(e) => setEndTime(e.target.value)}
                                    required
                                    error={errors.end_time}
                                />
                                <FormMessage error={errors.end_time} />
                            </div>
                        </div>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--spacing-2)' }}>
                            <input type="checkbox" checked={billable} onChange={(e) => setBillable(e.target.checked)} />
                            {t('Billable')}
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                                {t('Cancel')}
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting ? t('Saving...') : t('Save')}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function toLocalInput(date: Date): string {
    // `<input type="datetime-local">` expects YYYY-MM-DDTHH:mm (no seconds, no TZ).
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function localInputToIso(value: string): string {
    // Treat the local input as local time, convert to ISO with offset so the
    // server stores the absolute instant correctly.
    if (!value) return value;
    const d = new Date(value);
    return d.toISOString();
}
