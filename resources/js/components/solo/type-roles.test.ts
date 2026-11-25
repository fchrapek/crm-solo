import { describe, expect, test } from 'bun:test';
import { Glob } from 'bun';

const root = new URL('../../../', import.meta.url).pathname;

async function sheets(): Promise<[string, string][]> {
    const files = [...new Glob('{js,css}/**/*.css').scanSync(root)];
    return Promise.all(files.map(async (file) => [file, await Bun.file(root + file).text()] as [string, string]));
}

describe('type roles', () => {
    test('only the poster is set in capitals', async () => {
        const caps = (await sheets()).filter(([, css]) => css.includes('text-transform: uppercase')).map(([file]) => file);

        expect(caps.sort()).toEqual(['js/components/solo/client-header.module.css', 'js/components/solo/primitives.module.css']);
    });

    test('no sheet names a mono face, code and token fields included', async () => {
        const all = await sheets();

        expect(all.filter(([, css]) => /Geist Mono|Spline Sans Mono|--solo-font-mono|--solo-mono-/.test(css)).map(([file]) => file)).toEqual([]);
        expect(all.filter(([, css]) => /monospace|font-family-mono/.test(css)).map(([file]) => file)).toEqual([]);
    });

    test('the text face has two weights, 400 and 600', async () => {
        const odd = (await sheets()).filter(([file, css]) => file.startsWith('js/') && /font(-weight)?:\s*(500|700)\b/.test(css)).map(([file]) => file);

        expect(odd).toEqual([]);
    });
});
