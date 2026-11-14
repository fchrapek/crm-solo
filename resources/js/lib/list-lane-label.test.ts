import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import pl from '../../../lang/pl.json';
import { listLaneLabel } from './list-lane-label';

const i18n = i18next.createInstance();
await i18n.init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });
const t = i18n.getFixedT('pl');

describe('listLaneLabel', () => {
    test('a manual lane name is shown in the reader language', () => {
        expect(listLaneLabel(t, 'To-Do')).toBe('Do zrobienia');
        expect(listLaneLabel(t, 'Doing')).toBe('W toku');
        expect(listLaneLabel(t, 'Done')).toBe('Gotowe');
    });

    test('a custom or Trello lane keeps its own name', () => {
        expect(listLaneLabel(t, 'Waiting for client')).toBe('Waiting for client');
    });

    test('a custom lane with a colon keeps its whole name', () => {
        expect(listLaneLabel(t, 'QA:Blocked')).toBe('QA:Blocked');
    });

    test('no lane reads as a dash', () => {
        expect(listLaneLabel(t, null)).toBe('-');
    });
});
