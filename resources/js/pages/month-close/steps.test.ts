import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import pl from '../../../../lang/pl.json';
import { emailStep, invoiceDrafts, type SummaryEmailState } from './steps';

await i18next.init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });
const t = i18next.t.bind(i18next);

const base: SummaryEmailState = { status: null, drafted_on: null, drafted_via: null, sent_on: null, changed_on: '2026-10-02', recipients: 0 };

describe('emailStep', () => {
    test('each state of the summary email has its meta, colour and action', () => {
        expect(emailStep(base, 'pl-PL', t)).toEqual({ meta: 'Ty', short: 'Ty', action: 'Przygotuj szkic' });
        expect(emailStep({ ...base, status: 'draft', drafted_on: '2026-10-02', drafted_via: 'agent' }, 'pl-PL', t)).toEqual({
            meta: 'szkic 02.10 · agent',
            short: 'szkic 02.10',
            action: 'Otwórz szkic',
        });
        expect(emailStep({ ...base, status: 'test_sent' }, 'pl-PL', t)).toMatchObject({
            meta: 'testowo 02.10',
            tone: 'warn',
            action: 'Otwórz szkic',
        });
        expect(emailStep({ ...base, status: 'sent', sent_on: '2026-10-02', recipients: 2 }, 'pl-PL', t)).toEqual({
            meta: 'wysłano 02.10 · 2 osoby',
            short: 'wysłano 02.10',
            action: 'Zobacz',
        });
        expect(emailStep({ ...base, status: 'failed' }, 'pl-PL', t)).toMatchObject({
            meta: 'nie wysłano 02.10',
            short: 'nie wysłano',
            tone: 'error',
            action: 'Otwórz',
        });
        expect(emailStep({ ...base, status: 'unconfirmed' }, 'pl-PL', t)).toMatchObject({
            meta: 'niepotwierdzone 02.10',
            tone: 'warn',
            action: 'Otwórz',
        });
    });
});

describe('invoiceDrafts', () => {
    test('a skipped draft counts as resolved, clients without the step are left out', () => {
        const rows = [{ invoice: 'done' as const }, { invoice: 'skipped' as const }, { invoice: 'pending' as const }, { invoice: null }];

        expect(invoiceDrafts(rows)).toEqual({ done: 2, total: 3 });
    });
});
