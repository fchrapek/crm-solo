import { router } from '@inertiajs/react';
import { afterEach, describe, expect, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { durationText, inMonth } from '@/lib/dates';
import en from '../../../../../../lang/en.json';
import pl from '../../../../../../lang/pl.json';
import { ClientJournalTab, PhoneWorkRow, ProjectsSheetBody, RelationshipScreen } from './client-journal';
import { isRelationshipScreen, projectHours, withRelationshipScreen } from './format';
import {
    closeRelationshipScreen,
    forgetHeldJournal,
    holdJournal,
    journalChanged,
    markHistoryReturn,
    openRelationshipScreen,
    takeFocusReturn,
    takeHeldJournal,
} from './relationship-screen';
import type { ClientJournal, JournalEntryNote, JournalNote, JournalPage, JournalSettlement, JournalWorkRow } from './types';

await i18next.use(initReactI18next).init({
    lng: 'pl',
    fallbackLng: 'en',
    resources: { pl: { translation: pl }, en: { translation: en } },
    interpolation: { escapeValue: false },
});

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
    renderToStaticMarkup(
        <ClientJournalTab clientName="Pracownia Mebli Przykładowa" clientId={7} journal={journal} work={work} notes={notes} owner="Filip Chrapek" />,
    );

describe('Dziennik', () => {
    test('the sentence reads like the frame and counts what it was given', () => {
        const html = render(frame());
        expect(html).toContain('Od 28.09: <span');
        expect(html).toContain('>5 rzeczy</span>');
        expect(html).toContain('>1 nowy wpis</span>');
        expect(html).toContain('>Twój czas 6 h 15 min</span>.</p>');
    });

    test('the sentence takes the Polish form for every count', () => {
        const sentence = (rows: number, notes: number) => render(frame({ summary: { rows, notes, person_minutes: 0 } }));
        expect(sentence(1, 0)).toContain('>1 rzecz</span>.');
        expect(sentence(3, 2)).toContain('>3 rzeczy</span>, <span');
        expect(sentence(3, 2)).toContain('>2 nowe wpisy</span>');
        expect(sentence(22, 5)).toContain('>5 nowych wpisów</span>');
        expect(sentence(22, 5)).not.toContain('Twój czas');
    });

    test('with nothing since the last visit the sentence says so', () => {
        expect(render(frame({ summary: { rows: 0, notes: 0, person_minutes: 0 } }))).toContain('Nic nowego od 28.09.');
    });

    test('a visit earlier today reads as today, not as a date', () => {
        expect(render(frame({ since: '2026-10-05', since_today: true, summary: { rows: 0, notes: 0, person_minutes: 0 } }))).toContain(
            'Nic nowego od dziś.',
        );
        expect(render(frame({ since: '2026-10-05', since_today: true }))).toContain('Od dziś: <span');
    });

    test('an agent session is one row with its write count and the agent mark', () => {
        const html = render(frame());
        expect(html).toContain('claude · sesja, 3 zapisy');
        expect(html).toContain('data-actor="agent"');
        expect(html).toContain('3 h 15 min');
        expect(html).not.toContain('Cofnij');
    });

    test('work rows new since the last visit carry the label after the title, older ones and the rail do not', () => {
        const fresh = workPage({
            rows: [work('session:run-1', { kind: 'session', title: 'Deploy na prod', via: 'cli', is_new: true }), work('entry:3')],
        });
        const html = render(frame(), fresh);
        // once in the desktop row, once in the phone row
        expect(html.match(/>Nowe</g)?.length).toBe(2);
        expect(html).toContain('<p>Deploy na prod<span>Nowe</span></p>');
        expect(html.slice(html.lastIndexOf('Relacja z klientem'))).not.toContain('>Nowe<');
        expect(render(frame())).not.toContain('>Nowe<');
    });

    test('a person row shows the owner and an agent entry the agent', () => {
        const html = render(frame());
        expect(html).toContain('aria-label="Filip Chrapek"');
        expect(html).toContain('aria-label="claude (agent)"');
    });

    test('Starsze shows only under a list with an older page', () => {
        expect(render(frame()).match(/Starsze/g)?.length).toBe(1);
        expect(render(frame(), workPage({ next: null }), notesPage({ next: '1790000000-4' })).match(/Starsze/g)?.length).toBe(1);
        expect(render(frame(), workPage({ next: null }))).not.toContain('Starsze');
    });

    test('the rail shows projects with this months hours, the stage and the stage change entry', () => {
        const html = render(frame());
        expect(html).toContain('Projekty · 1');
        expect(html).toContain('href="/clients/7/projects/4"');
        expect(html).toContain('26,5 h we wrześniu');
        expect(html).toContain('Trello ↗');
        expect(html).toContain('od 13.06.2026 · abonament od 02.06');
        expect(html).toContain('Potencjalny → Aktywny');
        expect(html).toContain('>Etap<');
        expect(html).toContain('Nowy wpis');
        expect(html).toContain('href="/clients/7/edit#work">Nowy projekt</a>');
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
        expect((html.match(/<button/g) ?? []).length).toBe(
            (render(frame(), workPage(), { rows: [note(1)], next: null }).match(/<button/g) ?? []).length,
        );
    });

    test('a month closed without a package reads its hours alone', () => {
        const html = render(frame(), workPage(), {
            rows: [settlement('close', { contracted: null, closing: 0, used: 4, source: 'entries' })],
            next: null,
        });

        expect(html).toContain('Zamknięto wrzesień 2026: 4,0 h, bilans 0,0 h (z wpisów)');
    });
});

describe('Dziennik on phones', () => {
    const settlement: JournalSettlement = {
        key: 'settlement-1',
        kind: 'settlement',
        date: '2026-10-06',
        via: null,
        author: null,
        settlement: { action: 'close', period: '2026-08', used: 7.5, contracted: 8, closing: 0.5, source: 'report' },
    };
    const screen = (notes = notesPage()) =>
        renderToStaticMarkup(
            <RelationshipScreen
                clientId={7}
                clientName="Pracownia Mebli Przykładowa"
                journal={frame()}
                notes={notes}
                owner="Filip Chrapek"
                locale="pl-PL"
            />,
        );

    test('Relacja z klientem is a row with the new entries, Projekty a row with the count', () => {
        const html = render(frame());
        const text = html.replace(/<[^>]+>/g, '|');

        expect(html).toContain('href="/clients/7/dziennik?relacja=1"');
        expect(text).toMatch(/Relacja z klientem\|+1 nowy wpis/);
        expect(text).toMatch(/Projekty\|+1\|/);
        expect(html).toContain('aria-haspopup="dialog"');
        expect(render(frame({ summary: { rows: 5, notes: 0, person_minutes: 0 } })).replace(/<[^>]+>/g, '|')).not.toMatch(/Relacja z klientem\|+0/);
    });

    test('the pushed screen has the back link to Dziennik, the title, the stage line and every note', () => {
        const html = screen();

        expect(html).toMatch(/<a[^>]*href="\/clients\/7\/dziennik"[^>]*>.*Dziennik<\/span><\/a>/);
        expect(html).toContain('<h1 id="relationship-title">Relacja z klientem</h1>');
        expect(html).toContain('Pracownia Mebli Przykładowa');
        expect(html).toContain('Aktywny');
        expect(html).toContain('od 13.06.2026 · abonament od 02.06');
        expect(html).toContain('Raport za sierpień wysłany do Marka.');
        expect(html).toContain('Potencjalny → Aktywny');
    });

    test('settlement rows read ROZLICZENIE with their frozen figures and stay read-only', () => {
        const html = screen(notesPage({ rows: [settlement, note(1)] }));

        expect(html).toContain('>Rozliczenie<');
        expect(html).toContain('Zamknięto sierpień 2026: 7,5 z 8 h, bilans +0,5 h (z raportu)');
        expect(html.slice(0, html.indexOf('Raport za sierpień'))).not.toContain('<button');
    });

    test('the page renders the pushed screen only when the URL asks for it, and the page stays beside it for desktop', () => {
        const pushed = renderToStaticMarkup(
            <ClientJournalTab
                clientName="Pracownia Mebli Przykładowa"
                clientId={7}
                journal={frame()}
                work={workPage()}
                notes={notesPage()}
                owner="Filip Chrapek"
                relationship
            />,
        );

        expect(pushed).toContain('id="relationship-title"');
        expect(pushed).toContain('Od 28.09:');
        expect(render(frame())).not.toContain('id="relationship-title"');
    });

    test('the screen lives in the query string, so back closes it and other parameters survive', () => {
        expect(isRelationshipScreen('/clients/7/dziennik?relacja=1')).toBe(true);
        expect(isRelationshipScreen('/clients/7/dziennik?praca=12')).toBe(false);
        expect(isRelationshipScreen('/clients/7/dziennik#relacja')).toBe(false);
        expect(withRelationshipScreen('/clients/7/dziennik?praca=12', true)).toBe('/clients/7/dziennik?praca=12&relacja=1');
        expect(withRelationshipScreen('/clients/7/dziennik?relacja=1', false)).toBe('/clients/7/dziennik');
    });

    test('the Projekty sheet lists hours this month, Trello and Nowy projekt to where projects are made', () => {
        const html = renderToStaticMarkup(<ProjectsSheetBody clientId={7} projects={frame().projects} period="2026-09" locale="pl-PL" />);

        expect(html).toContain('href="/clients/7/projects/4"');
        expect(html).toContain('26,5 h we wrześniu');
        expect(html).toContain('href="https://trello.com/b/abc" target="_blank" rel="noopener noreferrer"');
        expect(html).toContain('href="/clients/7/edit#work"');
        expect(html).toContain('Nowy projekt');
        expect(renderToStaticMarkup(<ProjectsSheetBody clientId={7} projects={[]} period="2026-09" locale="pl-PL" />)).toContain('Brak projektów.');
    });

    test('NOWE marks new work rows only, never a note', () => {
        const row = (is_new: boolean) =>
            renderToStaticMarkup(
                <ul>
                    <PhoneWorkRow
                        row={work('session:run-1', { kind: 'session', title: 'Deploy na prod', via: 'cli', writes: 3, is_new })}
                        owner="Filip Chrapek"
                        locale="pl-PL"
                    />
                </ul>,
            );

        expect(row(true)).toContain('>Nowe<');
        expect(row(true)).toContain('claude · sesja, 3 zapisy');
        expect(row(true)).toContain(' · 30.09');
        expect(row(false)).not.toContain('>Nowe<');
        expect(screen()).not.toContain('>Nowe<');
    });
});

describe('the Relacja screen in history', () => {
    const spies: { mockRestore: () => void }[] = [];
    afterEach(() => {
        spies.splice(0).forEach((spy) => spy.mockRestore());
        delete (globalThis as { window?: unknown }).window;
    });

    test('opening pushes a client-side visit; its back link pops the entry, again after Forward, and replaces on a direct load', () => {
        const back: string[] = [];
        (globalThis as { window?: unknown }).window = { history: { back: () => back.push('back') } };
        const push = spyOn(router, 'push').mockImplementation(() => undefined);
        const replace = spyOn(router, 'replace').mockImplementation(() => undefined);
        spies.push(push, replace);

        openRelationshipScreen('/clients/7/dziennik');
        expect(push.mock.calls[0]?.[0]).toMatchObject({ url: '/clients/7/dziennik?relacja=1', preserveState: true, preserveScroll: false });

        closeRelationshipScreen('/clients/7/dziennik?relacja=1');
        closeRelationshipScreen('/clients/7/dziennik?relacja=1');
        expect(back).toEqual(['back', 'back']);

        closeRelationshipScreen('/clients/8/dziennik?relacja=1');
        expect(replace.mock.calls[0]?.[0]).toMatchObject({ url: '/clients/8/dziennik' });
    });

    test('Dziennik restored from history gets the journal the screen last held, once and for its own client only', () => {
        const held = { journal: frame({ summary: { rows: 6, notes: 2, person_minutes: 0 } }), notes: notesPage() };
        const before = { journal: frame(), notes: notesPage() };

        expect(journalChanged(held, before)).toBe(true);
        expect(journalChanged(before, { journal: frame(), notes: notesPage() })).toBe(false);

        holdJournal('/clients/7/dziennik', held);
        markHistoryReturn();
        expect(takeHeldJournal('/clients/8/dziennik')).toBeNull();
        holdJournal('/clients/7/dziennik', held);
        markHistoryReturn();
        expect(takeHeldJournal('/clients/7/dziennik')).toBe(held);
        expect(takeHeldJournal('/clients/7/dziennik')).toBeNull();
    });

    test('a fresh visit to Dziennik after leaving Relacja another way keeps the server props', () => {
        holdJournal('/clients/7/dziennik', { journal: frame(), notes: notesPage() });
        expect(takeHeldJournal('/clients/7/dziennik')).toBeNull();

        // Relacja, then Klienci (a server visit), Przegląd, Back to Klienci, Przegląd, Dziennik
        holdJournal('/clients/7/dziennik', { journal: frame(), notes: notesPage() });
        forgetHeldJournal();
        markHistoryReturn();
        forgetHeldJournal();
        expect(takeHeldJournal('/clients/7/dziennik')).toBeNull();
    });

    test('closing the screen gives the focus back to the Relacja row of its own Dziennik once, and a server visit cancels it', () => {
        holdJournal('/clients/7/dziennik', { journal: frame(), notes: notesPage() });
        expect(takeFocusReturn('/clients/8/dziennik')).toBe(false);

        holdJournal('/clients/7/dziennik', { journal: frame(), notes: notesPage() });
        expect(takeFocusReturn('/clients/7/dziennik')).toBe(true);
        expect(takeFocusReturn('/clients/7/dziennik')).toBe(false);

        holdJournal('/clients/7/dziennik', { journal: frame(), notes: notesPage() });
        forgetHeldJournal();
        expect(takeFocusReturn('/clients/7/dziennik')).toBe(false);
    });

    test('Starsze and the project rows keep 44 px targets on phones and their desktop size from 768 px', async () => {
        const css = await Bun.file(new URL('./client-journal.module.css', import.meta.url)).text();
        const phone = css.indexOf(
            '.olderNotes {\n    display: inline-flex;\n    align-items: center;\n    align-self: flex-start;\n    min-height: var(--solo-touch);',
        );
        const desktop = css.indexOf('.olderNotes {\n        display: inline;\n        min-height: auto;');

        expect(phone).toBeGreaterThan(-1);
        expect(desktop).toBeGreaterThan(-1);
        expect(css.lastIndexOf('@media (min-width: 768px)', desktop)).toBeGreaterThan(phone);
        expect(css).toMatch(/\.sheetItem \{\s*position: relative;/);
        expect(css).toMatch(/\.sheetProject::after \{\s*content: '';\s*position: absolute;\s*inset: 0;/);
    });
});
