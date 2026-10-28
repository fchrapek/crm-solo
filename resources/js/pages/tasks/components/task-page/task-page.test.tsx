import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { ActivitySection } from './activity-section';
import { BriefSection } from './brief-section';
import { DescriptionSection } from './description-section';
import { isLongDescription } from './format';
import { linkText, ResourcesSection } from './resources-section';
import { emptyBrief, runningTime, task, time, trelloTask } from './story-fixtures';
import { copyAgentPrompt, TaskHeader, TaskMeta } from './task-header';
import { TimeStats } from './time-section';
import type { ActivityRow, TaskActivityPage } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

function row(key: string, at: string): ActivityRow {
    return {
        key,
        kind: 'note',
        actor: { kind: 'owner', name: null },
        at,
        day: at.slice(0, 10),
        time: at.slice(11, 16),
        minutes: null,
        text: key,
        fields: [],
    };
}

const clock = { today: '2026-10-08', now: '2026-10-08T12:51:00Z' };
const NOW = new Date(clock.now).getTime();

describe('Kopiuj dla agenta', () => {
    test('puts the line on the clipboard and reports success', async () => {
        const written: string[] = [];
        const ok = await copyAgentPrompt('Pracuj nad zadaniem 36 z CRM Solo (crm task 36)', {
            writeText: async (text: string) => void written.push(text),
        });

        expect(ok).toBe(true);
        expect(written).toEqual(['Pracuj nad zadaniem 36 z CRM Solo (crm task 36)']);
    });

    test('reports a failure when the browser refuses or has no clipboard', async () => {
        expect(await copyAgentPrompt('x', { writeText: async () => Promise.reject(new Error('denied')) })).toBe(false);
        expect(await copyAgentPrompt('x', undefined)).toBe(false);
    });
});

describe('Aktywność', () => {
    test('the live line sits on top only while an agent works, then one line per entry: when, who, what', () => {
        const activity: TaskActivityPage = { rows: [row('n1', '2026-10-08T12:39:00Z')], more: false };
        const live = { entry_id: 9, agent: 'Codex', started_at: '2026-10-08T12:39:00Z', time: '14:39', description: 'Eksport tabel do PDF' };
        const props = { taskId: 36, activity, clock, now: NOW, locale: 'pl' };

        const working = renderToStaticMarkup(<ActivitySection {...props} live={live} />);
        expect(working).toContain('Codex pracuje · 12 min');
        expect(working.indexOf('Codex pracuje')).toBeLessThan(working.indexOf('notatka: n1'));

        const idle = renderToStaticMarkup(<ActivitySection {...props} live={null} />);
        expect(idle).not.toContain('pracuje');
        expect(idle).toContain('12 min temu · Ty · </span>notatka: n1');
    });

    test('Cała historia shows on every width while a single row is collapsed to one line, and goes once the history is open', async () => {
        const props = { taskId: 36, clock, now: NOW, locale: 'pl', live: null };
        const two = [row('n2', '2026-10-08T12:40:00Z'), row('n1', '2026-10-08T12:39:00Z')];
        const link = (html: string) => html.match(/<a [^>]*>Cała historia/)?.[0];
        const css = await Bun.file(new URL('./task-page.module.css', import.meta.url)).text();

        for (const activity of [
            { rows: two, more: true },
            { rows: two, more: false },
            { rows: two.slice(1), more: false },
        ]) {
            const shown = link(renderToStaticMarkup(<ActivitySection {...props} activity={activity} />));
            expect(shown).toBeString();
            expect(shown).not.toContain('data-phone-only');
        }
        expect(css).not.toContain('data-phone-only');
        expect(renderToStaticMarkup(<ActivitySection {...props} activity={{ rows: [], more: false }} />)).not.toContain('Cała historia');
        expect(renderToStaticMarkup(<ActivitySection {...props} full activity={{ rows: two, more: false }} />)).not.toContain('Cała historia');
    });

    test('with nothing to show, Brak aktywności. sits inside the block, not loose under the head', () => {
        const html = renderToStaticMarkup(
            <ActivitySection taskId={36} activity={{ rows: [], more: false }} clock={clock} now={NOW} locale="pl" live={null} />,
        );

        expect(html).toMatch(/<div[^>]*><p[^>]*>Brak aktywności\.<\/p><\/div>/);
        expect(html).not.toContain('nic się nie wydarzyło');
    });
});

describe('Brief and Zasoby rows', () => {
    test('an empty brief is its row with pusty and Edytuj, nothing to open', () => {
        const html = renderToStaticMarkup(<BriefSection taskId={42} brief={emptyBrief} now={NOW} today="2026-10-08" locale="pl" />);

        expect(html).toContain('pusty');
        expect(html).toContain('>Edytuj</button>');
        expect(html).not.toContain('Pokaż');
        expect(html).not.toContain('aria-controls');
    });

    test('no resources is the row with na razie brak and Dodaj, no plus and nothing to open', () => {
        const html = renderToStaticMarkup(<ResourcesSection taskId={42} resources={{ files: [], images: [], links: [] }} />);

        expect(html).toContain('na razie brak');
        expect(html).toContain('>Dodaj</button>');
        expect(html).not.toContain('+');
        expect(html).not.toContain('Pokaż');
        expect(html).not.toContain('aria-controls');
    });
});

describe('Zasoby', () => {
    test('a link reads without its scheme, a named link by its name with an arrow', () => {
        expect(linkText({ id: 1, url: 'https://staging.example.test/cennik/', label: null, from: 'crm' })).toBe('staging.example.test/cennik');
        expect(linkText({ id: 2, url: 'https://example.test/x', label: 'Makieta tabel', from: 'crm' })).toBe('Makieta tabel ↗');
    });
});

