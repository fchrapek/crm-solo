import { useCallback, useEffect, useState } from 'react';

/**
 * Theme — the app's visual identity (see resources/css/themes.css).
 *
 * Second axis alongside `appearance` (light/dark/system), which decides
 * brightness only. Every theme works in both appearances; the two compose via
 * `data-theme` on <html> plus the existing `.dark` class.
 *
 * Persisted the same way as appearance: localStorage for the client, a cookie
 * so a server-rendered first paint can match and avoid a flash.
 */
export const THEMES = [
    {
        id: 'paper',
        label: 'Paper',
        blurb: 'Warm editorial. Ink-brown text, terracotta accent, off-white cards.',
        swatches: ['hsl(38 22% 96%)', 'hsl(38 16% 93%)', 'hsl(14 72% 50%)'],
    },
    {
        id: 'ink',
        label: 'Ink',
        blurb: 'Monochrome. Every tone is a grey; meaning comes from weight, not hue.',
        swatches: ['hsl(0 0% 96%)', 'hsl(0 0% 90%)', 'hsl(0 0% 16%)'],
    },
    {
        id: 'brutal',
        label: 'Brutal',
        blurb: 'Slab borders, hard shadows, square corners, uppercase chips.',
        swatches: ['hsl(50 30% 96%)', 'hsl(50 18% 93%)', 'hsl(52 100% 52%)'],
    },
    {
        id: 'bauhaus',
        label: 'Bauhaus',
        blurb: 'Primaries and geometry: red accent, hairline rules, tight corners.',
        swatches: ['hsl(40 16% 96%)', 'hsl(40 10% 93%)', 'hsl(4 78% 50%)'],
    },
] as const;

export type Theme = (typeof THEMES)[number]['id'];

/**
 * Accent — the single saturated colour in the UI. Third axis, independent of
 * theme and appearance. `default` means "whatever the theme ships", which is
 * why it is not a colour of its own.
 *
 * Ink deliberately ignores this: a monochrome theme with one saturated button
 * is not monochrome (enforced in themes.css).
 */
export const ACCENTS = [
    { id: 'default', label: 'Theme default', swatch: 'var(--color-brand)' },
    { id: 'ember', label: 'Ember', swatch: 'hsl(18 100% 59%)' },
    { id: 'crimson', label: 'Crimson', swatch: 'hsl(348 79% 48%)' },
    { id: 'magenta', label: 'Magenta', swatch: 'hsl(322 72% 48%)' },
    { id: 'violet', label: 'Violet', swatch: 'hsl(268 62% 55%)' },
    { id: 'indigo', label: 'Indigo', swatch: 'hsl(232 68% 56%)' },
    { id: 'azure', label: 'Azure', swatch: 'hsl(205 82% 44%)' },
    { id: 'teal', label: 'Teal', swatch: 'hsl(178 62% 36%)' },
    { id: 'pine', label: 'Pine', swatch: 'hsl(152 55% 34%)' },
    { id: 'lime', label: 'Lime', swatch: 'hsl(74 68% 44%)' },
    { id: 'amber', label: 'Amber', swatch: 'hsl(38 96% 50%)' },
] as const;

export type Accent = (typeof ACCENTS)[number]['id'];

/**
 * Indigo, not the theme's own accent: paper ships terracotta, which reads warm
 * next to the warm canvas. Indigo gives the default look a cool counterpoint.
 * `default` remains selectable for whatever a theme prefers.
 */
export const DEFAULT_ACCENT: Accent = 'indigo';

export const DEFAULT_THEME: Theme = 'paper';

const STORAGE_KEY = 'theme';
const ACCENT_KEY = 'accent';

const isTheme = (value: unknown): value is Theme => THEMES.some((t) => t.id === value);
const isAccent = (value: unknown): value is Accent => ACCENTS.some((a) => a.id === value);

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') return;

    document.cookie = `${name}=${value};path=/;max-age=${days * 24 * 60 * 60};SameSite=Lax`;
};

const applyTheme = (theme: Theme) => {
    document.documentElement.setAttribute('data-theme', theme);
};

const applyAccent = (accent: Accent) => {
    if (accent === 'default') {
        document.documentElement.removeAttribute('data-accent');

        return;
    }

    document.documentElement.setAttribute('data-accent', accent);
};

/** Read the stored theme, falling back to the default for missing/legacy values. */
export const storedTheme = (): Theme => {
    if (typeof localStorage === 'undefined') return DEFAULT_THEME;

    const saved = localStorage.getItem(STORAGE_KEY);

    return isTheme(saved) ? saved : DEFAULT_THEME;
};

/** Read the stored accent, falling back to the theme's own. */
export const storedAccent = (): Accent => {
    if (typeof localStorage === 'undefined') return DEFAULT_ACCENT;

    const saved = localStorage.getItem(ACCENT_KEY);

    return isAccent(saved) ? saved : DEFAULT_ACCENT;
};

export function initializeTheme() {
    applyTheme(storedTheme());
    applyAccent(storedAccent());
}

export function useTheme() {
    const [theme, setTheme] = useState<Theme>(DEFAULT_THEME);

    const updateTheme = useCallback((next: Theme) => {
        setTheme(next);
        localStorage.setItem(STORAGE_KEY, next);
        setCookie(STORAGE_KEY, next);
        applyTheme(next);
    }, []);

    useEffect(() => {
        setTheme(storedTheme());
    }, []);

    return { theme, updateTheme } as const;
}

export function useAccent() {
    const [accent, setAccent] = useState<Accent>(DEFAULT_ACCENT);

    const updateAccent = useCallback((next: Accent) => {
        setAccent(next);
        localStorage.setItem(ACCENT_KEY, next);
        setCookie(ACCENT_KEY, next);
        applyAccent(next);
    }, []);

    useEffect(() => {
        setAccent(storedAccent());
    }, []);

    return { accent, updateAccent } as const;
}
