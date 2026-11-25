import { ActionMenuItem } from '@/components/solo/action-menu';
import { Chip } from '@/components/solo/chip';
import { fieldControl, SoloField, SoloFieldRow, SoloInput } from '@/components/solo/field';
import { KvRow } from '@/components/solo/kv-row';
import { Notice } from '@/components/solo/notice';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { Row } from '@/components/solo/row';
import { RowMenu } from '@/components/solo/row-menu';
import { SegmentedControl } from '@/components/solo/segmented-control';
import { Tile, TileActions, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { jsonRequest } from '@/lib/json-request';
import settings from '@/routes/settings';
import { router } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { type FormEvent, useEffect, useState } from 'react';
import { flushSync } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { copyAgentPrompt } from '../../tasks/components/task-page/task-header';
import type { IntakeFormRow, IntakeSettings, SenderRow } from '../types';
import styles from './forms-tab.module.css';
import { RowButton, TileButton } from './parts';
import tab from './tab.module.css';

const PORTS = [
    { value: '465', label: '465 · TLS' },
    { value: '587', label: '587 · STARTTLS' },
    { value: '2525', label: '2525 · STARTTLS' },
] as const;

const REASON: Record<string, string> = {
    tries: 'out of tries',
    no_recipient: 'no recipient',
    auth: 'login refused',
    host: 'mail server not allowed',
};

/** "2: brak odbiorcy 1, wyczerpane próby 1": each failure reason with its count. */
export function failureText(reasons: Record<string, number> | undefined, t: TFunction): string {
    return Object.entries(reasons ?? {})
        .map(([code, count]) => `${t(REASON[code] ?? 'another reason')} ${count}`)
        .join(', ');
}

/** The browser snippet: Turnstile's token with the lead-intake action, one key per enquiry kept across its retries only. */
export function browserSnippet(endpoint: string): string {
    return [
        '// Turnstile widget on the page: data-action="lead-intake"',
        '// One key per enquiry: the same when the visitor sends again after an error, a new one once it went through or the form changes',
        "form.addEventListener('input', () => sessionStorage.removeItem('lead-key'));",
        '',
        'async function send({ name, email, message }) {',
        "    const key = sessionStorage.getItem('lead-key') ?? crypto.randomUUID();",
        "    sessionStorage.setItem('lead-key', key);",
        `    const response = await fetch('${endpoint}', {`,
        "        method: 'POST',",
        "        headers: { 'Content-Type': 'application/json' },",
        "        body: JSON.stringify({ name, email, message, idempotency_key: key, 'cf-turnstile-response': turnstile.getResponse(widgetId) }),",
        '    });',
        "    if (response.status === 201 || response.status === 200) sessionStorage.removeItem('lead-key');",
        '',
        '    return response;',
        '}',
    ].join('\n');
}

/** The server example, with the webhook secret as a variable, never its value. */
export function webhookExample(endpoint: string): string {
    return [
        `curl -X POST ${endpoint} \\`,
        '  -H "Authorization: Bearer $WEBHOOK_SECRET" \\',
        "  -H 'Content-Type: application/json' \\",
        `  -d '{"name":"Anna Example","email":"anna@example.test","message":"A quote, please"}'`,
    ].join('\n');
}

function host(origin: string): string {
    try {
        return new URL(origin).host;
    } catch {
        return origin;
    }
}

/** Forgets a one-time secret when the page is left or comes back from the back-forward cache, so Back never shows it again. */
export function forgetOnPageHide(target: Pick<EventTarget, 'addEventListener' | 'removeEventListener'>, forget: () => void): () => void {
    const hide = () => forget();
    const show = (event: Event) => {
        if ((event as PageTransitionEvent).persisted) forget();
    };
    target.addEventListener('pagehide', hide);
    target.addEventListener('pageshow', show);

    return () => {
        target.removeEventListener('pagehide', hide);
        target.removeEventListener('pageshow', show);
    };
}

async function copy(text: string, t: TFunction) {
    if (await copyAgentPrompt(text)) {
        toast.success(t('Copied'));
    } else {
        toast.error(t('Could not copy to the clipboard.'));
    }
}

/** Figma: Settings · Forms (1383:30329, phone 1383:30625; a form 1384:30933, phone 1384:31267), the list or one form's page. */
export function FormsTab({ intake }: { intake: IntakeSettings }) {
    const form = intake.forms.find((row) => row.id === intake.form_id);

    return form === undefined ? <FormList intake={intake} /> : <FormPage form={form} intake={intake} />;
}

/** The side column: the sender beside the list, a form's secrets and notices beside its page. */
export function FormsSide({ intake }: { intake: IntakeSettings }) {
    const form = intake.forms.find((row) => row.id === intake.form_id);

    return form === undefined ? (
        <SenderTile sender={intake.sender} maxAge={intake.max_age_days} />
    ) : (
        <>
            <SecretsTile form={form} />
            <NoticesTile form={form} reasons={intake.failures[String(form.id)]} maxAge={intake.max_age_days} senderSet={intake.sender.configured} />
        </>
    );
}

function FormList({ intake }: { intake: IntakeSettings }) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);
    const failing = intake.forms.filter((form) => form.notices.failed > 0);

    return (
        <Tile kind="list">
            <TileHeader
                label={t('Forms')}
                count={intake.forms.length}
                end={<TileButton onClick={() => setCreating(true)}>{t('New form')}</TileButton>}
            />
            {intake.forms.length === 0 ? (
                <TileNote>{t('No forms yet. Add the first one and get the address and the code to paste on the site.')}</TileNote>
            ) : (
                <TileRows>
                    {intake.forms.map((form) => (
                        <Row
                            key={form.id}
                            title={form.name}
                            href={settings.forms.show(form.id)}
                            meta={[noticeLine(form, t), form.origins[0] ? host(form.origins[0]) : null].filter(Boolean).join(' · ')}
                            chip={<Chip status={form.active ? 'done' : 'waiting'}>{form.active ? t('form active') : t('switched off')}</Chip>}
                        />
                    ))}
                </TileRows>
            )}
            {failing.map((form) => (
                <TileInset key={form.id}>
                    <Notice kind="warn">
                        {t('notices_failed', {
                            count: form.notices.failed,
                            form: form.name,
                            reasons: failureText(intake.failures[String(form.id)], t),
                        })}
                    </Notice>
                    <SoloButtonLink href={settings.forms.show(form.id)} variant="quiet" size="control">
                        {t('Open the form')}
                    </SoloButtonLink>
                </TileInset>
            ))}
            <NewFormDialog open={creating} onOpenChange={setCreating} intake={intake} />
        </Tile>
    );
}

