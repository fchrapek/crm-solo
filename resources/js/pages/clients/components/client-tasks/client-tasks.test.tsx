import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { minutesSince, shortDate } from '@/lib/dates';
import en from '../../../../../../lang/en.json';
import pl from '../../../../../../lang/pl.json';
import { AgentsTile, ClientTasksTab, elapsedText, ProjectsTile, TaskLine, tasksActionOrder } from './client-tasks';
import { homePath, withTicked } from './format';
import type { ClientTaskRow, ClientTasks, OrderTasks, RetainerTasks } from './types';

await i18next.use(initReactI18next).init({
    lng: 'pl',
    resources: { pl: { translation: pl }, en: { translation: en } },
    interpolation: { escapeValue: false },
});

const row = (id: number, extra: Partial<ClientTaskRow> = {}): ClientTaskRow => ({
    task_id: id,
    name: `Zadanie ${id}`,
    project: 'Site',
    done: false,
    can_untick: false,
    due: null,
    running_id: null,
    running_since: null,
    agent_live: false,
    picked: false,
    ...extra,
});

const tasks: RetainerTasks = {
    mode: 'retainer',
    expanded: null,
    lanes: [
        { key: 'doing', count: 12, rows: [row(1, { due: '2026-10-30' }), row(2, { agent_live: true }), row(3, { running_id: 77 }), row(4), row(5)] },
        { key: 'testing', count: 0, rows: [] },
        { key: 'todo', count: 1, rows: [row(6)] },
    ],
    done: { count: 0, rows: [] },
    projects: [{ id: 3, name: 'Site', open: 13, is_private: true }],
    reportable_by_default: true,
    repositories: [{ path: '/Users/test/Sites/site', project: 'Site' }],
};

const text = (html: string) => html.replace(/<[^>]+>/g, '');

describe('ClientTasksTab', () => {
    const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={tasks} />);

    test('every lane shows at once as a group with its count, in the order of the board', () => {
        const heads = [...html.matchAll(/<h2[^>]*>([^<]+)<\/h2><span[^>]*>(\d+)<\/span>/g)].map(([, label, count]) => [label, Number(count)]);

        expect(heads.slice(0, 3)).toEqual([
            ['W toku', 12],
            ['Testy', 0],
            ['Do zrobienia', 1],
        ]);
        expect(html).not.toContain('role="tablist"');
    });

    test('five rows then the row that opens the rest of the lane with a partial reload', () => {
        expect(html).toMatch(/<a[^>]*href="\/clients\/9\/zadania\?wszystkie=doing"[^>]*>Wszystkie w toku<\/a><\/span><span[^>]*>7 więcej<\/span>/);
        expect(html).not.toContain('wszystkie=todo');
    });

    test('a row opens its task, shows its due date and project, the agent at work, Stop for the owners timer, else Start', () => {
        expect(html).toContain('href="/tasks/1"');
        expect(html).toContain('30.10');
        expect(text(html)).toContain('claude pracuje');
        expect(html.match(/>Stop</g)).toHaveLength(1);
        expect(html.match(/>Start</g)).toHaveLength(4);
        expect(html).toMatch(/<span[^>]*>Site<\/span>/);
    });

    test('an empty lane says so, and a client without done tasks has no Zrobione group', () => {
        expect(html).toContain('Brak zadań na tej liście.');
        expect(html).not.toContain('Zrobione');
    });

    test('the expanded lane sends every row and so has no row for the rest', () => {
        const all: ClientTasks = { ...tasks, expanded: 'todo', lanes: [tasks.lanes[0], tasks.lanes[1], { key: 'todo', count: 1, rows: [row(6)] }] };

        expect(renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={all} />)).not.toContain('Wszystkie do zrobienia');
    });

    test('a project page sends the rest of a lane to its own address', () => {
        const project = renderToStaticMarkup(
            <ClientTasksTab clientId={9} tasks={tasks} partHref={(part) => `/clients/9/projects/3?wszystkie=${part}`} side={<p>Projekt</p>} />,
        );

        expect(project).toContain('href="/clients/9/projects/3?wszystkie=doing"');
        expect(project).toContain('<p>Projekt</p>');
        expect(project).not.toContain('Dla agentów');
    });
});

