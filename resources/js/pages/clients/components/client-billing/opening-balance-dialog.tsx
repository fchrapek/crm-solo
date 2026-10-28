import { fieldControl, SoloField, SoloInput } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { monthName } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { localeOf } from '../client-list/format';
import styles from './client-billing.module.css';
import { periodLabel } from './format';
import type { LedgerRow } from './types';

/** Sets the balance the newest report opens with (a bottom sheet on phones); the server rewrites that report's billing summary from it. */
export function OpeningBalanceDialog({
    clientId,
    reportId,
    row,
    value: saved,
    version,
    open,
    onOpenChange,
}: {
    clientId: number;
    reportId: number;
    row: LedgerRow;
    value: number | null;
    version: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const period = row.whole_month ? `${monthName(row.period, locale)} ${row.period.slice(0, 4)}` : periodLabel(row, undefined, locale);

    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setValue(saved !== null ? saved.toLocaleString(locale, { maximumFractionDigits: 2, useGrouping: false }) : '');
            setError(undefined);
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const hours = value.trim() === '' ? null : Number(value.replace(/\s/g, '').replace(',', '.').replace('\u2212', '-'));
        if (hours !== null && !Number.isFinite(hours)) {
            setError(t('Enter a number of hours.'));
            return;
        }
        setProcessing(true);
        router.patch(
            clientReports.openingBalance([clientId, reportId]).url,
            { opening_balance_hours: hours, version },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) => setError(errors.opening_balance_hours),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Change opening balance')}
            fields
            description={t('The report for {{period}} opens with this balance, and its billing summary is recalculated from it.', { period })}
            onSubmit={submit}
            footerClassName={styles.dialogActions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Opening balance (h)')} htmlFor="opening-balance" error={error}>
                <SoloInput
                    {...fieldControl('opening-balance', error)}
                    inputMode="decimal"
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    placeholder="0"
                    disabled={processing}
                    autoFocus
                />
            </SoloField>
            <p className={styles.hint}>{t('A negative figure is hours the client owes the bank. Leave it empty to clear the balance.')}</p>
        </ResponsiveDialog>
    );
}
