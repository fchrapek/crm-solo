import { Chip } from '@/components/solo/chip';
import { Row } from '@/components/solo/row';
import { Tile, TileHeader, TileRows } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import users from '@/routes/users';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { AccountUser } from '../types';
import { RowButton, TileButton } from './parts';
import { UserDialog, type UserDialogMode } from './user-dialog';

/** Figma: Settings · Ogólne (1557:345025), Użytkownicy. The account's users in the side column; the owner's own row opens the profile form. */
export function UsersTile({ users: members }: { users: AccountUser[] }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<UserDialogMode | null>(null);
    const [removing, setRemoving] = useState<AccountUser | null>(null);
    const active = members.filter((member) => member.deleted_at === null);

    const role = (member: AccountUser) => {
        if (member.deleted_at !== null) return t('deleted, can be restored');
        const kind = member.owner ? t('owner') : t('user');

        return member.is_me ? t('{{role}}, that is you', { role: kind }) : kind;
    };
    const open = (member: AccountUser) => setDialog(member.is_me ? { kind: 'profile', user: member } : { kind: 'edit', user: member });

    return (
        <>
            <Tile kind="list">
                <TileHeader
                    label={t('Users')}
                    count={active.length}
                    end={<TileButton onClick={() => setDialog({ kind: 'create' })}>{t('New user')}</TileButton>}
                />
                <TileRows>
                    {members.map((member, index) => (
                        <Row
                            key={member.id}
                            rule={index > 0}
                            wrap
                            title={member.name}
                            meta={member.deleted_at === null ? role(member) : undefined}
                            chip={member.deleted_at !== null ? <Chip status="open">{role(member)}</Chip> : undefined}
                            action={<RowButton onClick={() => open(member)}>{t('Edit')}</RowButton>}
                        />
                    ))}
                </TileRows>
            </Tile>

            <UserDialog
                mode={dialog}
                onClose={() => setDialog(null)}
                onRemove={(member) => {
                    setDialog(null);
                    if (member.deleted_at === null) {
                        setRemoving(member);
                    } else {
                        router.put(users.restore(member.id).url, {}, { preserveScroll: true });
                    }
                }}
            />
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(value) => !value && setRemoving(null)}
                title={t('Delete user')}
                description={t('{{name}} loses access to this CRM. You can restore the user later.', { name: removing?.name ?? '' })}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() => {
                    if (removing !== null) router.delete(users.destroy(removing.id).url, { preserveScroll: true });
                    setRemoving(null);
                }}
            />
        </>
    );
}
