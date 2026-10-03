import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import React from 'react';

export interface PageModule {
    default: React.ComponentType & {
        layout?: (page: React.ReactNode) => React.ReactNode;
    };
}

export function applyLayoutToPage(module: unknown, pageName: string): void {
    const isAuthRoute =
        pageName.toLowerCase().includes('login') ||
        pageName.toLowerCase().includes('register') ||
        pageName.toLowerCase().includes('password') ||
        pageName.toLowerCase().includes('verification');

    // Solo pages draw their own masthead and colour, and the error page is shown to guests while the app shell needs a signed-in user.
    const isBarePage = pageName.startsWith('today/') || pageName === 'auth/login' || pageName === 'auth/czesc' || pageName === 'error';

    (module as PageModule).default.layout = (page: React.ReactNode) => {
        if (isBarePage) {
            return page;
        }

        if (isAuthRoute) {
            return <AuthLayout>{page}</AuthLayout>;
        }

        return <AppLayout>{page}</AppLayout>;
    };
}
