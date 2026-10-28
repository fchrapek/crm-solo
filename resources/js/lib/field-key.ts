/** A stored key from a label: "Termin wizji" is termin_wizji, "Źródło" is zrodlo. */
export function keyFromLabel(label: string): string {
    return label
        .toLowerCase()
        .replace(/ł/g, 'l')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^[^a-z]+|_+$/g, '')
        .slice(0, 64);
}

/** The key a new label gets on the account: numbered when the plain one is taken, never shown to the person. */
export function freeKey(label: string, taken: readonly string[], fallback: string): string {
    const base = (keyFromLabel(label) || fallback).slice(0, 60);
    let key = base;
    for (let n = 2; taken.includes(key); n++) {
        key = `${base}_${n}`;
    }

    return key;
}
