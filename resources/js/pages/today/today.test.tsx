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

const { DoneList, PickActions, TimerQueue, TomorrowLine } = await import('./index');
const { Suggestions } = await import('./plan');
const { ClientGroup, dayChoices, pickTask } = await import('./tasks');

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

describe('Done rows on Podsumowanie', () => {
    test('a row is the title and its time only, and an unfinished pick shows no time', () => {
        const html = renderToStaticMarkup(
            <DoneList picks={[pick(1, 'Korekta faktury', { done: true, minutes: 68 }), pick(2, 'Synchronizacja', { minutes: 30 })]} timers={[]} />,
        );
        const [done, open] = rows(html);

        expect(done).toContain('1 h 08');
        expect(open).not.toContain('0 h 30');
        expect(html).not.toContain('Fundacja Testowa');
        expect(html).not.toContain('Strona WWW');
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

describe('Propozycje', () => {
    test('each suggestion is its title and Na jutro, with no client, and the full list link counts every open task', () => {
        const html = renderToStaticMarkup(
            <Suggestions
                date="2026-10-24"
                suggestions={{ tasks: [pick(1, 'Testy formularza'), pick(2, 'Galeria realizacji')], leftover: 0, open: 19 }}
            />,
        );

        expect(rows(html)).toHaveLength(2);
        expect(html.match(/>Na jutro</g)).toHaveLength(2);
        expect(html).not.toContain('Fundacja Testowa');
        expect(html).toContain('terminy najpierw');
        expect(html).toMatch(/<a[^>]*href="\/zadania\?na=jutro"[^>]*>Wszystkie zadania \(19\) →<\/a>/);
    });

    test('on phones Na jutro and the full list link reach 44 px through the shared touch target', () => {
        const html = renderToStaticMarkup(
            <Suggestions date="2026-10-24" suggestions={{ tasks: [pick(1, 'Testy formularza')], leftover: 0, open: 19 }} />,
        );

        expect(html).toMatch(/<button[^>]*class="[^"]*touch-target[^"]*"[^>]*>Na jutro<\/button>/);
        expect(html).toMatch(/<a[^>]*class="[^"]*touch-target[^"]*"[^>]*href="\/zadania\?na=jutro"/);
    });
});

describe('Wszystkie zadania group', () => {
    const tasks = Array.from({ length: 6 }, (_, i) => pick(i + 1, `Zadanie ${i + 1}`, { client: null, picked: i === 4 }));

    test('shows three rows and every picked one, then Pokaż N więcej for the rest', () => {
        const html = renderToStaticMarkup(<ClientGroup client="Przychodnia Testowa" tasks={tasks} date="2026-10-23" target="today" full={false} />);

        expect(rows(html)).toHaveLength(4);
        expect(html).toContain('Zadanie 5');
        expect(html).toContain('Pokaż 2 więcej');
    });

    test('the count follows the name on phones and ends the head from 768 px; a picked row carries no pick button', () => {
        const html = renderToStaticMarkup(<ClientGroup client="Przychodnia Testowa" tasks={tasks} date="2026-10-23" target="today" full={false} />);
        const picked = rows(html).find((row) => row.includes('Zadanie 5')) ?? '';

        expect(html).toMatch(/Przychodnia Testowa<span[^>]*> · 6<\/span>/);
        expect(html).toMatch(/<span[^>]*>6<\/span><\/span><\/div>/);
        expect(picked).toContain('Wybrane');
        expect(picked).not.toContain('<button');
        expect(html).toContain('aria-label="Na dziś: Zadanie 1"');
    });

    test('on a full day the disabled pick cover lets taps through to the title link', async () => {
        const css = await Bun.file(new URL('./today.module.css', import.meta.url)).text();

        expect(css).toMatch(/\n\.pickAction:disabled \{\s*pointer-events: none;\s*\}/);
    });
});

describe('Pick actions', () => {
    const noop = () => {};

    test('remove is the close icon at the icon size, named and titled Usuń z dzisiaj', () => {
        const html = renderToStaticMarkup(<PickActions main pending={false} onStart={noop} onRemove={noop} />);
        const remove = html.match(/<button[^>]*aria-label="Usuń z dzisiaj"[^>]*>(.*?)<\/button>/);

        expect(remove).not.toBeNull();
        expect(remove![0]).toContain('title="Usuń z dzisiaj"');
        expect(remove![1]).toContain('<svg');
        expect(remove![1]).not.toContain('×');
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
