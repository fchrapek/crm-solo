import { fieldControl, SoloField, SoloInput } from '@/components/solo/field';
import { ListRow } from '@/components/solo/list-row';
import { SectionAction, SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { jsonRequest } from '@/lib/json-request';
import agentTokens from '@/routes/agent-tokens';
import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { copyAgentPrompt } from '../../tasks/components/task-page/task-header';
import type { AgentTokenRow } from '../types';
import { useStampLabel } from './format';
import styles from './tab.module.css';

const READ = 'read';

/** Days left at which the expiry turns to the accent colour. */
const SOON_DAYS = 14;

const EXPIRY = [
    { value: '30', label: '30 days' },
    { value: '90', label: '90 days' },
    { value: '365', label: '365 days' },
    { value: 'never', label: 'Never' },
] as const;

/**
 * Figma: Ustawienia / Agenci (206:5097), phones 473:8933. The owner's agent
 * tokens: the plain token arrives once, in the create response, and is never
 * sent again; the list shows names, abilities, last use and expiry only.
 */
export function AgentsTab({ tokens, abilities, today, demo }: { tokens: AgentTokenRow[]; abilities: string[]; today: string; demo: boolean }) {
    const { t } = useTranslation();
    const stamp = useStampLabel(today);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<AgentTokenRow | null>(null);
    const [revoking, setRevoking] = useState<AgentTokenRow | null>(null);

    const used = (token: AgentTokenRow) => (token.last_used === null ? t('never used') : t('used {{when}}', { when: stamp(token.last_used) }));
    const expiry = (token: AgentTokenRow) =>
        token.expires_in_days === null ? t('no expiry') : t('expires in {{days}}', { days: t('day_count', { count: token.expires_in_days }) });
    const soon = (token: AgentTokenRow) => token.expires_in_days !== null && token.expires_in_days <= SOON_DAYS;

    return (
        <section className={styles.section}>
            <SectionHead
                kind="body"
                label={`${t('Tokens')} · ${tokens.length}`}
                action={demo ? undefined : <SectionAction onClick={() => setCreating(true)}>{t('New token')}</SectionAction>}
            />
            {demo && <p className={styles.note}>{t('Agent tokens are not part of the demo.')}</p>}
            {!demo && tokens.length === 0 && (
                <p className={`${styles.note} ${styles.emptyBlock}`}>
                    {t('No agent tokens yet. A token lets Claude Code or Codex reach this CRM with the crm command or MCP.')}
                </p>
            )}
            <ul className={`${styles.list} ${styles.phone}`}>
                {tokens.map((token) => (
                    <li key={token.id}>
                        <ListRow
                            title={token.name}
                            sub={[...token.abilities, token.last_used === null ? null : used(token)].filter(Boolean).join(' · ')}
                            value={
                                token.expires_in_days === null ? undefined : (
                                    <span className={soon(token) ? styles.accent : undefined}>
                                        {t('day_count', { count: token.expires_in_days })}
                                    </span>
                                )
                            }
                            onClick={() => setEditing(token)}
                        />
                    </li>
                ))}
            </ul>
            <ul className={`${styles.list} ${styles.wide}`}>
                {tokens.map((token) => (
                    <li key={token.id} className={styles.row}>
                        <span className={styles.rowText}>
                            <button type="button" className={styles.rowTitle} onClick={() => setEditing(token)}>
                                {token.name}
                            </button>
                            <span className={styles.mono}>{token.abilities.join(' · ')}</span>
                        </span>
                        <span className={styles.rowEnd}>
                            {used(token)}
                            <strong data-tone={soon(token) ? 'accent' : undefined}>{expiry(token)}</strong>
                            <SectionAction onClick={() => setRevoking(token)}>{t('Revoke')}</SectionAction>
                        </span>
                    </li>
                ))}
            </ul>

            <CreateTokenDialog open={creating} abilities={abilities} onClose={() => setCreating(false)} />
            <EditTokenDialog
                token={editing}
                abilities={abilities}
                onClose={() => setEditing(null)}
                onRevoke={(token) => {
                    setEditing(null);
                    setRevoking(token);
                }}
            />
            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(value) => !value && setRevoking(null)}
                title={t('Revoke token')}
                description={t('{{name}} stops working at once. Whatever uses it needs a new token.', { name: revoking?.name ?? '' })}
                confirmLabel={t('Revoke')}
                variant="destructive"
                onConfirm={() => {
                    if (revoking !== null) router.delete(agentTokens.destroy(revoking.id).url, { preserveScroll: true });
                    setRevoking(null);
                }}
            />
        </section>
    );
}

