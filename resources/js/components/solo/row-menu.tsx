import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from './action-menu';
import { IconButton } from './icon-button';

/** A row's secondary actions behind its "more" button, named after the row for a screen reader; open is given when a second step leads back to it. */
export function RowMenu({
    name,
    open,
    onOpenChange,
    children,
}: {
    name: string;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    children: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <ActionMenu open={open} onOpenChange={onOpenChange} trigger={<IconButton icon="more" look="quiet" label={`${t('More actions')}: ${name}`} />}>
            {children}
        </ActionMenu>
    );
}
