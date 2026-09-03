import { Head, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import React, { FormEvent, useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import { store } from '@/actions/App/Http/Controllers/UsersController';
import { Form, FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePageActions } from '@/contexts/page-context';
import users from '@/routes/users';
import { BreadcrumbItem, UserFormData } from '@/types';

export default function Create() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Users',
                count: 2,
                href: users.index().url,
            },
            {
                title: 'Create',
                href: users.create().url,
            },
        ],
        [],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const form = useForm<Required<UserFormData>>({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        owner: '0',
    });

    function onSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        form.submit(store());
    }

    return (
        <>
            <Head title={t('Create User')} />

            <div className="formContainer">
                <h2 className="sectionTitle">{t('Create User')}</h2>

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
                                    disabled={form.processing}
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
                                    disabled={form.processing}
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
                                    disabled={form.processing}
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
                                    required
                                    autoComplete="new-password"
                                    disabled={form.processing}
                                    error={form.errors.password}
                                />

                                <FormMessage error={form.errors.password} />
                            </div>
                        </div>

                        <div>
                            <FormLabel htmlFor="owner" error={form.errors.owner}>
                                {t('Owner')}
                            </FormLabel>

                            <Select value={form.data.owner} onValueChange={(value) => form.setData('owner', value)} disabled={form.processing}>
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

                        <div className="formActions">
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Loader2 className="spinner" />}
                                {t('Create User')}
                            </Button>
                        </div>
                    </div>
                </Form>
            </div>
        </>
    );
}