describe('Zadanie header', () => {
    test('the trail ends on this task, clipped, with the project level when the task has one', () => {
        const html = renderToStaticMarkup(<TaskHeader task={task} today="2026-10-23" locale="pl" />);
        const trail = html.slice(html.indexOf('aria-label="Ścieżka"'), html.indexOf('</nav>'));

        expect(trail).toContain('>Pracownia Mebli</a>');
        expect(trail).toContain('href="/clients/3/projects/7"');
        expect(trail).toMatch(/aria-current="page" data-clip="true" title="Aktualizacja cennika PDF">Aktualizacja cennika PDF</);

        const loose = renderToStaticMarkup(<TaskHeader task={{ ...task, project: null }} today="2026-10-23" locale="pl" />);
        expect(loose).not.toContain('/projects/');
    });

    test('the meta reads project, due day and Trello, the link only for a card; overdue marks the due part', () => {
        const plain = renderToStaticMarkup(<TaskMeta task={task} today="2026-10-23" locale="pl" />);
        expect(plain.replace(/<[^>]+>/g, '')).toBe('Strona WWW · do 28.10');

        const card = renderToStaticMarkup(<TaskMeta task={{ ...trelloTask, is_overdue: true }} today="2026-10-23" locale="pl" />);
        expect(card.replace(/<[^>]+>/g, '')).toBe('Strona WWW · do 28.10 · Trello ↗');
        expect(card).toContain('data-overdue="true">do 28.10');
        expect(card).not.toContain('Lista');
    });

    test('the done action says w CRM only for a card, where the Trello state stays apart', () => {
        expect(renderToStaticMarkup(<TaskHeader task={task} today="2026-10-23" locale="pl" />)).toContain('Oznacz jako zrobione<');
        expect(renderToStaticMarkup(<TaskHeader task={task} today="2026-10-23" locale="pl" />)).not.toContain('w CRM');
        expect(renderToStaticMarkup(<TaskHeader task={trelloTask} today="2026-10-23" locale="pl" />)).toContain('Oznacz jako zrobione w CRM');

        const done = { is_done: true, finished_at: '2026-10-23T10:00:00Z', can_unfinish: true };
        expect(renderToStaticMarkup(<TaskHeader task={{ ...task, ...done }} today="2026-10-23" locale="pl" />)).toContain('Oznacz jako niezrobione');
        expect(renderToStaticMarkup(<TaskHeader task={{ ...trelloTask, ...done }} today="2026-10-23" locale="pl" />)).toContain(
            'Cofnij zrobione w CRM',
        );
    });

    test('a subtask links its parent quietly in the meta', () => {
        const html = renderToStaticMarkup(
            <TaskMeta task={{ ...task, parent_task: { id: 41, name: 'Sezon jesienny' } }} today="2026-10-23" locale="pl" />,
        );

        expect(html).toContain('href="/tasks/41"');
        expect(html).toContain('Nadrzędne: Sezon jesienny');
    });
});

describe('Czas on phones', () => {
    test('the stat row reads minutes under an hour and the clock form above it, running timers included', () => {
        expect(renderToStaticMarkup(<TimeStats time={time} now={NOW} />).replace(/<[^>]+>/g, '|')).toContain('38 min||dziś||||2 h 52||łącznie');

        const running = renderToStaticMarkup(<TimeStats time={runningTime} now={Date.parse('2026-10-23T12:00:00Z')} />).replace(/<[^>]+>/g, '|');
        expect(running).toContain('1 h 04|||||dziś · timer liczy od 11:34|');
        expect(running).toContain('3 h 18||łącznie');
    });
});

describe('Opis', () => {
    test('a long description gets Więcej and is marked to clamp; a short one gets neither', () => {
        const long = 'Nowe ceny są w arkuszu od Marka. '.repeat(8);
        const html = renderToStaticMarkup(<DescriptionSection task={{ ...task, description_markdown: long }} />);

        expect(isLongDescription(long)).toBe(true);
        expect(html).toContain('data-clamped="true"');
        expect(html).toContain('aria-expanded="false">Więcej ↓</button>');

        const short = renderToStaticMarkup(<DescriptionSection task={{ ...task, description_markdown: 'Nowe ceny.' }} />);
        expect(short).not.toContain('data-clamped');
        expect(short).not.toContain('Więcej');
    });

    test('an empty CRM description offers Dodaj opis and shows Brak opisu. in the block, without the Edytuj and Podgląd tabs', () => {
        const html = renderToStaticMarkup(<DescriptionSection task={{ ...task, description: null, description_markdown: '' }} />);

        expect(html).toContain('>Dodaj opis</button>');
        expect(html).toMatch(/<div[^>]*><p[^>]*>Brak opisu\.<\/p><\/div>/);
        expect(html).not.toContain('>Edytuj</button>');
        expect(html).not.toContain('Podgląd');
    });

    test('an empty card description keeps Edytuj w Trello as its one action', () => {
        const html = renderToStaticMarkup(<DescriptionSection task={{ ...trelloTask, description: null, description_markdown: '' }} />);

        expect(html).toContain('Edytuj w Trello ↗');
        expect(html).not.toContain('Dodaj opis');
        expect(html).not.toContain('Podgląd');
    });

    test('a card heads Opis with Edytuj w Trello at every width', () => {
        const html = renderToStaticMarkup(<DescriptionSection task={trelloTask} />);

        expect(html).toContain('href="https://trello.com/c/demo"');
        expect(html).toContain('Edytuj w Trello ↗');
    });
});
