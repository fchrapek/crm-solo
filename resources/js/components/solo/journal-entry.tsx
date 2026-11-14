import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Chip } from './chip';
import styles from './journal-entry.module.css';

interface JournalEntryProps {
    /** The date and who wrote it. */
    meta: ReactNode;
    /** What the entry is when it is not a plain note: a stage change, a settlement, a field change. */
    kind?: ReactNode;
    /** New since the last visit. */
    isNew?: boolean;
    /** The "more" button with its menu. */
    more?: ReactNode;
    /** No line above: the first entry under a tile's head. */
    rule?: boolean;
    children: ReactNode;
}

/** Figma: JournalEntry. One entry of a journal: a meta line with its kind and the Nowe chip, then the text. */
export function JournalEntry({ meta, kind, isNew = false, more, rule = true, children }: JournalEntryProps) {
    const { t } = useTranslation();

    return (
        <li className={rule ? `${styles.entry} ${styles.ruled}` : styles.entry}>
            <div className={styles.head}>
                <span className={styles.meta}>{meta}</span>
                {kind !== undefined && <span className={styles.kind}>{kind}</span>}
                {isNew && <Chip status="open">{t('New since last visit')}</Chip>}
                {more !== undefined && <span className={styles.more}>{more}</span>}
            </div>
            <div className={styles.body}>{children}</div>
        </li>
    );
}
