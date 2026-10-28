import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SectionAction, SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { ConfirmDialog } from '@/components/ui/confirm-dialog/confirm-dialog';
import { useFormatters } from '@/lib/format';
import { ClientCurrency } from '@/types';

import type { Retainer } from './retainer-chip';
import styles from './retainer-dialog.module.css';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientId: number;
    clientCurrency: ClientCurrency;
    retainers: Retainer[];
    /** Positions in force, the leading one first: the dialog opens on it. */
    activeIds: number[];
    /** Below 768 px a bottom sheet (Solo pages only). */
    sheet?: boolean;
}

export type Draft = {
    label: string;
    description: string;
    monthly_hours: string;
    monthly_fee: string;
    rollover_cap_hours: string;
    invoice_group: string;
    vat_symbol: string;
    currency: ClientCurrency;
    effective_from: string;
    notes: string;
};

const text = (value: number | string | null | undefined): string => (value === null || value === undefined ? '' : String(value));

export function draftOf(retainer: Retainer | null, currency: ClientCurrency): Draft {
    return {
        label: text(retainer?.label),
        description: text(retainer?.description),
        monthly_hours: text(retainer?.monthly_hours),
        monthly_fee: text(retainer?.monthly_fee),
        rollover_cap_hours: text(retainer?.rollover_cap_hours),
        invoice_group: text(retainer?.invoice_group ?? 1),
        vat_symbol: retainer?.vat_symbol ?? '23',
        currency: retainer?.currency ?? currency,
        effective_from: retainer?.effective_from ?? localToday(),
        notes: text(retainer?.notes),
    };
}

/** YYYY-MM-DD on the viewer's clock, not UTC's. */
const localToday = (): string => new Date().toLocaleDateString('sv-SE');

const numberOrNull = (value: string): number | null => (value.trim() === '' ? null : Number(value.replace(/\s/g, '').replace(',', '.')));

/** Behind the disclosure, so the form keeps Figma's six fields up front; an error in one opens it. */
const MORE_FIELDS = ['label', 'description', 'invoice_group', 'currency', 'notes'];

export const errorsNeedMore = (errors: Record<string, string>): boolean => MORE_FIELDS.some((field) => field in errors);

const NUMBER_FIELDS = ['monthly_fee', 'monthly_hours', 'rollover_cap_hours', 'invoice_group'] as const;

/** Fields whose text is not a number: sent as is they would arrive empty and clear the stored value. */
export function invalidNumbers(draft: Draft): (typeof NUMBER_FIELDS)[number][] {
    return NUMBER_FIELDS.filter((field) => {
        const text = draft[field].replace(/\s/g, '');
        if (text === '') {
            return field === 'monthly_hours';
        }

        return field === 'invoice_group' ? !/^\d+$/.test(text) : !Number.isFinite(Number(text.replace(',', '.')));
    });
}

/** Only what the form shows: the update keeps every field it is not sent (project, overage rate, order). */
export function retainerPayload(draft: Draft) {
    return {
        label: draft.label.trim() === '' ? null : draft.label.trim(),
        description: draft.description.trim() === '' ? null : draft.description.trim(),
        monthly_hours: numberOrNull(draft.monthly_hours) ?? 0,
        monthly_fee: numberOrNull(draft.monthly_fee),
        rollover_cap_hours: numberOrNull(draft.rollover_cap_hours),
        invoice_group: draft.invoice_group.trim() === '' ? 1 : parseInt(draft.invoice_group, 10),
        vat_symbol: draft.vat_symbol.trim() === '' ? null : draft.vat_symbol.trim(),
        currency: draft.currency,
        effective_from: draft.effective_from,
        notes: draft.notes.trim() === '' ? null : draft.notes.trim(),
    };
}

