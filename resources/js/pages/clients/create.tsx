import { Head, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import { store } from '@/actions/App/Http/Controllers/ClientsController';
import { Form, FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePageActions } from '@/contexts/page-context';
import clients from '@/routes/clients';
import { BreadcrumbItem, ClientFormData } from '@/types';

export default function Create() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Client',
                count: 2,
                href: clients.index().url,
            },
            {
                title: 'Create',
                href: clients.create().url,
            },
        ],
        [],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const form = useForm<Required<ClientFormData>>({
        type: 'business',
        name: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        region: '',
        country: 'PL',
        postal_code: '',
        tax_id: '',
        business_type: '',
        segment: '',
        cooperation_type: '',
        currency: 'PLN',
        hourly_rate: '',
        ssh_config: '',
        maintenance_invoice_description: '',
        include_in_month_close: false,
        notes: '',
    });

    function onSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        form.submit(store());
    }

    const isBusinessType = form.data.type === 'business';

    return (
        <>
            <Head title={t('Create Client')} />

            <div className="formContainer">
                <h2 className="sectionTitle">{t('Create Client')}</h2>

                <Form onSubmit={onSubmit}>
                    <div className="formSection">
                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="type" error={form.errors.type}>
                                    {t('Type')}
                                </FormLabel>

                                <Select
                                    value={form.data.type}
                                    onValueChange={(value) => form.setData('type', value as 'business' | 'individual')}
                                    disabled={form.processing}
                                >
                                    <SelectTrigger id="type" className={form.errors.type ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('Select type')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="business">{t('Business')}</SelectItem>
                                        <SelectItem value="individual">{t('Individual')}</SelectItem>
                                    </SelectContent>
                                </Select>

                                <FormMessage error={form.errors.type} />
                            </div>

                            <div>
                                <FormLabel htmlFor="name" error={form.errors.name}>
                                    {t('Name')}
                                </FormLabel>

                                <FormInput
                                    id="name"
                                    type="text"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    required
                                    autoFocus
                                    maxLength={100}
                                    disabled={form.processing}
                                    error={form.errors.name}
                                />

                                <FormMessage error={form.errors.name} />
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
                                    maxLength={50}
                                    disabled={form.processing}
                                    error={form.errors.email}
                                />

                                <FormMessage error={form.errors.email} />
                            </div>

                            <div>
                                <FormLabel htmlFor="phone" error={form.errors.phone}>
                                    {t('Phone')}
                                </FormLabel>

                                <FormInput
                                    id="phone"
                                    type="tel"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    maxLength={50}
                                    disabled={form.processing}
                                    error={form.errors.phone}
                                />

                                <FormMessage error={form.errors.phone} />
                            </div>
                        </div>

                        {isBusinessType && (
                            <div className="formGrid">
                                <div>
                                    <FormLabel htmlFor="tax_id" error={form.errors.tax_id}>
                                        {t('Tax ID (NIP)')}
                                    </FormLabel>

                                    <FormInput
                                        id="tax_id"
                                        type="text"
                                        value={form.data.tax_id}
                                        onChange={(e) => form.setData('tax_id', e.target.value)}
                                        maxLength={20}
                                        disabled={form.processing}
                                        error={form.errors.tax_id}
                                    />

                                    <FormMessage error={form.errors.tax_id} />
                                </div>

                                <div>
                                    <FormLabel htmlFor="business_type" error={form.errors.business_type}>
                                        {t('Business Type')}
                                    </FormLabel>

                                    <FormInput
                                        id="business_type"
                                        type="text"
                                        value={form.data.business_type}
                                        onChange={(e) => form.setData('business_type', e.target.value)}
                                        maxLength={100}
                                        disabled={form.processing}
                                        error={form.errors.business_type}
                                        placeholder={t('e.g., sp. z o.o., JDG')}
                                    />

                                    <FormMessage error={form.errors.business_type} />
                                </div>
                            </div>
                        )}

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="address" error={form.errors.address}>
                                    {t('Address')}
                                </FormLabel>

                                <FormInput
                                    id="address"
                                    type="text"
                                    value={form.data.address}
                                    onChange={(e) => form.setData('address', e.target.value)}
                                    maxLength={150}
                                    disabled={form.processing}
                                    error={form.errors.address}
                                />

                                <FormMessage error={form.errors.address} />
                            </div>

                            <div>
                                <FormLabel htmlFor="city" error={form.errors.city}>
                                    {t('City')}
                                </FormLabel>

                                <FormInput
                                    id="city"
                                    type="text"
                                    value={form.data.city}
                                    onChange={(e) => form.setData('city', e.target.value)}
                                    maxLength={50}
                                    disabled={form.processing}
                                    error={form.errors.city}
                                />

                                <FormMessage error={form.errors.city} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="postal_code" error={form.errors.postal_code}>
                                    {t('Postal Code')}
                                </FormLabel>

                                <FormInput
                                    id="postal_code"
                                    type="text"
                                    value={form.data.postal_code}
                                    onChange={(e) => form.setData('postal_code', e.target.value)}
                                    maxLength={25}
                                    disabled={form.processing}
                                    error={form.errors.postal_code}
                                    placeholder="00-000"
                                />

                                <FormMessage error={form.errors.postal_code} />
                            </div>

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
                                        <SelectItem value="PL">{t('Poland')}</SelectItem>
                                        <SelectItem value="DE">{t('Germany')}</SelectItem>
                                        <SelectItem value="GB">{t('United Kingdom')}</SelectItem>
                                    </SelectContent>
                                </Select>

                                <FormMessage error={form.errors.country} />
                            </div>

                            <div>
                                <FormLabel htmlFor="currency" error={form.errors.currency}>
                                    {t('Currency')}
                                </FormLabel>

                                <Select
                                    value={form.data.currency}
                                    onValueChange={(value) => form.setData('currency', value as 'PLN' | 'EUR' | 'USD' | 'GBP')}
                                    disabled={form.processing}
                                >
                                    <SelectTrigger id="currency" className={form.errors.currency ? 'selectError' : ''}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="PLN">PLN - złoty</SelectItem>
                                        <SelectItem value="EUR">EUR - euro</SelectItem>
                                        <SelectItem value="USD">USD - dollar</SelectItem>
                                        <SelectItem value="GBP">GBP - pound</SelectItem>
                                    </SelectContent>
                                </Select>

                                <FormMessage error={form.errors.currency} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="segment" error={form.errors.segment}>
                                    {t('Segment')}
                                </FormLabel>
                                <Select
                                    value={form.data.segment || '__none__'}
                                    onValueChange={(value) =>
                                        form.setData('segment', value === '__none__' ? '' : (value as 'agency' | 'smb' | 'enterprise' | 'individual'))
                                    }
                                    disabled={form.processing}
                                >
                                    <SelectTrigger id="segment" className={form.errors.segment ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('Select segment')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">{t(' - none - ')}</SelectItem>
                                        <SelectItem value="agency">{t('Agency')}</SelectItem>
                                        <SelectItem value="smb">{t('Small / mid business')}</SelectItem>
                                        <SelectItem value="enterprise">{t('Enterprise')}</SelectItem>
                                        <SelectItem value="individual">{t('Individual')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <FormMessage error={form.errors.segment} />
                            </div>

                            <div>
                                <FormLabel htmlFor="cooperation_type" error={form.errors.cooperation_type}>
                                    {t('Cooperation type')}
                                </FormLabel>
                                <Select
                                    value={form.data.cooperation_type || '__none__'}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'cooperation_type',
                                            value === '__none__' ? '' : (value as 'retainer' | 'hourly' | 'project' | 'one_off'),
                                        )
                                    }
                                    disabled={form.processing}
                                >
                                    <SelectTrigger id="cooperation_type" className={form.errors.cooperation_type ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('Select cooperation type')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">{t(' - none - ')}</SelectItem>
                                        <SelectItem value="retainer">{t('Retainer')}</SelectItem>
                                        <SelectItem value="hourly">{t('Hourly contract')}</SelectItem>
                                        <SelectItem value="project">{t('Project')}</SelectItem>
                                        <SelectItem value="one_off">{t('One-off')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <FormMessage error={form.errors.cooperation_type} />
                            </div>
                        </div>

                        {form.data.cooperation_type === 'hourly' && (
                            <div className="formGrid">
                                <div>
                                    <FormLabel htmlFor="hourly_rate" error={form.errors.hourly_rate}>
                                        {t('Hourly rate (netto)')}
                                    </FormLabel>
                                    <FormInput
                                        id="hourly_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={form.data.hourly_rate}
                                        onChange={(e) => form.setData('hourly_rate', e.target.value)}
                                        disabled={form.processing}
                                        error={form.errors.hourly_rate}
                                        placeholder={t('e.g., 95.00')}
                                    />
                                    <FormMessage error={form.errors.hourly_rate} />
                                </div>
                                <div />
                            </div>
                        )}

                        <div>
                            <FormLabel htmlFor="notes" error={form.errors.notes}>
                                {t('Notes')}
                            </FormLabel>

                            <Textarea
                                id="notes"
                                value={form.data.notes}
                                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => form.setData('notes', e.target.value)}
                                disabled={form.processing}
                                rows={4}
                            />

                            <FormMessage error={form.errors.notes} />
                        </div>

                        <div className="formActions">
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Loader2 className="spinner" />}
                                {t('Create Client')}
                            </Button>
                        </div>
                    </div>
                </Form>
            </div>
        </>
    );
}
