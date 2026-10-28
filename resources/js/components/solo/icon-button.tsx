import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ButtonHTMLAttributes } from 'react';
import styles from './mobile.module.css';
import { Icon } from '@/components/ui/icon';

type IconName = 'more' | 'filter' | 'plus' | 'close' | 'search';

/** Figma: Mobile / Icon button; size sm is the desktop 32 px Icon button / More. */
export function IconButton({
    icon,
    label,
    size = 'touch',
    className,
    ...rest
}: { icon: IconName; label: string; size?: 'touch' | 'sm' } & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'>) {

    return (
        <button
            type="button"
            aria-label={label}
            data-icon={icon}
            data-size={size === 'sm' ? 'sm' : undefined}
            className={[styles.iconButton, className].filter(Boolean).join(' ')}
            {...rest}
        >
            <Icon name={icon} size={size === 'sm' ? 16 : 20} />
        </button>
    );
}

/** The icon button as a link, such as + on Lista. */
export function IconButtonLink({ icon, label, className, ...rest }: { icon: IconName; label: string } & Omit<InertiaLinkProps, 'children'>) {

    return (
        <Link aria-label={label} data-icon={icon} className={[styles.iconButton, className].filter(Boolean).join(' ')} {...rest}>
            <Icon name={icon} size={20} />
        </Link>
    );
}