/** Figma: Dialog / Zmień abonament, a bottom sheet on phones. Overage goes to the hours bank and is never billed, so its stored rate is not shown. */
export function RetainerDialog({ open, onOpenChange, clientId, clientCurrency, retainers, activeIds, sheet = false }: Props) {
    const { t } = useTranslation();
    const f = useFormatters();
    const [editing, setEditing] = useState<Retainer | null>(null);
    const [draft, setDraft] = useState<Draft>(() => draftOf(null, clientCurrency));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<Retainer | null>(null);
    const [more, setMore] = useState(false);

    function fail(errs: Record<string, string>) {
        setErrors(errs);
        if (errorsNeedMore(errs)) {
            setMore(true);
        }
    }

    function edit(retainer: Retainer | null) {
        setEditing(retainer);
        setDraft(draftOf(retainer, clientCurrency));
        setErrors({});
        setMore(false);
    }

    // Opened from outside, so the form is reset while rendering the open, not in an effect after it.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            edit(retainers.find((r) => r.id === activeIds[0]) ?? null);
        }
    }

    const set = (key: keyof Draft) => (value: string) => setDraft((current) => ({ ...current, [key]: value }));

    function submit(e: FormEvent) {
        e.preventDefault();
        const invalid = invalidNumbers(draft);
        if (invalid.length > 0) {
            fail(Object.fromEntries(invalid.map((field) => [field, t('Enter a number.')])));
            return;
        }
        setProcessing(true);

        const payload = retainerPayload(draft);
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (errs: Record<string, string>) => fail(errs),
            onFinish: () => setProcessing(false),
        };

        if (editing !== null) {
            router.put(`/clients/${clientId}/retainers/${editing.id}`, payload, options);
        } else {
            router.post(`/clients/${clientId}/retainers`, payload, options);
        }
    }

    function doDelete(retainer: Retainer) {
        router.delete(`/clients/${clientId}/retainers/${retainer.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (editing?.id === retainer.id) {
                    edit(null);
                }
            },
            onFinish: () => setConfirmDelete(null),
        });
    }

    return (
        <>
            <ResponsiveDialog
                open={open}
                onOpenChange={onOpenChange}
                sheet={sheet}
                fields
                title={editing !== null || retainers.length > 0 ? t('Change retainer') : t('Add a retainer')}
                onSubmit={submit}
                footerClassName={styles.actions}
                actions={(size) => (
                    <>
                        <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                            {t('Cancel')}
                        </SoloButton>
                        <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                            {processing ? t('Saving…') : editing !== null ? t('Save') : t('Add position')}
                        </SoloButton>
                    </>
                )}
            >
                <SoloField label={t('Net amount / month')} htmlFor="retainer-fee" error={errors.monthly_fee}>
                    <SoloInput
                        {...fieldControl('retainer-fee', errors.monthly_fee)}
                        inputMode="decimal"
                        value={draft.monthly_fee}
                        onChange={(e) => set('monthly_fee')(e.target.value)}
                        disabled={processing}
                    />
                </SoloField>
                <SoloFieldRow>
                    <SoloField label={t('Package (h)')} htmlFor="retainer-hours" error={errors.monthly_hours}>
                        <SoloInput
                            {...fieldControl('retainer-hours', errors.monthly_hours)}
                            inputMode="decimal"
                            value={draft.monthly_hours}
                            onChange={(e) => set('monthly_hours')(e.target.value)}
                            required
                            disabled={processing}
                        />
                    </SoloField>
                    <SoloField label={t('Rollover up to (h)')} htmlFor="retainer-rollover" error={errors.rollover_cap_hours}>
                        <SoloInput
                            {...fieldControl('retainer-rollover', errors.rollover_cap_hours)}
                            inputMode="decimal"
                            value={draft.rollover_cap_hours}
                            onChange={(e) => set('rollover_cap_hours')(e.target.value)}
                            disabled={processing}
                        />
                    </SoloField>
                </SoloFieldRow>
                <SoloField label={t('Over the package')} htmlFor="retainer-overage">
                    <SoloInput id="retainer-overage" value={t('to the hours bank, no surcharge')} readOnly tabIndex={-1} />
                </SoloField>
                <SoloFieldRow>
                    <SoloField label={t('From')} htmlFor="retainer-from" error={errors.effective_from}>
                        <SoloInput
                            {...fieldControl('retainer-from', errors.effective_from)}
                            type="date"
                            value={draft.effective_from}
                            onChange={(e) => set('effective_from')(e.target.value)}
                            required
                            disabled={processing}
                        />
                    </SoloField>
                    <SoloField label={t('VAT')} htmlFor="retainer-vat" error={errors.vat_symbol}>
                        <SoloInput
                            {...fieldControl('retainer-vat', errors.vat_symbol)}
                            value={draft.vat_symbol}
                            onChange={(e) => set('vat_symbol')(e.target.value)}
                            maxLength={8}
                            disabled={processing}
                        />
                    </SoloField>
                </SoloFieldRow>
                <SectionAction
                    className={styles.more}
                    aria-expanded={more}
                    aria-controls="retainer-more"
                    onClick={() => setMore((current) => !current)}
                >
                    {t('Invoice details and notes')} {more ? '↑' : '↓'}
                </SectionAction>
                {more && (
                    <div id="retainer-more" className={styles.moreFields}>
                        <SoloField label={t('Position name')} htmlFor="retainer-label" error={errors.label}>
                            <SoloInput
                                {...fieldControl('retainer-label', errors.label)}
                                value={draft.label}
                                onChange={(e) => set('label')(e.target.value)}
                                placeholder={t('e.g. example.com, dev hours')}
                                maxLength={200}
                                disabled={processing}
                            />
                        </SoloField>
                        <SoloField label={t('Invoice line (with contact)')} htmlFor="retainer-desc" error={errors.description}>
                            <SoloTextarea
                                {...fieldControl('retainer-desc', errors.description)}
                                value={draft.description}
                                onChange={(e) => set('description')(e.target.value)}
                                rows={2}
                                placeholder={t('Falls back to the position name / client description')}
                                disabled={processing}
                            />
                        </SoloField>
                        <SoloFieldRow>
                            <SoloField label={t('Invoice #')} htmlFor="retainer-group" error={errors.invoice_group}>
                                <SoloInput
                                    {...fieldControl('retainer-group', errors.invoice_group)}
                                    inputMode="numeric"
                                    value={draft.invoice_group}
                                    onChange={(e) => set('invoice_group')(e.target.value)}
                                    disabled={processing}
                                />
                            </SoloField>
                            <SoloField label={t('Currency')} htmlFor="retainer-currency" error={errors.currency}>
                                <ResponsiveSelect
                                    label={t('Currency')}
                                    value={draft.currency}
                                    onValueChange={set('currency')}
                                    disabled={processing}
                                    control={fieldControl('retainer-currency', errors.currency)}
                                    sheet={sheet}
                                    options={['PLN', 'EUR', 'USD', 'GBP'].map((currency) => ({ value: currency, label: currency }))}
                                />
                            </SoloField>
                        </SoloFieldRow>
                        <SoloField label={t('Notes')} htmlFor="retainer-notes" error={errors.notes}>
                            <SoloTextarea
                                {...fieldControl('retainer-notes', errors.notes)}
                                value={draft.notes}
                                onChange={(e) => set('notes')(e.target.value)}
                                rows={2}
                                disabled={processing}
                            />
                        </SoloField>
                    </div>
                )}

                {retainers.length > 0 && (
                    <section className={styles.positions}>
                        <SectionHead
                            kind="body"
                            quiet
                            label={t('Positions')}
                            action={
                                editing !== null ? (
                                    <SectionAction onClick={() => edit(null)} disabled={processing}>
                                        {t('New position')}
                                    </SectionAction>
                                ) : undefined
                            }
                        />
                        <ul className={styles.list}>
                            {retainers.map((r) => (
                                <li key={r.id} className={styles.row} data-editing={editing?.id === r.id ? true : undefined}>
                                    <div className={styles.rowMain}>
                                        <span className={styles.rowTitle}>
                                            {r.label || f.hoursPerMonth(r.monthly_hours)}
                                            {activeIds.includes(r.id) && <span className={styles.rowActive}>{t('active')}</span>}
                                        </span>
                                        <span className={styles.rowMeta}>
                                            {[
                                                r.label && r.monthly_hours > 0 ? f.hoursPerMonth(r.monthly_hours) : null,
                                                r.monthly_fee !== null ? f.perMonth(r.monthly_fee, r.currency) : null,
                                                t('invoice #{{n}}', { n: r.invoice_group }),
                                                r.effective_to === null
                                                    ? t('Since {{from}}', { from: r.effective_from })
                                                    : t('{{from}} → {{to}}', { from: r.effective_from, to: r.effective_to }),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </div>
                                    <div className={styles.rowActions}>
                                        <SectionAction onClick={() => edit(r)} disabled={processing || editing?.id === r.id}>
                                            {t('Edit')}
                                        </SectionAction>
                                        <SectionAction onClick={() => setConfirmDelete(r)} disabled={processing}>
                                            {t('Delete')}
                                        </SectionAction>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </ResponsiveDialog>

            <ConfirmDialog
                open={confirmDelete !== null}
                onOpenChange={(o) => !o && setConfirmDelete(null)}
                title={t('Delete position?')}
                description={
                    confirmDelete?.label ||
                    confirmDelete?.description ||
                    t('This abonament position will be removed. Past reports keep their snapshot.')
                }
                confirmLabel={t('Delete')}
                onConfirm={() => confirmDelete && doDelete(confirmDelete)}
                variant="destructive"
            />
        </>
    );
}
