import { Head, useForm, usePage } from '@inertiajs/react';
import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import { destroy, restore, update } from '@/actions/App/Http/Controllers/UsersController';
import { Form, FormInput, FormLabel, FormMessage } from '@/components/form';
import { SubmitButton } from '@/components/submit-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePageActions } from '@/contexts/page-context';
import { useDeletionControls } from '@/hooks/use-deletion-controls';
import { useFormProcessing } from '@/hooks/use-form-processing';
import users from '@/routes/users';
import { BreadcrumbItem, SharedData, User, UserFormData } from '@/types';

interface EditPageProps extends SharedData {
    user: User & { password: string; photo: File | null };
}

export default function Edit() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();

    const { user } = usePage<EditPageProps>().props;

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'User',
                count: 2,
                href: users.index().url,
            },
            {
                title: `${user.first_name} ${user.last_name}`,
                href: users.edit(user.id).url,
            },
        ],
        [user.first_name, user.last_name, user.id],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const form = useForm<Required<UserFormData>>({
        first_name: user.first_name || '',
        last_name: user.last_name || '',
        email: user.email || '',
        password: user.password || '',
        owner: user.owner ? '1' : '0',
    });

    const isProcessing = useFormProcessing(form.processing);

    function onSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();

        form.transform((data) => ({
            ...data,
            password: data.password === '' ? undefined : data.password,
        }));

        form.submit(update(user), {
            preserveScroll: true,
        });
    }

    const { showDeleteControls } = useDeletionControls({
        isDeleted: !!user.deleted_at,
        resourceType: 'user',
        canDelete: user.can_delete,
        deleteAction: destroy(user),
        restoreAction: restore(user),
    });

    return (
        <>
            <Head title={`${form.data.first_name} ${form.data.last_name}`} />

            {!user.can_delete ? (
                <Alert className="warningAlert">
                    <AlertDescription className="warningAlertDescription">{t('Updating the demo user is not allowed.')}</AlertDescription>
                </Alert>
            ) : (
                user.deleted_at && showDeleteControls()
            )}

            <div className="formContainer">
                <h2 className="sectionTitle">{t('Edit User')}</h2>

                <Form onSubmit={onSubmit}>
                    <div className="formSection">
                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="first_name" error={form.errors.first_name}>
                                    {t('First name')}
                                </FormLabel>

                                <FormInput
                                    id="first_name"
                                    type="text"
                                    value={form.data.first_name}
                                    onChange={(e) => form.setData('first_name', e.target.value)}
                                    required
                                    autoComplete="given-name"
                                    disabled={isProcessing || !user.can_delete}
                                    error={form.errors.first_name}
                                />

                                <FormMessage error={form.errors.first_name} />
                            </div>

                            <div>
                                <FormLabel htmlFor="last_name" error={form.errors.last_name}>
                                    {t('Last name')}
                                </FormLabel>

                                <FormInput
                                    id="last_name"
                                    type="text"
                                    value={form.data.last_name}
                                    onChange={(e) => form.setData('last_name', e.target.value)}
                                    required
                                    autoComplete="family-name"
                                    disabled={isProcessing || !user.can_delete}
                                    error={form.errors.last_name}
                                />

                                <FormMessage error={form.errors.last_name} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="email" error={form.errors.email}>
                                    {t('Email')}
                                </FormLabel>

                                <FormInput
                                    id="email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    required
                                    autoComplete="email"
                                    disabled={isProcessing || !user.can_delete}
                                    error={form.errors.email}
                                />

                                <FormMessage error={form.errors.email} />
                            </div>

                            <div>
                                <FormLabel htmlFor="password" error={form.errors.password}>
                                    {t('Password')}
                                </FormLabel>

                                <FormInput
                                    id="password"
                                    type="password"
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                    autoComplete="new-password"
                                    disabled={isProcessing || !user.can_delete}
                                    error={form.errors.password}
                                />

                                <FormMessage error={form.errors.password} />
                            </div>
                        </div>

                        <div>
                            <FormLabel htmlFor="owner" error={form.errors.owner}>
                                {t('Owner')}
                            </FormLabel>

                            <Select
                                value={form.data.owner}
                                onValueChange={(value) => form.setData('owner', value)}
                                disabled={isProcessing || !user.can_delete}
                            >
                                <SelectTrigger id="owner" className={form.errors.owner ? 'selectError' : ''}>
                                    <SelectValue placeholder={t('Select an option')} />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="1">{t('Yes')}</SelectItem>
                                    <SelectItem value="0">{t('No')}</SelectItem>
                                </SelectContent>
                            </Select>

                            <FormMessage error={form.errors.owner} />
                        </div>

                        <div className="formActionsWithDelete">
                            {!user.deleted_at && user.can_delete && showDeleteControls()}

                            <SubmitButton processing={isProcessing} disabled={!user.can_delete}>
                                {t('Update User')}
                            </SubmitButton>
                        </div>
                    </div>
                </Form>
            </div>
        </>
    );
}
