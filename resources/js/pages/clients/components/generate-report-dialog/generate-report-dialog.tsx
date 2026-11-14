import { fieldControl, SoloField, SoloFieldRow, SoloInput } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { TileNote } from '@/components/solo/tile';
import clientReports from '@/routes/client-reports';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

export interface ComposerOption {
    key: string;
    label: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientId: number;
    composers: ComposerOption[];
    /** The month the report is for (YYYY-MM): its first and last day fill the dates. */
    period: string;
}

/** The first and the last day of a month, as the report's dates. */
export function monthRange(period: string): { start: string; end: string } {
    const [year, month] = period.split('-').map(Number);
    const last = new Date(year, month, 0).getDate();

    return { start: `${period}-01`, end: `${period}-${String(last).padStart(2, '0')}` };
}

/** Figma: Generuj raport. A month's draft from its tasks and time; the server takes whole months only and says so on other dates. */
export function GenerateReportDialog({ open, onOpenChange, clientId, composers, period }: Props) {
    const { t } = useTranslation();
    const [range, setRange] = useState(() => monthRange(period));
    const [composerKey, setComposerKey] = useState(composers[0]?.key ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const [seen, setSeen] = useState({ open, period });
    if (seen.open !== open || seen.period !== period) {
        setSeen({ open, period });
        if (open) {
            setRange(monthRange(period));
            setErrors({});
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        router.post(
            clientReports.store(clientId).url,
            { period_type: 'month', period_start: range.start, period_end: range.end, composer_key: composerKey || null },
            {
                onError: setErrors,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Generate report')}
            onSubmit={submit}
            fields
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Generating…') : t('Generate draft')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Period')} htmlFor="report-period-type" error={errors.period_type}>
                <ResponsiveSelect
                    label={t('Period')}
                    value="month"
                    onValueChange={() => undefined}
                    options={[{ value: 'month', label: t('Monthly') }]}
                    disabled={processing}
                    control={fieldControl('report-period-type', errors.period_type)}
                />
            </SoloField>
            <SoloFieldRow>
                <SoloField label={t('From')} htmlFor="report-start" error={errors.period_start}>
                    <SoloInput
                        {...fieldControl('report-start', errors.period_start)}
                        type="date"
                        value={range.start}
                        onChange={(event) => setRange((current) => ({ ...current, start: event.target.value }))}
                        required
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('To')} htmlFor="report-end" error={errors.period_end}>
                    <SoloInput
                        {...fieldControl('report-end', errors.period_end)}
                        type="date"
                        value={range.end}
                        onChange={(event) => setRange((current) => ({ ...current, end: event.target.value }))}
                        required
                        disabled={processing}
                    />
                </SoloField>
            </SoloFieldRow>
            {composers.length > 1 && (
                <SoloField label={t('Composer')} htmlFor="report-composer">
                    <ResponsiveSelect
                        label={t('Composer')}
                        value={composerKey}
                        onValueChange={setComposerKey}
                        options={composers.map((composer) => ({ value: composer.key, label: composer.label }))}
                        disabled={processing}
                        control={fieldControl('report-composer')}
                    />
                </SoloField>
            )}
            <TileNote>{t('A draft will be generated from time entries and completed tasks in this window. You can edit it before finalizing.')}</TileNote>
        </ResponsiveDialog>
    );
}
