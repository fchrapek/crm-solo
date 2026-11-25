import { BottomSheet } from '@/components/solo/bottom-sheet';
import { Chip } from '@/components/solo/chip';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { Tile, TileActions, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { TimeEntryEditDialog, type EditableTimeEntry } from '@/components/time-entry-edit-dialog';
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { minutesSince } from '@/lib/dates';
import tasks from '@/routes/tasks';
import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { activityMeta, activityTitle } from './format';
import styles from './task-page.module.css';
import { LIVE_PROPS, type ActivityRow, type LiveAgent, type TaskActivityPage, type TaskClock } from './types';

/** The rows the tile shows; the server sends as many, or every row while the whole history is open. */
export const PREVIEW_ROWS = 3;

/** Figma: the agent chip of Activity while an agent's timer runs on the task. */
function LiveChip({ live, now }: { live: LiveAgent; now: number }) {
    const { t } = useTranslation();

    return (
        <span title={live.description ?? undefined}>
            <Chip status="agent">{t('{{agent}} working · {{minutes}} min', { agent: live.agent, minutes: minutesSince(live.started_at, now) })}</Chip>
        </span>
    );
}

interface RowsProps {
    rows: ActivityRow[];
    now: number;
    clock: TaskClock;
    locale: string;
    /** Given in the whole history: a logged time row offers Edit. */
    onEdit?: (entry: EditableTimeEntry) => void;
}

function Rows({ rows, now, clock, locale, onEdit }: RowsProps) {
    const { t } = useTranslation();

    return (
        <TileRows className={styles.entries}>
            {rows.map((row) => {
                const entry = row.entry;

                return (
                    <Row
                        key={row.key}
                        wrap
                        title={activityTitle(row, locale, t)}
                        meta={activityMeta(row, now, clock.today, locale, t)}
                        action={
                            onEdit !== undefined && entry !== null ? (
                                <SoloButton variant="quiet" size="control" className={styles.rowAction} onClick={() => onEdit(entry)}>
                                    {t('Edit')}
                                </SoloButton>
                            ) : undefined
                        }
                    />
                );
            })}
        </TileRows>
    );
}

/** Figma: Whole history (1095:50081): a dialog from 768 px, a sheet below it. */
function HistoryDialog({ open, onClose, children }: { open: boolean; onClose: () => void; children: ReactNode }) {
    const { t } = useTranslation();
    const wide = useSoloWide();
    const onOpenChange = (next: boolean) => !next && onClose();

    if (!wide) {
        return (
            <BottomSheet open={open} onOpenChange={onOpenChange} title={t('Whole history')}>
                {open && children}
            </BottomSheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="wide" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{t('Whole history')}</DialogTitle>
                </DialogHeader>
                <DialogBody>{children}</DialogBody>
            </DialogContent>
        </Dialog>
    );
}

interface ActivitySectionProps {
    taskId: number;
    activity: TaskActivityPage;
    /** The whole history is open (`?history`): every row, in the dialog. */
    full?: boolean;
    live: LiveAgent | null;
    clock: TaskClock;
    now: number;
    locale: string;
}

/**
 * Figma: Task, the Activity tile (1094:40618) and Whole history (1095:50081). The agent chip while an agent works,
 * the latest three entries, then the whole history in a dialog, where a logged time row opens its edit form.
 */
export function ActivitySection({ taskId, activity, full = false, live, clock, now, locale }: ActivitySectionProps) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<EditableTimeEntry | null>(null);
    const preview = activity.rows.slice(0, PREVIEW_ROWS);
    const close = () =>
        router.get(tasks.show(taskId).url, {}, { only: ['activity'], preserveScroll: true, preserveState: true, replace: true });

    return (
        <Tile kind="list" aria-labelledby="task-activity">
            <TileHeader
                label={<span id="task-activity">{t('Activity')}</span>}
                end={live !== null ? <LiveChip live={live} now={now} /> : undefined}
            />
            {preview.length === 0 ? (
                <TileInset>
                    <TileNote>{t('No activity yet.')}</TileNote>
                </TileInset>
            ) : (
                <Rows rows={preview} now={now} clock={clock} locale={locale} />
            )}
            {preview.length > 0 && (
                <TileActions className={styles.historyAction}>
                    <SoloButtonLink
                        href={tasks.show(taskId, { query: { history: 1 } }).url}
                        only={['activity']}
                        preserveScroll
                        preserveState
                        replace
                        variant="secondary"
                        size="control"
                    >
                        {t('Whole history')}
                    </SoloButtonLink>
                </TileActions>
            )}
            <HistoryDialog open={full} onClose={close}>
                <div className={styles.history}>
                    {live !== null && (
                        <div className={styles.historyLive}>
                            <LiveChip live={live} now={now} />
                        </div>
                    )}
                    <Rows rows={activity.rows} now={now} clock={clock} locale={locale} onEdit={setEditing} />
                </div>
            </HistoryDialog>
            <TimeEntryEditDialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                entry={editing}
                onSaved={() => router.reload({ only: LIVE_PROPS })}
            />
        </Tile>
    );
}
