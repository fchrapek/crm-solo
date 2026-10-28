import { ActionMenuItem } from '@/components/solo/action-menu';
import { useInHeaderMenu } from '@/components/solo/client-header';
import { IconButton } from '@/components/solo/icon-button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import clients from '@/routes/clients';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export function setClientPinned(clientId: number, pinned: boolean): void {
    router.put(clients.pin.url(clientId), { pinned }, { preserveScroll: true });
}

export function PinMenuAction({ clientId, pinned }: { clientId: number; pinned: boolean }) {
    const { t } = useTranslation();
    const inHeaderMenu = useInHeaderMenu();

    if (inHeaderMenu) {
        return (
            <ActionMenuItem icon={<Icon name="pin" size={16} filled />} onSelect={() => setClientPinned(clientId, !pinned)}>
                {t(pinned ? 'Unpin from the list' : 'Pin on the list')}
            </ActionMenuItem>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <IconButton icon="more" size="sm" label={t('More actions')} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" sideOffset={8} collisionPadding={16}>
                <ActionMenuItem icon={<Icon name="pin" size={16} filled={!pinned} />} onSelect={() => setClientPinned(clientId, !pinned)}>
                    {t(pinned ? 'Unpin from the list' : 'Pin on the list')}
                </ActionMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
