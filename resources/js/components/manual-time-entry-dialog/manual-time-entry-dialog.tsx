import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Checkbox } from '@/components/ui/checkbox';

import styles from './manual-time-entry-dialog.module.css';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

export interface RunningEntry {
    id: number;
    description: string | null;
    start_time: string | null;
    source: 'clockify' | 'terminal_session' | 'manual';
    task: { id: number; name: string } | null;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The task the time goes on; its name and the client's short name make the subtitle. */
    task: { id: number; name: string; client?: string | null };
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
/** Figma: Zaloguj czas, dialog 794:20596, sheet 794:57932; the running-timer step 800:21650, 800:21981. */
export function ManualTimeEntryDialog({ open, onOpenChange, task, onSaved }: Props) {
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
    // A second submit can arrive before the disabled Save renders; each one would log the time again.
    const inFlight = useRef(false);

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
        if (inFlight.current) return;
        inFlight.current = true;
        setSubmitting(true);
        setErrors({});
        try {
            const res = await fetch(`/tasks/${task.id}/time-entries`, {
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
            inFlight.current = false;
            setSubmitting(false);
        }
    };

    const subtitle = [task.name, task.client].filter(Boolean).join(' · ');
    const cancel = (
        <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={submitting}>
            {t('Cancel')}
        </SoloButton>
    );

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Log time')}
            description={subtitle}
            onSubmit={(e) => {
                e.preventDefault();
                if (step === 'conflict_warning') setStep('form');
                if (step === 'form') void submit();
            }}
            sheetAction={step === 'form' ? 'save' : 'close'}
            saving={submitting}
            fields={step === 'form'}
            footerClassName={styles.actions}
            actions={(size) => (
                <>
                    {cancel}
                    {step === 'conflict_warning' && (
                        <SoloButton variant="ink" size={size} onClick={() => setStep('form')}>
                            {t('Continue anyway')}
                        </SoloButton>
                    )}
                    {step === 'form' && (
                        <SoloButton type="submit" variant="ink" size={size} disabled={submitting}>
                            {submitting ? t('Saving…') : t('Save')}
                        </SoloButton>
                    )}
                </>
            )}
        >
            {step === 'checking' && (
                <p className={styles.checking} role="status">
                    {t('Checking for active sessions…')}
                </p>
            )}

            {step === 'conflict_warning' && <RunningWarning running={running} />}

            {step === 'form' && (
                <>
                    <SoloField label={t('Title')} htmlFor="time-title" error={errors.title}>
                        <SoloInput
                            {...fieldControl('time-title', errors.title)}
                            type="text"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            maxLength={255}
                            placeholder={t('Short label shown in the list')}
                            autoFocus
                        />
                    </SoloField>
                    <SoloField label={t('Description')} htmlFor="time-description" error={errors.description}>
                        <SoloTextarea
                            {...fieldControl('time-description', errors.description)}
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            maxLength={2000}
                            rows={3}
                            placeholder={t('Details shown when the entry is opened')}
                        />
                    </SoloField>
                    <SoloFieldRow>
                        <SoloField label={t('Start time')} htmlFor="time-start" error={errors.start_time}>
                            <SoloInput
                                {...fieldControl('time-start', errors.start_time)}
                                type="datetime-local"
                                value={startTime}
                                onChange={(e) => setStartTime(e.target.value)}
                                required
                            />
                        </SoloField>
                        <SoloField label={t('End time')} htmlFor="time-end" error={errors.end_time}>
                            <SoloInput
                                {...fieldControl('time-end', errors.end_time)}
                                type="datetime-local"
                                value={endTime}
                                onChange={(e) => setEndTime(e.target.value)}
                                required
                            />
                        </SoloField>
                    </SoloFieldRow>
                    <label className={styles.billable}>
                        <Checkbox checked={billable} onCheckedChange={(checked) => setBillable(checked === true)} />
                        {t('Billable')}
                    </label>
                </>
            )}
        </ResponsiveDialog>
    );
}

/** Figma 800:21650: the warning and one row per running entry, its title and start time. */
export function RunningWarning({ running }: { running: RunningEntry[] }) {
    const { t, i18n } = useTranslation();
    const clock = new Intl.DateTimeFormat(i18n.language, { hour: '2-digit', minute: '2-digit' });

    return (
        <div className={styles.warning}>
            <p className={styles.warningText}>{t('A time entry is currently running. Adding a manual entry may overlap with it:')}</p>
            <ul className={styles.running}>
                {running.map((entry) => (
                    <li key={entry.id} className={styles.runningRow}>
                        <span className={styles.runningTitle}>{entry.task?.name ?? entry.description ?? t('(no task)')}</span>
                        {entry.start_time && (
                            <span className={styles.runningSince}>{t('since {{date}}', { date: clock.format(new Date(entry.start_time)) })}</span>
                        )}
                    </li>
                ))}
            </ul>
        </div>
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
