import { fieldControl, SoloField, SoloFieldRow, SoloInput } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import users from '@/routes/users';
import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { AccountUser, Me } from '../types';
import styles from './tab.module.css';

export type UserDialogMode = { kind: 'profile'; user: Me & { owner: boolean } } | { kind: 'create' } | { kind: 'edit'; user: AccountUser };

const EMPTY = { first_name: '', last_name: '', email: '', password: '', current_password: '' };

/** The profile (own account), a new user and another user's edit: one form, a dialog from 768 px and a sheet below. */
export function UserDialog({ mode, onClose, onRemove }: { mode: UserDialogMode | null; onClose: () => void; onRemove: (user: AccountUser) => void }) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const [shown, setShown] = useState(mode);
    if (mode !== shown) {
        setShown(mode);
        if (mode !== null) {
            const user = mode.kind === 'create' ? null : mode.user;
            setDraft({ ...EMPTY, first_name: user?.first_name ?? '', last_name: user?.last_name ?? '', email: user?.email ?? '' });
            setErrors({});
        }
    }

    const current = mode ?? shown;
    const set = (key: keyof typeof EMPTY) => (value: string) => setDraft((was) => ({ ...was, [key]: value }));
    const creating = current?.kind === 'create';
    const editing = current?.kind === 'edit' ? current.user : null;

    function submit(event: FormEvent) {
        event.preventDefault();
        if (current === null) return;
        setProcessing(true);
        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };
        const names = { first_name: draft.first_name, last_name: draft.last_name, email: draft.email };

        if (current.kind === 'create') {
            router.post(users.store().url, { ...names, password: draft.password, owner: false }, options);

            return;
        }

        // A blank password means no change, so it is left out (the rule is `sometimes`).
        const password =
            draft.password === ''
                ? {}
                : { password: draft.password, ...(current.kind === 'profile' ? { current_password: draft.current_password } : {}) };
        router.put(users.update(current.user.id).url, { ...names, owner: current.user.owner, ...password }, options);
    }

    const title = creating ? t('New user') : current?.kind === 'profile' ? t('Edit profile') : t('Edit user');

    return (
        <ResponsiveDialog
            open={mode !== null}
            onOpenChange={(open) => !open && onClose()}
            title={title}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={creating ? t('Add') : t('Save')}
            saving={processing}
            footerClassName={styles.dialogActions}
            actions={(size) => (
                <>
                    {editing !== null && <RemoveAction user={editing} size={size} disabled={processing} onRemove={onRemove} />}
                    <SoloButton variant="pill" onClick={onClose} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : creating ? t('Add user') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SoloFieldRow>
                <SoloField label={t('First name')} htmlFor="user-first-name" error={errors.first_name}>
                    <SoloInput
                        {...fieldControl('user-first-name', errors.first_name)}
                        value={draft.first_name}
                        onChange={(e) => set('first_name')(e.target.value)}
                        required
                        maxLength={25}
                        autoComplete="given-name"
                        disabled={processing}
                    />
                </SoloField>
                <SoloField label={t('Last name')} htmlFor="user-last-name" error={errors.last_name}>
                    <SoloInput
                        {...fieldControl('user-last-name', errors.last_name)}
                        value={draft.last_name}
                        onChange={(e) => set('last_name')(e.target.value)}
                        required
                        maxLength={25}
                        autoComplete="family-name"
                        disabled={processing}
                    />
                </SoloField>
            </SoloFieldRow>
            <SoloField label={t('Email')} htmlFor="user-email" error={errors.email}>
                <SoloInput
                    {...fieldControl('user-email', errors.email)}
                    type="email"
                    value={draft.email}
                    onChange={(e) => set('email')(e.target.value)}
                    required
                    maxLength={50}
                    autoComplete="email"
                    disabled={processing}
                />
            </SoloField>
            <SoloField label={creating ? t('Password') : t('New password (optional)')} htmlFor="user-password" error={errors.password}>
                <SoloInput
                    {...fieldControl('user-password', errors.password)}
                    type="password"
                    value={draft.password}
                    onChange={(e) => set('password')(e.target.value)}
                    required={creating}
                    autoComplete="new-password"
                    disabled={processing}
                />
            </SoloField>
            {current?.kind === 'profile' && draft.password !== '' && (
                <SoloField label={t('Current password')} htmlFor="user-current-password" error={errors.current_password}>
                    <SoloInput
                        {...fieldControl('user-current-password', errors.current_password)}
                        type="password"
                        value={draft.current_password}
                        onChange={(e) => set('current_password')(e.target.value)}
                        required
                        autoComplete="current-password"
                        disabled={processing}
                    />
                </SoloField>
            )}
            {editing !== null && (
                <div className={styles.sheetEnd}>
                    <RemoveAction user={editing} size="touch" disabled={processing} onRemove={onRemove} />
                </div>
            )}
        </ResponsiveDialog>
    );
}

/** Delete for a live user, Restore for a deleted one; the caller confirms or restores. */
function RemoveAction({
    user,
    size,
    disabled,
    onRemove,
}: {
    user: AccountUser;
    size: 'md' | 'touch';
    disabled: boolean;
    onRemove: (user: AccountUser) => void;
}) {
    const { t } = useTranslation();

    if (user.deleted_at === null && !user.can_delete) return null;

    return (
        <SoloButton variant="text" size={size} data-start="" disabled={disabled} onClick={() => onRemove(user)}>
            {user.deleted_at === null ? t('Delete user') : t('Restore user')}
        </SoloButton>
    );
}
