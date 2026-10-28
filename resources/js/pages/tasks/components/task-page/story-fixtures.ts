import type { TaskActivityPage, TaskBriefData, TaskCard, TaskClock, TaskData, TaskResources, TaskTime } from './types';

/** A fixed clock, so relative labels ("12 min temu") read the same on every render. */
export const NOW = Date.parse('2026-10-23T12:00:00Z');

export const clock: TaskClock = { today: '2026-10-23', now: '2026-10-23T12:00:00Z' };

const ago = (minutes: number) => new Date(NOW - minutes * 60_000).toISOString();

export const task: TaskData = {
    id: 42,
    name: 'Aktualizacja cennika PDF',
    description: 'Nowe ceny są w arkuszu. Układ tabel zostaje.',
    description_markdown: '**Cennik na sezon jesienny**\n\nNowe ceny są w arkuszu. Układ tabel zostaje, zmienia się waluta w nagłówku i kolejność kategorii.\n\n- Kuchnie, szafy i stoły w osobnych tabelach\n- Ceny netto, waluta w nagłówku tabeli',
    list_name: 'W toku',
    is_completed: false,
    finished_at: null,
    is_done: false,
    can_unfinish: false,
    is_archived: false,
    picked_today: false,
    due_date: '2026-10-28',
    due_day: '2026-10-28',
    is_overdue: false,
    parent_task: null,
    child_tasks: [],
    has_trello_card: false,
    trello_url: null,
    project: { id: 7, name: 'Strona WWW', trello_url: null },
    client: { id: 3, name: 'Pracownia Mebli Sp. z o.o.', short_name: 'Pracownia Mebli' },
};

export const trelloTask: TaskData = {
    ...task,
    has_trello_card: true,
    trello_url: 'https://trello.com/c/demo',
    project: { id: 7, name: 'Strona WWW', trello_url: 'https://trello.com/b/demo' },
};

export const time: TaskTime = {
    today_minutes: 38,
    total_minutes: 172,
    day_start: '2026-10-22T22:00:00Z',
    own_running: null,
    running: [],
    running_since: null,
};

export const runningTime: TaskTime = {
    ...time,
    own_running: { id: 9, started_at: ago(26) },
    running: [ago(26)],
    running_since: '11:34',
};

export const brief: TaskBriefData = {
    fields: {
        where: 'Strona cennika, sekcja Kuchnie',
        done_when: 'PDF na stronie, stary usunięty z mediów',
        constraints: null,
        notes: null,
    },
    filled: 2,
    last_change: { at: ago(12), day: '2026-10-23', time: '13:48', actor: { kind: 'agent', name: 'Codex' } },
};

export const emptyBrief: TaskBriefData = {
    fields: { where: null, done_when: null, constraints: null, notes: null },
    filled: 0,
    last_change: null,
};

export const card: TaskCard = {
    fetched_at: ago(5),
    error: null,
    done: 2,
    total: 4,
    checklists: [
        {
            name: 'Checklist',
            items: [
                { name: 'Eksport tabel do PDF', done: true },
                { name: 'Sprawdzenie cen netto', done: true },
                { name: 'Podmiana pliku na stronie', done: false },
                { name: 'Usunięcie starego pliku', done: false },
            ],
        },
    ],
};

export const resources: TaskResources = {
    files: [{ id: 1, name: 'cennik-jesien.xlsx', url: '#', mime: 'application/vnd.ms-excel', size: 48_210, from: 'upload', is_image: false }],
    images: [],
    links: [{ id: 2, url: 'https://meble-przykladowe.test/cennik', label: 'Obecny cennik', from: 'crm' }],
};

export const activity: TaskActivityPage = {
    more: true,
    rows: [
        {
            key: 'brief-1',
            kind: 'brief',
            at: ago(12),
            day: '2026-10-23',
            time: '13:48',
            actor: { kind: 'agent', name: 'Codex' },
            minutes: null,
            text: null,
            fields: ['done_when'],
        },
        {
            key: 'time-1',
            kind: 'time',
            at: ago(140),
            day: '2026-10-23',
            time: '11:40',
            actor: { kind: 'owner', name: null },
            minutes: 38,
            text: 'Eksport tabel',
            fields: [],
        },
        {
            key: 'note-1',
            kind: 'note',
            at: '2026-10-21T09:10:00Z',
            day: '2026-10-21',
            time: '11:10',
            actor: { kind: 'person', name: 'Anna Nowak' },
            minutes: null,
            text: 'Ceny w arkuszu są ostateczne.',
            fields: [],
        },
    ],
};

export const live = {
    entry_id: 11,
    agent: 'Codex',
    started_at: ago(12),
    time: '13:48',
    description: 'eksport tabel do PDF',
};
