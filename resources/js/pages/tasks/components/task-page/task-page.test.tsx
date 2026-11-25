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
import { copyAgentPrompt, TaskHeader, TaskMeta, taskPlace } from './task-header';
import { deleteRefusal, editValues, taskMenuItems } from './task-menu';
import { reportableRefusal, TimeSection } from './time-section';
import { JsonRequestError } from '@/lib/json-request';
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
        entry: null,
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

const text = (html: string) => html.replace(/<[^>]+>/g, '|');

describe('Aktywność', () => {
    test('the agent chip heads the tile only while an agent works; a row is its title with who and when at the end', () => {
        const activity: TaskActivityPage = { rows: [row('n1', '2026-10-08T12:39:00Z')], more: false };
        const live = { entry_id: 9, agent: 'Codex', started_at: '2026-10-08T12:39:00Z', time: '14:39', description: 'Eksport tabel do PDF' };
        const props = { taskId: 36, activity, clock, now: NOW, locale: 'pl' };

        const working = renderToStaticMarkup(<ActivitySection {...props} live={live} />);
        expect(working).toContain('Codex pracuje · 12 min');
        expect(working.indexOf('Codex pracuje')).toBeLessThan(working.indexOf('Notatka: n1'));

        const idle = text(renderToStaticMarkup(<ActivitySection {...props} live={null} />));
        expect(idle).not.toContain('pracuje');
        expect(idle).toContain('Notatka: n1||ty · 12 min temu');
    });

    test('the tile keeps the three newest rows and Whole history opens the rest by the URL', () => {
        const props = { taskId: 36, clock, now: NOW, locale: 'pl', live: null };
        const four = ['n4', 'n3', 'n2', 'n1'].map((key, index) => row(key, `2026-10-08T12:4${3 - index}:00Z`));
        const html = renderToStaticMarkup(<ActivitySection {...props} full activity={{ rows: four, more: false }} />);

        expect(html).toContain('Notatka: n2');
        expect(html).not.toContain('Notatka: n1');
        expect(html).toMatch(/<a [^>]*href="\/tasks\/36\?history=1"[^>]*>Cała historia/);
        expect(renderToStaticMarkup(<ActivitySection {...props} activity={{ rows: [], more: false }} />)).not.toContain('Cała historia');
    });

    test('with nothing to show the tile says No activity yet.', () => {
        const html = renderToStaticMarkup(
            <ActivitySection taskId={36} activity={{ rows: [], more: false }} clock={clock} now={NOW} locale="pl" live={null} />,
        );

        expect(html).toMatch(/<p[^>]*>Brak aktywności\.<\/p>/);
    });
});

