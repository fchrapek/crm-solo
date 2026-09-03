import { router, usePage } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { show as showTask } from '@/routes/tasks';
import type { LiveSession, SharedData } from '@/types';

/**
 * Subscribes to per-task `reverb.session.{id}` channels for every task that
 * currently has a live terminal session (taken from the `live_sessions`
 * shared prop). When the CLI's hook POSTs to /api/session-events/{token},
 * the controller broadcasts SessionAttention here - we fire a toast with
 * click-to-open. The kanban-card "waiting" dot is driven separately by
 * `task.session_attention_at` so it survives a page reload.
 */
export function SessionAttentionListener() {
    const { props } = usePage<SharedData>();
    const sessions = props.live_sessions ?? [];

    return (
        <>
            {sessions.map((session) => (
                <SessionChannelListener key={session.task_id} session={session} />
            ))}
        </>
    );
}

interface SessionAttentionEvent {
    task_id: number;
    task_name: string;
    event: string;
    message: string | null;
}

function SessionChannelListener({ session }: { session: LiveSession }) {
    const { t } = useTranslation();

    // Public channel - PHP uses Channel('reverb.session.X') (no auth). The
    // default useEcho subscribes as private and silently fails on the auth
    // round-trip. Same fix as reverb-notification-listener.
    useEchoPublic<SessionAttentionEvent>(`reverb.session.${session.task_id}`, '.reverb.session.attention', (event) => {
        const title = event.message?.trim() || t('Session waiting on you');
        toast(`${event.task_name}: ${title}`, {
            action: {
                label: t('Open'),
                onClick: () => {
                    router.visit(showTask(session.task_id).url);
                },
            },
        });
    });

    return null;
}
