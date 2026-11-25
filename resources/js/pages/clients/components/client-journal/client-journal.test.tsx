import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { durationText, inMonth } from '@/lib/dates';
import en from '../../../../../../lang/en.json';
import pl from '../../../../../../lang/pl.json';
import type { MessageRow } from '../client-fields';
import { ClientJournalTab, journalBrief, ProjectsTile, workDetails } from './client-journal';
import { projectHours } from './format';
import type { ClientJournal, JournalEntryNote, JournalNote, JournalPage, JournalSettlement, JournalWorkRow } from './types';

await i18next.use(initReactI18next).init({
    lng: 'pl',
    fallbackLng: 'en',
    resources: { pl: { translation: pl }, en: { translation: en } },
    interpolation: { escapeValue: false },
});

const t = i18next.getFixedT('pl');

const work = (key: string, extra: Partial<JournalWorkRow> = {}): JournalWorkRow => ({
    key,
    kind: 'entry',
    date: '2026-09-30',
    title: 'Ładowanie / animacje',
    subtitle: 'Preloader i animacje wejścia',
    via: null,
    minutes: 195,
    running: false,
    writes: null,
    is_new: false,
    ...extra,
});

const note = (id: number, extra: Partial<JournalEntryNote> = {}): JournalEntryNote => ({
    key: `note-${id}`,
    kind: 'note',
    id,
    date: '2026-09-03',
    via: null,
    author: 'Filip Chrapek',
    from_stage: 'active',
    to_stage: 'active',
    stage_change: false,
    note: 'Raport za sierpień wysłany do Marka.',
    ...extra,
});

const workPage = (extra: Partial<JournalPage<JournalWorkRow>> = {}): JournalPage<JournalWorkRow> => ({
    rows: [
        work('session:run-1', { kind: 'session', title: 'Deploy na prod', via: 'cli', minutes: 40, writes: 3 }),
        work('entry:2'),
        work('entry:3', { date: '2026-09-20' }),
    ],
    next: '1790000000',
    ...extra,
});

const notesPage = (extra: Partial<JournalPage<JournalNote>> = {}): JournalPage<JournalNote> => ({
    rows: [note(1, { via: 'cli', author: null }), note(2, { from_stage: 'prospect', to_stage: 'active', stage_change: true, note: null })],
    next: null,
    ...extra,
});

const frame = (extra: Partial<ClientJournal> = {}): ClientJournal => ({
    since: '2026-09-28',
    since_today: false,
    summary: { rows: 5, notes: 1, person_minutes: 375 },
    stage: { stage: 'active', since: '2026-06-13', retainer_from: '2026-06-02' },
    period: '2026-09',
    projects: [{ id: 4, name: 'Pracownia Mebli Przykładowa', minutes: 1590, trello_url: 'https://trello.com/b/abc' }],
    ...extra,
});

const render = (journal: ClientJournal, work = workPage(), notes = notesPage(), view: 'relationship' | null = null) =>
    renderToStaticMarkup(<ClientJournalTab clientId={7} journal={journal} work={work} notes={notes} owner="Filip Chrapek" view={view} />);

describe('Dziennik SMS', () => {
    const sms = (over: Partial<MessageRow>): MessageRow => ({
        id: 1,
        client_id: 7,
        client: 'Jan Kowalczyk',
        day: '2026-10-22',
        time: '18:00',
        status: 'pending',
        held: false,
        template: 'Przypomnienie',
        body: 'Wizja jutro o 10:00.',
        to: '+48100100200',
        error: null,
        may_have_gone_out: false,
        resends: 0,
        previous_error: null,
        expires: '2026-10-22 20:00',
        ...over,
    });
    const tab = (manage: boolean) =>
        renderToStaticMarkup(
            <ClientJournalTab
                clientId={7}
                journal={frame({})}
                work={workPage()}
                notes={notesPage()}
                owner="Filip Chrapek"
                manage={manage}
                sms={[sms({}), sms({ id: 2, status: 'failed', error: 'SMSAPI 13' })]}
            />,
        );

    test('a member reads the text messages without Cancel or Send again; the owner has both', () => {
        expect(tab(false)).toContain('Wizja jutro o 10:00.');
        expect(tab(false)).not.toContain('>Anuluj<');
        expect(tab(false)).not.toContain('Wyślij ponownie');
        expect(tab(true)).toContain('>Anuluj<');
        expect(tab(true)).toContain('Wyślij ponownie');
    });
});

