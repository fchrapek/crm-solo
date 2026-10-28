import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { monthGenitive, shortDate } from '@/lib/dates';
import pl from '../../../../../../lang/pl.json';
import type { MessageRow } from '../client-fields';
import { ClientOverviewTab } from './client-overview';
import { nextPeriod } from './format';
import { overviewActionOrder } from './overview-actions';
import type { ClientOrder, ClientOverview } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const quiet: ClientOverview = {
    card: null,
    sms: null,
    report_due: null,
    month_close: null,
    doing_count: 0,
    journal: { since: '2026-09-28', since_today: false, count: 0 },
    hours: {
        period: '2026-10',
        has_retainer: false,
        contracted: null,
        carried: 0,
        cap: null,
        available: 0,
        used: 0,
        remaining: 0,
        logged: 4.25,
        forfeited: 0,
        report_id: null,
        report_status: null,
    },
    order: null,
    order_waiting: null,
    settlement: { active_period: '2026-10', current_period: '2026-10', open_behind: false },
};

const busy: ClientOverview = {
    card: null,
    sms: null,
    report_due: { period: '2026-09', report_id: null, status: null },
    month_close: { period: '2026-09', started: false, done: 0, total: 15 },
    doing_count: 12,
    journal: { since: '2026-09-28', since_today: false, count: 4 },
    hours: {
        ...quiet.hours!,
        period: '2026-09',
        has_retainer: true,
        contracted: 30,
        carried: -9.5,
        available: 20.5,
        used: 36.5,
        remaining: -16,
        logged: 40,
    },
    order: null,
    order_waiting: null,
    settlement: { active_period: '2026-10', current_period: '2026-10', open_behind: false },
};

const render = (overview: ClientOverview) => renderToStaticMarkup(<ClientOverviewTab clientId={7} overview={overview} />);

describe('ClientOverviewTab', () => {
    test('a quiet client says nothing is waiting and counts every logged hour', () => {
        const html = render(quiet);
        expect(html).toContain('Nic nie czeka.');
        expect(html).toContain('Nic w toku →');
        expect(html).toContain('Nic nowego od 28.09 →');
        expect(html).toContain('4,25 h');
        expect(html).toContain('Bez abonamentu');
        expect(html).not.toContain('Bilans na');
    });

    test('the Figma state reads as the frame does', () => {
        const html = render(busy);
        expect(html).toContain('Raport za wrzesień');
        expect(html).toContain('href="/clients/7/edit#reports"');
        expect(html).toContain('Wygeneruj');
        expect(html).toContain('Zamknięcie września, 0 z 15');
        expect(html).toContain('href="/month-close?period=2026-09"');
        expect(html).toContain('12 w toku →');
        expect(html).toContain('href="/clients/7/zadania"');
        expect(html).toContain('4 nowe od 28.09');
        expect(html).toContain('href="/clients/7/dziennik"');
        expect(html).toContain('+30,0 h');
        expect(html).toContain('−9,5 h');
        expect(html).toContain('−36,5 h');
        expect(html).toContain('20,5 h do dyspozycji');
        expect(html).toContain('16,0 h ponad');
        expect(html).toContain('−16,0 h');
        expect(html).toContain('Bilans na październik');
    });

    test('an existing report opens itself instead of the generate flow', () => {
        const html = render({ ...busy, report_due: { period: '2026-09', report_id: 31, status: 'draft' } });
        expect(html).toContain('Raport za wrzesień, szkic');
        expect(html).toContain('href="/clients/7/reports/31"');
        expect(html).not.toContain('Wygeneruj');
    });

    test('Polish plurals pick the form the count needs', () => {
        expect(render({ ...busy, journal: { since: '2026-09-28', since_today: false, count: 1 } })).toContain('1 nowy od 28.09');
        expect(render({ ...busy, journal: { since: '2026-09-28', since_today: false, count: 5 } })).toContain('5 nowych od 28.09');
        expect(render({ ...busy, journal: { since: '2026-09-28', since_today: false, count: 22 } })).toContain('22 nowe od 28.09');
        expect(render({ ...busy, journal: { since: '2026-10-05', since_today: true, count: 3 } })).toContain('3 nowe od dziś');
        expect(render({ ...busy, journal: { since: '2026-10-05', since_today: true, count: 0 } })).toContain('Nic nowego od dziś');
    });

    test('the rollover cap shows as its own line when it cut the pool', () => {
        expect(render({ ...busy, hours: { ...busy.hours!, forfeited: 2 } })).toContain('Ponad limit kumulacji');
        expect(render(busy)).not.toContain('Ponad limit kumulacji');
    });
});

