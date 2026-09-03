import { usePage } from '@inertiajs/react';
import { Power, Terminal } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { type SharedData } from '@/types';

import styles from './terminal-session.module.css';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

interface Props {
    taskId: number;
    port: number | null;
    cli: 'claude' | 'codex' | null;
    branchName: string | null;
    onStopped?: () => void;
}

export function TerminalSession({ taskId, port, cli, branchName, onStopped }: Props) {
    const { t } = useTranslation();
    const sessionHost = usePage<SharedData>().props.terminal_session_host ?? 'localhost';
    const [stopping, setStopping] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    if (port === null) {
        return null;
    }

    const performStop = () => {
        setStopping(true);
        fetch(`/tasks/${taskId}/stop-session`, {
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
                    <Terminal size={14} />
                    <span>{cli}</span>
                    {branchName && <span className={styles.metaChip}>{branchName}</span>}
                </div>
                <div className={styles.actions}>
                    <Button size="sm" variant="outline" onClick={() => setConfirmOpen(true)} disabled={stopping}>
                        <Power size={12} />
                        {stopping ? t('Ending…') : t('End session')}
                    </Button>
                </div>
            </div>
            <iframe title={`terminal-task-${taskId}`} src={`http://${sessionHost}:${port}`} className={styles.iframe} />

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={t('End the terminal session?')}
                description={t(
                    'Stops time tracking and closes the history row. The tmux session stays alive in the background - you can resume later with full scrollback. Use Kill session to discard everything.',
                )}
                confirmLabel={t('End session')}
                variant="destructive"
                onConfirm={performStop}
            />
        </div>
    );
}
