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

const ROOT = new URL('..', import.meta.url).pathname;
const VARIABLES = join(ROOT, 'resources/css/variables.css');

// Defined anywhere in variables.css (light + dark blocks)
const defined = new Set([...readFileSync(VARIABLES, 'utf8').matchAll(/(--[\w-]+)\s*:/g)].map((m) => m[1]));

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

console.log(`Token contract OK — ${files.length} CSS files checked, no hardcoded colours, ink achromatic, accents readable.`);
