import * as inertia from '@inertiajs/react';
import { router } from '@inertiajs/react';
import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import type { DayTask } from '@/components/solo/task-row';
import pl from '../../../../lang/pl.json';

// CSS Modules give no class names under bun test; the shared touch target gets a readable one here.
const primitives = { ...(await import('@/components/solo/primitives')) };
// A string cannot be spied on, so the module is mocked for this file and handed back after it.
mock.module('@/components/solo/primitives', () => ({ ...primitives, touchTargetClass: 'touch-target' }));
afterAll(() => {
    mock.module('@/components/solo/primitives', () => primitives);
    mock.restore();
});

const { TimerQueue } = await import('./index');
const { DayView, placesNote } = await import('./day');
const { DayStatus, PlanRows, SummaryView, timeByClient, TomorrowLine } = await import('./summary');
const { RowMenu } = await import('@/components/solo/row-menu');
const { dayPills } = await import('./place');
const { default: PlanTomorrow, planListHref, Suggestions } = await import('./plan');
const { AddOrPick, addItems, selectedIndex } = await import('./add-or-pick');
const { projectGroups } = await import('./project-picker');
const { PickedCount } = await import('./picked');
const { dayAfter, dayChip } = await import('./pick-menu');
const { movedTo } = await import('./summary');
const { default: TaskList, ClientGroup, dayChoices, dayPillsFor, listBack, listUrl, pickTask } = await import('./tasks');
const shownPrimitives = await import('@/components/solo/primitives');
const { default: Week, DaySection } = await import('./week');

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const pick = (id: number, name: string, extra: Partial<DayTask> = {}): DayTask => ({
    id,
    task_id: id * 10,
    name,
    client: 'Fundacja Testowa',
    project: 'Strona WWW',
    done: false,
    minutes: 0,
    ...extra,
});

const rows = (html: string) => [...html.matchAll(/<li[^>]*>(.*?)<\/li>/g)].map(([row]) => row);

describe('Timer queue', () => {
    const picks = [
        pick(1, 'Synchronizacja bibliotek mediów', { done: true, minutes: 48 }),
        pick(2, 'Korekta faktury 14/09'),
        pick(3, 'Techniczne błędy SEO'),
        pick(4, 'Analiza ruchu na stronie'),
    ];

    test("lists the day's picks: done with its time, the running one in progress on the tint, the next one, the rest bare", () => {
        const html = renderToStaticMarkup(<TimerQueue picks={picks} runningTaskId={20} />);
        const [done, current, next, rest] = rows(html);

        expect(html).toContain('1 z 4 zrobione');
        expect(done).toContain('0 h 48');
        expect(current).toContain('aria-current="step"');
        expect(current).toContain('w toku');
        expect(next).toContain('następne');
        expect(rest).not.toMatch(/następne|w toku|\d h \d\d/);
    });

    test('names no client or project on any row', () => {
        expect(renderToStaticMarkup(<TimerQueue picks={picks} runningTaskId={20} />)).not.toContain('Fundacja Testowa');
    });

    test('off the plan the next open pick is the first one', () => {
        const [, first] = rows(renderToStaticMarkup(<TimerQueue picks={picks} runningTaskId={999} />));

        expect(first).toContain('następne');
        expect(first).not.toContain('aria-current');
    });
});

