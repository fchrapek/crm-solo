import { ActionMenuItem } from '@/components/solo/action-menu';
import { useInHeaderMenu } from '@/components/solo/client-header';
import { Icon } from '@/components/ui/icon';
import { useTranslation } from 'react-i18next';
import { type BillingDialog, openingRowOf } from './client-billing';
import type { BillingLedger, RetainerBilling } from './types';

export type BillingAction = Exclude<BillingDialog, null>;

/** The phone ••• menu (Figma 334:3721), where desktop has section actions; Nowa faktura waits for the invoice track. */
export function billingActionOrder(inMenu: boolean, billing: RetainerBilling, ledger: BillingLedger): BillingAction[] {
    if (!inMenu) {
        return [];
    }

    return openingRowOf(billing, ledger) !== undefined ? ['retainer', 'opening'] : ['retainer'];
}

export function BillingActions({
    billing,
    ledger,
    onOpen,
}: {
    billing: RetainerBilling;
    ledger: BillingLedger;
    onOpen: (action: BillingAction) => void;
}) {
    const { t } = useTranslation();

    return (
        <>
            {billingActionOrder(useInHeaderMenu(), billing, ledger).map((action) =>
                action === 'retainer' ? (
                    <ActionMenuItem key="retainer" icon={<Icon name="pencil" size={16} />} onSelect={() => onOpen('retainer')}>
                        {billing.retainer.positions.length > 0 ? t('Change retainer') : t('Add a retainer')}
                    </ActionMenuItem>
                ) : (
                    <ActionMenuItem key="opening" icon={<Icon name="hours" size={16} />} onSelect={() => onOpen('opening')}>
                        {t('Change opening balance')}
                    </ActionMenuItem>
                ),
            )}
        </>
    );
}
