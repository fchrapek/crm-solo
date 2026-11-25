import { describe, expect, test } from 'bun:test';
import { readFileSync } from 'node:fs';
import { renderToStaticMarkup } from 'react-dom/server';

import { ListTableCell } from './list-table';

const read = (page: string) => readFileSync(`${import.meta.dir}/../../pages/${page}`, 'utf8');
const pages = ['clients/index.tsx', 'leads/index.tsx'].map(read);

describe('a list table title', () => {
    test('a truncating name sits in a box that gives up its width, so a long name never widens the table past its tile', () => {
        const css = readFileSync(`${import.meta.dir}/list-table.module.css`, 'utf8');
        const html = renderToStaticMarkup(
            <table>
                <tbody>
                    <tr>
                        <ListTableCell kind="title" truncate>
                            <a href="/clients/7">Pracownia Mebli</a>
                        </ListTableCell>
                        <ListTableCell kind="title">Październik 2026</ListTableCell>
                    </tr>
                </tbody>
            </table>,
        );

        expect(html).toMatch(/<td data-kind="title"[^>]*><div[^>]*><a href="\/clients\/7">Pracownia Mebli<\/a><\/div><\/td>/);
        expect(html).toMatch(/<td data-kind="title"[^>]*>Październik 2026<\/td>/);
        expect(css.match(/\.truncate \{([^}]*)\}/)?.[1]).toContain('contain: inline-size');
    });

    test('the clients and leads lists cut their names; contacts, with three columns, show every name whole', () => {
        for (const page of pages) {
            expect(page).toContain('<ListTableCell kind="title" truncate>');
        }
        expect(read('contacts/index.tsx')).not.toContain('<ListTableCell kind="title" truncate>');
    });
});
