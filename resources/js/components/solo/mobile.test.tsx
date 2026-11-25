import * as inertia from '@inertiajs/react';
import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import * as soloWide from './use-solo-wide';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

let currentUrl = '/';
let wide = true;
let owner = 1;
spyOn(soloWide, 'useSoloWide').mockImplementation(() => wide);
spyOn(inertia, 'usePage').mockImplementation((() => ({
    url: currentUrl,
    props: { auth: { user: { name: 'Anna Zając', email: 'anna@example.test', owner } } },
})) as typeof inertia.usePage);
afterAll(() => mock.restore());

const { TabBar, tabBarSection } = await import('./tab-bar');
const { Rail } = await import('./shell/rail');
const { ClientHeader } = await import('./client-header');
const { BottomSheet, BottomSheetPanel } = await import('./bottom-sheet');
const { ListRow } = await import('./list-row');
const { IconButton } = await import('./icon-button');
const { ResponsiveSelect } = await import('./responsive-select');
const { returnTarget, submitOnCommandEnter } = await import('./responsive-dialog');
const { SoloButton } = await import('./primitives');
const { Sheet } = await import('@/components/ui/sheet');
const { HeaderAction } = await import('./client-header');

const activeTab = (html: string) => [...html.matchAll(/<(?:a|button)[^>]*aria-current="page"[^>]*>.*?<span>([^<]+)<\/span>/g)].map((m) => m[1]);