describe('Brief and Zasoby tiles', () => {
    test('an empty brief says empty, shows each field as not set and offers Edit', () => {
        const html = renderToStaticMarkup(<BriefSection taskId={42} brief={emptyBrief} now={NOW} today="2026-10-08" locale="pl" />);

        expect(text(html)).toContain('Brief dla agenta|||pusty|');
        expect(html).toContain('>Edytuj</button>');
        expect(html.match(/nie ustawiono/g)).toHaveLength(4);
    });

    test('no resources is the head with none yet and Add, and no rows', () => {
        const html = renderToStaticMarkup(<ResourcesSection taskId={42} resources={{ files: [], images: [], links: [] }} />);

        expect(html).toContain('na razie brak');
        expect(html).toContain('>Dodaj</button>');
        expect(html).not.toContain('<ul');
    });

    test('a file says where it came from, and only what the CRM holds can be removed', () => {
        const file = { id: 1, name: 'cennik.pdf', url: '/f/1', mime: 'application/pdf', size: 1_258_291, from: 'upload', is_image: false } as const;
        const html = renderToStaticMarkup(
            <ResourcesSection
                taskId={42}
                resources={{
                    files: [file, { ...file, id: 2, name: 'arkusz.xlsx', from: 'trello' }],
                    images: [{ ...file, id: 3, name: 'tabela.png', from: 'trello', is_image: true }],
                    links: [{ id: 4, url: 'https://example.test/cennik', label: 'Obecny cennik', from: 'crm' }],
                }}
            />,
        );

        expect(text(html)).toContain('cennik.pdf|||plik · 1,2 MB|');
        expect(text(html)).toContain('arkusz.xlsx|||plik · Trello|');
        expect(text(html)).toContain('tabela.png|||obraz · Trello|');
        expect(html.match(/aria-label="Usuń [^"]+"/g)).toEqual(['aria-label="Usuń cennik.pdf"', 'aria-label="Usuń Obecny cennik ↗"']);
    });
});

describe('Zasoby', () => {
    test('a link reads without its scheme, a named link by its name with an arrow', () => {
        expect(linkText({ id: 1, url: 'https://staging.example.test/cennik/', label: null, from: 'crm' })).toBe('staging.example.test/cennik');
        expect(linkText({ id: 2, url: 'https://example.test/x', label: 'Makieta tabel', from: 'crm' })).toBe('Makieta tabel ↗');
    });
});

describe('Zadanie header', () => {
    const context = { t: i18next.t.bind(i18next) as (key: string) => string, url: '/tasks/42', locale: 'pl' };

    test("the task's place is its client, Tasks, then the task itself", () => {
        const tokens = taskPlace({ task }, context).tokens;

        expect(tokens.map((token) => token.label)).toEqual(['Pracownia Mebli', 'Zadania', 'Aktualizacja cennika PDF']);
        expect(tokens[1].href).toBe('/clients/3/tasks');
        expect(tokens[2].href).toBeUndefined();
        expect(taskPlace({ task: { ...task, client: null } }, context).tokens).toHaveLength(1);
    });

    test('the meta reads client, project, due day and Trello, the link only for a card; overdue says so on the due part', () => {
        const plain = renderToStaticMarkup(<TaskMeta task={task} today="2026-10-23" locale="pl" />);
        expect(plain.replace(/<[^>]+>/g, '')).toBe('Pracownia Mebli · Strona WWW · do 28.10');

        const card = renderToStaticMarkup(<TaskMeta task={{ ...trelloTask, is_overdue: true }} today="2026-10-23" locale="pl" />);
        expect(card.replace(/<[^>]+>/g, '')).toBe('Pracownia Mebli · Strona WWW · do 28.10, po terminie · Trello ↗');
        expect(card).toMatch(/data-overdue="true"[^>]*>do 28\.10, po terminie/);
        expect(card).toMatch(/href="\/clients\/\d+\/overview"[^>]*>Pracownia Mebli</);
        expect(card).toMatch(/href="\/clients\/\d+\/projects\/\d+"[^>]*>Strona WWW</);
        expect(card).toMatch(/href="\/day\/2026-10-28"/);
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

    test('the title is the poster one size below the page titles, in its own case and shown whole, and a long name steps down', () => {
        const short = renderToStaticMarkup(<TaskHeader task={{ ...task, name: 'Migracja hostingu na PHP 8.3' }} today="2026-10-23" locale="pl" />);
        const long = 'Migracja hostingu na PHP 8.3 dla meble-przykladowe.test razem z pocztą i certyfikatem';
        const stepped = renderToStaticMarkup(<TaskHeader task={{ ...task, name: long }} today="2026-10-23" locale="pl" />);

        expect(short).toMatch(/<h1 class="[^"]*">Migracja hostingu na PHP 8.3<\/h1>/);
        expect(short).not.toContain('data-long');
        expect(stepped).toMatch(new RegExp(`<h1 class="[^"]*" data-long="">${long}</h1>`));
    });

    test('the title is never cut at a line count, so a hidden line cannot show its accents', async () => {
        const css = await Bun.file(new URL('../../../../components/solo/primitives.module.css', import.meta.url)).text();
        const rule = css.match(/\.posterRecord \{([^}]*)\}/)?.[1] ?? '';

        expect(rule).not.toMatch(/line-clamp|overflow/);
    });

    test('the title keeps a readable word space, and the long step has looser lines than the poster so Polish marks clear the line above', async () => {
        const css = await Bun.file(new URL('../../../../components/solo/primitives.module.css', import.meta.url)).text();
        const tokens = await Bun.file(new URL('../../../../../css/solo.css', import.meta.url)).text();
        const number = (name: string) => Number(tokens.match(new RegExp(`${name}: ([\\d.]+);`))?.[1]);

        expect(css.match(/\.posterRecord \{([^}]*)\}/)?.[1]).toMatch(/word-spacing: 0\.\d+em/);
        expect(css.match(/\.posterLong \{([^}]*)\}/)?.[1]).toContain('line-height: var(--solo-poster-record-long-leading)');
        expect(number('--solo-poster-record-long-leading')).toBeGreaterThan(number('--solo-caps-leading'));
    });

    test('a done or archived task says so in a chip above the title and is not offered for today', () => {
        const done = renderToStaticMarkup(
            <TaskHeader task={{ ...task, is_done: true, finished_at: '2026-10-23T10:00:00Z', can_unfinish: true }} today="2026-10-23" locale="pl" />,
        );
        expect(done.indexOf('zrobione w CRM')).toBeLessThan(done.indexOf('<h1'));
        expect(done).not.toContain('Na dziś');

        const archived = renderToStaticMarkup(<TaskHeader task={{ ...task, is_archived: true }} today="2026-10-23" locale="pl" />);
        expect(archived.indexOf('Zarchiwizowane')).toBeLessThan(archived.indexOf('<h1'));
        expect(archived).not.toContain('Na dziś');
    });

    test('a subtask links its parent quietly in the meta', () => {
        const html = renderToStaticMarkup(
            <TaskMeta task={{ ...task, parent_task: { id: 41, name: 'Sezon jesienny' } }} today="2026-10-23" locale="pl" />,
        );

        expect(html).toContain('href="/tasks/41"');
        expect(html).toContain('Nadrzędne: Sezon jesienny');
    });
});

