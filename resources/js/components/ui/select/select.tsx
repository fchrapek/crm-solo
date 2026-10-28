import * as SelectPrimitive from '@radix-ui/react-select';
import { CheckIcon, ChevronDownIcon, ChevronUpIcon } from 'lucide-react';
import * as React from 'react';

import { menuBoxClass, menuRowClass } from '@/components/solo/menu';
import { useSoloPortalSurface } from '@/components/solo/surface';
import { cn } from '@/lib/utils';
import styles from './select.module.css';

/** True inside a solo-menu panel, whose items are Menu / Row. */
const InSoloMenu = React.createContext(false);

function Select({ ...props }: React.ComponentProps<typeof SelectPrimitive.Root>) {
    return <SelectPrimitive.Root data-slot="select" {...props} />;
}

function SelectGroup({ ...props }: React.ComponentProps<typeof SelectPrimitive.Group>) {
    return <SelectPrimitive.Group data-slot="select-group" {...props} />;
}

function SelectValue({ ...props }: React.ComponentProps<typeof SelectPrimitive.Value>) {
    return <SelectPrimitive.Value data-slot="select-value" {...props} />;
}

/** The Figma Select trigger pill, for a popover that opens like a select. */
const soloPillTriggerClass = cn(styles.trigger, styles.soloPillTrigger);
const soloPillArrowClass = styles.pillArrow;

function SelectTrigger({
    className,
    children,
    look = 'default',
    ...props
}: React.ComponentProps<typeof SelectPrimitive.Trigger> & {
    /** solo: the outlined field box of Solo dialogs (Figma Input / Outline); solo-pill: the filter pill (Figma Select trigger). */
    look?: 'default' | 'solo' | 'solo-pill';
}) {
    return (
        <SelectPrimitive.Trigger
            data-slot="select-trigger"
            className={cn(styles.trigger, look === 'solo' && styles.soloTrigger, look === 'solo-pill' && styles.soloPillTrigger, className)}
            {...props}
        >
            {children}
            <SelectPrimitive.Icon asChild>
                {look === 'solo-pill' ? <span className={styles.pillArrow}>↓</span> : <ChevronDownIcon className={styles.triggerIcon} />}
            </SelectPrimitive.Icon>
        </SelectPrimitive.Trigger>
    );
}

function SelectContent({
    className,
    children,
    position = 'popper',
    look = 'default',
    ...props
}: React.ComponentProps<typeof SelectPrimitive.Content> & {
    /** solo-menu: Figma Menu / Filter, a SectionHead in a SelectLabel over ruled rows with the count as meta. */
    look?: 'default' | 'solo-menu';
}) {
    const container = useSoloPortalSurface();

    return (
        <SelectPrimitive.Portal container={container}>
            <SelectPrimitive.Content
                data-slot="select-content"
                className={cn(styles.content, look === 'solo-menu' && [styles.soloMenu, menuBoxClass], className)}
                position={position}
                {...props}
            >
                <SelectScrollUpButton />
                <SelectPrimitive.Viewport
                    className={cn(styles.viewport, position === 'popper' && styles.viewportPopper)}
                >
                    <InSoloMenu.Provider value={look === 'solo-menu'}>{children}</InSoloMenu.Provider>
                </SelectPrimitive.Viewport>
                <SelectScrollDownButton />
            </SelectPrimitive.Content>
        </SelectPrimitive.Portal>
    );
}

function SelectLabel({ className, ...props }: React.ComponentProps<typeof SelectPrimitive.Label>) {
    return (
        <SelectPrimitive.Label
            data-slot="select-label"
            className={cn(styles.label, className)}
            {...props}
        />
    );
}

function SelectItem({
    className,
    children,
    meta,
    ...props
}: React.ComponentProps<typeof SelectPrimitive.Item> & {
    /** Shown after the label but kept out of the trigger's value, like a count. */
    meta?: React.ReactNode;
}) {
    const inSoloMenu = React.useContext(InSoloMenu);

    return (
        <SelectPrimitive.Item
            data-slot="select-item"
            className={cn(styles.item, inSoloMenu && menuRowClass, className)}
            {...props}
        >
            <span className={styles.itemIndicator}>
                <SelectPrimitive.ItemIndicator>
                    <CheckIcon className={styles.itemIndicatorIcon} />
                </SelectPrimitive.ItemIndicator>
            </span>
            <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
            {meta !== undefined && <span className={styles.itemMeta}>{meta}</span>}
        </SelectPrimitive.Item>
    );
}

function SelectSeparator({
    className,
    ...props
}: React.ComponentProps<typeof SelectPrimitive.Separator>) {
    return (
        <SelectPrimitive.Separator
            data-slot="select-separator"
            className={cn(styles.separator, className)}
            {...props}
        />
    );
}

function SelectScrollUpButton({
    className,
    ...props
}: React.ComponentProps<typeof SelectPrimitive.ScrollUpButton>) {
    return (
        <SelectPrimitive.ScrollUpButton
            data-slot="select-scroll-up-button"
            className={cn(styles.scrollButton, className)}
            {...props}
        >
            <ChevronUpIcon className={styles.scrollButtonIcon} />
        </SelectPrimitive.ScrollUpButton>
    );
}

function SelectScrollDownButton({
    className,
    ...props
}: React.ComponentProps<typeof SelectPrimitive.ScrollDownButton>) {
    return (
        <SelectPrimitive.ScrollDownButton
            data-slot="select-scroll-down-button"
            className={cn(styles.scrollButton, className)}
            {...props}
        >
            <ChevronDownIcon className={styles.scrollButtonIcon} />
        </SelectPrimitive.ScrollDownButton>
    );
}

export {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectScrollDownButton,
    SelectScrollUpButton,
    SelectSeparator,
    SelectTrigger,
    SelectValue, soloPillArrowClass, soloPillTriggerClass };
