import { expect, test } from 'bun:test';

test('asking whether the 3D dog may run opens one WebGL context, however often a page re-renders', async () => {
    let contexts = 0;
    const scope = globalThis as unknown as { window?: unknown; document?: unknown };
    const before = { window: scope.window, document: scope.document };
    scope.window = { matchMedia: () => ({ matches: false }) };
    scope.document = {
        createElement: () => ({
            getContext: () => {
                contexts += 1;

                return {};
            },
        }),
    };

    const { canAnimate } = await import('./dog');
    const answers = Array.from({ length: 40 }, () => canAnimate());

    scope.window = before.window;
    scope.document = before.document;

    expect(answers.every(Boolean)).toBe(true);
    expect(contexts).toBe(1);
});
