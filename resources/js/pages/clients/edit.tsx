import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronRight, Link2, Plus, Settings, Trash } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { destroy, restore, update } from '@/actions/App/Http/Controllers/ClientsController';
import { AgentKanban, type AgentKanbanTask } from '@/components/agent-kanban';
import { Form, FormInput, FormLabel, FormMessage } from '@/components/form';
import InertiaPagination from '@/components/inertia-pagination';
import { SessionTimeEditDialog, type SessionTimeEntry, type TaskOption } from '@/components/session-time-edit-dialog';
import { SubmitButton } from '@/components/submit-button';
import { TableContainer } from '@/components/table-container';
import { Button } from '@/components/ui/button';
import { InfoHint } from '@/components/ui/info-hint';
import { SectionCard } from '@/components/ui/section-card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs } from '@/components/ui/tabs/tabs';
import { Textarea } from '@/components/ui/textarea';
import { usePageActions } from '@/contexts/page-context';
import { useDeletionControls } from '@/hooks/use-deletion-controls';
import { useFormProcessing } from '@/hooks/use-form-processing';
import clients from '@/routes/clients';
import contacts from '@/routes/contacts';
import { BreadcrumbItem, Client, ClientFormData, LifecycleStage, SharedData } from '@/types';

import { type ClientDocument, DocumentsTab } from './components/documents-tab';
import { type ComposerOption } from './components/generate-report-dialog';
import { type ClientInvoices, type ClientRevenueSummary, type InvoiceFilters, InvoicesTab } from './components/invoices-tab';
import { LifecycleTimeline } from './components/lifecycle-timeline';
import { MonthClosePill, MonthCloseSettings } from './components/month-close-type-chip';
import { type OverviewData, OverviewTab } from './components/overview-tab';
import { ReportsTab, type ReportSummary } from './components/reports-tab';
import { type Retainer, RetainerChip, RetainerPill } from './components/retainer-chip';
import { StageChip, StagePill } from './components/stage-chip';

import { ProjectFormDialog } from './components/project-form-dialog';
import { ProjectRow } from './components/project-row';
import styles from './edit.module.css';

interface TaskItem {
    id: number;
    name: string;
    description?: string | null;
    list_name: string | null;
    is_completed: boolean;
    due_date: string | null;
    is_overdue?: boolean;
    labels: string[] | null;
    trello_url: string | null;
    archived_at?: string | null;
    source?: string | null;
    priority?: string | null;
    recurrence_period_days?: number | null;
    executor_type?: string | null;
    is_agent_ready?: boolean;
    agent_lane?: string | null;
    latest_run?: {
        id: number;
        status: string;
        started_at: string | null;
        finished_at: string | null;
    } | null;
}

interface ProjectRepository {
    id: number;
    name: string;
    local_path: string | null;
    remote_url: string | null;
    provider: string;
}

interface TrelloList {
    id: string;
    name: string;
}

interface ProjectSummary {
    id: number;
    name: string;
    description: string | null;
    trello_url: string | null;
    is_private: boolean;
    trello_workspace?: string | null;
    trello_lists?: TrelloList[];
    trello_list_mapping?: Record<string, string>;
    tasks_count: number;
    completed_tasks_count: number;
    active_agent_tasks_count?: number;
    repositories?: ProjectRepository[];
    tasks: TaskItem[];
}

interface ClientTimeEntry {
    id: number;
    title: string | null;
    description: string | null;
    start_time: string;
    end_time: string | null;
    duration_minutes: number;
    billable: boolean;
    project_name: string | null;
    source: 'clockify' | 'terminal_session' | 'manual';
    is_running: boolean;
    task: { id: number; name: string } | null;
    tags: string[] | null;
}

