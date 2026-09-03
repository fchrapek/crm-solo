import MarkdownIt from 'markdown-it';

// Plain-text view of a markdown string, for kanban-card subtitles, search
// hits and notification bodies. Reuses the chat renderer's markdown-it parser
// so construct coverage cannot drift between the two.
const md = new MarkdownIt({
    html: false,
    linkify: false,
    breaks: true,
    typographer: false,
}).disable(['image']);

export function markdownToPlainText(markdown: string | null | undefined): string {
    if (markdown == null) return '';
    const html = md.render(markdown);
    if (typeof DOMParser === 'undefined') {
        // SSR fallback: strip tags + collapse whitespace. Inferior but safe.
        return html
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }
    const doc = new DOMParser().parseFromString(html, 'text/html');
    return (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();
}

export function markdownPreview(markdown: string | null | undefined, maxChars = 120): string | null {
    const text = markdownToPlainText(markdown);
    if (text === '') return null;
    return text.length > maxChars ? text.slice(0, maxChars - 1).trimEnd() + '…' : text;
}
