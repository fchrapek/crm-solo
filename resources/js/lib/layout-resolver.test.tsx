import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

import * as shellLayout from '@/layouts/shell-layout';

spyOn(shellLayout, 'default').mockImplementation((({ children, place }: { children: React.ReactNode; place?: () => unknown }) => (
    <div data-shell="" data-place={place ? 'own' : undefined}>
        {children}
    </div>
)) as unknown as typeof shellLayout.default);
afterAll(() => mock.restore());

const { applyLayoutToPage } = await import('./layout-resolver');

type TestPage = React.ComponentType & { layout?: (page: React.ReactNode) => React.ReactNode; place?: () => { tokens: [] } };

function renderWithLayout(pageName: string, place?: TestPage['place']): string {
    const Page: TestPage = () => <main />;
    Page.place = place;
    applyLayoutToPage({ default: Page }, pageName);

    return renderToStaticMarkup(<>{Page.layout!(<Page />)}</>);
}

describe('applyLayoutToPage', () => {
    test('every page but the two bare ones renders inside the shell, the error page too: the shell shows a guest the page alone', () => {
        for (const page of ['today/index', 'clients/show', 'tasks/show', 'contacts/index', 'settings/index', 'leads/index', 'leads/edit', 'messages/index', 'error']) {
            expect(renderWithLayout(page)).toBe('<div data-shell=""><main></main></div>');
        }
    });

    test("the shell is handed the page's own place", () => {
        expect(renderWithLayout('today/index', () => ({ tokens: [] }))).toContain('data-place="own"');
    });

    test('the login and splash pages stay bare', () => {
        expect(renderWithLayout('auth/login')).toBe('<main></main>');
        expect(renderWithLayout('auth/waitlist')).toBe('<main></main>');
    });
});
