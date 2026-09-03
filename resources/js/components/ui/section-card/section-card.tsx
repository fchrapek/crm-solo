import * as React from 'react';

import styles from './section-card.module.css';

interface Props {
    /** Uppercase small heading; omit for a chrome-only card. */
    title?: React.ReactNode;
    /** Right-aligned header content: a primary action button, a count line. */
    actions?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}

/**
 * One logical block of a record view: a white card on the warm canvas with an
 * uppercase small heading — the Kontakty / Dokumenty anatomy from the client
 * Dane tab. Every content block on the record tabs sits in one of these; only
 * kanban boards render outside a card (they own horizontal scrolling).
 */
export function SectionCard({ title, actions, children, className }: Props) {
    return (
        <section className={className ? `${styles.card} ${className}` : styles.card}>
            {(title || actions) && (
                <div className={styles.header}>
                    {title && <h3 className={styles.heading}>{title}</h3>}
                    {actions && <div className={styles.actions}>{actions}</div>}
                </div>
            )}
            {children}
        </section>
    );
}
