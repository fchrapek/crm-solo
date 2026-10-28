import { describe, expect, mock, test } from 'bun:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

mock.module('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-shell="app">{children}</div>,
}));
mock.module('@/layouts/auth-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-shell="auth">{children}</div>,
}));

const { applyLayoutToPage } = await import('./layout-resolver');

function renderWithLayout(pageName: string): string {
    const Page: React.ComponentType & { layout?: (page: React.ReactNode) => React.ReactNode } = () => <main />;
    applyLayoutToPage({ default: Page }, pageName);

    return renderToStaticMarkup(<>{Page.layout!(<Page />)}</>);
}

describe('applyLayoutToPage', () => {
    test('the error page renders without any shell, so guests can see it', () => {
        expect(renderWithLayout('error')).toBe('<main></main>');
    });

    test('an app page gets the app shell', () => {
        expect(renderWithLayout('revenue/index')).toBe('<div data-shell="app"><main></main></div>');
    });

    test('Ustawienia and Kontakty draw their own Solo shell', () => {
        expect(renderWithLayout('settings/index')).toBe('<main></main>');
        expect(renderWithLayout('contacts/index')).toBe('<main></main>');
        expect(renderWithLayout('messages/index')).toBe('<main></main>');
    });

    test('the client record draws its own Solo shell', () => {
        expect(renderWithLayout('clients/show')).toBe('<main></main>');
    });

    test('the client list draws its own Solo shell while the leads list keeps the app shell', () => {
        expect(renderWithLayout('clients/index')).toBe('<main></main>');
        expect(renderWithLayout('leads/index')).toBe('<div data-shell="app"><main></main></div>');
    });

    test('the solo login and splash pages draw their own shell', () => {
        expect(renderWithLayout('auth/login')).toBe('<main></main>');
        expect(renderWithLayout('auth/czesc')).toBe('<main></main>');
    });

    test('other auth pages get the auth shell', () => {
        expect(renderWithLayout('auth/forgot-password')).toBe('<div data-shell="auth"><main></main></div>');
    });
});
