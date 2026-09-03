import { router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog/confirm-dialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
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
    activeIds: number[];
}

type FormMode = { kind: 'create' } | { kind: 'edit'; retainer: Retainer };

export function RetainerDialog({ open, onOpenChange, clientId, clientCurrency, retainers, activeIds }: Props) {
    const { t } = useTranslation();
    const f = useFormatters();
    const [mode, setMode] = useState<FormMode>({ kind: 'create' });
    const [label, setLabel] = useState('');
    const [description, setDescription] = useState('');
    const [monthlyHours, setMonthlyHours] = useState('');
    const [monthlyFee, setMonthlyFee] = useState('');
    const [overageRate, setOverageRate] = useState('');
    const [rolloverCap, setRolloverCap] = useState('');
    const [invoiceGroup, setInvoiceGroup] = useState('1');
    const [currency, setCurrency] = useState<ClientCurrency>(clientCurrency);
    const [effectiveFrom, setEffectiveFrom] = useState(() => new Date().toISOString().slice(0, 10));
    const [notes, setNotes] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<Retainer | null>(null);

    useEffect(() => {
        if (open) {
            resetForm();
        }
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    function resetForm() {
        setMode({ kind: 'create' });
        setLabel('');
        setDescription('');
        setMonthlyHours('');
        setMonthlyFee('');
        setOverageRate('');
        setRolloverCap('');
        setInvoiceGroup('1');
        setCurrency(clientCurrency);
        setEffectiveFrom(new Date().toISOString().slice(0, 10));
        setNotes('');
        setErrors({});
    }

    function startEdit(retainer: Retainer) {
        setMode({ kind: 'edit', retainer });
        setLabel(retainer.label ?? '');
        setDescription(retainer.description ?? '');
        setMonthlyHours(retainer.monthly_hours.toString());
        setMonthlyFee(retainer.monthly_fee?.toString() ?? '');
        setOverageRate(retainer.overage_hourly_rate?.toString() ?? '');
        setRolloverCap(retainer.rollover_cap_hours?.toString() ?? '');
        setInvoiceGroup(retainer.invoice_group?.toString() ?? '1');
        setCurrency(retainer.currency);
        setEffectiveFrom(retainer.effective_from);
        setNotes(retainer.notes ?? '');
        setErrors({});
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        setProcessing(true);

        const payload: Record<string, string | number | null> = {
            label: label.trim() === '' ? null : label.trim(),
            description: description.trim() === '' ? null : description.trim(),
            monthly_hours: parseFloat(monthlyHours),
            monthly_fee: monthlyFee.trim() === '' ? null : parseFloat(monthlyFee),
            overage_hourly_rate: overageRate.trim() === '' ? null : parseFloat(overageRate),
            rollover_cap_hours: rolloverCap.trim() === '' ? null : parseFloat(rolloverCap),
            invoice_group: invoiceGroup.trim() === '' ? 1 : parseInt(invoiceGroup, 10),
            currency,
            effective_from: effectiveFrom,
            notes: notes.trim() === '' ? null : notes.trim(),
        };

        const onSuccess = () => {
            setProcessing(false);
            resetForm();
        };
        const onError = (errs: Record<string, string>) => {
            setErrors(errs);
            setProcessing(false);
        };

        if (mode.kind === 'edit') {
            router.put(`/clients/${clientId}/retainers/${mode.retainer.id}`, payload, {
                preserveScroll: true,
                onSuccess,
                onError,
            });
        } else {
            router.post(
                `/clients/${clientId}/retainers`,
                { ...payload, effective_from: effectiveFrom },
                { preserveScroll: true, onSuccess, onError },
            );
        }
    }

    function doDelete(retainer: Retainer) {
        router.delete(`/clients/${clientId}/retainers/${retainer.id}`, {
            preserveScroll: true,
            onFinish: () => setConfirmDelete(null),
        });
    }

    const isEdit = mode.kind === 'edit';
    const effectiveInPackageRate = computeInPackageRate(monthlyFee, monthlyHours);

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('Abonament')}</DialogTitle>
                    </DialogHeader>

                    <div className={styles.body}>
                        <section className={styles.section}>
                            <p className={styles.heading}>{isEdit ? t('Edit position') : t('Add position')}</p>
                            <form className={styles.form} onSubmit={submit}>
                                <div className={styles.formFullRow}>
                                    <FormLabel htmlFor="retainer-label">{t('Position name')}</FormLabel>
                                    <FormInput
                                        id="retainer-label"
                                        value={label}
                                        onChange={(e) => setLabel(e.target.value)}
                                        disabled={processing}
                                        placeholder={t('e.g. example.com, dev hours')}
                                    />
                                </div>
                                <div className={styles.formFullRow}>
                                    <FormLabel htmlFor="retainer-desc">{t('Invoice line (with contact)')}</FormLabel>
                                    <Textarea
                                        id="retainer-desc"
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        rows={2}
                                        disabled={processing}
                                        placeholder={t('Falls back to the position name / client description')}
                                    />
                                </div>
                                <div>
                                    <FormLabel htmlFor="retainer-hours" error={errors.monthly_hours}>
                                        {t('Included hours / month')}
                                    </FormLabel>
                                    <FormInput
                                        id="retainer-hours"
                                        type="number"
                                        step="0.5"
                                        min="0"
                                        value={monthlyHours}
                                        onChange={(e) => setMonthlyHours(e.target.value)}
                                        required
                                        disabled={processing}
                                        error={errors.monthly_hours}
                                    />
                                    <FormMessage error={errors.monthly_hours} />
                                </div>
                                <div>
                                    <FormLabel htmlFor="retainer-fee" error={errors.monthly_fee}>
                                        {t('Package fee / month')}
                                    </FormLabel>
                                    <FormInput
                                        id="retainer-fee"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={monthlyFee}
                                        onChange={(e) => setMonthlyFee(e.target.value)}
                                        disabled={processing}
                                        error={errors.monthly_fee}
                                    />
                                    {effectiveInPackageRate !== null && (
                                        <span className={styles.effectiveRate}>
                                            {t('= {{rate}} in package', {
                                                rate: f.perHour(effectiveInPackageRate, currency),
                                            })}
                                        </span>
                                    )}
                                    <FormMessage error={errors.monthly_fee} />
                                </div>
                                <div>
                                    <FormLabel htmlFor="retainer-overage" error={errors.overage_hourly_rate}>
                                        {t('Overage rate (per hour)')}
                                    </FormLabel>
                                    <FormInput
                                        id="retainer-overage"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={overageRate}
                                        onChange={(e) => setOverageRate(e.target.value)}
                                        disabled={processing}
                                        error={errors.overage_hourly_rate}
                                    />
                                    <FormMessage error={errors.overage_hourly_rate} />
                                </div>
                                <div>
                                    <FormLabel htmlFor="retainer-rollover" error={errors.rollover_cap_hours}>
                                        {t('Rollover cap (hours)')}
                                    </FormLabel>
                                    <FormInput
                                        id="retainer-rollover"
                                        type="number"
                                        step="0.5"
                                        min="0"
                                        value={rolloverCap}
                                        onChange={(e) => setRolloverCap(e.target.value)}
                                        disabled={processing}
                                        error={errors.rollover_cap_hours}
                                    />
                                    <FormMessage error={errors.rollover_cap_hours} />
                                </div>
                                <div>
                                    <FormLabel htmlFor="retainer-group" error={errors.invoice_group}>
                                        {t('Invoice #')}
                                    </FormLabel>
                                    <FormInput
                                        id="retainer-group"
                                        type="number"
                                        min="1"
                                        step="1"
                                        value={invoiceGroup}
                                        onChange={(e) => setInvoiceGroup(e.target.value)}
                                        disabled={processing}
                                        error={errors.invoice_group}
                                    />
                                    <FormMessage error={errors.invoice_group} />
                                </div>
                                <div>
                                    <FormLabel htmlFor="retainer-currency" error={errors.currency}>
                                        {t('Currency')}
                                    </FormLabel>
                                    <Select value={currency} onValueChange={(v) => setCurrency(v as ClientCurrency)} disabled={processing}>
                                        <SelectTrigger id="retainer-currency">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="PLN">PLN</SelectItem>
                                            <SelectItem value="EUR">EUR</SelectItem>
                                            <SelectItem value="USD">USD</SelectItem>
                                            <SelectItem value="GBP">GBP</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <FormMessage error={errors.currency} />
                                </div>
                                {!isEdit && (
                                    <div className={styles.formFullRow}>
                                        <FormLabel htmlFor="retainer-from" error={errors.effective_from}>
                                            {t('Effective from')}
                                        </FormLabel>
                                        <FormInput
                                            id="retainer-from"
                                            type="date"
                                            value={effectiveFrom}
                                            onChange={(e) => setEffectiveFrom(e.target.value)}
                                            required
                                            disabled={processing}
                                            error={errors.effective_from}
                                        />
                                        <FormMessage error={errors.effective_from} />
                                    </div>
                                )}
                                <div className={styles.formFullRow}>
                                    <FormLabel htmlFor="retainer-notes">{t('Notes')}</FormLabel>
                                    <Textarea
                                        id="retainer-notes"
                                        value={notes}
                                        onChange={(e) => setNotes(e.target.value)}
                                        rows={2}
                                        disabled={processing}
                                    />
                                </div>
                                <div className={styles.formActions}>
                                    {isEdit && (
                                        <Button type="button" variant="ghost" onClick={resetForm} disabled={processing}>
                                            {t('Cancel')}
                                        </Button>
                                    )}
                                    <Button type="submit" disabled={processing}>
                                        {isEdit ? t('Save changes') : t('Add position')}
                                    </Button>
                                </div>
                            </form>
                        </section>

                        <section className={styles.section}>
                            <p className={styles.heading}>{t('Positions')}</p>
                            {retainers.length === 0 ? (
                                <p className={styles.emptyHistory}>{t('No positions yet.')}</p>
                            ) : (
                                <div className={styles.history}>
                                    {retainers.map((r) => (
                                        <div key={r.id} className={styles.row} data-active={activeIds.includes(r.id)}>
                                            {activeIds.includes(r.id) && <span className={styles.activeDot} aria-hidden />}
                                            <div className={styles.rowMain}>
                                                <span className={styles.rowSummary}>
                                                    <strong className={styles.rowHours}>{r.label || f.hoursPerMonth(r.monthly_hours)}</strong>
                                                    {r.label && r.monthly_hours > 0 && (
                                                        <>
                                                            <span className={styles.rowSep}>·</span>
                                                            <span>{f.hoursPerMonth(r.monthly_hours)}</span>
                                                        </>
                                                    )}
                                                    {r.monthly_fee !== null && (
                                                        <>
                                                            <span className={styles.rowSep}>·</span>
                                                            <span>{f.perMonth(r.monthly_fee, r.currency)}</span>
                                                        </>
                                                    )}
                                                    {r.overage_hourly_rate !== null && (
                                                        <>
                                                            <span className={styles.rowSep}>·</span>
                                                            <span className={styles.rowOverage}>
                                                                {t('overage {{rate}}', { rate: f.perHour(r.overage_hourly_rate, r.currency) })}
                                                            </span>
                                                        </>
                                                    )}
                                                </span>
                                                <span className={styles.rowMeta} title={r.notes ?? undefined}>
                                                    {t('invoice #{{n}}', { n: r.invoice_group })}
                                                    {r.rollover_cap_hours !== null && (
                                                        <>
                                                            <span className={styles.rowSep}>·</span>
                                                            <span>{t('rollover ≤ {{cap}}h', { cap: r.rollover_cap_hours })}</span>
                                                        </>
                                                    )}
                                                    <span className={styles.rowSep}>·</span>
                                                    {formatRange(r.effective_from, r.effective_to, t)}
                                                    {r.notes && (
                                                        <>
                                                            <span className={styles.rowSep}>·</span>
                                                            <span className={styles.rowNotes}>{r.notes}</span>
                                                        </>
                                                    )}
                                                </span>
                                            </div>
                                            <div className={styles.rowActions}>
                                                <button
                                                    type="button"
                                                    className={styles.iconButton}
                                                    onClick={() => startEdit(r)}
                                                    aria-label={t('Edit')}
                                                >
                                                    <Pencil size={13} />
                                                </button>
                                                <button
                                                    type="button"
                                                    className={styles.iconButton}
                                                    onClick={() => setConfirmDelete(r)}
                                                    aria-label={t('Delete')}
                                                >
                                                    <Trash2 size={13} />
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>
                </DialogContent>
            </Dialog>

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

function computeInPackageRate(fee: string, hours: string): number | null {
    const f = parseFloat(fee);
    const h = parseFloat(hours);
    if (!Number.isFinite(f) || !Number.isFinite(h) || h <= 0) return null;
    return f / h;
}

function formatRange(from: string, to: string | null, t: (k: string, o?: Record<string, unknown>) => string): string {
    if (to === null) {
        return t('Since {{from}}', { from });
    }
    return t('{{from}} → {{to}}', { from, to });
}
