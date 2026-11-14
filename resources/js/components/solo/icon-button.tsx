import { Icon } from '@/components/ui/icon';
import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ButtonHTMLAttributes } from 'react';
import styles from './mobile.module.css';

type IconName = 'more' | 'filter' | 'plus' | 'close' | 'search' | 'pencil' | 'pin';

/** Figma: Mobile / Icon button; size sm is the desktop 32 px Icon button / More. Look quiet is the redesign's IconButton: bare, 44 px on phones and 40 from 768 px; outline is the same size with the secondary button's ink ring. */
export function IconButton({
    icon,
    label,
    size = 'touch',
    look,
    filled,
    className,
    ...rest
}: { icon: IconName; label: string; size?: 'touch' | 'sm'; look?: 'quiet' | 'outline'; filled?: boolean } & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'>) {
    return (
        <button
            type="button"
            aria-label={label}
            data-icon={icon}
            data-size={size === 'sm' ? 'sm' : undefined}
            data-look={look}
            className={[styles.iconButton, className].filter(Boolean).join(' ')}
            {...rest}
        >
            <Icon name={icon} size={size === 'sm' ? 16 : 20} filled={filled} />
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
