import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { AlertTitle } from '@/components/ui/alert';
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { fullText, plainText } from '@/lib/plain-text';
import pl from '../../../../lang/pl.json';
import { PhoneNavBar } from './client-header';
import { ResponsiveSelect } from './responsive-select';
import { ListRow } from './list-row';
import { ListTableCell } from './list-table';
import { SectionToggle } from './section-toggle';
import { SegmentedControl } from './segmented-control';
import { Token } from './shell/command-bar';
import { TimerStrip } from './shell/timer-strip';
import { TaskRow } from './task-row';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const root = join(import.meta.dir, '../../..');

/** Every stylesheet rule that ends a text with an ellipsis or clamps it to a line count, and where its element gets its whole text. */
const CUT_TEXTS: Record<string, string> = {
    'css/pages.css .cellContent': 'unused: no element carries the class',
    'js/components/manual-time-entry-dialog/manual-time-entry-dialog.module.css .runningTitle': 'title on the span',
    'js/components/solo/client-header.module.css .backLabel': 'PhoneNavBar: title on the back label',
    'js/components/solo/client-header.module.css .name': 'ClientHeader: titleAttr on the h1',
    'js/components/solo/command-palette.module.css .row > .caption:last-child': 'search results: title on the caption',
    'js/components/solo/command-palette.module.css .title': 'search results: title on the title',
    "js/components/solo/mobile.module.css .sheetHeader[data-action='save'] .sheetTitle": 'BottomSheet: title on SheetTitle',
    'js/components/solo/mobile.module.css .selectValue': 'ResponsiveSelect: title on the value',
    "js/components/solo/mobile.module.css .listRow[data-size='large'] .listTitle": 'ListRow: title on the title',
    'js/components/solo/mobile.module.css .listSubText': 'ListRow: title on the sub text',
    'js/components/solo/mobile.module.css .listAgentText': 'ListRow: title on the agent label',
    'js/components/solo/row.module.css .meta': 'Row: title on meta and wideMeta',
    'js/components/solo/row.module.css .part': 'Row: the meta around the parts carries the whole line',
    'js/components/solo/row.module.css .detail dd': 'Row: title on each detail value but the wide one',
    'js/components/solo/row.module.css .title': 'Row: title on the title',
    'js/components/solo/row.module.css .titleText': 'Row: title on the title around the linked text',
    'js/components/solo/row.module.css .item .title:not(:has(> .link[data-cut]))': 'Row: title on the title',
    'js/components/solo/row.module.css .item .titleText': 'Row: title on the title around the linked text',
    'js/components/solo/section-toggle.module.css .label': 'SectionToggle: title on the label',
    'js/components/solo/section-toggle.module.css .phoneSummary': 'SectionToggle: title on the phone summary',
    'js/components/solo/section-toggle.module.css .summary': 'SectionToggle: title on the summary',
    'js/components/solo/shell/command-bar.module.css .token': 'Token: title on every token',
    'js/components/solo/shell/timer-strip.module.css .title': 'TimerStrip: title on the task',
    'js/components/solo/task-row.module.css .titleText': 'TaskRow: title on the title paragraph',
    'js/components/solo/task-row.module.css .client': 'TaskRow: title on the meta paragraph',
    'js/components/solo/task-row.module.css .project': 'TaskRow: title on the meta paragraph',
    'js/components/ui/alert/alert.module.css .title': 'AlertTitle: title from its text',
    'js/components/ui/select/select.module.css .trigger > span': 'SelectTrigger: title from the chosen SelectItem, read by Select',
    'js/components/ui/toggle-group/toggle-group.module.css .group.segmented[data-width="full"] .item > span:not([data-icon])':
        'SegmentedControl: title on each option',
    'js/pages/clients/components/client-journal/client-journal.module.css .authorName': 'Author: title on the name',
    'js/pages/clients/components/client-fields/client-fields.module.css .body': 'Row: the title derived from the message button',
    'js/pages/clients/components/connect-trello-dialog/connect-trello-dialog.module.css .modeButton': 'title on each mode button',
    'js/pages/clients/components/connect-trello-dialog/connect-trello-dialog.module.css .boardOptionText': 'title on each board option',
    'js/pages/clients/components/list-mapping-dialog/list-mapping-dialog.module.css .listName': 'title on each list and lane name',
    'js/pages/clients/index.module.css .rowLink': 'title on the link (the full name)',
    'js/pages/today/add-or-pick.module.css .optionMeta': 'add field: title on the client and project',
    'js/pages/today/add-or-pick.module.css .taskOption .optionTitle': 'add field: title on the task name',
    'js/pages/contacts/index.module.css .rowButton': 'title on the person button',
    'js/pages/contacts/index.module.css .rowLink': 'title on the client link',
    'js/pages/leads/index.module.css .rowLink': 'title on the lead link',
    'js/pages/month-close/summary-email.module.css .address': 'title on each address',
    'js/pages/month-close/summary-email.module.css .name': 'title on each name',
    'js/pages/tasks/components/task-page/task-page.module.css .descriptionBody[data-clamped] .markdown':
        'exempt: rendered Markdown with its own Więcej button',
    'js/pages/today/today.module.css .taskTitle': 'title on the timer view heading',
    'js/pages/today/today.module.css .where': 'title on the client and project line',
};