describe('TabBar', () => {
    test('every route lands on its tab, and a page no tab owns lights none', () => {
        expect(tabBarSection('/')).toBe('today');
        expect(tabBarSection('/?view=summary')).toBe('today');
        expect(tabBarSection('/tomorrow')).toBe('today');
        expect(tabBarSection('/week?next=1')).toBe('today');
        expect(tabBarSection('/all-tasks?for=tomorrow')).toBe('today');
        expect(tabBarSection('/clients')).toBe('clients');
        expect(tabBarSection('/clients/7/billing')).toBe('clients');
        expect(tabBarSection('/contacts?contact=3')).toBe('contacts');
        expect(tabBarSection('/settings')).toBe('settings');
        expect(tabBarSection('/settings/integrations')).toBe('settings');
        expect(tabBarSection('/profile')).toBe('profile');
        // Leads are a pill beside Contacts
        expect(tabBarSection('/leads/3')).toBe('contacts');
        expect(tabBarSection('/revenue?year=2026')).toBeNull();
        expect(tabBarSection('/clientsx')).toBeNull();
        // a task and the month close are reached from Clients, so they light it (Figma: Task on phones)
        expect(tabBarSection('/tasks/12')).toBe('clients');
        expect(tabBarSection('/sms?status=failed')).toBe('clients');
        expect(tabBarSection('/month-close')).toBe('clients');
    });

    test('the current route marks exactly one tab as the page', () => {
        currentUrl = '/clients/1/overview';
        expect(activeTab(renderToStaticMarkup(<TabBar />))).toEqual(['Klienci']);
        currentUrl = '/';
        expect(activeTab(renderToStaticMarkup(<TabBar />))).toEqual(['Dziś']);
        currentUrl = '/contacts';
        expect(activeTab(renderToStaticMarkup(<TabBar />))).toEqual(['Kontakty']);
        currentUrl = '/settings/messages';
        expect(activeTab(renderToStaticMarkup(<TabBar />))).toEqual(['Ustawienia']);
        // an owner reaches My profile from the Settings list, so Settings stays lit
        currentUrl = '/profile';
        expect(activeTab(renderToStaticMarkup(<TabBar />))).toEqual(['Ustawienia']);
        currentUrl = '/revenue';
        expect(activeTab(renderToStaticMarkup(<TabBar />))).toEqual([]);
    });

    test('the four tabs are Today, Clients, Contacts and Settings, in that order', () => {
        currentUrl = '/';
        const html = renderToStaticMarkup(<TabBar />);
        const labels = [...html.matchAll(/<a[^>]*href="([^"]+)"[^>]*>.*?<span>([^<]+)<\/span><\/a>/g)].map((m) => [m[1], m[2]]);
        expect(labels).toEqual([
            ['/', 'Dziś'],
            ['/clients', 'Klienci'],
            ['/contacts', 'Kontakty'],
            ['/settings', 'Ustawienia'],
        ]);
        expect(html).toContain('data-solo-tab-bar=""');
        expect(html).not.toContain('Leady');
        expect(html).not.toContain('Więcej');
    });

    test('a member has no Settings: the fourth tab is My profile as the avatar, lit on /profile', () => {
        owner = 0;
        currentUrl = '/profile';
        const html = renderToStaticMarkup(<TabBar />);
        owner = 1;

        expect([...html.matchAll(/<a[^>]*href="([^"]+)"[^>]*>.*?<span>([^<]+)<\/span><\/a>/g)].map((m) => [m[1], m[2]])).toEqual([
            ['/', 'Dziś'],
            ['/clients', 'Klienci'],
            ['/contacts', 'Kontakty'],
            ['/profile', 'Mój profil'],
        ]);
        expect(activeTab(html)).toEqual(['Mój profil']);
        expect(html).toMatch(/href="\/profile"[^>]*>.*?<span[^>]*aria-hidden="true"[^>]*>AZ<\/span>/);
        expect(html).not.toContain('/settings');
    });

    test("a member's rail has no Settings and leaves My profile to the avatar", () => {
        owner = 0;
        currentUrl = '/profile';
        const html = renderToStaticMarkup(<Rail timer={null} />);
        owner = 1;
        const nav = html.slice(html.indexOf('<nav'), html.indexOf('</nav>'));

        expect([...nav.matchAll(/<a[^>]*>.*?<\/span>([^<]+)<\/a>/g)].map((m) => m[1])).toEqual(['Dziś', 'Klienci', 'Kontakty']);
        expect(html).toMatch(/aria-label="Anna Zając"[^>]*data-current=""/);
    });

    test('on /profile no rail item is lit and the avatar is', () => {
        currentUrl = '/profile';
        const html = renderToStaticMarkup(<Rail timer={null} />);
        const nav = html.slice(html.indexOf('<nav'), html.indexOf('</nav>'));

        expect(nav).not.toContain('aria-current');
        expect(html).toMatch(/aria-label="Anna Zając"[^>]*data-current=""/);

        currentUrl = '/settings';
        expect(renderToStaticMarkup(<Rail timer={null} />)).not.toContain('data-current');
    });

    test('the rail lists the same four sections and marks the current one', () => {
        currentUrl = '/settings/integrations';
        const html = renderToStaticMarkup(<Rail timer={null} />);
        const nav = html.slice(html.indexOf('<nav'), html.indexOf('</nav>'));
        expect([...nav.matchAll(/<a[^>]*>.*?<\/span>([^<]+)<\/a>/g)].map((m) => m[1])).toEqual(['Dziś', 'Klienci', 'Kontakty', 'Ustawienia']);
        expect(nav).toMatch(/<a[^>]*aria-current="page"[^>]*>.*?Ustawienia<\/a>/);
        expect(nav).not.toContain('Finanse');
    });

    test('the rail carries the running timer with Stop only while one runs', () => {
        const timer = { id: 4, started_at: '2026-10-28T08:00:00Z', task: 'Korekta faktury', client: 'Fundacja Testowa' };

        expect(renderToStaticMarkup(<Rail timer={timer} />)).toContain('aria-label="Stop: Korekta faktury"');
        expect(renderToStaticMarkup(<Rail timer={null} />)).not.toContain('Stop');
    });
});

const client = {
    id: 7,
    name: 'Pracownia Testowa Sp. z o.o.',
    short_name: 'Pracownia Testowa',
    billing_mode: 'retainer' as const,
    is_archived: false,
    is_pinned: false,
    open_tasks_count: 54,
    live_agent_minutes: null,
};

