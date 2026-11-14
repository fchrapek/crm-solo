import { describe, expect, test } from 'bun:test';
import { afterBilledSave } from './billed-save';

describe('the report editor after billed hours are saved', () => {
    test('an untouched editor takes the rewritten body and its version', () => {
        const result = afterBilledSave({ body: 'old', base: 'old', version: 'v1' }, { body_markdown: 'new summary', version: 'v2' });

        expect(result).toEqual({ draft: { body: 'new summary', base: 'new summary', version: 'v2' }, kept: false });
    });

    test('unsaved text is kept, with the version it was written against', () => {
        const draft = { body: 'my edit', base: 'old', version: 'v1' };

        expect(afterBilledSave(draft, { body_markdown: 'new summary', version: 'v2' })).toEqual({ draft, kept: true });
    });
});
