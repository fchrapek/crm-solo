import { MarkdownView } from '@/components/markdown-view';
import { Chip } from '@/components/solo/chip';
import { fieldControl, SoloField, SoloInput, SoloTextarea } from '@/components/solo/field';
import { KvRow } from '@/components/solo/kv-row';
import { Notice } from '@/components/solo/notice';
import { SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { Row } from '@/components/solo/row';
import { Tile, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { InfoHint } from '@/components/ui/info-hint';
import { jsonRequest } from '@/lib/json-request';
import settings from '@/routes/settings';
import { router } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { EmailSending, EmailTemplateRow } from '../types';
import { RowButton, TileButton } from './parts';
import styles from './tab.module.css';

/** Figma: Ustawienia · Wiadomości e-mail (1091:24073, phone 1091:24343). How email goes out and the templates. */
export function EmailTab({ sending, templates }: { sending: EmailSending; templates: EmailTemplateRow[] }) {
    const { t } = useTranslation();
    const [template, setTemplate] = useState<EmailTemplateRow | 'new' | null>(null);
    const sender = sending.from ?? t('no sender set');
    const senderHint = t(
        'The sender is set on the server. Until then every send is a test: nothing goes to clients and the summary is not marked as sent.',
    );
    const bccHint = t('Every summary also goes to you as a hidden copy. You can turn it off in the draft.');

    return (
        <>
            <Tile kind="plain">
                <TileHeader label={t('Sending')} />
                {!sending.live && <Notice kind="warn">{t('Test sending: nothing goes to the client until you set a sender.')}</Notice>}
                <dl className={styles.kv}>
                    <KvRow
                        layout="tile"
                        tone={sending.from === null ? 'muted' : undefined}
                        label={
                            <>
                                {t('Sender')} <InfoHint>{senderHint}</InfoHint>
                            </>
                        }
                    >
                        {sender}
                    </KvRow>
                    <KvRow
                        layout="tile"
                        label={
                            <>
                                {t('BCC copy')} <InfoHint>{bccHint}</InfoHint>
                            </>
                        }
                    >
                        {sending.bcc}
                    </KvRow>
                </dl>
            </Tile>

            <Tile kind="list">
                <TileHeader
                    label={t('Templates')}
                    count={templates.length}
                    end={<TileButton onClick={() => setTemplate('new')}>{t('New template')}</TileButton>}
                />
                {templates.length === 0 ? (
                    <TileInset>
                        <TileNote>
                            {t(
                                'No email template yet. A template is the subject and text of a summary: the client name, the month, the work list and the hours fill in by themselves. Without a template no summary draft is made.',
                            )}
                        </TileNote>
                    </TileInset>
                ) : (
                    <TileRows>
                        {templates.map((row, index) => (
                            <Row
                                key={row.key}
                                rule={index > 0}
                                title={row.name}
                                chip={row.is_default ? <Chip status="done">{t('Default')}</Chip> : undefined}
                                action={<RowButton onClick={() => setTemplate(row)}>{t('Edit')}</RowButton>}
                            />
                        ))}
                    </TileRows>
                )}
            </Tile>

            <EmailTemplateDialog template={template} count={templates.length} onClose={() => setTemplate(null)} />
        </>
    );
}

interface Preview {
    subject: string;
    body: string;
    client: string;
    period: string;
    period_label: string;
}

type Draft = { name: string; subject: string; body: string; is_default: boolean };

const EMPTY: Draft = { name: '', subject: '', body: '', is_default: false };

/** Figma: Szablon e-mail (992:27078), sheet 992:27206. Subject and text with the tokens, inserted where the caret was, and the email as a client gets it. */
function EmailTemplateDialog({ template, count, onClose }: { template: EmailTemplateRow | 'new' | null; count: number; onClose: () => void }) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const subject = useRef<HTMLInputElement>(null);
    const body = useRef<HTMLTextAreaElement>(null);
    const lastField = useRef<'subject' | 'body'>('body');

    const [shown, setShown] = useState(template);
    if (template !== shown) {
        setShown(template);
        if (template !== null) {
            setDraft(
                template === 'new'
                    ? { ...EMPTY, is_default: count === 0 }
                    : { name: template.name, subject: template.subject, body: template.body, is_default: template.is_default },
            );
            setErrors({});
            setPreview(null);
        }
    }
    const current = template ?? shown;
    const existing = current !== null && current !== 'new' ? current : null;

    useEffect(() => {
        if (template === null || draft.subject.trim() === '' || draft.body.trim() === '') return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            jsonRequest<Preview | null>(settings.email.preview().url, 'POST', { subject: draft.subject, body: draft.body }, controller.signal)
                .then((result) => setPreview(result))
                // A half-typed placeholder fails the preview; the check that counts runs on save.
                .catch(() => setPreview(null));
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [template, draft.subject, draft.body]);

    function insert(token: string) {
        const key = lastField.current;
        const field = key === 'subject' ? subject.current : body.current;
        const value = draft[key];
        const at = field?.selectionStart ?? value.length;
        const end = field?.selectionEnd ?? at;
        setDraft((was) => ({ ...was, [key]: was[key].slice(0, at) + token + was[key].slice(end) }));
        window.requestAnimationFrame(() => {
            field?.focus();
            field?.setSelectionRange(at + token.length, at + token.length);
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const missing: Record<string, string> = {};
        if (draft.name.trim() === '') missing.name = t('Enter a name.');
        if (draft.subject.trim() === '') missing.subject = t('Enter a subject.');
        if (draft.body.trim() === '') missing.body = t('The text cannot be empty.');
        if (Object.keys(missing).length > 0) {
            setErrors(missing);
            return;
        }
        setProcessing(true);
        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };
        if (existing === null) {
            router.post(settings.email.templates.store().url, draft, options);
        } else {
            router.put(settings.email.templates.update(existing.key).url, draft, options);
        }
    }

    // The default cannot go while another template could take its place: that one is made the default first.
    const locked = existing !== null && existing.is_default && count > 1;
    const deleteAction = (size: 'md' | 'touch') =>
        existing === null ? null : locked ? (
            <InfoHint
                side="bottom"
                trigger={
                    <SoloButton variant="text" size={size} aria-disabled="true" data-start="">
                        {t('Delete template')}
                    </SoloButton>
                }
            >
                {t('Set another template as the default first.')}
            </InfoHint>
        ) : (
            <SoloButton variant="text" size={size} data-start="" disabled={processing} onClick={() => setDeleting(true)}>
                {t('Delete template')}
            </SoloButton>
        );
    const tokens = [
        { token: '{client_name}', label: t('The client') },
        { token: '{period}', label: t('Period') },
        { token: '{work_list}', label: t('Work list') },
        { token: '{hours_summary}', label: t('Hours') },
    ];
    const shownPreview = draft.subject.trim() === '' || draft.body.trim() === '' ? null : preview;

    return (
        <>
            <ResponsiveDialog
                open={template !== null && !deleting}
                onOpenChange={(open) => !open && onClose()}
                title={existing === null ? t('New template') : t('Email template')}
                onSubmit={submit}
                sheetAction="save"
                fields
                saveLabel={t('Save')}
                saving={processing}
                footerClassName={styles.dialogActions}
                actions={(size) => (
                    <>
                        {deleteAction(size)}
                        <SoloButton variant="pill" onClick={onClose} disabled={processing}>
                            {t('Cancel')}
                        </SoloButton>
                        <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                            {processing ? t('Saving…') : t('Save')}
                        </SoloButton>
                    </>
                )}
            >
                {errors.form && (
                    <p className={styles.message} data-tone="error">
                        {errors.form}
                    </p>
                )}
                <SoloField label={t('Name')} htmlFor="email-template-name" error={errors.name}>
                    <SoloInput
                        {...fieldControl('email-template-name', errors.name)}
                        value={draft.name}
                        onChange={(e) => setDraft((was) => ({ ...was, name: e.target.value }))}
                        maxLength={100}
                        autoFocus={existing === null}
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('Subject')} htmlFor="email-template-subject" error={errors.subject}>
                    <SoloInput
                        {...fieldControl('email-template-subject', errors.subject)}
                        ref={subject}
                        value={draft.subject}
                        onFocus={() => (lastField.current = 'subject')}
                        onChange={(e) => setDraft((was) => ({ ...was, subject: e.target.value }))}
                        maxLength={200}
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('Text (markdown)')} htmlFor="email-template-body" error={errors.body || undefined}>
                    <SoloTextarea
                        {...fieldControl('email-template-body', errors.body || undefined)}
                        ref={body}
                        rows={10}
                        value={draft.body}
                        onFocus={() => (lastField.current = 'body')}
                        onChange={(e) => setDraft((was) => ({ ...was, body: e.target.value }))}
                        maxLength={10000}
                        disabled={processing}
                    />
                </SoloField>
                <div className={styles.chips} role="group" aria-label={t('Insert')}>
                    <span className={styles.chipsLabel}>{t('Insert')}</span>
                    {tokens.map((item) => (
                        <button
                            key={item.token}
                            type="button"
                            className={styles.chip}
                            // Keeps the caret in the field it was in.
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => insert(item.token)}
                            disabled={processing}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
                <label className={styles.check}>
                    <input
                        type="checkbox"
                        checked={draft.is_default}
                        onChange={(e) => setDraft((was) => ({ ...was, is_default: e.target.checked }))}
                        disabled={processing}
                    />
                    {t('Default for new drafts')}
                </label>
                {errors.is_default && (
                    <p className={styles.message} data-tone="error">
                        {errors.is_default}
                    </p>
                )}
                {shownPreview && (
                    <div className={styles.preview}>
                        <SectionHead
                            kind="body"
                            as="span"
                            label={
                                <>
                                    {t('Preview')}{' '}
                                    <InfoHint>
                                        {t('Filled from {{client}}. A field without a value stays in braces.', { client: shownPreview.client })}
                                    </InfoHint>
                                </>
                            }
                            meta={`${shownPreview.client} · ${shownPreview.period_label}`}
                        />
                        <div className={styles.emailPreview}>
                            <p className={styles.emailSubject}>{shownPreview.subject}</p>
                            <MarkdownView breaks>{shownPreview.body}</MarkdownView>
                        </div>
                    </div>
                )}
                {existing !== null && <div className={styles.sheetEnd}>{deleteAction('touch')}</div>}
            </ResponsiveDialog>
            <ConfirmDialog
                open={deleting}
                onOpenChange={(value) => !value && setDeleting(false)}
                title={t('Delete template')}
                description={t('{{name}} is deleted. Drafts made from it keep their text.', { name: existing?.name ?? '' })}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() => {
                    if (existing !== null) {
                        router.delete(settings.email.templates.destroy(existing.key).url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                setDeleting(false);
                                onClose();
                            },
                            onError: (errs) => {
                                setDeleting(false);
                                setErrors(errs);
                            },
                        });
                    }
                }}
            />
        </>
    );
}
