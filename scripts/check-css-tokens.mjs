#!/usr/bin/env node
/**
 * Token-contract guard: every var(--x) in the CSS layer must reference a
 * custom property defined in resources/css/variables.css (or a known runtime
 * family like Radix's). Agent-written CSS drifting onto invented names is the
 * single clearest LLM fingerprint this codebase has had — ~20 undefined
 * variables (var(--radius) rendering kanban corners square) before 2026-07-28.
 *
 * Run: bun run lint:tokens
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { offSystemWidthQueries } from './solo-breakpoints.mjs';

const ROOT = new URL('..', import.meta.url).pathname;
const VARIABLES = join(ROOT, 'resources/css/variables.css');

// Solo tokens (the day screens and the rewrite built on them) are a second source.
const SOLO = join(ROOT, 'resources/css/solo.css');

// Defined anywhere in variables.css (light + dark blocks) or solo.css
const defined = new Set(
    [VARIABLES, SOLO].flatMap((file) => [...readFileSync(file, 'utf8').matchAll(/(--[\w-]+)\s*:/g)].map((m) => m[1])),
);

// Runtime-provided or component-local prefixes that are legitimately not in variables.css
const RUNTIME_PREFIXES = ['--radix-'];

const files = [];
const walk = (dir) => {
    for (const entry of readdirSync(dir)) {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) {
            walk(path);
        } else if (entry.endsWith('.css') || entry.endsWith('.tsx') || entry.endsWith('.ts')) {
            files.push(path);
        }
    }
};
walk(join(ROOT, 'resources'));

let failures = 0;
for (const file of files) {
    const content = readFileSync(file, 'utf8');
    // Properties defined locally in this file (component-scoped custom props) are fine
    const local = new Set([...content.matchAll(/(--[\w-]+)\s*:/g)].map((m) => m[1]));
    for (const [, name] of content.matchAll(/var\(\s*(--[\w-]+)/g)) {
        if (defined.has(name) || local.has(name)) continue;
        if (RUNTIME_PREFIXES.some((p) => name.startsWith(p))) continue;
        console.error(`${file.replace(ROOT, '')}: var(${name}) is not defined in variables.css`);
        failures++;
    }
}

if (failures > 0) {
    console.error(`\n${failures} undefined custom propert${failures === 1 ? 'y' : 'ies'}. Add the token to resources/css/variables.css or fix the name.`);
    process.exit(1);
}

/*
 * Second contract: feature CSS must not hardcode colours. A literal hue in a
 * component is invisible to the theme system — it stays green while the theme
 * turns monochrome — which is exactly what made 75 values across 13 files
 * un-themable before 2026-07-30. Colours belong in variables.css / themes.css
 * as tokens (see the tone palette); components reference them.
 */