describe('ClientHeader on phones', () => {
    test('the head draws the title once and no back link or tabs: the place and its pills carry them', () => {
        const html = renderToStaticMarkup(<ClientHeader client={client} />);
        expect(html.match(/<h1/g)).toHaveLength(1);
        expect(html).not.toContain('href="/clients"');
        expect(html).not.toContain('aria-current="page"');
    });

    test('a long back label of a record nav bar ends in an ellipsis on one line and never squeezes the arrow', async () => {
        const css = await Bun.file(new URL('./client-header.module.css', import.meta.url)).text();
        const rule = (selector: string) => css.match(new RegExp(`\\n${selector.replace(/[.()[\]:]/g, '\\$&')} \\{([^}]*)\\}`))?.[1] ?? '';

        expect(rule('.navBar')).toContain('grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);');
        expect(rule('.back')).toContain('max-width: 100%;');
        expect(rule('.back')).toContain('min-width: 0;');
        expect(rule('.back')).toContain('white-space: nowrap;');
        expect(rule('.backIcon')).toContain('flex: none;');
        expect(rule('.backLabel')).toMatch(/min-width: 0;\s*overflow: hidden;\s*text-overflow: ellipsis;/);
    });

    test('a running agent shows one chip with its minutes', () => {
        const html = renderToStaticMarkup(<ClientHeader client={{ ...client, live_agent_minutes: 14 }} />);
        expect(html.match(/claude pracuje · 14 min/g)).toHaveLength(1);
        expect(renderToStaticMarkup(<ClientHeader client={client} />)).not.toContain('claude');
    });

    test('header actions add the "more" button, whose menu stays unmounted until opened', () => {
        const html = renderToStaticMarkup(<ClientHeader client={client} actions={<HeaderAction>Kopiuj kontekst</HeaderAction>} />);
        expect(html).toContain('aria-label="Więcej akcji"');
        expect(html).toContain('aria-haspopup="menu"');
        expect(html.match(/Kopiuj kontekst/g)).toHaveLength(1);
        expect(renderToStaticMarkup(<ClientHeader client={client} />)).not.toContain('Więcej akcji');
    });
});

describe('BottomSheet', () => {
    test('nothing of a closed sheet is in the page, only its trigger', () => {
        const html = renderToStaticMarkup(
            <BottomSheet title="Godziny · wrzesień" trigger={<button type="button">Otwórz</button>}>
                <p>Treść arkusza</p>
            </BottomSheet>,
        );
        expect(html).toContain('Otwórz');
        expect(html).toContain('aria-haspopup="dialog"');
        expect(html).not.toContain('Treść arkusza');
        expect(html).not.toContain('Godziny · wrzesień');
    });

    test('an open sheet has its header, one scrolling body and the actions outside it in a foot of their own', () => {
        const html = renderToStaticMarkup(
            <Sheet open>
                <BottomSheetPanel
                    title="Zamknij wrzesień"
                    description="Zamrażamy wrzesień w banku godzin."
                    actions={
                        <>
                            <SoloButton variant="outline" size="touch">
                                Anuluj
                            </SoloButton>
                            <SoloButton variant="ink" size="touch">
                                Zamknij miesiąc
                            </SoloButton>
                        </>
                    }
                >
                    <p>Pakiet</p>
                </BottomSheetPanel>
            </Sheet>,
        );
        expect(html).toContain('>Zamknij wrzesień</h2>');
        expect(html).toContain('aria-label="Zamknij"');
        expect(html).toMatch(/<div[^>]*data-slot="sheet-body"><p[^>]*>Zamrażamy wrzesień w banku godzin\.<\/p><p>Pakiet<\/p><\/div><div[^>]*><div[^>]*><button[^>]*>Anuluj/);
    });

    test('a done header centres the title between Clear and Done', () => {
        const html = renderToStaticMarkup(
            <Sheet open>
                <BottomSheetPanel title="Filtr" action="done" onClear={() => undefined}>
                    <p>Status</p>
                </BottomSheetPanel>
            </Sheet>,
        );
        expect(html).toContain('data-action="done"');
        expect(html).toMatch(/Wyczyść<\/button><h2[^>]*>Filtr<\/h2><button[^>]*>Gotowe<\/button>/);
        expect(html).not.toContain('aria-label="Zamknij"');
    });

    test('a save header puts Cancel and the submit around the centred title of a form sheet, with no footer', () => {
        const html = renderToStaticMarkup(
            <Sheet open>
                <BottomSheetPanel title="Dane firmy" action="save" onSubmit={() => undefined} saving>
                    <p>Nazwa</p>
                </BottomSheetPanel>
            </Sheet>,
        );
        expect(html).toMatch(/^<form noValidate=""><div data-action="save">/);
        expect(html).toMatch(/<button[^>]*disabled=""[^>]*>Anuluj<\/button><h2[^>]*>Dane firmy<\/h2><button type="submit"[^>]*disabled=""[^>]*>Zapisz<\/button>/);
        expect(html).not.toContain('aria-label="Zamknij"');
    });

    test("a sheet that is a menu's second step leads with a way back before its title and keeps its close", () => {
        const back = renderToStaticMarkup(
            <Sheet open>
                <BottomSheetPanel title="Przenieś na" onBack={() => undefined}>
                    <p>Kalendarz</p>
                </BottomSheetPanel>
            </Sheet>,
        );
        const plain = renderToStaticMarkup(
            <Sheet open>
                <BottomSheetPanel title="Przenieś na">
                    <p>Kalendarz</p>
                </BottomSheetPanel>
            </Sheet>,
        );

        expect(back).toMatch(/<button[^>]*aria-label="Wróć"[^>]*data-icon="chevron-left"[^>]*>.*?<\/button><h2[^>]*>Przenieś na<\/h2><button[^>]*aria-label="Zamknij"/);
        expect(plain).not.toContain('aria-label="Wróć"');
    });
});

