import FlashMessages from '@/components/flash-messages';
import { ReverbNotificationListener } from '@/components/reverb-notification-listener';
import { SessionAttentionListener } from '@/components/session-attention-listener';
import { ReverbExampleNotificationProvider } from '@/contexts/reverb-context';
import AppSidebarLayout from '@/layouts/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default function AppLayout({ children }: AppLayoutProps) {
    return (
        <AppSidebarLayout>
            <ReverbExampleNotificationProvider>
                {children}
                <FlashMessages />
                <ReverbNotificationListener />
                <SessionAttentionListener />
            </ReverbExampleNotificationProvider>
        </AppSidebarLayout>
    );
}
