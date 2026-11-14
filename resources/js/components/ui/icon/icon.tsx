import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

import { FLUENT } from './fluent';

import styles from './icon.module.css';

export type IconName = keyof typeof FLUENT;
/** 16 inline and in table rows, 20 in buttons and list items, 24 in navigation and the tab bar. */
export type IconSize = 16 | 20 | 24;

export const ICON_NAMES = Object.keys(FLUENT) as IconName[];

interface IconProps extends Omit<HTMLAttributes<HTMLSpanElement>, 'children'> {
    name: IconName;
    size?: IconSize;
    /** A filled glyph, such as a pinned pin or the play and stop of a timer. */
    filled?: boolean;
}

/** Fluent paths from ./fluent (scripts/fluent-icons.mjs). Decorative unless given a label. */
export function Icon({ name, size = 20, filled = false, className, ...rest }: IconProps) {
    const labelled = rest['aria-label'] !== undefined || rest['aria-labelledby'] !== undefined;
    const glyph: { regular: Record<IconSize, string[]>; filled?: Record<IconSize, string[]> } = FLUENT[name];

    return (
        <span
            aria-hidden={labelled ? undefined : true}
            role={labelled ? 'img' : undefined}
            {...rest}
            className={cn(styles.icon, className)}
            data-icon={name}
        >
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} fill="currentColor" aria-hidden="true">
                {((filled && glyph.filled) || glyph.regular)[size].map((d) => (
                    <path key={d} d={d} />
                ))}
            </svg>
        </span>
    );
}
