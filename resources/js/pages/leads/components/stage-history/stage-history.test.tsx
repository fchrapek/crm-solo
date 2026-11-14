import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { StageHistory, type StageEvent } from './stage-history';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const event = (over: Partial<StageEvent>): StageEvent => ({ id: 1, from_stage: 'new', to_stage: 'talk', note: null, created_at: '2026-11-10T09:00:00+00:00', user_name: 'Anna Zielińska', ...over });
const render = (events: StageEvent[]) => renderToStaticMarkup(<StageHistory events={events} stageName={(stage) => stage.toUpperCase()} onDelete={() => {}} />);

describe('StageHistory', () => {
    test('an entry shows the move, its day and who made it, and its note as text under the row', () => {
        const html = render([event({ note: 'Oddzwonić po 15, prosi o wycenę sklepu' })]);

        expect(html).toContain('NEW → TALK');
        expect(html).toContain('10.11');
        expect(html).toMatch(/<span title="Anna Zielińska" aria-label="Anna Zielińska">AZ<\/span>/);
        expect(html).toMatch(/<li[^>]*data-note="">Oddzwonić po 15, prosi o wycenę sklepu<\/li>/);
    });

    test('an entry without a note or an author shows neither, and the capture cannot be deleted', () => {
        const html = render([event({ from_stage: null, to_stage: 'new', user_name: null })]);

        expect(html).not.toContain('data-note');
        expect(html).not.toContain('aria-label="Anna');
        expect(html).not.toContain('<button');
    });

    test('three entries show at first and the rest opens on demand', () => {
        const html = render([1, 2, 3, 4].map((id) => event({ id, note: `Notatka ${id}` })));

        expect(html).toContain('Notatka 3');
        expect(html).not.toContain('Notatka 4');
        expect(html).toContain('Pokaż pełną historię (4)');
    });
});
