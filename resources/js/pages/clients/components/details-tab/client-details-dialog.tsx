import { store, update } from '@/actions/App/Http/Controllers/ClientsController';
import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './dialog-form.module.css';
import type { ClientDetails } from './types';

const NONE = '__none__';

export type Draft = {
    type: 'business' | 'individual';
    name: string;
    tax_id: string;
    address: string;
    postal_code: string;
    city: string;
    country: string;
    email: string;
    phone: string;
    cooperation_type: string;
    business_type: string;
    currency: string;
    segment: string;
    hourly_rate: string;
    notes: string;
};

const NEW_CLIENT: Draft = {
    type: 'business',
    name: '',
    tax_id: '',
    address: '',
    postal_code: '',
    city: '',
    country: 'PL',
    email: '',
    phone: '',
    cooperation_type: '',
    business_type: '',
    currency: 'PLN',
    segment: '',
    hourly_rate: '',
    notes: '',
};

function draftOf(details: ClientDetails | undefined): Draft {
    if (details === undefined) return NEW_CLIENT;

    return {
        ...NEW_CLIENT,
        type: details.type,
        name: details.name,
        tax_id: details.tax_id ?? '',
        address: details.address ?? '',
        postal_code: details.postal_code ?? '',
        city: details.city ?? '',
        country: details.country ?? '',
        email: details.email ?? '',
        phone: details.phone ?? '',
        cooperation_type: details.cooperation_type ?? '',
        business_type: details.business_type ?? '',
        currency: details.currency ?? 'PLN',
        segment: details.segment ?? '',
        hourly_rate: details.hourly_rate !== null && details.hourly_rate !== undefined ? String(details.hourly_rate) : '',
    };
}

/**
 * What the form sends: the rate only while the cooperation is hourly, so editing anything else never clears a stored
 * rate; notes only on create, since Dane does not show them.
 */
export function clientPayload(draft: Draft, creating: boolean): Partial<Draft> {
    const payload: Partial<Draft> = { ...draft };
    if (draft.cooperation_type !== 'hourly') delete payload.hourly_rate;
    if (!creating) delete payload.notes;

    return payload;
}

/**
 * Dane firmy, or with no client Nowy klient (Figma 573:10837, phone 573:15240): every field of the client form, Nazwa
 * first; notes are written only on create. The update rules require the type, so it always goes along, even unchanged.
 */
