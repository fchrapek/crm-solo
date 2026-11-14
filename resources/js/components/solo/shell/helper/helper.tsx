import { localeOf } from '@/lib/hours-text';
import { useReducer, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet } from '../../bottom-sheet';
import { Chip } from '../../chip';
import { IconButton } from '../../icon-button';
import { SoloButton } from '../../primitives';
import { useSoloWide } from '../../use-solo-wide';
import { Dog } from './dog';
import styles from './helper.module.css';
import { HELPER_CLOSED, helperPose, helperScene, helperShow, type HelperWindowKind } from './hints';
import { HelperWindow } from './windows';

function HelperTitle({ dog = false }: { dog?: boolean }) {
    const { t } = useTranslation();

    return (
        <span className={styles.title}>
            {dog && <Dog size={32} />}
            {t('Helper')}
            <Chip status="open">{t('mock-up')}</Chip>
        </span>
    );
}

/**
 * Figma: Helper. A mock, no chatbot: the dog floats bottom right from 768 px and ends the page on a phone; pointed at or
 * focused it may show one hint line with one action (after the dog, so Tab reaches it next), opened it shows a window of example content.
 */
export function Helper({ page, props }: { page: string; props: Record<string, unknown> }) {
    const { t, i18n } = useTranslation();
    const scene = helperScene(page, props, { t, locale: localeOf(i18n.language) });
    const [{ shown, seen }, dispatch] = useReducer(helperShow, HELPER_CLOSED);
    const [peek, setPeek] = useState(false);
    const wide = useSoloWide();
    const open = shown !== null;
    const hint = open || seen === page ? null : scene.hint;

    if (scene.hidden) {
        return null;
    }

    const show = (kind: HelperWindowKind | null) => dispatch({ page, show: kind });
    const line = shown === scene.window ? scene.hint?.text : undefined;
    const content = shown && <HelperWindow kind={shown} links={scene.links} line={line} onShow={show} />;

    return (
        <aside className={styles.helper} data-shell-helper="" aria-label={t('Helper')}>
            {open && wide && (
                <section className={styles.window} aria-label={t('Helper')}>
                    <header className={styles.head}>
                        <Dog size={32} />
                        <HelperTitle />
                        <IconButton icon="close" look="quiet" label={t('Close')} onClick={() => show(null)} />
                    </header>
                    {content}
                </section>
            )}
            <button
                type="button"
                className={styles.button}
                aria-label={open ? t('Close the helper') : t('Open the helper')}
                aria-expanded={open}
                onClick={() => show(open ? null : scene.window)}
                onPointerEnter={() => setPeek(true)}
                onPointerLeave={() => setPeek(false)}
            >
                <span className={styles.phoneDog}>
                    <Dog size={44} />
                </span>
                <span className={styles.wideDog}>
                    <Dog size={88} live={wide} peek={peek} pose={helperPose(hint)} shade={null} />
                </span>
            </button>
            {hint && (
                <div className={styles.hint} data-shell-hint="">
                    <p className={styles.hintText}>{hint.text}</p>
                    <SoloButton size="control" variant="primary" onClick={() => show(scene.window)}>
                        {hint.action}
                    </SoloButton>
                </div>
            )}
            {!wide && (
                <BottomSheet open={open} onOpenChange={(next) => show(next ? scene.window : null)} title={<HelperTitle dog />}>
                    {content}
                </BottomSheet>
            )}
        </aside>
    );
}
