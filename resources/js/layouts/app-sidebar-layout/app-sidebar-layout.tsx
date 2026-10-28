import { ReactNode } from 'react';

import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { SoloSurface } from '@/components/solo/surface';
import { TabBar } from '@/components/solo/tab-bar';
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

            <SoloSurface day="paper" className={styles.tabBar}>
                <TabBar />
            </SoloSurface>
        </AppShell>
    );
}
