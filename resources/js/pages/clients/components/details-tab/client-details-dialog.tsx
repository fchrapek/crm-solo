import { store, update } from '@/actions/App/Http/Controllers/ClientsController';
import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { SectionToggle } from '@/components/solo/section-toggle';
import { SegmentedControl } from '@/components/solo/segmented-control';
import { GroupHeading } from '@/components/solo/tile';
import { router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
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
    region: string;
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
    region: '',
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
        region: details.region ?? '',
        country: details.country ?? '',
        email: details.email ?? '',
        phone: details.phone ?? '',
        cooperation_type: details.cooperation_type ?? '',
        business_type: details.business_type ?? '',
        currency: details.currency ?? 'PLN',
        segment: details.segment ?? '',
        hourly_rate: details.hourly_rate !== null && details.hourly_rate !== undefined ? String(details.hourly_rate) : '',
        notes: details.notes ?? '',
    };
}

/** What the form sends: only the fields its type and cooperation show, so a hidden field never clears a stored value or refuses unseen. */
export function clientPayload(draft: Draft): Partial<Draft> {
    const payload: Partial<Draft> = { ...draft };
    if (draft.cooperation_type !== 'hourly') delete payload.hourly_rate;
    if (draft.type === 'individual') {
        delete payload.tax_id;
        delete payload.business_type;
    }

    return payload;
}

type Group = 'company' | 'notes';

/** The form's fields in the order they stand, with their control and the folded group that holds them. */
const FIELDS: { key: string; id: string; group?: Group }[] = [
    { key: 'name', id: 'client-name' },
    { key: 'cooperation_type', id: 'client-cooperation' },
    { key: 'email', id: 'client-email' },
    { key: 'phone', id: 'client-phone' },
    { key: 'hourly_rate', id: 'client-hourly-rate' },
    { key: 'currency', id: 'client-currency' },
    { key: 'tax_id', id: 'client-tax-id', group: 'company' },
    { key: 'business_type', id: 'client-business-type', group: 'company' },
    { key: 'address', id: 'client-address', group: 'company' },
    { key: 'postal_code', id: 'client-postal-code', group: 'company' },
    { key: 'city', id: 'client-city', group: 'company' },
    { key: 'region', id: 'client-region', group: 'company' },
    { key: 'country', id: 'client-country', group: 'company' },
    { key: 'segment', id: 'client-segment', group: 'company' },
    { key: 'notes', id: 'client-notes', group: 'notes' },
];

/** The first field the server refused, in the form's order: its group opens and the field takes the focus. */
export function firstInvalid(errors: Record<string, string>): { id: string; group?: Group } | null {
    return FIELDS.find((field) => errors[field.key] !== undefined) ?? null;
}

/** Refusals no field of the form shows, such as the type: they stand at the top of the form instead. */
export function unmappedErrors(errors: Record<string, string>): string[] {
    return Object.entries(errors)
        .filter(([key]) => !FIELDS.some((field) => field.key === key))
        .map(([, message]) => message);
}

