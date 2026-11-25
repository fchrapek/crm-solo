import { Icon } from '@/components/ui/icon';
import * as Dialog from '@radix-ui/react-dialog';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { IconButton } from '../../icon-button';
import { SoloButton, SoloButtonLink } from '../../primitives';
import { Dog } from './dog';
import styles from './helper.module.css';
import { type HelperAction, type HelperTopic } from './topics';

interface HelperPanelProps {
    topics: HelperTopic[];
    /** The answer shown; null (or an id this screen does not have) shows the list. */
    topic: string | null;
    /** The docs link of the list or of the answer; null hides it. */
    docs: string | null;
    onTopic: (id: string | null) => void;
    onAction: (action: HelperAction) => void;
}

function DocsLink({ href }: { href: string | null }) {
    const { t } = useTranslation();

    return href === null ? null : (
        <a className={styles.docs} href={href} target="_blank" rel="noopener noreferrer">
            {t('More in the docs')}
            <Icon name="external-link" size={16} />
        </a>
    );
}

function Close() {
    const { t } = useTranslation();

    return (
        <Dialog.Close asChild>
            <IconButton icon="close" look="quiet" label={t('Close the helper')} className={styles.close} />
        </Dialog.Close>
    );
}

/**
 * Figma: Helper / Window (1173:182). The list of the screen's topics, or one answer with at most one action and the
 * way back. A desktop window and a phone sheet are the same DOM; the CSS sizes the type and draws the grip.
 */
export function HelperPanel({ topics, topic, docs, onTopic, onAction }: HelperPanelProps) {
    const { t } = useTranslation();
    const shown = topics.find((entry) => entry.id === topic) ?? null;
    const back = useRef<HTMLButtonElement>(null);
    const list = useRef<HTMLUListElement>(null);
    const last = useRef<string | null>(shown?.id ?? null);

    useEffect(() => {
        if (last.current === (shown?.id ?? null)) return;
        if (shown !== null) back.current?.focus();
        else list.current?.querySelector<HTMLButtonElement>(`[data-topic="${last.current}"]`)?.focus();
        last.current = shown?.id ?? null;
    }, [shown]);

    return (
        <div className={styles.panel} data-helper-panel={shown === null ? 'topics' : 'answer'}>
            <span className={styles.grip} aria-hidden="true" />
            {shown === null ? (
                <>
                    <header className={styles.head}>
                        <span className={styles.headDog}>
                            <Dog size={32} />
                        </span>
                        <Dialog.Title className={styles.title}>{t('Helper')}</Dialog.Title>
                        <Close />
                    </header>
                    {topics.length > 0 && <p className={styles.intro}>{t('What do you want to ask on this screen?')}</p>}
                    <ul ref={list} className={styles.topics}>
                        {topics.map((entry) => (
                            <li key={entry.id}>
                                <button type="button" className={styles.topic} data-topic={entry.id} onClick={() => onTopic(entry.id)}>
                                    <span className={styles.topicText}>{entry.question}</span>
                                    <Icon name="chevron-right" size={20} />
                                </button>
                            </li>
                        ))}
                    </ul>
                    <DocsLink href={docs} />
                </>
            ) : (
                <>
                    <header className={styles.head} data-back="">
                        <button ref={back} type="button" className={styles.back} onClick={() => onTopic(null)}>
                            <Icon name="arrow-left" size={20} />
                            {t('Topics')}
                        </button>
                        <Close />
                    </header>
                    <div className={styles.answer}>
                        <Dialog.Title className={styles.question}>{shown.question}</Dialog.Title>
                        <p className={styles.answerText}>{shown.answer}</p>
                    </div>
                    {shown.action !== null && (
                        <div className={styles.action}>
                            {'href' in shown.action ? (
                                <SoloButtonLink href={shown.action.href} size="control" variant="primary" onClick={() => shown.action && onAction(shown.action)}>
                                    {shown.action.label}
                                </SoloButtonLink>
                            ) : (
                                <SoloButton size="control" variant="primary" onClick={() => shown.action && onAction(shown.action)}>
                                    {shown.action.label}
                                </SoloButton>
                            )}
                        </div>
                    )}
                    <DocsLink href={docs} />
                </>
            )}
        </div>
    );
}
