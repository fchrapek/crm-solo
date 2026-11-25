import type { Me } from '@/pages/settings/types';

/** Helper per device kind, saved to the user's account. */
export interface HelperSettings {
    helper_desktop: boolean;
    helper_phone: boolean;
}

export interface TaskSettings {
    warn_full_day: boolean;
    /** Null for a member: billed time is the owner's. */
    show_billed_on_tasks: boolean | null;
}

export interface ProfileProps {
    me: Me;
    is_owner: boolean;
    /** The account's name and, for a member, its owners' names. */
    account: { name: string; owners: string[] };
    task_settings: TaskSettings;
    helper_settings: HelperSettings;
    [key: string]: unknown;
}
