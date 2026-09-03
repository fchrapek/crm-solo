import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';

import styles from './app-sidebar-header.module.css';

export function AppSidebarHeader() {
    return (
        <header className={styles.header}>
            <div className={styles.inner}>
                <SidebarTrigger className={styles.trigger} />
                <Breadcrumbs />
            </div>
        </header>
    );
}
