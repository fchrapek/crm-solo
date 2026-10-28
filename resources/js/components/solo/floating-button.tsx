import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ButtonHTMLAttributes } from 'react';
import styles from './mobile.module.css';

/** Figma: Mobile / Floating button. Phones only; desktop keeps the page's own button. */
export function FloatingButton({ className, ...rest }: ButtonHTMLAttributes<HTMLButtonElement>) {
    return <button type="button" data-solo-fab="" className={[styles.fab, className].filter(Boolean).join(' ')} {...rest} />;
}

export function FloatingButtonLink({ className, ...rest }: InertiaLinkProps) {
    return <Link data-solo-fab="" className={[styles.fab, className].filter(Boolean).join(' ')} {...rest} />;
}