function stylesheets(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? stylesheets(path) : path.endsWith('.css') ? [path] : [];
    });
}

function cutRules(): string[] {
    return stylesheets(join(root, 'css'))
        .concat(stylesheets(join(root, 'js')))
        .flatMap((path) =>
            [
                ...readFileSync(path, 'utf8')
                    .replace(/\/\*[\s\S]*?\*\//g, '')
                    .matchAll(/([^{}]+)\{([^{}]*)\}/g),
            ]
                .filter(([, , body]) => /text-overflow:\s*ellipsis|line-clamp:\s*\d/.test(body))
                .flatMap(([, selectors]) => selectors.split(',').map((selector) => `${relative(root, path)} ${selector.trim()}`)),
        );
}

describe('a text cut with an ellipsis', () => {
    test('every stylesheet rule that cuts a text is listed with the place its element gets the whole text as a tooltip', () => {
        expect([...new Set(cutRules())].sort()).toEqual(Object.keys(CUT_TEXTS).sort());
    });

    test('the tooltip text is read from strings and HTML elements, never from inside a component', () => {
        const Hint = ({ children }: { children: string }) => <i>{children}</i>;

        expect(plainText(['Pracownia ', <b key="b">Mebli</b>, 7])).toBe('Pracownia Mebli7');
        expect(
            fullText(
                <>
                    Nieudany SMS <Hint>Invalid phone number</Hint>
                </>,
            ),
        ).toBe('Nieudany SMS');
        expect(fullText(<span> </span>)).toBeUndefined();
    });

    test('a truncating list table cell carries its text as the tooltip', () => {
        const html = renderToStaticMarkup(
            <table>
                <tbody>
                    <tr>
                        <ListTableCell kind="title" truncate>
                            <span>Pracownia Mebli Przykładowa</span>
                        </ListTableCell>
                    </tr>
                </tbody>
            </table>,
        );

        expect(html).toMatch(/<td[^>]*><div[^>]*title="Pracownia Mebli Przykładowa">/);
    });

    test('the shell parts that cut a name carry it whole: tokens, the timer strip and the record nav bar', () => {
        expect(renderToStaticMarkup(<Token label="Pracownia Mebli Przykładowa" href="/clients/7" />)).toContain(
            'title="Pracownia Mebli Przykładowa"',
        );
        expect(renderToStaticMarkup(<Token label="Rozliczenia" />)).toContain('title="Rozliczenia"');
        expect(
            renderToStaticMarkup(
                <TimerStrip
                    timer={{ id: 1, started_at: '2026-11-19T08:00:00Z', task: 'Korekta faktury za wrzesień', client: null, project: null } as never}
                />,
            ),
        ).toMatch(/<span[^>]*title="Korekta faktury za wrzesień">Korekta faktury za wrzesień<\/span>/);
        expect(renderToStaticMarkup(<PhoneNavBar back={{ href: '/clients', label: 'Pracownia Mebli Przykładowa' }} />)).toMatch(
            /<span[^>]*title="Pracownia Mebli Przykładowa">Pracownia Mebli Przykładowa<\/span>/,
        );
    });

    test('a list row, a section row and a task row carry every cut text as the tooltip', () => {
        const list = renderToStaticMarkup(
            <ListRow title="Pracownia Mebli Przykładowa" sub="1,3 z 7,75 h" size="large" marks={{ agent: 'claude pracuje' }} />,
        );
        const section = renderToStaticMarkup(
            <SectionToggle label="Zadania w toku" summary="3" phoneSummary="1 z 3">
                <p>body</p>
            </SectionToggle>,
        );
        const task = renderToStaticMarkup(
            <TaskRow task={{ task_id: 4, name: 'Przepisanie strony', client: 'Pracownia Mebli', project: 'Sklep', done: false }} />,
        );

        expect(list).toContain('title="Pracownia Mebli Przykładowa"');
        expect(list).toContain('title="1,3 z 7,75 h"');
        expect(list).toContain('title="claude pracuje"');
        expect(section).toMatch(/<h2[^>]*title="Zadania w toku"/);
        expect(section).toMatch(/<p[^>]*title="3"/);
        expect(section).toMatch(/<p[^>]*title="1 z 3"/);
        expect(task).toContain('title="Przepisanie strony"');
        expect(task).toContain('title="Pracownia Mebli · Sklep"');
    });

    test('a select trigger carries the chosen option as the tooltip, controlled or not, and an explicit title wins', () => {
        const options = (
            <SelectContent>
                <SelectGroup>
                    <SelectItem value="a">Pracownia Mebli Przykładowa</SelectItem>
                    <SelectItem value="b">
                        <span>Szkoła</span> Językowa Testowa
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        );
        const select = (props: { value?: string; defaultValue?: string }, title?: string) =>
            renderToStaticMarkup(
                <Select {...props}>
                    <SelectTrigger title={title}>
                        <SelectValue placeholder="Wybierz" />
                    </SelectTrigger>
                    {options}
                </Select>,
            ).match(/<button[^>]*>/)?.[0] ?? '';

        expect(select({ value: 'a' })).toContain('title="Pracownia Mebli Przykładowa"');
        expect(select({ defaultValue: 'b' })).toContain('title="Szkoła Językowa Testowa"');
        expect(select({ value: 'a' }, 'Tablica klienta')).toContain('title="Tablica klienta"');
        expect(select({})).not.toContain('title=');
        expect(
            renderToStaticMarkup(
                <ResponsiveSelect
                    label="Klient"
                    value="7"
                    onValueChange={() => {}}
                    options={[{ value: '7', label: <span>Pracownia Mebli</span> }]}
                    sheet={false}
                    control={{ id: 'client' }}
                />,
            ),
        ).toMatch(/<button[^>]*title="Pracownia Mebli"/);
    });

    test('segmented options and an alert title carry their text as the tooltip', () => {
        const links = renderToStaticMarkup(
            <SegmentedControl
                label="Dzień"
                width="full"
                value="today"
                options={[
                    { value: 'today', label: <span>Dziś i wczoraj</span>, href: '/' },
                    { value: 'plan', label: 'Jutro', href: '/tomorrow' },
                ]}
            />,
        );
        const toggles = renderToStaticMarkup(
            <SegmentedControl label="Status" width="full" value="a" onChange={() => {}} options={[{ value: 'a', label: 'Aktywni klienci' }]} />,
        );

        expect(links).toMatch(/<a[^>]*title="Dziś i wczoraj"/);
        expect(links).toMatch(/<a[^>]*title="Jutro"/);
        expect(toggles).toMatch(/<button[^>]*title="Aktywni klienci"/);
        expect(renderToStaticMarkup(<AlertTitle>Nie udało się połączyć z Trello</AlertTitle>)).toContain('title="Nie udało się połączyć z Trello"');
    });
});
