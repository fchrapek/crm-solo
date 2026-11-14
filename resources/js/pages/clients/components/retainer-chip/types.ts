import type { ClientCurrency } from '@/types';

/** A retainer position as the retainer dialog edits it (ClientRetainer::toPayload). */
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
