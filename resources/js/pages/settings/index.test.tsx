import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { ModulesTile } from './components/modules-tile';
import Settings, { settingsTabs } from './index';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const context = { t: (key: string, options?: Record<string, unknown>) => i18next.t(key, options), url: '/settings', locale: 'pl-PL' };
const place = (tab: string | null, intake = true) => Settings.place({ tab, intake_available: intake } as never, context);

describe('Ustawienia', () => {
    test('five tabs on one row: Ogólne, Leady, Formularze, Wiadomości and Integracje, and the demo has no forms', () => {
        expect(settingsTabs()).toEqual(['general', 'leads', 'forms', 'messages', 'integrations']);
        expect(settingsTabs(false)).toEqual(['general', 'leads', 'messages', 'integrations']);
        expect(place('general').pills?.map((pill) => pill.label)).toEqual(['Ogólne', 'Leady', 'Formularze', 'Wiadomości', 'Integracje']);
    });

    test('the token is the tab and the tabs are the pills, one of them current', () => {
        const { tokens, pills, pillsFrom } = place('integrations');

        expect(tokens).toEqual([{ label: 'Integracje' }]);
        expect(pills?.filter((pill) => pill.active).map((pill) => pill.label)).toEqual(['Integracje']);
        expect(pillsFrom).toBeUndefined();
    });

    test('/settings has no token; its pills stand from 768 px with Ogólne current, a phone lists the sections itself', () => {
        const { tokens, pills, pillsFrom } = place(null);

        expect(tokens).toEqual([]);
        expect(pills?.find((pill) => pill.active)?.label).toBe('Ogólne');
        expect(pillsFrom).toBe('wide');
    });

    test('Moduły rows carry no grey second line: what a module does is in its (i), the client fields link stays on the tab', () => {
        const html = renderToStaticMarkup(
            <ModulesTile modules={{ month_close: true, month_close_in_use: 0, report_baseline: false, client_fields: 4, sms_rules: 3 }} />,
        );

        const rows = html.slice(html.indexOf('<ul'), html.indexOf('</ul>'));

        expect(rows.match(/<li/g)).toHaveLength(4);
        expect(rows.match(/aria-label="Więcej informacji"/g)).toHaveLength(4);
        for (const hint of [pl.module_month_close_hint, pl.module_report_baseline_hint, pl.module_client_fields_hint, pl.module_sms_hint]) {
            expect(rows).not.toContain(hint);
        }
        expect(html).toMatch(/<a href="#client-fields"[^>]*aria-label="Pokaż pola: Pola klienta"[^>]*>Pokaż pola<\/a>/);
        expect(html).toMatch(/<a[^>]*aria-label="Otwórz kartę: Wiadomości SMS"[^>]*href="\/settings\/messages"[^>]*>Otwórz kartę<\/a>/);
        expect(html).toContain('href="/settings/messages"');
        expect(html).not.toContain('/settings/client-fields');
        expect(html).not.toContain('/settings/sms');
    });
});
