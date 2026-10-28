import * as AlertDialog from '@radix-ui/react-alert-dialog';
import * as Dialog from '@radix-ui/react-dialog';
import { describe, expect, test } from 'bun:test';
import type { ReactElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createFlashGate, formErrorText, isDialogOpen, summarisesFormErrors } from './flash-gate';

/** Runs the gate's real selector against server-rendered Radix markup. */
function documentOf(tree: ReactElement): Pick<Document, 'querySelector'> {
    const html = renderToStaticMarkup(tree);

    return {
        querySelector: (selector: string) => {
            let found = false;
            new HTMLRewriter()
                .on(selector, {
                    element() {
                        found = true;
                    },
                })
                .transform(html);

            return found ? ({} as Element) : null;
        },
    };
}

describe('flash gate', () => {
    test('a page that arrives with a new layout still gets the flash of the visit that opened it', () => {
        const gate = createFlashGate();
        gate.visitStarted();

        expect(gate.take(true)).toBe(true);
    });

    test('a flash is shown once, not again on the next render of the same props', () => {
        const gate = createFlashGate();
        gate.visitStarted();
        gate.take(true);

        expect(gate.take(true)).toBe(false);
    });

    test('the first load of a page without a visit shows no stale flash', () => {
        expect(createFlashGate().take(true)).toBe(false);
    });

    test('going back in history does not replay the flash stored with that entry', () => {
        const gate = createFlashGate();
        gate.visitStarted();
        gate.historyRestored();

        expect(gate.take(true)).toBe(false);
    });

    test('the first visit after a history step still shows its flash', () => {
        const gate = createFlashGate();
        gate.visitStarted();
        gate.take(true);
        gate.historyRestored();
        gate.visitStarted();

        expect(gate.take(true)).toBe(true);
    });

    test('after a history step, a page mounted by the next visit reads the gate that visit armed', () => {
        const gate = createFlashGate();
        gate.historyRestored();

        const oldLayoutStartsTheVisit = () => gate.visitStarted();
        const newLayoutMountsWithTheFlash = () => gate.take(true);
        oldLayoutStartsTheVisit();

        expect(newLayoutMountsWithTheFlash()).toBe(true);
        expect(newLayoutMountsWithTheFlash()).toBe(false);
    });

    test('a visit without a flash keeps waiting for the one that carries it', () => {
        const gate = createFlashGate();
        gate.visitStarted();

        expect(gate.take(false)).toBe(false);
        expect(gate.take(true)).toBe(true);
    });
});

describe('form error summary', () => {
    test('a page form gets the summary toast', () => {
        expect(summarisesFormErrors(2, false)).toBe(true);
    });

    test('an open dialog keeps its inline errors and gets no toast over its actions', () => {
        expect(summarisesFormErrors(2, true)).toBe(false);
    });

    test('no errors, no toast', () => {
        expect(summarisesFormErrors(0, false)).toBe(false);
    });

    test('an open Radix dialog or alert dialog counts as open', () => {
        const dialog = documentOf(
            <Dialog.Root open>
                <Dialog.Content>
                    <Dialog.Title>Edit</Dialog.Title>
                </Dialog.Content>
            </Dialog.Root>,
        );
        const alert = documentOf(
            <AlertDialog.Root open>
                <AlertDialog.Content>
                    <AlertDialog.Title>Delete?</AlertDialog.Title>
                </AlertDialog.Content>
            </AlertDialog.Root>,
        );

        expect(isDialogOpen(dialog)).toBe(true);
        expect(isDialogOpen(alert)).toBe(true);
    });

    test('a closed dialog still in the markup, or no dialog at all, does not count', () => {
        const closing = documentOf(
            <AlertDialog.Root open={false}>
                <AlertDialog.Content forceMount>
                    <AlertDialog.Title>Delete?</AlertDialog.Title>
                </AlertDialog.Content>
            </AlertDialog.Root>,
        );

        expect(isDialogOpen(closing)).toBe(false);
        expect(isDialogOpen(documentOf(<main data-state="open" />))).toBe(false);
        expect(isDialogOpen(undefined)).toBe(false);
    });
});

describe('the text of a refusal toast', () => {
    test('one refusal reads as itself, so a full or closed day says why', () => {
        expect(formErrorText({ task_id: 'Ten dzień jest zamknięty.' }, '1 błąd')).toBe('Ten dzień jest zamknięty.');
        expect(formErrorText({ task_id: ['Pierwszy', 'Drugi'] }, '1 błąd')).toBe('Pierwszy');
    });

    test('several keep the count', () => {
        expect(formErrorText({ name: 'a', email: 'b' }, '2 błędy')).toBe('2 błędy');
    });
});
