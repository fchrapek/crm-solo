import { describe, expect, test } from 'bun:test';

import { dialogSession, type UserDialogMode } from './user-dialog';

const profile = (first: string): UserDialogMode => ({ kind: 'profile', user: { id: 1, first_name: first, last_name: 'Zielińska', email: 'anna@example.test', owner: true } });

describe('the user dialog session', () => {
    test("a parent's rerender with a fresh mode object for the same user keeps the session, so the draft survives a polled page", () => {
        expect(dialogSession(profile('Anna'))).toBe(dialogSession(profile('Anna')));
        expect(dialogSession(profile('Anna'))).toBe(dialogSession(profile('Anka')));
    });

    test('another user, another kind or a close starts a new session', () => {
        const member = { id: 2, first_name: 'Marta', last_name: 'Członkini', email: 'marta@example.test', name: 'Marta Członkini', owner: false, is_me: false, deleted_at: null, can_delete: true };

        expect(dialogSession({ kind: 'edit', user: member })).not.toBe(dialogSession(profile('Anna')));
        expect(dialogSession({ kind: 'create' })).toBe('create');
        expect(dialogSession(null)).toBeNull();
    });
});
