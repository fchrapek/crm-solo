import { HeaderAction } from '@/components/solo/client-header';
import { Icon } from '@/components/ui/icon';
import { shortDate } from '@/lib/dates';
import { useTranslation } from 'react-i18next';

export type ReportStatus = 'draft' | 'finalized' | 'sent';

export type Confirm = 'regenerate' | 'finalize' | 'sent' | 'reopen' | 'delete' | null;

export function statusLabelKey(status: ReportStatus): string {
    switch (status) {
        case 'draft':
            return 'Draft';
        case 'finalized':
            return 'Finalized';
        case 'sent':
            return 'Sent';
    }
}

/** The one forward step: Oznacz jako gotowy on a draft, Oznacz jako wysłany when it is ready; a header action on desktop and in the phone menu. */
export function StatusAction({ status, onConfirm }: { status: ReportStatus; onConfirm: (confirm: Confirm) => void }) {
    const { t } = useTranslation();

    if (status === 'draft') {
        return (
            <HeaderAction icon={<Icon name="done" size={16} />} onClick={() => onConfirm('finalize')}>
                {t('Mark as ready')}
            </HeaderAction>
        );
    }

    return status === 'finalized' ? (
        <HeaderAction icon={<Icon name="send" size={16} />} onClick={() => onConfirm('sent')}>
            {t('Mark as sent')}
        </HeaderAction>
    ) : null;
}

export function statusLine(
    report: { status: ReportStatus; sent_on: string | null; finalized_on: string | null; generated_on: string | null },
    locale: string,
    t: (key: string, options?: Record<string, unknown>) => string,
): string {
    if (report.status === 'sent' && report.sent_on !== null) {
        return t('ledger_report_sent', { date: shortDate(report.sent_on, locale) });
    }
    const since = report.status === 'finalized' ? report.finalized_on : report.generated_on;

    return since !== null
        ? t('{{status}} since {{date}}', { status: t(statusLabelKey(report.status)), date: shortDate(since, locale) })
        : t(statusLabelKey(report.status));
}
