import { SoloButton } from '@/components/solo/primitives';
import { WaitingRow } from '@/components/solo/waiting-row';
import { Icon } from '@/components/ui/icon';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { shortDate } from '@/lib/dates';
import clients from '@/routes/clients';
import tasks from '@/routes/tasks';
import { useTranslation } from 'react-i18next';
import type { OrderWaitingInvoice, OrderWaitingTask } from './types';

/** Figma D8.3 Czeka na ciebie rows. */
export function OrderWaitingRows({
    clientId,
    task,
    invoice,
    locale,
}: {
    clientId: number;
    task: OrderWaitingTask | null;
    invoice: OrderWaitingInvoice | null;
    locale: string;
}) {
    const { t } = useTranslation();
    const { pending, start, stop } = useTimerActions();

    return (
        <>
            {task && (
                <WaitingRow
                    title={t('order_waiting_task', { task: task.name, date: shortDate(task.due, locale) })}
                    titleHref={tasks.show(task.task_id)}
                    action={
                        task.running_id !== null ? (
                            <SoloButton variant="text" size="sm" disabled={pending} onClick={() => stop(task.running_id!)}>
                                {t('Stop')}
                            </SoloButton>
                        ) : (
                            <SoloButton variant="text" size="sm" disabled={pending} onClick={() => start(task.task_id)}>
                                {t('Start')}
                            </SoloButton>
                        )
                    }
                    phoneAction={
                        task.running_id !== null ? (
                            <SoloButton variant="stop" size="md" disabled={pending} onClick={() => stop(task.running_id!)}>
                                {t('Stop')}
                            </SoloButton>
                        ) : (
                            <SoloButton variant="ink" size="md" disabled={pending} onClick={() => start(task.task_id)}>
                                <Icon name="play" size={16} filled />
                                {t('Start')}
                            </SoloButton>
                        )
                    }
                />
            )}
            {invoice && (
                <WaitingRow
                    title={invoiceTitle(invoice, locale, t)}
                    link={
                        invoice.external_url !== null
                            ? { href: invoice.external_url, label: t('Open'), external: true }
                            : { href: clients.show({ client: clientId, tab: 'rozliczenia' }).url, label: t('Open') }
                    }
                />
            )}
        </>
    );
}

function invoiceTitle(invoice: OrderWaitingInvoice, locale: string, t: ReturnType<typeof useTranslation>['t']): string {
    const number = invoice.number ?? t('No number');

    if (invoice.overdue && invoice.payment_date !== null) {
        return t('order_waiting_invoice_overdue', { number, date: shortDate(invoice.payment_date, locale) });
    }

    return invoice.payment_date !== null
        ? t('order_waiting_invoice_due', { number, date: shortDate(invoice.payment_date, locale) })
        : t('order_waiting_invoice', { number });
}
