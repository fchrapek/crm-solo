import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../lang/pl.json';
import ReportEdit from './edit';
import { isEditing, modeUrl, StatusAction, statusChip, statusLine, type ReportStatus } from './status-actions';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const action = (status: ReportStatus) => renderToStaticMarkup(<StatusAction status={status} onConfirm={() => undefined} />);
const t = i18next.t.bind(i18next) as (key: string, options?: Record<string, unknown>) => string;

describe('the report status step', () => {
    test('a draft moves on to ready, a ready report to sent, and a sent one has no forward step', () => {
        expect(action('draft')).toContain('Oznacz jako gotowy');
        expect(action('finalized')).toContain('Oznacz jako wysłany');
        expect(action('sent')).toBe('');
    });

    test('the status line names the sent day, or the state and the day it began', () => {
        const report = { finalized_on: null, generated_on: '2026-10-02' };

        expect(statusLine({ ...report, status: 'sent', sent_on: '2026-10-04' }, 'pl-PL', t)).toContain('wysłany 04.10');
        expect(statusLine({ ...report, status: 'draft', sent_on: null }, 'pl-PL', t)).toBe('Szkic z 02.10');
    });
});

describe('the place of the report page', () => {
    const props = { client: { id: 7, name: 'Pracownia Mebli sp. z o.o.', short_name: 'Pracownia Mebli' }, report: { id: 9, period_start: '2026-09-01' } } as never;
    const place = (url: string) => ReportEdit.place(props, { t, url, locale: 'pl' });

    test('the tokens are the client, the month and Report, and the month leads to its Billing tab', () => {
        expect(place('/clients/7/reports/9').tokens.map((token) => [token.label, 'href' in token ? token.href : undefined])).toEqual([
            ['Pracownia Mebli', '/clients/7/overview'],
            ['Wrzesień', '/clients/7/billing?month=2026-09'],
            ['Raport', undefined],
        ]);
    });

    test('Preview and Edit are the pills, and the URL says which one the page is on', () => {
        expect(place('/clients/7/reports/9').pills.map((pill) => [pill.label, pill.href, pill.active])).toEqual([
            ['Podgląd', '/clients/7/reports/9', true],
            ['Edytuj', '/clients/7/reports/9?edycja=1', false],
        ]);
        expect(place('/clients/7/reports/9?edycja=1').pills.map((pill) => pill.active)).toEqual([false, true]);
        expect(place('/clients/7/reports/9').pills[1].visit).toMatchObject({ preserveState: true, replace: true });
    });

    test('the mode is read from the query and written back the same way', () => {
        expect(isEditing(modeUrl('/clients/7/reports/9', true))).toBe(true);
        expect(isEditing(modeUrl('/clients/7/reports/9', false))).toBe(false);
        expect(isEditing('/clients/7/reports/9?edycja=0')).toBe(false);
    });
});

describe('the delivery chip', () => {
    test('a draft is open, a ready report waits, a sent one is done with its day', () => {
        expect(statusChip({ status: 'draft', sent_on: null }, 'pl', t)).toEqual({ status: 'open', label: 'szkic' });
        expect(statusChip({ status: 'finalized', sent_on: null }, 'pl', t)).toEqual({ status: 'waiting', label: 'gotowy' });
        expect(statusChip({ status: 'sent', sent_on: '2026-10-05' }, 'pl', t)).toEqual({ status: 'done', label: 'wysłany 05.10' });
    });
});
