import { usePage } from '@inertiajs/react';
import { ExternalLink, Globe, Power } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { type SharedData } from '@/types';

import styles from './task-preview.module.css';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

interface Props {
    taskId: number;
    ttydPort: number;
    command: string;
    workingDir: string;
    url: string | null;
    onStopped?: () => void;
}

export function TaskPreview({ taskId, ttydPort, command, workingDir, url, onStopped }: Props) {
    const { t } = useTranslation();
    const sessionHost = usePage<SharedData>().props.terminal_session_host ?? 'localhost';
    const [stopping, setStopping] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const performStop = () => {
        setStopping(true);
        fetch(`/tasks/${taskId}/preview/stop`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(() => onStopped?.())
            .catch(() => undefined)
            .finally(() => setStopping(false));
    };

    return (
        <div className={styles.wrap}>
            <div className={styles.header}>
                <div className={styles.meta}>
                    <Globe size={14} />
                    <span className={styles.metaChip} title={`${command} (cwd: ${workingDir})`}>
                        {command}
                    </span>
                    {url && (
                        <a href={url} target="_blank" rel="noopener noreferrer" className={styles.urlLink}>
                            {url}
                            <ExternalLink size={11} style={{ marginLeft: 4, verticalAlign: '-1px' }} />
                        </a>
                    )}
                </div>
                <div className={styles.actions}>
                    <Button size="sm" variant="outline" onClick={() => setConfirmOpen(true)} disabled={stopping}>
                        <Power size={12} />
                        {stopping ? t('Stopping…') : t('Stop preview')}
                    </Button>
                </div>
            </div>
            <iframe title={`preview-task-${taskId}`} src={`http://${sessionHost}:${ttydPort}`} className={styles.iframe} />

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={t('Stop the preview?')}
                description={t(
                    'Kills the preview process running in the task worktree. For long-running dev servers (vite, etc.) this stops them. For ddev-style startup commands, the underlying service may keep running - stop it manually with ddev stop if needed.',
                )}
                confirmLabel={t('Stop preview')}
                variant="destructive"
                onConfirm={performStop}
            />
        </div>
    );
}
