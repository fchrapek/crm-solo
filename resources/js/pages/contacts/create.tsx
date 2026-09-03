import { Head, useForm, usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import { store } from '@/actions/App/Http/Controllers/ContactsController';
import { EmailListInput } from '@/components/email-list-input';
import { Form, FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePageActions } from '@/contexts/page-context';
import contacts from '@/routes/contacts';
import { BreadcrumbItem, Client, ContactFormData, SharedData } from '@/types';

interface CreatePageProps extends SharedData {
    clients: Client[];
}

export default function Create() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Contact',
                count: 2,
                href: contacts.index().url,
            },
            {
                title: 'Create',
                href: contacts.create().url,
            },
        ],
        [],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const { clients } = usePage<CreatePageProps>().props;
    const form = useForm<Required<ContactFormData>>({
        first_name: '',
        last_name: '',
        client_id: '',
        emails: [],
        phone: '',
        address: '',
        city: '',
        region: '',
        country: '',
        postal_code: '',
        position: '',
        phone_secondary: '',
        notes: '',
    });

    function onSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        form.submit(store());
    }

    return (
        <>
            <Head title={t('Create Contact')} />

            <div className="formContainer">
                <h2 className="sectionTitle">{t('Create Contact')}</h2>

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
                                    autoFocus
                                    tabIndex={1}
                                    maxLength={25}
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
                                    tabIndex={2}
                                    maxLength={25}
                                    disabled={form.processing}
                                    error={form.errors.last_name}
                                />

                                <FormMessage error={form.errors.last_name} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="client_id" error={form.errors.client_id}>
                                    {t('Client', { count: 1 })}
                                </FormLabel>

                                <Select
                                    value={form.data.client_id || '0'}
                                    onValueChange={(value) => form.setData('client_id', value === '0' ? '' : value)}
                                    disabled={form.processing}
                                >
                                    <SelectTrigger id="client_id" className={form.errors.client_id ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('Select a client')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="0">{t('None')}</SelectItem>
                                        {clients.map(({ id, name }) => (
                                            <SelectItem key={id} value={id.toString()}>
                                                {name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <FormMessage error={form.errors.client_id} />
                            </div>

                            <div>
                                <FormLabel htmlFor="email-0">{t('Emails')}</FormLabel>

                                <EmailListInput
                                    value={form.data.emails}
                                    onChange={(next) => form.setData('emails', next)}
                                    errors={form.errors as Record<string, string>}
                                    disabled={form.processing}
                                />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="phone" error={form.errors.phone}>
                                    {t('Phone')}
                                </FormLabel>

                                <FormInput
                                    id="phone"
                                    type="tel"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    tabIndex={5}
                                    maxLength={50}
                                    disabled={form.processing}
                                    error={form.errors.phone}
                                />

                                <FormMessage error={form.errors.phone} />
                            </div>

                            <div>
                                <FormLabel htmlFor="address" error={form.errors.address}>
                                    {t('Address')}
                                </FormLabel>

                                <FormInput
                                    id="address"
                                    type="text"
                                    value={form.data.address}
                                    onChange={(e) => form.setData('address', e.target.value)}
                                    tabIndex={6}
                                    maxLength={150}
                                    disabled={form.processing}
                                    error={form.errors.address}
                                />

                                <FormMessage error={form.errors.address} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="city" error={form.errors.city}>
                                    {t('City')}
                                </FormLabel>

                                <FormInput
                                    id="city"
                                    type="text"
                                    value={form.data.city}
                                    onChange={(e) => form.setData('city', e.target.value)}
                                    tabIndex={7}
                                    maxLength={50}
                                    disabled={form.processing}
                                    error={form.errors.city}
                                />

                                <FormMessage error={form.errors.city} />
                            </div>

                            <div>
                                <FormLabel htmlFor="region" error={form.errors.region}>
                                    {t('Province/State')}
                                </FormLabel>

                                <FormInput
                                    id="region"
                                    type="text"
                                    value={form.data.region}
                                    onChange={(e) => form.setData('region', e.target.value)}
                                    tabIndex={8}
                                    maxLength={50}
                                    disabled={form.processing}
                                    error={form.errors.region}
                                />

                                <FormMessage error={form.errors.region} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="country" error={form.errors.country}>
                                    {t('Country')}
                                </FormLabel>

                                <Select
                                    value={form.data.country || '0'}
                                    onValueChange={(value) => form.setData('country', value === '0' ? '' : value)}
                                    disabled={form.processing}
                                >
                                    <SelectTrigger id="country" className={form.errors.country ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('Select a country')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="0">{t('None')}</SelectItem>
                                        <SelectItem value="CA">{t('Canada')}</SelectItem>
                                        <SelectItem value="US">{t('United States')}</SelectItem>
                                    </SelectContent>
                                </Select>

                                <FormMessage error={form.errors.country} />
                            </div>

                            <div>
                                <FormLabel htmlFor="postal_code" error={form.errors.postal_code}>
                                    {t('Postal Code')}
                                </FormLabel>

                                <FormInput
                                    id="postal_code"
                                    type="text"
                                    value={form.data.postal_code}
                                    onChange={(e) => form.setData('postal_code', e.target.value)}
                                    tabIndex={10}
                                    maxLength={25}
                                    disabled={form.processing}
                                    error={form.errors.postal_code}
                                />

                                <FormMessage error={form.errors.postal_code} />
                            </div>
                        </div>

                        <div className="formActions">
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Loader2 className="spinner" />}
                                {t('Create Contact')}
                            </Button>
                        </div>
                    </div>
                </Form>
            </div>
        </>
    );
}
