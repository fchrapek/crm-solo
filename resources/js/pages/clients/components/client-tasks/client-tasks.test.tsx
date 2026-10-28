import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { minutesSince, shortDate } from '@/lib/dates';
import en from '../../../../../../lang/en.json';
import pl from '../../../../../../lang/pl.json';
import { AgentsSheetBody, ClientTasksTab, laneChoices, PhoneTaskRow, tasksActionOrder, TasksActions } from './client-tasks';
import { homePath, initialLane, withTicked } from './format';
import type { ClientTaskRow, ClientTasks, OrderTasks } from './types';

await i18next.use(initReactI18next).init({
    lng: 'pl',
    resources: { pl: { translation: pl }, en: { translation: en } },
    interpolation: { escapeValue: false },
});

const row = (id: number, extra: Partial<ClientTaskRow> = {}): ClientTaskRow => ({
    task_id: id,
    name: `Zadanie ${id}`,
    done: false,
    can_untick: false,
    due: null,
    running_id: null,
    running_since: null,
    agent_live: false,
    picked: false,
    ...extra,
});

const tasks: ClientTasks = {
    mode: 'retainer',
    expanded: null,
    lanes: [
        { key: 'doing', count: 12, rows: [row(1, { due: '2026-10-30' }), row(2, { agent_live: true }), row(3, { running_id: 77 }), row(4), row(5)] },
        { key: 'testing', count: 0, rows: [] },
        { key: 'todo', count: 1, rows: [row(6)] },
    ],
    projects: [{ id: 3, name: 'Site' }],
    repositories: [{ path: '/Users/test/Sites/site', project: 'Site' }],
};

describe('ClientTasksTab', () => {
    test('the switch names each lane with its count and opens on Doing', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />);

        expect(html).toContain('W toku · 12');
        expect(html).toContain('Testy · 0');
        expect(html).toContain('Do zrobienia · 1');
        expect(html).toMatch(/aria-selected="true"[^>]*>W toku · 12/);
    });

    test('five rows then a link to the rest of the lane', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />);

        expect(html).toContain('7 więcej →');
        expect(html).toContain('/clients/9/zadania?wszystkie=doing');
    });

    test('a row shows its due date, the agent at work, Stop for the owners timer, else Start', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />);

        expect(html).toContain(`>${shortDate('2026-10-30', 'pl-PL', new Date().getFullYear())}<`);
        expect(html).toContain('>claude<');
        expect(html).toContain('>Stop<');
        // the desktop rows and the phone rows both render; CSS shows one
        expect(html.match(/>Start</g)?.length).toBe(6);
    });

    test('the rail lists repository paths under the home folder as ~', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />);

        expect(html).toContain('Dla agentów');
        expect(html).toContain('~/Sites/site');
        expect(html).not.toContain('month-close-site');
    });

    test('an empty lane, no paths and no project say so and offer no new task', () => {
        const bare: ClientTasks = {
            mode: 'retainer',
            expanded: null,
            lanes: tasks.lanes.map((l) => ({ ...l, count: 0, rows: [] })),
            projects: [],
            repositories: [],
        };
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={bare} />);

        expect(html).toContain('Brak zadań na tej liście.');
        expect(html).toContain('Brak ścieżek repozytoriów.');
        expect(html).not.toContain('Nowe zadanie');
    });

    test('the expanded lane is the one the switch opens on', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={{ ...tasks, expanded: 'todo' }} />);

        expect(html).toMatch(/aria-selected="true"[^>]*>Do zrobienia · 1/);
        expect(html).toContain('Zadanie 6');
        expect(html).not.toContain('Zadanie 1<');
    });
});

describe('live state', () => {
    const lane = (rows: ClientTaskRow[]): ClientTasks => ({
        ...tasks,
        lanes: [{ key: 'doing', count: rows.length, rows }, tasks.lanes[1], tasks.lanes[2]],
    });

    test('an agent timer stopped elsewhere gives the row its Start back on the next refresh', () => {
        const before = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={lane([row(1, { agent_live: true })])} />);
        const after = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={lane([row(1, { agent_live: false })])} />);

        expect(before).toContain('>claude<');
        expect(before).not.toContain('>Start<');
        expect(after).not.toContain('>claude<');
        expect(after).toContain('>Start<');
    });

    test('with the owner and an agent on one task the row offers Stop for the owners timer', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={lane([row(1, { agent_live: true, running_id: 5 })])} />);

        expect(html).toContain('>Stop<');
        expect(html).not.toContain('>claude<');
    });
});

