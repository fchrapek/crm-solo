import { Chip } from '@/components/solo/chip';
import { KvRow } from '@/components/solo/kv-row';
import { Row } from '@/components/solo/row';
import { Tile, TileHeader, TileRows } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import users from '@/routes/users';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { AccountUser, Me } from '../types';
import { RowButton, TileButton } from './parts';
import styles from './tab.module.css';
import { UserDialog, type UserDialogMode } from './user-dialog';

/** Figma: Ustawienia · Konto (1090:9949, phone 1090:10299). The profile, then the account's users. */
export function AccountTab({ me, users: members, isOwner }: { me: Me; users: AccountUser[]; isOwner: boolean }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<UserDialogMode | null>(null);
    const [removing, setRemoving] = useState<AccountUser | null>(null);
    const active = members.filter((member) => member.deleted_at === null);

    const role = (member: AccountUser) => {
        if (member.deleted_at !== null) return t('deleted, can be restored');
        const kind = member.owner ? t('owner') : t('user');

        return member.is_me ? t('{{role}}, that is you', { role: kind }) : kind;
    };
    const open = (member: AccountUser) => {
        if (member.is_me) return setDialog({ kind: 'profile', user: { ...me, owner: isOwner } });
        if (isOwner) setDialog({ kind: 'edit', user: member });
    };
    const opens = (member: AccountUser) => member.is_me || isOwner;

    return (
        <>
            <Tile kind="plain">
                <TileHeader
                    label={t('Profile')}
                    end={<TileButton onClick={() => setDialog({ kind: 'profile', user: { ...me, owner: isOwner } })}>{t('Edit')}</TileButton>}
                />
                <dl className={styles.kv}>
                    <KvRow layout="tile" label={t('Name')}>
                        {me.first_name} {me.last_name}
                    </KvRow>
                    <KvRow layout="tile" label={t('Email')}>
                        {me.email}
                    </KvRow>
                    <KvRow layout="tile" label={t('Password')}>
                        ••••••••••
                    </KvRow>
                </dl>
            </Tile>

            <Tile kind="list">
                <TileHeader
                    label={t('Users')}
                    count={active.length}
                    end={isOwner ? <TileButton onClick={() => setDialog({ kind: 'create' })}>{t('New user')}</TileButton> : undefined}
                />
                <TileRows>
                    {members.map((member, index) => (
                        <Row
                            key={member.id}
                            rule={index > 0}
                            title={member.name}
                            meta={member.deleted_at === null ? role(member) : undefined}
                            chip={member.deleted_at !== null ? <Chip status="open">{role(member)}</Chip> : undefined}
                            action={opens(member) ? <RowButton onClick={() => open(member)}>{t('Edit')}</RowButton> : undefined}
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
