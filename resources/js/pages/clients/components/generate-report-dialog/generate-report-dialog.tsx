import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

import styles from './generate-report-dialog.module.css';

export interface ComposerOption {
    key: string;
    label: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientId: number;
    composers: ComposerOption[];
}

export function GenerateReportDialog({ open, onOpenChange, clientId, composers }: Props) {
    const { t } = useTranslation();

    const defaults = useMemo(() => previousMonthRange(), []);
    const [periodType, setPeriodType] = useState<'month' | 'week'>('month');
    const [periodStart, setPeriodStart] = useState(defaults.start);
    const [periodEnd, setPeriodEnd] = useState(defaults.end);
    const [composerKey, setComposerKey] = useState(composers[0]?.key ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            const range = periodType === 'month' ? previousMonthRange() : previousWeekRange();
            setPeriodStart(range.start);
            setPeriodEnd(range.end);
            setErrors({});
        }
    }, [open, periodType]);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        setProcessing(true);

        router.post(
            `/clients/${clientId}/reports`,
            {
                period_type: periodType,
                period_start: periodStart,
                period_end: periodEnd,
                composer_key: composerKey || null,
            },
            {
                onError: (errs) => {
                    setErrors(errs);
                    setProcessing(false);
                },
                onSuccess: () => {
                    setProcessing(false);
                    onOpenChange(false);
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Generate report')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className={styles.form}>
                    <div>
                        <FormLabel htmlFor="report-period-type">{t('Period')}</FormLabel>
                        <Select value={periodType} onValueChange={(v) => setPeriodType(v as 'month' | 'week')} disabled={processing}>
                            <SelectTrigger id="report-period-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="month">{t('Monthly')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className={styles.dateRow}>
                        <div>
                            <FormLabel htmlFor="report-start" error={errors.period_start}>
                                {t('From')}
                            </FormLabel>
                            <FormInput
                                id="report-start"
                                type="date"
                                value={periodStart}
                                onChange={(e) => setPeriodStart(e.target.value)}
                                required
                                disabled={processing}
                                error={errors.period_start}
                            />
                            <FormMessage error={errors.period_start} />
                        </div>
                        <div>
                            <FormLabel htmlFor="report-end" error={errors.period_end}>
                                {t('To')}
                            </FormLabel>
                            <FormInput
                                id="report-end"
                                type="date"
                                value={periodEnd}
                                onChange={(e) => setPeriodEnd(e.target.value)}
                                required
                                disabled={processing}
                                error={errors.period_end}
                            />
                            <FormMessage error={errors.period_end} />
                        </div>
                    </div>

                    {composers.length > 1 && (
                        <div>
                            <FormLabel htmlFor="report-composer">{t('Composer')}</FormLabel>
                            <Select value={composerKey} onValueChange={setComposerKey} disabled={processing}>
                                <SelectTrigger id="report-composer">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {composers.map((c) => (
                                        <SelectItem key={c.key} value={c.key}>
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    <p className={styles.hint}>
                        {t('A draft will be generated from time entries and completed tasks in this window. You can edit it before finalizing.')}
                    </p>

                    <div className={styles.actions}>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)} disabled={processing}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? t('Generating…') : t('Generate draft')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function previousMonthRange(): { start: string; end: string } {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    const end = new Date(now.getFullYear(), now.getMonth(), 0);
    return { start: toIsoDate(start), end: toIsoDate(end) };
}

function previousWeekRange(): { start: string; end: string } {
    const now = new Date();
    const dayOfWeek = now.getDay() || 7; // 1 (Mon) … 7 (Sun)
    const lastSunday = new Date(now);
    lastSunday.setDate(now.getDate() - dayOfWeek);
    const lastMonday = new Date(lastSunday);
    lastMonday.setDate(lastSunday.getDate() - 6);
    return { start: toIsoDate(lastMonday), end: toIsoDate(lastSunday) };
}

function toIsoDate(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}