/** "1 czeka · 48 wysłanych": the notice counts that are not zero, failed first. */
/** The allowed site's error: the list rule answers under `origins`, a rule on one site under `origins.N`. */
export function originError(errors: Record<string, string>): string | undefined {
    return errors.origins ?? Object.entries(errors).find(([key]) => /^origins\.\d+$/.test(key))?.[1];
}

export function noticeLine(form: IntakeFormRow, t: TFunction): string {
    return [
        form.notices.failed > 0 ? t('failed_count', { count: form.notices.failed }) : null,
        form.notices.waiting > 0 ? t('waiting_count', { count: form.notices.waiting }) : null,
        form.notices.sent > 0 ? t('sent_count', { count: form.notices.sent }) : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function NewFormDialog({ open, onOpenChange, intake }: { open: boolean; onOpenChange: (open: boolean) => void; intake: IntakeSettings }) {
    const { t } = useTranslation();
    const empty = { name: '', pipeline: intake.pipelines[0]?.value ?? '', source: intake.sources[0]?.value ?? '', origin: '', notify_to: '' };
    const [draft, setDraft] = useState(empty);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setDraft(empty);
            setErrors({});
        }
    }
    const set = (key: keyof typeof empty) => (value: string) => setDraft((was) => ({ ...was, [key]: value }));

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        router.post(
            settings.forms.store().url,
            { name: draft.name, pipeline: draft.pipeline, source: draft.source, origins: [draft.origin], notify_to: draft.notify_to },
            { onError: setErrors, onSuccess: () => onOpenChange(false), onFinish: () => setProcessing(false) },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('New form')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={t('Add')}
            saving={processing}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {t('Add form')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Name')} htmlFor="form-name" error={errors.name}>
                <SoloInput
                    {...fieldControl('form-name', errors.name)}
                    value={draft.name}
                    onChange={(e) => set('name')(e.target.value)}
                    maxLength={120}
                    autoFocus
                />
            </SoloField>
            <SoloFieldRow>
                <PipelineField value={draft.pipeline} onChange={set('pipeline')} intake={intake} error={errors.pipeline} />
                <SourceField value={draft.source} onChange={set('source')} intake={intake} error={errors.source} />
            </SoloFieldRow>
            <SoloField label={t('Allowed site')} htmlFor="form-origin" error={originError(errors)}>
                <SoloInput
                    {...fieldControl('form-origin', originError(errors))}
                    value={draft.origin}
                    onChange={(e) => set('origin')(e.target.value)}
                    placeholder="https://www.example.test"
                    inputMode="url"
                />
            </SoloField>
            <p className={tab.fieldNote}>{t('origin_hint')}</p>
            <SoloField label={t('Notify')} htmlFor="form-notify" error={errors.notify_to}>
                <SoloInput
                    {...fieldControl('form-notify', errors.notify_to)}
                    type="email"
                    value={draft.notify_to}
                    onChange={(e) => set('notify_to')(e.target.value)}
                />
            </SoloField>
            <p className={tab.fieldNote}>{t('The address the new lead notice goes to.')}</p>
        </ResponsiveDialog>
    );
}

function PipelineField({
    value,
    onChange,
    intake,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    intake: IntakeSettings;
    error?: string;
}) {
    const { t } = useTranslation();

    return (
        <SoloField label={t('Pipeline')} htmlFor="form-pipeline" error={error}>
            <ResponsiveSelect
                label={t('Pipeline')}
                value={value}
                onValueChange={onChange}
                options={intake.pipelines}
                control={fieldControl('form-pipeline', error)}
            />
        </SoloField>
    );
}

function SourceField({
    value,
    onChange,
    intake,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    intake: IntakeSettings;
    error?: string;
}) {
    const { t } = useTranslation();

    return (
        <SoloField label={t('Source')} htmlFor="form-source" error={error}>
            <ResponsiveSelect
                label={t('Source')}
                value={value}
                onValueChange={onChange}
                options={intake.sources}
                control={fieldControl('form-source', error)}
            />
        </SoloField>
    );
}

/** One form's settings, saved together with Save, then what to paste on the site. */
function FormPage({ form, intake }: { form: IntakeFormRow; intake: IntakeSettings }) {
    const { t } = useTranslation();
    const initial = {
        name: form.name,
        pipeline: form.pipeline,
        source: form.source,
        notify_to: form.notify_to ?? '',
        active: form.active,
        origins: form.origins,
    };
    const [draft, setDraft] = useState(initial);
    const [origin, setOrigin] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [shown, setShown] = useState(form.id);
    if (form.id !== shown) {
        setShown(form.id);
        setDraft(initial);
        setErrors({});
    }
    const set =
        <K extends keyof typeof initial>(key: K) =>
        (value: (typeof initial)[K]) =>
            setDraft((was) => ({ ...was, [key]: value }));
    const addOrigin = () => {
        const next = origin.trim();
        if (next !== '' && !draft.origins.includes(next)) set('origins')([...draft.origins, next]);
        setOrigin('');
    };

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        router.put(settings.forms.update(form.id).url, draft, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => setErrors({}),
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <>
            <Tile kind="plain">
                <TileHeader label={t('Form')} />
                {form.active && !form.turnstile_secret_set && <Notice kind="warn">{t('turnstile_missing')}</Notice>}
                <form className={styles.form} onSubmit={submit} noValidate>
                    <SoloField label={t('Name')} htmlFor="form-name" error={errors.name}>
                        <SoloInput
                            {...fieldControl('form-name', errors.name)}
                            value={draft.name}
                            onChange={(e) => set('name')(e.target.value)}
                            maxLength={120}
                        />
                    </SoloField>
                    <div className={styles.endpoint}>
                        <span className={tab.fieldNote}>{t('Form address')}</span>
                        <div className={styles.copyRow}>
                            <code className={styles.url}>{form.endpoint}</code>
                            <SoloButton variant="secondary" size="control" onClick={() => copy(form.endpoint, t)}>
                                {t('Copy')}
                            </SoloButton>
                        </div>
                    </div>
                    <SoloFieldRow>
                        <PipelineField value={draft.pipeline} onChange={set('pipeline')} intake={intake} error={errors.pipeline} />
                        <SourceField value={draft.source} onChange={set('source')} intake={intake} error={errors.source} />
                    </SoloFieldRow>
                    <SoloField label={t('Notify')} htmlFor="form-notify" error={errors.notify_to}>
                        <SoloInput
                            {...fieldControl('form-notify', errors.notify_to)}
                            type="email"
                            value={draft.notify_to}
                            onChange={(e) => set('notify_to')(e.target.value)}
                        />
                    </SoloField>
                    <p className={tab.fieldNote}>{t('The address the new lead notice goes to.')}</p>
                    <Row
                        as="div"
                        rule={false}
                        title={t('The form takes submissions')}
                        action={
                            <Checkbox
                                variant="switch"
                                aria-label={t('The form takes submissions')}
                                checked={draft.active}
                                onCheckedChange={(checked) => set('active')(checked === true)}
                            />
                        }
                    />
                    <div className={styles.origins}>
                        <TileHeader label={t('Allowed sites')} count={draft.origins.length} as="h3" />
                        <TileRows>
                            {draft.origins.map((row) => (
                                <Row
                                    key={row}
                                    title={row}
                                    action={
                                        <RowButton onClick={() => set('origins')(draft.origins.filter((other) => other !== row))}>
                                            {t('Remove')}
                                        </RowButton>
                                    }
                                />
                            ))}
                        </TileRows>
                        <div className={tab.secret}>
                            <SoloInput
                                aria-label={t('New allowed site')}
                                {...fieldControl('form-origin', originError(errors))}
                                value={origin}
                                onChange={(e) => setOrigin(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        addOrigin();
                                    }
                                }}
                                placeholder="https://www.example.test"
                                inputMode="url"
                            />
                            <SoloButton variant="secondary" size="control" onClick={addOrigin}>
                                {t('Add')}
                            </SoloButton>
                        </div>
                        <p id="form-origin-hint" className={tab.fieldNote}>
                            {t('origin_hint_list')}
                        </p>
                        {originError(errors) && (
                            <p id="form-origin-error" className={styles.error} role="alert">
                                {originError(errors)}
                            </p>
                        )}
                    </div>
                    <TileActions>
                        <SoloButton type="submit" variant="ink" size="control" disabled={processing}>
                            {processing ? t('Saving…') : t('Save')}
                        </SoloButton>
                    </TileActions>
                </form>
            </Tile>
            <Tile kind="plain">
                <TileHeader label={t('Paste on the site')} />
                <p className={tab.fieldNote}>{t('A browser form: a Turnstile token with the lead-intake action, the same key when sent again')}</p>
                <pre className={styles.code}>{browserSnippet(form.endpoint)}</pre>
                <TileActions>
                    <SoloButton variant="secondary" size="control" onClick={() => copy(browserSnippet(form.endpoint), t)}>
                        {t('Copy the code')}
                    </SoloButton>
                </TileActions>
                <p className={tab.fieldNote}>{t('A webhook from a server (the secret in the Authorization header)')}</p>
                <pre className={styles.code}>{webhookExample(form.endpoint)}</pre>
                <TileActions>
                    <SoloButton variant="secondary" size="control" onClick={() => copy(webhookExample(form.endpoint), t)}>
                        {t('Copy the example')}
                    </SoloButton>
                </TileActions>
            </Tile>
        </>
    );
}