describe('Dziennik', () => {
    const sentence = (extra: Partial<ClientJournal>) => journalBrief(frame(extra), 'pl-PL', t);

    test('the sentence reads like the frame and counts what it was given', () => {
        expect(sentence({})).toBe('Od 28.09: 5 rzeczy · 1 nowy wpis · Twój czas 6\u00a0h\u00a015.');
        expect(render(frame())).toContain('Od 28.09: 5 rzeczy · 1 nowy wpis · Twój czas 6\u00a0h\u00a015.');
    });

    test('the sentence takes the Polish form for every count', () => {
        const counted = (rows: number, notes: number) => sentence({ summary: { rows, notes, person_minutes: 0 } });

        expect(counted(1, 0)).toBe('Od 28.09: 1 rzecz.');
        expect(counted(3, 2)).toBe('Od 28.09: 3 rzeczy · 2 nowe wpisy.');
        expect(counted(22, 5)).toBe('Od 28.09: 22 rzeczy · 5 nowych wpisów.');
    });

    test('with nothing since the last visit the sentence says so', () => {
        expect(sentence({ summary: { rows: 0, notes: 0, person_minutes: 0 } })).toBe('Nic nowego od 28.09.');
    });

    test('a visit earlier today reads as today, not as a date', () => {
        expect(sentence({ since: '2026-10-05', since_today: true, summary: { rows: 0, notes: 0, person_minutes: 0 } })).toBe('Nic nowego od dziś.');
        expect(sentence({ since: '2026-10-05', since_today: true })).toStartWith('Od dziś: ');
    });

    test('an agent session is one row with its write count in its details, and a person row names the owner', () => {
        const [session, entry] = workPage().rows;
        const values = (row: JournalWorkRow) =>
            workDetails(row, 'Filip Chrapek', 'pl-PL', t).map((detail) => [detail.label, renderToStaticMarkup(<>{detail.value}</>).replace(/<[^>]+>/g, '')]);

        expect(values(session)).toEqual([
            ['Data', '30.09'],
            ['Czas', '40\u00a0min'],
            ['Status', ''],
            ['Autor', 'Cclaude'],
            ['Rodzaj', 'sesja agenta, 3 zapisy'],
        ]);
        expect(values(entry)).toEqual([
            ['Data', '30.09'],
            ['Czas', '3\u00a0h\u00a015'],
            ['Status', ''],
            ['Autor', 'FCFilip Chrapek'],
            ['Rodzaj', 'Wpis czasu'],
        ]);
        expect(render(frame())).toContain('3\u00a0h\u00a015<');
        expect(render(frame())).not.toContain('Cofnij');
    });

    test('a work row at rest is its title, the chevron and Edit on every width; its day, time, status, author and kind are fixed columns of its details, each once', () => {
        const entry = {
            id: 12,
            title: null,
            description: null,
            task_id: 41,
            start_time: '2026-09-30T08:00:00+00:00',
            end_time: '2026-09-30T11:15:00+00:00',
            billable: true,
        };
        const html = render(frame(), workPage({ rows: [work('entry:2', { task_id: 41, entry }), work('entry:3', { task_id: null })] }));
        const [linked, loose] = [...html.matchAll(/<li[^>]*>(.*?)<\/li>/g)].map(([row]) => row);
        const head = (row: string) => row.slice(0, row.indexOf('<dl'));

        expect(head(linked)).toMatch(/<a[^>]*href="\/tasks\/41"[^>]*>.*Ładowanie \/ animacje<\/span><\/span><\/a><\/span><button[^>]*aria-expanded="false"[^>]*>.*<\/button><button[^>]*>Edytuj<\/button><\/div><div id=/);
        expect(head(linked)).not.toContain('30.09');
        expect(head(linked)).not.toContain('3\u00a0h\u00a015');
        expect(linked.match(/30\.09/g)).toHaveLength(2);
        expect(linked.match(/3\u00a0h\u00a015</g)).toHaveLength(1);
        expect(linked).toContain(
            '<dl style="--detail-columns:var(--role-detail-short) var(--role-detail-short) var(--role-detail-text) var(--role-detail-text)">',
        );
        expect(linked).toContain('<div style="--detail-column:1"><dt>Data</dt><dd title="30.09">30.09</dd></div>');
        expect(linked).toContain('<div style="--detail-column:2"><dt>Czas</dt><dd title="3 h 15">3\u00a0h\u00a015</dd></div>');
        expect(linked).toMatch(/<div style="--detail-column:3"><dt>Autor<\/dt>.*<div style="--detail-column:4"><dt>Rodzaj<\/dt>/);
        expect(linked).not.toContain('<dt>Status</dt>');
        expect(head(loose)).not.toContain('<a ');
        expect(head(loose)).not.toContain('arrow-up-right');
    });

    test('a running work row shows the dot and the live time, read from its timer start', () => {
        const html = render(frame(), workPage({ rows: [work('entry:3', { running: true, minutes: null, running_since: '2026-09-30T08:00:00+00:00' })] }));

        expect(html).toMatch(/data-running=""><span[^>]*aria-hidden="true"><\/span><span aria-label="[^"]*">\d+:\d\d<\/span>/);
        expect(render(frame())).not.toContain('data-running');
    });

    test('work rows new since the last visit say so in their Status column, never at rest; the column stays for the list while one row is new and is gone when none is', () => {
        const fresh = workPage({
            rows: [work('session:run-1', { kind: 'session', title: 'Deploy na prod', via: 'cli', is_new: true }), work('entry:3')],
        });
        const html = render(frame(), fresh);
        const [newRow, oldRow] = [...html.matchAll(/<li[^>]*>(.*?)<\/li>/g)].map(([row]) => row);

        expect(html.match(/>Nowe</g)).toHaveLength(1);
        expect(newRow).toContain('<div style="--detail-column:3"><dt>Status</dt><dd title="Nowe">Nowe</dd></div>');
        expect(newRow.slice(0, newRow.indexOf('<dl'))).not.toContain('Nowe');
        expect(oldRow).not.toContain('<dt>Status</dt>');
        for (const row of [newRow, oldRow]) {
            expect(row).toContain('style="--detail-columns:var(--role-detail-short) var(--role-detail-short) var(--role-detail-short) var(--role-detail-text) var(--role-detail-text)"');
        }
        expect(oldRow).toContain('<div style="--detail-column:4"><dt>Autor</dt>');
        expect(html).not.toContain('data-status="open"');
        expect(render(frame())).not.toContain('>Nowe<');
        expect(render(frame())).not.toContain('<dt>Status</dt>');
    });

    test('an entry shows who wrote it: the owner, or the agent', () => {
        const html = render(frame());

        expect(html).toContain('aria-label="Filip Chrapek"');
        expect(html).toContain('aria-label="claude (agent)"');
    });

    test('Older pages Work on the tab and the entries in the Relationship sub-view, never in the column', () => {
        expect(render(frame()).match(/>Starsze</g)).toHaveLength(1);
        expect(render(frame(), workPage({ next: null }), notesPage({ next: '1790000000-4' }))).not.toContain('Starsze');
        const view = render(frame(), workPage({ next: null }), notesPage({ next: '1790000000-4' }), 'relationship');
        expect(view.match(/>Starsze</g)).toHaveLength(1);
        expect(view).toContain('notes=1790000000-4&amp;view=relationship');
    });

    test('the column shows the five newest entries as one line rows with no arrow and leads to the whole relationship with its count', () => {
        const rows = [1, 2, 3, 4, 5, 6].map((id) => note(id, { note: `Wpis ${id}` }));
        const html = render(frame({ notes_total: 24 }), workPage({ rows: [] }), notesPage({ rows }));

        expect(html.match(/aria-label="Pokaż szczegóły: Wpis \d"/g)).toHaveLength(5);
        expect(html).not.toContain('Wpis 6');
        expect(html).not.toContain('arrow-up-right');
        expect(html).toMatch(/<a href="\/clients\/7\/journal\?view=relationship">Cała relacja<\/a><\/span><span[^>]*>24 wpisy<\/span>/);
    });

    test('a field change is a row too, its whole line as the tooltip of its cut title', () => {
        const long = 'bardzo długa wartość pola '.repeat(20);
        const field = {
            key: 'field-1',
            kind: 'field',
            date: '2026-09-29',
            time: '10:00',
            via: null,
            author: null,
            label: 'Uwagi',
            old: null,
            new: long,
            field_key: 'notes',
        } as JournalNote;
        const column = render(frame(), workPage(), notesPage({ rows: [field] }));
        const view = render(frame(), workPage(), notesPage({ rows: [field] }), 'relationship');

        expect(column).toMatch(/<span[^>]*title="Uwagi[^"]*bardzo długa wartość pola/);
        expect(view).toMatch(/<dt>Rodzaj<\/dt><dd title="Pole">Pole<\/dd>/);
        expect(view).toContain('<div style="--detail-column:1"><dt>Data</dt><dd title="29.09 · 10:00">29.09\u00a0·\u00a010:00</dd></div>');
    });

    test('Work and SMS lead the main column and the column holds Projects then Relationship, which a phone reads first', () => {
        const html = render(frame());

        expect(html.indexOf('Praca')).toBeLessThan(html.indexOf('<aside'));
        expect(html.indexOf('>Projekty<')).toBeLessThan(html.indexOf('>Relacja z klientem<'));
    });

    test('the Relationship sub-view lists every entry in full under its month, beside the stage tile', () => {
        const long = 'Marek poprosił, żeby meble kuchenne z galerii były pierwsze w stopce strony. '.repeat(3);
        const html = render(
            frame({ notes_total: 2 }),
            workPage(),
            notesPage({ rows: [note(1, { note: long }), note(2, { date: '2026-08-14' })] }),
            'relationship',
        );

        expect(html).toContain('Wszystkie wpisy');
        expect(html).toContain('>Wrzesień 2026<');
        expect(html).toContain('>Sierpień 2026<');
        expect(html).not.toContain('data-clamped');
        expect(html).toContain('Zmień etap');
        expect(html).not.toContain('Praca');
        expect(html.match(/Aktywny/g)).toHaveLength(1);
        expect(html).not.toContain('Etap: ');
    });

    test('the relationship tile names the stage and since when, and a stage change is an entry of its own kind', () => {
        const html = render(frame());

        expect(html).toContain('Aktywny od 13.06.2026 · abonament od 02.06');
        expect(html).toContain('Potencjalny → Aktywny');
        expect(html).toContain('>Etap<');
    });

    test('a note can be edited from its menu; nothing offers to delete one', () => {
        const html = render(frame());

        expect(html.match(/aria-label="Więcej akcji: /g)).toHaveLength(2);
        expect(html).not.toContain('Usuń');
    });

    test('a work row that is one finished time entry opens its editor, a session or a running timer does not', () => {
        const entry = {
            id: 12,
            title: null,
            description: null,
            task_id: null,
            start_time: '2026-09-30T08:00:00+00:00',
            end_time: '2026-09-30T11:15:00+00:00',
            billable: true,
        };
        const html = render(
            frame(),
            workPage({
                rows: [
                    work('session:run-1', { kind: 'session', via: 'cli' }),
                    work('entry:2', { entry }),
                    work('entry:3', { running: true, minutes: null }),
                ],
            }),
        );

        expect(html.match(/<button[^>]*aria-label="Edytuj wpis czasu: Ładowanie \/ animacje"[^>]*>Edytuj<\/button>/g)).toHaveLength(1);
    });

    test('the projects tile lists this months hours, the board and the page of each project, and adds a project in place', () => {
        const html = renderToStaticMarkup(<ProjectsTile clientId={7} projects={frame().projects} period="2026-09" locale="pl-PL" />);

        expect(html).toMatch(/<h2[^>]*>Projekty<\/h2><span[^>]*>1<span[^>]*> · wrzesień<\/span><\/span>/);
        expect(html).toContain('href="/clients/7/projects/4"');
        expect(html).toContain('>26,5\u00a0h</span>');
        expect(html).toContain('data-wrap=""');
        expect(html).not.toContain('we wrześniu');
        expect(html).toContain('Trello ↗');
        expect(html).toMatch(/<button[^>]*>Nowy projekt<\/button>/);
        expect(html).not.toContain('/edit');
        expect(renderToStaticMarkup(<ProjectsTile clientId={7} projects={[]} period="2026-09" locale="pl-PL" />)).toContain('Brak projektów.');
    });

    test('a long note is a row of its first line, its whole text in the details; a short one does not repeat itself', () => {
        const long = 'Marek poprosił, żeby meble kuchenne z galerii były pierwsze w stopce strony. '.repeat(3).trim();
        const html = render(frame(), workPage(), notesPage({ rows: [note(1, { note: `Telefon z Markiem\n${long}` }), note(2, { note: 'Krótko' })] }), 'relationship');

        expect(html).toMatch(/<span[^>]*title="Telefon z Markiem">Telefon z Markiem<\/span>/);
        expect(html).toMatch(/<div[^>]*data-wide="true"><dt>Tekst<\/dt><dd>Telefon z Markiem\nMarek poprosił/);
        expect(html.match(/<dt>Tekst<\/dt>/g)).toHaveLength(1);
        expect(html).toMatch(/<dt>Rodzaj<\/dt><dd title="Notatka">Notatka<\/dd>/);
    });

    test('a note of one line longer than a phone\'s two clamped lines repeats its text in the details on every width; a short one does not', () => {
        const long = 'Marek poprosił o meble kuchenne w stopce strony.';
        const html = render(frame(), workPage({ rows: [] }), notesPage({ rows: [note(1, { note: long }), note(2, { note: 'Telefon z Markiem' })] }), 'relationship');

        expect(html.match(/<div data-wide="true"><dt>Tekst<\/dt>/g)).toHaveLength(1);
        expect(html).toContain(`<dd>${long}</dd>`);
    });

    test('a relationship entry at rest is its title, the chevron and the menu; its date leads its details, then author and kind, the same columns on every entry', () => {
        const html = render(frame(), workPage({ rows: [] }), notesPage({ rows: [note(1, { date: '2026-09-03' })] }));
        const row = [...html.matchAll(/<li[^>]*>(.*?)<\/li>/g)].map(([match]) => match).find((match) => match.includes('Raport za sierpie')) ?? '';
        const head = row.slice(0, row.indexOf('<dl'));

        expect(head).toMatch(/Raport za sierpień wysłany do Marka\.<\/span><button[^>]*aria-expanded="false"/);
        expect(head).not.toContain('03.09');
        expect(row).toContain('<dl style="--detail-columns:var(--role-detail-text) var(--role-detail-text) var(--role-detail-text)">');
        expect(row).toContain('<div style="--detail-column:1"><dt>Data</dt><dd title="03.09">03.09</dd></div>');
        expect(row).toMatch(/<dt>Autor<\/dt><dd><span[^>]*>.*<span[^>]*title="([^"]+)">\1<\/span><\/span><\/dd><\/div><div[^>]*><dt>Rodzaj<\/dt><dd title="Notatka">Notatka<\/dd>/);
    });
});

