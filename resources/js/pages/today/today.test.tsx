import { router } from '@inertiajs/react';
import { describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import type { DayTask } from '@/components/solo/task-row';
import pl from '../../../../lang/pl.json';

// CSS Modules give no class names under bun test; the shared touch target gets a readable one here.
const primitives = { ...(await import('@/components/solo/primitives')) };
mock.module('@/components/solo/primitives', () => ({ ...primitives, touchTargetClass: 'touch-target' }));

const { TimerQueue } = await import('./index');
const { DayStatus, PlanRows, timeByClient, TomorrowLine } = await import('./summary');
const { RowMenu } = await import('@/components/solo/row-menu');
const { dayPills } = await import('./place');
const { FreeSlots, Suggestions } = await import('./plan');
const { ClientGroup, dayChoices, dayPillsFor, pickTask } = await import('./tasks');
const { DaySection } = await import('./week');

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
        expect(row).toContain('href="/?widok=timer&amp;timer=7"');
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
    test('each suggestion is its title and Na jutro, with no client, and the last row opens the full list with its count', () => {
        const html = renderToStaticMarkup(
            <Suggestions
                date="2026-10-24"
                suggestions={{ tasks: [pick(1, 'Testy formularza'), pick(2, 'Galeria realizacji')], leftover: 0, open: 19 }}
            />,
        );

        expect(rows(html)).toHaveLength(3);
        expect(html.match(/>Na jutro</g)).toHaveLength(2);
        expect(html).not.toContain('Fundacja Testowa');
        expect(html).toContain('terminy najpierw');
        expect(rows(html)[2]).toContain('Wszystkie zadania (19)');
        expect(rows(html)[2]).toMatch(/<a[^>]*href="\/zadania\?na=jutro"[^>]*>Otwórz<\/a>/);
    });

    test('free places point at the suggestions while there are any, else they lead to the full list', () => {
        const proposing = renderToStaticMarkup(<FreeSlots free={3} proposing />);
        expect(proposing).toContain('3 wolne miejsca');
        expect(proposing).toContain('dodaj z propozycji obok');
        expect(proposing).toContain('dodaj z propozycji niżej');
        expect(proposing).not.toContain('<a ');

        const picking = renderToStaticMarkup(<FreeSlots free={1} proposing={false} />);
        expect(picking).toContain('1 wolne miejsce');
        expect(picking).toMatch(/<a[^>]*href="\/zadania\?na=jutro"[^>]*>Wybierz<\/a>/);
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

    test('today carries the mark and is not quiet, its picks can be removed and a free place offers Dodaj', () => {
        const html = renderToStaticMarkup(
            <DaySection today="2026-10-28" maxPicks={5} entry={entry({ date: '2026-10-28', plannable: true, picks: [taken(1, 'Korekta faktury')] })} />,
        );

        expect(html).toMatch(/<h2[^>]*>Środa<\/h2><span[^>]*>28\.10<\/span><span[^>]*>dziś<\/span>/);
        expect(html).not.toContain('data-quiet');
        expect(html).toContain('1 z 5 wybrane');
        expect(html).toContain('aria-label="Usuń: Korekta faktury"');
        expect(html).toMatch(/<a[^>]*href="\/zadania"[^>]*>Dodaj<\/a>/);
    });

    test('an empty day is its heading and one quiet line, never a row: ahead it offers Wybierz for its own date, closed or past it offers nothing', () => {
        const ahead = renderToStaticMarkup(<DaySection today="2026-10-28" maxPicks={5} entry={entry({ date: '2026-10-30', plannable: true })} />);
        expect(ahead).toMatch(/<p[^>]*>Nic nie zaplanowano<\/p>/);
        expect(rows(ahead)).toHaveLength(0);
        expect(ahead).not.toContain('dziś');
        expect(ahead).toMatch(/<a[^>]*href="\/zadania\?na=2026-10-30"[^>]*>Wybierz<\/a>/);

        const closed = renderToStaticMarkup(
            <DaySection today="2026-10-28" maxPicks={5} entry={entry({ date: '2026-10-28', plannable: true, closed: true })} />,
        );
        expect(closed).toContain('Nic nie zaplanowano');
        expect(closed).not.toContain('<a ');
    });
});

describe('Wszystkie zadania', () => {
    const tasks = Array.from({ length: 6 }, (_, i) => pick(i + 1, `Zadanie ${i + 1}`, { client: null, picked: i === 4 }));
    const group = (full = false) =>
        renderToStaticMarkup(<ClientGroup client="Przychodnia Testowa" tasks={tasks} date="2026-10-23" target="today" full={full} />);

    test('a client shows three rows and every picked one, then Pokaż N więcej for the rest', () => {
        const html = group();

        expect(rows(html)).toHaveLength(4);
        expect(html).toContain('Zadanie 5');
        expect(html).toContain('Pokaż 2 więcej');
    });

    test('the head counts the client\'s tasks; a picked row is the selected one, says Wybrane and carries no pick button', () => {
        const html = group();
        const picked = rows(html).find((row) => row.includes('Zadanie 5')) ?? '';

        expect(html.replace(/<[^>]+>/g, '|')).toContain('Przychodnia Testowa||6|');
        expect(picked).toContain('aria-current="true"');
        expect(picked).toContain('Wybrane');
        expect(picked).not.toContain('<button');
        expect(html).toContain('aria-label="Na dziś: Zadanie 1"');
    });

    test('on a full day the pick buttons are disabled and the titles still lead to their tasks', () => {
        const [first] = rows(group(true));

        expect(first).toMatch(/<button[^>]*disabled=""[^>]*aria-label="Na dziś: Zadanie 1"/);
        expect(first).toContain('href="/tasks/10"');
    });

    test('the days a pick can go to are pills: today, tomorrow and the next working days, the shown one selected', () => {
        const context = { t: (key: string) => i18next.t(key), locale: 'pl' };
        const pills = dayPillsFor({ today: '2026-10-28', date: '2026-10-29', lastPlannable: '2026-11-08', showHidden: false }, context);

        expect(pills.map((pill) => [pill.label, pill.href, pill.active])).toEqual([
            ['Dziś · śr. 28.10', '/zadania', false],
            ['Jutro · czw. 29.10', '/zadania?na=jutro', true],
            ['pt. 30.10', '/zadania?na=2026-10-30', false],
            ['pon. 02.11', '/zadania?na=2026-11-02', false],
            ['wt. 03.11', '/zadania?na=2026-11-03', false],
        ]);
    });

    test('a weekend day sent by Tydzień joins the pills in its place, and hidden groups stay shown across days', () => {
        const context = { t: (key: string) => i18next.t(key), locale: 'pl' };
        const pills = dayPillsFor({ today: '2026-10-28', date: '2026-10-31', lastPlannable: '2026-11-08', showHidden: true }, context);

        expect(pills.map((pill) => pill.label)).toEqual(['Dziś · śr. 28.10', 'Jutro · czw. 29.10', 'pt. 30.10', 'sob. 31.10', 'pon. 02.11', 'wt. 03.11']);
        expect(pills.find((pill) => pill.active)?.href).toBe('/zadania?na=2026-10-31&ukryte=1');
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
            ['Dziś', '/?widok=dzis', false],
            ['Podsumowanie', '/?widok=gotowe', true],
            ['Jutro', '/jutro', false],
            ['Tydzień', '/tydzien', false],
        ]);
    });

    test('a closed day ticks Podsumowanie on every day view', () => {
        expect(dayPills('today', t, true).map((pill) => pill.done)).toEqual([undefined, 'Dzień zamknięty', undefined, undefined]);
        expect(dayPills('today', t).some((pill) => pill.done !== undefined)).toBe(false);
    });

    test('a page beside the day views marks none', () => {
        expect(dayPills(null, t).some((pill) => pill.active)).toBe(false);
    });
});
