import { ClientHeader, type ClientHeaderData, type ClientTab } from '@/components/solo/client-header';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    BillingFrame,
    type BillingInvoiceList,
    type BillingLedger,
    type BillingMonth,
    type ClientBilling,
    ClientBillingTab,
    type SummaryEmails,
} from './components/client-billing';
import type { ClientCard, FieldEvent, MessageRow } from './components/client-fields';
import {
    type ClientJournal,
    ClientJournalTab,
    JournalActions,
    type JournalNote,
    type JournalPage,
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
    details?: ClientDetails;
    stage?: ClientStage;
    projects?: DetailsProject[];
    contacts?: ClientContactRow[];
    documents?: ClientDocumentRow[];
    card?: ClientCard | null;
    journal_sms?: MessageRow[] | null;
    field_history?: FieldEvent[];
    field_history_count?: number;
    [key: string]: unknown;
}

export default function ClientShow() {
    const { props } = usePage<SharedData & ShowProps>();
    const {
        tab,
        client,
        overview,
        tasks,
        journal,
        journal_work: journalWork,
        journal_notes: journalNotes,
        billing,
        billing_ledger: billingLedger,
        billing_month: billingMonth,
        billing_invoices: billingInvoices,
        details,
        contacts,
        documents,
        card,
        field_history: fieldHistory,
        field_history_count: fieldHistoryCount,
        journal_sms: journalSms,
        auth,
    } = props;
    const [creatingTask, setCreatingTask] = useState(false);
    const [detailsDialog, setDetailsDialog] = useState<DetailsDialog>(null);
    const [composing, setComposing] = useState(false);
    const header =
        tab === 'rozliczenia' && billing ? (
            <BillingFrame client={client} billing={billing} ledger={billingLedger} month={billingMonth} />
        ) : (
        <ClientHeader
            client={client}
            phoneMenu={tab !== 'dziennik'}
            actions={
                tab === 'przeglad' ? (
                    <OverviewActions client={client} />
                ) : tab === 'zadania' ? (
                    <TasksActions clientId={client.id} canCreate={(tasks?.projects.length ?? 0) > 0} onCreate={() => setCreatingTask(true)} />
                ) : tab === 'dziennik' ? (
                    <JournalActions onCompose={() => setComposing(true)} />
                ) : tab === 'dane' && details ? (
                    <DetailsActions details={details} onOpen={setDetailsDialog} />
                ) : undefined
            }
            main={
                tab === 'przeglad' ? (
                    <CopyContextAction clientId={client.id} />
                ) : tab === 'zadania' && (tasks?.projects.length ?? 0) > 0 ? (
                    <NewTaskAction onCreate={() => setCreatingTask(true)} />
                ) : tab === 'dziennik' ? (
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
                {tab === 'przeglad' && overview ? (
                    <ClientOverviewTab clientId={client.id} overview={overview} manage={Boolean(auth.user?.owner)} />
                ) : tab === 'zadania' && tasks ? (
                    <ClientTasksTab clientId={client.id} tasks={tasks} creating={creatingTask} onCreatingChange={setCreatingTask} />
                ) : tab === 'rozliczenia' && billing && (billing.mode === 'order' || billingLedger) ? (
                    <ClientBillingTab
                        clientId={client.id}
                        clientName={client.short_name}
                        billing={billing}
                        ledger={billingLedger}
                        month={billingMonth}
                        invoices={billingInvoices}
                        emails={props.billing_emails ?? null}
                    />
                ) : tab === 'dane' && details && contacts ? (
                    <DetailsTab
                        client={client}
                        details={details}
                        contacts={contacts}
                        documents={documents ?? []}
                        card={card ?? null}
                        fieldHistory={fieldHistory ?? []}
                        fieldHistoryCount={fieldHistoryCount}
                        stage={props.stage ?? null}
                        projects={props.projects ?? []}
                        owner={Boolean(auth.user?.owner)}
                        dialog={detailsDialog}
                        onDialogChange={setDetailsDialog}
                    />
                ) : tab === 'dziennik' && journal && journalWork && journalNotes ? (
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
                    />
                ) : null}
            </main>
        </SoloSurface>
    );
}

ClientShow.place = clientPlace satisfies PlaceResolver<ShowProps>;