describe('withTicked', () => {
    const ticked = { 2: { lane: 'doing' as const, index: 1, row: row(2, { running_id: 9 }) } };

    test('a row ticked on this visit returns to its place, ticked and untickable, after the reload drops it', () => {
        const rows = withTicked('doing', [row(1), row(3)], ticked);

        expect(rows.map((r) => r.task_id)).toEqual([1, 2, 3]);
        expect(rows[1]).toMatchObject({ done: true, can_untick: true, running_id: null });
    });

    test('other lanes are untouched and an index past the end appends', () => {
        expect(withTicked('todo', [row(1)], ticked).map((r) => r.task_id)).toEqual([1]);
        expect(withTicked('doing', [], ticked).map((r) => r.task_id)).toEqual([2]);
    });

    test('a ticked row the agent was working on drops the agent marker, since the tick stopped every timer', () => {
        const agentTicked = { 4: { lane: 'doing' as const, index: 0, row: row(4, { agent_live: true }) } };
        const rows = withTicked('doing', [row(1)], agentTicked);

        expect(rows[0]).toMatchObject({ task_id: 4, done: true, agent_live: false, running_id: null });
        const html = renderToStaticMarkup(
            <ClientTasksTab clientId={9} tasks={{ ...tasks, lanes: [{ key: 'doing', count: 1, rows }, tasks.lanes[1], tasks.lanes[2]] }} />,
        );
        expect(html).not.toContain('>claude<');
        expect(html).not.toContain('claude pracuje');
        expect(html.match(/>Start</g)?.length).toBe(2);
        expect(withTicked('doing', [row(4, { agent_live: true, running_id: 3 })], agentTicked)[0]).toMatchObject({
            agent_live: false,
            running_id: null,
        });
    });

    test('the ticked row survives a refresh that drops it and brings a new row', () => {
        const afterTick = withTicked('doing', [row(1), row(3)], ticked);
        const afterRefresh = withTicked('doing', [row(1), row(3), row(8)], ticked);

        expect(afterTick.map((r) => r.task_id)).toEqual([1, 2, 3]);
        expect(afterRefresh.map((r) => r.task_id)).toEqual([1, 2, 3, 8]);
        expect(afterRefresh[1].done).toBe(true);
    });

    test('a ticked row the server still lists is not doubled', () => {
        const rows = withTicked('doing', [row(1), row(2)], ticked);

        expect(rows.map((r) => r.task_id)).toEqual([1, 2]);
        expect(rows[1].done).toBe(true);
    });
});

describe('N więcej in Polish', () => {
    test.each([
        [1, '1 więcej'],
        [3, '3 więcej'],
        [5, '5 więcej'],
        [22, '22 więcej'],
    ])('%i', (count, expected) => {
        expect(i18next.t('{{count}} more', { count })).toBe(expected);
    });

    test('every Polish plural form has its own key', () => {
        for (const form of ['one', 'few', 'many', 'other']) {
            expect((pl as Record<string, string>)[`{{count}} more_${form}`]).toBe('{{count}} więcej');
        }
        expect(i18next.getFixedT('en')('{{count}} more', { count: 1 })).toBe('1 more');
    });
});

describe('format', () => {
    test('a due date this year drops the year, another year keeps it', () => {
        expect(shortDate('2026-10-30', 'pl-PL', 2026)).toBe('30.10');
        expect(shortDate('2027-01-15', 'pl-PL', 2026)).toBe('15.01.2027');
    });

    test('home folders shorten to ~, other paths stay whole', () => {
        expect(homePath('/Users/you/Sites/site')).toBe('~/Sites/site');
        expect(homePath('/home/demo/www')).toBe('/home/demo/www');
        expect(homePath('/var/www/site')).toBe('/var/www/site');
        expect(homePath('/Users/you')).toBe('~');
    });

    test('the switch opens on the expanded lane, else the first with tasks, else Doing', () => {
        const lanes = [
            { key: 'doing', count: 0 },
            { key: 'testing', count: 2 },
            { key: 'todo', count: 1 },
        ];
        expect(initialLane(lanes, null)).toBe('testing');
        expect(initialLane(lanes, 'todo')).toBe('todo');
        expect(
            initialLane(
                lanes.map((l) => ({ ...l, count: 0 })),
                null,
            ),
        ).toBe('doing');
    });
});

