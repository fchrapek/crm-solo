import { Info } from 'lucide-react';
import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

import styles from './info-hint.module.css';

interface Props {
    /** The explanation. Kept out of the page body so forms stay scannable. */
    children: React.ReactNode;
    /** Accessible name for the trigger; defaults to "More information". */
    label?: string;
}

/**
 * An "i" affordance that holds explanatory copy next to the thing it explains.
 *
 * Settings pages accumulate paragraphs: every field grows a sentence of
 * rationale until the form is unreadable. This keeps the rationale one click
 * away instead of deleting it.
 *
 * A popover rather than a title tooltip: hover-only text is unreachable on
 * touch, and this copy is occasionally load-bearing (why a source cannot be
 * removed, which theme ignores the accent).
 */
export function InfoHint({ children, label }: Props) {
    const { t } = useTranslation();

    return (
        <Popover>
            <PopoverTrigger asChild>
                <button type="button" className={styles.trigger} aria-label={label ?? t('More information')}>
                    <Info size={13} aria-hidden="true" />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className={styles.content}>
                {children}
            </PopoverContent>
        </Popover>
    );
}
