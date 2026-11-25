import type { ClientCooperationType, LifecycleStage, MonthCloseType, ReportMode } from '@/types';

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
    region: string | null;
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
    notes: string | null;
    archived: boolean;
    has_report_baseline: boolean;
    /** The account's modules a client's Dane shows: their tile and their ⋯ menu entries appear only while on. */
    modules: { month_close: boolean; report_baseline: boolean };
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

/** Where the relationship stands: the stage, the day it began and the day the current retainer began. */
export interface ClientStage {
    stage: LifecycleStage;
    since: string | null;
    retainer_from: string | null;
}

/** A live project of the client as Dane lists it. */
export interface DetailsProject {
    id: number;
    name: string;
    is_private: boolean;
    include_in_month_close: boolean;
    repositories: number;
}