interface PaginatedTimeEntries {
    data: ClientTimeEntry[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

interface LifecycleEvent {
    id: number;
    from_stage: LifecycleStage | null;
    to_stage: LifecycleStage;
    note: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
}

interface EditPageProps extends SharedData {
    client: Client;
    overview: OverviewData;
    projects: ProjectSummary[];
    agentTasks: AgentKanbanTask[];
    agentLanes: string[];
    timeEntries: PaginatedTimeEntries;
    clientTasks: TaskOption[];
    lifecycleEvents: LifecycleEvent[];
    retainers: Retainer[];
    documents: ClientDocument[];
    reports: ReportSummary[];
    reportComposers: ComposerOption[];
    invoices: ClientInvoices;
    invoiceFilters: InvoiceFilters;
    reportBaselineMarkdown: string | null;
    revenue: ClientRevenueSummary | null;
    trelloEnabled: boolean;
    trelloActions: boolean;
    flash?: { success?: string | null; error?: string | null };
}

function ProjectsTabContent({
    projects,
    clientId,
    trelloEnabled,
    trelloActions,
    t,
}: {
    projects: ProjectSummary[];
    clientId: number;
    trelloEnabled: boolean;
    trelloActions: boolean;
    t: (key: string) => string;
}) {
    const [newProjectOpen, setNewProjectOpen] = useState(false);

    return (
        <>
            <SectionCard
                title={t('Projects')}
                actions={
                    <Button size="sm" variant="outline" onClick={() => setNewProjectOpen(true)}>
                        <Plus size={14} className={styles.iconLeading} />
                        {t('New project')}
                    </Button>
                }
            >
                {projects.length === 0 ? (
                    <p className={styles.projectsEmpty}>{t('No projects found.')}</p>
                ) : (
                    <div className={styles.projectRows}>
                        {projects.map((project) => (
                            <ProjectRow
                                key={project.id}
                                project={project}
                                clientId={clientId}
                                trelloEnabled={trelloEnabled}
                                trelloActions={trelloActions}
                            />
                        ))}
                    </div>
                )}
            </SectionCard>

            <ProjectFormDialog open={newProjectOpen} onOpenChange={setNewProjectOpen} clientId={clientId} />
        </>
    );
}

export default function Edit() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();

    const {
        client,
        overview,
        projects,
        agentTasks,
        agentLanes,
        timeEntries,
        clientTasks,
        lifecycleEvents,
        retainers,
        documents,
        reports,
        reportComposers,
        invoices,
        invoiceFilters,
        reportBaselineMarkdown,
        revenue,
        trelloEnabled,
        trelloActions,
    } = usePage<EditPageProps>().props;

    // Click a time-entry row to open the edit dialog (title / detail / times / task).
    const [editTimeEntry, setEditTimeEntry] = useState<SessionTimeEntry | null>(null);
    const openTimeEntry = (entry: ClientTimeEntry) =>
        setEditTimeEntry({
            id: entry.id,
            title: entry.title,
            task_id: entry.task?.id ?? null,
            start_time: entry.start_time,
            end_time: entry.end_time,
            description: entry.description,
            billable: entry.billable,
        });

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            {
                title: 'Client',
                count: 2,
                href: clients.index().url,
            },
            {
                title: client.name,
                href: clients.edit(client.id).url,
            },
        ],
        [client.name, client.id],
    );

    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const form = useForm<Required<ClientFormData>>({
        type: client.type || 'business',
        name: client.name || '',
        email: client.email || '',
        phone: client.phone || '',
        address: client.address || '',
        city: client.city || '',
        region: client.region || '',
        country: client.country || '',
        postal_code: client.postal_code || '',
        tax_id: client.tax_id || '',
        business_type: client.business_type || '',
        segment: client.segment || '',
        cooperation_type: client.cooperation_type || '',
        currency: client.currency || 'PLN',
        hourly_rate: client.hourly_rate !== null && client.hourly_rate !== undefined ? String(client.hourly_rate) : '',
        ssh_config: client.ssh_config || '',
        maintenance_invoice_description: client.maintenance_invoice_description || '',
        include_in_month_close: client.include_in_month_close ?? false,
        notes: client.notes || '',
    });

    const isProcessing = useFormProcessing(form.processing);

    function onSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        form.submit(update(client), {
            preserveScroll: true,
        });
    }

    const { showDeleteControls } = useDeletionControls({
        isDeleted: !!client.deleted_at,
        resourceType: 'client',
        deleteAction: destroy(client),
        restoreAction: restore(client),
    });

    const isBusinessType = form.data.type === 'business';

    const detailsTab = (
        <>
            {client.deleted_at && showDeleteControls()}

            <div className="formContainer">
                <Form onSubmit={onSubmit}>
                    <div className="formSection">
                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="type" error={form.errors.type}>
                                    {t('Type')}
                                </FormLabel>
                                <Select
                                    value={form.data.type}
                                    onValueChange={(value) => form.setData('type', value as 'business' | 'individual')}
                                    disabled={isProcessing}
                                >
                                    <SelectTrigger id="type" className={form.errors.type ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('Select type')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="business">{t('Business')}</SelectItem>
                                        <SelectItem value="individual">{t('Individual')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <FormMessage error={form.errors.type} />
                            </div>

                            <div>
                                <FormLabel htmlFor="name" error={form.errors.name}>
                                    {t('Name')}
                                </FormLabel>
                                <FormInput
                                    id="name"
                                    type="text"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    required
                                    autoFocus
                                    maxLength={100}
                                    disabled={isProcessing}
                                    error={form.errors.name}
                                />
                                <FormMessage error={form.errors.name} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="email" error={form.errors.email}>
                                    {t('Email')}
                                </FormLabel>
                                <FormInput
                                    id="email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    maxLength={50}
                                    disabled={isProcessing}
                                    error={form.errors.email}
                                />
                                <FormMessage error={form.errors.email} />
                            </div>

                            <div>
                                <FormLabel htmlFor="phone" error={form.errors.phone}>
                                    {t('Phone')}
                                </FormLabel>
                                <FormInput
                                    id="phone"
                                    type="tel"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    maxLength={50}
                                    disabled={isProcessing}
                                    error={form.errors.phone}
                                />
                                <FormMessage error={form.errors.phone} />
                            </div>
                        </div>

                        {isBusinessType && (
                            <div className="formGrid">
                                <div>
                                    <FormLabel htmlFor="tax_id" error={form.errors.tax_id}>
                                        {t('Tax ID (NIP)')}
                                    </FormLabel>
                                    <FormInput
                                        id="tax_id"
                                        type="text"
                                        value={form.data.tax_id}
                                        onChange={(e) => form.setData('tax_id', e.target.value)}
                                        maxLength={20}
                                        disabled={isProcessing}
                                        error={form.errors.tax_id}
                                    />
                                    <FormMessage error={form.errors.tax_id} />
                                </div>

                                <div>
                                    <FormLabel htmlFor="business_type" error={form.errors.business_type}>
                                        {t('Business Type')}
                                    </FormLabel>
                                    <FormInput
                                        id="business_type"
                                        type="text"
                                        value={form.data.business_type}
                                        onChange={(e) => form.setData('business_type', e.target.value)}
                                        maxLength={100}
                                        disabled={isProcessing}
                                        error={form.errors.business_type}
                                        placeholder={t('e.g., sp. z o.o., JDG')}
                                    />
                                    <FormMessage error={form.errors.business_type} />
                                </div>
                            </div>
                        )}

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="address" error={form.errors.address}>
                                    {t('Address')}
                                </FormLabel>
                                <FormInput
                                    id="address"
                                    type="text"
                                    value={form.data.address}
                                    onChange={(e) => form.setData('address', e.target.value)}
                                    maxLength={150}
                                    disabled={isProcessing}
                                    error={form.errors.address}
                                />
                                <FormMessage error={form.errors.address} />
                            </div>

                            <div>
                                <FormLabel htmlFor="city" error={form.errors.city}>
                                    {t('City')}
                                </FormLabel>
                                <FormInput
                                    id="city"
                                    type="text"
                                    value={form.data.city}
                                    onChange={(e) => form.setData('city', e.target.value)}
                                    maxLength={50}
                                    disabled={isProcessing}
                                    error={form.errors.city}
                                />
                                <FormMessage error={form.errors.city} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="postal_code" error={form.errors.postal_code}>
                                    {t('Postal Code')}
                                </FormLabel>
                                <FormInput
                                    id="postal_code"
                                    type="text"
                                    value={form.data.postal_code}
                                    onChange={(e) => form.setData('postal_code', e.target.value)}
                                    maxLength={25}
                                    disabled={isProcessing}
                                    error={form.errors.postal_code}
                                    placeholder="00-000"
                                />
                                <FormMessage error={form.errors.postal_code} />
                            </div>

                            <div>
                                <FormLabel htmlFor="country" error={form.errors.country}>
                                    {t('Country')}
                                </FormLabel>
                                <Select
                                    value={form.data.country || '0'}
                                    onValueChange={(value) => form.setData('country', value === '0' ? '' : value)}
                                    disabled={isProcessing}
                                >
                                    <SelectTrigger id="country" className={form.errors.country ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('None')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="0">{t('None')}</SelectItem>
                                        <SelectItem value="PL">{t('Poland')}</SelectItem>
                                        <SelectItem value="DE">{t('Germany')}</SelectItem>
                                        <SelectItem value="GB">{t('United Kingdom')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <FormMessage error={form.errors.country} />
                            </div>

                            <div>
                                <FormLabel htmlFor="currency" error={form.errors.currency}>
                                    {t('Currency')}
                                </FormLabel>
                                <Select
                                    value={form.data.currency}
                                    onValueChange={(value) => form.setData('currency', value as 'PLN' | 'EUR' | 'USD' | 'GBP')}
                                    disabled={isProcessing}
                                >
                                    <SelectTrigger id="currency" className={form.errors.currency ? 'selectError' : ''}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="PLN">PLN - złoty</SelectItem>
                                        <SelectItem value="EUR">EUR - euro</SelectItem>
                                        <SelectItem value="USD">USD - dollar</SelectItem>
                                        <SelectItem value="GBP">GBP - pound</SelectItem>
                                    </SelectContent>
                                </Select>
                                <FormMessage error={form.errors.currency} />
                            </div>
                        </div>

                        <div className="formGrid">
                            <div>
                                <FormLabel htmlFor="segment" error={form.errors.segment}>
                                    {t('Segment')}
                                </FormLabel>
                                <Select
                                    value={form.data.segment || '__none__'}
                                    onValueChange={(value) =>
                                        form.setData('segment', value === '__none__' ? '' : (value as 'agency' | 'smb' | 'enterprise' | 'individual'))
                                    }
                                    disabled={isProcessing}
                                >
                                    <SelectTrigger id="segment" className={form.errors.segment ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('Select segment')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">{t(' - none - ')}</SelectItem>
                                        <SelectItem value="agency">{t('Agency')}</SelectItem>
                                        <SelectItem value="smb">{t('Small / mid business')}</SelectItem>
                                        <SelectItem value="enterprise">{t('Enterprise')}</SelectItem>
                                        <SelectItem value="individual">{t('Individual')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <FormMessage error={form.errors.segment} />
                            </div>

                            <div>
                                <FormLabel htmlFor="cooperation_type" error={form.errors.cooperation_type}>
                                    {t('Cooperation type')}
                                </FormLabel>
                                <Select
                                    value={form.data.cooperation_type || '__none__'}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'cooperation_type',
                                            value === '__none__' ? '' : (value as 'retainer' | 'hourly' | 'project' | 'one_off'),
                                        )
                                    }
                                    disabled={isProcessing}
                                >
                                    <SelectTrigger id="cooperation_type" className={form.errors.cooperation_type ? 'selectError' : ''}>
                                        <SelectValue placeholder={t('Select cooperation type')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">{t(' - none - ')}</SelectItem>
                                        <SelectItem value="retainer">{t('Retainer')}</SelectItem>
                                        <SelectItem value="hourly">{t('Hourly contract')}</SelectItem>
                                        <SelectItem value="project">{t('Project')}</SelectItem>
                                        <SelectItem value="one_off">{t('One-off')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <FormMessage error={form.errors.cooperation_type} />
                            </div>
                        </div>

                        {form.data.cooperation_type === 'hourly' && (
                            <div className="formGrid">
                                <div>
                                    <FormLabel htmlFor="hourly_rate" error={form.errors.hourly_rate}>
                                        {t('Hourly rate (netto)')}
                                    </FormLabel>
                                    <FormInput
                                        id="hourly_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={form.data.hourly_rate}
                                        onChange={(e) => form.setData('hourly_rate', e.target.value)}
                                        disabled={isProcessing}
                                        error={form.errors.hourly_rate}
                                        placeholder={t('e.g., 95.00')}
                                    />
                                    <FormMessage error={form.errors.hourly_rate} />
                                </div>
                                <div />
                            </div>
                        )}

                        <div>
                            <FormLabel htmlFor="notes" error={form.errors.notes}>
                                {t('Notes')}
                            </FormLabel>
                            <Textarea
                                id="notes"
                                value={form.data.notes}
                                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => form.setData('notes', e.target.value)}
                                disabled={isProcessing}
                                rows={4}
                            />
                            <FormMessage error={form.errors.notes} />
                        </div>

                        <div className="formActionsWithDelete">
                            <SubmitButton processing={isProcessing}>{t('Update Client')}</SubmitButton>
                        </div>
                    </div>
                </Form>
            </div>
        </>
    );

    // Shares the page form object with the Dane fields, so either Save
    // submits the whole record.
    const opsSettingsTab = (
        <>
            <div className="formContainer">
                <Form onSubmit={onSubmit}>
                    <div className="formSection">
                        <MonthCloseSettings
                            clientId={client.id}
                            value={client.month_close_type}
                            reportMode={client.report_mode}
                            includeInMonthClose={form.data.include_in_month_close}
                            onIncludeChange={(checked) => form.setData('include_in_month_close', checked)}
                        />

                        <div>
                            <span className={styles.labelRow}>
                                <FormLabel htmlFor="ssh_config" error={form.errors.ssh_config}>
                                    {t('SSH config')}
                                </FormLabel>
                                <InfoHint label={t('About SSH config')}>
                                    {t('SSH access to the live server for month-close checks and dumps. Not every client has one.')}
                                </InfoHint>
                            </span>
                            <FormInput
                                id="ssh_config"
                                type="text"
                                value={form.data.ssh_config}
                                onChange={(e) => form.setData('ssh_config', e.target.value)}
                                maxLength={1024}
                                disabled={isProcessing}
                                error={form.errors.ssh_config}
                                placeholder={t('e.g., user@host or an SSH host alias')}
                            />
                            <FormMessage error={form.errors.ssh_config} />
                        </div>

                        <div>
                            <span className={styles.labelRow}>
                                <FormLabel htmlFor="maintenance_invoice_description" error={form.errors.maintenance_invoice_description}>
                                    {t('Maintenance invoice line')}
                                </FormLabel>
                                <InfoHint label={t('About the maintenance invoice line')}>
                                    {t('Line-item wording on the monthly maintenance DRAFT invoice in Infakt.')}
                                </InfoHint>
                            </span>
                            <FormInput
                                id="maintenance_invoice_description"
                                type="text"
                                value={form.data.maintenance_invoice_description}
                                onChange={(e) => form.setData('maintenance_invoice_description', e.target.value)}
                                maxLength={500}
                                disabled={isProcessing}
                                error={form.errors.maintenance_invoice_description}
                                placeholder="Utrzymanie strony i wsparcie techniczne"
                            />
                            <FormMessage error={form.errors.maintenance_invoice_description} />
                        </div>

                        <div className="formActionsWithDelete">
                            {!client.deleted_at && showDeleteControls()}
                            <SubmitButton processing={isProcessing}>{t('Update Client')}</SubmitButton>
                        </div>
                    </div>
                </Form>
            </div>
        </>
    );

    const contactsTab = (
        <TableContainer>
            <TableHeader>
                <TableRow>
                    <TableHead>{t('Name')}</TableHead>
                    <TableHead>{t('City')}</TableHead>
                    <TableHead colSpan={2}>{t('Phone')}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {client.contacts.map(({ id, name, phone, city, deleted_at }) => (
                    <TableRow key={id}>
                        <TableCell className="cellRelative">
                            <div className="cellOverlay">
                                <Link href={contacts.edit(id)} prefetch className="cellLink">
                                    <span className="srOnly">Edit {name}</span>
                                </Link>
                            </div>
                            <div className="cellContent">
                                {name}
                                {deleted_at && <Trash className="trashIcon" />}
                            </div>
                        </TableCell>
                        <TableCell className="cellRelative">
                            <div className="cellOverlay">
                                <Link href={contacts.edit(id)} prefetch tabIndex={-1} className="cellLink">
                                    <span className="srOnly">Edit {name}</span>
                                </Link>
                            </div>
                            <div className="cellContent">{city}</div>
                        </TableCell>
                        <TableCell className="cellRelative">
                            <div className="cellOverlay">
                                <Link href={contacts.edit(id)} prefetch tabIndex={-1} className="cellLink">
                                    <span className="srOnly">Edit {name}</span>
                                </Link>
                            </div>
                            <div className="cellContent">{phone}</div>
                        </TableCell>
                        <TableCell style={{ width: '1px' }}>
                            <Button asChild variant="ghost" size="icon">
                                <Link tabIndex={-1} href={contacts.edit(id)} prefetch>
                                    <ChevronRight className="cellIcon" style={{ color: 'var(--color-muted-foreground)' }} />
                                </Link>
                            </Button>
                        </TableCell>
                    </TableRow>
                ))}
                {client.contacts.length === 0 && (
                    <TableRow>
                        <TableCell colSpan={4} className="emptyCell">
                            {t('No contacts found.')}
                        </TableCell>
                    </TableRow>
                )}
            </TableBody>
        </TableContainer>
    );

    const projectsTab = (
        <ProjectsTabContent projects={projects} clientId={client.id} trelloEnabled={trelloEnabled} trelloActions={trelloActions} t={t} />
    );

    const timeTab = (
        <>
            <TableContainer>
                <TableHeader>
                    <TableRow>
                        <TableHead>{t('Date')}</TableHead>
                        <TableHead>{t('Description')}</TableHead>
                        <TableHead>{t('Source')}</TableHead>
                        <TableHead>{t('Project')}</TableHead>
                        <TableHead>{t('Duration')}</TableHead>
                        <TableHead>{t('Billable')}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {timeEntries.data.map((entry) => (
                        <TableRow
                            key={entry.id}
                            className={styles.clickableRow}
                            role="button"
                            tabIndex={0}
                            title={t('Click to edit')}
                            onClick={() => openTimeEntry(entry)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    openTimeEntry(entry);
                                }
                            }}
                        >
                            <TableCell className={styles.cellNowrap}>{new Date(entry.start_time).toLocaleDateString()}</TableCell>
                            <TableCell>
                                <div className={styles.timeDescCell}>
                                    <span>{entry.title || entry.description || '-'}</span>
                                    {entry.task && (
                                        <Link
                                            href={`/tasks/${entry.task.id}`}
                                            className={styles.taskChip}
                                            title={entry.task.name}
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <Link2 size={11} /> #{entry.task.id}
                                        </Link>
                                    )}
                                </div>
                            </TableCell>
                            <TableCell>
                                {entry.source === 'terminal_session' ? t('Session') : entry.source === 'manual' ? t('Manual') : 'Clockify'}
                            </TableCell>
                            <TableCell>{entry.project_name || '-'}</TableCell>
                            <TableCell className={styles.cellNowrap}>
                                {entry.is_running ? t('In progress') : `${Math.floor(entry.duration_minutes / 60)}h ${entry.duration_minutes % 60}m`}
                            </TableCell>
                            <TableCell>{entry.billable ? '✓' : '-'}</TableCell>
                        </TableRow>
                    ))}
                    {timeEntries.data.length === 0 && (
                        <TableRow>
                            <TableCell colSpan={7} className="emptyCell">
                                {t('No time entries found.')}
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </TableContainer>
            {/* The shared pagination component, in partial-visit mode so a
                page flip reloads only timeEntries and the tab stays put. */}
            <InertiaPagination links={timeEntries.links} className={styles.pager} only={['timeEntries']} preserveState preserveScroll replace />
            <SessionTimeEditDialog
                open={editTimeEntry !== null}
                entry={editTimeEntry}
                tasks={clientTasks}
                onOpenChange={(open) => !open && setEditTimeEntry(null)}
                onSaved={() => router.reload({ only: ['timeEntries'] })}
            />
        </>
    );

    // Praca: the agent board leads when agents are working this client, the
    // projects card lists everything else. No switchers - the per-project
    // board (and its board/list toggle) lives on the project's own page.
    const workContent = (
        <>
            {agentTasks.length > 0 && (
                <section className={styles.boardSection}>
                    <h3 className={styles.boardHeading}>{t('Agent board')}</h3>
                    <AgentKanban tasks={agentTasks} lanes={agentLanes} showProjectChip reloadOnly={['projects', 'agentTasks']} />
                </section>
            )}
            <div className={styles.tabColumn}>{projectsTab}</div>
        </>
    );

    // Aktywność: the time table wide, the relationship stage + its timeline
    // in the rail. Stage changes happen here now - the header pill is inert.
    const activityContent = (
        <div className={styles.tabColumn}>
            <div className="recordColumns">
                <div className="recordMain">
                    <SectionCard title={t('Time')}>{timeTab}</SectionCard>
                </div>
                <aside className="recordRail">
                    <SectionCard title={t('Relationship stage')} actions={<StageChip clientId={client.id} currentStage={client.lifecycle_stage} />}>
                        <LifecycleTimeline events={lifecycleEvents} clientId={client.id} />
                    </SectionCard>
                </aside>
            </div>
        </div>
    );

    // Ustawienia: month-close wiring and the danger zone - the once-or-twice
    // operational config. Report configuration lives with the reports it
    // shapes (Raporty); the identity form lives under Dane, since it is the
    // client's record rather than configuration.
    const settingsContent = (
        <div className={styles.tabColumn}>
            <SectionCard title={t('Month Close')}>{opsSettingsTab}</SectionCard>
            {/* Abonament management moved here from the header - the pill up
                there only informs now. */}
            <SectionCard title={t('Abonament')}>
                <RetainerChip clientId={client.id} clientCurrency={client.currency || 'PLN'} retainers={retainers} />
            </SectionCard>
        </div>
    );

    // Dane: the client's record - the identity form wide on the left,
    // contacts + documents stacked in the short right rail (the record-view
    // column anatomy shared with the lead page).
    const dataContent = (
        <div className={styles.tabColumn}>
            <div className="recordColumns">
                <div className="recordMain">
                    <SectionCard title={t('Client data')}>{detailsTab}</SectionCard>
                </div>
                <aside className="recordRail">
                    <SectionCard title={t('Contacts')}>{contactsTab}</SectionCard>
                    <SectionCard title={t('Documents')}>
                        <DocumentsTab clientId={client.id} documents={documents} />
                    </SectionCard>
                </aside>
            </div>
        </div>
    );

    const reportsContent = (
        <div className={styles.tabColumn}>
            <ReportsTab clientId={client.id} reports={reports} composers={reportComposers} reportBaselineMarkdown={reportBaselineMarkdown} />
        </div>
    );

    const invoicesContent = (
        <div className={styles.tabColumn}>
            <InvoicesTab invoices={invoices} revenue={revenue} filters={invoiceFilters} />
        </div>
    );

    const overviewContent = (
        <div className={styles.tabColumn}>
            <OverviewTab clientId={client.id} overview={overview} lifecycleEvents={lifecycleEvents} projects={projects} />
        </div>
    );

    const tabs = [
        { id: 'overview', label: t('Overview'), content: overviewContent },
        { id: 'work', label: t('Work'), content: workContent },
        { id: 'reports', label: t('Reports'), content: reportsContent },
        // Invoices sits next to Reports: both are read together at month close.
        { id: 'invoices', label: t('Invoices'), content: invoicesContent },
        { id: 'activity', label: t('Activity'), content: activityContent },
        { id: 'data', label: t('Data'), content: dataContent },
        // No tab button - the gear in the header chip row is the trigger.
        { id: 'settings', label: t('Settings'), hidden: true, content: settingsContent },
    ];

    return (
        <>
            <Head title={form.data.name} />
            {/* Name alone on top; status pills on their own row below - same
                header anatomy as the lead record view. Hours live on the
                Przegląd card, not here; the gear opens Ustawienia. */}
            <div className={styles.clientHeader}>
                <h1 className={styles.clientHeading}>{client.name}</h1>
                <div className={styles.clientChips}>
                    <StagePill stage={client.lifecycle_stage} />
                    <RetainerPill clientCurrency={client.currency || 'PLN'} retainers={retainers} />
                    <MonthClosePill value={client.month_close_type} />
                    <button
                        type="button"
                        className={styles.settingsButton}
                        aria-label={t('Settings')}
                        title={t('Settings')}
                        onClick={() => {
                            window.location.hash = 'settings';
                        }}
                    >
                        <Settings size={15} />
                    </button>
                </div>
            </div>
            <Tabs tabs={tabs} defaultTab="overview" />
        </>
    );
}
