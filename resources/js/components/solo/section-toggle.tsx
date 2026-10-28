import { Icon } from '@/components/ui/icon';
import { type ReactNode, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './section-toggle.module.css';

interface SectionToggleProps {
    /** The caps label on desktop, the row title on phones. */
    label: string;
    /** The count or summary at the end of the row, before the actions. */
    summary?: ReactNode;
    /** Phones: a shorter end meta in place of the summary ("1 z 3"); null for none. */
    phoneSummary?: ReactNode;
    /** Desktop only: an action beside Pokaż, such as Dodaj or Edytuj w Trello. */
    extra?: ReactNode;
    /** Phones only: an action on the row before the caret, such as Dodaj. */
    phoneExtra?: ReactNode;
    defaultOpen?: boolean;
    /** Nothing to open: the row keeps its title, summary and actions, without Pokaż and the chevron. */
    empty?: boolean;
    children?: ReactNode;
}

/**
 * Figma: A2f / List row with a chevron, collapsed by default; on phones the
 * title, the end meta and the chevron alone (Pokaż stays for screen readers).
 * Pokaż / Ukryj is the one control: it stretches over the row, so the whole
 * row toggles, and the extra action sits above it.
 */
export function SectionToggle({ label, summary, phoneSummary, extra, phoneExtra, defaultOpen = false, empty = false, children }: SectionToggleProps) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(defaultOpen);
    const id = useId();

    return (
        <section className={styles.section} aria-labelledby={`${id}-label`}>
            <div className={styles.row}>
                <h2 id={`${id}-label`} className={styles.label}>
                    {label}
                </h2>
                {summary !== undefined && (
                    <p className={styles.summary} data-wide-only={phoneSummary !== undefined || undefined}>
                        {summary}
                    </p>
                )}
                {phoneSummary !== undefined && phoneSummary !== null && <p className={styles.phoneSummary}>{phoneSummary}</p>}
                {phoneExtra !== undefined && <div className={styles.phoneExtra}>{phoneExtra}</div>}
                {extra !== undefined && <div className={styles.extra}>{extra}</div>}
                {!empty && (
                    <button
                        type="button"
                        className={styles.toggle}
                        aria-expanded={open}
                        aria-controls={`${id}-body`}
                        aria-describedby={`${id}-label`}
                        onClick={() => setOpen((value) => !value)}
                    >
                        <span className={styles.toggleText}>{open ? t('Hide') : t('Show')}</span>
                        <Icon name="chevron-right" size={16} className={styles.caret} data-open={open || undefined} />
                    </button>
                )}
            </div>
            {!empty && (
                <div id={`${id}-body`} className={styles.body} hidden={!open}>
                    {open && children}
                </div>
            )}
        </section>
    );
}
