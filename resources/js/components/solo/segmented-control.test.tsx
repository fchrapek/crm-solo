import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { SegmentedControl } from './segmented-control';

const options = [
    { value: 'business', label: 'Firma' },
    { value: 'individual', label: 'Osoba prywatna' },
] as const;

describe('SegmentedControl', () => {
    test('a disabled control offers no segment to pick, as while its form saves', () => {
        const html = renderToStaticMarkup(<SegmentedControl label="Typ" value="business" onChange={() => {}} options={options} disabled />);

        expect(html.match(/<button[^>]*disabled=""/g)).toHaveLength(2);
        expect(renderToStaticMarkup(<SegmentedControl label="Typ" value="business" onChange={() => {}} options={options} />)).not.toContain('disabled=""');
    });
});