/** Figma: Secrets, each secret only as set or not: set, replaced or removed, never read back. */
function SecretsTile({ form }: { form: IntakeFormRow }) {
    const { t } = useTranslation();
    const [turnstile, setTurnstile] = useState(false);
    const [removing, setRemoving] = useState<'turnstile' | 'webhook' | null>(null);
    const [issued, setIssued] = useState<string | null>(null);
    const [rotating, setRotating] = useState(false);
    const [rotate, setRotate] = useState(false);

    // Synchronous, so the page the browser freezes for Back already holds no secret.
    useEffect(() => (issued === null ? undefined : forgetOnPageHide(window, () => flushSync(() => setIssued(null)))), [issued]);

    async function newWebhookSecret() {
        setRotating(true);
        try {
            const result = await jsonRequest<{ secret: string }>(settings.forms.webhook.store(form.id).url, 'POST');
            setIssued(result.secret);
            router.reload({ only: ['intake'] });
        } catch (failure) {
            toast.error(failure instanceof Error ? failure.message : t('The secret could not be created.'));
        } finally {
            setRotating(false);
            setRotate(false);
        }
    }

    const remove = () => {
        const route = removing === 'turnstile' ? settings.forms.turnstile.destroy(form.id) : settings.forms.webhook.destroy(form.id);
        router.delete(route.url, { preserveScroll: true, onFinish: () => setRemoving(null) });
    };

    return (
        <Tile kind="list">
            <TileHeader label={t('Secrets')} />
            <TileRows>
                <Row
                    title={t('Turnstile secret')}
                    meta={form.turnstile_secret_set ? t('secret set') : t('secret not set')}
                    action={<RowButton onClick={() => setTurnstile(true)}>{form.turnstile_secret_set ? t('Replace secret') : t('Set secret')}</RowButton>}
                    more={
                        form.turnstile_secret_set ? (
                            <RowMenu name={t('Turnstile secret')}>
                                <ActionMenuItem onSelect={() => setRemoving('turnstile')}>{t('Remove')}</ActionMenuItem>
                            </RowMenu>
                        ) : undefined
                    }
                />
                <Row
                    title={t('Webhook secret')}
                    meta={form.webhook_secret_set ? t('secret set') : t('secret not set')}
                    action={<RowButton onClick={() => (form.webhook_secret_set ? setRotate(true) : newWebhookSecret())}>{t('New secret')}</RowButton>}
                    more={
                        form.webhook_secret_set ? (
                            <RowMenu name={t('Webhook secret')}>
                                <ActionMenuItem onSelect={() => setRemoving('webhook')}>{t('Remove')}</ActionMenuItem>
                            </RowMenu>
                        ) : undefined
                    }
                />
            </TileRows>
            <TileNote>{t('secrets_note')}</TileNote>
            <TurnstileDialog form={form} open={turnstile} onOpenChange={setTurnstile} />
            <WebhookSecretDialog form={form} secret={issued} onClose={() => setIssued(null)} />
            <ConfirmDialog
                open={rotate}
                onOpenChange={setRotate}
                title={t('Replace the webhook secret?')}
                description={t('The old secret stops working at once; a webhook still sending it gets a 401.')}
                confirmLabel={t('New secret')}
                onConfirm={newWebhookSecret}
                confirmDisabled={rotating}
            />
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={removing === 'turnstile' ? t('Remove the Turnstile secret?') : t('Remove the webhook secret?')}
                description={
                    removing === 'turnstile'
                        ? t('Without it the form refuses every browser post.')
                        : t('Without it every request with Authorization is refused.')
                }
                confirmLabel={t('Remove')}
                onConfirm={remove}
            />
        </Tile>
    );
}

