import Markdown, { type Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';

import styles from './markdown-view.module.css';

const PLUGINS = [remarkGfm];

/** Markdown as GitHub renders it, task lists as read-only checkboxes; raw HTML in it is never rendered. */
export function MarkdownView({ children, components, className }: { children: string; components?: Components; className?: string }) {
    return (
        <div className={[styles.view, className].filter(Boolean).join(' ')}>
            <Markdown remarkPlugins={PLUGINS} components={components} skipHtml>
                {children}
            </Markdown>
        </div>
    );
}
