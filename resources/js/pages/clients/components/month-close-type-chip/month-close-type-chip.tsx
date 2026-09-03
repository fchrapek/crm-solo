import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { FormLabel } from '@/components/form';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { monthCloseType as monthCloseTypeRoute, reportMode as reportModeRoute } from '@/routes/clients';
import { MonthCloseType, ReportMode } from '@/types';

import styles from './month-close-type-chip.module.css';

const TYPE_OPTIONS: { value: MonthCloseType; labelKey: string; tone: string }[] = [
    { value: 'maintenance', labelKey: 'Maintenance site', tone: 'green' },
    { value: 'gig', labelKey: 'Agency gig', tone: 'blue' },
    { value: null, labelKey: 'Not in monthly close', tone: 'grey' },
];

const MODE_OPTIONS: { value: ReportMode; labelKey: string }[] = [
    { value: 'report', labelKey: 'Full report' },
    { value: 'summary_email', labelKey: 'Summary email' },
    { value: 'none', labelKey: 'No deliverable' },
];

function typeOptionFor(value: MonthCloseType) {
    return TYPE_OPTIONS.find((option) => option.value === value) ?? TYPE_OPTIONS[2];
}

/**
 * Inert header pill - the selects live in the Ustawienia Month Close card.
 */
export function MonthClosePill({ value }: { value: MonthCloseType }) {
    const { t } = useTranslation();
    const current = typeOptionFor(value);

    return (
        <span className={styles.pill}>
            <span className={styles.dot} data-tone={current.tone} />
            {t(current.labelKey)}
        </span>
    );
}

interface SettingsProps {
    clientId: number;
    value: MonthCloseType;
    reportMode: ReportMode;
    includeInMonthClose: boolean;
    onIncludeChange: (checked: boolean) => void;
}

/**
 * The month-close wiring that used to hide behind the header chip: cohort
 * type + deliverable (each PATCHes its endpoint on change) and the inclusion
 * checkbox (part of the surrounding Ustawienia form, saved with it).
 */
export function MonthCloseSettings({ clientId, value, reportMode, includeInMonthClose, onIncludeChange }: SettingsProps) {
    const { t } = useTranslation();
    const [submitting, setSubmitting] = useState(false);

    const pickType = (raw: string) => {
        const next = (raw === 'none' ? null : raw) as MonthCloseType;
        if (next === value || submitting) return;
        setSubmitting(true);
        router.patch(monthCloseTypeRoute(clientId).url, { month_close_type: next }, { preserveScroll: true, onFinish: () => setSubmitting(false) });
    };

    const pickMode = (raw: string) => {
        const next = raw as Exclude<ReportMode, null>;
        if (next === reportMode || submitting) return;
        setSubmitting(true);
        router.patch(reportModeRoute(clientId).url, { report_mode: next }, { preserveScroll: true, onFinish: () => setSubmitting(false) });
    };

    return (
        <>
            <div className="formGrid">
                <div>
                    <FormLabel htmlFor="month_close_type">{t('Monthly close')}</FormLabel>
                    <Select value={value ?? 'none'} onValueChange={pickType} disabled={submitting}>
                        <SelectTrigger id="month_close_type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {TYPE_OPTIONS.map((option) => (
                                <SelectItem key={String(option.value)} value={option.value ?? 'none'}>
                                    {t(option.labelKey)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {value !== null && (
                    <div>
                        <FormLabel htmlFor="report_mode">{t('Deliverable')}</FormLabel>
                        <Select value={reportMode ?? 'none'} onValueChange={pickMode} disabled={submitting}>
                            <SelectTrigger id="report_mode">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {MODE_OPTIONS.map((option) => (
                                    <SelectItem key={option.value ?? 'none'} value={option.value ?? 'none'}>
                                        {t(option.labelKey)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
            </div>

            <label className={styles.includeRow}>
                <Checkbox checked={includeInMonthClose} onCheckedChange={(checked) => onIncludeChange(checked === true)} />
                <span>{t('Include in the monthly close')}</span>
            </label>
        </>
    );
}
