import { Icon } from '@/components/ui/icon';
import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import styles from './primitives.module.css';

/** pill is the secondary action (soloPillClass) and has one size. */
type ButtonLook = {
    variant?: 'primary' | 'ghost' | 'text' | 'ink' | 'outline' | 'stop' | 'pill';
    size?: 'sm' | 'md' | 'lg' | 'touch';
    block?: boolean;
};

/** The secondary pill: the masthead search, a record header's actions, a dialog's Anuluj. */
export const soloPillClass = styles.pill;

export const soloButtonClass = ({ variant = 'primary', size = 'md', block }: ButtonLook) =>
    (variant === 'pill' ? [styles.pill, block ? styles.block : ''] : [styles.button, styles[variant], styles[size], block ? styles.block : ''])
        .filter(Boolean)
        .join(' ');

/** The phone hit area for a small text link (44 px), with no change to how it looks. */
export const touchTargetClass = styles.touchTarget;

/** Primary fills with --solo-accent, so it recolours with the day state. Stop is a running timer's: the surface's own ink, never the brand colour. */
export function SoloButton({ variant, size, block, className, children, ...rest }: ButtonLook & ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button type="button" className={[soloButtonClass({ variant, size, block }), className].filter(Boolean).join(' ')} {...rest}>
            {variant === 'stop' && <Icon name="stop" size={16} filled />}
            {children}
        </button>
    );
}

export function SoloButtonLink({ variant, size, block, className, ...rest }: ButtonLook & Omit<InertiaLinkProps, 'size'>) {
    return <Link className={[soloButtonClass({ variant, size, block }), className].filter(Boolean).join(' ')} {...rest} />;
}

interface SectionHeadProps {
    label: ReactNode;
    meta?: ReactNode;
    action?: ReactNode;
    kind?: 'day' | 'body';
    quiet?: boolean;
    /** span where a heading is not allowed, such as a listbox group label. */
    as?: 'h2' | 'span';
    /** Below 768 px a body head is the Rule head; plain drops the rule and reads the meta inline (Przegląd). */
    phone?: 'rule' | 'plain';
    /** none: the head sits on the page without a block below it, flush with the column edge (Figma Inset=None). */
    inset?: 'block' | 'none';
    className?: string;
}

/** Quiet is for secondary lists like Poza planem. */
export function SectionHead({
    label,
    meta,
    action,
    kind = 'day',
    quiet,
    as: Label = 'h2',
    phone = 'rule',
    inset = 'block',
    className: extra,
}: SectionHeadProps) {
    const className = [
        styles.sectionHead,
        kind === 'body' ? styles.sectionBody : '',
        kind === 'body' && phone === 'plain' ? styles.sectionPlain : '',
        action !== undefined ? styles.sectionWithAction : '',
        quiet ? styles.sectionQuiet : '',
        inset === 'none' ? styles.sectionFlush : '',
        extra,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <div className={className}>
            <Label className={styles.sectionLabel}>{label}</Label>
            {action !== undefined ? (
                <div className={styles.sectionActions}>{action}</div>
            ) : (
                meta !== undefined && <span className={styles.sectionMeta}>{meta}</span>
            )}
        </div>
    );
}

/** Figma: Link action. */
export function SectionLink({ className, ...rest }: InertiaLinkProps) {
    return <Link className={[styles.sectionLink, className].filter(Boolean).join(' ')} {...rest} />;
}

/** Figma: body link (7 więcej →, Starsze →). */
export function BodyLink({ className, ...rest }: InertiaLinkProps) {
    return <Link className={[styles.bodyLink, className].filter(Boolean).join(' ')} {...rest} />;
}

export function SectionAction({ className, ...rest }: ButtonHTMLAttributes<HTMLButtonElement>) {
    return <button type="button" className={[styles.sectionLink, className].filter(Boolean).join(' ')} {...rest} />;
}

/** Figma: A2f / Block with its Empty row: the one muted line a section shows when it has nothing, in the block its rows would fill. */
export function EmptyBlock({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <div className={[styles.emptyBlock, className].filter(Boolean).join(' ')}>
            <p className={styles.emptyLine}>{children}</p>
        </div>
    );
}

/** The page headline: a weekday (never the date number) or a page name at the Heading size; display is for the moment screens (GOTOWE., 404). */
export function Poster({ size = 'heading', children }: { size?: 'heading' | 'display'; children: ReactNode }) {
    return <h1 className={size === 'display' ? `${styles.poster} ${styles.posterDisplay}` : styles.poster}>{children}</h1>;
}

/** Figma: A2f / Stat, one size everywhere. */
export function Stat({ value, label }: { value: ReactNode; label: ReactNode }) {
    return (
        <div className={styles.stat}>
            <span className={styles.statValue}>{value}</span>
            <span className={styles.statLabel}>{label}</span>
        </div>
    );
}

/** Figma: A2f / Stat row, 44 apart and top aligned; fill spreads the stats over a phone's width. */
export function StatRow({ fill = false, className, children }: { fill?: boolean; className?: string; children: ReactNode }) {
    return <div className={[styles.statRow, fill ? styles.statFill : '', className].filter(Boolean).join(' ')}>{children}</div>;
}
