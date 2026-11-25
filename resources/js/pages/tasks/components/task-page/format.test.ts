import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import en from '../../../../../../lang/en.json';
import pl from '../../../../../../lang/pl.json';
import { activityMeta, activityTitle, agentPrompt, dueText, fileSize, liveMinutes, whenLabel } from './format';
import type { ActivityRow } from './types';

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
        entry: null,
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

describe('Aktywność rows', () => {
    test('a title says what happened in a few words, the same for an agent and the owner', () => {
        expect(activityTitle(row({ minutes: 38, text: 'odczyt arkusza' }), 'pl', t)).toBe('Czas: 38 min · odczyt arkusza');
        expect(activityTitle(row({ actor: { kind: 'owner', name: null }, minutes: 120 }), 'pl', t)).toBe('Czas: 2 h');
        expect(activityTitle(row({ kind: 'finished' }), 'pl', t)).toBe('Zrobione w CRM');
        expect(activityTitle(row({ kind: 'reopened' }), 'pl', t)).toBe('Otwarte ponownie w CRM');
        expect(activityTitle(row({ kind: 'task_created' }), 'pl', t)).toBe('Zadanie utworzone');
    });

    test('brief and task changes name the fields in the language of the page', () => {
        expect(activityTitle(row({ kind: 'brief', fields: ['done_when', 'notes'] }), 'pl', t)).toBe('Brief: Gotowe gdy, Notatki');
        expect(activityTitle(row({ kind: 'brief', fields: ['where', 'notes'] }), 'en', tEn)).toBe('Brief: Where, Notes');
        expect(activityTitle(row({ kind: 'task', fields: ['name', 'due'] }), 'pl', t)).toBe('Zadanie: nazwa, termin');
        expect(activityTitle(row({ kind: 'note', text: 'brak cen stołów.' }), 'pl', t)).toBe('Notatka: brak cen stołów.');
    });

    test('the end of a row names who and when, the owner as ty', () => {
        expect(activityMeta(row({}), NOW, '2026-10-08', 'pl', t)).toBe('Codex · 12 min temu');
        expect(activityMeta(row({ actor: { kind: 'owner', name: null }, at: '2026-10-01T07:05:00Z', day: '2026-10-01' }), NOW, '2026-10-08', 'pl', t)).toBe(
            'ty · 01.10',
        );
        expect(activityMeta(row({ actor: { kind: 'person', name: 'Anna Nowak' } }), NOW, '2026-10-08', 'pl', t)).toBe('Anna Nowak · 12 min temu');
    });
});

describe('labels', () => {
    test('a file size reads in the unit a person would use', () => {
        expect(fileSize(815, 'pl')).toBe('815 B');
        expect(fileSize(48_210, 'pl')).toBe('47 kB');
        expect(fileSize(1_258_291, 'pl')).toBe('1,2 MB');
    });

    test('the due date reads without the year in the current one', () => {
        expect(dueText('2026-10-15', '2026-10-08', 'pl', t)).toBe('do 15.10');
        expect(dueText('2027-01-15', '2026-10-08', 'pl', t)).toBe('do 15.01.2027');
    });

    test('Copy for the agent sends the agent to crm task in both languages', () => {
        expect(agentPrompt(36, t)).toBe('Pracuj nad zadaniem 36 z CRM Solo (crm task 36)');
        expect(agentPrompt(36, tEn)).toBe('Work on task 36 from CRM Solo (crm task 36)');
    });
});
