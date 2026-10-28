/** The Solo layer is mobile first: a width query may only add room, at the two breakpoints solo.css documents. */
export const SOLO_BREAKPOINTS = ['768px', '1100px'];

const ALLOWED = new RegExp(`\\(\\s*min-width\\s*:\\s*(?:${SOLO_BREAKPOINTS.join('|')})\\s*\\)`, 'gi');

/** Every @media prelude that names a width any other way: max-width, ranges, negation, other values. */
export function offSystemWidthQueries(css) {
    const failures = [];
    const code = css.replace(/\/\*[\s\S]*?\*\//g, '');
    for (const [rule] of code.matchAll(/@media[^{;]*/gi)) {
        const prelude = rule.slice('@media'.length);
        const rest = prelude.replace(ALLOWED, '');
        if (/width/i.test(rest) || (rest !== prelude && /\bnot\b/i.test(prelude))) {
            failures.push(rule.trim());
        }
    }

    return failures;
}