describe('overview format', () => {
    test('a month after a noun takes the Polish genitive', () => {
        expect(monthGenitive('2026-09', 'pl-PL')).toBe('września');
        expect(monthGenitive('2026-09', 'en-GB')).toBe('September');
    });

    test('the balance month rolls over the year', () => {
        expect(nextPeriod('2026-09')).toBe('2026-10');
        expect(nextPeriod('2026-12')).toBe('2027-01');
    });

    test('the since date is read off the date string', () => {
        expect(shortDate('2026-09-28', 'pl-PL')).toBe('28.09');
    });
});

describe('Przegląd with a month left open', () => {
    const behind: ClientOverview = {
        ...busy,
        settlement: { active_period: '2026-09', current_period: '2026-10', open_behind: true },
    };

    test('the hours block names the open month under the balance and offers to close it', () => {
        const html = render(behind);
        const text = html.replace(/<[^>]+>/g, '');

        expect(text).toContain('Bilans na październik');
        expect(text).toContain('Jest już październik · wrzesień jeszcze otwarty');
        expect(html).toMatch(/<button[^>]*>Zamknij miesiąc<\/button>/);
    });

    test('a client settled up to the running month gets neither the note nor the button', () => {
        const text = render(busy).replace(/<[^>]+>/g, '');

        expect(text).not.toContain('jeszcze otwarty');
        expect(text).not.toContain('Zamknij miesiąc');
    });
});

const orderFixture: ClientOrder = {
    title: 'Audyt energetyczny domu',
    project: null,
    started_on: null,
    hours: 0,
    month_hours: 0,
    tasks: { done: 0, total: 0 },
    state: null,
    invoice: null,
};

describe('Przegląd for a client in the order layout', () => {
    const order: ClientOrder = {
        title: 'Audyt energetyczny domu',
        project: null,
        started_on: '2026-09-15',
        hours: 10,
        month_hours: 3.5,
        tasks: { done: 2, total: 4 },
        state: 'in_progress',
        invoice: {
            id: 1,
            number: '29/09/2026',
            state: 'unpaid',
            currency: 'PLN',
            gross: 2400,
            net: 1951.22,
            invoice_date: '2026-09-29',
            payment_date: '2026-10-14',
            paid_date: null,
            overdue: false,
        },
    };
    const textOf = (overview: ClientOverview) =>
        render(overview)
            .replace(/<[^>]+>/g, '')
            .replace(/[\u00a0\u202f]/g, ' ');

    test('the Zlecenie block takes the place of the hours, with no report, close or balance', () => {
        const text = textOf({ ...quiet, order });

        expect(text).toContain('Zlecenie');
        expect(text).toContain('w trakcie');
        expect(text).toContain('Audyt energetyczny domuod 15.09');
        expect(text).toContain('Kwota2 400 zł brutto');
        expect(text).toContain('Czas10,0 h');
        expect(text).toContain('2 z 4 zadań zrobione');
        expect(text).toContain('Faktura 29/09/2026 · czeka na wpłatę');
        expect(text).toContain('Wystawiona 29.09 · termin płatności 14.10');
        expect(text).not.toContain('Godziny');
        expect(text).not.toContain('Zamknij miesiąc');
        expect(text).not.toContain('Bilans na');
    });

    test('each invoice state reads as the board names it', () => {
        expect(textOf({ ...quiet, order: { ...order, invoice: { ...order.invoice!, state: 'draft' } } })).toContain('· szkic');
        expect(textOf({ ...quiet, order: { ...order, invoice: { ...order.invoice!, state: 'paid', paid_date: '2026-10-02' } } })).toContain(
            '· opłacona',
        );
    });

    test('an overdue invoice says so in the accent', () => {
        const html = render({ ...quiet, order: { ...order, invoice: { ...order.invoice!, overdue: true } } });

        expect(html).toContain('<span data-tone="over">po terminie od 14.10</span>');
    });

    test('without an invoice the poster says there is none and the amount row is gone', () => {
        const text = textOf({ ...quiet, order: { ...order, invoice: null } });

        expect(text).toContain('brak faktury');
        expect(text).not.toContain('Kwota');
    });

    test('one task out of one takes the singular genitive', () => {
        expect(textOf({ ...quiet, order: { ...order, tasks: { done: 0, total: 1 } } })).toContain('0 z 1 zadania zrobione');
    });
});

