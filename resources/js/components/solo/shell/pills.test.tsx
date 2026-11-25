import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import type { ReactElement, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

import * as menu from '@/components/ui/dropdown-menu';

// Radix renders a closed menu's content nowhere on the server, so while these tests run the content renders in place.
spyOn(menu, 'DropdownMenuPortal').mockImplementation(() => <></>);
spyOn(menu, 'DropdownMenuContent').mockImplementation(({ children }) => <div data-test="menu">{children as ReactNode}</div>);
spyOn(menu, 'DropdownMenuItem').mockImplementation(({ asChild, children }) => (asChild ? (children as ReactElement) : <div>{children as ReactNode}</div>));
spyOn(menu, 'DropdownMenuLabel').mockImplementation(({ children, className }) => <p className={className}>{children as ReactNode}</p>);
spyOn(menu, 'DropdownMenuGroup').mockImplementation(({ children, ...props }) => (
    <div role="group" aria-label={props['aria-label']}>
        {children as ReactNode}
    </div>
));

const { MenuPill } = await import('./pills');

afterAll(() => mock.restore());

const text = (html: string) => html.replace(/<[^>]+>/g, '|').replace(/\|+/g, '|');

describe('MenuPill', () => {
    test('a heading tops the menu and each run of choices sits in a group named by its group', () => {
        const html = renderToStaticMarkup(
            <MenuPill
                label="Starsze"
                heading="Starsze miesiące"
                menu={[
                    { label: 'Lipiec', href: '/m/2026-07', group: '2026' },
                    { label: 'Czerwiec', href: '/m/2026-06', group: '2026', active: true },
                    { label: 'Grudzień', href: '/m/2025-12', group: '2025' },
                ]}
            />,
        );

        expect(text(html)).toContain('|Starsze miesiące|2026|Lipiec|Czerwiec|2025|Grudzień|');
        expect(html.match(/role="group"/g)).toHaveLength(2);
        expect(html).toContain('aria-label="2026"');
        expect(html).toMatch(/<a[^>]*aria-current="true"[^>]*href="\/m\/2026-06"|<a[^>]*href="\/m\/2026-06"[^>]*aria-current="true"/);
    });

    test('choices without a group stay a plain list under no heading', () => {
        const html = renderToStaticMarkup(<MenuPill label="Współpraca: wszyscy" menu={[{ label: 'Wszyscy', href: '/clients', count: 7 }]} />);

        expect(html).not.toContain('role="group"');
        expect(text(html)).toContain('|Wszyscy · 7|');
    });
});
