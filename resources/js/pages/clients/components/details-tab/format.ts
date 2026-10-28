import type { ClientCooperationType, MonthCloseType } from '@/types';
import type { ClientDetails } from './types';

const COOPERATION_LABEL: Record<ClientCooperationType, string> = {
    retainer: 'Retainer',
    hourly: 'Hourly contract',
    project: 'Project',
    one_off: 'One-off',
};

const MONTH_CLOSE_LABEL: Record<Exclude<MonthCloseType, null>, string> = {
    maintenance: 'site maintenance',
    gig: 'agency gig',
};

export function cooperationLine(details: Pick<ClientDetails, 'cooperation_type' | 'month_close_type'>, t: (key: string) => string): string {
    return [
        details.cooperation_type ? t(COOPERATION_LABEL[details.cooperation_type]) : null,
        details.month_close_type ? t(MONTH_CLOSE_LABEL[details.month_close_type]) : null,
    ]
        .filter(Boolean)
        .join(', ');
}

export function addressLine(details: Pick<ClientDetails, 'address' | 'postal_code' | 'city'>): string {
    const town = [details.postal_code, details.city].filter(Boolean).join(' ');

    return [details.address, town].filter(Boolean).join(', ');
}

/** Only a ten-digit Polish NIP is regrouped; EU VAT ids stay as typed. */
export function taxIdLine(taxId: string | null): string {
    const value = (taxId ?? '').trim();
    const digits = value.replace(/[\s-]/g, '');

    return /^\d{10}$/.test(digits) ? `${digits.slice(0, 3)}-${digits.slice(3, 6)}-${digits.slice(6, 8)}-${digits.slice(8)}` : value;
}