function AbilityChecks({
    abilities,
    value,
    onChange,
    disabled,
}: {
    abilities: string[];
    value: string[];
    onChange: (value: string[]) => void;
    disabled: boolean;
}) {
    const { t } = useTranslation();

    return (
        <fieldset className={styles.checks}>
            <legend>{t('Abilities')}</legend>
            {abilities.map((ability) => (
                <label key={ability} className={styles.check}>
                    <input
                        type="checkbox"
                        checked={ability === READ || value.includes(ability)}
                        disabled={disabled || ability === READ}
                        onChange={(e) => onChange(e.target.checked ? [...value, ability] : value.filter((held) => held !== ability))}
                    />
                    <span className={styles.mono}>{ability}</span>
                </label>
            ))}
        </fieldset>
    );
}

const NEW = { name: '', abilities: [READ], expires: '90', current_password: '' };

/** Nowy token: the form, then the token itself, shown this once with Kopiuj. Closing forgets it. */
function CreateTokenDialog({ open, abilities, onClose }: { open: boolean; abilities: string[]; onClose: () => void }) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState(NEW);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [issued, setIssued] = useState<{ name: string; token: string } | null>(null);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        setDraft(NEW);
        setError(null);
        setIssued(null);
    }

    // While the token is being created the dialog stays open, so its answer always lands where it is shown.
    const close = () => {
        if (processing) return;
        const reload = issued !== null;
        setIssued(null);
        onClose();
        if (reload) router.reload({ only: ['tokens'] });
    };

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (issued !== null) return close();
        if (processing) return;
        setProcessing(true);
        setError(null);
        try {
            const result = await jsonRequest<{ name: string; token: string }>(agentTokens.store().url, 'POST', {
                ...draft,
                abilities: [READ, ...draft.abilities.filter((ability) => ability !== READ)],
            });
            setDraft(NEW);
            setIssued({ name: result.name, token: result.token });
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : t('The token could not be created.'));
        } finally {
            setProcessing(false);
        }
    }

    async function copy() {
        if (issued !== null && (await copyAgentPrompt(issued.token))) {
            toast.success(t('Copied'));
        } else {
            toast.error(t('Could not copy to the clipboard.'));
        }
    }

    if (issued !== null) {
        return (
            <ResponsiveDialog
                open={open}
                onOpenChange={(value) => !value && close()}
                title={t('Token for {{name}}', { name: issued.name })}
                onSubmit={submit}
                sheetAction="save"
                fields
                saveLabel={t('Done')}
                footerClassName={styles.dialogActions}
                actions={(size) => (
                    <SoloButton type="submit" variant="ink" size={size}>
                        {t('Done')}
                    </SoloButton>
                )}
            >
                <p className={styles.message} data-tone="warn">
                    {t('Copy it now: it is shown this once and never again. Store it where the agent reads it, such as ~/.config/crm-solo/.')}
                </p>
                <SoloField label={t('Token')} htmlFor="agent-token-value">
                    <div className={styles.secret}>
                        <SoloInput id="agent-token-value" value={issued.token} readOnly onFocus={(e) => e.currentTarget.select()} />
                        <SoloButton variant="outline" size="md" onClick={copy}>
                            {t('Copy')}
                        </SoloButton>
                    </div>
                </SoloField>
            </ResponsiveDialog>
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={(value) => !value && close()}
            title={t('New agent token')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={t('Create')}
            saving={processing}
            footerClassName={styles.dialogActions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={close} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Create token')}
                    </SoloButton>
                </>
            )}
        >
            {error !== null && (
                <p className={styles.message} data-tone="error" role="alert">
                    {error}
                </p>
            )}
            <SoloField label={t('Name')} htmlFor="agent-token-name">
                <SoloInput
                    id="agent-token-name"
                    value={draft.name}
                    placeholder={t('e.g. claude-code macbook')}
                    onChange={(e) => setDraft((was) => ({ ...was, name: e.target.value }))}
                    required
                    maxLength={120}
                    autoFocus
                    disabled={processing}
                />
            </SoloField>
            <AbilityChecks
                abilities={abilities}
                value={draft.abilities}
                onChange={(value) => setDraft((was) => ({ ...was, abilities: value }))}
                disabled={processing}
            />
            <SoloField label={t('Expires')} htmlFor="agent-token-expires">
                <ResponsiveSelect
                    label={t('Expires')}
                    value={draft.expires}
                    onValueChange={(value) => setDraft((was) => ({ ...was, expires: value }))}
                    options={EXPIRY.map((option) => ({ value: option.value, label: t(option.label) }))}
                    disabled={processing}
                    control={fieldControl('agent-token-expires')}
                />
            </SoloField>
            <SoloField label={t('Your password')} htmlFor="agent-token-password">
                <SoloInput
                    id="agent-token-password"
                    type="password"
                    value={draft.current_password}
                    onChange={(e) => setDraft((was) => ({ ...was, current_password: e.target.value }))}
                    required
                    autoComplete="current-password"
                    disabled={processing}
                />
            </SoloField>
        </ResponsiveDialog>
    );
}

