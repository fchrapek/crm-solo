import { ClientHeader, type ClientHeaderData, type ClientTab } from '@/components/solo/client-header';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useClientView } from './client-view';
import {
    BillingFrame,
    type BillingInvoiceList,
    type BillingLedger,
    type BillingMonth,
    type ClientBilling,
    ClientBillingTab,
    type SummaryEmails,
} from './components/client-billing';
import type { ClientCard, MessageRow } from './components/client-fields';
import {
    type ClientJournal,
    ClientJournalTab,
    JournalActions,
    type JournalNote,
    type JournalPage,
    type JournalView,
    type JournalWorkRow,
} from './components/client-journal';
import { type ClientOverview, ClientOverviewTab, OverviewActions } from './components/client-overview';
import { CopyContextAction } from './components/client-overview/copy-context-action';
import { type ClientTasks, ClientTasksTab, NewTaskAction, TasksActions } from './components/client-tasks';
import {
    type ClientContactRow,
    type ClientDetails,
    type ClientDocumentRow,
    type ClientStage,
    DetailsActions,
    type DetailsDialog,
    type DetailsProject,
    DetailsTab,
} from './components/details-tab';
import { clientPlace } from './place';
import styles from './show.module.css';

interface ShowProps {
    tab: ClientTab;
    /** When the server read this page's data, Unix seconds: the opening is acknowledged as of then. */
    read_at: number;
    client: ClientHeaderData;
    overview?: ClientOverview;
    tasks?: ClientTasks;
    billing?: ClientBilling;
    billing_ledger?: BillingLedger;
    billing_month?: BillingMonth;
    billing_invoices?: BillingInvoiceList;
    billing_emails?: SummaryEmails | null;
    journal?: ClientJournal;
    journal_work?: JournalPage<JournalWorkRow>;
    journal_notes?: JournalPage<JournalNote>;
    journal_view?: JournalView;
    details?: ClientDetails;
    stage?: ClientStage;
    projects?: DetailsProject[];
    contacts?: ClientContactRow[];
    documents?: ClientDocumentRow[];
    card?: ClientCard | null;
    journal_sms?: MessageRow[] | null;
    [key: string]: unknown;
}

export default function ClientShow() {
    const { props } = usePage<SharedData & ShowProps>();
    const {
        tab,
        read_at: readAt,
        client,
        overview,
        tasks,
        journal,
        journal_work: journalWork,
        journal_notes: journalNotes,
        journal_view: journalView = null,
        billing,
        billing_ledger: billingLedger,
        billing_month: billingMonth,
        billing_invoices: billingInvoices,
        details,
        contacts,
        documents,
        card,
        journal_sms: journalSms,
        auth,
    } = props;
    const { t } = useTranslation();
    useClientView(client.id, tab, readAt);
    const [creatingTask, setCreatingTask] = useState(false);
    const [detailsDialog, setDetailsDialog] = useState<DetailsDialog>(null);
    const [composing, setComposing] = useState(false);
    const header =
        tab === 'billing' && billing ? (
            <BillingFrame client={client} billing={billing} ledger={billingLedger} month={billingMonth} />
        ) : (
            <ClientHeader
                client={client}
                title={tab === 'journal' && journalView === 'relationship' ? t('Client relationship') : undefined}
                phoneMenu={tab !== 'journal'}
                actions={
                    tab === 'overview' ? (
                        <OverviewActions client={client} />
                    ) : tab === 'tasks' ? (
                        <TasksActions clientId={client.id} canCreate={(tasks?.projects.length ?? 0) > 0} onCreate={() => setCreatingTask(true)} />
                    ) : tab === 'journal' ? (
                        <JournalActions onCompose={() => setComposing(true)} />
                    ) : tab === 'details' && details ? (
                        <DetailsActions details={details} owner={Boolean(auth.user?.owner)} onOpen={setDetailsDialog} />
                    ) : undefined
                }
                main={
                    tab === 'overview' ? (
                        <CopyContextAction clientId={client.id} />
                    ) : tab === 'tasks' && (tasks?.projects.length ?? 0) > 0 ? (
                        <NewTaskAction onCreate={() => setCreatingTask(true)} />
                    ) : tab === 'journal' ? (
                        <JournalActions onCompose={() => setComposing(true)} />
                    ) : undefined
                }
            />
        );

    return (
        <SoloSurface day="paper">
            <Head title={client.short_name} />
            <main className={styles.page}>
                {header}
                {tab === 'overview' && overview ? (
                    <ClientOverviewTab
                        clientId={client.id}
                        clientName={client.short_name}
                        overview={overview}
                        retainerTerms={client.retainer_terms ?? false}
                        manage={Boolean(auth.user?.owner)}
                    />
                ) : tab === 'tasks' && tasks ? (
                    <ClientTasksTab clientId={client.id} tasks={tasks} creating={creatingTask} onCreatingChange={setCreatingTask} />
                ) : tab === 'billing' && billing && (billing.mode === 'order' || billingLedger) ? (
                    <ClientBillingTab
                        clientId={client.id}
                        clientName={client.short_name}
                        billing={billing}
                        ledger={billingLedger}
                        month={billingMonth}
                        invoices={billingInvoices}
                        emails={props.billing_emails ?? null}
                    />
                ) : tab === 'details' && details && contacts ? (
                    <DetailsTab
                        client={client}
                        details={details}
                        contacts={contacts}
                        documents={documents ?? []}
                        card={card ?? null}
                        stage={props.stage ?? null}
                        projects={props.projects ?? []}
                        owner={Boolean(auth.user?.owner)}
                        dialog={detailsDialog}
                        onDialogChange={setDetailsDialog}
                    />
                ) : tab === 'journal' && journal && journalWork && journalNotes ? (
                    <ClientJournalTab
                        clientId={client.id}
                        journal={journal}
                        work={journalWork}
                        notes={journalNotes}
                        sms={journalSms ?? null}
                        owner={auth.user?.name ?? null}
                        manage={Boolean(auth.user?.owner)}
                        composing={composing}
                        onComposingChange={setComposing}
                        view={journalView}
                        billing={client.billing !== false}
                    />
                ) : null}
            </main>
        </SoloSurface>
    );
}

ClientShow.place = clientPlace satisfies PlaceResolver<ShowProps>;
