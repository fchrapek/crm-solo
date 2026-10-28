import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Icon } from '@/components/ui/icon';
import { type ReactNode, useState } from 'react';
import { BottomSheet } from './bottom-sheet';
import { fieldBoxClass, labelId } from './field';
import { ListRow } from './list-row';
import styles from './mobile.module.css';
import { useSoloWide } from './use-solo-wide';

export interface ResponsiveSelectOption {
    value: string;
    label: ReactNode;
}

interface ResponsiveSelectProps {
    /** The field label, which titles the phone sheet. */
    label: ReactNode;
    value: string;
    onValueChange: (value: string) => void;
    options: ResponsiveSelectOption[];
    disabled?: boolean;
    /** False keeps the select popover on phones too, for a caller outside the Solo pages. */
    sheet?: boolean;
    /** fieldControl() output: id, invalid state and the error link. */
    control: { id: string; 'aria-invalid'?: true; 'aria-describedby'?: string };
}

/** Figma: Mobile / Text field (Select), 346:8706: the Solo select from 768 px, a field opening a sheet of options below it. */
export function ResponsiveSelect({ label, value, onValueChange, options, disabled, sheet = true, control }: ResponsiveSelectProps) {
    const wide = useSoloWide() || !sheet;
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);

    if (wide) {
        return (
            <Select value={value} onValueChange={onValueChange} disabled={disabled}>
                <SelectTrigger look="solo" title={typeof selected?.label === 'string' ? selected.label : undefined} {...control}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    return (
        <BottomSheet
            open={open}
            onOpenChange={setOpen}
            title={label}
            trigger={
                <button
                    type="button"
                    className={`${fieldBoxClass} ${styles.selectField}`}
                    disabled={disabled}
                    {...control}
                    // The field label alone would name the button, so the choice is read after it, as the combobox does.
                    aria-labelledby={`${labelId(control.id)} ${control.id}-value`}
                >
                    <span className={styles.selectValue} id={`${control.id}-value`}>
                        {selected?.label}
                    </span>
                    <Icon name="chevron-down" size={16} />
                </button>
            }
        >
            {open && (
                <ul className={styles.selectOptions}>
                    {options.map((option) => {
                        const active = option.value === value;

                        return (
                            <li key={option.value}>
                                <ListRow
                                    title={option.label}
                                    trailing={active ? <Icon name="check" size={16} /> : null}
                                    aria-pressed={active}
                                    onClick={() => {
                                        onValueChange(option.value);
                                        setOpen(false);
                                    }}
                                />
                            </li>
                        );
                    })}
                </ul>
            )}
        </BottomSheet>
    );
}
