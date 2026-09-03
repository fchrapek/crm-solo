import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export interface BreadcrumbItem {
    title: string;
    count?: number;
    href: string;
}

export interface NavItem {
    title: string;
    count?: number;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface User {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    email: string;
    avatar?: string;
    owner: string;
    deleted_at: string;
    account: Account;
    can_delete: boolean;
    [key: string]: unknown;
}

export type UserFormData = {
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    owner: string;
};

export interface Client {
    id: number;
    type: 'business' | 'individual';
    name: string;
    email: string;
    phone: string;
    address: string;
    city: string;
    region: string;
    country: string;
    postal_code: string;
    tax_id: string;
    business_type: string;
    currency: ClientCurrency;
    hourly_rate: number | null;
    notes: string;
    is_pinned: boolean;
    month_close_type: MonthCloseType;
    include_in_month_close: boolean;
    report_mode: ReportMode;
    ssh_config: string | null;
    maintenance_invoice_description: string | null;
    lifecycle_stage: LifecycleStage;
    segment: ClientSegment | null;
    cooperation_type: ClientCooperationType | null;
    deleted_at: string;
    contacts_count: number;
    contacts: Contact[];
}

export type LifecycleStage = 'prospect' | 'offer_sent' | 'active' | 'paused' | 'churned';

export type MonthCloseType = 'maintenance' | 'gig' | null;

export type ReportMode = 'report' | 'summary_email' | 'none';

export type ClientSegment = 'agency' | 'smb' | 'enterprise' | 'individual';

export type ClientCooperationType = 'retainer' | 'hourly' | 'project' | 'one_off';

export type ClientCurrency = 'PLN' | 'EUR' | 'USD' | 'GBP';

export type ClientFormData = {
    type: 'business' | 'individual';
    name: string;
    email: string;
    phone: string;
    address: string;
    city: string;
    region: string;
    country: string;
    postal_code: string;
    tax_id: string;
    business_type: string;
    segment: ClientSegment | '';
    cooperation_type: ClientCooperationType | '';
    currency: ClientCurrency;
    hourly_rate: string;
    ssh_config: string;
    maintenance_invoice_description: string;
    include_in_month_close: boolean;
    notes: string;
};

export interface Contact {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    emails: string[];
    phone: string;
    address: string;
    city: string;
    region: string;
    country: string;
    postal_code: string;
    position: string;
    phone_secondary: string;
    notes: string;
    deleted_at: string;
    client_id: number;
    client: Client;
}

export type ContactFormData = {
    first_name: string;
    last_name: string;
    client_id: string;
    emails: string[];
    phone: string;
    address: string;
    city: string;
    region: string;
    country: string;
    postal_code: string;
    position: string;
    phone_secondary: string;
    notes: string;
};

export type PaginatedData<T> = {
    data: T[];
    links: {
        first: string;
        last: string;
        prev: string | null;
        next: string | null;
    };

    meta: {
        current_page: number;
        from: number;
        last_page: number;
        path: string;
        per_page: number;
        to: number;
        total: number;

        links: {
            url: null | string;
            label: string;
            active: boolean;
        }[];
    };
};

interface TranslationStore {
    [locale: string]: {
        translation: Record<string, string>;
    };
}

export interface LiveSession {
    task_id: number;
    task_name: string;
    project_id: number;
    client_id: number | null;
    session_attention_at: string | null;
}

export interface SharedData {
    name: string;
    auth: Auth;
    locale: string;
    translations: TranslationStore | null;
    live_sessions?: LiveSession[];
    terminal_session_host: string;
    [key: string]: unknown;
}