describe('done tasks', () => {
    const done: RetainerTasks = {
        ...tasks,
        done: {
            count: 13,
            rows: [
                { ...row(20, { name: 'Poprawka stopki', done: true, can_untick: true }), done_on: '2026-10-24', minutes: 70 },
                { ...row(21, { name: 'Karta z Trello', done: true, can_untick: false }), done_on: '2026-10-22', minutes: 0 },
            ],
        },
    };
    const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={done} />);

    test('Zrobione follows the lanes with its count, each row with its time, day and project', () => {
        expect(html).toMatch(/<h2[^>]*>Zrobione<\/h2><span[^>]*>13<\/span>/);
        expect(html.indexOf('Zrobione')).toBeGreaterThan(html.indexOf('Do zrobienia'));
        expect(text(html)).toContain('Poprawka stopki1 h 10 min24.10 · Site');
    });

    test('a task the CRM ticked can be unticked, a card done on its board cannot', () => {
        expect(html).toMatch(/<button[^>]*aria-label="Oznacz jako niezrobione: Poprawka stopki"[^>]*aria-pressed="true"(?![^>]*disabled)/);
        expect(html).toMatch(/<button[^>]*aria-label="Gotowe: Karta z Trello"[^>]*disabled=""/);
    });

    test('the rest of the done tasks open with the same partial reload as a lane', () => {
        expect(html).toMatch(/href="\/clients\/9\/zadania\?wszystkie=done"[^>]*>Wszystkie zrobione<\/a><\/span><span[^>]*>11 więcej<\/span>/);
    });
});

describe('the side tiles', () => {
    test('Projekty lists every project with its open tasks and whether a board stands behind it, and opens its page', () => {
        const html = renderToStaticMarkup(
            <ProjectsTile
                clientId={9}
                projects={[
                    { id: 3, name: 'Strona WWW', open: 16, is_private: false },
                    { id: 4, name: 'Galeria', open: 9, is_private: true },
                ]}
            />,
        );

        expect(html).not.toContain('aria-current');
        expect(html).toMatch(/href="\/clients\/9\/projects\/3"[^>]*>Strona WWW<\/a><\/span><span[^>]*>16<\/span><span[^>]*>Trello<\/span>/);
        expect(html).toMatch(/href="\/clients\/9\/projects\/4"[^>]*>Galeria<\/a><\/span><span[^>]*>9<\/span><span[^>]*>prywatny<\/span>/);
        expect(html).toMatch(/<button[^>]*>Nowy projekt<\/button>/);
    });

    test('Dla agentów lists repository paths under the home folder as ~ and copies the command', () => {
        const html = renderToStaticMarkup(<AgentsTile clientId={9} repositories={tasks.repositories} />);

        expect(html).toContain('~/Sites/site');
        expect(html).toContain('title="/Users/test/Sites/site"');
        expect(html).toMatch(/<button[^>]*title="crm brief 9"[^>]*>Skopiuj polecenie dla agenta<\/button>/);
        expect(renderToStaticMarkup(<AgentsTile clientId={9} repositories={[]} />)).toContain('Brak ścieżek repozytoriów.');
    });
});

describe('live state', () => {
    const lane = (rows: ClientTaskRow[]): ClientTasks => ({
        ...tasks,
        lanes: [{ key: 'doing', count: rows.length, rows }, tasks.lanes[1], { key: 'todo', count: 0, rows: [] }],
    });

    test('an agent timer stopped elsewhere gives the row its Start back on the next refresh', () => {
        const before = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={lane([row(1, { agent_live: true })])} />);
        const after = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={lane([row(1, { agent_live: false })])} />);

        expect(before).toContain('claude pracuje');
        expect(before).not.toContain('>Start<');
        expect(after).not.toContain('claude pracuje');
        expect(after).toContain('>Start<');
    });

    test('with the owner and an agent on one task the row offers Stop for the owners timer', () => {
        const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={lane([row(1, { agent_live: true, running_id: 5 })])} />);

        expect(html).toContain('>Stop<');
        expect(html).not.toContain('>Start<');
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
});

