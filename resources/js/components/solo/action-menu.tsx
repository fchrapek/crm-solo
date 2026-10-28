import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { menuLabelClass } from './menu';
import styles from './mobile.module.css';

interface ActionMenuProps {
    trigger: ReactNode;
    children: ReactNode;
    align?: 'start' | 'center' | 'end';
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}

/** Figma: Menu / Actions, with the scrim of Mobile / Action menu on phones. */
export function ActionMenu({ trigger, children, align = 'end', open, onOpenChange }: ActionMenuProps) {
    return (
        <DropdownMenu open={open} onOpenChange={onOpenChange}>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuPortal>
                <div className={styles.menuScrim} aria-hidden="true" />
            </DropdownMenuPortal>
            <DropdownMenuContent align={align} sideOffset={8} collisionPadding={16}>
                {children}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

type ItemProps = Omit<ComponentProps<typeof DropdownMenuItem>, 'children' | 'asChild'> & {
    icon?: ReactNode;
    /** agent: the cobalt item that opens agent material, such as Dla agentów. */
    tone?: 'agent';
    children: ReactNode;
};

/** Figma: Menu / Row, the icon before the label. */
export function ActionMenuItem({ icon, tone, children, ...rest }: ItemProps) {
    return (
        <DropdownMenuItem data-tone={tone} {...rest}>
            {icon}
            <span className={menuLabelClass}>{children}</span>
        </DropdownMenuItem>
    );
}

/** A link item; external opens a new tab outside Inertia, such as a Trello card. */
export function ActionMenuLink({
    icon,
    children,
    external,
    ...rest
}: Omit<InertiaLinkProps, 'children'> & { icon?: ReactNode; children: ReactNode; external?: boolean }) {
    if (external) {
        return (
            <DropdownMenuItem asChild>
                <a href={String(rest.href)} target="_blank" rel="noopener noreferrer">
                    {icon}
                    <span className={menuLabelClass}>{children}</span>
                </a>
            </DropdownMenuItem>
        );
    }

    return (
        <DropdownMenuItem asChild>
            <Link {...rest}>
                {icon}
                <span className={menuLabelClass}>{children}</span>
            </Link>
        </DropdownMenuItem>
    );
}
