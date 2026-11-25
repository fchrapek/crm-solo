import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';
import pl from '../../../../lang/pl.json';
import { Chip } from './chip';
import { SoloButton, Stat } from './primitives';
import { listDetails, Row, rowParts } from './row';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

describe('Row', () => {
    test('a row is its title and end meta in one line of markup, with no second text line', () => {
        const html = renderToStaticMarkup(<Row title="Aktualizacja cennika PDF" meta="Pracownia Mebli" />);

        expect(html).toMatch(/^<li[^>]*><span[^>]*>Aktualizacja cennika PDF<\/span><span[^>]*>Pracownia Mebli<\/span><\/li>$/);
    });

    test('a context line stands under the title in its own span and only when given; from 768 px it hides and the end meta says it', async () => {
        const html = renderToStaticMarkup(<Row title="Raport z audytu" wideMeta="Jan Przykładowy" context="Jan Przykładowy · Audyt · z pt." />);
        const plain = renderToStaticMarkup(<Row title="Raport z audytu" wideMeta="Jan Przykładowy" />);
        const css = await Bun.file(new URL('./row.module.css', import.meta.url)).text();
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));

        expect(html).toMatch(/<li[^>]*><span[^>]*><span[^>]*title="Raport z audytu">Raport z audytu<\/span><span[^>]*>Jan Przykładowy · Audyt · z pt\.<\/span><\/span>/);
        expect(plain).not.toContain('Audyt');
        expect(plain).toMatch(/^<li[^>]*><span[^>]*title="Raport z audytu">/);
        expect(css).toMatch(/\.context \{[^}]*overflow-wrap: anywhere;/);
        expect(wide).toMatch(/\.lead \{\s*display: contents;/);
        expect(wide).toMatch(/\.context \{\s*display: none;/);
    });

    test('a list row beside place meta keeps its title on one line from 768 px; only a side column row wraps it', async () => {
        const css = await Bun.file(new URL('./row.module.css', import.meta.url)).text();
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));

        expect(renderToStaticMarkup(<Row title="Raport" wideMeta="Jan" />)).not.toContain('data-wrap');
        expect(renderToStaticMarkup(<Row wrap title="Raport" />)).toContain('data-wrap=""');
        expect(wide).toMatch(/\.title \{\s*flex: 1 1 auto;\s*min-width: 34%;\s*overflow: hidden;\s*text-overflow: ellipsis;\s*white-space: nowrap;/);
        expect(css).not.toContain("data-wrap='title'");
    });

    test('the title leads somewhere only when the row is given a place to lead to', () => {
        expect(renderToStaticMarkup(<Row title="Zadanie" href="/tasks/4" />)).toContain('<a href="/tasks/4"');
        expect(renderToStaticMarkup(<Row title="Zadanie" />)).not.toContain('<a ');
    });

    test('the checkbox says what a click does and whether the task is ticked', () => {
        const open = renderToStaticMarkup(<Row title="Zadanie" check={{ done: false, label: 'Oznacz jako zrobione', onToggle: () => {} }} />);
        const done = renderToStaticMarkup(<Row title="Zadanie" check={{ done: true, label: 'Cofnij', onToggle: () => {} }} />);

        expect(open).toMatch(/<button[^>]*aria-label="Oznacz jako zrobione"[^>]*aria-pressed="false"/);
        expect(done).toMatch(/<button[^>]*aria-label="Cofnij"[^>]*aria-pressed="true"/);
        expect(open).not.toContain('disabled');
    });

    test('a tick that cannot change is a disabled checkbox, such as a card completed on its board', () => {
        expect(renderToStaticMarkup(<Row title="Zadanie" check={{ done: true, label: 'Zrobione' }} />)).toMatch(/<button[^>]*disabled=""/);
    });

    test('the keyboard or current row is marked for assistive tech as well as by colour', () => {
        const html = renderToStaticMarkup(<Row title="Pracownia Mebli" selected />);

        expect(html).toContain('data-selected=""');
        expect(html).toContain('aria-current="true"');
        expect(renderToStaticMarkup(<Row title="Pracownia Mebli" />)).not.toContain('aria-current');
    });

    test('time, meta, chip, action and the more menu follow the title in that order', () => {
        const html = renderToStaticMarkup(
            <Row title="T" time="1:12" timeTone="over" meta="M" chip={<Chip status="waiting">C</Chip>} action={<button>A</button>} more={<button>…</button>} />,
        );
        const order = ['>T<', '>1:12<', '>M<', 'C</span>', '>A<', '>…<'].map((part) => html.indexOf(part));

        expect(order.every((at) => at > -1)).toBe(true);
        expect([...order].sort((a, b) => a - b)).toEqual(order);
        expect(html).toContain('data-tone="over"');
    });
});

