import { describe, expect, test } from 'bun:test';
import { readFileSync } from 'node:fs';
import * as Dialog from '@radix-ui/react-dialog';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import en from '../../../../../../lang/en.json';
import pl from '../../../../../../lang/pl.json';
import { helperActionHref, withoutHelperAction } from './actions';
import { Helper } from './helper';
import { HELPER_CLOSED, helperHere, helperPose, helperScene, hintShown } from './hints';
import { HelperPanel } from './panel';
import { docsLink, helperTopics, SCREEN_TOPICS } from './topics';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const t = (key: string, options?: Record<string, unknown>) => i18next.t(key, options);
const context = { t, locale: 'pl-PL' };
const text = (html: string) => html.replace(/<[^>]+>/g, '|');
const ids = Object.values(SCREEN_TOPICS).flatMap((topics) => topics.map((topic) => topic.id));
const panel = (page: string, topic: string | null, docs: string | null = null, props: Record<string, unknown> = {}) =>
    renderToStaticMarkup(
        <Dialog.Root open modal={false}>
            <HelperPanel topics={helperTopics(page, props, t)} topic={topic} docs={docs} onTopic={() => undefined} onAction={() => undefined} />
        </Dialog.Root>,
    );

describe('the helper topics', () => {
    test('every topic has a question and an answer of at most 240 characters in both languages', () => {
        const problems: string[] = [];

        for (const [locale, lang] of [['pl', pl as Record<string, string>], ['en', en as Record<string, string>]] as const) {
            for (const id of ids) {
                const question = lang[`helper_question_${id}`] ?? '';
                const answer = lang[`helper_answer_${id}`] ?? '';
                if (question.trim() === '') problems.push(`${locale}: no question for ${id}`);
                if (answer.trim() === '') problems.push(`${locale}: no answer for ${id}`);
                if (answer.length > 240) problems.push(`${locale}: the answer of ${id} has ${answer.length} characters`);
            }
        }

        expect(problems).toEqual([]);
    });

    test('a topic id is the docs id: unique, lowercase with hyphens, and no answer in the lang files is left without a screen', () => {
        expect(new Set(ids).size).toBe(ids.length);
        expect(ids.filter((id) => !/^[a-z0-9-]+$/.test(id))).toEqual([]);

        for (const lang of [pl, en] as Record<string, string>[]) {
            const stored = Object.keys(lang)
                .filter((key) => key.startsWith('helper_answer_') || key.startsWith('helper_question_'))
                .map((key) => key.replace(/^helper_(answer|question)_/, ''));
            expect(stored.filter((id) => !ids.includes(id))).toEqual([]);
        }
    });

    test('a screen lists its topics in order, with the action an answer offers', () => {
        const topics = helperTopics('tasks/show', {}, t);

        expect(topics.slice(0, 2).map((topic) => topic.question)).toEqual(['Jak liczyć czas na tym zadaniu?', 'Co wpisać w brief dla agenta?']);
        expect(topics[0].action).toEqual({ label: 'Zaloguj czas', event: 'log-time' });
        expect(helperTopics('contacts/index', {}, t)).toEqual([]);
    });

    test('a link action leads only where the page has the place: no Billing link without billing or on the tab itself', () => {
        const client = (tab: string, billing = true) =>
            helperTopics('clients/show', { tab, client: { id: 7 }, auth: { user: { account: { features: { billing } } } } }, t).find((topic) => topic.id === 'hours-left')?.action;

        expect(client('overview')).toEqual({ label: 'Otwórz Rozliczenia', href: '/clients/7/billing' });
        expect(client('billing')).toBeNull();
        expect(client('overview', false)).toBeUndefined();
        expect(helperTopics('month-close/index', { period: '2026-10' }, t)[1].action).toEqual({
            label: 'Pokaż, co czeka na mnie',
            href: '/month-close?period=2026-10&filter=you',
        });
    });

    test('a topic whose place a member cannot open is hidden: billing for the owner only, Settings and the SMS queue past their own tabs', () => {
        const viewer = (owner: boolean, billing = owner) => ({ auth: { user: { owner, account: { features: { billing } } } } });
        const seen = (page: string, props: Record<string, unknown>) => helperTopics(page, props, t).map((topic) => topic.id);
        const client = { tab: 'tasks', client: { id: 7, billing_mode: 'retainer' } };
        const everyone = ['waiting-for-you', 'journal-note'];
        const billingTopics = ['hours-left', 'client-report', 'retainer-terms', 'hours-bank', 'billed-time-task', 'close-month', 'invoice-draft'];

        expect(seen('clients/show', { ...client, ...viewer(true) })).toEqual(SCREEN_TOPICS['clients/show'].map((topic) => topic.id));
        expect(seen('clients/show', { ...client, ...viewer(false) })).toEqual(everyone);
        expect(seen('clients/show', { ...client, ...viewer(true, false) })).toEqual(everyone);
        expect(seen('clients/show', { ...client, ...viewer(true) }).filter((id) => !everyone.includes(id))).toEqual(billingTopics);
        expect(seen('tasks/show', viewer(false))).toEqual([
            'task-time',
            'agent-brief',
            'counts-in-report',
            'mark-done',
            'agent-mark',
            'trello-owns-card',
            'task-archive',
        ]);
        expect(seen('tasks/show', viewer(true))).toContain('task-billed-time');
        expect(seen('settings/index', viewer(true))).toEqual(['agent-token-new', 'sms-polish-letters']);
        expect(seen('settings/index', viewer(false))).toEqual([]);
        expect(seen('messages/index', viewer(true))).toEqual(['sms-failed']);
        expect(seen('messages/index', viewer(false))).toEqual([]);

        for (const page of ['today/index', 'clients/index', 'leads/index', 'leads/edit']) {
            expect(seen(page, viewer(false))).toEqual(SCREEN_TOPICS[page].map((topic) => topic.id));
        }
    });

    test('a member on Settings or the SMS queue has no topics left, so no dog', () => {
        const member = { auth: { user: { owner: false, account: { features: { billing: false } } } } };

        expect(renderToStaticMarkup(<Helper page="settings/index" url="/settings" props={member} />)).toBe('');
        expect(renderToStaticMarkup(<Helper page="messages/index" url="/sms" props={member} />)).toBe('');
        expect(renderToStaticMarkup(<Helper page="settings/index" url="/settings" props={{ auth: { user: { owner: true } } }} />)).toContain(
            'data-shell-helper',
        );
    });

    test('Show the retainer is offered on every tab exactly where Overview has the Retainer button, and opens it there', () => {
        const owner = { auth: { user: { owner: true, account: { features: { billing: true } } } } };
        const member = { auth: { user: { owner: false, account: { features: { billing: false } } } } };
        const action = (props: Record<string, unknown>, viewer: Record<string, unknown> = owner) =>
            helperTopics('clients/show', { ...viewer, ...props }, t).find((topic) => topic.id === 'retainer-terms')?.action;
        const withButton = { id: 7, billing_mode: 'retainer', retainer_terms: true };
        const withoutButton = { id: 7, billing_mode: 'retainer', retainer_terms: false };

        expect(action({ tab: 'overview', client: withButton })).toEqual({ label: 'Pokaż abonament', event: 'retainer-terms' });
        for (const tab of ['tasks', 'journal', 'billing', 'details']) {
            expect(action({ tab, client: withButton })).toEqual({ label: 'Pokaż abonament', href: '/clients/7/overview?helper=retainer-terms' });
            expect(action({ tab, client: withoutButton })).toBeNull();
        }
        expect(action({ tab: 'overview', client: withoutButton })).toBeNull();
        expect(action({ tab: 'journal', client: { id: 7, billing_mode: 'retainer' } })).toBeNull();
        expect(action({ tab: 'journal', client: withButton }, member)).toBeUndefined();
    });

    test('the Retainer button on Overview reads the same fact the helper reads', () => {
        const tile = readFileSync(`${import.meta.dir}/../../../../pages/clients/components/client-overview/client-overview.tsx`, 'utf8');
        const page = readFileSync(`${import.meta.dir}/../../../../pages/clients/show.tsx`, 'utf8');

        expect(page).toContain('retainerTerms={client.retainer_terms ?? false}');
        expect(tile).toContain('terms={retainerTerms}');
        expect(tile).toMatch(/\{terms && \(\s*<SoloButton[^>]*onClick=\{\(\) => setDialog\('details'\)\}/);
    });

    test('Generate a report opens the dialog on Billing while the shown month has the button, leads there from another tab, and is never offered for an order client', () => {
        const owner = { auth: { user: { owner: true, account: { features: { billing: true } } } } };
        const action = (props: Record<string, unknown>) =>
            helperTopics('clients/show', { ...owner, ...props }, t).find((topic) => topic.id === 'client-report')?.action;
        const retainerClient = { id: 7, billing_mode: 'retainer' };

        expect(action({ tab: 'overview', client: retainerClient })).toEqual({
            label: 'Generuj raport',
            href: '/clients/7/billing?helper=client-report',
        });
        expect(action({ tab: 'billing', client: retainerClient, billing_month: { report: null }, billing_emails: null })).toEqual({
            label: 'Generuj raport',
            event: 'client-report',
        });
        expect(
            action({ tab: 'billing', client: retainerClient, billing_month: { report: { id: 3, status: 'draft' } }, billing_emails: null }),
        ).toBeNull();
        expect(action({ tab: 'billing', client: retainerClient, billing_month: { report: null }, billing_emails: { '2026-10': {} } })).toBeNull();
        expect(action({ tab: 'overview', client: { id: 7, billing_mode: 'order' } })).toBeNull();
    });

    test('New entry opens the dialog on Journal and leads there from any other tab, for a member too', () => {
        const action = (tab: string, owner: boolean) =>
            helperTopics('clients/show', { tab, client: { id: 7 }, auth: { user: { owner, account: { features: { billing: owner } } } } }, t).find(
                (topic) => topic.id === 'journal-note',
            )?.action;

        expect(action('journal', true)).toEqual({ label: 'Nowy wpis', event: 'journal-note' });
        expect(action('overview', false)).toEqual({ label: 'Nowy wpis', href: '/clients/7/journal?helper=journal-note' });
    });

    test('a link from another tab names its dialog in one query the arrival drops, keeping the rest of the address', () => {
        expect(helperActionHref('/clients/7/journal', 'journal-note')).toBe('/clients/7/journal?helper=journal-note');
        expect(helperActionHref('/clients/7/billing?month=2026-10', 'client-report')).toBe(
            '/clients/7/billing?month=2026-10&helper=client-report',
        );
        expect(withoutHelperAction('https://crm-solo.test/clients/7/billing?month=2026-10&helper=client-report', 'client-report')).toBe(
            '/clients/7/billing?month=2026-10',
        );
        expect(withoutHelperAction('/clients/7/journal?helper=journal-note', 'journal-note')).toBe('/clients/7/journal');
        expect(withoutHelperAction('/clients/7/journal?helper=journal-note', 'client-report')).toBeNull();
        expect(withoutHelperAction('/clients/7/journal', 'journal-note')).toBeNull();
    });

    test('every event an answer can ask for has one page that owns the dialog and listens for it', () => {
        const events = ['log-time', 'retainer-terms', 'client-report', 'journal-note'];
        const owners: Record<string, string> = {
            'log-time': 'pages/tasks/components/task-page/time-section.tsx',
            'retainer-terms': 'pages/clients/components/client-overview/client-overview.tsx',
            'client-report': 'pages/clients/components/client-billing/month-tiles.tsx',
            'journal-note': 'pages/clients/components/client-journal/client-journal.tsx',
        };
        const dialogs: Record<string, string> = {
            'log-time': '<ManualTimeEntryDialog',
            'retainer-terms': '<RetainerDetailsDialog',
            'client-report': '<GenerateReportDialog',
            'journal-note': '<JournalEntryDialog',
        };
        const root = `${import.meta.dir}/../../../..`;
        const listened = Array.from(new Bun.Glob('**/*.tsx').scanSync(root))
            .filter((file) => !file.endsWith('.test.tsx'))
            .flatMap((file) =>
                [...readFileSync(`${root}/${file}`, 'utf8').matchAll(/useHelperAction\(\s*'([a-z-]+)'/g)].map((match) => `${match[1]} ${file}`),
            );

        expect(listened.sort()).toEqual(events.map((event) => `${event} ${owners[event]}`).sort());
        for (const event of events) expect(readFileSync(`${root}/${owners[event]}`, 'utf8')).toContain(dialogs[event]);
    });

    test('the docs link is built from the one setting and stays hidden while it is empty', () => {
        expect(docsLink('', 'pl', 'close-day')).toBeNull();
        expect(docsLink(null, 'pl', null)).toBeNull();
        expect(docsLink('https://docs.example.com/', 'pl', 'close-day')).toBe('https://docs.example.com/t/close-day');
        expect(docsLink('https://docs.example.com', 'en', 'close-day')).toBe('https://docs.example.com/en/t/close-day');
        expect(docsLink('https://docs.example.com', 'pl', null)).toBe('https://docs.example.com/');

        expect(panel('today/index', null)).not.toContain('Więcej w dokumentacji');
        expect(panel('today/index', 'close-day')).not.toContain('Więcej w dokumentacji');
        expect(panel('today/index', 'close-day', 'https://docs.example.com/t/close-day')).toMatch(/<a[^>]*href="https:\/\/docs.example.com\/t\/close-day"[^>]*>Więcej w dokumentacji/);
    });
});

describe('the helper panel', () => {
    test('the list asks what to ask and names every topic of the screen, with no field to type into', () => {
        const html = panel('month-close/index', null);

        expect(text(html)).toContain('O co chcesz zapytać na tym ekranie?');
        expect(html.match(/data-topic="/g)).toHaveLength(4);
        expect(text(html)).toContain('Kto robi który krok?');
        expect(html).not.toContain('<input');
        expect(html).not.toContain('<textarea');
    });

    test('an answer shows the question, the short answer, its one action and the way back to the topics', () => {
        const html = panel('today/index', 'today-picks');

        expect(text(html)).toContain('Tematy');
        expect(html).toMatch(/<h2[^>]*>Jak zaplanować dzień\?<\/h2>/);
        expect(text(html)).toContain(pl['helper_answer_today-picks']);
        expect(html).toMatch(/<a[^>]*href="\/all-tasks"[^>]*>Wybierz zadania<\/a>/);
    });

    test('an answer without an action draws no button but the way back and the close', () => {
        const html = panel('today/index', 'off-plan');

        expect(html.match(/<button/g)).toHaveLength(2);
        expect(html).not.toContain('<a ');
    });

    test('a screen without topics shows no dog at all', () => {
        expect(renderToStaticMarkup(<Helper page="contacts/index" url="/contacts" props={{}} />)).toBe('');
        expect(renderToStaticMarkup(<Helper page="today/index" url="/" props={{}} />)).toContain('data-shell-helper');
    });
});

describe('the helper line', () => {
    test('Today with free slots says how many and leads to planning the day; a full day, the timer and the summary say nothing', () => {
        expect(helperScene('today/index', { state: 'paper', maxPicks: 5, picks: [1, 2] }, context).hint).toEqual({ text: 'Zostały 3 wolne miejsca na dziś.', topic: 'today-picks' });
        expect(helperScene('today/index', { state: 'paper', maxPicks: 5, picks: [] }, context).hint?.text).toBe('Zostało 5 wolnych miejsc na dziś.');
        expect(helperScene('today/index', { state: 'paper', maxPicks: 5, picks: [1, 2, 3, 4, 5] }, context).hint).toBeNull();
        expect(helperScene('today/index', { state: 'timer', maxPicks: 5, picks: [] }, context).hint).toBeNull();
        expect(helperScene('today/index', { state: 'done', maxPicks: 5, picks: [] }, context).hint).toBeNull();
    });

    test('a client over the package gets a warning that leads to the hours, and the dog is surprised', () => {
        const props = { tab: 'overview', client: { id: 7, short_name: 'Pracownia Mebli' }, overview: { hours: { remaining: -12.8 } } };
        const hint = helperScene('clients/show', props, context).hint;

        expect(hint).toEqual({ text: 'Pracownia Mebli jest 12,8 h ponad pakiet.', topic: 'hours-left', warning: true });
        expect(helperPose(hint, false)).toBe('wow');
        expect(helperScene('clients/show', { ...props, overview: { hours: { remaining: 3 } } }, context).hint).toBeNull();
    });

    test('the month close counts the clients waiting for you', () => {
        const row = (owner: 'you' | 'agent', finished: string | null = null) => ({ next: { key: 'x', owner }, finished_on: finished });
        const scene = helperScene('month-close/index', { clients: [row('you'), row('you'), row('you'), row('agent'), row('you', '2026-11-02')] }, context);

        expect(scene.hint).toEqual({ text: '3 klientów czeka na Ciebie.', topic: 'month-close-who' });
        expect(helperScene('month-close/index', { clients: [row('agent')] }, context).hint).toBeNull();
    });

    test('a task, a screen without data worth saying and Settings show the dog alone', () => {
        expect(helperScene('tasks/show', {}, context)).toEqual({ hint: null });
        expect(helperScene('settings/index', {}, context)).toEqual({ hint: null });
    });

    test('every line leads to a topic of its own screen', () => {
        const lines: [string, Record<string, unknown>][] = [
            ['today/index', { state: 'paper', maxPicks: 5, picks: [] }],
            ['clients/show', { overview: { hours: { remaining: -1 } } }],
            ['month-close/index', { clients: [{ next: { owner: 'you' }, finished_on: null }] }],
        ];

        for (const [page, props] of lines) {
            const topic = helperScene(page, props, context).hint?.topic ?? 'none';
            expect(SCREEN_TOPICS[page].map((entry) => entry.id)).toContain(topic);
        }
    });

    test('the first run hides the corner helper: the dog is large on the screen itself', () => {
        expect(helperScene('clients/index', { stage_counts: { active: 0, former: 0 } }, context).hidden).toBe(true);
        expect(helperScene('clients/index', { stage_counts: { active: 2, former: 0 } }, context).hidden).toBe(false);
    });

    test('the dog looks at its line while it shows and keeps its glasses on without one', () => {
        expect(helperPose({ text: 'x', topic: 'y' }, true)).toBe('look-left');
        expect(helperPose({ text: 'x', topic: 'y' }, false)).toBe('cool');
        expect(helperPose(null, true)).toBe('cool');
    });

    test('opening the helper uses up the line for this visit; another page starts closed with its line', () => {
        const open = { url: '/', open: true, topic: 'today-picks' };
        const closed = { ...open, open: false };

        expect(hintShown(HELPER_CLOSED, '/')).toBe(true);
        expect(hintShown(open, '/')).toBe(false);
        expect(hintShown(closed, '/')).toBe(false);
        expect(helperHere(open, '/clients/7')).toEqual(HELPER_CLOSED);
        expect(hintShown(closed, '/clients/7')).toBe(true);
    });
});

describe('the helper in the shell', () => {
    test('the phone button, when switched on, stands fixed above the timer strip and the page end makes room for it', () => {
        const css = readFileSync(`${import.meta.dir}/helper.module.css`, 'utf8');
        const base = css.slice(0, css.indexOf('@media'));
        const helper = base.match(/\.helper \{([^}]*)\}/)?.[1] ?? '';
        const solo = readFileSync(`${import.meta.dir}/../../../../../css/solo.css`, 'utf8');

        expect(helper).toMatch(/position: fixed;/);
        expect(helper).toMatch(/bottom: calc\([^;]*var\(--shell-dock\) \+ var\(--shell-strip\)/);
        expect(solo).toMatch(/:root:has\(\[data-shell-helper\]:where\(\[data-phone\]\)\) \{\s*--shell-helper: 64px;/);
        expect(solo).not.toMatch(/:root:has\(\[data-shell-helper\]\) \{/);
        expect(css).toMatch(/\.helper:hover \.hint,\s*\.helper:focus-within \.hint \{\s*display: flex;/);
    });

    test('each device kind shows the helper only while its switch is on, hidden with display none so nothing reaches it', () => {
        const css = readFileSync(`${import.meta.dir}/helper.module.css`, 'utf8');
        const base = css.slice(0, css.indexOf('@media'));
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));

        expect(base).toMatch(/\.helper:not\(\[data-phone\]\) \{\s*display: none;/);
        expect(wide).toMatch(/\.helper:not\(\[data-desktop\]\) \{\s*display: none;/);
        expect(wide).toMatch(/\.helper\[data-desktop\] \{\s*display: flex;/);
        expect(css).not.toMatch(/opacity|visibility/);
    });

    test('the switches reach the corner as data attributes: on for a computer and off for a phone by default, nothing at all when both are off', () => {
        const helper = (choice: { desktop?: boolean; phone?: boolean }) => renderToStaticMarkup(<Helper page="today/index" url="/" props={{}} {...choice} />);
        const aside = (html: string) => html.match(/<aside[^>]*>/)?.[0] ?? '';

        expect(aside(helper({}))).toContain('data-desktop=""');
        expect(aside(helper({}))).not.toContain('data-phone');
        expect(aside(helper({ desktop: false, phone: true }))).toContain('data-phone=""');
        expect(aside(helper({ desktop: false, phone: true }))).not.toContain('data-desktop');
        expect(helper({ desktop: false, phone: false })).toBe('');
    });

    test('from 768 px the dog is 112 px and the window and the page end clear it', () => {
        const css = readFileSync(`${import.meta.dir}/helper.module.css`, 'utf8');
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));
        const solo = readFileSync(`${import.meta.dir}/../../../../../css/solo.css`, 'utf8');
        const source = readFileSync(`${import.meta.dir}/helper.tsx`, 'utf8');

        expect(solo).toMatch(/--shell-dog: 112px;/);
        expect(wide).toMatch(/\.button \{\s*width: var\(--shell-dog\);\s*height: var\(--shell-dog\);/);
        expect(wide).toMatch(/bottom: calc\(var\(--role-space-16\) \+ var\(--shell-dog\) \+ var\(--role-space-8\)\);/);
        expect(wide).toMatch(/max-height: calc\(100dvh - var\(--shell-dog\) - var\(--role-space-16\) \* 3\);/);
        expect(css).not.toMatch(/72px/);
        expect(source).toContain('<Dog size={112}');
        expect(solo).toMatch(/\[data-shell\]:has\(\[data-shell-helper\]\[data-desktop\]\) \{\s*padding-bottom: calc\(var\(--role-space-16\) \+ var\(--shell-dog\) \+ var\(--role-space-24\)\);/);
    });

    test('nothing in the helper performs a request: no chat, no AI call', () => {
        const source = ['helper.tsx', 'panel.tsx', 'hints.ts', 'topics.ts', 'actions.ts', 'dog.tsx'].map((file) => readFileSync(`${import.meta.dir}/${file}`, 'utf8')).join('\n');

        expect(source).not.toMatch(/fetch\(|axios|router\.(post|put|patch|delete)|useForm/);
    });
});
