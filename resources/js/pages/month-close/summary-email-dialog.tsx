import { MarkdownView } from '@/components/markdown-view';
import { fieldControl, SoloField, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { Alert } from '@/components/ui/alert';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { InfoHint } from '@/components/ui/info-hint';
import { fullDate, monthName, shortDate } from '@/lib/dates';
import { jsonRequest } from '@/lib/json-request';
import clientSummaryEmails from '@/routes/client-summary-emails';
import { router } from '@inertiajs/react';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { localeOf } from '../clients/components/client-list/format';
import styles from './summary-email.module.css';

interface Stamp {
    day: string;
    time: string;
}

export interface SummaryEmail {
    id: number;
    client: string | null;
    period: string;
    status: 'draft' | 'queued' | 'sent' | 'test_sent' | 'failed' | 'unconfirmed';
    delivery: 'live' | 'test' | null;
    template: { key: string; name: string } | null;
    recipients: string[];
    attempt: number;
    bcc_owner: boolean;
    subject: string;
    body: string;
    drafted: Stamp | null;
    drafted_via: 'agent' | 'web' | null;
    changed: Stamp | null;
    sent: Stamp | null;
    error: string | null;
    step_ticked: boolean;
    tick_error: string | null;
    text_edited: boolean;
    editable: boolean;
    locked: boolean;
    lost: boolean;
}

interface Candidate {
    email: string;
    name: string;
    source: 'contact' | 'client';
}

interface Loaded {
    email: SummaryEmail | null;
    candidates: Candidate[];
    templates: { key: string; name: string; is_default: boolean }[];
    delivery: 'live' | 'test' | 'refused';
    owner_email: string;
}

type Confirm = 'send' | 'resend' | 'mark' | 'refresh' | { template: string } | null;

const POLL_MS = 2000;

/** The error a send lost before it reached the mailer carries (SummaryEmails::NOT_STARTED); the one error the screens translate. */
const NOT_STARTED = 'Sending did not start.';

const inFlight = (email: SummaryEmail | null | undefined) => email?.status === 'queued' && !email.lost;

/**
 * The attempt this dialog started has an outcome, however fast it came (even before the first poll):
 * the page behind shows the old state until it reloads.
 */
export function watchedSettled(watched: number | null, email: SummaryEmail | null | undefined): boolean {
    return watched !== null && email !== null && email !== undefined && email.attempt >= watched && !inFlight(email);
}

/**
 * Figma: Podsumowanie za … (993:29609, sheet 994:32245) and its states (993:33774 testowo, 993:34432 wysłano,
 * 993:35144 nie wysłano, 993:35802 niepotwierdzone). The owner's draft and send of a client's month-end email.
 */
export function SummaryEmailDialog({
    clientId,
    clientName,
    period,
    open,
    onClose,
    onSkip,
    onChanged,
}: {
    clientId: number;
    clientName: string;
    period: string;
    open: boolean;
    onClose: () => void;
    /** Skips the month-close step; offered while the email is a draft. */
    onSkip?: () => void;
    /** Reloads what the page behind shows about the email: called when a watched send settles and when the dialog closes. */
    onChanged?: () => void;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [loaded, setLoaded] = useState<Loaded | null>(null);
    const [form, setForm] = useState({ recipients: [] as string[], bcc_owner: true, subject: '', body: '' });
    const [tab, setTab] = useState<'text' | 'preview'>('text');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<'save' | 'send' | null>(null);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const route = { client: clientId, period };

    // A ref, so a caller's new callback each render never restarts the loading below.
    const changed = useRef(onChanged);
    const watched = useRef<number | null>(null);
    useEffect(() => {
        changed.current = onChanged;
    });

    const load = useCallback(async () => {
        const next = await jsonRequest<Loaded>(clientSummaryEmails.show(route).url, 'GET');
        setLoaded(next);
        if (watchedSettled(watched.current, next.email)) {
            watched.current = null;
            changed.current?.();
        }
        if (next.email !== null) {
            setForm({ recipients: next.email.recipients, bcc_owner: next.email.bcc_owner, subject: next.email.subject, body: next.email.body });
        }

        return next;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [clientId, period]);

    useEffect(() => {
        if (!open) return;
        setTab('text');
        setErrors({});
        load().catch(() => setLoaded(null));
    }, [open, load]);

    const email = loaded?.email ?? null;
    const sending = inFlight(email);
    const close = () => {
        onClose();
        changed.current?.();
    };
    useEffect(() => {
        if (!open || !sending) return;
        const timer = window.setInterval(() => load().catch(() => undefined), POLL_MS);

        return () => window.clearInterval(timer);
    }, [open, sending, load]);

    const visit = { preserveScroll: true, preserveState: true };
    const failed = (errs: Record<string, string>) => {
        setErrors(errs);
        setBusy(null);
    };

    function save(then?: () => void) {
        setBusy(then ? 'send' : 'save');
        router.put(clientSummaryEmails.update(route).url, form, {
            ...visit,
            onSuccess: () => (then ? then() : load().finally(() => setBusy(null))),
            onError: failed,
        });
    }

    function send(resendUnconfirmed = false) {
        setConfirm(null);
        watched.current = (email?.attempt ?? 0) + 1;
        const post = () =>
            router.post(
                clientSummaryEmails.send(route).url,
                { resend_unconfirmed: resendUnconfirmed },
                {
                    ...visit,
                    onSuccess: () => load().finally(() => setBusy(null)),
                    onError: failed,
                },
            );
        if (email?.editable) {
            save(post);
        } else {
            setBusy('send');
            post();
        }
    }

    function post(url: string, data: Record<string, string> = {}) {
        setConfirm(null);
        setBusy('save');
        router.post(url, data, { ...visit, onSuccess: () => load().finally(() => setBusy(null)), onError: failed });
    }

    const nameOf = (address: string) => {
        const candidate = loaded?.candidates.find((c) => c.email === address);
        return candidate === undefined ? address : candidate.source === 'client' ? t('Client email') : candidate.name;
    };
    const at = (stamp: Stamp | null) => (stamp === null ? { date: '', time: '' } : { date: shortDate(stamp.day, locale), time: stamp.time });
    const subtitle = (() => {
        if (email === null) return clientName;
        const changed = at(email.changed);
        const state = {
            draft:
                email.drafted === null
                    ? null
                    : [t('draft {{date}}', { date: shortDate(email.drafted.day, locale) }), email.drafted_via === 'agent' ? t('agent') : null]
                          .filter(Boolean)
                          .join(', '),
            test_sent: t('sent as a test {{date}}, {{time}}', changed),
            sent: t('sent {{date}}, {{time}}', at(email.sent)),
            failed: t('not sent {{date}}, {{time}}', changed),
            unconfirmed: t('send {{date}}, {{time}} without confirmation', changed),
            queued: email.lost ? t('send {{date}}, {{time}} without confirmation', changed) : t('Sending…'),
        }[email.status];

        return [clientName, state].filter(Boolean).join(' · ');
    })();

    const readOnly = email !== null && !email.editable;
    const edited = email !== null && (form.subject !== email.subject || form.body !== email.body);
    const unknown = email !== null && (email.status === 'unconfirmed' || (email.status === 'queued' && email.lost));
    const picked = form.recipients;
    const candidates = loaded?.candidates ?? [];
    const canSend = picked.length > 0 && form.subject.trim() !== '';
    const recipientList = new Intl.ListFormat(locale, { type: 'conjunction' }).format(picked.map((a) => `${nameOf(a)} (${a})`));
    const title = t('Summary for {{month}}', { month: monthName(period, locale) });
    const test = loaded?.delivery !== 'live';

    function toggle(address: string) {
        setForm((was) => ({
            ...was,
            recipients: was.recipients.includes(address) ? was.recipients.filter((a) => a !== address) : [...was.recipients, address],
        }));
    }

    const sendButton = (size: 'md' | 'touch', label: string, onClick: () => void) =>
        canSend ? (
            <SoloButton variant="ink" size={size} disabled={busy !== null} onClick={onClick}>
                {busy === 'send' ? t('Sending…') : label}
            </SoloButton>
        ) : (
            <InfoHint
                side="top"
                trigger={
                    <SoloButton variant="ink" size={size} aria-disabled="true">
                        {label}
                    </SoloButton>
                }
            >
                {picked.length === 0 ? t('Tick at least one recipient.') : t('Enter a subject.')}
            </InfoHint>
        );

    const actions = (size: 'md' | 'touch') => {
        if (email === null) {
            return (
                <SoloButton variant="pill" onClick={close}>
                    {t('Close')}
                </SoloButton>
            );
        }
        if (unknown) {
            return (
                <>
                    {email.delivery === 'live' && (
                        <SoloButton variant="text" size={size} data-start="" disabled={busy !== null} onClick={() => setConfirm('mark')}>
                            {t('Mark the email as sent')}
                        </SoloButton>
                    )}
                    <SoloButton variant="pill" onClick={close}>
                        {t('Close')}
                    </SoloButton>
                    {sendButton(size, t('Send again'), () => setConfirm('resend'))}
                </>
            );
        }
        if (readOnly) {
            return (
                <>
                    {email.status === 'sent' && email.delivery === 'live' && !email.step_ticked && (
                        <SoloButton
                            variant="text"
                            size={size}
                            data-start=""
                            disabled={busy !== null}
                            onClick={() => post(clientSummaryEmails.tick(route).url)}
                        >
                            {t('Tick the step')}
                        </SoloButton>
                    )}
                    <SoloButton variant="pill" onClick={close}>
                        {t('Close')}
                    </SoloButton>
                </>
            );
        }

        return (
            <>
                {onSkip !== undefined && email.status !== 'failed' && (
                    <SoloButton variant="text" size={size} data-start="" className={styles.wideOnly} disabled={busy !== null} onClick={onSkip}>
                        {t('Skip this month')}
                    </SoloButton>
                )}
                <SoloButton variant="pill" disabled={busy !== null} onClick={() => save()}>
                    {busy === 'save' ? t('Saving…') : t('Save draft')}
                </SoloButton>
                {sendButton(size, email.status === 'failed' ? t('Try again') : t('Send'), () => setConfirm('send'))}
            </>
        );
    };

    return (
        <>
            <ResponsiveDialog
                open={open && confirm === null}
                onOpenChange={(value) => !value && close()}
                title={title}
                description={subtitle}
                onSubmit={(event: FormEvent) => {
                    event.preventDefault();
                    if (email?.editable && canSend) setConfirm('send');
                }}
                fields
                footerClassName={styles.actions}
                actions={actions}
            >
                {email === null ? (
                    <p className={styles.quiet}>{loaded === null ? t('Loading…') : t('No draft for this month yet.')}</p>
                ) : (
                    <>
                        {notices(email)}
                        {errors.form || errors.recipients || errors.template ? (
                            <p className={styles.error}>{errors.form ?? errors.recipients ?? errors.template}</p>
                        ) : null}

                        <section>
                            <SectionHead
                                label={
                                    readOnly
                                        ? `${t('To')} · ${email.recipients.length}`
                                        : `${t('To')} · ${t('{{picked}} of {{total}}', { picked: picked.length, total: candidates.length })}`
                                }
                            />
                            <ul className={styles.block}>
                                {(readOnly
                                    ? email.recipients.map((address) => ({ email: address, name: nameOf(address), source: 'contact' as const }))
                                    : candidates
                                ).map((candidate) => (
                                    <li key={candidate.email} className={styles.row}>
                                        {!readOnly && (
                                            <input
                                                type="checkbox"
                                                className={styles.check}
                                                checked={picked.includes(candidate.email)}
                                                onChange={() => toggle(candidate.email)}
                                                aria-label={candidate.email}
                                                disabled={busy !== null}
                                            />
                                        )}
                                        <span className={styles.name}>{candidate.source === 'client' ? t('Client email') : candidate.name}</span>
                                        <span className={styles.address}>{candidate.email}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        {(!readOnly || email.bcc_owner) && (
                            <section>
                                <SectionHead label={t('BCC')} />
                                <ul className={styles.block}>
                                    <li className={styles.row}>
                                        {!readOnly && (
                                            <input
                                                type="checkbox"
                                                className={styles.check}
                                                checked={form.bcc_owner}
                                                onChange={(e) => setForm((was) => ({ ...was, bcc_owner: e.target.checked }))}
                                                aria-label={t('Copy to me')}
                                                disabled={busy !== null}
                                            />
                                        )}
                                        <span className={styles.name}>{t('Copy to me')}</span>
                                        <span className={styles.address}>{loaded?.owner_email}</span>
                                    </li>
                                </ul>
                            </section>
                        )}

                        {!readOnly && (loaded?.templates.length ?? 0) > 0 && (
                            <SoloField label={t('Template')} htmlFor="summary-template">
                                <ResponsiveSelect
                                    label={t('Template')}
                                    value={email.template?.key ?? ''}
                                    onValueChange={(key) => key !== email.template?.key && setConfirm({ template: key })}
                                    options={(loaded?.templates ?? []).map((row) => ({ value: row.key, label: row.name }))}
                                    control={fieldControl('summary-template')}
                                    disabled={busy !== null}
                                />
                                <span className={styles.fieldAction}>
                                    <SoloButton
                                        variant="text"
                                        size="sm"
                                        disabled={busy !== null}
                                        // A subject or text edited by hand is replaced only after the owner says so.
                                        onClick={() =>
                                            email.text_edited || edited ? setConfirm('refresh') : post(clientSummaryEmails.store(route).url)
                                        }
                                    >
                                        {t('Refresh from the template')}
                                    </SoloButton>
                                </span>
                            </SoloField>
                        )}

                        <SoloField label={t('Subject')} htmlFor="summary-subject" error={errors.subject}>
                            <SoloInput
                                {...fieldControl('summary-subject', errors.subject)}
                                value={form.subject}
                                onChange={(e) => setForm((was) => ({ ...was, subject: e.target.value }))}
                                maxLength={200}
                                disabled={readOnly || busy !== null}
                            />
                        </SoloField>

                        {readOnly ? (
                            <section>
                                <SectionHead label={t('Email text')} />
                                <div className={styles.rendered}>
                                    <MarkdownView breaks>{email.body}</MarkdownView>
                                </div>
                            </section>
                        ) : (
                            <>
                                <div className={styles.tabs} role="tablist">
                                    {(['text', 'preview'] as const).map((key) => (
                                        <button
                                            key={key}
                                            type="button"
                                            role="tab"
                                            aria-selected={tab === key}
                                            className={styles.tab}
                                            onClick={() => setTab(key)}
                                        >
                                            {key === 'text' ? t('Email text') : t('Preview')}
                                        </button>
                                    ))}
                                </div>
                                {tab === 'text' ? (
                                    <SoloField label={t('Email text')} htmlFor="summary-body" error={errors.body} className={styles.bareField}>
                                        <SoloTextarea
                                            {...fieldControl('summary-body', errors.body)}
                                            rows={12}
                                            value={form.body}
                                            onChange={(e) => setForm((was) => ({ ...was, body: e.target.value }))}
                                            disabled={busy !== null}
                                        />
                                    </SoloField>
                                ) : (
                                    <div className={styles.rendered}>
                                        <p className={styles.subject}>{form.subject}</p>
                                        <MarkdownView breaks>{form.body}</MarkdownView>
                                    </div>
                                )}
                                {onSkip !== undefined && email.status !== 'failed' && (
                                    <div className={styles.phoneOnly}>
                                        <SoloButton variant="text" size="touch" disabled={busy !== null} onClick={onSkip}>
                                            {t('Skip this month')}
                                        </SoloButton>
                                    </div>
                                )}
                            </>
                        )}
                    </>
                )}
            </ResponsiveDialog>

            <ConfirmDialog
                open={confirm === 'send'}
                onOpenChange={(value) => !value && setConfirm(null)}
                title={t('send_summary_to', { count: picked.length })}
                description={
                    form.bcc_owner
                        ? t('{{recipients}}, BCC copy to you. After sending, the subject and text are locked.', { recipients: recipientList })
                        : t('{{recipients}}. After sending, the subject and text are locked.', { recipients: recipientList })
                }
                confirmLabel={t('Send')}
                onConfirm={() => send()}
            >
                {test && <Alert variant="warn">{t('Test sending: nothing goes to the client and the step is not ticked.')}</Alert>}
            </ConfirmDialog>
            <ConfirmDialog
                open={confirm === 'resend'}
                onOpenChange={(value) => !value && setConfirm(null)}
                title={t('Send again?')}
                description={t('If the first send arrived, the client gets two emails.')}
                confirmLabel={t('Send')}
                onConfirm={() => send(true)}
            />
            <ConfirmDialog
                open={confirm === 'refresh'}
                onOpenChange={(value) => !value && setConfirm(null)}
                title={t('Refresh from the template?')}
                description={t('The subject and text you wrote by hand are replaced by the template. The recipients stay.')}
                confirmLabel={t('Refresh')}
                onConfirm={() => post(clientSummaryEmails.store(route).url, { replace: '1' })}
            />
            <ConfirmDialog
                open={confirm === 'mark'}
                onOpenChange={(value) => !value && setConfirm(null)}
                title={t('Mark as sent?')}
                description={t('The step is ticked and the text locked.')}
                confirmLabel={t('Mark')}
                onConfirm={() => post(clientSummaryEmails.markSent(route).url)}
            />
            <ConfirmDialog
                open={confirm !== null && typeof confirm === 'object'}
                onOpenChange={(value) => !value && setConfirm(null)}
                title={t('Replace the subject and text with the template?')}
                confirmLabel={t('Replace')}
                onConfirm={() =>
                    confirm !== null &&
                    typeof confirm === 'object' &&
                    post(clientSummaryEmails.store(route).url, { template: confirm.template, replace: '1' })
                }
            />
        </>
    );

    function notices(email: SummaryEmail) {
        const changed = at(email.changed);
        if (email.status === 'sent') {
            return (
                <>
                    <Alert variant="ok">
                        {t('sent_summary_notice', {
                            count: email.recipients.length,
                            date: email.sent === null ? '' : fullDate(email.sent.day, locale),
                            time: email.sent?.time ?? '',
                        })}
                    </Alert>
                    {email.tick_error !== null && (
                        <Alert variant="warn">{t('The month-close step did not tick: {{error}}', { error: email.tick_error })}</Alert>
                    )}
                </>
            );
        }
        if (email.status === 'queued' && !email.lost) {
            return <Alert variant="warn">{t('Sending…')}</Alert>;
        }
        if (email.status === 'unconfirmed' || email.status === 'queued') {
            const text =
                email.error === null
                    ? t(
                          'Nobody knows whether the email went out: on {{date}} at {{time}} the send got no answer. The CRM will not try again by itself. Check the BCC copy in your mailbox.',
                          changed,
                      )
                    : t(
                          'Nobody knows whether the email went out: on {{date}} at {{time}} the send got no answer ({{error}}). The CRM will not try again by itself. Check the BCC copy in your mailbox.',
                          { ...changed, error: email.error },
                      );

            return <Alert variant="warn">{text}</Alert>;
        }
        if (email.status === 'failed') {
            return (
                <Alert variant="error">
                    {t('Not sent on {{date}} at {{time}}: {{error}}. Try again.', {
                        ...changed,
                        error: (email.error === NOT_STARTED ? t(NOT_STARTED) : (email.error ?? '')).replace(/\.$/, ''),
                    })}
                </Alert>
            );
        }
        if (email.status === 'test_sent') {
            return (
                <Alert variant="warn">
                    {t(
                        'Sent as a test on {{date}} at {{time}}: nothing went to the client and the step is not ticked. Once you set a sender, send again.',
                        changed,
                    )}
                </Alert>
            );
        }
        if (loaded?.delivery === 'refused') {
            return <Alert variant="warn">{t('Sending is off on the server: nothing goes out.')}</Alert>;
        }

        return test ? <Alert variant="warn">{t('Test sending: nothing goes to the client until you set a sender.')}</Alert> : null;
    }
}
