import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../lang/pl.json';
import { StatusAction, statusLine, type ReportStatus } from './status-actions';

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
