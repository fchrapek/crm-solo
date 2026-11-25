import styles from './menu.module.css';

/** Figma Menu / Actions and Menu / Row: the Solo primitives put these on their content and items, so a screen never styles a menu itself. */
export const menuBoxClass = styles.box;
export const menuRowClass = styles.row;
export const menuHeadClass = styles.head;
export const menuLabelClass = styles.label;
export const menuValueClass = styles.value;
export const menuWideClass = styles.wide;
export const menuFillClass = styles.fill;
export const menuNestedClass = styles.nested;
export const menuGroupLabelClass = styles.groupLabel;
export const menuChipsClass = styles.chips;
export const menuChipClass = styles.chip;

type Edges = { top: number; right: number; bottom: number; left: number };

/**
 * A menu keeps its edge from the screen and, on a phone, from the bars fixed at its bottom, so it flips up when it would
 * run under them. The bottom is a getter: Radix reads it when it mounts the open content, after a timer strip may have appeared.
 */
export function menuCollisionPadding(edge: number | Partial<Edges> | undefined, floor: () => string): Edges {
    const base = typeof edge === 'number' ? { top: edge, right: edge, bottom: edge, left: edge } : { top: 0, right: 0, bottom: 0, left: 0, ...edge };

    return {
        top: base.top,
        right: base.right,
        left: base.left,
        get bottom() {
            const bars = Number.parseFloat(floor());

            return base.bottom + (Number.isFinite(bars) ? bars : 0);
        },
    };
}

/** The resolved height of the phone's fixed bottom bars (--menu-floor in solo.css, 0 from 768 px). */
export function readMenuFloor(): string {
    return typeof document === 'undefined' ? '' : getComputedStyle(document.documentElement).getPropertyValue('--menu-floor');
}
