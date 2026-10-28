import { describe, expect, test } from 'bun:test';
import { Glob } from 'bun';
import { renderToStaticMarkup } from 'react-dom/server';

import { MarkdownView } from './markdown-view';

const checkboxes = (html: string) => [...html.matchAll(/<input[^>]*type="checkbox"[^>]*>/g)].map((m) => m[0]);

describe('MarkdownView', () => {
    test('a GitHub task list renders as read-only checkboxes, ticked where the source says [x]', () => {
        const html = renderToStaticMarkup(<MarkdownView>{'- [x] szkielet formularza\n- [ ] walidacja pól'}</MarkdownView>);
        const boxes = checkboxes(html);

        expect(html).not.toContain('[x]');
        expect(html).not.toContain('[ ]');
        expect(boxes).toHaveLength(2);
        expect(boxes.every((box) => box.includes('disabled'))).toBe(true);
        expect(boxes[0]).toContain('checked');
        expect(boxes[1]).not.toContain('checked');
        expect(html).toContain('szkielet formularza');
        expect(html).toContain('walidacja pól');
    });

    test('a loose task list wraps its checkboxes in paragraphs, and the checkbox look reaches them there too', async () => {
        const html = renderToStaticMarkup(<MarkdownView>{'- [x] szkielet\n\n- [ ] walidacja'}</MarkdownView>);
        const css = await Bun.file(new URL('./markdown-view.module.css', import.meta.url)).text();

        expect(html).toMatch(/<li class="task-list-item">\s*<p><input/);
        expect(css).not.toMatch(/\.task-list-item > input/);
        expect(css).toContain(".view :global(.task-list-item input[type='checkbox']) {");
    });

    test('in a mixed list only the task items drop their bullets', async () => {
        const html = renderToStaticMarkup(<MarkdownView>{'- zwykły punkt\n- [x] zadanie'}</MarkdownView>);
        const css = await Bun.file(new URL('./markdown-view.module.css', import.meta.url)).text();
        const list = css.match(/\.view :global\(\.contains-task-list[^)]*\)+ \{([^}]*)\}/)?.[1] ?? '';

        expect(html).toContain('<ul class="contains-task-list">');
        expect(html).toContain('<li>zwykły punkt</li>');
        expect(list).not.toContain('list-style');
        expect(css).toMatch(/\.view :global\(\.task-list-item\) \{[^}]*list-style: none;/);
    });

    test('raw HTML in the source is never rendered', () => {
        const html = renderToStaticMarkup(<MarkdownView>{'Tekst <img src=x onerror=alert(1)> i <b>pogrubienie</b>'}</MarkdownView>);

        expect(html).not.toContain('<img');
        expect(html).not.toContain('<b>');
    });

    test('every Markdown surface renders through MarkdownView, so none of them shows a task list as text', async () => {
        const root = new URL('../../', import.meta.url).pathname;
        const direct: string[] = [];
        for await (const file of new Glob('**/*.tsx').scan(root)) {
            if (file.startsWith('components/markdown-view/')) continue;
            if (/^import (?!type )[^;]*from 'react-markdown'/m.test(await Bun.file(root + file).text())) direct.push(file);
        }

        expect(direct).toEqual([]);
    });
});