export function ClientDetailsDialog({
    clientId,
    details,
    open,
    onOpenChange,
}: {
    clientId?: number;
    details?: ClientDetails;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<Draft>(() => draftOf(details));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    // Opened from outside, so the form is reset while rendering the open, not in an effect after it.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setDraft(draftOf(details));
            setErrors({});
        }
    }

    const set = (key: keyof Draft) => (value: string) => setDraft((current) => ({ ...current, [key]: value }));

    const creating = clientId === undefined;
    const individual = draft.type === 'individual';

    function submit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        const options = {
            preserveScroll: !creating,
            onSuccess: () => onOpenChange(false),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };

        if (creating) {
            router.post(store().url, clientPayload(draft, true), options);

            return;
        }

        router.put(update(clientId).url, clientPayload(draft, false), options);
    }

    const nameField = (
        <SoloField
            label={
                individual ? (
                    <>
                        <span className={styles.wideOnly}>{t('Name')}</span>
                        <span className={styles.phoneOnly}>{t('Full name')}</span>
                    </>
                ) : (
                    t('Name')
                )
            }
            htmlFor="client-name"
            error={errors.name}
        >
            <SoloInput
                {...fieldControl('client-name', errors.name)}
                value={draft.name}
                onChange={(e) => set('name')(e.target.value)}
                required
                maxLength={100}
                autoFocus={creating}
                disabled={processing}
            />
        </SoloField>
    );

    const typeRow = (
        <SoloFieldRow>
            <SoloField label={t('Type')} htmlFor="client-type" error={errors.type}>
                <ResponsiveSelect
                    label={t('Type')}
                    value={draft.type}
                    onValueChange={set('type')}
                    disabled={processing}
                    control={fieldControl('client-type', errors.type)}
                    options={[
                        { value: 'business', label: t('Business') },
                        { value: 'individual', label: t('Individual') },
                    ]}
                />
            </SoloField>
            <SoloField label={t('Tax ID (NIP)')} htmlFor="client-tax-id" error={errors.tax_id} className={individual ? styles.wideField : undefined}>
                <SoloInput
                    {...fieldControl('client-tax-id', errors.tax_id)}
                    value={draft.tax_id}
                    onChange={(e) => set('tax_id')(e.target.value)}
                    maxLength={20}
                    disabled={processing}
                />
            </SoloField>
        </SoloFieldRow>
    );

    const businessTypeField = (
        <SoloField label={t('Business Type')} htmlFor="client-business-type" error={errors.business_type}>
            <SoloInput
                {...fieldControl('client-business-type', errors.business_type)}
                value={draft.business_type}
                onChange={(e) => set('business_type')(e.target.value)}
                maxLength={100}
                placeholder={t('e.g., sp. z o.o., JDG')}
                disabled={processing}
            />
        </SoloField>
    );

    const addressField = (
        <SoloField label={t('Address')} htmlFor="client-address" error={errors.address}>
            <SoloInput
                {...fieldControl('client-address', errors.address)}
                value={draft.address}
                onChange={(e) => set('address')(e.target.value)}
                maxLength={150}
                disabled={processing}
            />
        </SoloField>
    );

    const postalRow = (
        <SoloFieldRow>
            <SoloField label={t('Postal Code')} htmlFor="client-postal-code" error={errors.postal_code}>
                <SoloInput
                    {...fieldControl('client-postal-code', errors.postal_code)}
                    value={draft.postal_code}
                    onChange={(e) => set('postal_code')(e.target.value)}
                    maxLength={25}
                    placeholder="00-000"
                    disabled={processing}
                />
            </SoloField>
            <SoloField label={t('City')} htmlFor="client-city" error={errors.city}>
                <SoloInput
                    {...fieldControl('client-city', errors.city)}
                    value={draft.city}
                    onChange={(e) => set('city')(e.target.value)}
                    maxLength={50}
                    disabled={processing}
                />
            </SoloField>
        </SoloFieldRow>
    );

    const contactRow = (
        <SoloFieldRow>
            <SoloField label={t('Email')} htmlFor="client-email" error={errors.email}>
                <SoloInput
                    {...fieldControl('client-email', errors.email)}
                    type="email"
                    value={draft.email}
                    onChange={(e) => set('email')(e.target.value)}
                    maxLength={191}
                    disabled={processing}
                />
            </SoloField>
            <SoloField label={t('Phone')} htmlFor="client-phone" error={errors.phone}>
                <SoloInput
                    {...fieldControl('client-phone', errors.phone)}
                    type="tel"
                    value={draft.phone}
                    onChange={(e) => set('phone')(e.target.value)}
                    maxLength={50}
                    disabled={processing}
                />
            </SoloField>
        </SoloFieldRow>
    );

    const countryField = (
        <SoloField label={t('Country')} htmlFor="client-country" error={errors.country}>
            <ResponsiveSelect
                label={t('Country')}
                value={draft.country || NONE}
                onValueChange={(value) => set('country')(value === NONE ? '' : value)}
                disabled={processing}
                control={fieldControl('client-country', errors.country)}
                options={[
                    { value: NONE, label: t('None') },
                    { value: 'PL', label: t('Poland') },
                    { value: 'DE', label: t('Germany') },
                    { value: 'GB', label: t('United Kingdom') },
                ]}
            />
        </SoloField>
    );

    const currencyField = (
        <SoloField label={t('Currency')} htmlFor="client-currency" error={errors.currency}>
            <ResponsiveSelect
                label={t('Currency')}
                value={draft.currency}
                onValueChange={set('currency')}
                disabled={processing}
                control={fieldControl('client-currency', errors.currency)}
                options={[
                    { value: 'PLN', label: t('PLN - złoty') },
                    { value: 'EUR', label: t('EUR - euro') },
                    { value: 'USD', label: t('USD - dollar') },
                    { value: 'GBP', label: t('GBP - pound') },
                ]}
            />
        </SoloField>
    );

    const segmentField = (
        <SoloField label={t('Segment')} htmlFor="client-segment" error={errors.segment}>
            <ResponsiveSelect
                label={t('Segment')}
                value={draft.segment || NONE}
                onValueChange={(value) => set('segment')(value === NONE ? '' : value)}
                disabled={processing}
                control={fieldControl('client-segment', errors.segment)}
                options={[
                    { value: NONE, label: t(' - none - ') },
                    { value: 'agency', label: t('Agency') },
                    { value: 'smb', label: t('Small / mid business') },
                    { value: 'enterprise', label: t('Enterprise') },
                    { value: 'individual', label: t('Individual') },
                ]}
            />
        </SoloField>
    );

    const cooperationField = (
        <SoloField label={t('Cooperation type')} htmlFor="client-cooperation" error={errors.cooperation_type}>
            <ResponsiveSelect
                label={t('Cooperation type')}
                value={draft.cooperation_type || NONE}
                onValueChange={(value) => set('cooperation_type')(value === NONE ? '' : value)}
                disabled={processing}
                control={fieldControl('client-cooperation', errors.cooperation_type)}
                options={[
                    { value: NONE, label: creating ? t(' - none - ') : t('None') },
                    { value: 'retainer', label: t('Retainer') },
                    { value: 'hourly', label: t('Hourly contract') },
                    { value: 'project', label: t('Project') },
                    { value: 'one_off', label: t('One-off') },
                ]}
            />
        </SoloField>
    );

    const hourlyRateField = (
        <SoloField label={t('Hourly rate (netto)')} htmlFor="client-hourly-rate" error={errors.hourly_rate}>
            <SoloInput
                {...fieldControl('client-hourly-rate', errors.hourly_rate)}
                type="number"
                inputMode="decimal"
                min={0}
                step="0.01"
                value={draft.hourly_rate}
                onChange={(e) => set('hourly_rate')(e.target.value)}
                placeholder={t('e.g., 95.00')}
                disabled={processing}
            />
        </SoloField>
    );

    const notesField = (
        <SoloField label={t('Notes')} htmlFor="client-notes" error={errors.notes}>
            <SoloTextarea
                {...fieldControl('client-notes', errors.notes)}
                value={draft.notes}
                onChange={(e) => set('notes')(e.target.value)}
                rows={3}
                disabled={processing}
            />
        </SoloField>
    );

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={creating ? t('New client') : details?.type === 'individual' ? t('Edit details') : t('Edit company data')}
            sheetTitle={creating ? t('New client') : details?.type === 'individual' ? t('Data') : t('Company data')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={creating ? t('Add') : undefined}
            saving={processing}
            footerClassName={styles.actions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : creating ? t('Add client') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            {nameField}
            {typeRow}
            {!individual && businessTypeField}
            {contactRow}
            {addressField}
            {postalRow}
            <SoloFieldRow>
                {countryField}
                {currencyField}
            </SoloFieldRow>
            <SoloFieldRow>
                {segmentField}
                {cooperationField}
            </SoloFieldRow>
            {draft.cooperation_type === 'hourly' && hourlyRateField}
            {creating && notesField}
        </ResponsiveDialog>
    );
}
