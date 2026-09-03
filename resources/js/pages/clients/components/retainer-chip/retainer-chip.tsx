import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { useFormatters } from '@/lib/format';
import { ClientCurrency } from '@/types';

import styles from './retainer-chip.module.css';
import { RetainerDialog } from './retainer-dialog';

export interface Retainer {
    id: number;
    project_id: number | null;
    label: string | null;
    description: string | null;
    monthly_hours: number;
    monthly_fee: number | null;
    overage_hourly_rate: number | null;
    rollover_cap_hours: number | null;
    invoice_group: number;
    vat_symbol: string | null;
    is_active: boolean;
    sort_order: number;
    currency: ClientCurrency;
    effective_from: string;
    effective_to: string | null;
    notes: string | null;
}

interface Props {
    clientId: number;
    clientCurrency: ClientCurrency;
    retainers: Retainer[];
}

// Every position in force today (independent rows, not a single timeline),
// summarised as one short label. Shared by the inert header pill and the
// Ustawienia trigger.
function useRetainerSummary(retainers: Retainer[], clientCurrency: ClientCurrency) {
    const { t } = useTranslation();
    const f = useFormatters();

    const active = useMemo(() => {
        const today = new Date().toISOString().slice(0, 10);
        return retainers.filter((r) => r.is_active && r.effective_from <= today && (r.effective_to === null || r.effective_to > today));
    }, [retainers]);

    // One position → show it inline; several → show the monthly total + count.
    const singleLabel = (r: Retainer): string => {
        if (r.monthly_hours > 0 && r.monthly_fee !== null) {
            return `${f.hoursPerMonth(r.monthly_hours)} · ${f.perMonth(r.monthly_fee, r.currency)}`;
        }
        if (r.monthly_hours > 0) {
            return f.hoursPerMonth(r.monthly_hours);
        }
        if (r.monthly_fee !== null) {
            return f.perMonth(r.monthly_fee, r.currency);
        }
        return f.hoursPerMonth(r.monthly_hours);
    };

    const label = useMemo(() => {
        if (active.length === 0) {
            return null;
        }
        if (active.length === 1) {
            return singleLabel(active[0]);
        }
        const sumFee = active.reduce((s, r) => s + (r.monthly_fee ?? 0), 0);
        return `${f.perMonth(sumFee, clientCurrency)} · ${active.length} ${t('pos.')}`;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active, clientCurrency, t]);

    return { active, label };
}

/**
 * Inert header pill - managing positions happens in Ustawienia (Abonament
 * card). Hidden entirely when no position is in force.
 */
export function RetainerPill({ clientCurrency, retainers }: { clientCurrency: ClientCurrency; retainers: Retainer[] }) {
    const { label } = useRetainerSummary(retainers, clientCurrency);

    if (label === null) {
        return null;
    }

    return <span className={styles.pill}>{label}</span>;
}

export function RetainerChip({ clientId, clientCurrency, retainers }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const { active, label } = useRetainerSummary(retainers, clientCurrency);

    return (
        <>
            <button type="button" className={styles.trigger} onClick={() => setOpen(true)} aria-label={t('Manage abonament')}>
                <span className={active.length > 0 ? undefined : styles.placeholder}>{label ?? t('+ Retainer')}</span>
            </button>
            <RetainerDialog
                open={open}
                onOpenChange={setOpen}
                clientId={clientId}
                clientCurrency={clientCurrency}
                retainers={retainers}
                activeIds={active.map((r) => r.id)}
            />
        </>
    );
}