describe('Zadanie, the more menu', () => {
    test('a CRM task can be edited, archived and deleted; an archived one is restored instead', () => {
        expect(taskMenuItems(task)).toEqual(['copy', 'edit', 'archive', 'delete']);
        expect(taskMenuItems({ ...task, is_archived: true })).toEqual(['copy', 'edit', 'restore', 'delete']);
    });

    test('a card is Trello\'s: the menu offers its card and none of edit, archive or delete', () => {
        expect(taskMenuItems(trelloTask)).toEqual(['copy', 'trello']);
    });

    test('a delete refused for settled time gives the server\'s reason; any other failure does not offer the archive', () => {
        const reason = 'To zadanie ma czas w zamkniętym miesiącu (wrzesień 2026). Zarchiwizuj je zamiast usuwać.';

        expect(deleteRefusal(new JsonRequestError('The given data was invalid.', { archive_instead: [reason] }))).toBe(reason);
        expect(deleteRefusal(new JsonRequestError('Trello-source tasks must be deleted on the Trello board.'))).toBeNull();
        expect(deleteRefusal(new Error('offline'))).toBeNull();
    });

    test('the edit form opens on the task as it stands', () => {
        expect(editValues({ ...task, list_name: 'Doing', priority: 'high', parent_task_id: 41, is_reportable: false })).toEqual({
            id: 42,
            name: 'Aktualizacja cennika PDF',
            description: 'Nowe ceny są w arkuszu. Układ tabel zostaje.',
            list_name: 'Doing',
            due_date: '2026-10-28',
            priority: 'high',
            parent_task_id: 41,
            is_reportable: false,
        });
        expect(editValues({ ...task, list_name: 'W toku' })).not.toHaveProperty('list_name');
    });
});

