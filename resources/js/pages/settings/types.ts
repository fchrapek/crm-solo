export const SETTINGS_TABS = ['general', 'leads', 'forms', 'messages', 'integrations'] as const;

export type SettingsTab = (typeof SETTINGS_TABS)[number];

export const TAB_LABEL: Record<SettingsTab, string> = {
    general: 'General',
    leads: 'Leads',
    forms: 'Forms',
    messages: 'Messages',
    integrations: 'Integrations',
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
    skipped_lists: string[] | null;
    seen_lists: string[] | null;
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

export interface EmailTemplateRow {
    key: string;
    name: string;
    subject: string;
    body: string;
    is_default: boolean;
}

/** How a client email goes out: live only once a sender is set on the server. */
export interface EmailSending {
    live: boolean;
    refused?: boolean;
    from: string | null;
    bcc: string;
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
    /** Null where billing is off, which leaves Messages without e-mail. */
    email_templates: number | null;
    default_sources: number;
    custom_sources: number;
    connected: string[];
    tokens: { count: number; soonest_days: number | null };
    /** How many website forms and whether the sender is set; null in the demo. */
    forms: { count: number; sender: boolean } | null;
}

export type NoticeState = 'waiting' | 'failed' | 'sent' | 'skipped';

/** A website form as IntakeForms describes it: never a secret, only whether one is set. */
export interface IntakeFormRow {
    id: number;
    name: string;
    endpoint: string;
    origins: string[];
    pipeline: string;
    source: string;
    notify_to: string | null;
    active: boolean;
    turnstile_secret_set: boolean;
    webhook_secret_set: boolean;
    notices: Record<NoticeState, number>;
}

/** The account's sender without its password, which shows only as set or not. */
export type SenderRow =
    | { configured: false }
    | {
          configured: true;
          enabled: boolean;
          host: string | null;
          port: number | null;
          username: string | null;
          from_address: string | null;
          from_name: string | null;
          password_set: boolean;
          password_readable: boolean;
          last_test: { at: string; ok: boolean; reason: string | null } | null;
      };

export interface Choice {
    value: string;
    label: string;
}

export interface IntakeSettings {
    /** The form whose page is open, or null for the list. */
    form_id: number | null;
    forms: IntakeFormRow[];
    /** Failed notices per form id: reason code and count. */
    failures: Record<string, Record<string, number>>;
    sender: SenderRow;
    pipelines: Choice[];
    sources: Choice[];
    max_age_days: number;
}

/** The account's modules: a switch is null where billing is off; the others are counts of what is set up for them. */
export interface AccountModules {
    month_close: boolean | null;
    /** Clients the month close holds; while any are, the module refuses to switch off. */
    month_close_in_use: number;
    report_baseline: boolean | null;
    client_fields: number;
    sms_rules: number;
}

export interface SettingsProps {
    tab: SettingsTab | null;
    summary?: SettingsSummary;
    users?: AccountUser[];
    modules?: AccountModules;
    leadgen?: LeadgenSettings;
    client_fields?: FieldDefinition[];
    /** Keys a new field may not take, such as the built-in assignee. */
    reserved_field_keys?: string[];
    sending?: SendingSettings;
    templates?: MessageTemplateRow[];
    rules?: MessageRuleRow[];
    email_templates?: EmailTemplateRow[];
    email_sending?: EmailSending;
    fields?: FieldDefinition[];
    integrations?: IntegrationRow[];
    demo?: boolean;
    server?: ServerConfig | null;
    tokens?: AgentTokenRow[];
    intake?: IntakeSettings;
    /** False in the demo, which has no website forms. */
    intake_available?: boolean;
    /** True in the demo, where the account's settings are shown and never saved. */
    read_only?: boolean;
    abilities?: string[];
    today?: string;
    [key: string]: unknown;
}
