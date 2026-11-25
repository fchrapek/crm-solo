import { Chip, type ChipStatus } from '@/components/solo/chip';
import { KvRow } from '@/components/solo/kv-row';
import { Notice } from '@/components/solo/notice';
import { SoloButton, soloButtonClass } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Row } from '@/components/solo/row';
import { TileRows } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { InfoHint } from '@/components/ui/info-hint';
import { shortDate } from '@/lib/dates';
import { localeOf } from '@/lib/hours-text';
import clients from '@/routes/clients';
import messages from '@/routes/messages';
import { Link, router } from '@inertiajs/react';
import { type FormEvent, Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './client-fields.module.css';
import type { MessageRow, MessageStatus } from './types';

export const STATUS_LABEL: Record<MessageStatus, string> = {
    pending: 'sms_scheduled',
    sending: 'sms_sending',
    sent: 'sms_sent',
    simulated: 'sms_simulated',
    failed: 'sms_failed',
    cancelled: 'sms_cancelled',
    skipped: 'sms_skipped',
    superseded: 'sms_replaced',
};

const SKIP_REASON: Record<string, string> = {
    expired: 'sms_expired',
    opted_out: 'sms_opted_out',
    no_phone: 'sms_no_phone',
    client_archived: 'sms_client_archived',
    rule_removed: 'sms_rule_removed',
    field_archived: 'sms_field_archived',
    stale: 'sms_stale',
};

/** "pominięta: brak telefonu"; a missing placeholder reads as a missing value. */
export function useStatusLabel() {
    const { t } = useTranslation();

    return (message: Pick<MessageRow, 'status' | 'error' | 'held'>) => {
        if (message.held) return t('sms_held');
        const status = t(STATUS_LABEL[message.status]);
        if (message.status !== 'skipped' || message.error === null) return status;
        const reason = SKIP_REASON[message.error] ?? (message.error.startsWith('missing ') ? 'sms_field_empty' : null);

        return reason === null ? status : `${status}: ${t(reason)}`;
    };
}

/** Why a send failed, for the hint beside failed. */
export function useFailureReason() {
    const { t } = useTranslation();

    return (message: Pick<MessageRow, 'error' | 'may_have_gone_out'>) =>
        message.may_have_gone_out
            ? t('SMSAPI did not answer. The text may already have reached the client, so check before sending it again.')
            : t('The provider refused the text and nothing went out. Reason: {{error}}', { error: message.error ?? '-' });
}

/** Sends a failed text again: at once after a refusal; after no answer it asks first, since the client may already have it. */
export function ResendAction({ message, className }: { message: Pick<MessageRow, 'id' | 'may_have_gone_out'>; className?: string }) {
    const { t } = useTranslation();
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);
    const send = () =>
        router.post(messages.resend(message.id).url, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <>
            <button
                type="button"
                className={className ?? soloButtonClass({ variant: 'quiet', size: 'control' })}
                disabled={busy}
                onClick={() => (message.may_have_gone_out ? setAsking(true) : send())}
            >
                {t('Send again')}
            </button>
            {message.may_have_gone_out && (
                <ConfirmDialog
                    open={asking}
                    onOpenChange={setAsking}
                    title={t('Send the SMS again?')}
                    description={t(
                        'There was no answer when it went out, so the client may already have it. Send it again only if you know it did not arrive.',
                    )}
                    confirmLabel={t('Send again')}
                    onConfirm={() => {
                        setAsking(false);
                        send();
                    }}
                />
            )}
        </>
    );
}

const CHIP: Record<MessageStatus, ChipStatus> = {
    pending: 'waiting',
    sending: 'waiting',
    sent: 'done',
    simulated: 'done',
    failed: 'agent',
    cancelled: 'open',
    skipped: 'open',
    superseded: 'open',
};

/**
 * Figma: SMS queue, the row (1094:43607). The time, the text that opens the details, the client when the list
 * spans clients, the status as a chip. A failed text has the ink dot and its reason on a line of its own: there is
 * no error red. With manage the row carries its one action: Cancel on a scheduled text, Send again on a failed one.
 */
