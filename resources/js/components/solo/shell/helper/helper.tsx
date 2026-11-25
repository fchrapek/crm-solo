import { Icon } from '@/components/ui/icon';
import { localeOf } from '@/lib/hours-text';
import * as Dialog from '@radix-ui/react-dialog';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSoloPortalSurface } from '../../surface';
import { useSoloWide } from '../../use-solo-wide';
import { requestHelperAction } from './actions';
import { Dog } from './dog';
import styles from './helper.module.css';
import { HELPER_CLOSED, helperHere, helperPose, helperScene, hintShown, type HelperState } from './hints';
import { HelperPanel } from './panel';
import { docsLink, helperTopics, type HelperAction } from './topics';

interface HelperProps {
    /** The Inertia page name: it picks the screen's topics and line. */
    page: string;
    /** The page URL: a new one closes the helper and offers the line again. */
    url: string;
    props: Record<string, unknown>;
    /** The docs site; empty keeps every docs link hidden. */
    docsUrl?: string | null;
    /** The user's choice per device kind: CSS shows the helper from 768 px only with desktop, below it only with phone. */
    desktop?: boolean;
    phone?: boolean;
}

/**
 * Figma: Helper (1173:44). No chat: the dog opens the screen's fixed topics, a topic shows its short answer with at
 * most one action, and an action closes the helper. From 768 px the dog floats bottom right and, pointed at or
 * focused, may show one line from the page's data that leads to its topic; on a phone it is a 56 px button above the
 * timer strip and the topics open as a bottom sheet.
 */
export function Helper({ page, url, props, docsUrl = null, desktop = true, phone = false }: HelperProps) {
    const { t, i18n } = useTranslation();
    const [state, setState] = useState<HelperState>(HELPER_CLOSED);
    const [peek, setPeek] = useState(false);
    const after = useRef<(() => void) | null>(null);
    const wide = useSoloWide();
    const container = useSoloPortalSurface();
    const scene = helperScene(page, props, { t, locale: localeOf(i18n.language) });
    const topics = helperTopics(page, props, t);
    const here = helperHere(state, url);
    const hint = hintShown(state, url) && scene.hint !== null && topics.some((entry) => entry.id === scene.hint?.topic) ? scene.hint : null;

    if (scene.hidden || topics.length === 0 || (!desktop && !phone)) {
        return null;
    }

    const show = (topic: string | null) => setState({ url, open: true, topic });
    const close = () => setState({ ...here, url, open: false });
    const act = (action: HelperAction) => {
        if ('event' in action) after.current = () => requestHelperAction(action.event);
        close();
    };

    return (
        <Dialog.Root open={here.open} onOpenChange={(open) => (open ? show(null) : close())}>
            <aside
                className={styles.helper}
                data-shell-helper=""
                data-desktop={desktop ? '' : undefined}
                data-phone={phone ? '' : undefined}
                aria-label={t('Helper')}
            >
                <Dialog.Trigger asChild>
                    <button
                        type="button"
                        className={styles.button}
                        aria-label={t('Open the helper')}
                        onPointerEnter={() => setPeek(true)}
                        onPointerLeave={() => setPeek(false)}
                    >
                        <span className={styles.phoneDog}>
                            <Dog size={44} />
                        </span>
                        <span className={styles.wideDog}>
                            <Dog size={112} live={wide && desktop} peek={peek} pose={helperPose(hint, peek)} shade={null} />
                        </span>
                    </button>
                </Dialog.Trigger>
                {hint && (
                    <div className={styles.hint} data-shell-hint="">
                        <p className={styles.hintText}>{hint.text}</p>
                        <button type="button" className={styles.hintTopic} onClick={() => show(hint.topic)}>
                            {t(`helper_question_${hint.topic}`)}
                            <Icon name="chevron-right" size={16} />
                        </button>
                    </div>
                )}
            </aside>
            <Dialog.Portal container={container}>
                <Dialog.Overlay className={styles.overlay} />
                <Dialog.Content
                    className={styles.sheet}
                    aria-describedby={undefined}
                    onCloseAutoFocus={(event) => {
                        const next = after.current;
                        after.current = null;
                        if (next) {
                            event.preventDefault();
                            next();
                        }
                    }}
                >
                    <HelperPanel
                        topics={topics}
                        topic={here.topic}
                        docs={docsLink(docsUrl, i18n.language, here.topic)}
                        onTopic={(topic) => setState({ ...here, url, topic })}
                        onAction={act}
                    />
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
