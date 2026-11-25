import { KvRow } from '@/components/solo/kv-row';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { GroupHeading } from '@/components/solo/tile';
import { fullDate } from '@/lib/dates';
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { moneyOf } from '../client-billing/format';
import styles from './retainer-dialog.module.css';
import type { Retainer } from './types';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientName: string;
    retainers: Retainer[];
    activeIds: number[];
    /** Opens the edit dialog; absent for someone who may only read. */
    onEdit?: () => void;
}

/** One position's fields as label and value, without the ones that are empty and never the overage rate. */
export function retainerFacts(retainer: Retainer, locale: string, t: (key: string, options?: Record<string, unknown>) => string): [string, string][] {
    const hours = (value: number) => `${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(value)} h`;
    const packaged = retainer.monthly_hours > 0;
    const facts: [string, string | null][] = [
        [t('Net amount / month'), retainer.monthly_fee === null ? null : moneyOf(retainer.monthly_fee, retainer.currency ?? 'PLN', locale, true)],
        [t('Package'), packaged ? hours(retainer.monthly_hours) : t('no hours')],
        [t('Rollover up to'), !packaged ? null : retainer.rollover_cap_hours === null ? t('no limit') : hours(retainer.rollover_cap_hours)],
        [t('Over the package'), packaged ? t('to the hours bank, no surcharge') : null],
        [t('From'), fullDate(retainer.effective_from, locale)],
        [t('To'), retainer.effective_to === null ? t('no end') : fullDate(retainer.effective_to, locale)],
        [t('VAT'), /^\d+$/.test(retainer.vat_symbol ?? '') ? `${retainer.vat_symbol}%` : (retainer.vat_symbol ?? null)],
        [t('Currency'), retainer.currency ?? null],
        [t('Invoice line'), retainer.description || null],
        [t('Notes'), retainer.notes || null],
    ];

    return facts.filter((fact): fact is [string, string] => fact[1] !== null && fact[1] !== '');
}

/** Figma: Retainer (1158:63932, sheet 1158:64448). Every position of the retainer to read, with Change retainer leading to the edit dialog. */
export function RetainerDetailsDialog({ open, onOpenChange, clientName, retainers, activeIds, onEdit }: Props) {
    const { t, i18n } = useTranslation();
    const active = retainers.filter((retainer) => activeIds.includes(retainer.id)).length;

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Retainer')}
            description={`${clientName} · ${t('{{count}} positions', { count: retainers.length })}, ${t('{{count}} active', { count: active })}`}
            onSubmit={(event) => {
                event.preventDefault();
                onEdit?.();
            }}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)}>
                        {t('Close')}
                    </SoloButton>
                    {onEdit && (
                        <SoloButton type="submit" variant="ink" size={size}>
                            {t('Change retainer')}
                        </SoloButton>
                    )}
                </>
            )}
        >
            {retainers.map((retainer, index) => (
                <Fragment key={retainer.id}>
                    <GroupHeading
                        flush
                        as="h3"
                        label={retainer.label || t('Position {{n}}', { n: index + 1 })}
                        end={t('invoice #{{n}}', { n: retainer.invoice_group ?? 1 })}
                    />
                    <dl className={styles.facts}>
                        {retainerFacts(retainer, i18n.language, t).map(([label, value]) => (
                            <KvRow key={label} layout="tile" label={label}>
                                {value}
                            </KvRow>
                        ))}
                    </dl>
                </Fragment>
            ))}
        </ResponsiveDialog>
    );
}
