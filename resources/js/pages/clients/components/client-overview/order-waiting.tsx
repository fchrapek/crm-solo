import { SoloButton, SoloButtonLink, soloButtonClass } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { shortDate } from '@/lib/dates';
import clients from '@/routes/clients';
import tasks from '@/routes/tasks';
import { useTranslation } from 'react-i18next';
import type { OrderWaitingInvoice, OrderWaitingTask } from './types';

/** An order client's rows of Waiting for you: the next dated task with its timer, the unpaid invoice. */
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
                <Row
                    title={t('order_waiting_task', { task: task.name, date: shortDate(task.due, locale) })}
                    href={tasks.show(task.task_id)}
                    action={
                        task.running_id !== null ? (
                            <SoloButton variant="stop" size="control" disabled={pending} onClick={() => stop(task.running_id!)}>
                                {t('Stop')}
                            </SoloButton>
                        ) : (
                            <SoloButton variant="secondary" size="control" disabled={pending} onClick={() => start(task.task_id)}>
                                {t('Start')}
                            </SoloButton>
                        )
                    }
                />
            )}
            {invoice && (
                <Row
                    title={invoiceTitle(invoice, locale, t)}
                    action={
                        invoice.external_url !== null ? (
                            <a
                                className={soloButtonClass({ variant: 'secondary', size: 'control' })}
                                href={invoice.external_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {t('Open')}
                            </a>
                        ) : (
                            <SoloButtonLink href={clients.show({ client: clientId, tab: 'billing' }).url} variant="secondary" size="control">
                                {t('Open')}
                            </SoloButtonLink>
                        )
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
