import { describe, expect, test } from 'bun:test';
import { closeSettingsPayload } from './month-close-dialog';

describe('the month close settings save', () => {
    test('the inclusion is always sent as the dialog shows it, so a No stays No after the type is set', () => {
        const details = { type: 'business' as const, name: 'Pracownia' };

        expect(closeSettingsPayload(details, { include: '0', ssh: '', invoice: '' })).toMatchObject({ include_in_month_close: false });
        expect(closeSettingsPayload(details, { include: '1', ssh: 'meble', invoice: '' })).toMatchObject({
            include_in_month_close: true,
            ssh_config: 'meble',
        });
    });
});
