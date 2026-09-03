import { ReactNode } from 'react';

import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { TestAccountBanner } from '@/components/test-account-banner';
import { SidebarInset } from '@/components/ui/sidebar';

import styles from './app-sidebar-layout.module.css';

export default function AppSidebarLayout({ children }: { children: ReactNode }) {
    return (
        <AppShell>
            <AppSidebar />

            <SidebarInset>
                <TestAccountBanner />
                <AppSidebarHeader />
                <div className={styles.content}>{children}</div>
            </SidebarInset>
        </AppShell>
    );
}
