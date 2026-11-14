import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from './action-menu';
import { IconButton } from './icon-button';

/** A row's secondary actions behind its "more" button, named after the row for a screen reader. */
export function RowMenu({ name, children }: { name: string; children: ReactNode }) {
    const { t } = useTranslation();

    return <ActionMenu trigger={<IconButton icon="more" look="quiet" label={`${t('More actions')}: ${name}`} />}>{children}</ActionMenu>;
}
