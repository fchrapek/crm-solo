import { fieldControl, SoloField, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import clients from '@/routes/clients';
import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './client-fields.module.css';
import type { CardField, ClientCard } from './types';

const NONE = '__none__';

type Draft = { values: Record<string, string | string[]>; assignee: string; optOut: boolean };

const draftOf = (card: ClientCard): Draft => ({
    values: Object.fromEntries(card.fields.map((field) => [field.key, field.input])),
    assignee: card.assignee === null ? NONE : String(card.assignee.id),
    optOut: card.sms_opt_out,
});

const same = (a: string | string[], b: string | string[]) => JSON.stringify(a) === JSON.stringify(b);

/** Figma: Dialog / Pola klienta · Edytuj (601:21054). Every active field, the assignee and the opt-out; only what changed is sent. */
export function ClientFieldsDialog({
    clientId,
    card,
    open,
    onOpenChange,
}: {
    clientId: number;
    card: ClientCard;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<Draft>(() => draftOf(card));
    // What the form opened with: only a value the person changed from it is sent, whatever the props do meanwhile.
    const [opened, setOpened] = useState<Draft>(() => draftOf(card));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setDraft(draftOf(card));
            setOpened(draftOf(card));
            setErrors({});
        }
    }

    const set = (key: string, value: string | string[]) => setDraft((was) => ({ ...was, values: { ...was.values, [key]: value } }));

    function submit(event: FormEvent) {
        event.preventDefault();
        const changed = Object.fromEntries(
            card.fields
                .filter((field) => !field.archived && !same(opened.values[field.key] ?? '', draft.values[field.key] ?? ''))
                .map((field) => [field.key, draft.values[field.key]]),
        );
        const cleared = card.fields.filter(
            (field) => field.required && field.key in changed && same(changed[field.key], field.type === 'multiselect' ? [] : ''),
        );
        if (cleared.length > 0) {
            setErrors(Object.fromEntries(cleared.map((field) => [field.key, t('{{field}} is required.', { field: field.label })])));

            return;
        }
        const assignee = draft.assignee === NONE ? null : Number(draft.assignee);
        setProcessing(true);
        router.patch(
            clients.fields.update(clientId).url,
            {
                values: changed,
                ...(draft.assignee !== opened.assignee ? { assignee_id: assignee } : {}),
                ...(draft.optOut !== opened.optOut ? { sms_opt_out: draft.optOut } : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errs) => setErrors(errs),
                onFinish: () => setProcessing(false),
            },
        );
    }

    const generalError = errors.form ?? errors.values ?? errors.assignee_id;

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Client fields')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={t('Save')}
            saving={processing}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            {generalError && <p className={styles.error}>{generalError}</p>}
            <div className={styles.grid}>
                <SoloField label={t('Assignee')} htmlFor="card-assignee">
                    <ResponsiveSelect
                        label={t('Assignee')}
                        value={draft.assignee}
                        onValueChange={(value) => setDraft((was) => ({ ...was, assignee: value }))}
                        options={[{ value: NONE, label: '—' }, ...card.users.map((user) => ({ value: String(user.id), label: user.name }))]}
                        control={fieldControl('card-assignee')}
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('Send SMS')} htmlFor="card-opt-out">
                    <ResponsiveSelect
                        label={t('Send SMS')}
                        value={draft.optOut ? 'no' : 'yes'}
                        onValueChange={(value) => setDraft((was) => ({ ...was, optOut: value === 'no' }))}
                        options={[
                            { value: 'yes', label: t('Yes') },
                            { value: 'no', label: t('No, send nothing') },
                        ]}
                        control={fieldControl('card-opt-out')}
                        disabled={processing}
                    />
                </SoloField>
                {card.fields.map((field) => (
                    <FieldControl
                        key={field.key}
                        field={field}
                        value={draft.values[field.key] ?? ''}
                        onChange={(value) => set(field.key, value)}
                        error={errors[field.key] ?? errors[`values.${field.key}`]}
                        disabled={processing}
                    />
                ))}
            </div>
        </ResponsiveDialog>
    );
}

function FieldControl({
    field,
    value,
    onChange,
    error,
    disabled,
}: {
    field: CardField;
    value: string | string[];
    onChange: (value: string | string[]) => void;
    error?: string;
    disabled: boolean;
}) {
    const { t } = useTranslation();
    const id = `card-${field.key}`;
    const label = field.archived ? t('{{label}} (archived)', { label: field.label }) : field.required ? `${field.label} *` : field.label;
    const wide = field.type === 'multiselect' || field.type === 'textarea' || field.type === 'url';
    const off = disabled || field.archived;
    const text = typeof value === 'string' ? value : '';

    if (field.type === 'multiselect') {
        const chosen = Array.isArray(value) ? value : [];

        return (
            <fieldset className={`${styles.checks} ${styles.span}`} aria-invalid={error ? true : undefined}>
                <legend>{label}</legend>
                <div className={styles.checkRow}>
                    {field.options.map((option) => (
                        <label key={option.key} className={styles.check}>
                            <input
                                type="checkbox"
                                checked={chosen.includes(option.key)}
                                disabled={off}
                                onChange={(e) => onChange(e.target.checked ? [...chosen, option.key] : chosen.filter((key) => key !== option.key))}
                            />
                            {option.label}
                        </label>
                    ))}
                </div>
                {error && <p className={styles.error}>{error}</p>}
            </fieldset>
        );
    }

    return (
        <SoloField label={label} htmlFor={id} error={error} className={wide ? styles.span : undefined}>
            {field.type === 'select' ? (
                <ResponsiveSelect
                    label={field.label}
                    value={text === '' ? NONE : text}
                    onValueChange={(next) => onChange(next === NONE ? '' : next)}
                    options={[
                        ...(field.required ? [] : [{ value: NONE, label: '—' }]),
                        ...field.options.map((option) => ({ value: option.key, label: option.label })),
                    ]}
                    control={fieldControl(id, error)}
                    disabled={off}
                />
            ) : field.type === 'textarea' ? (
                <SoloTextarea
                    {...fieldControl(id, error)}
                    rows={3}
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={off}
                    maxLength={5000}
                />
            ) : (
                <SoloInput
                    {...fieldControl(id, error)}
                    type={field.type === 'date' ? 'date' : field.type === 'datetime' ? 'datetime-local' : field.type === 'url' ? 'url' : 'text'}
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={off}
                    maxLength={field.type === 'url' ? 2048 : 255}
                />
            )}
        </SoloField>
    );
}
