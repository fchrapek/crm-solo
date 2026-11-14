import { type PageWithPlace } from '@/components/solo/shell/place';
import ShellLayout from '@/layouts/shell-layout';
import React from 'react';

export interface PageModule {
    default: PageWithPlace & {
        layout?: (page: React.ReactNode) => React.ReactNode;
    };
}

/** Login and the waitlist splash stand alone; every other page goes through the shell, which shows a guest the page alone. */
const isBarePage = (pageName: string) => pageName === 'auth/login' || pageName === 'auth/czesc';

export function applyLayoutToPage(module: unknown, pageName: string): void {
    const page = (module as PageModule).default;

    page.layout = (content: React.ReactNode) => (isBarePage(pageName) ? content : <ShellLayout place={page.place}>{content}</ShellLayout>);
}
