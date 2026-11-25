import styles from './danger-zone.module.css';
import { SoloButton } from './primitives';
import { Tile, TileHeader } from './tile';

/** Figma: Danger zone (1371:23743), the last tile of a record's main column with its one action that takes the record out of use or back. */
export function DangerZone({
    label,
    title,
    description,
    action,
    onAction,
}: {
    /** Danger zone. */
    label: string;
    title: string;
    description: string;
    action: string;
    onAction: () => void;
}) {
    return (
        <Tile kind="plain">
            <TileHeader label={label} />
            <div className={styles.entry} data-fill="">
                <p className={styles.title}>{title}</p>
                <p className={styles.description}>{description}</p>
            </div>
            <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={onAction}>
                {action}
            </SoloButton>
        </Tile>
    );
}