const COLOUR_LITERAL = /(?<![\w-])(#[0-9a-fA-F]{3,8}\b|\b(?:hsla?|rgba?|oklch|lab)\(\s*[^)]*\))/g;
// Alpha-only overlays on pure black/white carry no hue, so they cannot fight a
// theme; scrims and shadows legitimately use them.
const ACHROMATIC = /^(?:hsla?|rgba?)\(\s*(?:0[\s,]+0%?[\s,]+(?:0|100)%?|0[\s,]+0[\s,]+0|255[\s,]+255[\s,]+255)\b/;

let literals = 0;
for (const file of files) {
    if (!file.endsWith('.module.css')) continue;
    const content = readFileSync(file, 'utf8');
    for (const [, literal] of content.matchAll(COLOUR_LITERAL)) {
        if (ACHROMATIC.test(literal)) continue;
        console.error(`${file.replace(ROOT, '')}: hardcoded colour ${literal} — use a token from variables.css`);
        literals++;
    }
}

if (literals > 0) {
    console.error(`\n${literals} hardcoded colour${literals === 1 ? '' : 's'} in component CSS. Themes cannot reach these.`);
    process.exit(1);
}

/*
 * Third contract: the ink theme must be achromatic. "Only shades of black, no
 * other colours" is a promise the theme makes, and a single hue slipping into
 * one of its tokens breaks it invisibly (a lone green chip on a grey board).
 * Every colour ink declares must have zero saturation.
 */
const THEMES_FILE = join(ROOT, 'resources/css/themes.css');
const themesCss = readFileSync(THEMES_FILE, 'utf8');
const inkBlocks = [...themesCss.matchAll(/:root\[data-theme='ink'\][^{]*\{([^}]*)\}/g)].map((m) => m[1]);

if (inkBlocks.length === 0) {
    console.error('themes.css: no ink theme blocks found — the achromatic check cannot run.');
    process.exit(1);
}

let chromatic = 0;
for (const block of inkBlocks) {
    for (const [, decl, value] of block.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
        // "hue sat" primitive pairs (--theme-success: 0 0%) and full hsl() both
        // have to be hueless; a non-zero saturation is what adds colour.
        const hsl = value.match(/hsla?\(\s*([\d.]+)\s+([\d.]+)%/);
        const pair = value.trim().match(/^([\d.]+)\s+([\d.]+)%$/);
        const sat = hsl ? Number(hsl[2]) : pair ? Number(pair[2]) : null;
        if (sat !== null && sat > 0) {
            console.error(`themes.css: ink theme declares ${decl}: ${value.trim()} — saturation must be 0 for a monochrome theme.`);
            chromatic++;
        }
    }
}

if (chromatic > 0) {
    console.error(`\n${chromatic} chromatic value${chromatic === 1 ? '' : 's'} in the ink theme.`);
    process.exit(1);
}

/*
 * Fourth contract: accent text must be readable ON the accent. --accent-fg sits
 * on a --color-brand fill (primary buttons, hot-tier badges), so the pair needs
 * 4.5:1. Eight of the first ten accents failed this, worst at 2.2:1 — including
 * the orange this app shipped with — so it is checked rather than trusted.
 */
const hslToRgb = (h, s, l) => {
    s /= 100;
    l /= 100;
    const k = (n) => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));

    return [f(0) * 255, f(8) * 255, f(4) * 255];
};
const relLum = (rgb) =>
    rgb
        .map((c) => c / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
        .reduce((acc, c, i) => acc + c * [0.2126, 0.7152, 0.0722][i], 0);
const contrast = (a, b) => {
    const [hi, lo] = [relLum(a), relLum(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};

let accentFails = 0;
for (const [, name, body] of themesCss.matchAll(/:root\[data-accent='([\w-]+)'\]\s*\{([^}]*)\}/g)) {
    const hs = body.match(/--accent-hs:\s*([\d.]+)\s+([\d.]+)%/);
    const l = body.match(/--accent-l:\s*([\d.]+)%/);
    const fg = body.match(/--accent-fg:\s*hsl\(\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%\s*\)/);
    if (!hs || !l || !fg) {
        console.error(`themes.css: accent '${name}' is missing --accent-hs/-l/-fg.`);
        accentFails++;
        continue;
    }
    const ratio = contrast(
        hslToRgb(Number(hs[1]), Number(hs[2]), Number(l[1])),
        hslToRgb(Number(fg[1]), Number(fg[2]), Number(fg[3])),
    );
    if (ratio < 4.5) {
        console.error(`themes.css: accent '${name}' text on fill is ${ratio.toFixed(2)}:1 — needs 4.5:1. Try the other --accent-fg or shift --accent-l.`);
        accentFails++;
    }
}

if (accentFails > 0) {
    console.error(`\n${accentFails} accent contrast failure${accentFails === 1 ? '' : 's'}.`);
    process.exit(1);
}

/*
 * Sixth contract: a theme that pins its own surfaces (--theme-bg-light and kin, read by the derived
 * blocks) keeps its text readable on them: body and muted text on the page, the card and the muted
 * band, attention red on the card and the label on a red fill, in both appearances, and the brand's
 * text on its fill.
 */
const hslOf = (value) => {
    const m = value?.match(/hsl\(\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%\s*\)/);

    return m ? hslToRgb(Number(m[1]), Number(m[2]), Number(m[3])) : null;
};

let surfaceFails = 0;
for (const [, name, body] of themesCss.matchAll(/:root\[data-theme='([\w-]+)'\]\s*\{([^}]*)\}/g)) {
    if (!body.includes('--theme-bg-light')) continue;
    const decl = Object.fromEntries([...body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]));
    const destructive = decl['--theme-destructive']?.match(/^([\d.]+)\s+([\d.]+)%$/);
    const pairs = [];
    for (const [mode, redL] of [
        ['light', 48],
        ['dark', 56],
    ]) {
        const surfaces = ['bg', 'card', 'muted'].map((s) => [s, hslOf(decl[`--theme-${s}-${mode}`])]);
        for (const [s, rgb] of surfaces) {
            pairs.push([`fg-${mode} on ${s}`, hslOf(decl[`--theme-fg-${mode}`]), rgb]);
            pairs.push([`muted-fg-${mode} on ${s}`, hslOf(decl[`--theme-muted-fg-${mode}`]), rgb]);
        }
        const red = hslOf(decl[`--theme-destructive-${mode}`]) ?? (destructive && hslToRgb(Number(destructive[1]), Number(destructive[2]), redL));
        pairs.push([`destructive on card-${mode}`, red, surfaces[1][1]]);
        pairs.push([`destructive-fg-${mode} on destructive`, hslOf(decl[`--theme-destructive-fg-${mode}`]) ?? [255, 255, 255], red]);
    }
    pairs.push(['brand-foreground on brand', hslOf(decl['--color-brand-foreground']), hslOf(decl['--color-brand'])]);

    for (const [label, fg, bg] of pairs) {
        if (!fg || !bg) {
            console.error(`themes.css: theme '${name}' pins its surfaces but ${label} is not an hsl() pair the check can read.`);
            surfaceFails++;
            continue;
        }
        const ratio = contrast(fg, bg);
        if (ratio < 4.5) {
            console.error(`themes.css: theme '${name}' ${label} is ${ratio.toFixed(2)}:1 — needs 4.5:1.`);
            surfaceFails++;
        }
    }
}

if (surfaceFails > 0) {
    console.error(`\n${surfaceFails} surface contrast failure${surfaceFails === 1 ? '' : 's'}.`);
    process.exit(1);
}

/*
 * Seventh contract: a token only Boring SaaS sets is always read with Paper's own value as its
 * fallback, var(--solo-label-case, uppercase). Read bare, it is unset under Paper, the declaration
 * drops, and Paper quietly changes.
 */
const soloCss = readFileSync(SOLO, 'utf8');
const saasNames = new Set();
const paperNames = new Set(readFileSync(VARIABLES, 'utf8').matchAll(/(--[\w-]+)\s*:/g).map((m) => m[1]));
for (const [, selector, body] of soloCss.matchAll(/([^{}]*)\{([^{}]*)\}/g)) {
    const target = selector.includes("data-theme='boring-saas'") ? saasNames : paperNames;
    for (const [, name] of body.matchAll(/(--[\w-]+)\s*:/g)) target.add(name);
}
const saasOnly = [...saasNames].filter((name) => !paperNames.has(name));

let bareReads = 0;
for (const file of files) {
    if (file === SOLO || file === THEMES_FILE || !file.endsWith('.css')) continue;
    const content = readFileSync(file, 'utf8');
    for (const name of saasOnly) {
        if (new RegExp(`var\\(\\s*${name}\\s*\\)`).test(content)) {
            console.error(`${file.replace(ROOT, '')}: var(${name}) has no fallback; only Boring SaaS sets it, so Paper needs its own value after the comma.`);
            bareReads++;
        }
    }
}

if (bareReads > 0) {
    console.error(`\n${bareReads} Boring SaaS token${bareReads === 1 ? '' : 's'} read without Paper's fallback.`);
    process.exit(1);
}

/*
 * Fifth contract: the Solo layer is mobile first (scripts/solo-breakpoints.mjs). A max-width query or a
 * third breakpoint is how one layout per screen size quietly comes back.
 */
const soloFiles = [SOLO, ...files.filter((file) => file.includes('/resources/js/components/solo/') && file.endsWith('.css'))];

let breakpointFails = 0;
for (const file of soloFiles) {
    for (const failure of offSystemWidthQueries(readFileSync(file, 'utf8'))) {
        console.error(`${file.replace(ROOT, '')}: ${failure} — Solo CSS is mobile first: use (min-width: 768px) or (min-width: 1100px)`);
        breakpointFails++;
    }
}

if (breakpointFails > 0) {
    console.error(`\n${breakpointFails} off-system media quer${breakpointFails === 1 ? 'y' : 'ies'} in the Solo layer.`);
    process.exit(1);
}

console.log(`Token contract OK — ${files.length} CSS files checked, no hardcoded colours, ink achromatic, accents and pinned surfaces readable, Boring SaaS tokens fall back to Paper, Solo breakpoints mobile first.`);
