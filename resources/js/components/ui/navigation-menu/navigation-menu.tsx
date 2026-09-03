import * as NavigationMenuPrimitive from '@radix-ui/react-navigation-menu';
import { ChevronDownIcon } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';
import styles from './navigation-menu.module.css';

function NavigationMenu({
    className,
    children,
    viewport = true,
    ...props
}: React.ComponentProps<typeof NavigationMenuPrimitive.Root> & {
    viewport?: boolean;
}) {
    return (
        <NavigationMenuPrimitive.Root
            data-slot="navigation-menu"
            data-viewport={viewport}
            className={cn(styles.root, className)}
            {...props}
        >
            {children}
            {viewport && <NavigationMenuViewport />}
        </NavigationMenuPrimitive.Root>
    );
}

function NavigationMenuList({
    className,
    ...props
}: React.ComponentProps<typeof NavigationMenuPrimitive.List>) {
    return (
        <NavigationMenuPrimitive.List
            data-slot="navigation-menu-list"
            className={cn(styles.list, className)}
            {...props}
        />
    );
}

function NavigationMenuItem({
    className,
    ...props
}: React.ComponentProps<typeof NavigationMenuPrimitive.Item>) {
    return (
        <NavigationMenuPrimitive.Item
            data-slot="navigation-menu-item"
            className={cn(styles.item, className)}
            {...props}
        />
    );
}

function NavigationMenuTrigger({
    className,
    children,
    ...props
}: React.ComponentProps<typeof NavigationMenuPrimitive.Trigger>) {
    return (
        <NavigationMenuPrimitive.Trigger
            data-slot="navigation-menu-trigger"
            className={cn(styles.trigger, className)}
            {...props}
        >
            {children}{' '}
            <ChevronDownIcon className={styles.triggerIcon} aria-hidden="true" />
        </NavigationMenuPrimitive.Trigger>
    );
}

function NavigationMenuContent({
    className,
    ...props
}: React.ComponentProps<typeof NavigationMenuPrimitive.Content>) {
    return (
        <NavigationMenuPrimitive.Content
            data-slot="navigation-menu-content"
            className={cn(styles.content, className)}
            {...props}
        />
    );
}

function NavigationMenuViewport({
    className,
    ...props
}: React.ComponentProps<typeof NavigationMenuPrimitive.Viewport>) {
    return (
        <div className={styles.viewportWrapper}>
            <NavigationMenuPrimitive.Viewport
                data-slot="navigation-menu-viewport"
                className={cn(styles.viewport, className)}
                {...props}
            />
        </div>
    );
}

function NavigationMenuLink({
    className,
    ...props
}: React.ComponentProps<typeof NavigationMenuPrimitive.Link>) {
    return (
        <NavigationMenuPrimitive.Link
            data-slot="navigation-menu-link"
            className={cn(styles.link, className)}
            {...props}
        />
    );
}

function NavigationMenuIndicator({
    className,
    ...props
}: React.ComponentProps<typeof NavigationMenuPrimitive.Indicator>) {
    return (
        <NavigationMenuPrimitive.Indicator
            data-slot="navigation-menu-indicator"
            className={cn(styles.indicator, className)}
            {...props}
        >
            <div className={styles.indicatorArrow} />
        </NavigationMenuPrimitive.Indicator>
    );
}

/** Helper to get trigger styles for use on other elements */
function getNavigationMenuTriggerStyles(className?: string) {
    return cn(styles.trigger, className);
}

export {
    NavigationMenu,
    NavigationMenuList,
    NavigationMenuItem,
    NavigationMenuContent,
    NavigationMenuTrigger,
    NavigationMenuLink,
    NavigationMenuIndicator,
    NavigationMenuViewport,
    getNavigationMenuTriggerStyles,
};