describe('Row details', () => {
    const details = [
        { label: 'Klient', value: 'Fundacja Testowa' },
        { label: 'Projekt', value: 'Nowa strona fundacji' },
        { label: 'Termin', value: '2.11', short: true },
        { label: 'Czas', value: null, short: true },
    ];
    const css = () => Bun.file(new URL('./row.module.css', import.meta.url)).text();

    test('a row with details starts closed: the chevron says it shows them, controls the folded pairs, and they are inert', () => {
        const html = renderToStaticMarkup(<Row title="Makiety" href="/tasks/4" details={details} />);
        const controls = html.match(/aria-controls="([^"]+)"/)?.[1];

        expect(html).toMatch(/<button type="button"[^>]*aria-expanded="false"[^>]*aria-label="Pokaż szczegóły: Makiety"/);
        expect(controls).toBeDefined();
        expect(html).toMatch(new RegExp(`<div id="${controls}"[^>]*data-closed="true" inert=""><div[^>]*><dl`));
        expect(html).toMatch(/^<li[^>]*><div[^>]*>/);
        expect(html).not.toMatch(/<li[^>]*>.*<li/);
    });

    test('an open row says Hide and its pairs are reachable', () => {
        const html = renderToStaticMarkup(<Row title="Makiety" details={details} detailsOpen />);

        expect(html).toContain('aria-expanded="true"');
        expect(html).toContain('aria-label="Ukryj szczegóły: Makiety"');
        expect(html).not.toContain('inert');
        expect(html).toMatch(/^<li[^>]*data-open="true"/);
    });

    test('the pairs are label over value in their order, each value cut in its column with its whole text as the tooltip, and a missing value is left out', () => {
        const html = renderToStaticMarkup(<Row title="Makiety" details={details} detailsOpen />);

        expect(html).toMatch(
            /<dt>Klient<\/dt><dd title="Fundacja Testowa">Fundacja Testowa<\/dd><\/div><div[^>]*><dt>Projekt<\/dt><dd title="Nowa strona fundacji">Nowa strona fundacji<\/dd><\/div><div[^>]*><dt>Termin<\/dt><dd title="2\.11">2\.11<\/dd><\/div><\/dl>/,
        );
        expect(html).not.toContain('Czas');
    });

    test('the details are one fixed grid for the row kind: every pair keeps its column whatever is missing before it, so the rows of a list line up', () => {
        const long = 'PRZEDSIĘBIORSTWO HANDLOWO-USŁUGOWE PRZYKŁADOWE S.C. ANNA TESTOWA-NOWAK, JAN, PIOTR TESTOWY';
        const full = renderToStaticMarkup(<Row title="Makiety" details={[{ ...details[0], value: long }, ...details.slice(1)]} detailsOpen />);
        const gap = renderToStaticMarkup(<Row title="Testy" details={[details[0], { label: 'Projekt', value: null }, details[2], { ...details[3], value: '1 h' }]} detailsOpen />);
        const tracks = '--detail-columns:var(--role-detail-text) var(--role-detail-text) var(--role-detail-short) var(--role-detail-short)';

        expect(full).toContain(`<dl style="${tracks}">`);
        expect(gap).toContain(`<dl style="${tracks}">`);
        expect(full).toContain(`<div style="--detail-column:1"><dt>Klient</dt><dd title="${long}">${long}</dd>`);
        expect(gap).not.toContain('Projekt');
        expect(gap).toContain('<div style="--detail-column:3"><dt>Termin</dt>');
        expect(gap).toContain('<div style="--detail-column:4"><dt>Czas</dt>');
    });

    test('the grid has two fixed columns on a phone and the row kind\'s tracks from 768 px, text shares with a floor and a cap, short values a narrow fixed track, every value cut with an ellipsis', async () => {
        const text = await css();
        const wide = text.slice(text.indexOf('@media (min-width: 768px)'));

        expect(text).toMatch(/\.details \{\s*--detail-columns: none;\s*display: grid;\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/);
        expect(await Bun.file(new URL('../../../css/solo.css', import.meta.url)).text()).toMatch(/--role-detail-text: minmax\(4rem, 15rem\);\s*--role-detail-short: 5\.5rem;/);
        expect(text).not.toMatch(/\.details \{[^}]*flex-wrap/);
        expect(text).toMatch(/\.detail\[data-wide\] \{\s*grid-column: 1 \/ -1;/);
        expect(text).toMatch(/\.detail dd \{[^}]*overflow: hidden;[^}]*text-overflow: ellipsis;\s*white-space: nowrap;/);
        expect(text).toMatch(/\.detail\[data-wide\] dd \{\s*overflow-wrap: anywhere;\s*white-space: pre-line;/);
        expect(wide).toMatch(/\.details \{\s*grid-template-columns: var\(--detail-columns\);\s*\}\s*\.detail \{\s*grid-column: var\(--detail-column\);/);
    });

    test('the details read a step under the title: values at the end meta\'s size, labels at the small label\'s, both on whole-pixel lines and close together', async () => {
        const text = await css();

        expect(text).toMatch(/\.row \{[^}]*\}[\s\S]*\.title \{[^}]*font: 600 var\(--solo-text-row\)/);
        expect(text).toMatch(/\.detail dt \{\s*color: var\(--role-text-secondary\);\s*font: 400 var\(--solo-text-label\) \/ 16px var\(--solo-font-text\);/);
        expect(text).toMatch(/\.detail dd \{[^}]*color: var\(--role-text-primary\);\s*font: 400 var\(--solo-text-meta\) \/ 18px var\(--solo-font-text\);/);
        expect(text).toMatch(/\.details \{[^}]*gap: var\(--role-space-8\) var\(--role-space-24\);/);
        expect(text).not.toMatch(/\.detail \{[^}]*gap:/);
        expect(text).not.toMatch(/\.detail (dt|dd) \{[^}]*--solo-text-sub/);
    });

    test('a long text pair takes the whole line, uncut and without a tooltip, on every width', () => {
        const html = renderToStaticMarkup(<Row title="Telefon" details={[{ label: 'Autor', value: 'Filip' }, { label: 'Tekst', value: 'Pierwsza linia\nDruga', wide: true }]} detailsOpen />);

        expect(html).toContain('<dl style="--detail-columns:var(--role-detail-text)">');
        expect(html).toContain('<div data-wide="true"><dt>Tekst</dt><dd>Pierwsza linia\nDruga</dd></div>');
    });

    test('a row with details has no hover band; its title and its chevron show a hover, the chevron the quiet disc of the icon buttons around its glyph', async () => {
        const text = await css();

        const hovers = [...text.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{[^{}]*\}/g)].flatMap(([, selectors]) => selectors.split(',').map((selector) => selector.trim())).filter((selector) => selector.includes(':hover'));

        expect(hovers.sort()).toEqual(['.row:not([data-selected]) .link:hover', '.toggle:hover::before']);
        expect(text).toMatch(/\.toggle::before \{[^}]*width: 32px;\s*height: 32px;\s*border-radius: var\(--role-radius-full\);/);
        expect(text).toMatch(/@media \(hover: hover\) \{\s*\.toggle:hover::before \{\s*background: var\(--role-hover\);\s*\}\s*\}/);
    });

    test('a title that is a link is never underlined; on hover it takes the secondary ink, except on the selected row, and it keeps the focus ring', async () => {
        const text = await css();

        expect(text).not.toContain('underline');
        expect(text).toMatch(/@media \(hover: hover\) \{\s*\.row:not\(\[data-selected\]\) \.link:hover \{\s*color: var\(--role-text-secondary\);\s*\}\s*\}/);
        expect(text).toMatch(/\.link:focus-visible,\s*\.check:focus-visible \{\s*outline: var\(--role-stroke-focus\) solid var\(--role-focus-ring\);/);
    });

    test('a row whose details are all missing has no chevron and stays a plain row', () => {
        const html = renderToStaticMarkup(<Row title="Makiety" details={[{ label: 'Czas', value: null }]} />);

        expect(html).not.toContain('aria-expanded');
        expect(html).not.toContain('<dl');
    });

    test("the selected row's link draws its ring in the selection's text colour, which its fill is not", async () => {
        expect(await css()).toMatch(/\.row\[data-selected\] \.link:focus-visible \{\s*outline-color: var\(--role-selection-text\);/);
    });

    test('five columns fit the narrowest two-column screen: 64 px text tracks, 88 px short ones and 24 px gaps in 508 px', async () => {
        const tokens = await Bun.file(new URL('../../../css/solo.css', import.meta.url)).text();
        const text = Number(tokens.match(/--role-detail-text: minmax\((\d+)rem/)?.[1]) * 16;
        const short = Number(tokens.match(/--role-detail-short: ([\d.]+)rem/)?.[1]) * 16;

        expect(2 * text + 3 * short + 4 * 24).toBeLessThanOrEqual(508);
    });

    test('no arrow follows a title, with a page behind it or without one', () => {
        const linked = renderToStaticMarkup(<Row title="Makiety" href="/tasks/4" details={details} />);
        const note = renderToStaticMarkup(<Row title="Telefon: migracja" details={details} />);

        expect(linked).toMatch(/<a data-cut="true" href="\/tasks\/4"[^>]*><span[^>]*>Makiety<\/span><\/a>/);
        expect(note).not.toContain('<a ');
        for (const html of [linked, note, renderToStaticMarkup(<Row title="Zadanie" href="/tasks/4" />)]) expect(html).not.toContain('arrow-up-right');
    });

    test('the title, the chevron and the end action are three separate controls, the chevron before the action and after the time', () => {
        const html = renderToStaticMarkup(
            <Row title="Makiety" href="/tasks/4" time="45 min" details={details} action={<SoloButton variant="quiet">Na jutro</SoloButton>} more={<button>…</button>} />,
        );
        const order = ['<a ', '>45 min<', 'aria-expanded', '>Na jutro<', '>…<'].map((part) => html.indexOf(part));

        expect(order.every((at) => at > -1)).toBe(true);
        expect([...order].sort((a, b) => a - b)).toEqual(order);
        expect(html).toMatch(/<button[^>]*data-look="quiet"[^>]*>Na jutro<\/button>/);
    });

    test('a running timer shows the dot and the live time in the time slot, in the over tone, instead of a logged time', () => {
        const html = renderToStaticMarkup(<Row title="BLIK" time="45 min" running={new Date().toISOString()} details={details} />);

        expect(html).toMatch(/<span[^>]*data-tone="over" data-running=""><span[^>]*aria-hidden="true"><\/span><span aria-label="0 h 0 min na zegarze">0:00<\/span><\/span>/);
        expect(html).not.toContain('45 min');
    });

    test('a row has no quiet date at rest: a row kind that dates its rows puts the date in its details', async () => {
        const html = renderToStaticMarkup(<Row title="Poprawki" details={[{ label: 'Data', value: '23.11', short: true }]} />);

        expect(await css()).not.toContain('.stamp');
        expect(html.indexOf('>23.11<')).toBeGreaterThan(html.indexOf('<dl'));
    });

    test('on a phone the shown pairs flow two to a line with no cell for a missing value; from 768 px each keeps its column', async () => {
        const text = await css();
        const wide = text.slice(text.indexOf('@media (min-width: 768px)'));
        const phone = text.slice(0, text.indexOf('@media (min-width: 768px)'));

        expect(phone).toMatch(/\.detail \{\s*--detail-column: auto;\s*display: flex;\s*flex-direction: column;\s*min-width: 0;\s*\}/);
        expect(phone).not.toMatch(/\.detail \{[^}]*grid-column/);
        expect(wide).toMatch(/\.detail \{\s*grid-column: var\(--detail-column\);/);
        expect(text).not.toContain('data-only');
    });

    test('a list leaves out the columns none of its rows has a value for, and keeps a column any row has for every row', () => {
        const rows = listDetails([
            [details[0], { ...details[2], value: null }, { ...details[3], value: '1 h' }],
            [details[0], { ...details[2], value: null }, { ...details[3], value: null }],
        ]);

        expect(rows.map((row) => row.map((detail) => detail.label))).toEqual([
            ['Klient', 'Czas'],
            ['Klient', 'Czas'],
        ]);
        expect(renderToStaticMarkup(<Row title="T" details={rows[1]} detailsOpen />)).toContain('<dl style="--detail-columns:var(--role-detail-text) var(--role-detail-short)">');
    });

    test('a row with details keeps its title to two lines on a phone, clamped inside the link so its ring is whole; one line from 768 px; other rows unchanged', async () => {
        const text = await css();
        const wide = text.slice(text.indexOf('@media (min-width: 768px)'));
        const long = 'Przygotowanie kompletnej dokumentacji wdrożeniowej dla nowego sklepu internetowego z integracją płatności';
        const html = renderToStaticMarkup(<Row title={long} href="/tasks/4" details={details} />);

        expect(text).toMatch(
            /\.item \.title:not\(:has\(> \.link\[data-cut\]\)\),\s*\.item \.titleText \{\s*display: -webkit-box;\s*-webkit-box-orient: vertical;\s*-webkit-line-clamp: 2;\s*line-clamp: 2;\s*min-width: 0;\s*overflow: hidden;/,
        );
        expect(text).toMatch(/\.link\[data-cut\] \{\s*display: inline-flex;\s*max-width: 100%;/);
        expect(wide).toMatch(/\.item \.titleText \{\s*display: block;\s*-webkit-line-clamp: none;\s*line-clamp: none;/);
        expect(text).toMatch(/\.title \{\s*flex: 1 1 0;\s*min-width: 0;\s*font: [^;]+;\s*overflow-wrap: anywhere;\s*\}/);
        expect(html).toContain(`<span title="${long}"><a data-cut="true" href="/tasks/4"><span>${long}</span>`);
    });

    test("the more button keeps clear of the timer's overhang on a phone, and the chevron's ring is its 32 px disc", async () => {
        const text = await css();
        const wide = text.slice(text.indexOf('@media (min-width: 768px)'));

        expect(text).toMatch(/\[data-size='row'\] \+ \.more \{\s*margin-inline-start: calc\(var\(--row-gap-more\) - var\(--row-gap\) \+ 6px\);/);
        expect(wide).toMatch(/\[data-size='row'\] \+ \.more \{\s*margin-inline-start: calc\(var\(--row-gap-more\) - var\(--row-gap\)\);/);
        expect(text).toMatch(/\.toggle:focus-visible::before \{\s*outline: var\(--role-stroke-focus\)/);
    });

    test('a logged time beside the running place reads in the secondary ink', async () => {
        expect(renderToStaticMarkup(<Row title="T" time="45 min" timeTone="quiet" />)).toContain('data-tone="quiet"');
        expect(await css()).toMatch(/\.time\[data-tone='quiet'\] \{\s*color: var\(--role-text-secondary\);/);
    });

    test('the pairs fold in the shared fold, and after a checkbox they start where the title does', async () => {
        const text = await css();

        expect(text).toMatch(/\.details\[data-checked\] \{\s*padding-inline-start: calc\(44px - var\(--role-space-12\) \+ var\(--row-gap-near\)\);/);
        expect(renderToStaticMarkup(<Row title="T" check={{ done: false, label: 'Zrób' }} details={details} />)).toContain('data-checked=""');
        expect(renderToStaticMarkup(<Row title="T" details={details} />)).toMatch(/<div id="[^"]+-details"[^>]*data-fold="" data-closed="true" inert="">/);
    });

    test('the chevron is a 44 px target on a phone and 32 px from 768 px, with the 24 px gaps measured between visible edges', async () => {
        const text = await css();
        const wide = text.slice(text.indexOf('@media (min-width: 768px)'));

        expect(text).toMatch(/\.toggle \{[^}]*width: 44px;\s*height: 44px;\s*margin-inline: calc\(var\(--role-space-24\) - var\(--row-gap\) - 23px\) calc\(var\(--role-space-24\) - var\(--row-gap\) - 11px\);/);
        expect(wide).toMatch(/\.toggle \{\s*width: 32px;\s*height: 32px;\s*margin-inline: calc\(var\(--role-space-24\) - var\(--row-gap\) - 11px\);/);
        expect(text).toMatch(/\.toggle \+ \[data-look='quiet'\] \{\s*margin-inline-start: calc\(-1 \* var\(--role-control-inset\)\);/);
    });
});

describe('Stat in a tile', () => {
    test('the caps label comes before the figure and the caption after it', () => {
        const html = renderToStaticMarkup(<Stat kind="tile" label="Październik" value="18 z 22" sub="dni zamkniętych" />);

        expect(html.indexOf('Październik')).toBeLessThan(html.indexOf('18 z 22'));
        expect(html.indexOf('18 z 22')).toBeLessThan(html.indexOf('dni zamkniętych'));
    });

    test('a figure past its limit and a figure on the timer tile carry their tone', () => {
        expect(renderToStaticMarkup(<Stat kind="tile" tone="over" label="Godziny" value="36,8 h" />)).toContain('data-tone="over"');
        expect(renderToStaticMarkup(<Stat kind="tile" tone="on-colour" label="Timer" value="1:12" />)).toContain('data-tone="on-colour"');
    });

    test('the earlier stat keeps its figure above its label', () => {
        const html = renderToStaticMarkup(<Stat value="2 h 52" label="zalogowane" />);

        expect(html.indexOf('2 h 52')).toBeLessThan(html.indexOf('zalogowane'));
    });

    test('an open task being worked on shows the half-filled mark, and a done row keeps its title in ink', async () => {
        const mark = (check: { done: boolean; progress?: boolean }) => renderToStaticMarkup(<Row title="Zadanie" check={{ ...check, label: 'Zadanie' }} />);

        expect(mark({ done: false, progress: true })).toContain('data-progress');
        expect(mark({ done: false })).not.toContain('data-progress');
        expect(mark({ done: true, progress: true })).not.toContain('data-progress');
        expect(await Bun.file(new URL('./row.module.css', import.meta.url)).text()).not.toMatch(/\.done \.title/);
    });

    test('a text title carries itself as the native tooltip, so a cut name can still be read', () => {
        expect(renderToStaticMarkup(<Row title="Pracownia Mebli Przykładowa" />)).toContain('title="Pracownia Mebli Przykładowa"');
    });

    test('every text a row may cut carries its whole value as the tooltip, read from a node as well as a string', () => {
        const html = renderToStaticMarkup(<Row title={<b>Pracownia Mebli</b>} time="1 h 20 min" meta={<span>4 wolne</span>} wideMeta={rowParts(['Pracownia Mebli', 'Sklep'])} />);

        expect(html).toMatch(/<span[^>]*title="Pracownia Mebli"><b>/);
        expect(html).toMatch(/<span[^>]*title="1 h 20 min">1 h 20 min<\/span>/);
        expect(html).toMatch(/<span[^>]*title="4 wolne"><span>4 wolne<\/span><\/span>/);
        expect(html).toMatch(/<span[^>]*title="Pracownia Mebli · Sklep">/);
    });

    test('a component inside the title adds nothing to the tooltip, so the text of a hint never leaks into it', () => {
        const Hint = ({ children }: { children: string }) => <button aria-label={children} />;
        const html = renderToStaticMarkup(
            <Row
                title={
                    <>
                        Nieudany SMS <Hint>Invalid phone number</Hint>
                    </>
                }
            />,
        );

        expect(html).toContain('title="Nieudany SMS"');
        expect(html).not.toContain('title="Nieudany SMS Invalid');
    });

    test('client and project are two parts of the meta, each cut on its own, and one name stays plain text', () => {
        const html = renderToStaticMarkup(<>{rowParts(['Pracownia Mebli', null, 'Sklep'])}</>);

        expect(html).toMatch(/^<span[^>]*><span[^>]*>Pracownia Mebli<\/span><span[^>]*> · <\/span><span[^>]*>Sklep<\/span><\/span>$/);
        expect(rowParts(['Pracownia Mebli', null])).toBe('Pracownia Mebli');
        expect(rowParts([null, ''])).toBeUndefined();
    });

    test('from 768 px the title beside end meta takes half the row at most, the meta the rest, and the meta gives way first', async () => {
        const css = await Bun.file(new URL('./row.module.css', import.meta.url)).text();
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));

        expect(wide).toMatch(/\.row:not\(\[data-wrap\]\):has\(> \.meta\) \.title \{\s*flex: 0 1 auto;\s*min-width: 0;\s*max-width: 50%;/);
        expect(wide).toMatch(/\.row:not\(\[data-wrap\]\):has\(> \.meta\) \.title \+ \* \{\s*margin-inline-start: auto;/);
        expect(wide).toMatch(/\.meta \{\s*flex: 0 100000 auto;\s*min-width: 5ch;/);
        expect(wide).toMatch(/\.row\[data-wrap\] \.meta \{\s*flex: none;/);
        expect(css).toMatch(/\.parts \{[^}]*grid-auto-columns: minmax\(0, max-content\);/);
    });

    test('the mark stands one spacing step closer than the rest of the row and the more button two, their targets unchanged', async () => {
        const css = await Bun.file(new URL('./row.module.css', import.meta.url)).text();
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));

        expect(css).toMatch(/--row-gap: var\(--role-space-12\);\s*--row-gap-near: var\(--role-space-8\);\s*--row-gap-more: var\(--role-space-4\);/);
        expect(wide).toMatch(/--row-gap: var\(--role-space-16\);\s*--row-gap-near: var\(--role-space-12\);\s*--row-gap-more: var\(--role-space-8\);/);
        expect(css).toMatch(/\.check \{[^}]*width: 44px;\s*height: 44px;\s*margin: 0 calc\(var\(--row-gap-near\) - var\(--row-gap\) - var\(--role-space-12\)\) 0 calc\(-1 \* var\(--role-space-12\)\);/);
        expect(css).toMatch(/\.more \{[^}]*margin-inline-start: calc\(var\(--row-gap-more\) - var\(--row-gap\)\);/);
        expect(renderToStaticMarkup(<Row title="T" more={<button>…</button>} />)).toMatch(/<span[^>]*><button>…<\/button><\/span><\/li>$/);
    });
});
