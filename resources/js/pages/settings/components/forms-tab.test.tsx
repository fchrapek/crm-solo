import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../lang/pl.json';
import type { IntakeFormRow, IntakeSettings } from '../types';
import { browserSnippet, failureText, forgetOnPageHide, FormsSide, FormsTab, noticeLine, originError, webhookExample } from './forms-tab';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const t = i18next.t.bind(i18next);
const text = (html: string) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');

const form: IntakeFormRow = {
    id: 4,
    name: 'Zapytanie o wycenę',
    endpoint: 'https://app.example.test/intake/3f9a6c21e84b40d6a5c2f19e7b3dc810',
    origins: ['https://sklep.example.test'],
    pipeline: 'kiwwwi',
    source: 'www-form',
    notify_to: 'biuro@example.test',
    active: true,
    turnstile_secret_set: false,
    webhook_secret_set: true,
    notices: { waiting: 1, failed: 2, sent: 12, skipped: 0 },
};

const intake = (overrides: Partial<IntakeSettings> = {}): IntakeSettings => ({
    form_id: null,
    forms: [form],
    failures: { '4': { no_recipient: 1, tries: 1 } },
    sender: { configured: true, enabled: true, host: 'smtp.example.test', port: 587, username: 'biuro@example.test', from_address: 'biuro@example.test', from_name: 'Biuro', password_set: true, password_readable: true, last_test: null },
    pipelines: [{ value: 'kiwwwi', label: 'Kiwwwi' }],
    sources: [{ value: 'www-form', label: 'Formularz WWW' }],
    max_age_days: 7,
    ...overrides,
});

describe('Ustawienia · Formularze', () => {
    test('a form row reads its failed, waiting and sent notices, and the reasons read as words with their counts', () => {
        expect(noticeLine(form, t)).toBe('2 nieudane · 1 czeka · 12 wysłanych');
        expect(failureText({ no_recipient: 1, tries: 1, auth: 2 }, t)).toBe('brak odbiorcy 1, wyczerpane próby 1, logowanie odrzucone 2');
    });

    test('the list names each form with a warning for its failed notices, and the sender shows its password only as set', () => {
        const list = text(renderToStaticMarkup(<FormsTab intake={intake()} />));
        const side = text(renderToStaticMarkup(<FormsSide intake={intake()} />));

        expect(list).toContain('Zapytanie o wycenę');
        expect(list).toContain('2 powiadomienia nie wyszły (Zapytanie o wycenę): brak odbiorcy 1, wyczerpane próby 1.');
        expect(side).toContain('Nadawca poczty');
        expect(side).toContain('587 · STARTTLS');
        expect(side).toContain('Hasło ustawione');
    });

    test('a form page warns about the missing Turnstile secret, shows the secrets only as set or not, and its snippets name only the endpoint', () => {
        const page = renderToStaticMarkup(<FormsTab intake={intake({ form_id: 4 })} />);
        const side = text(renderToStaticMarkup(<FormsSide intake={intake({ form_id: 4 })} />));

        expect(text(page)).toContain('przeglądarka dostaje odpowiedź 503');
        expect(text(page)).toContain('https://sklep.example.test');
        expect(side).toContain('Sekret Turnstile nieustawiony');
        expect(side).toContain('Sekret webhooka ustawiony');
        expect(side).toContain('Ponów nieudane (2)');
        expect(browserSnippet(form.endpoint)).toContain(`await fetch('${form.endpoint}'`);
        expect(webhookExample(form.endpoint)).toContain('Bearer $WEBHOOK_SECRET');
        expect(`${browserSnippet(form.endpoint)}${webhookExample(form.endpoint)}`).not.toMatch(/crmhook_/);
    });

    test('without a sender the tile says notices wait and offers to set one', () => {
        const side = text(renderToStaticMarkup(<FormsSide intake={intake({ sender: { configured: false } })} />));

        expect(side).toContain('Nie ma nadawcy. Powiadomienia o nowych leadach poczekają');
        expect(side).toContain('Ustaw nadawcę');
    });

    test('a one-time webhook secret is forgotten when the page is left and when Back restores it from the cache', () => {
        const page = new EventTarget();
        let forgotten = 0;
        const stop = forgetOnPageHide(page, () => forgotten++);
        const show = (persisted: boolean) => Object.assign(new Event('pageshow'), { persisted });

        page.dispatchEvent(new Event('pagehide'));
        page.dispatchEvent(show(false));
        expect(forgotten).toBe(1);
        page.dispatchEvent(show(true));
        expect(forgotten).toBe(2);

        stop();
        page.dispatchEvent(new Event('pagehide'));
        expect(forgotten).toBe(2);
    });

    test('the snippet keeps one key across retries of an enquiry and starts a new one after it went through or the form changed', async () => {
        const store = new Map<string, string>();
        const session = {
            getItem: (key: string) => store.get(key) ?? null,
            setItem: (key: string, value: string) => void store.set(key, value),
            removeItem: (key: string) => void store.delete(key),
        };
        let n = 0;
        const ids = { randomUUID: () => `key-${++n}` };
        const statuses = [500, 201, 201];
        const sent: string[] = [];
        const post = async (_url: string, init: { body: string }) => {
            sent.push(JSON.parse(init.body).idempotency_key);

            return { status: statuses.shift() ?? 201 };
        };
        const page = new EventTarget();
        const run = new Function('sessionStorage', 'crypto', 'fetch', 'turnstile', 'widgetId', 'form', `${browserSnippet('https://app.example.test/intake/abc')}\nreturn send;`);
        const send = run(session, ids, post, { getResponse: () => 'token' }, 'w', page) as (fields: Record<string, string>) => Promise<unknown>;
        const enquiry = { name: 'Anna', email: 'anna@example.test', message: 'Wycena' };

        await send(enquiry);
        await send(enquiry);
        await send(enquiry);
        page.dispatchEvent(new Event('input'));
        await send(enquiry);

        expect(sent).toEqual(['key-1', 'key-1', 'key-2', 'key-3']);
    });

    test("the allowed site shows the server's error whether it comes under origins or under one site's origins.N", () => {
        expect(originError({ 'origins.0': 'Podaj adres strony.' })).toBe('Podaj adres strony.');
        expect(originError({ origins: 'Dodaj co najmniej jedną stronę.' })).toBe('Dodaj co najmniej jedną stronę.');
        expect(originError({ name: 'Podaj nazwę.', 'origins_note.0': 'inne pole' })).toBeUndefined();
    });
});
