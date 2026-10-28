import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Icon } from '@/components/ui/icon';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

import styles from './info-hint.module.css';

interface Props {
    /** The explanation. Kept out of the page body so forms stay scannable. */
    children: React.ReactNode;
    /** Accessible name for the trigger; defaults to "More information". */
    label?: string;
    /** Something else to explain instead of the "i", such as a disabled button and why it is off. */
    trigger?: React.ReactElement;
    side?: 'top' | 'bottom';
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
 * removed, which theme ignores the accent). A mouse opens it on hover as well.
 */
export function InfoHint({ children, label, trigger, side }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = React.useState(false);
    const hovering = React.useRef(false);
    const byHover = React.useRef(false);
    const closing = React.useRef<number | undefined>(undefined);

    React.useEffect(() => () => window.clearTimeout(closing.current), []);

    // The gap between the trigger and the panel would close it on the way across, so leaving waits a beat.
    const hover = (inside: boolean) => (event: React.PointerEvent) => {
        if (event.pointerType !== 'mouse') return;
        hovering.current = inside;
        window.clearTimeout(closing.current);
        if (inside) {
            if (!open) byHover.current = true;
            setOpen(true);
        } else {
            closing.current = window.setTimeout(() => setOpen(false), 150);
        }
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                if (!next) hovering.current = false;
                setOpen(next);
            }}
        >
            <PopoverTrigger
                asChild
                onPointerEnter={hover(true)}
                onPointerLeave={hover(false)}
                // A click under a hovering mouse would toggle the panel hover just opened.
                onClick={(event) => {
                    if (hovering.current) {
                        event.preventDefault();
                    } else {
                        byHover.current = false;
                    }
                }}
            >
                {trigger ?? (
                    <button type="button" className={styles.trigger} aria-label={label ?? t('More information')}>
                        <Icon name="info" size={16} />
                    </button>
                )}
            </PopoverTrigger>
            <PopoverContent
                align="start"
                side={side}
                className={styles.content}
                onPointerEnter={hover(true)}
                onPointerLeave={hover(false)}
                // Opened by a passing mouse, the panel leaves focus where the person is typing, on the way in and out.
                onOpenAutoFocus={(event) => byHover.current && event.preventDefault()}
                onCloseAutoFocus={(event) => byHover.current && event.preventDefault()}
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}
