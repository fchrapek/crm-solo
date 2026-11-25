import * as SelectPrimitive from '@radix-ui/react-select';
import { CheckIcon, ChevronDownIcon, ChevronUpIcon } from 'lucide-react';
import * as React from 'react';

import { menuBoxClass, menuRowClass } from '@/components/solo/menu';
import { useSoloPortalSurface } from '@/components/solo/surface';
import { fullText } from '@/lib/plain-text';
import { cn } from '@/lib/utils';
import styles from './select.module.css';

/** True inside a solo-menu panel, whose items are Menu / Row. */
const InSoloMenu = React.createContext(false);

/** The chosen item's text, for the trigger's tooltip: Radix draws the value itself, so the trigger cannot read it. */
const ChosenText = React.createContext<string | undefined>(undefined);

/** The text of the SelectItem with this value among the children; an item inside another component is not found. */
function chosenText(children: React.ReactNode, value: string): string | undefined {
    for (const child of React.Children.toArray(children)) {
        if (!React.isValidElement<{ value?: string; children?: React.ReactNode }>(child)) continue;
        if (child.type === SelectItem && child.props.value === value) return fullText(child.props.children);
        const nested = chosenText(child.props.children, value);
        if (nested !== undefined) return nested;
    }

    return undefined;
}

function Select({ children, value, defaultValue, onValueChange, ...props }: React.ComponentProps<typeof SelectPrimitive.Root>) {
    const [picked, setPicked] = React.useState(defaultValue);
    const current = value ?? picked;

    return (
        <SelectPrimitive.Root
            data-slot="select"
            value={value}
            defaultValue={defaultValue}
            onValueChange={(next) => {
                setPicked(next);
                onValueChange?.(next);
            }}
            {...props}
        >
            <ChosenText.Provider value={current === undefined ? undefined : chosenText(children, current)}>{children}</ChosenText.Provider>
        </SelectPrimitive.Root>
    );
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
    const chosen = React.useContext(ChosenText);

    return (
        <SelectPrimitive.Trigger
            data-slot="select-trigger"
            className={cn(styles.trigger, look === 'solo' && styles.soloTrigger, look === 'solo-pill' && styles.soloPillTrigger, className)}
            {...props}
            title={props.title ?? chosen}
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