describe('Czeka na ciebie for an order client', () => {
    const waiting = (order_waiting: ClientOverview['order_waiting']): ClientOverview => ({
        ...quiet,
        hours: null,
        settlement: null,
        order: orderFixture,
        order_waiting,
    });
    const text = (overview: ClientOverview) => render(overview).replace(/<[^>]+>/g, '');

    test('the next dated task waits with Start and an unpaid invoice with Otwórz', () => {
        const html = render(
            waiting({
                task: { task_id: 4, name: 'Raport z audytu', due: '2026-10-09', running_id: null },
                invoice: { id: 9, number: '29/09/2026', payment_date: '2026-10-14', overdue: false, external_url: 'https://app.infakt.test/9' },
            }),
        );
        const plain = html.replace(/<[^>]+>/g, '');

        expect(plain).toContain('Raport z audytu, termin 09.10');
        expect(html).toMatch(/<a[^>]*href="\/tasks\/4"[^>]*>Raport z audytu, termin 09.10<\/a>/);
        expect(html).toMatch(/<button[^>]*>Start<\/button>/);
        expect(plain).toContain('Faktura 29/09/2026 czeka na wpłatę, termin 14.10');
        expect(html).toMatch(/<a[^>]*href="https:\/\/app.infakt.test\/9"[^>]*>Otwórz<\/a>/);
        expect(plain).not.toContain('Nic nie czeka.');
    });

    test('a running timer offers Stop, an overdue invoice says so, and nothing waiting says so', () => {
        expect(render(waiting({ task: { task_id: 4, name: 'Raport', due: '2026-10-09', running_id: 12 }, invoice: null }))).toMatch(
            /<button[^>]*>Stop<\/button>/,
        );
        expect(
            text(waiting({ task: null, invoice: { id: 9, number: null, payment_date: '2026-09-03', overdue: true, external_url: null } })),
        ).toContain('Faktura Bez numeru po terminie od 03.09');
        expect(text(waiting({ task: null, invoice: null }))).toContain('Nic nie czeka.');
    });
});

describe('Przegląd on phones', () => {
    const behind: ClientOverview = { ...busy, settlement: { active_period: '2026-09', current_period: '2026-10', open_behind: true } };

    test('the balance is the poster, in vermilion when negative, and bilans na opens the Godziny sheet, which stays unmounted', () => {
        const html = render(busy);

        expect(html).toMatch(/<p[^>]*data-tone="over"[^>]*>[−-]16,0 h<\/p>/);
        expect(html).toMatch(/<button type="button"[^>]*aria-haspopup="dialog"[^>]*>bilans na październik<span[^>]*data-icon="chevron-right"><svg/);
        expect(html).not.toContain('Godziny · wrzesień');
        expect(html.indexOf('data-tone="over"')).toBeLessThan(html.indexOf('bilans na październik'));
    });

    test('an open month adds the note and the outlined Zamknij miesiąc for phones beside the desktop action', () => {
        const html = render(behind);
        const closeButtons = [...html.matchAll(/<button[^>]*>Zamknij miesiąc<\/button>/g)];

        expect(html).toContain('Jest już październik · wrzesień jeszcze otwarty');
        expect(closeButtons).toHaveLength(2);
        expect(html.indexOf('jeszcze otwarty')).toBeGreaterThan(html.indexOf('bilans na październik'));
    });

    test('a positive balance reads in ink', () => {
        const html = render({ ...busy, hours: { ...busy.hours!, used: 6.5, remaining: 14 } });

        expect(html).toMatch(/<p[^>]*>\+14,0 h<\/p>/);
        expect(html).not.toMatch(/data-tone="over"[^>]*>\+14/);
    });

    test('an order client gets Zlecenie with no balance link, and its task waits with a phone Start beside the desktop one', () => {
        const html = render({
            ...quiet,
            hours: null,
            settlement: null,
            order: { ...orderFixture, state: 'in_progress', tasks: { done: 2, total: 4 } },
            order_waiting: { task: { task_id: 4, name: 'Raport z audytu', due: '2026-10-09', running_id: null }, invoice: null },
        });

        expect(html).toContain('Zlecenie');
        expect(html).toContain('>w trakcie<');
        expect(html).not.toContain('bilans na');
        expect(html).not.toContain('Zamknij miesiąc');
        expect([...html.matchAll(/<button[^>]*>(?:<span[^>]*data-icon="play"><svg.*?<\/svg><\/span>)?Start<\/button>/g)]).toHaveLength(2);
        expect(html).toMatch(/<button[^>]*><span[^>]*data-icon="play"><svg[^>]*>.*?<\/svg><\/span>Start<\/button>/);
    });

    test('a waiting row with a link is one row target on a phone, named by its title', () => {
        const html = render(busy);
        const [, titleId] = html.match(/<span[^>]*id="([^"]+)"[^>]*>Raport za wrzesień<\/span>/) ?? [];

        expect(titleId).toBeDefined();
        expect(html).toContain(`aria-labelledby="${titleId}"`);
    });

    test('the ••• menu leads with Generuj raport, then Kopiuj and the pin; an order client has no report', () => {
        expect(overviewActionOrder(true, 'retainer')).toEqual(['report', 'copy', 'pin']);
        expect(overviewActionOrder(false, 'retainer')).toEqual(['copy', 'report', 'pin']);
        expect(overviewActionOrder(true, 'order')).toEqual(['copy', 'pin']);
        expect(overviewActionOrder(false, 'order')).toEqual(['copy', 'pin']);
    });
});

