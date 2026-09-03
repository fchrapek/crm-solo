import { ReactNode, useCallback, useEffect, useState } from 'react';

import styles from './tabs.module.css';

interface Tab {
    id: string;
    label: string;
    content: ReactNode;
    /**
     * No button in the tab row - the panel stays addressable via hash and an
     * external trigger (e.g. the client header's settings gear).
     */
    hidden?: boolean;
}

interface TabsProps {
    tabs: Tab[];
    defaultTab?: string;
    /**
     * When true, the active tab id is reflected in the URL hash and
     * survives page refresh + browser back/forward. Defaults to true.
     */
    persistInUrl?: boolean;
}

const readHashTab = (validIds: string[]): string | null => {
    if (typeof window === 'undefined') return null;
    const hash = window.location.hash.replace(/^#/, '');
    return validIds.includes(hash) ? hash : null;
};

export function Tabs({ tabs, defaultTab, persistInUrl = true }: TabsProps) {
    const validIds = tabs.map((t) => t.id);
    const initial = (persistInUrl && readHashTab(validIds)) || defaultTab || tabs[0]?.id;
    const [activeTab, setActiveTab] = useState(initial);

    useEffect(() => {
        if (!persistInUrl) return;
        const onHashChange = () => {
            const fromHash = readHashTab(validIds);
            if (fromHash) setActiveTab(fromHash);
        };
        window.addEventListener('hashchange', onHashChange);
        return () => window.removeEventListener('hashchange', onHashChange);
    }, [persistInUrl, validIds]);

    const handleSelect = useCallback(
        (id: string) => {
            setActiveTab(id);
            if (persistInUrl && typeof window !== 'undefined') {
                // Use replaceState so the hash change doesn't clutter browser history
                // for every click - but back/forward across full navigations still work.
                history.replaceState(null, '', `#${id}`);
            }
        },
        [persistInUrl],
    );

    return (
        <>
            <div className={styles.tabList} role="tablist">
                {tabs
                    .filter((tab) => !tab.hidden)
                    .map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            role="tab"
                            aria-selected={activeTab === tab.id}
                            className={`${styles.tab} ${activeTab === tab.id ? styles.tabActive : ''}`}
                            onClick={() => handleSelect(tab.id)}
                        >
                            {tab.label}
                        </button>
                    ))}
            </div>
            {tabs.map((tab) => (
                <div
                    key={tab.id}
                    role="tabpanel"
                    style={{ display: activeTab === tab.id ? 'block' : 'none' }}
                >
                    {tab.content}
                </div>
            ))}
        </>
    );
}