describe('IconButton', () => {
    test('a focused icon button draws its ring on its own edge, so it keeps the size of the buttons beside it', async () => {
        const css = await Bun.file(new URL('./mobile.module.css', import.meta.url)).text();

        expect(css).toMatch(/\.iconButton:focus-visible \{[^}]*outline: var\(--role-stroke-focus\) solid var\(--role-focus-ring\);\s*outline-offset: calc\(-1 \* var\(--role-stroke-focus\)\);/);
        expect(css).not.toMatch(/\.iconButton[^{]*:focus-visible \{[^}]*outline-offset: [1-9]/);
    });

    test('a bare button and a row timer show one 32 px disc, whatever their target: no look paints the target on hover', async () => {
        const css = await Bun.file(new URL('./mobile.module.css', import.meta.url)).text();
        const html = renderToStaticMarkup(<IconButton icon="play" look="outline" size="row" filled label="Start: Wycena" />);

        expect(html).toContain('data-size="row"');
        expect(css).toMatch(/\.iconButton:is\(\[data-look='quiet'\], \[data-size='row'\]\)::before \{[^}]*width: 32px;\s*height: 32px;/);
        const hovers = [...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)].filter(([, selector, body]) => selector.includes('.iconButton') && selector.includes(':hover') && body.includes('background'));
        expect(hovers.map(([, selector]) => selector.trim())).toEqual([
            ".iconButton[data-look='outline']:not([data-size='row']):hover:not(:disabled)",
            ".iconButton:is([data-look='quiet'], [data-size='row'][data-look='outline']):hover:not(:disabled)::before",
        ]);
        expect(css).toMatch(/@media \(min-width: 768px\) \{\s*\.iconButton\[data-size='row'\] \{\s*width: 32px;\s*height: 32px;\s*margin-inline: 0;/);
    });
});

describe('ListRow', () => {
    test('marks sit after the title and in the sub line, and a link row is one target', () => {
        const html = renderToStaticMarkup(
            <ListRow href="/clients/7" title="Pracownia Mebli Przykładowa" sub="36,5 z 20,5 h" size="large" marks={{ pinned: true, isNew: true, openMonth: 'WRZ', agent: 'claude pracuje' }} />,
        );
        expect(html.match(/<a /g)).toHaveLength(1);
        expect(html).toContain('data-size="large"');
        expect(html).toContain('aria-label="Przypięty"');
        expect(html).toContain('>Nowe</span>');
        expect(html).toContain('>WRZ</span>');
        expect(html).toContain('claude pracuje</span>');
    });

    test('with a month mark, a running agent and a balance on one phone row, the agent label truncates in its own slot instead of covering the balance', async () => {
        const html = renderToStaticMarkup(
            <ListRow href="/clients/7" title="Pracownia Mebli Przykładowa" sub="1,3 z 7,75 h" value="+6,45 h" size="large" marks={{ openMonth: 'WRZ', agent: 'claude pracuje' }} />,
        );
        const css = await Bun.file(new URL('./mobile.module.css', import.meta.url)).text();
        const agent = css.match(/\.listAgent \{([^}]*)\}/)?.[1] ?? '';
        const label = css.match(/\.listAgentText \{([^}]*)\}/)?.[1] ?? '';

        expect(html).toContain('<span aria-hidden="true"></span><span title="claude pracuje">claude pracuje</span>');
        expect(agent).toContain('min-width: 0;');
        expect(label).toContain('text-overflow: ellipsis;');
        expect(html.indexOf('+6,45 h')).toBeGreaterThan(html.indexOf('claude pracuje'));
    });

    test('an external row is a plain link to a new tab, not an Inertia visit', () => {
        const html = renderToStaticMarkup(<ListRow href="https://app.infakt.test/15" external title="15/09/2026 · sierpień" value="1 800,00 zł" />);
        expect(html).toMatch(/^<a [^>]*target="_blank" rel="noopener noreferrer" href="https:\/\/app\.infakt\.test\/15"/);
        expect(html).not.toContain('external');
    });

    test('a row shows its trailing value and drops the chevron when asked', () => {
        const html = renderToStaticMarkup(<ListRow title="Pakiet" value="+30,0 h" trailing={null} />);
        expect(html).toContain('+30,0 h');
        expect(html).not.toContain('<svg');
    });
});

