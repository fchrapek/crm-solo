import { fieldControl, SoloField, SoloFieldRow, SoloInput } from '@/components/solo/field';
import { IconButton } from '@/components/solo/icon-button';
import { ListRow } from '@/components/solo/list-row';
import { SectionAction, SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { InfoHint } from '@/components/ui/info-hint';
import { freeKey } from '@/lib/field-key';
import settings from '@/routes/settings';
import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { FieldDefinition, FieldOption, FieldType } from '../types';
import styles from './tab.module.css';

export const FIELD_TYPE_LABEL: Record<FieldType, string> = {
    text: 'Text',
    textarea: 'Long text',
    select: 'Choice',
    multiselect: 'Multiple choice',
    date: 'Date',
    datetime: 'Date and time',
    url: 'Link',
};

const hasOptions = (type: FieldType) => type === 'select' || type === 'multiselect';

/** Figma: Ustawienia / Pola klienta (597:2), phones 598:18217. The built-in fields, the account's fields, then the archived ones. */
export function ClientFieldsTab({ fields, reserved }: { fields: FieldDefinition[]; reserved: string[] }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<FieldDefinition | 'new' | null>(null);
    const active = fields.filter((field) => !field.archived);
    const archived = fields.filter((field) => field.archived);
    const flags = (field: FieldDefinition) =>
        [field.on_overview ? t('key field') : null, field.required ? t('required') : null].filter(Boolean).join(' · ');

    const rows = (list: FieldDefinition[]) => (
        <>
            <ul className={`${styles.list} ${styles.phone}`}>
                {list.map((field) => (
                    <li key={field.key}>
                        <ListRow title={field.label} value={flags(field) || undefined} onClick={() => setEditing(field)} />
                    </li>
                ))}
            </ul>
            <ul className={`${styles.list} ${styles.wide}`}>
                {list.map((field) => (
                    <li key={field.key} className={styles.row}>
                        <span className={styles.rowText}>
                            <button
                                type="button"
                                className={styles.rowTitle}
                                data-tone={field.archived ? 'muted' : undefined}
                                onClick={() => setEditing(field)}
                            >
                                {field.label}
                            </button>
                        </span>
                        <span className={styles.rowEnd}>
                            {field.on_overview && <span>{t('key field')}</span>}
                            {field.required && <strong>{t('required')}</strong>}
                            <SectionAction onClick={() => setEditing(field)}>{t('Edit')}</SectionAction>
                        </span>
                    </li>
                ))}
            </ul>
        </>
    );

    return (
        <>
            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={
                        <>
                            {t('Built in')} · 2 <InfoHint>{t('Every client has these two; they cannot be removed.')}</InfoHint>
                        </>
                    }
                />
                <dl className={styles.list}>
                    <BuiltIn label={t('Assignee')} hint={t('One user of the account per client.')} />
                    <BuiltIn label={t('Send SMS')} hint={t('Yes or no. A client set to no gets no text messages.')} />
                </dl>
            </section>

            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={`${t('Client fields')} · ${active.length}`}
                    action={<SectionAction onClick={() => setEditing('new')}>{t('New field')}</SectionAction>}
                />
                {active.length === 0 && (
                    <p className={styles.note}>{t('No fields yet. A field can hold a status, a date or a choice for every client.')}</p>
                )}
                {rows(active)}
            </section>

            {archived.length > 0 && (
                <section className={styles.section}>
                    <SectionHead
                        kind="body"
                        label={
                            <>
                                {t('Archived')} · {archived.length}{' '}
                                <InfoHint>{t('Archived fields keep their values but take no new ones.')}</InfoHint>
                            </>
                        }
                    />
                    {rows(archived)}
                </section>
            )}

            <FieldDialog field={editing} taken={[...reserved, ...fields.map((field) => field.key)]} onClose={() => setEditing(null)} />
        </>
    );
}

function BuiltIn({ label, hint }: { label: string; hint: string }) {
    return (
        <div className={styles.row}>
            <span className={styles.rowText}>
                <span className={styles.rowTitle}>
                    {label} <InfoHint>{hint}</InfoHint>
                </span>
            </span>
        </div>
    );
}

type Draft = {
    label: string;
    type: FieldType;
    options: (FieldOption & { stored: boolean })[];
    required: boolean;
    on_overview: boolean;
};

const BLANK_OPTION = { key: '', label: '', stored: false };
const EMPTY: Draft = { label: '', type: 'select', options: [BLANK_OPTION], required: false, on_overview: false };

/**
 * Figma: Dialog / Edytuj pole (598:52), sheet 598:18709. Keys come from the labels and stay hidden:
 * the field's and each option's key are fixed once saved, their labels change.
 */
function FieldDialog({ field, taken, onClose }: { field: FieldDefinition | 'new' | null; taken: string[]; onClose: () => void }) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const [shown, setShown] = useState(field);
    if (field !== shown) {
        setShown(field);
        if (field !== null) {
            setDraft(
                field === 'new'
                    ? EMPTY
                    : {
                          label: field.label,
                          type: field.type,
                          options: field.options.map((option) => ({ ...option, stored: true })),
                          required: field.required,
                          on_overview: field.on_overview,
                      },
            );
            setErrors({});
        }
    }

    const current = field ?? shown;
    const creating = current === 'new';
    const existing = current !== null && current !== 'new' ? current : null;
    const update = (patch: Partial<Draft>) => {
        setDraft((was) => ({ ...was, ...patch }));
        setErrors((was) => Object.fromEntries(Object.entries(was).filter(([name]) => !(name in patch))));
    };
    const yesNo = [
        { value: 'yes', label: t('Yes') },
        { value: 'no', label: t('No') },
    ];

    const setOptions = (options: Draft['options']) => update({ options });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (current === null) return;
        const keys = draft.options.filter((option) => option.stored).map((option) => option.key);
        const options = draft.options
            .filter((option) => option.label.trim() !== '')
            .map((option) => {
                const key = option.stored ? option.key : freeKey(option.label, keys, 'opcja');
                keys.push(key);

                return { key, label: option.label.trim() };
            });
        if (hasOptions(draft.type) && options.length === 0) {
            setErrors({ options: t('A choice field needs at least one option.') });

            return;
        }
        setProcessing(true);
        router.put(
            settings.clientFields.update(existing?.key ?? freeKey(draft.label, taken, 'pole')).url,
            {
                label: draft.label,
                ...(creating ? { type: draft.type } : {}),
                ...(hasOptions(draft.type) ? { options } : {}),
                required: draft.required,
                on_overview: draft.on_overview,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (errs) => setErrors(errs),
                onFinish: () => setProcessing(false),
            },
        );
    }

    function archive(archived: boolean) {
        if (existing === null) return;
        setProcessing(true);
        router.put(
            settings.clientFields.update(existing.key).url,
            { archived },
            { preserveScroll: true, onSuccess: () => onClose(), onFinish: () => setProcessing(false) },
        );
    }

    const archiveAction = (size: 'md' | 'touch') =>
        existing === null ? null : (
            <SoloButton variant="text" size={size} data-start="" disabled={processing} onClick={() => archive(!existing.archived)}>
                {existing.archived ? t('Restore field') : t('Archive field')}
            </SoloButton>
        );

    return (
        <ResponsiveDialog
            open={field !== null}
            onOpenChange={(open) => !open && onClose()}
            title={creating ? t('New field') : t('Edit field')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={t('Save')}
            saving={processing}
            footerClassName={styles.dialogActions}
            actions={(size) => (
                <>
                    {archiveAction(size)}
                    <SoloButton variant="pill" onClick={onClose} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            {(errors.form ?? errors.key) && (
                <p className={styles.message} data-tone="error">
                    {errors.form ?? errors.key}
                </p>
            )}
            <SoloFieldRow>
                <SoloField label={t('Label')} htmlFor="field-label" error={errors.label}>
                    <SoloInput
                        {...fieldControl('field-label', errors.label)}
                        value={draft.label}
                        onChange={(e) => update({ label: e.target.value })}
                        required
                        maxLength={100}
                        autoFocus={creating}
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={creating ? t('Type') : t('Type (fixed)')} htmlFor="field-type">
                    {creating ? (
                        <ResponsiveSelect
                            label={t('Type')}
                            value={draft.type}
                            onValueChange={(value) => update({ type: value as FieldType })}
                            options={(Object.keys(FIELD_TYPE_LABEL) as FieldType[]).map((type) => ({
                                value: type,
                                label: t(FIELD_TYPE_LABEL[type]),
                            }))}
                            control={fieldControl('field-type')}
                            disabled={processing}
                        />
                    ) : (
                        <SoloInput id="field-type" value={t(FIELD_TYPE_LABEL[draft.type])} disabled readOnly />
                    )}
                </SoloField>
            </SoloFieldRow>

            {hasOptions(draft.type) && (
                <div className={styles.options}>
                    <SectionHead
                        kind="body"
                        as="span"
                        label={
                            <>
                                {t('Options')} · {draft.options.length}{' '}
                                <InfoHint>{t('Rename an option at any time. A saved option stays, so clients who have it keep it.')}</InfoHint>
                            </>
                        }
                        action={<SectionAction onClick={() => setOptions([...draft.options, BLANK_OPTION])}>{t('Add option')}</SectionAction>}
                    />
                    {draft.options.map((option, index) => (
                        <div key={index} className={styles.optionRow}>
                            <SoloInput
                                aria-label={t('Option {{n}}', { n: index + 1 })}
                                placeholder={t('Option {{n}}', { n: index + 1 })}
                                value={option.label}
                                onChange={(e) => setOptions(draft.options.map((was, at) => (at === index ? { ...was, label: e.target.value } : was)))}
                                maxLength={100}
                                disabled={processing}
                            />
                            {!option.stored && (
                                <IconButton
                                    icon="close"
                                    size="sm"
                                    label={t('Remove option')}
                                    onClick={() => setOptions(draft.options.filter((_, at) => at !== index))}
                                    disabled={processing}
                                />
                            )}
                        </div>
                    ))}
                    {errors.options && (
                        <p className={styles.message} data-tone="error">
                            {errors.options}
                        </p>
                    )}
                </div>
            )}

            <SoloFieldRow>
                <SoloField label={t('Required')} htmlFor="field-required">
                    <ResponsiveSelect
                        label={t('Required')}
                        value={draft.required ? 'yes' : 'no'}
                        onValueChange={(value) => update({ required: value === 'yes' })}
                        options={yesNo}
                        control={fieldControl('field-required')}
                        disabled={processing}
                    />
                </SoloField>
                <SoloField
                    label={
                        <>
                            {t('Key field')} <InfoHint>{t('Shown on the client Overview and as a column on the Clients list.')}</InfoHint>
                        </>
                    }
                    htmlFor="field-overview"
                >
                    <ResponsiveSelect
                        label={t('Key field')}
                        value={draft.on_overview ? 'yes' : 'no'}
                        onValueChange={(value) => update({ on_overview: value === 'yes' })}
                        options={yesNo}
                        control={fieldControl('field-overview')}
                        disabled={processing}
                    />
                </SoloField>
            </SoloFieldRow>
            {existing !== null && <div className={styles.sheetEnd}>{archiveAction('touch')}</div>}
        </ResponsiveDialog>
    );
}
