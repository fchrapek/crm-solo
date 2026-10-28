import type { ClientCooperationType, MonthCloseType, ReportMode } from '@/types';

export interface ClientDetails {
    type: 'business' | 'individual';
    name: string;
    tax_id: string | null;
    business_type: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    postal_code: string | null;
    city: string | null;
    country: string | null;
    cooperation_type: ClientCooperationType | null;
    month_close_type: MonthCloseType;
    infakt_id: string | null;
    currency: string;
    segment: string | null;
    hourly_rate: number | null;
    report_mode: ReportMode;
    include_in_month_close: boolean;
    ssh_config: string | null;
    maintenance_invoice_description: string | null;
    report_baseline_markdown: string | null;
    archived: boolean;
}

export interface ClientDocumentRow {
    id: number;
    url: string;
    original_name: string;
    mime: string;
    size: number;
    category: string | null;
    label: string | null;
    created_at: string;
    /** The upload's day in the display timezone. */
    created_on: string;
}

export interface ClientContactRow {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    position: string | null;
}
