import type { SVGProps } from 'react';

// Exported from the CRM Solo Figma file (Components, Mobile / Icon / *, 323:169 to 323:246).
// Strokes and fills are currentColor so each glyph follows the surface it sits on.

type IconProps = SVGProps<SVGSVGElement>;

const base = { fill: 'none', 'aria-hidden': true, focusable: false } as const;

export function CheckIcon(props: IconProps) {
    return (
        <svg width="16" height="12" viewBox="0 0 16 12" {...base} {...props}>
            <path d="M1.5 6.5L5.5 10.5L14.5 1.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

export function AgentSquareIcon(props: IconProps) {
    return (
        <svg width="16" height="16" viewBox="0 0 16 16" {...base} {...props}>
            <rect x="2" y="2" width="12" height="12" rx="2" fill="currentColor" />
        </svg>
    );
}

export function LateDotIcon(props: IconProps) {
    return (
        <svg width="20" height="20" viewBox="0 0 20 20" {...base} {...props}>
            <circle cx="10" cy="10" r="4" fill="var(--solo-accent)" />
        </svg>
    );
}
