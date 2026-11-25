import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { longDate } from '@/lib/dates';
import { useState, type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet } from './bottom-sheet';
import styles from './date-field.module.css';
import fieldStyles from './field.module.css';
import calendar from './month-card.module.css';
import { MonthCalendar } from './month-card';
import { SoloButton } from './primitives';
import { useSoloWide } from './use-solo-wide';

interface DateFieldProps extends Pick<ComponentProps<'button'>, 'id' | 'disabled' | 'aria-invalid' | 'aria-describedby'> {
    /** YYYY-MM-DD, or empty. */
    value: string;
    onChange: (value: string) => void;
    /** What the calendar is for, as its heading and the sheet's title. */
    label: string;
    min?: string;
    /** The date may be left empty. */
    clearable?: boolean;
}

/** A date as a field: the day in words, and the calendar (MonthCalendar in pick mode) as a popover from 768 px and a sheet on a phone. */
export function DateField({ value, onChange, label, min, clearable = false, ...control }: DateFieldProps) {
    const { t, i18n } = useTranslation();
    const wide = useSoloWide();
    const [open, setOpen] = useState(false);
    const choose = (date: string) => {
        onChange(date);
        setOpen(false);
    };
    const field = (
        <button
            type="button"
            className={`${fieldStyles.box} ${styles.field}`}
            data-empty={value === '' ? '' : undefined}
            aria-haspopup="dialog"
            aria-expanded={open}
            onClick={wide ? undefined : () => setOpen(true)}
            {...control}
        >
            {value === '' ? t('Choose a date') : longDate(value, i18n.language)}
        </button>
    );
    const body = open && (
        <>
            <MonthCalendar pick={{ value: value === '' ? null : value, onPick: choose, min }} />
            {clearable && value !== '' && (
                <SoloButton variant="text" size="control" className={styles.clear} onClick={() => choose('')}>
                    {t('Clear')}
                </SoloButton>
            )}
        </>
    );

    if (!wide) {
        return (
            <>
                {field}
                <BottomSheet open={open} onOpenChange={setOpen} title={label}>
                    {body}
                </BottomSheet>
            </>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{field}</PopoverTrigger>
            <PopoverContent align="start" sideOffset={6} className={calendar.monthPopover} aria-label={label}>
                {body}
            </PopoverContent>
        </Popover>
    );
}
