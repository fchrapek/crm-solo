import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';
import pl from '../../../../../lang/pl.json';
import { TimerSwitch } from './timer-switch';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const timer = (id: number) => ({ id, started_at: '2026-11-18T10:00:00+01:00', task: `Zadanie ${id}`, client: 'Pracownia Mebli' });

describe('TimerSwitch', () => {
    test('one timer has nothing to switch, so nothing is drawn', () => {
        expect(renderToStaticMarkup(<TimerSwitch timers={[timer(1)]} shown={1} />)).toBe('');
    });

    test('with several it says which of how many is shown', () => {
        const html = renderToStaticMarkup(<TimerSwitch timers={[timer(3), timer(2), timer(1)]} shown={2} />);

        expect(html).toContain('aria-label="Działające timery"');
        expect(html).toContain('2 z 3');
    });
});
