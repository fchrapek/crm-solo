import { useCallback, useEffect, useState } from 'react';

/**
 * Accent — the single saturated colour in the UI, independent of appearance.
 * `default` means "whatever the theme ships", which is why it is not a colour
 * of its own. Persisted in localStorage and a cookie.
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

/** The one theme: themes.css keys its tokens on this `data-theme` value. */
export const THEME = 'paper';

const ACCENT_KEY = 'accent';

const isAccent = (value: unknown): value is Accent => ACCENTS.some((a) => a.id === value);

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') return;

    document.cookie = `${name}=${value};path=/;max-age=${days * 24 * 60 * 60};SameSite=Lax`;
};

export const applyTheme = () => {
    document.documentElement.setAttribute('data-theme', THEME);
};

export const applyAccent = (accent: Accent) => {
    if (accent === 'default') {
        document.documentElement.removeAttribute('data-accent');

        return;
    }

    document.documentElement.setAttribute('data-accent', accent);
};

/** Read the stored accent, falling back to the theme's own. */
export const storedAccent = (): Accent => {
    if (typeof localStorage === 'undefined') return DEFAULT_ACCENT;

    const saved = localStorage.getItem(ACCENT_KEY);

    return isAccent(saved) ? saved : DEFAULT_ACCENT;
};

export function initializeTheme() {
    applyTheme();
    applyAccent(storedAccent());
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
