import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ReactNode, Ref } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './primitives.module.css';

/**
 * pill is the secondary action (soloPillClass) and has one size. Size control is the redesign's Button (Figma: Button):
 * 40 px, 44 on phones, with primary, secondary, quiet, stop and on-colour; the other sizes are the earlier look.
 */
type ButtonLook = {
    variant?: 'primary' | 'secondary' | 'quiet' | 'on-colour' | 'ghost' | 'text' | 'ink' | 'outline' | 'stop' | 'pill';
    size?: 'sm' | 'md' | 'lg' | 'touch' | 'control';
    block?: boolean;
};

/** The secondary pill: the masthead search, a record header's actions, a dialog's Cancel. */
export const soloPillClass = styles.pill;

export const soloButtonClass = ({ variant = 'primary', size = 'md', block }: ButtonLook) =>
    (variant === 'pill'
        ? [styles.pill, block ? styles.block : '']
        : [styles.button, styles[variant === 'on-colour' ? 'onColour' : variant], styles[size], block ? styles.block : '']
    )
        .filter(Boolean)
        .join(' ');

/** The phone hit area for a small text link (44 px), with no change to how it looks. */
export const touchTargetClass = styles.touchTarget;

/** Primary fills with --solo-accent, so it recolours with the day state. Stop is a running timer's: the surface's own ink, never the brand colour. */
export function SoloButton({ variant, size, block, className, children, ...rest }: ButtonLook & ButtonHTMLAttributes<HTMLButtonElement> & { ref?: Ref<HTMLButtonElement> }) {
    return (
        <button
            type="button"
            className={[soloButtonClass({ variant, size, block }), className].filter(Boolean).join(' ')}
            data-look={variant === 'quiet' ? 'quiet' : undefined}
            {...rest}
        >
            {variant === 'stop' && size !== 'control' && <Icon name="stop" size={16} filled />}
            {children}
        </button>
    );
}

export function SoloButtonLink({ variant, size, block, className, ...rest }: ButtonLook & Omit<InertiaLinkProps, 'size'>) {
    return <Link className={[soloButtonClass({ variant, size, block }), className].filter(Boolean).join(' ')} data-look={variant === 'quiet' ? 'quiet' : undefined} {...rest} />;
}

interface SectionHeadProps {
    label: ReactNode;
    meta?: ReactNode;
    action?: ReactNode;
    kind?: 'day' | 'body';
    quiet?: boolean;
    /** span where a heading is not allowed, such as a listbox group label. */
    as?: 'h2' | 'span';
    /** Below 768 px a body head is the Rule head; plain drops the rule and reads the meta inline (Overview). */
    phone?: 'rule' | 'plain';
    /** none: the head sits on the page without a block below it, flush with the column edge (Figma Inset=None). */
    inset?: 'block' | 'none';
    className?: string;
}

/** Quiet is for secondary lists like Off plan. */
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

/** Figma: body link (7 more →, Older →). */
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

/** A record name past this many characters steps down one size. */
export const POSTER_LONG = 60;

/**
 * The page headline: a weekday (never the date number) or a page name at the Heading size; display is for the moment
 * screens (DONE., 404); record is one size below Heading for a task's or a client's name, shown whole, and a name
 * past 60 characters one step smaller. Capitals come from CSS, the text keeps its case.
 */
export function Poster({ size = 'heading', title, children }: { size?: 'heading' | 'display' | 'record'; title?: string; children: ReactNode }) {
    const record = size === 'record';
    const long = record && typeof children === 'string' && children.length > POSTER_LONG;
    const look = [styles.poster, size === 'display' ? styles.posterDisplay : '', record ? styles.posterRecord : '', long ? styles.posterLong : ''];

    return (
        <h1 className={look.filter(Boolean).join(' ')} title={title} data-long={long ? '' : undefined}>
            {children}
        </h1>
    );
}

interface StatProps {
    value: ReactNode;
    label: ReactNode;
    /** tile is the redesign's Stat (Figma: Stat): the caps label above the figure and one caption line under it. */
    kind?: 'row' | 'tile';
    /** One caption line, tile only. */
    sub?: ReactNode;
    /** What the figure is made of, behind an (i) beside the label named "{label}: explanation", tile only. */
    hint?: { label: string; text: ReactNode };
    /** over: the figure is past its limit; on-colour: inside the timer tile. */
    tone?: 'neutral' | 'over' | 'on-colour';
}

/** Figma: A2f / Stat, one size everywhere. */
export function Stat({ value, label, kind = 'row', sub, hint, tone = 'neutral' }: StatProps) {
    const { t } = useTranslation();

    if (kind === 'tile') {
        return (
            <div className={styles.tileStat} data-tone={tone}>
                <span className={styles.tileStatLabel}>
                    {label}
                    {hint !== undefined && <InfoHint label={t('hint_label', { label: hint.label })}>{hint.text}</InfoHint>}
                </span>
                <span className={styles.statValue}>{value}</span>
                {sub !== undefined && <span className={styles.tileStatSub}>{sub}</span>}
            </div>
        );
    }

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
