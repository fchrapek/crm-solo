import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../lang/pl.json';
import { SmsTab } from './sms-tab';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const sending = { quiet_from: '20:00', quiet_to: '08:00', ascii: false, provider: 'simulator', smsapi_connected: false };

describe('SmsTab', () => {
    test('the phone list names what sends the messages, simulator or SMSAPI', () => {
        const simulated = renderToStaticMarkup(<SmsTab sending={sending} templates={[]} rules={[]} fields={[]} />).replace(/<[^>]+>/g, '');
        const real = renderToStaticMarkup(
            <SmsTab sending={{ ...sending, provider: 'smsapi', smsapi_connected: true }} templates={[]} rules={[]} fields={[]} />,
        ).replace(/<[^>]+>/g, '');

        expect(simulated).toContain('Wysyłka przezSymulator');
        expect(real).toContain('Wysyłka przezSMSAPI');
    });
});
