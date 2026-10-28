import type { FieldOption, FieldType } from '@/pages/settings/types';

export interface CardField {
    key: string;
    label: string;
    type: FieldType;
    options: FieldOption[];
    required: boolean;
    on_overview: boolean;
    archived: boolean;
    /** The value as people read it, null when empty. */
    display: string | null;
    /** The value as the form control holds it. */
    input: string | string[];
}

export interface ClientCard {
    fields: CardField[];
    assignee: { id: number; name: string } | null;
    sms_opt_out: boolean;
    users: { id: number; name: string }[];
}

export interface FieldEvent {
    id: number;
    day: string;
    time: string;
    user: string | null;
    key: string;
    label: string;
    old: string | null;
    new: string | null;
}

export type MessageStatus = 'pending' | 'sending' | 'sent' | 'simulated' | 'failed' | 'cancelled' | 'skipped' | 'superseded';

export interface MessageRow {
    id: number;
    client_id: number;
    client: string | null;
    day: string;
    time: string;
    status: MessageStatus;
    /** Waiting while the client gets no texts: it goes only if they are switched back on before it expires. */
    held: boolean;
    template: string | null;
    body: string | null;
    to: string | null;
    error: string | null;
    /** Failed without an answer from the provider, so the client may already have it: sending again asks first. */
    may_have_gone_out: boolean;
    /** How many times a failed copy was sent again; previous_error is the failure before the last one. */
    resends: number;
    previous_error: string | null;
    expires: string;
}
