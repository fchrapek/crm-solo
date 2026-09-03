import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Bot } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { AgentKanban, type AgentKanbanTask } from '@/components/agent-kanban';
import { usePageActions } from '@/contexts/page-context';
import clients from '@/routes/clients';
import { BreadcrumbItem, SharedData } from '@/types';

import styles from './show.module.css';

interface PageProps extends SharedData {
    client: { id: number; name: string };
    project: { id: number; name: string };
    tasks: AgentKanbanTask[];
    lanes: string[];
}

export default function AgentBoardShow() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();
    const { client, project, tasks, lanes } = usePage<PageProps>().props;

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('Clients'), href: clients.index().url, count: 2 },
            { title: client.name, href: clients.edit(client.id).url },
            { title: t('Agent Board'), href: '' },
        ],
        [t, client.name, client.id],
    );

    useEffect(() => setBreadcrumbs(breadcrumbs), [breadcrumbs, setBreadcrumbs]);

    return (
        <>
            <Head title={`${project.name} - ${t('Agent Board')}`} />

            <div className={styles.header}>
                <Link href={clients.edit(client.id).url} className={styles.backLink}>
                    <ArrowLeft size={14} />
                    {client.name}
                </Link>
                <h1 className={styles.title}>
                    <Bot size={20} />
                    {project.name} - {t('Agent Board')}
                </h1>
                <p className={styles.subtitle}>{t('Drag cards between lanes. No agent runs yet - manual lane management for now.')}</p>
            </div>

            <AgentKanban tasks={tasks} lanes={lanes} reloadOnly={['tasks']} />
        </>
    );
}