describe('ClientTasksTab for an order', () => {
    const order: OrderTasks = {
        mode: 'order',
        expanded: null,
        open: {
            count: 12,
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
        projects: [{ id: 3, name: 'Audyt', open: 12, is_private: true }],
        repositories: [],
        reportable_by_default: false,
    };
    const html = renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={order} />);

    test('one list of open tasks, no lanes, with Start and Stop and the row that opens the rest', () => {
        expect(html).toMatch(/<h2[^>]*>Zadania<\/h2><span[^>]*>12<\/span>/);
        expect(html).not.toContain('W toku');
        expect(html).toContain('>Start<');
        expect(html).toContain('>Stop<');
        expect(html).toMatch(/wszystkie=open"[^>]*>Wszystkie otwarte<\/a><\/span><span[^>]*>10 więcej<\/span>/);
    });

    test('the done group reads its count, day and time, and links to the rest', () => {
        expect(html).toMatch(/<h2[^>]*>Zrobione<\/h2><span[^>]*>7<\/span>/);
        expect(text(html)).toContain('Pomiary i termowizja4 h29.09 · Site');
        expect(html).toContain('wszystkie=done');
    });

    test('the side tile lists the order time instead of projects and agents', () => {
        expect(text(html)).toContain('Czas · 10,0 h');
        expect(text(html)).toContain('05.10 · Raport z audytu1 h 30');
        expect(html).toContain('wszystkie=time');
        expect(html).not.toContain('Dla agentów');
        expect(html).not.toContain('Projekty');
    });
});

describe('a task row', () => {
    const t = i18next.t.bind(i18next);
    const noop = () => undefined;
    const line = (r: ClientTaskRow) =>
        renderToStaticMarkup(
            <ul>
                <TaskLine row={r} locale="pl-PL" pending={false} onTick={noop} onUntick={noop} onStart={noop} onStop={noop} />
            </ul>,
        );

    test('an idle row has its tick, the title that opens the task, Start, and Na dziś behind "more"', () => {
        const html = line(row(1));

        expect(html).toMatch(/<button type="button"[^>]*aria-label="Oznacz jako zrobione: Zadanie 1"[^>]*aria-pressed="false"/);
        expect(html).toMatch(/<a[^>]*href="\/tasks\/1"[^>]*>Zadanie 1<\/a>/);
        expect(html.match(/<button[^>]*>Start<\/button>/g)).toHaveLength(1);
        expect(html).toContain('aria-label="Więcej akcji: Zadanie 1"');
        expect(html).not.toContain('>Stop<');
    });

    test('the owners running timer gives the row Stop, never Start, and the agent mark is not the owners', () => {
        const html = line(row(1, { running_id: 5, running_since: '2026-10-05T09:18:00+00:00' }));

        expect(html).toContain('>Stop<');
        expect(html).not.toContain('>Start<');
        expect(html).not.toContain('claude');
    });

    test('the elapsed time is whole minutes since the start and never negative', () => {
        const since = '2026-10-05T09:18:00+00:00';

        expect(minutesSince(since, Date.parse('2026-10-05T10:00:59Z'))).toBe(42);
        expect(minutesSince(since, Date.parse('2026-10-05T09:17:00Z'))).toBe(0);
        expect(elapsedText({ running_id: 5, running_since: since }, Date.parse('2026-10-05T10:00:59Z'), t)).toBe('42 min');
        expect(elapsedText({ running_id: null, running_since: null }, Date.parse('2026-10-05T10:00:59Z'), t)).toBeNull();
    });

    test('an agent at work is named on every width and the row offers no Start', () => {
        const html = line(row(1, { agent_live: true }));

        expect(html).toContain('claude pracuje');
        expect(html).not.toContain('>Start<');
    });

    test('a picked task says so beside its project', () => {
        expect(text(line(row(1, { picked: true })))).toContain('Site · Wybrane');
        expect(text(line(row(1)))).not.toContain('Wybrane');
    });

    test('a ticked row can be unticked when the CRM can take it back, and offers no timer or menu', () => {
        const html = line(row(1, { done: true, can_untick: true }));

        expect(html).toMatch(/<button[^>]*aria-label="Oznacz jako niezrobione: Zadanie 1"[^>]*aria-pressed="true"/);
        expect(html).not.toContain('>Start<');
        expect(html).not.toContain('Więcej akcji');
        expect(line(row(1, { done: true, can_untick: false }))).toMatch(/aria-label="Gotowe: Zadanie 1"[^>]*disabled=""/);
    });
});

describe('the actions of Zadania', () => {
    test('from 768 px the agent command stands beside Nowe zadanie; a phone keeps Nowe zadanie as its button and the two copies behind "more"', () => {
        expect(tasksActionOrder(false, true)).toEqual(['brief', 'new']);
        expect(tasksActionOrder(true, true)).toEqual(['brief', 'copy']);
    });

    test('a client without a project offers no new task', () => {
        expect(tasksActionOrder(false, false)).toEqual(['brief']);
        expect(renderToStaticMarkup(<ClientTasksTab clientId={9} tasks={{ ...tasks, projects: [] }} creating />)).not.toContain('role="dialog"');
    });
});