describe('Podsumowanie', () => {
    test('a done pick shows its time, an unfinished one none, and the client stands at the end from 768 px', () => {
        const html = renderToStaticMarkup(
            <PlanRows picks={[pick(1, 'Korekta faktury', { done: true, minutes: 68 }), pick(2, 'Synchronizacja', { minutes: 30 })]} timers={[]} />,
        );
        const [done, open] = rows(html);

        expect(done).toContain('1 h 8 min');
        expect(done).toContain('href="/tasks/10"');
        expect(open).not.toMatch(/30 min/);
        expect(open).toContain('Fundacja Testowa');
        expect(html).not.toContain('Strona WWW');
    });

    test('a pick with a running timer keeps its Stop and leads to the timer', () => {
        const timer = { id: 7, started_at: '2026-10-28T09:00:00Z', task_id: 10, task: 'Korekta faktury', client: null, project: null };
        const [row] = rows(renderToStaticMarkup(<PlanRows picks={[pick(1, 'Korekta faktury', { running_id: 7 })]} timers={[timer]} />));

        expect(row).toContain('>Stop</button>');
        expect(row).toContain('href="/?view=timer&amp;timer=7"');
    });

    test('the state beside the close button is a chip, or the reason a running timer blocks the close', () => {
        expect(renderToStaticMarkup(<DayStatus closed running={false} />)).toContain('data-status="done"');
        expect(renderToStaticMarkup(<DayStatus closed={false} running={false} />)).toContain('Dzień jest jeszcze otwarty.');
        expect(renderToStaticMarkup(<DayStatus closed={false} running />)).toContain('Timer wciąż działa. Zatrzymaj go, żeby zamknąć dzień.');
    });

    test('time per client adds the rows up, most first, and is left out when they do not cover the day', () => {
        const listed = [
            { client: 'Pracownia Mebli', minutes: 65 },
            { client: 'Fundacja Testowa', minutes: 130 },
            { client: 'Pracownia Mebli', minutes: 25 },
            { client: 'Przychodnia Testowa', minutes: 0 },
        ];

        expect(timeByClient(listed, 220)).toEqual([
            { client: 'Fundacja Testowa', minutes: 130 },
            { client: 'Pracownia Mebli', minutes: 90 },
        ]);
        expect(timeByClient(listed, 250)).toBeNull();
        expect(timeByClient([], 0)).toBeNull();
    });

    test('on phones the title fills the row and the time ends it; from 768 px the time follows the title', async () => {
        const css = await Bun.file(new URL('../../components/solo/task-row.module.css', import.meta.url)).text();
        const wide = css.slice(css.indexOf('/* Done row Size=Desktop'));

        expect(css).toMatch(/\.doneRow \.text,\n\.queueRow \.text \{\n {4}flex: 1 1 auto;/);
        expect(wide).toMatch(/\.doneRow \.text \{\n {8}flex: 0 1 auto;/);
        expect(wide).toMatch(/\.doneRow \.trailing \{\n {8}margin-left: 0;/);
    });
});

describe('JUTRO line', () => {
    test("tomorrow's plan comes first, then what is overdue, then that nothing is planned", () => {
        expect(renderToStaticMarkup(<TomorrowLine tomorrowPicks={2} overdue={{ count: 7, oldest: '2026-06-15' }} />)).toContain(
            'Na jutro zaplanowane: 2.',
        );
        expect(renderToStaticMarkup(<TomorrowLine tomorrowPicks={0} overdue={{ count: 7, oldest: '2026-06-15' }} />)).toContain(
            'Zostało 7 zaległych, najstarsze od 15 czerwca.',
        );
        expect(renderToStaticMarkup(<TomorrowLine tomorrowPicks={0} overdue={{ count: 0, oldest: null }} />)).toContain(
            'Na jutro nic jeszcze nie ma.',
        );
    });
});

describe('Jutro', () => {
    test('each suggestion is one line at rest: its title to its task, the chevron and a quiet For tomorrow; the last row opens the full list', () => {
        const html = renderToStaticMarkup(
            <Suggestions
                date="2026-10-24"
                list={planListHref('2026-10-24')}
                suggestions={{
                    tasks: [pick(1, 'Testy formularza', { from_today: true, logged_minutes: 80 }), pick(2, 'Galeria realizacji', { due: '2026-10-30' })],
                    leftover: 1,
                    open: 19,
                }}
            />,
        );
        const [leftover, due, all] = rows(html);
        const head = (row: string) => row.slice(0, row.indexOf('<dl'));

        expect(rows(html)).toHaveLength(3);
        expect(html).toContain('z dzisiaj, potem terminy');
        expect(html).not.toContain('<aside');
        expect(html.match(/>Na jutro</g)).toHaveLength(2);
        expect(leftover).toMatch(/<a[^>]*href="\/tasks\/10"[^>]*><span[^>]*>Testy formularza<\/span><\/a>/);
        expect(head(leftover)).not.toContain('Fundacja Testowa');
        expect(leftover).toMatch(/<button[^>]*aria-expanded="false"[^>]*aria-label="Pokaż szczegóły: Testy formularza"/);
        expect(leftover).toMatch(
            /<dt>Klient<\/dt><dd title="Fundacja Testowa">Fundacja Testowa<\/dd>.*<dt>Projekt<\/dt><dd title="Strona WWW">Strona WWW<\/dd>.*<dt>Czas<\/dt><dd title="1 h 20 min">1 h 20 min<\/dd>/,
        );
        expect(leftover).not.toContain('<dt>Termin</dt>');
        expect(due).toMatch(/<dt>Termin<\/dt><dd title="30\.10">30\.10<\/dd>/);
        for (const row of [leftover, due]) {
            expect(row).toContain('<dl style="--detail-columns:var(--role-detail-text) var(--role-detail-text) var(--role-detail-short) var(--role-detail-short)">');
        }
        expect(leftover).toContain('<div style="--detail-column:4"><dt>Czas</dt>');
        expect(due).toContain('<div style="--detail-column:3"><dt>Termin</dt>');
        expect(due).not.toContain('<dt>Czas</dt>');
        expect(html).not.toContain('Powód');
        expect(all).toContain('Wszystkie zadania (19)');
        expect(all).not.toContain('aria-expanded');
        expect(all).not.toContain('arrow-up-right');
        expect(all).toMatch(/<a[^>]*href="\/all-tasks\?for=tomorrow"[^>]*>Otwórz<\/a>/);
    });

    test('a list where no row has a due day has no Termin column: the other columns close up, the same on every row', () => {
        const html = renderToStaticMarkup(
            <Suggestions
                date="2026-10-24"
                list={planListHref('2026-10-24')}
                suggestions={{ tasks: [pick(1, 'Testy formularza', { logged_minutes: 80 }), pick(2, 'Galeria realizacji')], leftover: 0, open: 2 }}
            />,
        );
        const [first, second] = rows(html);

        expect(html).not.toContain('<dt>Termin</dt>');
        for (const row of [first, second]) {
            expect(row).toContain('<dl style="--detail-columns:var(--role-detail-text) var(--role-detail-text) var(--role-detail-short)">');
        }
        expect(first).toContain('<div style="--detail-column:3"><dt>Czas</dt>');
    });

    test('a suggestion whose task a timer runs on shows the dot and the live time at rest, read from the running timers', () => {
        const startedAt = new Date(Date.now() - 42 * 60_000).toISOString();
        const html = renderToStaticMarkup(
            <Suggestions
                date="2026-10-24"
                list={planListHref('2026-10-24')}
                timers={[{ id: 9, started_at: startedAt, task: 'Konfiguracja', client: null }]}
                suggestions={{ tasks: [pick(1, 'Konfiguracja', { running_id: 9 }), pick(2, 'Hosting')], leftover: 0, open: 2 }}
            />,
        );
        const [running, idle] = rows(html);

        expect(running).toMatch(/data-tone="over" data-running=""><span[^>]*aria-hidden="true"><\/span><span aria-label="0 h \d+ min[^"]*">0:\d\d<\/span>/);
        expect(idle).not.toContain('data-running');
    });

    test('a move names its day: the chip of a weekday, tomorrow in words, a later day by its date', () => {
        expect(dayChip('2026-11-18', 'pl')).toBe('Śr 18');
        expect([dayAfter('2026-11-30'), dayAfter('2026-12-31')]).toEqual(['2026-12-01', '2027-01-01']);
        expect(movedTo('2026-11-18', '2026-11-17', 'pl', i18next.t)).toBe('na jutro');
        expect(movedTo('2026-11-19', '2026-11-17', 'pl', i18next.t)).toBe('na czw. 19.11');
    });

    test('a day past five picks says how many and, unless switched off, that it is more than five', () => {
        const count = (picked: number, warn: boolean) =>
            renderToStaticMarkup(<PickedCount count={picked} max={5} warn={warn} />).replace(/<[^>]+>/g, '');

        expect(count(5, true)).toBe('5 z 5 wybrane');
        expect(count(6, true)).toBe('6 wybranych, więcej niż 5');
        expect(count(6, false)).toBe('6 wybranych');
    });

    test('the add field rests as one quiet Add a task row, with no field until it is opened', () => {
        const html = renderToStaticMarkup(
            <ul>
                <AddOrPick date="2026-10-24" picked={2} maxPicks={5} warnFull listHref={planListHref('2026-10-24')} />
            </ul>,
        );

        expect(rows(html)).toHaveLength(1);
        expect(html).toMatch(/<button[^>]*type="button"[^>]*>.*<span>Dodaj zadanie<\/span><\/button>/);
        expect(html).not.toContain('<input');
        expect(html).not.toContain('role="combobox"');

        const noted = renderToStaticMarkup(
            <ul>
                <AddOrPick date="2026-10-24" picked={2} maxPicks={5} warnFull note="3 wolne miejsca" />
            </ul>,
        );
        expect(noted).toMatch(/<span>Dodaj zadanie<\/span><span[^>]*>3 wolne miejsca<\/span><\/button>/);
    });

    test('typed text selects nothing until its matches are in, so Enter never makes a task that may already exist', () => {
        const match = { task_id: 7, name: 'Cennik PDF', client: 'Pracownia Mebli', project: 'Strona WWW' };
        const ready = addItems('cennik', { status: 'ready', options: { query: 'cennik', recent: false, tasks: [match], total: 1 } }, []);
        const loading = addItems('cennik', { status: 'loading', options: null }, []);
        const failed = addItems('cennik', { status: 'error', options: null }, []);
        const none = addItems('zzz', { status: 'ready', options: { query: 'zzz', recent: false, tasks: [], total: 0 } }, []);

        expect(ready.items.map((item) => item.kind)).toEqual(['task', 'new']);
        expect(selectedIndex(ready.items.length, null, ready.preselect)).toBe(0);
        expect(loading.items.map((item) => item.kind)).toEqual(['new']);
        expect(selectedIndex(loading.items.length, null, loading.preselect)).toBeNull();
        expect(selectedIndex(failed.items.length, null, failed.preselect)).toBeNull();
        expect(selectedIndex(failed.items.length, 0, failed.preselect)).toBe(0);
        expect(selectedIndex(none.items.length, null, none.preselect)).toBe(0);
        expect(none.items[0].kind).toBe('new');
        expect(
            addItems('', { status: 'ready', options: { query: '', recent: true, tasks: [match], total: null } }, [3]).items.map((item) => item.kind),
        ).toEqual(['task', 'project']);
        expect(selectedIndex(0, null, true)).toBeNull();
        expect(selectedIndex(2, 5, true)).toBe(1);
    });

    test('the add row says the free places, "day full" at the limit and, past it, how many over the plan', () => {
        expect(placesNote(2, 5, true, i18next.t)).toBe('3 wolne miejsca');
        expect(placesNote(5, 5, true, i18next.t)).toBe('dzień pełny');
        expect(placesNote(7, 5, true, i18next.t)).toBe('2 ponad plan');
        expect(placesNote(7, 5, false, i18next.t)).toBe('dzień pełny');
    });

    test('a project opened from the add field lists the recent ones first, then each client over its projects; typed words narrow both', () => {
        const choices = {
            recent: [3],
            projects: [
                { id: 1, name: 'Strona WWW', client_id: 7, client: 'Pracownia Mebli' },
                { id: 2, name: 'Ogólne', client_id: 7, client: 'Pracownia Mebli' },
                { id: 3, name: 'Sklep online', client_id: 8, client: 'Kawiarnia Przykładowa' },
            ],
        };
        const labels = { recent: 'Ostatnie projekty', noClient: 'Bez klienta' };

        expect(projectGroups(choices, '', labels).map((group) => [group.head, group.projects.map((project) => project.id)])).toEqual([
            ['Ostatnie projekty', [3]],
            ['Pracownia Mebli', [1, 2]],
            ['Kawiarnia Przykładowa', [3]],
        ]);
        expect(projectGroups(choices, 'mebli ogolne', labels).map((group) => [group.head, group.projects.map((project) => project.id)])).toEqual([
            ['Pracownia Mebli', [2]],
        ]);
        expect(projectGroups(choices, 'nic takiego', labels)).toEqual([]);
    });

    test("another day's plan opens the full list for its own date, and Done there returns to that day", () => {
        const nav = { date: '2026-10-30', prev: '2026-10-29', next: '2026-10-31', today: '2026-10-23' };
        const list = planListHref('2026-10-30', nav);
        expect(list).toBe('/all-tasks?for=2026-10-30&from=day');

        const suggestions = renderToStaticMarkup(
            <Suggestions date="2026-10-30" list={list} tomorrow={false} suggestions={{ tasks: [pick(1, 'Testy')], leftover: 0, open: 4 }} />,
        );
        expect(rows(suggestions)[1]).toMatch(/<a[^>]*href="\/all-tasks\?for=2026-10-30&amp;from=day"[^>]*>Otwórz<\/a>/);

        expect(listBack('date', '2026-10-30', 'day')).toBe('/day/2026-10-30');
        expect(listBack('tomorrow', '2026-10-24', 'day')).toBe('/day/2026-10-24');
        expect([listBack('date', '2026-10-30', null), listBack('tomorrow', '2026-10-24', null), listBack('today', '2026-10-23', null)]).toEqual([
            '/week',
            '/tomorrow',
            '/',
        ]);
    });
});

describe('Tydzień, one date', () => {
    const entry = (over: object) => ({ date: '2026-10-26', plannable: false, closed: false, picks: [], ...over });
    const taken = (id: number, name: string, extra: Partial<DayTask> = {}) => ({ ...pick(id, name, extra), id });

    test('a past day reads quieter and counts what was done; an unfinished pick can go to today or be dropped behind its more button', () => {
        const html = renderToStaticMarkup(
            <DaySection
                today="2026-10-28"
                maxPicks={5}
                warnFull
                entry={entry({ picks: [taken(1, 'Formularz wyceny', { done: true, minutes: 150 }), taken(2, 'Dopracowanie podstron')] })}
            />,
        );
        const [done, late] = rows(html);

        expect(html).toMatch(/data-quiet=""[^>]*>.*<h2[^>]*>Poniedziałek<\/h2><span[^>]*>26\.10<\/span>/);
        expect(html).toContain('1 z 2 zrobione');
        expect(done).toContain('2 h 30 min');
        expect(done).not.toContain('<button type="button" class');
        expect(late).toContain('nie zrobione');
        expect(late).toContain('>Na dziś</button>');
        expect(late).toContain('aria-label="Więcej akcji: Dopracowanie podstron"');
    });

    test('today carries the mark and is not quiet, its picks can be removed and a free place offers Write in a task and Pick', () => {
        const html = renderToStaticMarkup(
            <DaySection
                today="2026-10-28"
                maxPicks={5}
                warnFull
                entry={entry({ date: '2026-10-28', plannable: true, picks: [taken(1, 'Korekta faktury')] })}
            />,
        );

        expect(html).toMatch(/<h2[^>]*>Środa<\/h2><span[^>]*>28\.10<\/span><span[^>]*>dziś<\/span>/);
        expect(html).not.toContain('data-quiet');
        expect(html).toContain('1 z 5 wybrane');
        expect(html).toContain('aria-label="Usuń: Korekta faktury"');
        expect(html).toMatch(/<button[^>]*>Dopisz zadanie<\/button>/);
        expect(html).toMatch(/<a[^>]*href="\/all-tasks"[^>]*>Wybierz<\/a>/);
    });

    test('an empty day ahead says so in one quiet row with Write in a task and Pick for its own date; closed or past it is one line offering nothing', () => {
        const ahead = renderToStaticMarkup(
            <DaySection today="2026-10-28" maxPicks={5} warnFull entry={entry({ date: '2026-10-30', plannable: true })} />,
        );
        expect(rows(ahead)).toHaveLength(1);
        expect(rows(ahead)[0]).toContain('Nic nie zaplanowano');
        expect(ahead).toContain('0 z 5 wybrane');
        expect(ahead).not.toContain('dziś');
        expect(ahead).toMatch(/<button[^>]*>Dopisz zadanie<\/button>/);
        expect(ahead).toMatch(/<a[^>]*href="\/all-tasks\?for=2026-10-30"[^>]*>Wybierz<\/a>/);

        const closed = renderToStaticMarkup(
            <DaySection today="2026-10-28" maxPicks={5} warnFull entry={entry({ date: '2026-10-28', plannable: true, closed: true })} />,
        );
        expect(closed).toContain('Nic nie zaplanowano');
        expect(closed).not.toContain('<a ');
    });
});

describe('Wszystkie zadania', () => {
    const tasks = Array.from({ length: 6 }, (_, i) => pick(i + 1, `Zadanie ${i + 1}`, { client: null, picked: i === 4 }));
    const group = () => renderToStaticMarkup(<ClientGroup client="Przychodnia Testowa" tasks={tasks} date="2026-10-23" target="today" />);

    test('a client shows three rows and every picked one, then Show N more for the rest', () => {
        const html = group();

        expect(rows(html)).toHaveLength(4);
        expect(html).toContain('Zadanie 5');
        expect(html).toContain('Pokaż 2 więcej');
    });

    test("the head counts the client's tasks; a picked row is the selected one, says Picked and carries no pick button", () => {
        const html = group();
        const picked = rows(html).find((row) => row.includes('Zadanie 5')) ?? '';

        expect(html.replace(/<[^>]+>/g, '|')).toContain('Przychodnia Testowa||6|');
        expect(picked).toContain('aria-current="true"');
        expect(picked).toContain('Wybrane');
        expect(picked).not.toContain('<button');
        expect(html).toContain('aria-label="Na dziś: Zadanie 1"');
    });

    test('the pick buttons stay open on a full day and the titles lead to their tasks', () => {
        const [first] = rows(group());

        expect(first).toMatch(/<button[^>]*aria-label="Na dziś: Zadanie 1"/);
        expect(first).not.toContain('disabled');
        expect(first).toContain('href="/tasks/10"');
    });

    test('the days a pick can go to are pills: today, tomorrow and the next working days, the shown one selected', () => {
        const context = { t: (key: string) => i18next.t(key), locale: 'pl' };
        const pills = dayPillsFor({ today: '2026-10-28', date: '2026-10-29', lastPlannable: '2026-11-08', showHidden: false }, context);

        expect(pills.map((pill) => [pill.label, pill.href, pill.active])).toEqual([
            ['Dziś · śr. 28.10', '/all-tasks', false],
            ['Jutro · czw. 29.10', '/all-tasks?for=tomorrow', true],
            ['pt. 30.10', '/all-tasks?for=2026-10-30', false],
            ['pon. 02.11', '/all-tasks?for=2026-11-02', false],
            ['wt. 03.11', '/all-tasks?for=2026-11-03', false],
        ]);
    });

    test('a weekend day sent by Week joins the pills in its place, and hidden groups stay shown across days', () => {
        const context = { t: (key: string) => i18next.t(key), locale: 'pl' };
        const pills = dayPillsFor({ today: '2026-10-28', date: '2026-10-31', lastPlannable: '2026-11-08', showHidden: true }, context);

        expect(pills.map((pill) => pill.label)).toEqual([
            'Dziś · śr. 28.10',
            'Jutro · czw. 29.10',
            'pt. 30.10',
            'sob. 31.10',
            'pon. 02.11',
            'wt. 03.11',
        ]);
        expect(pills.find((pill) => pill.active)?.href).toBe('/all-tasks?for=2026-10-31&hidden=1');
    });

    test("a list opened from a day's page keeps its way back through the hidden toggle and the day pills", () => {
        const context = { t: (key: string) => i18next.t(key), locale: 'pl' };
        const pills = dayPillsFor({ today: '2026-10-28', date: '2026-10-30', lastPlannable: '2026-11-08', showHidden: false, back: 'day' }, context);

        expect(listUrl('2026-10-30', '2026-10-28', true, 'day')).toBe('/all-tasks?for=2026-10-30&hidden=1&from=day');
        expect(listUrl('2026-10-30', '2026-10-28', false, 'day')).toBe('/all-tasks?for=2026-10-30&from=day');
        expect(pills.find((pill) => pill.active)?.href).toBe('/all-tasks?for=2026-10-30&from=day');
        expect(pills.every((pill) => String(pill.href).endsWith('from=day'))).toBe(true);
        expect(listUrl('2026-10-30', '2026-10-28', true)).toBe('/all-tasks?for=2026-10-30&hidden=1');
    });

    test("Show hidden on a list opened from a day's page reloads it with its way back", () => {
        const RealButton = shownPrimitives.SoloButton;
        const clicks: Array<() => void> = [];
        const spies = [
            spyOn(shownPrimitives, 'SoloButton').mockImplementation((props) => {
                if (props.onClick) clicks.push(() => props.onClick?.({} as never));

                return <RealButton {...props} />;
            }),
            spyOn(inertia, 'Head').mockImplementation((() => null) as unknown as typeof inertia.Head),
            spyOn(inertia, 'usePage').mockImplementation((() => ({
                props: {
                    errors: {},
                    ...{ today: '2026-10-28', date: '2026-10-30', target: 'date', back: 'day', lastPlannable: '2026-11-08', closed: false },
                    ...{ maxPicks: 5, warnFull: false, pickedCount: 0, groups: [], hideable: [], hidden: { groups: 1, tasks: 2 }, showHidden: false },
                },
            })) as typeof inertia.usePage),
        ];
        const get = spyOn(router, 'get').mockImplementation(() => {});

        try {
            expect(renderToStaticMarkup(<TaskList />)).toContain('Pokaż ukryte');
            expect(clicks).toHaveLength(1);
            clicks[0]();
            expect(get.mock.calls.map(([url]) => url)).toEqual(['/all-tasks?for=2026-10-30&hidden=1&from=day']);
        } finally {
            [...spies, get].forEach((spy) => spy.mockRestore());
        }
    });
});

describe('Pick actions', () => {
    test("a pick's secondary actions sit behind its more button, named after the task", () => {
        const html = renderToStaticMarkup(
            <RowMenu name="Korekta faktury 14/09">
                <span />
            </RowMenu>,
        );

        expect(html).toMatch(/<button[^>]*aria-label="Więcej akcji: Korekta faktury 14\/09"/);
        expect(html).toContain('<svg');
        expect(html).not.toContain('Usuń z dzisiaj');
    });

    test('the main Start dims on hover like the other filled buttons, and a disabled one stays dimmed', async () => {
        const css = await Bun.file(new URL('../../components/solo/primitives.module.css', import.meta.url)).text();
        const hover = css.match(/([^}]*):hover:not\(:disabled, \[aria-disabled='true'\]\)\s*\{([^}]*)\}/);

        expect(hover).not.toBeNull();
        expect(hover![1]).toContain('.primary');
        expect(hover![2]).toContain('opacity');
    });
});

describe('the row day menu', () => {
    test('offers today, tomorrow and the next working days, never past the last plannable day', () => {
        expect(dayChoices('2026-10-28', '2026-11-08')).toEqual(['2026-10-28', '2026-10-29', '2026-10-30', '2026-11-02', '2026-11-03']);
        expect(dayChoices('2026-10-30', '2026-11-01')).toEqual(['2026-10-30', '2026-10-31']);
    });
});

describe('pickTask', () => {
    test('keeps the page state so an expanded group stays open', () => {
        const post = spyOn(router, 'post').mockImplementation(() => {});
        pickTask(7, '2026-10-30');
        expect(post.mock.calls[0][1]).toEqual({ task_id: 7, date: '2026-10-30' });
        expect(post.mock.calls[0][2]).toMatchObject({ preserveState: true, preserveScroll: true });
        post.mockRestore();
    });
});

describe('the day pills', () => {
    const t = (key: string) => i18next.t(key);

    test('the four day views are links and exactly one is the current place', () => {
        expect(dayPills('done', t).map(({ label, href, active }) => [label, typeof href === 'string' ? href : href.url, active])).toEqual([
            ['Dziś', '/?view=today', false],
            ['Podsumowanie', '/?view=summary', true],
            ['Jutro', '/tomorrow', false],
            ['Tydzień', '/week', false],
        ]);
    });

    test('a closed day ticks Summary on every day view', () => {
        expect(dayPills('today', t, true).map((pill) => pill.done)).toEqual([undefined, 'Dzień zamknięty', undefined, undefined]);
        expect(dayPills('today', t).some((pill) => pill.done !== undefined)).toBe(false);
    });

    test('a page beside the day views marks none', () => {
        expect(dayPills(null, t).some((pill) => pill.active)).toBe(false);
    });
});

describe("the day screens' head, rows and panel", () => {
    const month = {
        month: '2026-10',
        closed: 18,
        weekdays: 22,
        days: Array.from({ length: 31 }, (_, i) => ({
            date: `2026-10-${String(i + 1).padStart(2, '0')}`,
            weekday: ((i + 3) % 7) + 1,
            state: 'open' as const,
        })),
    };
    const day = {
        state: 'paper' as const,
        closed: false,
        date: '2026-10-28',
        dayStart: '2026-10-27T23:00:00Z',
        maxPicks: 5,
        warnFull: true,
        moveDays: [],
        moved: [],
        picks: [pick(1, 'Formularz wyceny mebli na wymiar')],
        month,
        totals: { logged_minutes: 0, clients: 0 },
        tomorrowPicks: 2,
        overdue: null,
        running: null,
        timers: [],
        looseTimers: [],
        offPlan: [pick(2, 'Zebrać zdjęcia z palarni do nowej galerii', { minutes: 80 })],
        carried: [{ ...pick(3, 'Walidacja pól formularza wyceny i komunikaty błędów dla klienta'), id: 3, from: '2026-10-26' }],
    };
    const plan = { date: '2026-10-29', plannable: true, closed: false, maxPicks: 5, warnFull: true, picks: [pick(5, 'Aktualizacja cennika PDF')] };
    const withPage = (props: object, render: () => string): string => {
        const spies = [
            spyOn(inertia, 'Head').mockImplementation((() => null) as unknown as typeof inertia.Head),
            spyOn(inertia, 'usePage').mockImplementation((() => ({ url: '/', props: { errors: {}, ...props } })) as typeof inertia.usePage),
        ];

        try {
            return render();
        } finally {
            spies.forEach((spy) => spy.mockRestore());
        }
    };
    const headOf = (html: string) => html.slice(html.indexOf('<header'), html.indexOf('</header>'));

    test('every day row keeps its title on one line: the earlier days, off the plan and the suggestions wrap nothing', () => {
        const today = withPage(day, () => renderToStaticMarkup(<DayView {...day} />));
        const suggested = renderToStaticMarkup(
            <Suggestions date="2026-10-29" list="/all-tasks?for=tomorrow" suggestions={{ tasks: [pick(4, 'Makiety: strona główna')], leftover: 0, open: 3 }} />,
        );

        expect(today).toContain('Zebrać zdjęcia z palarni do nowej galerii');
        expect(today).not.toContain('data-wrap="title"');
        expect(suggested).toContain('Makiety: strona główna');
        expect(suggested).not.toContain('data-wrap="title"');
    });

    test('a task from an earlier day says which day in its details, between its project and its time, and nothing at rest', () => {
        const carried = [{ ...pick(3, 'Walidacja pól', { logged_minutes: 80 }), id: 3, from: '2026-10-26' }];
        const html = withPage(day, () => renderToStaticMarkup(<DayView {...day} carried={carried} />));
        const row = html.slice(html.indexOf('>Walidacja pól<'));

        expect(row.slice(0, row.indexOf('<dl'))).not.toContain('26.10');
        expect(row).toMatch(/<dt>Projekt<\/dt>.*?<dt>Z dnia<\/dt><dd title="pon\. 26\.10">pon\. 26\.10<\/dd>.*?<dt>Czas<\/dt>/);
    });

    test('Today, Tomorrow, a day view and Summary print no date under the heading; the month tile carries it', () => {
        const today = withPage(day, () => renderToStaticMarkup(<DayView {...day} />));
        const planned = { ...plan, suggestions: { tasks: [], leftover: 0, open: 3 }, month, timers: [] };
        const tomorrow = withPage(planned, () => renderToStaticMarkup(<PlanTomorrow />));
        const dayView = withPage({ ...planned, day: { date: '2026-10-29', today: '2026-10-28', next: '2026-10-30' } }, () => renderToStaticMarkup(<PlanTomorrow />));
        const summary = withPage(day, () => renderToStaticMarkup(<SummaryView {...day} state="done" />));

        expect(headOf(today)).not.toContain('<p');
        expect(today).toContain('Październik · 28.10.2026');
        for (const html of [tomorrow, dayView]) {
            expect(headOf(html)).toMatch(/<header[^>]*><h1[^>]*>Czwartek<\/h1>$/);
            expect(html).not.toContain('29 października 2026');
            expect(html).not.toContain('z 5 wybrane');
            expect(html).toContain('Październik · 29.10.2026');
            expect(html).toContain('4 wolne miejsca');
        }
        expect(headOf(summary)).toMatch(/<header[^>]*><h1[^>]*>Gotowe\?<\/h1>$/);
        expect(summary).not.toContain('28 października 2026');
        expect(summary).toContain('Październik · 28.10.2026');
    });

    test("Week's heading has its pills and no range line; the tile names the week and each weekday keeps its count", () => {
        const days = ['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01'].map((date) => ({
            date,
            plannable: date >= '2026-10-28',
            closed: false,
            picks: [],
        }));
        const html = withPage({ today: '2026-10-28', closed: false, week: 'this', maxPicks: 5, warnFull: true, month, days, timer: null }, () =>
            renderToStaticMarkup(<Week />),
        );

        expect(headOf(html)).not.toMatch(/<p[^>]*>/);
        expect(headOf(html)).toContain('Ten tydzień');
        expect(html).toContain('Październik · 26.10 – 01.11.2026');
        expect(html).toContain('0 z 5 wybrane');
    });

    test('All tasks keeps the hidden-tasks note and no longer counts the picks', () => {
        const props = { today: '2026-10-28', date: '2026-10-29', target: 'tomorrow', back: null, lastPlannable: '2026-11-08', closed: false };
        const html = withPage(
            { ...props, maxPicks: 5, warnFull: true, pickedCount: 2, groups: [], hideable: [], hidden: { groups: 1, tasks: 4 }, showHidden: false },
            () => renderToStaticMarkup(<TaskList />),
        );

        expect(html).toContain('Ukryte: 4 zadania');
        expect(html).not.toContain('z 5 wybrane');
    });

    test('the closing buttons stand apart from the last row and name where the plan button leads, before and after tomorrow has picks', async () => {
        const css = await Bun.file(new URL('../../components/solo/tile.module.css', import.meta.url)).text();
        const today = withPage(day, () => renderToStaticMarkup(<DayView {...day} />));
        const empty = withPage(day, () => renderToStaticMarkup(<DayView {...day} tomorrowPicks={0} />));
        const summary = withPage(day, () => renderToStaticMarkup(<SummaryView {...day} state="done" />));

        expect(css).toMatch(/\.list > \.actions\[data-apart\] \{\s*padding-top: var\(--role-space-24\);/);
        for (const html of [today, empty, summary]) {
            expect(html).toContain('data-apart=""');
            expect(html).toContain('>Plan na jutro<');
            expect(html).not.toMatch(/Zmień plan|Zaplanuj jutro/);
        }
    });

    test('the rules of a day panel run as far in as the rows, and every line of a heading or a note is a whole pixel', async () => {
        const add = await Bun.file(new URL('./add-or-pick.module.css', import.meta.url)).text();
        const tile = await Bun.file(new URL('../../components/solo/tile.module.css', import.meta.url)).text();
        const block = (selector: string) => tile.slice(tile.indexOf(`\n${selector} {`)).split('}')[0];

        expect(add).not.toMatch(/\.resting \{[^}]*border-bottom/);
        expect(add).toMatch(/\.resting::after \{[^}]*inset: auto var\(--role-space-12\) 0;[^}]*height: var\(--role-stroke-rule\);/);
        for (const selector of ['.label', '.count,\n.headerEnd', '.groupLabel', '.groupNote,\n.groupEnd', '.note']) {
            expect(block(selector)).toMatch(/font: 400 [^;]+ \/ \d+px /);
        }
        expect(tile).toMatch(/\n\.groupCurrent \{\s*font: 400 [^;]+ \/ \d+px /);
        expect(block('.group')).toContain('line-height: 20px;');
        expect(block('.header')).toContain('line-height: 20px;');
    });
});