describe('ClientTasksTab for an order', () => {
    const order: OrderTasks = {
        mode: 'order',
        expanded: null,
        open: {
            count: 2,
            rows: [
                row(1, { name: 'Raport z audytu', due: '2026-10-09' }),
                row(2, { name: 'Przekazanie raportu', due: '2026-10-14', running_id: 40 }),
            ],
        },
        done: {
            count: 7,
            rows: [{ ...row(3, { name: 'Pomiary i termowizja', done: true, can_untick: true }), done_on: '2026-09-29', minutes: 240 }],
        },
        time: { hours: 10, count: 9, rows: [{ id: 5, date: '2026-10-05', label: 'Raport z audytu', minutes: 90 }] },
        projects: [{ id: 3, name: 'Audyt' }],
        repositories: [],
    };
    const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={order} />);
    const text = html.replace(/<[^>]+>/g, '');

    test('one list of open tasks, no lane switch, with Start and Stop', () => {
        expect(text).toContain('Zadania · 9');
        expect(html).not.toContain('role="tablist"');
        expect(text).toContain('Raport z audytu');
        expect(html).toMatch(/<button[^>]*>Start<\/button>/);
        expect(html).toMatch(/<button[^>]*>Stop<\/button>/);
        expect(text).toContain('Nowe zadanie');
    });

    test('the done group reads its count, day and time, and links to the rest', () => {
        expect(text).toContain('Zrobione · 7');
        expect(text).toContain('Pomiary i termowizja');
        expect(text).toContain('29.09');
        expect(text).toContain('4 h');
        expect(html).toContain('/clients/9/zadania?wszystkie=done');
    });

    test('the rail lists the order time instead of the agent rail', () => {
        expect(text).toContain('Czas · 10,0 h');
        expect(text).toContain('05.10 · Raport z audytu');
        expect(text).toContain('1 h 30 min');
        expect(html).toContain('/clients/9/zadania?wszystkie=time');
        expect(text).not.toContain('Dla agentów');
    });

    test('on phones the same list, Zrobione and time read as touch rows with rows that open each whole part', () => {
        expect(html.match(/aria-label="Oznacz jako zrobione"/g)?.length).toBe(4);
        expect(html).toContain('aria-label="Oznacz jako niezrobione"');
        expect(text).toContain('Wszystkie zrobione');
        expect(text).toContain('Wszystkie wpisy czasu');
        expect(text).not.toContain('Wszystkie otwarte');
        expect(html).toMatch(/<button type="button" data-solo-fab=""[^>]*>Nowe zadanie<\/button>/);
    });
});

