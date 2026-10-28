import { store, update } from '@/actions/App/Http/Controllers/ContactsController';
import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { SectionToggle } from '@/components/solo/section-toggle';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './dialog-form.module.css';

/** A contact as the edit form reads it (ContactResource). */
export interface ContactRecord {
    id: number;
    first_name: string;
    last_name: string;
    emails: string[];
    phone: string | null;
    address: string | null;
    city: string | null;
    region: string | null;
    country: string | null;
    postal_code: string | null;
    position: string | null;
    phone_secondary: string | null;
    notes: string | null;
    deleted_at: string | null;
    client_id: number | null;
}

const EMPTY = {
    first_name: '',
    last_name: '',
    client_id: '',
    email: '',
    other_emails: '',
    phone: '',
    position: '',
    address: '',
    postal_code: '',
    city: '',
    region: '',
    country: '',
    phone_secondary: '',
    notes: '',
};

type Draft = typeof EMPTY;

const NO_CLIENT = 'none';

/** The first address, then the others (commas or spaces between them), each once. */
export function emailList(first: string, others: string): string[] {
    const all = [first, ...others.split(/[\s,;]+/)].map((email) => email.trim()).filter((email) => email !== '');

    return all.filter((email, index) => all.findIndex((seen) => seen.toLowerCase() === email.toLowerCase()) === index);
}

function draftOf(contact: ContactRecord | null | undefined): Draft {
    if (!contact) return EMPTY;

    return {
        first_name: contact.first_name,
        last_name: contact.last_name,
        client_id: contact.client_id === null ? '' : String(contact.client_id),
        email: contact.emails[0] ?? '',
        other_emails: contact.emails.slice(1).join(', '),
        phone: contact.phone ?? '',
        position: contact.position ?? '',
        address: contact.address ?? '',
        postal_code: contact.postal_code ?? '',
        city: contact.city ?? '',
        region: contact.region ?? '',
        country: contact.country ?? '',
        phone_secondary: contact.phone_secondary ?? '',
        notes: contact.notes ?? '',
    };
}

interface ContactDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Klient Dane: the client the new contact belongs to, with no Klient field. */
    clientId?: number;
    /** Kontakty: the clients the Klient field offers. */
    clients?: { id: number; name: string }[];
    /** Edit this contact instead of adding one. */
    contact?: ContactRecord | null;
    /** Kontakty: Usuń kontakt (the caller confirms) or, for a deleted contact, Przywróć. */
    onRemove?: (contact: ContactRecord) => void;
}

/**
 * Figma: Kontakty 477:8906 (Nowy kontakt) and 477:8940 (Edytuj kontakt); a
 * bottom sheet on phones (477:8986, 477:9101). Also Klient Dane's Nowy kontakt.
 */
