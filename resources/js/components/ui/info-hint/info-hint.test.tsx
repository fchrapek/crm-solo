import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { hintLabelling, InfoHint } from './info-hint';

describe('InfoHint', () => {
    test('the trigger carries the id the open hint takes its name from, the default "i" and a custom trigger alike', () => {
        const plain = renderToStaticMarkup(<InfoHint label="Dlaczego">Tekst</InfoHint>);
        const custom = renderToStaticMarkup(
            <InfoHint trigger={<button type="button" aria-label="Claude pracuje" />}>Claude pracuje</InfoHint>,
        );

        expect(plain).toMatch(/<button[^>]*aria-label="Dlaczego"[^>]*id="[^"]+-trigger"|<button[^>]*id="[^"]+-trigger"[^>]*aria-label="Dlaczego"/);
        expect(custom).toMatch(/<button[^>]*id="[^"]+-trigger"[^>]*aria-haspopup="dialog"|<button[^>]*aria-haspopup="dialog"[^>]*id="[^"]+-trigger"/);
    });

    test('the open panel is named by the trigger and described by its text', () => {
        expect(hintLabelling('r1', 'Dlaczego', 'Bo tak liczy bank.')).toEqual({ 'aria-labelledby': 'r1-trigger', 'aria-describedby': 'r1-text' });
    });

    test('a text that only repeats the name is not read a second time', () => {
        expect(hintLabelling('r1', 'Claude pracuje · 14 min', 'Claude pracuje · 14 min')).toEqual({ 'aria-labelledby': 'r1-trigger' });
    });
});
