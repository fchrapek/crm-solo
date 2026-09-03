import { diffLines } from 'diff';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import styles from './markdown-diff.module.css';

interface Props {
    before: string;
    after: string;
}

interface DiffLine {
    kind: 'added' | 'removed' | 'context';
    text: string;
}

/**
 * Line-by-line unified diff renderer for markdown bodies. Powers the
 * "show changes" view inside the report revision history.
 *
 * Unchanged context lines are collapsed by default - we show 2 lines of
 * context around each hunk so the user has visual anchors without
 * scrolling through the entire prior body. Identical inputs render an
 * empty-state message instead of an empty box.
 */
export function MarkdownDiff({ before, after }: Props) {
    const { t } = useTranslation();

    const lines = useMemo<DiffLine[]>(() => collapseContext(toDiffLines(before, after), 2), [before, after]);

    if (lines.length === 0) {
        return <div className={styles.empty}>{t('No textual changes.')}</div>;
    }

    return (
        <div className={styles.root}>
            {lines.map((line, i) => (
                <div key={i} className={styles.line} data-kind={line.kind}>
                    <span className={styles.marker}>{line.kind === 'added' ? '+' : line.kind === 'removed' ? '−' : ' '}</span>
                    <span className={styles.text}>{line.text}</span>
                </div>
            ))}
        </div>
    );
}

function toDiffLines(before: string, after: string): DiffLine[] {
    const parts = diffLines(before, after, { newlineIsToken: false });
    const out: DiffLine[] = [];
    for (const part of parts) {
        const kind: DiffLine['kind'] = part.added ? 'added' : part.removed ? 'removed' : 'context';
        // jsdiff emits one chunk per added/removed/unchanged span; split into
        // physical lines so each row gets its own +/- marker.
        const chunkLines = part.value.split('\n');
        // Drop the trailing empty line that always trails a `\n`-terminated chunk.
        if (chunkLines[chunkLines.length - 1] === '') chunkLines.pop();
        for (const text of chunkLines) {
            out.push({ kind, text });
        }
    }
    return out;
}

/**
 * Collapse runs of context lines longer than 2*contextSize to:
 *   [contextSize context lines][gap marker][contextSize context lines]
 * Hunks (added/removed) are kept verbatim. Context at the start/end of
 * the diff is trimmed to contextSize so we don't show the whole prior
 * body just because most of it didn't change.
 */
function collapseContext(lines: DiffLine[], contextSize: number): DiffLine[] {
    // If there's no actual change, return nothing - empty state.
    if (!lines.some((l) => l.kind !== 'context')) return [];

    const out: DiffLine[] = [];
    let i = 0;
    while (i < lines.length) {
        const line = lines[i];
        if (line.kind !== 'context') {
            out.push(line);
            i += 1;
            continue;
        }

        let runEnd = i;
        while (runEnd < lines.length && lines[runEnd].kind === 'context') runEnd += 1;
        const runLen = runEnd - i;

        const isLeading = out.length === 0;
        const isTrailing = runEnd === lines.length;

        if (isLeading) {
            // Keep the LAST contextSize lines so we anchor the first hunk.
            const start = Math.max(i, runEnd - contextSize);
            for (let k = start; k < runEnd; k += 1) out.push(lines[k]);
        } else if (isTrailing) {
            // Keep the FIRST contextSize lines after the last hunk.
            const end = Math.min(runEnd, i + contextSize);
            for (let k = i; k < end; k += 1) out.push(lines[k]);
        } else if (runLen <= contextSize * 2) {
            // Short interior run - show it all.
            for (let k = i; k < runEnd; k += 1) out.push(lines[k]);
        } else {
            // Long interior run - show contextSize at each end with a gap.
            for (let k = i; k < i + contextSize; k += 1) out.push(lines[k]);
            out.push({ kind: 'context', text: '…' });
            for (let k = runEnd - contextSize; k < runEnd; k += 1) out.push(lines[k]);
        }
        i = runEnd;
    }
    return out;
}
