import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { FieldError, fieldControl } from './field';

describe('a field error outside SoloField', () => {
    test('renders the line the control points at, and nothing without an error', () => {
        const html = renderToStaticMarkup(<FieldError htmlFor="task-reportable" error="Otwórz raport ponownie." />);

        expect(html).toContain('id="task-reportable-error"');
        expect(html).toContain('Otwórz raport ponownie.');
        expect(fieldControl('task-reportable', 'x')['aria-describedby']).toBe('task-reportable-error');
        expect(renderToStaticMarkup(<FieldError htmlFor="task-reportable" />)).toBe('');
    });
});