describe('Czas', () => {
    const props = { task: { id: 42, name: 'Cennik', client: 'Pracownia Mebli', is_reportable: true } };

    test('today and the total read minutes under an hour and the clock form above it, running timers included', () => {
        const idle = text(renderToStaticMarkup(<TimeSection {...props} time={time} now={NOW} />));
        expect(idle).toContain('Dziś||38 min||zalogowane dziś');
        expect(idle).toContain('Łącznie||2 h 52||wszystkie wpisy');
        expect(idle).toContain('|Start|');

        const html = renderToStaticMarkup(<TimeSection {...props} time={runningTime} now={Date.parse('2026-10-23T12:00:00Z')} />);
        expect(text(html)).toContain('Dziś||1 h 04||timer liczy od 11:34');
        expect(text(html)).toContain('Łącznie||3 h 18|');
        expect(html).toContain('data-tone="over"');
        expect(text(html)).toContain('|Stop|');
        expect(text(html)).not.toContain('|Start|');
    });

    test('the report switch shows the flag of a card and of a CRM task alike', () => {
        const on = renderToStaticMarkup(<TimeSection {...props} time={time} now={NOW} />);
        const off = renderToStaticMarkup(<TimeSection task={{ ...props.task, is_reportable: false }} time={time} now={NOW} />);

        expect(on).toContain('Liczy się w raporcie');
        expect(on).toMatch(/role="switch"[^>]*aria-checked="true"/);
        expect(off).toMatch(/role="switch"[^>]*aria-checked="false"/);
    });

    test('a refused switch shows the reason the server gave for the field', () => {
        const reason = 'To zadanie liczy się w sfinalizowanym lub wysłanym raporcie za wrzesień 2026. Otwórz raport ponownie, żeby zmienić, czy się liczy.';

        expect(reportableRefusal(new JsonRequestError('The given data was invalid.', { is_reportable: [reason] }))).toBe(reason);
        expect(reportableRefusal(new JsonRequestError('Forbidden'))).toBe('Forbidden');
    });
});

describe('Opis', () => {
    test('a long description gets More and is marked to clamp; a short one gets neither', () => {
        const long = 'Nowe ceny są w arkuszu od Marka. '.repeat(8);
        const html = renderToStaticMarkup(<DescriptionSection task={{ ...task, description_markdown: long }} />);

        expect(isLongDescription(long)).toBe(true);
        expect(html).toContain('data-clamped="true"');
        expect(html).toContain('aria-expanded="false">Więcej ↓</button>');

        const short = renderToStaticMarkup(<DescriptionSection task={{ ...task, description_markdown: 'Nowe ceny.' }} />);
        expect(short).not.toContain('data-clamped');
        expect(short).not.toContain('Więcej');
    });

    test('an empty CRM description offers Add description and says No description yet., without Edit or Preview', () => {
        const html = renderToStaticMarkup(<DescriptionSection task={{ ...task, description: null, description_markdown: '' }} />);

        expect(html).toContain('>Dodaj opis</button>');
        expect(html).toMatch(/<p[^>]*>Brak opisu\.<\/p>/);
        expect(html).not.toContain('>Edytuj</button>');
        expect(html).not.toContain('Podgląd');
    });

    test('an empty card description keeps Edit in Trello as its one action', () => {
        const html = renderToStaticMarkup(<DescriptionSection task={{ ...trelloTask, description: null, description_markdown: '' }} />);

        expect(html).toContain('Edytuj w Trello ↗');
        expect(html).not.toContain('Dodaj opis');
        expect(html).not.toContain('Podgląd');
    });

    test('a card heads Description with Edit in Trello at every width', () => {
        const html = renderToStaticMarkup(<DescriptionSection task={trelloTask} />);

        expect(html).toContain('href="https://trello.com/c/demo"');
        expect(html).toContain('Edytuj w Trello ↗');
    });
});
