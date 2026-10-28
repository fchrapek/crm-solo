export const SETTINGS_TABS = ['konto', 'wyglad', 'leady', 'pola-klienta', 'sms', 'integracje', 'agenci'] as const;

export type SettingsTab = (typeof SETTINGS_TABS)[number];

export const TAB_LABEL: Record<SettingsTab, string> = {
    konto: 'Account',
    wyglad: 'Appearance',
    leady: 'Leads',
    'pola-klienta': 'Client fields',
    sms: 'Text messages',
    integracje: 'Integrations',
    agenci: 'Agents',
};

export interface Stamp {
    at: string;
    day: string;
    time: string;
}

export interface Me {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
}

export interface AccountUser extends Me {
    name: string;
    owner: boolean;
    is_me: boolean;
    deleted_at: string | null;
    can_delete: boolean;
}

export interface LeadgenSettings {
    default_sources: string[];
    custom_sources: string[];
    source_labels: Record<string, string>;
}

export interface IntegrationRow {
    provider: 'trello' | 'infakt' | string;
    name: string;
    is_enabled: boolean;
    is_configured: boolean;
    has_api_key: boolean;
    has_trello_api_key: boolean;
    has_unreadable_credentials: boolean;
    last_synced: Stamp | null;
    last_sync_error: string | null;
    client_conflicts: string[];
    boards: number | null;
    syncs: boolean;
}

export interface ServerConfig {
    openai: boolean;
    kiwwwi_forms: { polled: number; total: number };
}

export interface AgentTokenRow {
    id: number;
    name: string;
    user: string | null;
    abilities: string[];
    last_used: Stamp | null;
    expires_in_days: number | null;
}

export type FieldType = 'text' | 'textarea' | 'select' | 'multiselect' | 'date' | 'datetime' | 'url';

export interface FieldOption {
    key: string;
    label: string;
}

export interface FieldDefinition {
    key: string;
    label: string;
    type: FieldType;
    options: FieldOption[];
    required: boolean;
    on_overview: boolean;
    archived: boolean;
    values_count?: number;
}

export interface MessageTemplateRow {
    key: string;
    name: string;
    body: string;
    rules: number;
}

export interface MessageRuleRow {
    key: string;
    template: string | null;
    template_name: string | null;
    trigger: 'field_value' | 'date_offset';
    field: string;
    values: string[];
    offset_minutes: number;
    at: string | null;
    expire_minutes: number;
    enabled: boolean;
    pending: number;
}

export interface SendingSettings {
    quiet_from: string;
    quiet_to: string;
    ascii: boolean;
    provider: string;
    smsapi_connected: boolean;
}

export interface SettingsSummary {
    users: number;
    client_fields: number;
    message_rules: number;
    default_sources: number;
    custom_sources: number;
    connected: string[];
    tokens: { count: number; soonest_days: number | null } | null;
}

export interface SettingsProps {
    tab: SettingsTab | null;
    is_owner: boolean;
    summary?: SettingsSummary;
    me?: Me;
    users?: AccountUser[];
    leadgen?: LeadgenSettings;
    client_fields?: FieldDefinition[];
    /** Keys a new field may not take, such as the built-in assignee. */
    reserved_field_keys?: string[];
    sending?: SendingSettings;
    templates?: MessageTemplateRow[];
    rules?: MessageRuleRow[];
    fields?: FieldDefinition[];
    integrations?: IntegrationRow[];
    demo?: boolean;
    server?: ServerConfig | null;
    tokens?: AgentTokenRow[];
    abilities?: string[];
    today?: string;
    [key: string]: unknown;
}
