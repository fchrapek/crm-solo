import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { StageStepper } from './components/stage-stepper';
import { TierBadge } from './components/tier-badge';
import { filterPill, leadPlace, leadsPlace, leadsUrl, leadView } from './place';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const t = (key: string, options?: Record<string, unknown>) => i18next.t(key, options);
const filters = { pipeline: 'all', stage: 'all', tier: 'gold', source: 'outbound' };

describe('Leady', () => {
    test('Leady stand beside Kontakty as a pill, with their own token', () => {
        const place = leadsPlace({ filters }, { t, url: '/leads', locale: 'pl-PL' });

        expect(place.tokens).toEqual([{ label: 'Leady' }]);
        expect(place.pills?.map((pill) => [pill.label, pill.active])).toEqual([
            ['Kontakty', false],
            ['Leady', true],
        ]);
    });

    test('a lead and the new lead are one level under Leady', () => {
        expect(leadPlace('Zofia Szablonowa', { t, url: '/leads/3/edit', locale: 'pl-PL' }).tokens).toEqual([{ label: 'Leady', href: '/leads' }, { label: 'Zofia Szablonowa' }]);
    });

    test('the view lives in the URL, and a search from the command bar keeps the filters and the view', () => {
        expect(leadView('/leads')).toBe('kanban');
        expect(leadView('/leads?tier=gold&widok=lista')).toBe('list');
        expect(leadsUrl(filters, 'kanban')).toBe('/leads?tier=gold&source=outbound');
        expect(leadsPlace({ filters }, { t, url: '/leads?widok=lista', locale: 'pl-PL' }).filter?.href('anna')).toBe('/leads?tier=gold&source=outbound&search=anna&widok=lista');
    });

    test('a filter is a menu pill that names its choice once it narrows', () => {
        const choices = [
            { value: 'all', label: 'Wszystkie poziomy' },
            { value: 'gold', label: 'Gorący' },
        ];
        const open = filterPill('Poziom', 'all', choices, (value) => `/leads?tier=${value}`);
        const narrowed = filterPill('Poziom', 'gold', choices, (value) => `/leads?tier=${value}`);

        expect(open).toMatchObject({ label: 'Poziom', active: false });
        expect(narrowed).toMatchObject({ label: 'Poziom: gorący', active: true });
        expect('menu' in narrowed && narrowed.menu.map((choice) => choice.active)).toEqual([false, true]);
    });

    test('a tier is told by the shape of its dot, with the score beside the name', () => {
        expect(renderToStaticMarkup(<TierBadge tier="gold" total={8} />)).toMatch(/data-status="hot"[^>]*><\/span>Gorący · 8/);
        expect(renderToStaticMarkup(<TierBadge tier="oak" />)).toContain('data-status="warm"');
        expect(renderToStaticMarkup(<TierBadge tier="unknown" />)).toContain('data-status="cold"');
    });

    test('the stage track marks the current stage and offers every other one', () => {
        const html = renderToStaticMarkup(<StageStepper stages={['new', 'won']} current="new" onSelect={() => {}} />);

        expect(html.match(/aria-pressed="true"/g)).toHaveLength(1);
        expect(html.match(/<button/g)).toHaveLength(2);
    });
});