export function ContactDialog({ open, onOpenChange, clientId, clients, contact, onRemove }: ContactDialogProps) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<Draft>(draftOf(contact));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const [wasOpen, setWasOpen] = useState(open);
    const [shownId, setShownId] = useState(contact?.id);
    if (open !== wasOpen || contact?.id !== shownId) {
        setWasOpen(open);
        setShownId(contact?.id);
        if (open) {
            setDraft(draftOf(contact));
            setErrors({});
        }
    }

    const editing = contact ?? null;
    const set = (key: keyof Draft) => (value: string) => setDraft((current) => ({ ...current, [key]: value }));
    const emailError = errors['emails.0'] ?? errors.emails;
    const otherEmailsError = Object.entries(errors).find(([key]) => /^emails\.[1-9]/.test(key))?.[1];
    const ids = (key: string) => `contact-${key}`;

    function submit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        const emails = emailList(draft.email, draft.other_emails);
        const client = clientId ?? (draft.client_id === '' ? null : Number(draft.client_id));
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };
        const fields = {
            first_name: draft.first_name,
            last_name: draft.last_name,
            client_id: client,
            emails,
            phone: draft.phone,
            position: draft.position,
        };

        if (editing === null) {
            router.post(store().url, fields, options);

            return;
        }

        router.put(
            update(editing.id).url,
            {
                ...fields,
                address: draft.address,
                postal_code: draft.postal_code,
                city: draft.city,
                region: draft.region,
                country: draft.country,
                phone_secondary: draft.phone_secondary,
                notes: draft.notes,
            },
            options,
        );
    }

    const remove = editing !== null && onRemove !== undefined ? () => onRemove(editing) : null;
    const removeLabel = editing?.deleted_at ? t('Restore contact') : t('Delete contact');
    const extras = [draft.city, draft.notes.trim() !== '' ? t('note_count', { count: 1 }) : null].filter(Boolean).join(' · ');

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={editing === null ? t('New contact') : t('Edit contact')}
            sheetTitle={editing === null ? t('New contact') : t('Edit contact')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={editing === null ? t('Add') : t('Save')}
            saving={processing}
            footerClassName={styles.actions}
            actions={(size) => (
                <>
                    {remove !== null && (
                        <SoloButton variant="text" size={size} className={styles.start} onClick={remove} disabled={processing}>
                            {removeLabel}
                        </SoloButton>
                    )}
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : editing === null ? t('Add contact') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SoloFieldRow>
                <SoloField label={t('First name')} htmlFor={ids('first-name')} error={errors.first_name}>
                    <SoloInput
                        {...fieldControl(ids('first-name'), errors.first_name)}
                        value={draft.first_name}
                        onChange={(e) => set('first_name')(e.target.value)}
                        required
                        maxLength={25}
                        autoFocus
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('Last name')} htmlFor={ids('last-name')} error={errors.last_name}>
                    <SoloInput
                        {...fieldControl(ids('last-name'), errors.last_name)}
                        value={draft.last_name}
                        onChange={(e) => set('last_name')(e.target.value)}
                        required
                        maxLength={25}
                        disabled={processing}
                    />
                </SoloField>
            </SoloFieldRow>
            {clients !== undefined && clientId === undefined && (
                <SoloField label={t('Client name')} htmlFor={ids('client')} error={errors.client_id}>
                    <ResponsiveSelect
                        label={t('Client name')}
                        value={draft.client_id === '' ? NO_CLIENT : draft.client_id}
                        onValueChange={(value) => set('client_id')(value === NO_CLIENT ? '' : value)}
                        options={[
                            { value: NO_CLIENT, label: t('No client') },
                            ...clients.map((client) => ({ value: String(client.id), label: client.name })),
                        ]}
                        disabled={processing}
                        control={fieldControl(ids('client'), errors.client_id)}
                    />
                </SoloField>
            )}
            <SoloField label={t('Email')} htmlFor={ids('email')} error={emailError}>
                <SoloInput
                    {...fieldControl(ids('email'), emailError)}
                    type="email"
                    value={draft.email}
                    onChange={(e) => set('email')(e.target.value)}
                    maxLength={255}
                    disabled={processing}
                />
            </SoloField>
            <SoloFieldRow>
                <SoloField label={t('Phone')} htmlFor={ids('phone')} error={errors.phone}>
                    <SoloInput
                        {...fieldControl(ids('phone'), errors.phone)}
                        type="tel"
                        value={draft.phone}
                        onChange={(e) => set('phone')(e.target.value)}
                        maxLength={50}
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('Position')} htmlFor={ids('position')} error={errors.position}>
                    <SoloInput
                        {...fieldControl(ids('position'), errors.position)}
                        value={draft.position}
                        onChange={(e) => set('position')(e.target.value)}
                        maxLength={100}
                        disabled={processing}
                    />
                </SoloField>
            </SoloFieldRow>
            {editing !== null && (
                <SectionToggle label={t('Address and notes')} summary={extras || undefined}>
                    <div className={styles.toggleFields}>
                        <SoloField label={t('Address')} htmlFor={ids('address')} error={errors.address}>
                            <SoloInput
                                {...fieldControl(ids('address'), errors.address)}
                                value={draft.address}
                                onChange={(e) => set('address')(e.target.value)}
                                maxLength={150}
                                disabled={processing}
                            />
                        </SoloField>
                        <SoloFieldRow>
                            <SoloField label={t('Postal code')} htmlFor={ids('postal-code')} error={errors.postal_code}>
                                <SoloInput
                                    {...fieldControl(ids('postal-code'), errors.postal_code)}
                                    value={draft.postal_code}
                                    onChange={(e) => set('postal_code')(e.target.value)}
                                    maxLength={25}
                                    disabled={processing}
                                />
                            </SoloField>
                            <SoloField label={t('City')} htmlFor={ids('city')} error={errors.city}>
                                <SoloInput
                                    {...fieldControl(ids('city'), errors.city)}
                                    value={draft.city}
                                    onChange={(e) => set('city')(e.target.value)}
                                    maxLength={50}
                                    disabled={processing}
                                />
                            </SoloField>
                        </SoloFieldRow>
                        <SoloFieldRow>
                            <SoloField label={t('Region')} htmlFor={ids('region')} error={errors.region}>
                                <SoloInput
                                    {...fieldControl(ids('region'), errors.region)}
                                    value={draft.region}
                                    onChange={(e) => set('region')(e.target.value)}
                                    maxLength={50}
                                    disabled={processing}
                                />
                            </SoloField>
                            <SoloField label={t('Country code')} htmlFor={ids('country')} error={errors.country}>
                                <SoloInput
                                    {...fieldControl(ids('country'), errors.country)}
                                    value={draft.country}
                                    onChange={(e) => set('country')(e.target.value.toUpperCase())}
                                    maxLength={2}
                                    disabled={processing}
                                />
                            </SoloField>
                        </SoloFieldRow>
                        <SoloField label={t('Other email addresses')} htmlFor={ids('other-emails')} error={otherEmailsError}>
                            <SoloInput
                                {...fieldControl(ids('other-emails'), otherEmailsError)}
                                value={draft.other_emails}
                                placeholder={t('Separated by commas')}
                                onChange={(e) => set('other_emails')(e.target.value)}
                                disabled={processing}
                            />
                        </SoloField>
                        <SoloField label={t('Second phone')} htmlFor={ids('phone-secondary')} error={errors.phone_secondary}>
                            <SoloInput
                                {...fieldControl(ids('phone-secondary'), errors.phone_secondary)}
                                type="tel"
                                value={draft.phone_secondary}
                                onChange={(e) => set('phone_secondary')(e.target.value)}
                                maxLength={50}
                                disabled={processing}
                            />
                        </SoloField>
                        <SoloField label={t('Notes')} htmlFor={ids('notes')} error={errors.notes}>
                            <SoloTextarea
                                {...fieldControl(ids('notes'), errors.notes)}
                                value={draft.notes}
                                onChange={(e) => set('notes')(e.target.value)}
                                rows={3}
                                disabled={processing}
                            />
                        </SoloField>
                    </div>
                </SectionToggle>
            )}
            {remove !== null && (
                <div className={styles.sheetEnd}>
                    <SoloButton variant="text" size="touch" onClick={remove} disabled={processing}>
                        {removeLabel}
                    </SoloButton>
                </div>
            )}
        </ResponsiveDialog>
    );
}
