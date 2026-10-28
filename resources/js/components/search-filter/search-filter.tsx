import { router, usePage } from '@inertiajs/react';
import { Filter, X } from 'lucide-react';
import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SharedData } from '@/types';

import styles from './search-filter.module.css';

interface Values {
    role: string;
    search: string;
    trashed: string;
}

const ANY_VALUE = 'any'; // Instead of empty string

function pickBy(object: Values): Partial<Values> {
    const keys: Array<keyof Values> = ['role', 'search', 'trashed'];
    return keys.reduce<Partial<Values>>((acc, key) => {
        const value = object[key];
        // If the value is ANY_VALUE, don't include it in the query
        if (value !== '' && value !== undefined && value !== null && value !== ANY_VALUE) {
            acc[key] = value;
        }
        return acc;
    }, {});
}

interface SearchFilterPageProps extends SharedData {
    filters: {
        role?: string;
        search?: string;
        trashed?: string;
    };
}

export default function SearchFilter() {
    const { t } = useTranslation();
    const { filters } = usePage<SearchFilterPageProps>().props;
    const [open, setOpen] = React.useState(false);

    const [values, setValues] = React.useState<Values>({
        // Convert empty strings in filters to ANY_VALUE
        role: filters.role || ANY_VALUE,
        search: filters.search || '',
        trashed: filters.trashed || ANY_VALUE,
    });

    function handleChange(key: keyof Values, value: string) {
        const newValues = {
            ...values,
            [key]: value,
        };

        setValues(newValues);

        const query = pickBy(newValues);

        router.get(window.location.pathname, query, {
            replace: true,
            preserveState: true,
        });
    }

    return (
        <div className={styles.container}>
            <div className={styles.inputWrapper}>
                <div className={styles.inputContainer}>
                    <Popover open={open} onOpenChange={setOpen}>
                        <PopoverTrigger asChild>
                            <Button variant="ghost" size="icon" className={styles.filterButton}>
                                <Filter className={styles.icon} />
                                <span className="sr-only">{t('Filter')}</span>
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent className={styles.popoverContent} align="start">
                            <div className={styles.grid}>
                                {Object.prototype.hasOwnProperty.call(filters, 'role') && (
                                    <div className={styles.fieldGrid}>
                                        <Label htmlFor="role">{t('Role')}</Label>
                                        <Select value={values.role} onValueChange={(value) => handleChange('role', value)}>
                                            <SelectTrigger id="role">
                                                <SelectValue placeholder={t('Select role')} />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={ANY_VALUE}>{t('Any')}</SelectItem>
                                                <SelectItem value="user">{t('User')}</SelectItem>
                                                <SelectItem value="owner">{t('Owner')}</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                                <div className={styles.fieldGrid}>
                                    <Label htmlFor="trashed">{t('Trashed')}</Label>
                                    <Select value={values.trashed} onValueChange={(value) => handleChange('trashed', value)}>
                                        <SelectTrigger id="trashed">
                                            <SelectValue placeholder={t('Select trashed status')} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ANY_VALUE}>{t('Any')}</SelectItem>
                                            <SelectItem value="with">{t('With Trashed')}</SelectItem>
                                            <SelectItem value="only">{t('Only Trashed')}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </PopoverContent>
                    </Popover>
                    <Input
                        type="text"
                        placeholder={t('Search')}
                        value={values.search}
                        onChange={(e) => handleChange('search', e.target.value)}
                        className={styles.input}
                    />
                    {values.search && (
                        <Button variant="ghost" size="icon" className={styles.clearButton} onClick={() => handleChange('search', '')}>
                            <X className={styles.icon} />
                            <span className="sr-only">{t('Clear')}</span>
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
