import { describe, expect, test } from 'bun:test';

import { accessLoginTarget } from './access-login';

const here = 'https://app.example.test/czesc';

describe('a visit Cloudflare Access refuses', () => {
    test('a refused link loads the page it pointed at, so Access can show its login', () => {
        expect(accessLoginTarget({ status: 401, config: { url: '/login', method: 'get' } }, here)).toBe('https://app.example.test/login');
    });

    test('a refused form submit reloads the page it was sent from', () => {
        expect(accessLoginTarget({ status: 401, config: { url: '/clients', method: 'post' } }, here)).toBe(here);
    });

    test('any other non-Inertia response keeps the error dialog', () => {
        expect(accessLoginTarget({ status: 500, config: { url: '/login', method: 'get' } }, here)).toBeNull();
        expect(accessLoginTarget({ status: 403, config: { url: '/login', method: 'get' } }, here)).toBeNull();
    });
});
