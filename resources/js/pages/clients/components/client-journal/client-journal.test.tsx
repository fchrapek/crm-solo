import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { durationText, inMonth } from '@/lib/dates';
import en from '../../../../../../lang/en.json';
import pl from '../../../../../../lang/pl.json';
import { ClientJournalTab, journalBrief, ProjectsTile, workMeta } from './client-journal';
import { projectHours } from './format';
import type { MessageRow } from '../client-fields';
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

const render = (journal: ClientJournal, work = workPage(), notes = notesPage()) =>
    renderToStaticMarkup(<ClientJournalTab clientId={7} journal={journal} work={work} notes={notes} owner="Filip Chrapek" />);

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

    test('a member reads the text messages without Anuluj or Wyślij ponownie; the owner has both', () => {
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
        expect(sentence({})).toBe('Od 28.09: 5 rzeczy, 1 nowy wpis, Twój czas 6 h 15 min.');
        expect(render(frame())).toContain('Od 28.09: 5 rzeczy, 1 nowy wpis, Twój czas 6 h 15 min.');
    });

    test('the sentence takes the Polish form for every count', () => {
        const counted = (rows: number, notes: number) => sentence({ summary: { rows, notes, person_minutes: 0 } });

        expect(counted(1, 0)).toBe('Od 28.09: 1 rzecz.');
        expect(counted(3, 2)).toBe('Od 28.09: 3 rzeczy, 2 nowe wpisy.');
        expect(counted(22, 5)).toBe('Od 28.09: 22 rzeczy, 5 nowych wpisów.');
    });

    test('with nothing since the last visit the sentence says so', () => {
        expect(sentence({ summary: { rows: 0, notes: 0, person_minutes: 0 } })).toBe('Nic nowego od 28.09.');
    });

    test('a visit earlier today reads as today, not as a date', () => {
        expect(sentence({ since: '2026-10-05', since_today: true, summary: { rows: 0, notes: 0, person_minutes: 0 } })).toBe('Nic nowego od dziś.');
        expect(sentence({ since: '2026-10-05', since_today: true })).toStartWith('Od dziś: ');
    });

    test('an agent session is one row with its write count, and a person row names the owner', () => {
        const [session, entry] = workPage().rows;

        expect(workMeta(session, 'Filip Chrapek', 'pl-PL', t)).toBe('30.09 · claude · sesja, 3 zapisy');
        expect(workMeta(entry, 'Filip Chrapek', 'pl-PL', t)).toBe('30.09 · Filip Chrapek');
        expect(workMeta({ ...entry, running: true, minutes: null }, 'Filip Chrapek', 'pl-PL', t)).toBe('30.09 · Filip Chrapek · trwa');
        expect(render(frame())).toContain('3 h 15 min');
        expect(render(frame())).not.toContain('Cofnij');
    });

    test('work rows new since the last visit carry the Nowe chip, older ones and the entries do not', () => {
        const fresh = workPage({
            rows: [work('session:run-1', { kind: 'session', title: 'Deploy na prod', via: 'cli', is_new: true }), work('entry:3')],
        });
        const html = render(frame(), fresh);

        expect(html.match(/>Nowe</g)).toHaveLength(1);
        expect(html.indexOf('Nowe<')).toBeGreaterThan(html.indexOf('Deploy na prod'));
        expect(html.slice(0, html.indexOf('Praca · od'))).not.toContain('>Nowe<');
        expect(render(frame())).not.toContain('>Nowe<');
    });

    test('an entry shows who wrote it: the owner, or the agent', () => {
        const html = render(frame());

        expect(html).toContain('aria-label="Filip Chrapek"');
        expect(html).toContain('aria-label="claude (agent)"');
    });

    test('Starsze shows only under a list with an older page, and asks for that list alone', () => {
        expect(render(frame()).match(/>Starsze</g)).toHaveLength(1);
        expect(render(frame(), workPage({ next: null }), notesPage({ next: '1790000000-4' })).match(/>Starsze</g)).toHaveLength(1);
        expect(render(frame(), workPage({ next: null }))).not.toContain('Starsze');
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

        expect(html).toMatch(/<h2[^>]*>Projekty<\/h2><span[^>]*>1<\/span>/);
        expect(html).toContain('href="/clients/7/projects/4"');
        expect(html.replace(/<[^>]+>/g, '')).toContain('26,5 hwe wrześniu');
        expect(html).toContain('Trello ↗');
        expect(html).toMatch(/<button[^>]*>Nowy projekt<\/button>/);
        expect(html).not.toContain('/edit');
        expect(renderToStaticMarkup(<ProjectsTile clientId={7} projects={[]} period="2026-09" locale="pl-PL" />)).toContain('Brak projektów.');
    });

    test('a long note opens folded with a more button', () => {
        const long = 'Marek poprosił, żeby meble kuchenne z galerii były pierwsze w stopce strony. '.repeat(3);
        const html = render(frame(), workPage(), notesPage({ rows: [note(1, { note: long })] }));

        expect(html).toContain('data-folded="true"');
        expect(html).toContain('>więcej</button>');
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
