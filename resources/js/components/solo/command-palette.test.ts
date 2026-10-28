import { describe, expect, test } from 'bun:test';
import { paletteRows, type SearchGroup, type SearchItem } from './command-palette';

const item = (id: number): SearchItem => ({ id, title: `#${id}`, client: null, url: `/x/${id}`, archived: false, detail: {} });

describe('the palette rows', () => {
    const groups: SearchGroup[] = [
        { group: 'clients', total: 7, items: [1, 2, 3, 4, 5].map(item) },
        { group: 'tasks', total: 1, items: [item(9)] },
    ];

    test('each group lists its items, then pokaż więcej with what is left, in the order the keys walk', () => {
        const rows = paletteRows(groups, {});

        expect(rows.map((row) => (row.kind === 'item' ? row.item.id : `more ${row.left}`))).toEqual([1, 2, 3, 4, 5, 'more 2', 9]);
    });

    test('a group loaded in full shows all its items and no pokaż więcej', () => {
        const rows = paletteRows(groups, { clients: [1, 2, 3, 4, 5, 6, 7].map(item) });

        expect(rows.filter((row) => row.group === 'clients').map((row) => row.kind)).toEqual(Array(7).fill('item'));
    });
});