describe('ResponsiveSelect', () => {
    const select = (value = 'individual') =>
        renderToStaticMarkup(
            <ResponsiveSelect
                label="Typ"
                value={value}
                onValueChange={() => undefined}
                control={{ id: 'client-type' }}
                options={[
                    { value: 'business', label: 'Firma' },
                    { value: 'individual', label: 'Osoba prywatna' },
                ]}
            />,
        );

    test('from 768 px it is the Solo select popover', () => {
        wide = true;
        const html = select();
        expect(html).toContain('role="combobox"');
        expect(html).not.toContain('aria-haspopup="dialog"');
    });

    test('on a phone it is a field showing the choice that opens a sheet of options, mounted only when open (346:8706)', () => {
        wide = false;
        const html = select();
        wide = true;
        expect(html).toMatch(/^<button type="button"[^>]*id="client-type"[^>]*aria-labelledby="client-type-label client-type-value"[^>]*aria-haspopup="dialog"[^>]*><span[^>]*id="client-type-value"[^>]*>Osoba prywatna<\/span><span[^>]*data-icon="chevron-down"><svg/);
        expect(html).not.toContain('role="combobox"');
        expect(html).not.toContain('>Firma<');

        wide = false;
        const changed = select('business');
        wide = true;
        expect(changed).toMatch(/id="client-type-value"[^>]*>Firma<\/span>/);
    });
});

describe('returnTarget', () => {
    const element = (id: string, menu: { id: string } | null = null, controls: string | null = null) =>
        ({ id, focus: () => undefined, closest: () => menu, getAttribute: () => controls }) as unknown as Element;

    test('a control outside a menu gets the focus back itself', () => {
        const button = element('edit');
        expect(returnTarget(button, () => [])).toBe(button as HTMLElement);
    });

    test('an item of the ••• menu hands the focus to the menu trigger, which outlives it', () => {
        const trigger = element('more', null, 'menu-1');
        const item = element('item', { id: 'menu-1' });
        expect(returnTarget(item, () => [element('other', null, 'menu-2'), trigger])).toBe(trigger as HTMLElement);
        expect(returnTarget(item, () => [])).toBeNull();
        expect(returnTarget(null)).toBeNull();
    });
});

describe('⌘↵ in a form dialog', () => {
    const press = (submit: { type: string; disabled: boolean } | null, keys: { metaKey?: boolean; ctrlKey?: boolean } = { metaKey: true }) => {
        let sent = 0;
        const form = { elements: [{ type: 'text' }, ...(submit ? [submit] : [])], requestSubmit: () => sent++ };
        submitOnCommandEnter({ key: 'Enter', metaKey: false, ctrlKey: false, ...keys, currentTarget: form as unknown as HTMLFormElement, preventDefault: () => undefined });

        return sent;
    };

    test('sends the form, with Ctrl as well as ⌘, and a step without a submit button too', () => {
        expect(press({ type: 'submit', disabled: false })).toBe(1);
        expect(press({ type: 'submit', disabled: false }, { ctrlKey: true })).toBe(1);
        expect(press(null)).toBe(1);
        expect(press({ type: 'submit', disabled: false }, {})).toBe(0);
    });

    test('sends nothing while the submit button is disabled, so a pending save is not posted twice', () => {
        expect(press({ type: 'submit', disabled: true })).toBe(0);
    });
});