describe('Dziennik formats', () => {
    test('durations read as hours and minutes', () => {
        const t = i18next.getFixedT('pl');
        expect(durationText(45, t)).toBe('45 min');
        expect(durationText(120, t)).toBe('2 h');
        expect(durationText(195, t)).toBe('3 h 15 min');
    });

    test('the month takes the Polish locative', () => {
        expect(inMonth('2026-09', i18next.getFixedT('pl'))).toBe('we wrześniu');
        expect(inMonth('2026-10', i18next.getFixedT('pl'))).toBe('w październiku');
        expect(inMonth('2026-10', i18next.getFixedT('en'))).toBe('in October');
    });

    test('project hours drop a trailing zero', () => {
        expect(projectHours(1590, 'pl-PL')).toBe('26,5');
        expect(projectHours(120, 'pl-PL')).toBe('2');
        expect(projectHours(0, 'pl-PL')).toBe('0');
    });
});

describe('Dziennik settlement rows', () => {
    const settlement = (action: 'close' | 'reopen', extra: Partial<JournalSettlement['settlement']> = {}): JournalSettlement => ({
        key: `settlement-${action}`,
        kind: 'settlement',
        date: '2026-10-05',
        via: null,
        author: null,
        settlement: { action, period: '2026-09', used: 32, contracted: 30, closing: -11.5, source: 'report', ...extra },
    });

    test('a close and a reopen read from their events, between the entries, with no edit control', () => {
        const notes: JournalPage<JournalNote> = { rows: [settlement('reopen', { source: 'entries' }), settlement('close'), note(1)], next: null };
        const html = render(frame(), workPage(), notes);
        const text = html.replace(/<[^>]+>/g, '').replace(/[\u00a0\u202f]/g, ' ');

        expect(text).toContain('Zamknięto wrzesień 2026: 32,0 z 30 h, bilans −11,5 h (z raportu)');
        expect(text).toContain('Otwarto ponownie wrzesień 2026: 32,0 z 30 h, bilans −11,5 h (z wpisów)');
        expect(text.indexOf('Otwarto ponownie')).toBeLessThan(text.indexOf('Zamknięto'));
        expect(text.indexOf('Zamknięto')).toBeLessThan(text.indexOf('Raport za sierpień wysłany'));
        expect(text).toContain('Rozliczenie');
        expect(html.match(/aria-label="Więcej akcji: /g)).toHaveLength(1);
    });

    test('a month closed without a package reads its hours alone', () => {
        const html = render(frame(), workPage(), {
            rows: [settlement('close', { contracted: null, closing: 0, used: 4, source: 'entries' })],
            next: null,
        });

        expect(html).toContain('Zamknięto wrzesień 2026: 4,0 h, bilans 0,0 h (z wpisów)');
    });

    test('a month closed on billed time names it as the source', () => {
        const html = render(frame(), workPage(), { rows: [settlement('close', { used: 32.5, closing: -12, source: 'billed' })], next: null });

        expect(html.replace(/[\u00a0\u202f]/g, ' ')).toContain('Zamknięto wrzesień 2026: 32,5 z 30 h, bilans −12,0 h (z czasu rozliczanego)');
    });
});
