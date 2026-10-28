import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import en from '../../../../../../lang/en.json';
import pl from '../../../../../../lang/pl.json';
import { activityLine, activitySentence, agentPrompt, dueText, liveMinutes, resourcesSummary, whenLabel } from './format';
import type { ActivityRow, ResourceFile, ResourceLink, TaskResources } from './types';

const i18n = i18next.createInstance();
await i18n.init({
    lng: 'pl',
    fallbackLng: 'en',
    resources: { en: { translation: en }, pl: { translation: pl } },
    interpolation: { escapeValue: false },
});
const t = i18n.getFixedT('pl');
const tEn = i18n.getFixedT('en');

const NOW = new Date('2026-10-08T12:51:00Z').getTime();

function row(over: Partial<ActivityRow>): ActivityRow {
    return {
        key: 'k',
        kind: 'time',
        actor: { kind: 'agent', name: 'Codex' },
        at: '2026-10-08T12:39:00Z',
        day: '2026-10-08',
        time: '14:39',
        minutes: null,
        text: null,
        fields: [],
        ...over,
    };
}

describe('when a row happened', () => {
    test('inside the last hour it reads as minutes ago, under a minute as just now', () => {
        expect(whenLabel(row({ at: '2026-10-08T12:39:00Z' }), NOW, '2026-10-08', 'pl', t)).toBe('12 min temu');
        expect(whenLabel(row({ at: '2026-10-08T12:50:30Z' }), NOW, '2026-10-08', 'pl', t)).toBe('przed chwilą');
    });

    test('a write a few seconds ahead of the page clock reads as just now, not as a time', () => {
        expect(whenLabel(row({ at: '2026-10-08T12:51:09Z', time: '14:51' }), NOW, '2026-10-08', 'pl', t)).toBe('przed chwilą');
    });

    test('earlier today it reads as the local time the server gave, before today as the date', () => {
        expect(whenLabel(row({ at: '2026-10-08T07:05:00Z', day: '2026-10-08', time: '09:05' }), NOW, '2026-10-08', 'pl', t)).toBe('09:05');
        expect(whenLabel(row({ at: '2026-10-01T07:05:00Z', day: '2026-10-01', time: '09:05' }), NOW, '2026-10-08', 'pl', t)).toBe('01.10');
        expect(whenLabel(row({ at: '2025-12-30T07:05:00Z', day: '2025-12-30', time: '08:05' }), NOW, '2026-10-08', 'pl', t)).toBe('30.12.2025');
    });

    test('the day is the display timezone day from the server, whatever the browser clock says', () => {
        // 23:30 UTC is already the next day in Warsaw; the row says so, and the label follows it.
        const late = row({ at: '2026-10-07T23:30:00Z', day: '2026-10-08', time: '01:30' });
        expect(whenLabel(late, NOW, '2026-10-08', 'pl', t)).toBe('01:30');
    });
});

describe('minutes on the page', () => {
    test('a running timer adds its time so far; one started before local midnight counts on its own day, as it will once stopped', () => {
        const time = {
            today_minutes: 10,
            total_minutes: 100,
            day_start: '2026-10-07T22:00:00Z',
            own_running: null,
            running: ['2026-10-08T12:39:00Z', '2026-10-07T21:00:00Z'],
            running_since: '14:39',
        };
        const minutes = liveMinutes(time, NOW);

        expect(minutes.total).toBe(100 + 12 + (15 * 60 + 51));
        expect(minutes.today).toBe(10 + 12);
    });
});

describe('Aktywność sentences', () => {
    test('an agent writes in the third person, the owner is addressed', () => {
        expect(activitySentence(row({ minutes: 38, text: 'odczyt arkusza i plan tabel.' }), 'pl', t)).toBe(
            'Zapisał 38 min pracy: odczyt arkusza i plan tabel.',
        );
        expect(activitySentence(row({ actor: { kind: 'owner', name: null }, minutes: 120, text: 'nowe ceny.' }), 'pl', t)).toBe(
            'Zapisano 2 h pracy: nowe ceny.',
        );
        expect(activitySentence(row({ actor: { kind: 'owner', name: null }, minutes: 14 }), 'pl', t)).toBe('Zapisano 14 min pracy.');
        expect(activitySentence(row({ kind: 'finished', actor: { kind: 'owner', name: null } }), 'pl', t)).toBe('Oznaczono jako zrobione w CRM.');
        expect(activitySentence(row({ kind: 'finished' }), 'pl', t)).toBe('Oznaczył jako zrobione w CRM.');
    });

    test('brief changes name the fields in the language of the page, one field in the singular', () => {
        expect(activitySentence(row({ kind: 'brief', fields: ['done_when', 'notes'] }), 'pl', t)).toBe('Zmienił pola briefu: Gotowe gdy i Notatki.');
        expect(activitySentence(row({ kind: 'brief', fields: ['where'] }), 'pl', t)).toBe('Zmienił pole briefu: Gdzie.');
        expect(activitySentence(row({ kind: 'brief', fields: ['where', 'notes'] }), 'en', tEn)).toBe('Changed the brief fields Where and Notes.');
    });

    test('notes and task edits read as the design writes them', () => {
        expect(activitySentence(row({ kind: 'note', text: 'brak cen stołów.' }), 'pl', t)).toBe('Notatka: brak cen stołów.');
        expect(activitySentence(row({ kind: 'task', fields: ['name', 'due'] }), 'pl', t)).toBe('Zmienił zadanie: nazwa i termin.');
    });
});

describe('labels', () => {
    test('a one-line activity row reads the sentence as its lower-case tail, without the full stop', () => {
        expect(activityLine(row({ kind: 'brief', fields: ['where', 'notes'] }), 'pl', t)).toBe('zmienił pola briefu: Gdzie i Notatki');
        expect(activityLine(row({ actor: { kind: 'owner', name: null }, minutes: 120 }), 'pl', t)).toBe('zapisano 2 h pracy');
    });

    test('Zasoby counts each kind with its Polish plural', () => {
        const file = { id: 1, name: 'a.pdf', url: '/x', mime: 'application/pdf', size: 1, from: 'upload', is_image: false } satisfies ResourceFile;
        const link = { id: 1, url: 'https://x.test', label: null, from: 'crm' } satisfies ResourceLink;
        const resources: TaskResources = {
            files: [file, { ...file, id: 2 }],
            images: [
                { ...file, id: 3, is_image: true },
                { ...file, id: 4, is_image: true },
            ],
            links: [link, { ...link, id: 2 }],
        };

        expect(resourcesSummary(resources, t)).toBe('6 · 2 pliki, 2 obrazy, 2 linki');
        expect(resourcesSummary({ files: Array.from({ length: 5 }, (_, i) => ({ ...file, id: i })), images: [], links: [link] }, t)).toBe(
            '6 · 5 plików, 1 link',
        );
        expect(resourcesSummary({ files: [], images: [], links: [] }, t)).toBe('na razie brak');
    });

    test('the due date reads without the year in the current one', () => {
        expect(dueText('2026-10-15', '2026-10-08', 'pl', t)).toBe('do 15.10');
        expect(dueText('2027-01-15', '2026-10-08', 'pl', t)).toBe('do 15.01.2027');
    });

    test('Kopiuj dla agenta sends the agent to crm task in both languages', () => {
        expect(agentPrompt(36, t)).toBe('Pracuj nad zadaniem 36 z CRM Solo (crm task 36)');
        expect(agentPrompt(36, tEn)).toBe('Work on task 36 from CRM Solo (crm task 36)');
    });
});
