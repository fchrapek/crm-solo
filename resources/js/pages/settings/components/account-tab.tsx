import { KvRow } from '@/components/solo/kv-row';
import { ListRow } from '@/components/solo/list-row';
import { SectionAction, SectionHead } from '@/components/solo/primitives';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import users from '@/routes/users';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { AccountUser, Me } from '../types';
import styles from './tab.module.css';
import { UserDialog, type UserDialogMode } from './user-dialog';

/** Figma: Ustawienia / Konto (466:7567), phones 473:8038. The profile, then the account's users. */
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
            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={t('Profile')}
                    action={
                        <SectionAction onClick={() => setDialog({ kind: 'profile', user: { ...me, owner: isOwner } })}>{t('Edit')}</SectionAction>
                    }
                />
                <dl className={styles.list}>
                    <KvRow label={t('Name')}>
                        {me.first_name} {me.last_name}
                    </KvRow>
                    <KvRow label={t('Email')}>{me.email}</KvRow>
                    <KvRow label={t('Password')}>••••••••••</KvRow>
                </dl>
            </section>

            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={`${t('Users')} · ${active.length}`}
                    action={isOwner ? <SectionAction onClick={() => setDialog({ kind: 'create' })}>{t('New user')}</SectionAction> : undefined}
                />
                <ul className={`${styles.list} ${styles.phone}`}>
                    {members.map((member) => (
                        <li key={member.id}>
                            {opens(member) ? (
                                <ListRow title={member.name} sub={role(member)} onClick={() => open(member)} />
                            ) : (
                                <ListRow title={member.name} sub={role(member)} trailing={null} disabled />
                            )}
                        </li>
                    ))}
                </ul>
                <dl className={`${styles.list} ${styles.wide}`}>
                    {members.map((member) => (
                        <KvRow
                            key={member.id}
                            label={
                                opens(member) ? (
                                    <button type="button" className={styles.plainButton} onClick={() => open(member)}>
                                        {member.name}
                                    </button>
                                ) : (
                                    member.name
                                )
                            }
                            tone={member.deleted_at !== null ? 'muted' : undefined}
                        >
                            {role(member)}
                        </KvRow>
                    ))}
                </dl>
            </section>

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