function TurnstileDialog({ form, open, onOpenChange }: { form: IntakeFormRow; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const [secret, setSecret] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        setSecret('');
        setErrors({});
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        router.put(
            settings.forms.turnstile.update(form.id).url,
            { turnstile_secret: secret },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => {
                    setSecret('');
                    onOpenChange(false);
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Turnstile secret')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saving={processing}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing || secret === ''}>
                        {t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Secret key')} htmlFor="turnstile-secret" error={errors.turnstile_secret}>
                <SoloInput
                    {...fieldControl('turnstile-secret', errors.turnstile_secret)}
                    type="password"
                    autoComplete="off"
                    value={secret}
                    onChange={(e) => setSecret(e.target.value)}
                    maxLength={255}
                    autoFocus
                />
            </SoloField>
            <p className={tab.fieldNote}>{t('turnstile_hint')}</p>
        </ResponsiveDialog>
    );
}

/** Figma 1384:31549: the new webhook secret, shown this once with a copy and the warning that the old one stopped. */
function WebhookSecretDialog({ form, secret, onClose }: { form: IntakeFormRow; secret: string | null; onClose: () => void }) {
    const { t } = useTranslation();

    return (
        <ResponsiveDialog
            open={secret !== null}
            onOpenChange={(open) => !open && onClose()}
            title={t('Webhook secret: {{form}}', { form: form.name })}
            description={t('Copy it now: it shows only this once. Put it in the system that sends the form, in the Authorization: Bearer header.')}
            onSubmit={(event) => {
                event.preventDefault();
                onClose();
            }}
            actions={(size) => (
                <SoloButton type="submit" variant="ink" size={size}>
                    {t('Done')}
                </SoloButton>
            )}
        >
            <div className={tab.secret}>
                <SoloInput readOnly value={secret ?? ''} aria-label={t('Webhook secret')} onFocus={(e) => e.target.select()} />
                <SoloButton variant="secondary" size="control" onClick={() => secret !== null && copy(secret, t)}>
                    {t('Copy')}
                </SoloButton>
            </div>
            <Notice kind="warn">{t('A new secret replaces the old one at once; a webhook with the old secret gets a 401.')}</Notice>
        </ResponsiveDialog>
    );
}

/** Figma: Lead notices, the form's notices by state with the failure reasons and Send the failed again. */
function NoticesTile({
    form,
    reasons,
    maxAge,
    senderSet,
}: {
    form: IntakeFormRow;
    reasons?: Record<string, number>;
    maxAge: number;
    senderSet: boolean;
}) {
    const { t } = useTranslation();
    const [retrying, setRetrying] = useState(false);
    const failed = form.notices.failed;

    return (
        <Tile kind="plain">
            <TileHeader label={t('Lead notices')} />
            {!senderSet && <Notice>{t('no_sender_note', { days: maxAge })}</Notice>}
            <dl className={tab.kv}>
                <KvRow layout="tile" label={t('Waiting notices')}>
                    {form.notices.waiting}
                </KvRow>
                <KvRow layout="tile" label={t('Failed notices')}>
                    {failed > 0 ? `${failed}: ${failureText(reasons, t)}` : failed}
                </KvRow>
                <KvRow layout="tile" label={t('Sent notices')}>
                    {form.notices.sent}
                </KvRow>
                <KvRow layout="tile" label={t('Skipped notices')}>
                    {t('skipped_line', { count: form.notices.skipped, days: maxAge })}
                </KvRow>
            </dl>
            <TileActions>
                <SoloButton
                    variant="quiet"
                    size="control"
                    disabled={failed === 0 || retrying}
                    onClick={() => {
                        setRetrying(true);
                        router.post(settings.forms.retry(form.id).url, {}, { preserveScroll: true, onFinish: () => setRetrying(false) });
                    }}
                >
                    {t('Send the failed again ({{count}})', { count: failed })}
                </SoloButton>
            </TileActions>
        </Tile>
    );
}

/** Figma: Mail sender, the account's own mailbox for lead notices with the password only as set. */
function SenderTile({ sender, maxAge }: { sender: SenderRow; maxAge: number }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const [testing, setTesting] = useState(false);

    return (
        <Tile kind="plain">
            <TileHeader
                label={t('Mail sender')}
                end={sender.configured ? <TileButton onClick={() => setEditing(true)}>{t('Edit')}</TileButton> : undefined}
            />
            {sender.configured ? (
                <>
                    <dl className={tab.kv}>
                        <KvRow layout="tile" label={t('Server')}>
                            {sender.host}
                        </KvRow>
                        <KvRow layout="tile" label={t('Port')}>
                            {PORTS.find((port) => Number(port.value) === sender.port)?.label ?? sender.port}
                        </KvRow>
                        <KvRow layout="tile" label={t('SMTP login')}>
                            {sender.username}
                        </KvRow>
                        <KvRow layout="tile" label={t('Password')} tone={sender.password_readable ? undefined : 'over'}>
                            {!sender.password_set ? t('password not set') : sender.password_readable ? t('password set') : t('unreadable, set it again')}
                        </KvRow>
                        <KvRow layout="tile" label={t('From')}>
                            {sender.from_name ? `${sender.from_name} <${sender.from_address}>` : sender.from_address}
                        </KvRow>
                    </dl>
                    <TileActions>
                        <SoloButton
                            variant="secondary"
                            size="control"
                            disabled={testing}
                            onClick={() => {
                                setTesting(true);
                                router.post(settings.sender.test().url, {}, { preserveScroll: true, onFinish: () => setTesting(false) });
                            }}
                        >
                            {t('Send a test')}
                        </SoloButton>
                    </TileActions>
                    <TileNote>{t('Sends only the new lead notices. The password cannot be read back, only replaced.')}</TileNote>
                </>
            ) : (
                <>
                    <Notice>{t('no_sender_note', { days: maxAge })}</Notice>
                    <TileActions>
                        <SoloButton variant="ink" size="control" aria-haspopup="dialog" onClick={() => setEditing(true)}>
                            {t('Set the sender')}
                        </SoloButton>
                    </TileActions>
                </>
            )}
            <SenderDialog sender={sender} open={editing} onOpenChange={setEditing} />
        </Tile>
    );
}

function SenderDialog({ sender, open, onOpenChange }: { sender: SenderRow; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const initial = {
        host: sender.configured ? (sender.host ?? '') : '',
        port: sender.configured && sender.port !== null ? String(sender.port) : '587',
        username: sender.configured ? (sender.username ?? '') : '',
        password: '',
        from_address: sender.configured ? (sender.from_address ?? '') : '',
        from_name: sender.configured ? (sender.from_name ?? '') : '',
    };
    const [draft, setDraft] = useState(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setDraft(initial);
            setErrors({});
        }
    }
    const set = (key: keyof typeof initial) => (value: string) => setDraft((was) => ({ ...was, [key]: value }));
    const passwordSet = sender.configured && sender.password_set;

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        router.put(
            settings.sender.update().url,
            { ...draft, port: Number(draft.port) },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => {
                    setDraft((was) => ({ ...was, password: '' }));
                    onOpenChange(false);
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Mail sender')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saving={processing}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {t('Save')}
                    </SoloButton>
                </>
            )}
        >
            {errors.sender && (
                <Notice kind="warn" role="alert">
                    {errors.sender}
                </Notice>
            )}
            <SoloField label={t('SMTP server')} htmlFor="sender-host" error={errors.host}>
                <SoloInput
                    {...fieldControl('sender-host', errors.host)}
                    value={draft.host}
                    onChange={(e) => set('host')(e.target.value)}
                    placeholder="smtp.example.test"
                    autoFocus
                />
            </SoloField>
            <p className={tab.fieldNote}>{t('The server certificate must be issued for this name.')}</p>
            <div className={styles.field}>
                <span className={tab.fieldNote}>{t('Port')}</span>
                <SegmentedControl
                    label={t('Port')}
                    value={draft.port}
                    onChange={set('port')}
                    options={PORTS.map((port) => ({ value: port.value, label: port.label }))}
                />
            </div>
            <SoloField label={t('SMTP login')} htmlFor="sender-username" error={errors.username}>
                <SoloInput
                    {...fieldControl('sender-username', errors.username)}
                    value={draft.username}
                    onChange={(e) => set('username')(e.target.value)}
                    autoComplete="off"
                />
            </SoloField>
            <SoloField label={t('Password')} htmlFor="sender-password" error={errors.password}>
                <SoloInput
                    {...fieldControl('sender-password', errors.password)}
                    type="password"
                    autoComplete="new-password"
                    value={draft.password}
                    onChange={(e) => set('password')(e.target.value)}
                    placeholder={passwordSet ? t('Leave empty to keep the current one') : undefined}
                />
            </SoloField>
            {passwordSet && <p className={tab.fieldNote}>{t('The password is set. It cannot be read back, only replaced.')}</p>}
            <SoloFieldRow>
                <SoloField label={t('From address')} htmlFor="sender-from" error={errors.from_address}>
                    <SoloInput
                        {...fieldControl('sender-from', errors.from_address)}
                        type="email"
                        value={draft.from_address}
                        onChange={(e) => set('from_address')(e.target.value)}
                    />
                </SoloField>
                <SoloField label={t('From name')} htmlFor="sender-from-name" error={errors.from_name}>
                    <SoloInput
                        {...fieldControl('sender-from-name', errors.from_name)}
                        value={draft.from_name}
                        onChange={(e) => set('from_name')(e.target.value)}
                        maxLength={100}
                    />
                </SoloField>
            </SoloFieldRow>
        </ResponsiveDialog>
    );
}
