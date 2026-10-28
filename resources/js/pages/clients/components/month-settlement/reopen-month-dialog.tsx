import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { capitalize, monthGenitive, monthName } from '@/lib/dates';
import clientMonths from '@/routes/client-months';
import clientReports from '@/routes/client-reports';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, localeOf } from '../client-list/format';
import styles from './month-settlement.module.css';
import type { ReopenTarget } from './types';

/** Figma D3 F6 (phones 347:4931, blocked 347:4982); reopens the closure the row showed, by id, so a late retry never reopens a newer close. */
export function ReopenMonthDialog({
    clientId,
    clientName,
    target,
    open,
    onOpenChange,
}: {
    clientId: number;
    clientName: string;
    target: ReopenTarget;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [processing, setProcessing] = useState(false);
    const month = monthName(target.period, locale);
    const blocked = !useSoloWide() && target.refusal !== null;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (target.refusal !== null) {
            return;
        }
        setProcessing(true);
        router.delete(clientMonths.reopen([clientId, target.period]).url, {
            data: { closure_id: target.closure_id },
            preserveScroll: true,
            // The phone flow returns to the Bank godzin sheet, which lives in the page's state.
            preserveState: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => setProcessing(false),
        });
    }

    if (blocked) {
        const blocker = target.blocker;

        return (
            <ResponsiveDialog
                open={open}
                onOpenChange={onOpenChange}
                title={t('reopen_blocked_title', { month: monthGenitive(target.period, locale), Month: capitalize(month) })}
                onSubmit={submit}
                actions={(size) => (
                    <>
                        <SoloButton variant="pill" onClick={() => onOpenChange(false)}>
                            {t('Cancel')}
                        </SoloButton>
                        {blocker !== null && (
                            <SoloButtonLink variant="ink" size={size} href={clientReports.edit([clientId, blocker.report_id]).url}>
                                {t('Report for {{month}}', { month: monthName(blocker.period, locale) })}
                            </SoloButtonLink>
                        )}
                    </>
                )}
            >
                <p className={styles.blockedRefusal} role="alert">
                    {target.refusal}
                </p>
                <p className={styles.info}>{t('reopen_blocked_info', { Month: capitalize(month) })}</p>
            </ResponsiveDialog>
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Reopen {{month}}?', { month })}
            description={t('reopen_month_lead', { client: clientName, Month: capitalize(month) })}
            onSubmit={submit}
            footerClassName={styles.actions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing || target.refusal !== null}>
                        {t('Reopen the month')}
                    </SoloButton>
                </>
            )}
        >
            {target.refusal !== null ? (
                <p className={styles.refusal} role="alert">
                    {target.refusal}
                </p>
            ) : (
                <p className={styles.info}>
                    {t('The frozen figures ({{used}} h, balance {{closing}} h) are recalculated at the next close.', {
                        used: hoursNumber(target.used, locale),
                        closing: hoursNumber(target.closing, locale, true),
                    })}
                </p>
            )}
        </ResponsiveDialog>
    );
}
