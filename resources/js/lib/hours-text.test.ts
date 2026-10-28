import { describe, expect, test } from 'bun:test';

import fixtures from '../../../tests/Fixtures/hours-text.json';
import { hoursTerms, hoursText, localeOf } from './hours-text';

describe('hours text', () => {
    test.each(fixtures.map((f) => [`${f.kind} ${f.locale} ${f.value}${f.signed ? ' signed' : ''}`, f] as const))(
        'the screens print %s exactly as the server does',
        (_, f) => {
            const locale = localeOf(f.locale);
            expect(f.kind === 'terms' ? hoursTerms(f.value, locale) : hoursText(f.value, locale, f.signed)).toBe(f.expected);
        },
    );
});