export function MessageList({
    rows,
    withClient = false,
    withDay = true,
    stacked = false,
    manage = true,
}: {
    rows: MessageRow[];
    withClient?: boolean;
    withDay?: boolean;
    /** The row is in a narrow column: the text wraps. */
    stacked?: boolean;
    /** False for a member, who may read the queue but not change it. */
    manage?: boolean;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const label = useStatusLabel();
    const reason = useFailureReason();
    const [open, setOpen] = useState<MessageRow | null>(null);

    return (
        <>
            <TileRows>
                {rows.map((row, index) => (
                    <Fragment key={row.id}>
                        <Row
                            rule={index > 0}
                            wrap={stacked}
                            stack={!stacked}
                            time={withDay ? `${shortDate(row.day, locale)}, ${row.time}` : row.time}
                            title={
                                <button
                                    type="button"
                                    className={styles.body}
                                    data-status={row.held ? 'held' : row.status}
                                    onClick={() => setOpen(row)}
                                >
                                    {row.body ?? row.template ?? t('Text message')}
                                </button>
                            }
                            wideMeta={withClient ? row.client : undefined}
                            chip={
                                <Chip status={row.held ? 'open' : CHIP[row.status]}>
                                    {label(row)}
                                    {row.held && (
                                        <>
                                            {' '}
                                            <InfoHint>
                                                {t(
                                                    'SMS to this client are off. It goes out only if they are turned back on before its sending time.',
                                                )}
                                            </InfoHint>
                                        </>
                                    )}
                                </Chip>
                            }
                            action={
                                !manage ? undefined : row.status === 'pending' ? (
                                    <SoloButton variant="quiet" size="control" onClick={() => setOpen(row)}>
                                        {t('Cancel')}
                                    </SoloButton>
                                ) : row.status === 'failed' ? (
                                    <ResendAction message={row} />
                                ) : undefined
                            }
                        />
                        {row.status === 'failed' && (
                            <li className={styles.reason}>
                                <Notice kind="warn">{reason(row)}</Notice>
                            </li>
                        )}
                    </Fragment>
                ))}
            </TileRows>
            <MessageDialog message={open} manage={manage} onClose={() => setOpen(null)} />
        </>
    );
}

/** Figma: Scheduled SMS (sheet with Cancel), 607:6040. A failed one is sent again from here (the sheet is the confirmation) or cancelled. */
function MessageDialog({ message, manage, onClose }: { message: MessageRow | null; manage: boolean; onClose: () => void }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const label = useStatusLabel();
    const reason = useFailureReason();
    const [shown, setShown] = useState(message);
    if (message !== null && message !== shown) setShown(message);
    const current = message ?? shown;
    const pending = manage && current?.status === 'pending';
    const failed = manage && current?.status === 'failed';

    const cancel = () => current !== null && router.post(messages.cancel(current.id).url, {}, { preserveScroll: true, onSuccess: () => onClose() });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (current === null || !(pending || failed)) return onClose();
        if (pending) return cancel();
        router.post(messages.resend(current.id).url, {}, { preserveScroll: true, onSuccess: () => onClose() });
    }

    return (
        <ResponsiveDialog
            open={message !== null}
            onOpenChange={(value) => !value && onClose()}
            title={current === null ? '' : `${t('Text message')} · ${label(current)}`}
            onSubmit={submit}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={onClose}>
                        {t('Close')}
                    </SoloButton>
                    {pending && (
                        <SoloButton type="submit" variant="ink" size={size}>
                            {t('Cancel the SMS')}
                        </SoloButton>
                    )}
                    {failed && (
                        <>
                            <SoloButton variant="pill" onClick={cancel}>
                                {t('Cancel the SMS')}
                            </SoloButton>
                            <SoloButton type="submit" variant="ink" size={size}>
                                {t('Send again')}
                            </SoloButton>
                        </>
                    )}
                </>
            )}
        >
            {current !== null && (
                <>
                    <dl className={styles.details}>
                        {current.client !== null && (
                            <KvRow label={t('Client name')} layout="split">
                                <Link href={clients.show({ client: current.client_id, tab: 'journal' }).url}>{current.client}</Link>
                            </KvRow>
                        )}
                        <KvRow label={t('When')} layout="split">
                            {shortDate(current.day, locale)}, {current.time}
                        </KvRow>
                        <KvRow label={t('To')} layout="split" tone={current.to ? undefined : 'muted'}>
                            {current.to ?? t('No phone')}
                        </KvRow>
                        {current.template !== null && (
                            <KvRow label={t('Template')} layout="split">
                                {current.template}
                            </KvRow>
                        )}
                        {current.status === 'pending' && (
                            <KvRow label={t('Valid until')} layout="split">
                                {shortDate(current.expires.slice(0, 10), locale)}, {current.expires.slice(11)}
                            </KvRow>
                        )}
                        {current.status === 'failed' && (
                            <KvRow label={t('Reason')} layout="column">
                                {reason(current)}
                            </KvRow>
                        )}
                        {current.resends > 0 && (
                            <KvRow label={t('Sent again')} layout="split">
                                {t('times_count', { count: current.resends })}
                            </KvRow>
                        )}
                        {current.resends > 0 && current.previous_error !== null && (
                            <KvRow label={t('Earlier failure')} layout="column" tone="muted">
                                {current.previous_error}
                            </KvRow>
                        )}
                    </dl>
                    {current.body !== null && <p className={styles.fullBody}>{current.body}</p>}
                </>
            )}
        </ResponsiveDialog>
    );
}
