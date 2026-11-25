import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import styles from './picked.module.css';

/** Figma: Today with 6 picks (1158:63339). How many tasks a day holds: "3 z 5" up to a full day, then the count and, unless switched off, that it is more. */
export function PickedCount({ count, max, warn }: { count: number; max: number; warn: boolean }) {
    const { t } = useTranslation();

    if (count <= max) {
        return <>{t('{{count}} of {{max}} picked', { count, max })}</>;
    }

    return (
        <>
            {t('{{count}} picked', { count })}
            {warn && (
                <>
                    , <span className={styles.over}>{t('more than {{max}}', { max })}</span>
                </>
            )}
        </>
    );
}

/** What the "pick another" row says about the room left; nothing once the day is full and the warning is off. */
export function freeNote(t: TFunction, free: number, warn: boolean, short = false): string | undefined {
    if (free > 0) {
        return short ? t('{{count}} free', { count: free }) : t('{{count}} free slots', { count: free });
    }

    return warn ? t('the day is already full') : undefined;
}
