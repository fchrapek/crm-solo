import { Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { FormInput, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';

import styles from './email-list-input.module.css';

interface Props {
    /** Current list of emails. Pass an empty array; the component always
     * renders at least one input row so the user has something to type into. */
    value: string[];
    onChange: (next: string[]) => void;
    /** Server-side validation errors keyed by Laravel field path:
     *  - 'emails.0': error on the first row
     *  - 'emails': error on the whole array (e.g. dedupe message) */
    errors?: Record<string, string>;
    /** Name prefix used to look up errors in the errors map. Defaults to "emails". */
    namePrefix?: string;
    disabled?: boolean;
    idPrefix?: string;
}

/**
 * Add/remove rows of email addresses. The visible rows always include at
 * least one (even if value is empty) so the user has a tappable input. On
 * change, empty/whitespace-only entries are stripped so the parent gets a
 * clean array to submit.
 */
export function EmailListInput({ value, onChange, errors = {}, namePrefix = 'emails', disabled = false, idPrefix = 'email' }: Props) {
    const { t } = useTranslation();
    const display = value.length > 0 ? value : [''];

    const updateRow = (index: number, next: string) => {
        const copy = [...display];
        copy[index] = next;
        // Emit the cleaned list (no empty strings) on every keystroke so the
        // parent's submitted payload is always normalized. We render `display`
        // (with the placeholder empty row) but emit only filled entries.
        onChange(copy.map((e) => e.trim()).filter((e) => e !== ''));
    };

    const removeRow = (index: number) => {
        const copy = display.filter((_, i) => i !== index);
        onChange(copy.map((e) => e.trim()).filter((e) => e !== ''));
    };

    return (
        <div className={styles.wrap}>
            {display.map((email, index) => {
                const rowError = errors[`${namePrefix}.${index}`];
                return (
                    <div key={index}>
                        <div className={styles.row}>
                            <FormInput
                                id={`${idPrefix}-${index}`}
                                type="email"
                                value={email}
                                onChange={(e) => updateRow(index, e.target.value)}
                                placeholder={index === 0 ? 'primary@example.com' : 'another@example.com'}
                                maxLength={255}
                                disabled={disabled}
                                error={rowError}
                                className={styles.input}
                                autoComplete="email"
                            />
                            {display.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => removeRow(index)}
                                    disabled={disabled}
                                    aria-label={t('Remove email')}
                                    className={styles.removeBtn}
                                >
                                    <X size={14} />
                                </Button>
                            )}
                        </div>
                        <FormMessage error={rowError} />
                    </div>
                );
            })}
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => {
                    // Emit a copy that ends with an empty string so the next
                    // render shows a fresh blank row. The parent shouldn't
                    // submit this empty - handle that on submit by re-filtering.
                    const cleaned = display.map((e) => e.trim()).filter((e) => e !== '');
                    onChange([...cleaned, '']);
                }}
                disabled={disabled}
                className={styles.addBtn}
            >
                <Plus size={14} />
                {t('Add email')}
            </Button>
            {errors[namePrefix] && <FormMessage error={errors[namePrefix]} />}
        </div>
    );
}
