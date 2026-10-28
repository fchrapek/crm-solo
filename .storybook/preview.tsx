import '../resources/css/app.css';

import { SoloSurface, type DayState } from '@/components/solo/surface';
import { applyAppearance } from '@/hooks/use-appearance';
import { ACCENTS, applyAccent, applyTheme, DEFAULT_ACCENT, DEFAULT_THEME, THEMES } from '@/hooks/use-theme';
import type { Decorator, Preview } from '@storybook/react-vite';
import i18next from 'i18next';
import { I18nextProvider, initReactI18next } from 'react-i18next';
import en from '../lang/en.json';
import pl from '../lang/pl.json';

const translator = (lng: 'pl' | 'en') => {
    const instance = i18next.createInstance();
    void instance.use(initReactI18next).init({
        lng,
        fallbackLng: 'en',
        resources: { en: { translation: en }, pl: { translation: pl } },
        interpolation: { escapeValue: false },
    });

    return instance;
};

const translators = { pl: translator('pl'), en: translator('en') };

const withAppAxes: Decorator = (Story, { globals }) => {
    applyTheme(globals.theme);
    applyAccent(globals.accent);
    applyAppearance(globals.appearance);

    return (
        <I18nextProvider i18n={translators[globals.locale as 'pl' | 'en']}>
            <SoloSurface day={globals.day as DayState} style={{ minHeight: '100vh', padding: 'var(--solo-space-5)' }}>
                <Story />
            </SoloSurface>
        </I18nextProvider>
    );
};

// Sizes on each side of the app's breakpoints (768 and 1100 px).
const viewports = {
    phone: { name: 'Phone', styles: { width: '390px', height: '844px' }, type: 'mobile' },
    tablet: { name: 'Tablet (768)', styles: { width: '768px', height: '1024px' }, type: 'tablet' },
    desktop: { name: 'Desktop (1100+)', styles: { width: '1280px', height: '800px' }, type: 'desktop' },
} as const;

const preview: Preview = {
    decorators: [withAppAxes],
    parameters: { layout: 'fullscreen', viewport: { options: viewports } },
    initialGlobals: {
        theme: DEFAULT_THEME,
        accent: DEFAULT_ACCENT,
        appearance: 'light',
        locale: 'pl',
        day: 'paper',
        viewport: { value: 'phone', isRotated: false },
    },
    globalTypes: {
        theme: {
            toolbar: { title: 'Theme', icon: 'paintbrush', dynamicTitle: true, items: THEMES.map((t) => ({ value: t.id, title: t.label })) },
        },
        accent: {
            toolbar: { title: 'Accent', icon: 'circle', dynamicTitle: true, items: ACCENTS.map((a) => ({ value: a.id, title: a.label })) },
        },
        appearance: {
            toolbar: {
                title: 'Appearance',
                icon: 'mirror',
                dynamicTitle: true,
                items: [
                    { value: 'light', title: 'Light' },
                    { value: 'dark', title: 'Dark' },
                ],
            },
        },
        locale: {
            toolbar: {
                title: 'Language',
                icon: 'globe',
                dynamicTitle: true,
                items: [
                    { value: 'pl', title: 'Polski' },
                    { value: 'en', title: 'English' },
                ],
            },
        },
        day: {
            toolbar: {
                title: 'Day',
                icon: 'time',
                dynamicTitle: true,
                items: [
                    { value: 'paper', title: 'Paper' },
                    { value: 'timer', title: 'Timer running' },
                    { value: 'done', title: 'Day closed' },
                ],
            },
        },
    },
};

export default preview;
