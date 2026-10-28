import FlashMessages from '@/components/flash-messages';
import { ClientHeader, type ClientHeaderData, type ClientTab } from '@/components/solo/client-header';
import { Masthead } from '@/components/solo/masthead';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { BillingActions, type BillingDialog, type BillingLedger, type ClientBilling, ClientBillingTab } from './components/client-billing';
import type { ClientCard, FieldEvent, MessageRow } from './components/client-fields';
import {
    type ClientJournal,
    ClientJournalTab,
    isRelationshipScreen,
    type JournalNote,
    type JournalPage,
    type JournalWorkRow,
} from './components/client-journal';
import { type ClientOverview, ClientOverviewTab, OverviewActions } from './components/client-overview';
import { type ClientTasks, ClientTasksTab, TasksActions } from './components/client-tasks';
import {
    type ClientContactRow,
    type ClientDetails,
    type ClientDocumentRow,
    DetailsActions,
    type DetailsDialog,
    DetailsTab,
} from './components/details-tab';
import styles from './show.module.css';

interface ShowProps {
    tab: ClientTab;
    client: ClientHeaderData;
    overview?: ClientOverview;
    tasks?: ClientTasks;
    billing?: ClientBilling;
    billing_ledger?: BillingLedger;
    journal?: ClientJournal;
    journal_work?: JournalPage<JournalWorkRow>;
    journal_notes?: JournalPage<JournalNote>;
    details?: ClientDetails;
    contacts?: ClientContactRow[];
    documents?: ClientDocumentRow[];
    card?: ClientCard | null;
    journal_sms?: MessageRow[] | null;
    field_history?: FieldEvent[];
    field_history_count?: number;
    [key: string]: unknown;
}

export default function ClientShow() {
    const { url, props } = usePage<SharedData & ShowProps>();
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
        details,
        contacts,
        documents,
        card,
        field_history: fieldHistory,
        field_history_count: fieldHistoryCount,
        journal_sms: journalSms,
        auth,
    } = props;
    const [agentsOpen, setAgentsOpen] = useState(false);
    const [billingDialog, setBillingDialog] = useState<BillingDialog>(null);
    const [detailsDialog, setDetailsDialog] = useState<DetailsDialog>(null);
    const relationship = tab === 'dziennik' && isRelationshipScreen(url);
    const header = (
        <ClientHeader
            client={client}
            tab={tab}
            actions={
                tab === 'przeglad' ? (
                    <OverviewActions client={client} />
                ) : tab === 'zadania' ? (
                    <TasksActions clientId={client.id} onOpenAgents={() => setAgentsOpen(true)} />
                ) : tab === 'rozliczenia' && billing?.mode === 'retainer' && billingLedger ? (
                    <BillingActions billing={billing} ledger={billingLedger} onOpen={setBillingDialog} />
                ) : tab === 'dane' && details ? (
                    <DetailsActions details={details} onOpen={setDetailsDialog} />
                ) : undefined
            }
        />
    );

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={client.short_name} />
            <div className={styles.frame}>
                <Masthead current="clients" />
                <main>
                    {relationship ? <div className={styles.pushedAway}>{header}</div> : header}
                    {tab === 'przeglad' && overview ? (
                        <ClientOverviewTab clientId={client.id} overview={overview} />
                    ) : tab === 'zadania' && tasks ? (
                        <ClientTasksTab clientId={client.id} tasks={tasks} agentsOpen={agentsOpen} onAgentsOpenChange={setAgentsOpen} />
                    ) : tab === 'rozliczenia' && billing && (billing.mode === 'order' || billingLedger) ? (
                        <ClientBillingTab
                            clientId={client.id}
                            clientName={client.short_name}
                            billing={billing}
                            ledger={billingLedger}
                            dialog={billingDialog}
                            onDialogChange={setBillingDialog}
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
                            dialog={detailsDialog}
                            onDialogChange={setDetailsDialog}
                        />
                    ) : tab === 'dziennik' && journal && journalWork && journalNotes ? (
                        <ClientJournalTab
                            clientId={client.id}
                            clientName={client.short_name}
                            journal={journal}
                            work={journalWork}
                            notes={journalNotes}
                            sms={journalSms ?? null}
                            owner={auth.user?.name ?? null}
                            relationship={relationship}
                        />
                    ) : null}
                </main>
            </div>
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}
