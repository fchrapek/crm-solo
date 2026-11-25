import { closeListUrl } from '@/pages/month-close/filters';
import clients from '@/routes/clients';
import today from '@/routes/today';
import { helperActionHref, type HelperEvent } from './actions';

/** The one thing an answer can do: open a place, or ask the page to open one of its dialogs. Both close the helper. */
export type HelperAction = { label: string; href: string } | { label: string; event: HelperEvent };

export interface HelperTopic {
    id: string;
    question: string;
    answer: string;
    action: HelperAction | null;
}

type Props = Record<string, unknown>;

interface TopicSpec {
    id: string;
    /** False hides the topic where its place cannot be opened: a module the account has off, a place for the owner only. */
    when?: (props: Props) => boolean;
    /** The label is a lang key; null where the page offers nothing to open. */
    action?: (props: Props) => HelperAction | null;
}

export const record = (value: unknown): Record<string, unknown> => (typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {});

const clientId = (props: Props): number | null => {
    const id = record(props.client).id;

    return typeof id === 'number' ? id : null;
};

/** The account bills and the viewer may see it: the features are shared per viewer, so a member has billing off. */
const billingOn = (props: Props) => record(record(record(record(props.auth).user).account).features).billing !== false;

/** The owner manages the account: Settings, resending a text, the agent tokens. */
const managesAccount = (props: Props) => Boolean(record(record(props.auth).user).owner);

const retainerClient = (props: Props) => billingOn(props) && record(props.client).billing_mode === 'retainer';

const clientTab = (tab: string, label: string) => (props: Props) => {
    const id = clientId(props);

    return id === null || props.tab === tab ? null : { label, href: clients.show({ client: id, tab }).url };
};

/** The read-only Retainer window of Overview's Hours tile, offered on every tab exactly where that tile has its button. */
const retainerTerms = (props: Props): HelperAction | null => {
    const id = clientId(props);
    if (id === null || !billingOn(props) || record(props.client).retainer_terms !== true) return null;

    return props.tab === 'overview'
        ? { label: 'Show the retainer', event: 'retainer-terms' }
        : { label: 'Show the retainer', href: helperActionHref(clients.show({ client: id, tab: 'overview' }).url, 'retainer-terms') };
};

/** The Generate a report dialog of Billing's Report tile, offered where the header offers a report. */
const clientReport = (props: Props): HelperAction | null => {
    const id = clientId(props);
    if (id === null || !retainerClient(props)) return null;
    if (props.tab !== 'billing')
        return { label: 'Generate a report', href: helperActionHref(clients.show({ client: id, tab: 'billing' }).url, 'client-report') };
    const month = record(props.billing_month);
    const tile = props.billing_emails === null || props.billing_emails === undefined || month.report !== null;

    return 'report' in month && month.report === null && tile ? { label: 'Generate a report', event: 'client-report' } : null;
};

/** Journal's new entry dialog. */
const journalNote = (props: Props): HelperAction | null => {
    const id = clientId(props);
    if (id === null) return null;

    return props.tab === 'journal'
        ? { label: 'New entry', event: 'journal-note' }
        : { label: 'New entry', href: helperActionHref(clients.show({ client: id, tab: 'journal' }).url, 'journal-note') };
};

/**
 * The topics of each screen, by Inertia page name, in the order the helper lists them. An id is the docs' id
 * (the docs site answers /t/<id>), so it is never renamed; the texts are `helper_question_<id>` and `helper_answer_<id>`.
 */
export const SCREEN_TOPICS: Record<string, readonly TopicSpec[]> = {
    'today/index': [
        { id: 'today-picks', action: () => ({ label: 'Pick tasks', href: today.tasks().url }) },
        { id: 'off-plan' },
        { id: 'carried-tasks' },
        { id: 'close-day' },
        { id: 'plan-tomorrow' },
        { id: 'timer-start-stop' },
    ],
    'clients/index': [{ id: 'client-filters' }],
    'clients/show': [
        { id: 'hours-left', when: billingOn, action: clientTab('billing', 'Open Billing') },
        { id: 'waiting-for-you' },
        { id: 'client-report', when: billingOn, action: clientReport },
        { id: 'retainer-terms', when: billingOn, action: retainerTerms },
        { id: 'journal-note', action: journalNote },
        { id: 'hours-bank', when: billingOn },
        { id: 'billed-time-task', when: billingOn },
        { id: 'close-month', when: billingOn },
        { id: 'invoice-draft', when: billingOn },
    ],
    'tasks/show': [
        { id: 'task-time', action: () => ({ label: 'Log time', event: 'log-time' }) },
        { id: 'agent-brief' },
        { id: 'counts-in-report' },
        { id: 'task-billed-time', when: billingOn },
        { id: 'mark-done' },
        { id: 'agent-mark' },
        { id: 'trello-owns-card' },
        { id: 'task-archive' },
    ],
    'month-close/index': [
        { id: 'month-close-what' },
        { id: 'month-close-who', action: (props) => (typeof props.period === 'string' ? { label: 'Show what waits for me', href: closeListUrl(props.period, 'you') } : null) },
        { id: 'hours-to-close' },
        { id: 'month-close-add' },
    ],
    'leads/index': [{ id: 'lead-tier' }],
    'leads/edit': [{ id: 'lead-convert' }, { id: 'lead-source-fixed' }],
    'messages/index': [{ id: 'sms-failed', when: managesAccount }],
    'settings/index': [
        { id: 'agent-token-new', when: managesAccount },
        { id: 'sms-polish-letters', when: managesAccount },
    ],
};

export function helperTopics(page: string, props: Props, t: (key: string) => string): HelperTopic[] {
    return (SCREEN_TOPICS[page] ?? [])
        .filter(({ when }) => when?.(props) ?? true)
        .map(({ id, action }) => {
            const resolved = action?.(props) ?? null;

            return {
                id,
                question: t(`helper_question_${id}`),
                answer: t(`helper_answer_${id}`),
                action: resolved === null ? null : { ...resolved, label: t(resolved.label) },
            };
        });
}

/** The docs page of a topic, or the docs' start without one; null while the docs are not published (no base set). */
export function docsLink(base: string | null | undefined, locale: string, topic: string | null): string | null {
    if (!base) return null;
    const root = `${base.replace(/\/+$/, '')}${locale.startsWith('en') ? '/en' : ''}`;

    return topic === null ? `${root}/` : `${root}/t/${topic}`;
}
