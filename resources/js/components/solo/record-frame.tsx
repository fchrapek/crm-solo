import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from './action-menu';
import { HeaderActionsScope } from './header-place';
import { IconButton } from './icon-button';
import { Poster } from './primitives';
import styles from './record-frame.module.css';

interface RecordFrameProps {
    title: string;
    /** The full name when the title is a short form. */
    titleAttr?: string;
    /** record: the title is a client's name, set like a task's. */
    titleSize?: 'heading' | 'record';
    /** Above the title: the working agent's chip. */
    chip?: ReactNode;
    /** Under the title: a state the whole record is in, such as archived. */
    note?: ReactNode;
    /** line: the note is a sentence about the record (a month's state), not a caps label. */
    noteKind?: 'label' | 'line';
    /** Beside the title from 768 px and under it on a phone: a second row of pills, such as the months of a client's billing. */
    aside?: ReactNode;
    /** The record's actions as HeaderAction parts: buttons at the title's end from 768 px, the "more" menu on a phone. */
    actions?: ReactNode;
    /** The one button a phone shows beside "more". */
    main?: ReactNode;
    /** False when the main button is all the record offers, so a phone needs no "more" menu. */
    phoneMenu?: boolean;
}

/**
 * Figma: the record's head on the redesign (Client, Project). The poster title with the chip above it; where the
 * record is and its tabs are the page's place, never drawn here.
 */
export function RecordFrame({
    title,
    titleAttr,
    titleSize = 'heading',
    chip,
    note,
    noteKind = 'label',
    aside,
    actions,
    main,
    phoneMenu = true,
}: RecordFrameProps) {
    const { t } = useTranslation();

    return (
        <header className={styles.frame}>
            {chip !== undefined && <div className={styles.chip}>{chip}</div>}
            <div className={styles.titleRow}>
                <div className={styles.title}>
                    <Poster size={titleSize} title={titleAttr}>
                        {title}
                    </Poster>
                    {note !== undefined && (
                        <p className={styles.note} data-kind={noteKind}>
                            {note}
                        </p>
                    )}
                </div>
                {aside !== undefined && <div className={styles.aside}>{aside}</div>}
                {actions !== undefined && (
                    <div className={styles.actions}>
                        <HeaderActionsScope place="frame">{actions}</HeaderActionsScope>
                    </div>
                )}
                {(main !== undefined || actions !== undefined) && (
                    <div className={styles.phoneActions}>
                        <HeaderActionsScope place="frame">{main}</HeaderActionsScope>
                        {actions !== undefined && phoneMenu && (
                            <ActionMenu trigger={<IconButton icon="more" look="outline" label={t('More actions')} />}>
                                <HeaderActionsScope place="menu">{actions}</HeaderActionsScope>
                            </ActionMenu>
                        )}
                    </div>
                )}
            </div>
        </header>
    );
}