/** Zmień uprawnienia (read stays), and on phones Unieważnij at the end of the sheet. */
function EditTokenDialog({
    token,
    abilities,
    onClose,
    onRevoke,
}: {
    token: AgentTokenRow | null;
    abilities: string[];
    onClose: () => void;
    onRevoke: (token: AgentTokenRow) => void;
}) {
    const { t } = useTranslation();
    const [held, setHeld] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [shown, setShown] = useState(token);
    if (token?.id !== shown?.id) {
        setShown(token);
        if (token !== null) {
            setHeld(token.abilities);
            setErrors({});
        }
    }
    const current = token ?? shown;
    if (current === null) return null;

    function submit(event: FormEvent) {
        event.preventDefault();
        if (current === null) return;
        setProcessing(true);
        router.patch(
            agentTokens.update(current.id).url,
            { abilities: [READ, ...held.filter((ability) => ability !== READ)] },
            { preserveScroll: true, onSuccess: onClose, onError: (errs) => setErrors(errs), onFinish: () => setProcessing(false) },
        );
    }

    return (
        <ResponsiveDialog
            open={token !== null}
            onOpenChange={(value) => !value && onClose()}
            title={current.name}
            onSubmit={submit}
            sheetAction="save"
            fields
            saving={processing}
            footerClassName={styles.dialogActions}
            actions={(size) => (
                <>
                    <SoloButton variant="text" size={size} data-start="" onClick={() => onRevoke(current)} disabled={processing}>
                        {t('Revoke token')}
                    </SoloButton>
                    <SoloButton variant="pill" onClick={onClose} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            {current.user !== null && <p className={styles.note}>{t('Acts as {{name}}', { name: current.user })}</p>}
            {errors.abilities && (
                <p className={styles.message} data-tone="error" role="alert">
                    {errors.abilities}
                </p>
            )}
            <AbilityChecks abilities={abilities} value={held} onChange={setHeld} disabled={processing} />
            <div className={styles.sheetEnd}>
                <SoloButton variant="text" size="touch" onClick={() => onRevoke(current)} disabled={processing}>
                    {t('Revoke token')}
                </SoloButton>
            </div>
        </ResponsiveDialog>
    );
}
