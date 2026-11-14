import Markdown, { type Components } from 'react-markdown';
import remarkBreaks from 'remark-breaks';
import remarkGfm from 'remark-gfm';

import styles from './markdown-view.module.css';

const PLUGINS = [remarkGfm];

const WITH_BREAKS = [remarkGfm, remarkBreaks];

/**
 * Markdown as GitHub renders it, task lists as read-only checkboxes; raw HTML in it is never rendered.
 * breaks: every line break is kept, as an email renders it (ClientSummaryMail).
 */
export function MarkdownView({
    children,
    components,
    className,
    breaks = false,
}: {
    children: string;
    components?: Components;
    className?: string;
    breaks?: boolean;
}) {
    return (
        <div className={[styles.view, className].filter(Boolean).join(' ')}>
            <Markdown remarkPlugins={breaks ? WITH_BREAKS : PLUGINS} components={components} skipHtml>
                {children}
            </Markdown>
        </div>
    );
}
