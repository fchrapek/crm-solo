import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import styles from './primitives.module.css';

type ButtonLook = { variant?: 'primary' | 'ghost'; size?: 'sm' | 'md' | 'lg'; block?: boolean };

const cls = ({ variant = 'primary', size = 'md', block }: ButtonLook) =>
    [styles.button, styles[variant], styles[size], block ? styles.block : ''].filter(Boolean).join(' ');

/** Figma: Button (Style × Size). Primary fills with --solo-accent, so it recolours with the day state. */
export function SoloButton({ variant, size, block, className, ...rest }: ButtonLook & ButtonHTMLAttributes<HTMLButtonElement>) {
    return <button type="button" className={[cls({ variant, size, block }), className].filter(Boolean).join(' ')} {...rest} />;
}

export function SoloButtonLink({ variant, size, block, className, ...rest }: ButtonLook & Omit<InertiaLinkProps, 'size'>) {
    return <Link className={[cls({ variant, size, block }), className].filter(Boolean).join(' ')} {...rest} />;
}

/** Figma: Section head. Caps label left, counter right, strong underline; quiet for secondary lists like Poza planem. */
export function SectionHead({ label, meta, quiet }: { label: ReactNode; meta?: ReactNode; quiet?: boolean }) {
    return (
        <div className={[styles.sectionHead, quiet ? styles.sectionQuiet : ''].filter(Boolean).join(' ')}>
            <h2 className={styles.sectionLabel}>{label}</h2>
            {meta !== undefined && <span className={styles.sectionMeta}>{meta}</span>}
        </div>
    );
}

/** The date line above a poster. */
export function Eyebrow({ children }: { children: ReactNode }) {
    return <p className={styles.eyebrow}>{children}</p>;
}

/** The day headline: weekday only, never the date number. */
export function Poster({ children }: { children: ReactNode }) {
    return <h1 className={styles.poster}>{children}</h1>;
}
