import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { Chip } from './chip';
import { Stat } from './primitives';
import { Row } from './row';

describe('Row', () => {
    test('a row is its title and end meta in one line of markup, with no second text line', () => {
        const html = renderToStaticMarkup(<Row title="Aktualizacja cennika PDF" meta="Pracownia Mebli" />);

        expect(html).toMatch(/^<li[^>]*><span[^>]*>Aktualizacja cennika PDF<\/span><span[^>]*>Pracownia Mebli<\/span><\/li>$/);
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

    test('on one line the meta is cut before the title, and a wrapping title keeps its meta whole', async () => {
        const css = await Bun.file(new URL('./row.module.css', import.meta.url)).text();
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));

        expect(wide).toMatch(/\.meta \{\s*flex: 0 100000 auto;\s*min-width: 5ch;/);
        expect(wide).toMatch(/\.title \{\s*flex: 1 1 auto;/);
        expect(wide).toMatch(/\.row\[data-wrap\] \.meta \{\s*flex: none;/);
    });
});