/** New client, or Company data for a client (Figma 1395:35803, phone 1395:36518); the type always goes along, as the update rules require it. */
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
    const editing = clientId !== undefined;
    const [companyOpen, setCompanyOpen] = useState(editing);
    const [notesOpen, setNotesOpen] = useState(editing);
    const [focus, setFocus] = useState<{ id: string } | null>(null);

    // Opened from outside, so the form is reset while rendering the open, not in an effect after it.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setDraft(draftOf(details));
            setErrors({});
            setCompanyOpen(editing);
            setNotesOpen(editing);
        }
    }

    // A folded field is inert until its group opens and disabled until the request ends, so the focus waits for both.
    useEffect(() => {
        if (focus !== null && !processing) document.getElementById(focus.id)?.focus();
    }, [focus, processing]);

    function refused(errs: Record<string, string>) {
        setErrors(errs);
        const field = firstInvalid(errs);
        if (field === null) return;
        if (field.group === 'company') setCompanyOpen(true);
        if (field.group === 'notes') setNotesOpen(true);
        setFocus({ id: field.id });
    }

    const set = (key: keyof Draft) => (value: string) => setDraft((current) => ({ ...current, [key]: value }));

    const creating = !editing;
    const individual = draft.type === 'individual';
    const hourly = draft.cooperation_type === 'hourly';

    function submit(e: FormEvent) {
        e.preventDefault();
        setFocus(null);
        setProcessing(true);
        const options = {
            preserveScroll: !creating,
            onSuccess: () => onOpenChange(false),
            onError: refused,
            onFinish: () => setProcessing(false),
        };

        if (creating) {
            router.post(store().url, clientPayload(draft), options);

            return;
        }

        router.put(update(clientId).url, clientPayload(draft), options);
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
            hint={creating ? t('Required. You can fill in the rest later.') : undefined}
        >
            <SoloInput
                {...fieldControl('client-name', errors.name, creating)}
                value={draft.name}
                onChange={(e) => set('name')(e.target.value)}
                required
                maxLength={100}
                autoFocus={creating}
                disabled={processing}
            />
        </SoloField>
    );

    const typeField = (
        <SegmentedControl
            label={t('Type')}
            value={draft.type}
            onChange={(value) => set('type')(value)}
            disabled={processing}
            options={[
                { value: 'business', label: t('Business') },
                { value: 'individual', label: t('Individual') },
            ]}
        />
    );

    const taxIdField = (
        <SoloField label={t('Tax ID (NIP)')} htmlFor="client-tax-id" error={errors.tax_id}>
            <SoloInput
                {...fieldControl('client-tax-id', errors.tax_id)}
                value={draft.tax_id}
                onChange={(e) => set('tax_id')(e.target.value)}
                maxLength={20}
                disabled={processing}
            />
        </SoloField>
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

    const regionField = (
        <SoloField label={t('Region')} htmlFor="client-region" error={errors.region}>
            <SoloInput
                {...fieldControl('client-region', errors.region)}
                value={draft.region}
                onChange={(e) => set('region')(e.target.value)}
                maxLength={50}
                disabled={processing}
            />
        </SoloField>
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
        <SoloField
            label={t('Currency')}
            htmlFor="client-currency"
            error={errors.currency}
            hint={hourly ? undefined : t('The hourly rate appears only with an hourly contract.')}
        >
            <ResponsiveSelect
                label={t('Currency')}
                value={draft.currency}
                onValueChange={set('currency')}
                disabled={processing}
                control={fieldControl('client-currency', errors.currency, !hourly)}
                options={[
                    { value: 'PLN', label: t('PLN (złoty)') },
                    { value: 'EUR', label: t('EUR (euro)') },
                    { value: 'USD', label: t('USD (US dollar)') },
                    { value: 'GBP', label: t('GBP (pound)') },
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
                    { value: NONE, label: t('None') },
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
                    { value: NONE, label: t('None') },
                    { value: 'retainer', label: t('Retainer') },
                    { value: 'hourly', label: t('Hourly contract') },
                    { value: 'project', label: t('Project') },
                    { value: 'one_off', label: t('One-off') },
                ]}
            />
        </SoloField>
    );

    const hourlyRateField = (
        <SoloField label={t('Hourly rate (net)')} htmlFor="client-hourly-rate" error={errors.hourly_rate} hint={t('Only with an hourly contract.')}>
            <SoloInput
                {...fieldControl('client-hourly-rate', errors.hourly_rate, true)}
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
            {unmappedErrors(errors).length > 0 && (
                <p className={styles.error} role="alert">
                    {unmappedErrors(errors).join(' ')}
                </p>
            )}
            {typeField}
            {nameField}
            {cooperationField}
            <GroupHeading label={t('Contact details')} size="compact" flush as="h3" />
            {contactRow}
            <GroupHeading label={t('Billing')} size="compact" flush as="h3" />
            {hourly ? (
                <SoloFieldRow>
                    {hourlyRateField}
                    {currencyField}
                </SoloFieldRow>
            ) : (
                currencyField
            )}
            <SectionToggle
                label={individual ? t('Address') : t('Company data and address')}
                summary={companyOpen ? undefined : individual ? t('Address, region, country') : t('Tax ID, address, region, country')}
                phoneSummary={null}
                open={companyOpen}
                onOpenChange={setCompanyOpen}
                disabled={processing}
            >
                <div className={styles.toggleFields}>
                    {!individual && (
                        <SoloFieldRow>
                            {taxIdField}
                            {businessTypeField}
                        </SoloFieldRow>
                    )}
                    {addressField}
                    {postalRow}
                    <SoloFieldRow>
                        {regionField}
                        {countryField}
                    </SoloFieldRow>
                    {segmentField}
                </div>
            </SectionToggle>
            <SectionToggle label={t('Notes')} summary={notesOpen ? undefined : draft.notes.trim() || t('notes_empty')} open={notesOpen} onOpenChange={setNotesOpen} disabled={processing}>
                <div className={styles.toggleFields}>{notesField}</div>
            </SectionToggle>
        </ResponsiveDialog>
    );
}