describe('Przegląd phone rules', () => {
    test('an empty Czeka na ciebie keeps the top rule the rows have on a phone and drops it from 768 px', async () => {
        const css = await Bun.file(new URL('./client-overview.module.css', import.meta.url)).text();
        const [phone, desktop] = css.split('@media (min-width: 768px)');

        expect(phone).toMatch(
            /\.main \.waiting,\s*\.main \.empty,\s*\.hours > \.ledger \{\s*border-top: var\(--solo-head-rule, 1px\) solid var\(--solo-rule\);/,
        );
        expect(desktop).toMatch(/\.main \.waiting,\s*\.main \.empty,\s*\.hours > \.ledger \{\s*border-top: 0;/);
        expect(render(quiet)).toMatch(/<p[^>]*>Nic nie czeka\.<\/p>/);
    });
});

describe('Przegląd SMS for an account with client fields', () => {
    const sms = (over: Partial<MessageRow>): MessageRow => ({
        id: 1,
        client_id: 7,
        client: 'Jan Kowalczyk',
        day: '2026-10-22',
        time: '18:00',
        status: 'pending',
        held: false,
        template: 'Przypomnienie',
        body: 'Wizja jutro o 10:00.',
        to: '+48100100200',
        error: null,
        may_have_gone_out: false,
        resends: 0,
        previous_error: null,
        expires: '2026-10-22 20:00',
        ...over,
    });
    const card: ClientOverview = {
        ...quiet,
        hours: null,
        settlement: null,
        card: { fields: [], assignee: null, sms_opt_out: false, users: [] },
        sms: [sms({ id: 2, status: 'failed', template: 'Potwierdzenie', error: 'SMSAPI 13: Invalid phone number' }), sms({})],
        sms_pending: 1,
        sms_failed: 1,
        sms_live: true,
    };

    test('the head counts the failed text beside the scheduled one, which a phone leaves out', () => {
        expect(render(card)).toMatch(/SMS · 1 nieudana<span[^>]*> · 1 zaplanowana<\/span>/);
        expect(render({ ...card, sms: [sms({})], sms_failed: 0 })).toContain('SMS · 1 zaplanowana');
        expect(render({ ...card, sms: [card.sms![0]], sms_pending: 0 })).toContain('SMS · 1 nieudana<');
    });

    test('a failed text waits for you as one row with its reason in a hint and Wyślij ponownie', () => {
        const html = render(card);
        const waiting = html.slice(html.indexOf('Czeka na ciebie'), html.indexOf('Pola klienta'));

        expect(waiting).toContain('Nieudany SMS: Potwierdzenie');
        expect(waiting).toContain('aria-label="Dlaczego się nie udało"');
        expect(waiting).toContain('Wyślij ponownie');
        expect(waiting).not.toContain('Invalid phone number');
    });
});
