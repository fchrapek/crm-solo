import { Fragment, isValidElement, type ReactNode } from 'react';

/** The text a node shows, read from its strings and its HTML elements; a component reads as empty, since its children may be a hint or a menu rather than what shows. */
export function plainText(node: ReactNode): string {
    if (typeof node === 'string' || typeof node === 'number') {
        return String(node);
    }

    if (Array.isArray(node)) {
        return node.map(plainText).join('');
    }

    if (isValidElement<{ children?: ReactNode }>(node) && (typeof node.type === 'string' || node.type === Fragment)) {
        return plainText(node.props.children);
    }

    return '';
}

/** The native tooltip of a text that may end in an ellipsis: its whole value, or nothing when no text can be read. */
export function fullText(node: ReactNode): string | undefined {
    const text = plainText(node).replace(/\s+/g, ' ').trim();

    return text === '' ? undefined : text;
}
