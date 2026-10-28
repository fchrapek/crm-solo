import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { MessageList, useFailureReason } from './message-list';
import type { MessageRow } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const row = (over: Partial<MessageRow>): MessageRow => ({
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

function Reason({ message }: { message: MessageRow }) {
    return <>{useFailureReason()(message)}</>;
}

describe('MessageList', () => {
    test('a failed text reads nieudana with a hint and offers Wyślij ponownie where Anuluj sits', () => {
        const html = renderToStaticMarkup(<MessageList rows={[row({ id: 2, status: 'failed', error: 'SMSAPI 13: Invalid phone number' })]} />);

        expect(html).toContain('data-status="failed"');
        expect(html).toContain('nieudana');
        expect(html).toContain('aria-label="Dlaczego się nie udało"');
        expect(html).toContain('Wyślij ponownie');
        expect(html).not.toContain('>Anuluj<');
    });

    test('a scheduled text keeps Anuluj and has nothing to send again', () => {
        const html = renderToStaticMarkup(<MessageList rows={[row({})]} />);

        expect(html).toContain('>Anuluj<');
        expect(html).not.toContain('Wyślij ponownie');
    });

    test('the hint tells a refusal from a missing answer, which may already have reached the client', () => {
        expect(renderToStaticMarkup(<Reason message={row({ status: 'failed', error: 'SMSAPI 13: Invalid phone number' })} />)).toBe(
            'Operator odrzucił SMS i nic nie wyszło. Powód: SMSAPI 13: Invalid phone number',
        );
        expect(renderToStaticMarkup(<Reason message={row({ status: 'failed', error: 'no answer', may_have_gone_out: true })} />)).toContain(
            'SMS mógł już dotrzeć do klienta',
        );
    });
});
