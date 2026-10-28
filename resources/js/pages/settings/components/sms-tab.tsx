import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { ListRow } from '@/components/solo/list-row';
import { SectionAction, SectionHead, SectionLink, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { InfoHint } from '@/components/ui/info-hint';
import { jsonRequest } from '@/lib/json-request';
import messages from '@/routes/messages';
import settings from '@/routes/settings';
import { router } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { FieldDefinition, MessageRuleRow, MessageTemplateRow, SendingSettings } from '../types';
import styles from './tab.module.css';

interface Props {
    sending: SendingSettings;
    templates: MessageTemplateRow[];
    rules: MessageRuleRow[];
    fields: FieldDefinition[];
}

const DAY = 1440;

/** Figma: Ustawienia / Wiadomości SMS (599:1141), phones 599:20041. How messages go out, the templates, the rules. */
export function SmsTab({ sending, templates, rules, fields }: Props) {
    const { t } = useTranslation();
    const [sendingOpen, setSendingOpen] = useState(false);
    const [template, setTemplate] = useState<MessageTemplateRow | 'new' | null>(null);
    const [rule, setRule] = useState<MessageRuleRow | 'new' | null>(null);
    const describe = useRuleTitle(fields);
    const quiet = t('{{from}} to {{to}}', { from: sending.quiet_from, to: sending.quiet_to });
    const letters = sending.ascii ? t('without Polish letters') : t('with Polish letters');
    const provider = sending.provider === 'simulator' ? t('Simulator') : sending.provider === 'smsapi' ? 'SMSAPI' : sending.provider;

    return (
        <>
            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={
                        <>
                            {t('Sending')}{' '}
                            {sending.provider === 'simulator' && (
                                <InfoHint>{t('Simulator: nothing is sent. Every message gets the status simulated.')}</InfoHint>
                            )}
                        </>
                    }
                    action={<SectionAction onClick={() => setSendingOpen(true)}>{t('Edit')}</SectionAction>}
                />
                <ul className={`${styles.list} ${styles.phone}`}>
                    <li>
                        <ListRow title={t('Sent through')} value={provider} onClick={() => setSendingOpen(true)} />
                    </li>
                    <li>
                        <ListRow title={t('Queue')} href={messages.index().url} />
                    </li>
                    <li>
                        <ListRow title={t('Quiet hours')} value={quiet} onClick={() => setSendingOpen(true)} />
                    </li>
                    <li>
                        <ListRow title={t('Polish letters')} value={letters} onClick={() => setSendingOpen(true)} />
                    </li>
                </ul>
                <ul className={`${styles.list} ${styles.wide}`}>
                    <li className={styles.row}>
                        <span className={styles.rowText}>
                            <span className={styles.rowTitle}>{provider}</span>
                        </span>
                        <span className={styles.rowEnd}>
                            <SectionLink href={messages.index().url}>{t('Queue')} →</SectionLink>
                        </span>
                    </li>
                    <li className={styles.row}>
                        <span className={styles.rowText}>
                            <span className={styles.rowTitle}>
                                {t('Quiet hours')} <InfoHint>{t('A message due in these hours waits for their end.')}</InfoHint>
                            </span>
                        </span>
                        <span className={styles.rowEnd}>
                            <strong>{quiet}</strong>
                        </span>
                    </li>
                    <li className={styles.row}>
                        <span className={styles.rowText}>
                            <span className={styles.rowTitle}>
                                {t('Polish letters')}{' '}
                                <InfoHint>{t('One Polish letter cuts an SMS from 160 characters to 70. Without them, ą becomes a.')}</InfoHint>
                            </span>
                        </span>
                        <span className={styles.rowEnd}>
                            <strong>{letters}</strong>
                        </span>
                    </li>
                </ul>
            </section>

            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={`${t('Templates')} · ${templates.length}`}
                    action={<SectionAction onClick={() => setTemplate('new')}>{t('New template')}</SectionAction>}
                />
                {templates.length === 0 && (
                    <p className={styles.note}>
                        {t('No templates yet. A template is the text of a message, with the client name or a field filled in.')}
                    </p>
                )}
                <ul className={`${styles.list} ${styles.phone}`}>
                    {templates.map((row) => (
                        <li key={row.key}>
                            <ListRow title={row.name} value={t('rule_count', { count: row.rules })} onClick={() => setTemplate(row)} />
                        </li>
                    ))}
                </ul>
                <ul className={`${styles.list} ${styles.wide}`}>
                    {templates.map((row) => (
                        <li key={row.key} className={styles.row}>
                            <span className={styles.rowText}>
                                <button type="button" className={styles.rowTitle} onClick={() => setTemplate(row)}>
                                    {row.name}
                                </button>
                            </span>
                            <span className={styles.rowEnd}>
                                {t('rule_count', { count: row.rules })}
                                <SectionAction onClick={() => setTemplate(row)}>{t('Edit')}</SectionAction>
                            </span>
                        </li>
                    ))}
                </ul>
            </section>

            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={`${t('Rules')} · ${rules.length}`}
                    action={templates.length > 0 ? <SectionAction onClick={() => setRule('new')}>{t('New rule')}</SectionAction> : undefined}
                />
                {rules.length === 0 && (
                    <p className={styles.note}>
                        {t('No rules yet. A rule sends a template when a field takes a value, or some days before a date.')}
                    </p>
                )}
                <ul className={`${styles.list} ${styles.phone}`}>
                    {rules.map((row) => (
                        <li key={row.key}>
                            <ListRow
                                title={describe(row)}
                                value={!row.enabled ? t('off') : row.pending > 0 ? t('scheduled_count', { count: row.pending }) : undefined}
                                onClick={() => setRule(row)}
                            />
                        </li>
                    ))}
                </ul>
                <ul className={`${styles.list} ${styles.wide}`}>
                    {rules.map((row) => (
                        <li key={row.key} className={styles.row}>
                            <span className={styles.rowText}>
                                <button
                                    type="button"
                                    className={styles.rowTitle}
                                    data-tone={row.enabled ? undefined : 'muted'}
                                    onClick={() => setRule(row)}
                                >
                                    {describe(row)}
                                </button>
                            </span>
                            <span className={styles.rowEnd}>
                                {row.pending > 0 && t('scheduled_count', { count: row.pending })}
                                <strong>{row.enabled ? t('on') : t('off')}</strong>
                                <SectionAction onClick={() => setRule(row)}>{t('Edit')}</SectionAction>
                            </span>
                        </li>
                    ))}
                </ul>
            </section>

            <SendingDialog open={sendingOpen} sending={sending} onClose={() => setSendingOpen(false)} />
            <TemplateDialog
                template={template}
                fields={fields}
                usedBy={template !== null && template !== 'new' ? rules.filter((row) => row.template === template.key).map(describe) : []}
                onClose={() => setTemplate(null)}
            />
            <RuleDialog rule={rule} templates={templates} fields={fields} onClose={() => setRule(null)} />
        </>
    );
}

/** "Wizja: umówiona → Potwierdzenie wizji", "Termin wizji, 1 dzień przed, 18:00 → Przypomnienie". */
function useRuleTitle(fields: FieldDefinition[]) {
    const { t } = useTranslation();
    const offset = useOffsetLabel();

    return (rule: MessageRuleRow) => {
        const field = fields.find((candidate) => candidate.key === rule.field);
        const label = field?.label ?? rule.field;
        const when =
            rule.trigger === 'field_value'
                ? `${label}: ${
                      rule.values.length === 0
                          ? t('any value')
                          : rule.values.map((value) => field?.options.find((option) => option.key === value)?.label ?? value).join(', ')
                  }`
                : [label, offset(rule.offset_minutes), rule.at].filter(Boolean).join(', ');

        return rule.template_name === null ? when : `${when} → ${rule.template_name}`;
    };
}

function useOffsetLabel() {
    const { t } = useTranslation();

    return (minutes: number) => {
        if (minutes === 0) return t('on the day');
        const side = minutes < 0 ? 'before' : 'after';
        const size = Math.abs(minutes);
        const amount =
            size % DAY === 0
                ? t('day_count', { count: size / DAY })
                : size % 60 === 0
                  ? t('hour_count', { count: size / 60 })
                  : t('minute_count', { count: size });

        return side === 'before' ? t('{{amount}} before', { amount }) : t('{{amount}} after', { amount });
    };
}

function SendingDialog({ open, sending, onClose }: { open: boolean; sending: SendingSettings; onClose: () => void }) {
    const { t } = useTranslation();
    const stored = { quiet_from: sending.quiet_from, quiet_to: sending.quiet_to, ascii: sending.ascii, provider: sending.provider };
    const [draft, setDraft] = useState(stored);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setDraft(stored);
            setErrors({});
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (draft.provider === 'smsapi' && sending.provider !== 'smsapi') {
            setConfirming(true);
            return;
        }
        save();
    }

    function save() {
        setProcessing(true);
        router.patch(settings.sms.sending().url, draft, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errs) => setErrors(errs),
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title={t('Sending')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={t('Save')}
            saving={processing}
            footerClassName={styles.dialogActions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={onClose} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Sent through')} htmlFor="sms-provider" error={errors.provider}>
                <ResponsiveSelect
                    label={t('Sent through')}
                    value={draft.provider}
                    onValueChange={(value) => setDraft((was) => ({ ...was, provider: value }))}
                    options={[
                        { value: 'simulator', label: t('Simulator, nothing goes out') },
                        { value: 'smsapi', label: t('SMSAPI, real messages') },
                    ]}
                    control={fieldControl('sms-provider', errors.provider)}
                    disabled={processing || (!sending.smsapi_connected && draft.provider !== 'smsapi')}
                />
                {!sending.smsapi_connected && (
                    <p className={styles.fieldNote}>
                        {t('Connect SMSAPI first.')} <SectionLink href={settings.show('integracje').url}>{t('Integrations')} →</SectionLink>
                    </p>
                )}
            </SoloField>
            <SoloFieldRow>
                <SoloField label={t('Quiet from')} htmlFor="quiet-from" error={errors.quiet_from}>
                    <SoloInput
                        {...fieldControl('quiet-from', errors.quiet_from)}
                        type="time"
                        value={draft.quiet_from}
                        onChange={(e) => setDraft((was) => ({ ...was, quiet_from: e.target.value }))}
                        required
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('Quiet to')} htmlFor="quiet-to" error={errors.quiet_to}>
                    <SoloInput
                        {...fieldControl('quiet-to', errors.quiet_to)}
                        type="time"
                        value={draft.quiet_to}
                        onChange={(e) => setDraft((was) => ({ ...was, quiet_to: e.target.value }))}
                        required
                        disabled={processing}
                    />
                </SoloField>
            </SoloFieldRow>
            <SoloField label={t('Polish letters')} htmlFor="sms-ascii">
                <ResponsiveSelect
                    label={t('Polish letters')}
                    value={draft.ascii ? 'ascii' : 'unicode'}
                    onValueChange={(value) => setDraft((was) => ({ ...was, ascii: value === 'ascii' }))}
                    options={[
                        { value: 'unicode', label: t('Keep them, 70 characters per SMS') },
                        { value: 'ascii', label: t('Without, 160 characters per SMS') },
                    ]}
                    control={fieldControl('sms-ascii')}
                    disabled={processing}
                />
            </SoloField>
            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={t('Send real messages?')}
                description={t('From now on clients get the messages your rules send. Each one costs what your SMSAPI price list says.')}
                confirmLabel={t('Turn sending on')}
                onConfirm={() => {
                    setConfirming(false);
                    save();
                }}
            />
        </ResponsiveDialog>
    );
}

interface Preview {
    text: string;
    client: string | null;
    characters: number;
    segments: number;
    unicode: boolean;
}

/** Figma: Dialog / Szablon SMS (599:19578), sheet 600:1371. The text, the placeholders to insert, and the text as a client gets it. */
function TemplateDialog({
    template,
    fields,
    usedBy,
    onClose,
}: {
    template: MessageTemplateRow | 'new' | null;
    fields: FieldDefinition[];
    /** The rules that send this template, as their list titles. */
    usedBy: string[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState({ name: '', body: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const body = useRef<HTMLTextAreaElement>(null);

    const [shown, setShown] = useState(template);
    if (template !== shown) {
        setShown(template);
        if (template !== null) {
            setDraft(template === 'new' ? { name: '', body: '' } : { name: template.name, body: template.body });
            setErrors({});
            setPreview(null);
        }
    }
    const current = template ?? shown;
    const existing = current !== null && current !== 'new' ? current : null;

    useEffect(() => {
        if (template === null || draft.body.trim() === '') return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            jsonRequest<Preview>(settings.sms.preview().url, 'POST', { body: draft.body }, controller.signal)
                .then((result) => {
                    setPreview(result);
                })
                // A half-typed placeholder fails the preview; the check that counts runs on save.
                .catch(() => setPreview(null));
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [template, draft.body]);

    function insert(token: string) {
        const area = body.current;
        const at = area?.selectionStart ?? draft.body.length;
        const end = area?.selectionEnd ?? at;
        setDraft((was) => ({ ...was, body: was.body.slice(0, at) + token + was.body.slice(end) }));
        window.requestAnimationFrame(() => {
            area?.focus();
            area?.setSelectionRange(at + token.length, at + token.length);
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };
        if (existing === null) {
            router.post(settings.sms.templates.store().url, draft, options);
        } else {
            router.put(settings.sms.templates.update(existing.key).url, draft, options);
        }
    }

    // A template a rule still uses cannot go; the rule goes first.
    const deleteAction = (size: 'md' | 'touch') =>
        existing === null ? null : existing.rules > 0 ? (
            <InfoHint
                side="bottom"
                trigger={
                    // aria-disabled, not disabled: the button stays reachable by keyboard and touch, and opens the reason.
                    <SoloButton variant="text" size={size} aria-disabled="true" data-start="">
                        {t('Delete template')}
                    </SoloButton>
                }
            >
                {t('used_by_rules', { count: usedBy.length, rules: usedBy.join('; ') })}
            </InfoHint>
        ) : (
            <SoloButton variant="text" size={size} data-start="" disabled={processing} onClick={() => setDeleting(true)}>
                {t('Delete template')}
            </SoloButton>
        );
    const tokens = [
        { token: '{client_name}', label: t('Client name') },
        { token: '{assignee_name}', label: t('Assignee') },
        ...fields.map((field) => ({ token: `{field:${field.key}}`, label: field.label })),
    ];
    const shownPreview = draft.body.trim() === '' ? null : preview;

    return (
        <>
            <ResponsiveDialog
                open={template !== null && !deleting}
                onOpenChange={(open) => !open && onClose()}
                title={existing === null ? t('New template') : t('SMS template')}
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
                <SoloField label={t('Name')} htmlFor="template-name" error={errors.name}>
                    <SoloInput
                        {...fieldControl('template-name', errors.name)}
                        value={draft.name}
                        onChange={(e) => setDraft((was) => ({ ...was, name: e.target.value }))}
                        required
                        maxLength={100}
                        autoFocus={existing === null}
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('Text')} htmlFor="template-body" error={errors.body || undefined}>
                    <SoloTextarea
                        {...fieldControl('template-body', errors.body || undefined)}
                        ref={body}
                        rows={4}
                        value={draft.body}
                        onChange={(e) => setDraft((was) => ({ ...was, body: e.target.value }))}
                        required
                        maxLength={1000}
                        disabled={processing}
                    />
                </SoloField>
                <div className={styles.chips} role="group" aria-label={t('Insert')}>
                    <span className={styles.chipsLabel}>{t('Insert')}</span>
                    {tokens.map((item) => (
                        <button key={item.token} type="button" className={styles.chip} onClick={() => insert(item.token)} disabled={processing}>
                            {item.label}
                        </button>
                    ))}
                </div>
                {shownPreview && (
                    <div className={styles.preview}>
                        <SectionHead
                            kind="body"
                            as="span"
                            label={
                                <>
                                    {t('Preview')}{' '}
                                    {shownPreview.client && (
                                        <InfoHint>
                                            {t('Filled from {{client}}. A field without a value stays in braces.', { client: shownPreview.client })}
                                        </InfoHint>
                                    )}
                                </>
                            }
                            meta={t('{{characters}} characters · {{sms}}', {
                                characters: shownPreview.characters,
                                sms: t('sms_count', { count: shownPreview.segments }),
                            })}
                        />
                        <p className={styles.message}>{shownPreview.text}</p>
                    </div>
                )}
                {existing !== null && <div className={styles.sheetEnd}>{deleteAction('touch')}</div>}
            </ResponsiveDialog>
            <ConfirmDialog
                open={deleting}
                onOpenChange={(value) => !value && setDeleting(false)}
                title={t('Delete template')}
                description={t('{{name}} is deleted. A rule that uses it has to go first.', { name: existing?.name ?? '' })}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() => {
                    if (existing !== null) {
                        router.delete(settings.sms.templates.destroy(existing.key).url, {
                            preserveScroll: true,
                            onSuccess: () => onClose(),
                            onError: (errs) => setErrors(errs),
                        });
                    }
                    setDeleting(false);
                }}
            />
        </>
    );
}

const OFFSET_DAYS = [-7, -3, -2, -1, 0, 1, 2, 3, 7];
const EXPIRY = [30, 60, 120, 360, 900, 1440];

type RuleDraft = {
    template: string;
    trigger: MessageRuleRow['trigger'];
    field: string;
    values: string[];
    offset_minutes: number;
    at: string;
    expire_minutes: number;
    enabled: boolean;
};

/** Figma: Dialog / Reguła SMS (599:19634), sheet 600:1643. When a template goes out. */
function RuleDialog({
    rule,
    templates,
    fields,
    onClose,
}: {
    rule: MessageRuleRow | 'new' | null;
    templates: MessageTemplateRow[];
    fields: FieldDefinition[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const offset = useOffsetLabel();
    const [draft, setDraft] = useState<RuleDraft | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const dateFields = fields.filter((field) => field.type === 'date' || field.type === 'datetime');

    const [shown, setShown] = useState(rule);
    if (rule !== shown) {
        setShown(rule);
        if (rule !== null) {
            setDraft(
                rule === 'new'
                    ? {
                          template: templates[0]?.key ?? '',
                          trigger: 'field_value',
                          field: fields[0]?.key ?? '',
                          values: [],
                          offset_minutes: -DAY,
                          at: '18:00',
                          expire_minutes: 900,
                          enabled: true,
                      }
                    : {
                          template: rule.template ?? '',
                          trigger: rule.trigger,
                          field: rule.field,
                          values: rule.values,
                          offset_minutes: rule.offset_minutes,
                          at: rule.at ?? '',
                          expire_minutes: rule.expire_minutes,
                          enabled: rule.enabled,
                      },
            );
            setErrors({});
        }
    }
    const current = rule ?? shown;
    const existing = current !== null && current !== 'new' ? current : null;
    if (draft === null) return null;

    const update = (patch: Partial<RuleDraft>) => setDraft((was) => (was === null ? was : { ...was, ...patch }));
    const choosable = draft.trigger === 'date_offset' ? dateFields : fields;
    const field = fields.find((candidate) => candidate.key === draft.field);
    const offsets = OFFSET_DAYS.map((days) => days * DAY);
    const expiries = EXPIRY.includes(draft.expire_minutes) ? EXPIRY : [...EXPIRY, draft.expire_minutes].sort((a, b) => a - b);
    const duration = (minutes: number) => (minutes % 60 === 0 ? t('hour_count', { count: minutes / 60 }) : t('minute_count', { count: minutes }));

    function setTrigger(trigger: RuleDraft['trigger']) {
        const pool = trigger === 'date_offset' ? dateFields : fields;
        update({
            trigger,
            field: pool.some((candidate) => candidate.key === draft?.field) ? draft?.field : (pool[0]?.key ?? ''),
            values: [],
            expire_minutes: trigger === 'date_offset' ? 120 : 900,
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (draft === null) return;
        setProcessing(true);
        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };
        if (existing === null) {
            router.post(settings.sms.rules.store().url, draft, options);
        } else {
            router.put(settings.sms.rules.update(existing.key).url, draft, options);
        }
    }

    const deleteAction = (size: 'md' | 'touch') =>
        existing === null ? null : (
            <SoloButton variant="text" size={size} data-start="" disabled={processing} onClick={() => setDeleting(true)}>
                {t('Delete rule')}
            </SoloButton>
        );

    return (
        <>
            <ResponsiveDialog
                open={rule !== null && !deleting}
                onOpenChange={(open) => !open && onClose()}
                title={existing === null ? t('New rule') : t('SMS rule')}
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
                {(errors.form || errors.field || errors.template) && (
                    <p className={styles.message} data-tone="error">
                        {errors.form || errors.field || errors.template}
                    </p>
                )}
                <SoloField label={t('Template')} htmlFor="rule-template">
                    <ResponsiveSelect
                        label={t('Template')}
                        value={draft.template}
                        onValueChange={(value) => update({ template: value })}
                        options={templates.map((row) => ({ value: row.key, label: row.name }))}
                        control={fieldControl('rule-template')}
                        disabled={processing}
                    />
                </SoloField>
                <SoloFieldRow>
                    <SoloField
                        label={
                            <>
                                {t('When')}{' '}
                                <InfoHint>
                                    {t(
                                        'When a field takes a value: the SMS goes out right after the change. Before or after a date: on the day counted from the date in the field.',
                                    )}
                                </InfoHint>
                            </>
                        }
                        htmlFor="rule-trigger"
                    >
                        <ResponsiveSelect
                            label={t('When')}
                            value={draft.trigger}
                            onValueChange={(value) => setTrigger(value as RuleDraft['trigger'])}
                            options={[
                                { value: 'field_value', label: t('When a field takes a value') },
                                ...(dateFields.length > 0 ? [{ value: 'date_offset', label: t('Before or after a date') }] : []),
                            ]}
                            control={fieldControl('rule-trigger')}
                            disabled={processing}
                        />
                    </SoloField>
                    <SoloField label={draft.trigger === 'date_offset' ? t('Date field') : t('Field')} htmlFor="rule-field">
                        <ResponsiveSelect
                            label={t('Field')}
                            value={draft.field}
                            onValueChange={(value) => update({ field: value, values: [] })}
                            options={choosable.map((candidate) => ({ value: candidate.key, label: candidate.label }))}
                            control={fieldControl('rule-field')}
                            disabled={processing}
                        />
                    </SoloField>
                </SoloFieldRow>

                {draft.trigger === 'field_value' && field !== undefined && field.options.length > 0 && (
                    <fieldset className={styles.checks}>
                        <legend>
                            {t('Values')} <InfoHint>{t('None ticked: any value sends it.')}</InfoHint>
                        </legend>
                        {field.options.map((option) => (
                            <label key={option.key} className={styles.check}>
                                <input
                                    type="checkbox"
                                    checked={draft.values.includes(option.key)}
                                    disabled={processing}
                                    onChange={(e) =>
                                        update({
                                            values: e.target.checked
                                                ? [...draft.values, option.key]
                                                : draft.values.filter((value) => value !== option.key),
                                        })
                                    }
                                />
                                {option.label}
                            </label>
                        ))}
                    </fieldset>
                )}

                {draft.trigger === 'date_offset' && (
                    <SoloFieldRow>
                        <SoloField
                            label={
                                <>
                                    {t('How long')} <InfoHint>{t('Days before or after the date in the field. On the day: the same day.')}</InfoHint>
                                </>
                            }
                            htmlFor="rule-offset"
                        >
                            <ResponsiveSelect
                                label={t('How long')}
                                value={String(draft.offset_minutes)}
                                onValueChange={(value) => update({ offset_minutes: Number(value) })}
                                options={(offsets.includes(draft.offset_minutes)
                                    ? offsets
                                    : [...offsets, draft.offset_minutes].sort((a, b) => a - b)
                                ).map((minutes) => ({
                                    value: String(minutes),
                                    label: offset(minutes),
                                }))}
                                control={fieldControl('rule-offset')}
                                disabled={processing}
                            />
                        </SoloField>
                        <SoloField
                            label={
                                <>
                                    {t('At')}{' '}
                                    <InfoHint>
                                        {t(
                                            'The sending time on that day. Without one, a date field sends after quiet hours, a date and time field at its time.',
                                        )}
                                    </InfoHint>
                                </>
                            }
                            htmlFor="rule-at"
                            error={errors.at}
                        >
                            <SoloInput
                                {...fieldControl('rule-at', errors.at)}
                                type="time"
                                value={draft.at}
                                onChange={(e) => update({ at: e.target.value })}
                                disabled={processing}
                            />
                        </SoloField>
                    </SoloFieldRow>
                )}

                <SoloFieldRow>
                    <SoloField
                        label={
                            <>
                                {t('Valid for')} <InfoHint>{t('After this time a message not yet sent is skipped, never sent late.')}</InfoHint>
                            </>
                        }
                        htmlFor="rule-expire"
                    >
                        <ResponsiveSelect
                            label={t('Valid for')}
                            value={String(draft.expire_minutes)}
                            onValueChange={(value) => update({ expire_minutes: Number(value) })}
                            options={expiries.map((minutes) => ({ value: String(minutes), label: duration(minutes) }))}
                            control={fieldControl('rule-expire')}
                            disabled={processing}
                        />
                    </SoloField>
                    <SoloField label={t('Active')} htmlFor="rule-enabled">
                        <ResponsiveSelect
                            label={t('Active')}
                            value={draft.enabled ? 'yes' : 'no'}
                            onValueChange={(value) => update({ enabled: value === 'yes' })}
                            options={[
                                { value: 'yes', label: t('Yes') },
                                { value: 'no', label: t('No') },
                            ]}
                            control={fieldControl('rule-enabled')}
                            disabled={processing}
                        />
                    </SoloField>
                </SoloFieldRow>
                {existing !== null && <div className={styles.sheetEnd}>{deleteAction('touch')}</div>}
            </ResponsiveDialog>
            <ConfirmDialog
                open={deleting}
                onOpenChange={(value) => !value && setDeleting(false)}
                title={t('Delete rule')}
                description={t('The rule is deleted and the messages it still has waiting are withdrawn. Sent ones stay in the history.')}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() => {
                    if (existing !== null)
                        router.delete(settings.sms.rules.destroy(existing.key).url, { preserveScroll: true, onSuccess: () => onClose() });
                    setDeleting(false);
                }}
            />
        </>
    );
}
