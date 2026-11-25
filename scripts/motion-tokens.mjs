/** Every transition and animation reads its duration and easing from the motion tokens in variables.css (docs theming.md, Motion). */
const DECLARATION =
    /(?<![\w-])(transition|transition-duration|transition-delay|transition-timing-function|animation|animation-duration|animation-delay|animation-timing-function)\s*:\s*([^;{}]+)/gi;
const TIME = /(?<![\w.-])(\d*\.?\d+)(ms|s)(?![\w-])/gi;
const EASING = /(?<![\w-])(?:(?:cubic-bezier|steps|linear)\(|(?:ease-in-out|ease-in|ease-out|ease|linear|step-start|step-end)(?![\w-]))/gi;
const TOKEN = /^--(motion|ease)-/;

/** Each declaration that writes a non-zero time, an easing of its own or a var() other than a motion token; a looping animation (infinite) keeps its own period. */
export function hardCodedMotion(css) {
    const failures = [];
    const code = css.replace(/\/\*[\s\S]*?\*\//g, '');
    for (const [declaration, , value] of code.matchAll(DECLARATION)) {
        if (/(?<![\w-])infinite(?![\w-])/i.test(value)) continue;
        const times = [...value.matchAll(TIME)].filter(([, number]) => Number.parseFloat(number) !== 0);
        const easings = [...value.matchAll(EASING)];
        const others = [...value.matchAll(/var\(\s*(--[\w-]+)/g)].filter(([, name]) => !TOKEN.test(name));
        if (times.length > 0 || easings.length > 0 || others.length > 0) {
            failures.push(declaration.trim().replace(/\s+/g, ' '));
        }
    }

    return failures;
}