describe('Zadania on phones', () => {
    const t = i18next.t.bind(i18next);
    const noop = () => undefined;
    const phoneRow = (r: ClientTaskRow, sub?: string) =>
        renderToStaticMarkup(
            <ul>
                <PhoneTaskRow row={r} sub={sub} pending={false} onTick={noop} onUntick={noop} onStart={noop} onStop={noop} />
            </ul>,
        );

    test('the lane picker names each lane with its count, marks the current one and keeps the expanded lane', () => {
        expect(laneChoices(tasks.lanes, 'doing', t)).toEqual([
            { key: 'doing', label: 'W toku · 12', selected: true },
            { key: 'testing', label: 'Testy · 0', selected: false },
            { key: 'todo', label: 'Do zrobienia · 1', selected: false },
        ]);
        expect(renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />)).toContain('aria-label="Wybierz listę: W toku · 12"');
        expect(renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={{ ...tasks, expanded: 'todo' }} />)).toContain(
            'aria-label="Wybierz listę: Do zrobienia · 1"',
        );
    });

    test('a lane cut at five rows ends with the row that opens the whole lane, the same partial reload as desktop', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />);

        expect(html).toContain('Wszystkie w toku');
        expect(html.match(/href="\/clients\/9\/zadania\?wszystkie=doing"/g)?.length).toBe(2);
        expect(renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={{ ...tasks, expanded: 'todo' }} />)).not.toContain('Wszystkie do zrobienia');
    });

    test('a row title opens the task page beside its checkbox and Start, on the phone rows and the desktop rows alike', () => {
        const html = phoneRow(row(1, { due: '2026-10-30' }), '30.10');

        expect(html).toMatch(/<a[^>]*href="\/tasks\/1"[^>]*>Zadanie 1<\/a>/);
        expect(html).toMatch(/<button[^>]*aria-label="Oznacz jako zrobione"/);
        expect(html).toContain('Start</button>');
        expect(
            renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />).match(
                /<a[^>]*href="\/tasks\/1"[^>]*>(<span>)?Zadanie 1(<\/span>)?<\/a>/g,
            ),
        ).toHaveLength(2);
    });

    test('an idle row has a 44 px checkbox and Start, nothing else', () => {
        const html = phoneRow(row(1, { due: '2026-10-30' }), '30.10');

        expect(html).toContain('aria-label="Oznacz jako zrobione"');
        expect(html).toContain('aria-pressed="false"');
        expect(html).toContain('>30.10<');
        expect(html).toContain('Start</button>');
        expect(html).not.toContain('Stop');
    });

    test('the owners running timer gives the row Stop, never Start, and the agent mark is not the owners', () => {
        const html = phoneRow(row(1, { running_id: 77, running_since: '2026-10-05T09:18:00+00:00' }));

        expect(html).toContain('Stop</button>');
        expect(html).not.toContain('Start');
        expect(html).not.toContain('claude pracuje');
    });

    test('the elapsed time is whole minutes since the start and never negative', () => {
        const since = '2026-10-05T09:18:00+00:00';

        expect(minutesSince(since, Date.parse('2026-10-05T10:00:59Z'))).toBe(42);
        expect(minutesSince(since, Date.parse('2026-10-05T09:17:00Z'))).toBe(0);
    });

    test('an agent at work shows the live mark and opens the task instead of offering Start', () => {
        const html = phoneRow(row(4, { agent_live: true }), '09.10');

        expect(html).toContain('claude pracuje');
        expect(html).toContain('href="/tasks/4"');
        expect(html).toContain('aria-label="Otwórz zadanie"');
        expect(html).not.toContain('Start');
    });

    test('a ticked row can be unticked when the CRM can take it back, and offers no timer', () => {
        const ticked = phoneRow(row(2, { done: true, can_untick: true }));
        const boardDone = phoneRow(row(3, { done: true, can_untick: false }));

        expect(ticked).toContain('aria-pressed="true"');
        expect(ticked).toContain('aria-label="Oznacz jako niezrobione"');
        expect(ticked).not.toContain('Start');
        expect(boardDone).toContain('disabled=""');
    });

    test('Nowe zadanie floats on phones; no project, no button', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />);

        expect(html).toMatch(/<button type="button" data-solo-fab=""[^>]*>Nowe zadanie<\/button>/);
        expect(renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={{ ...tasks, projects: [] }} />)).not.toContain('data-solo-fab');
    });

    test('the ••• menu opens Dla agentów and copies the context; desktop keeps the brief chip', () => {
        expect(tasksActionOrder(true)).toEqual(['agents', 'copy']);
        expect(tasksActionOrder(false)).toEqual(['brief']);
        expect(renderToStaticMarkup(<TasksActions clientId={9} onOpenAgents={noop} />)).toContain('crm brief 9');
    });

    test('the Dla agentów sheet leads with the brief to copy, then the repository paths', () => {
        const html = renderToStaticMarkup(<AgentsSheetBody clientId={9} repositories={tasks.repositories} />);

        expect(html).toContain('aria-label="Skopiuj polecenie dla agenta: crm brief 9"');
        expect(html.indexOf('crm brief 9')).toBeLessThan(html.indexOf('~/Sites/site'));
        expect(html).not.toContain('month-close-site');
        expect(renderToStaticMarkup(<AgentsSheetBody clientId={9} repositories={[]} />)).toContain('Brak ścieżek repozytoriów.');
    });

    test('the desktop rail stays and the sheet content waits for the menu', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />);

        expect(html.match(/~\/Sites\/site/g)?.length).toBe(1);
        expect(html).not.toContain('crm brief 9');
    });
});
