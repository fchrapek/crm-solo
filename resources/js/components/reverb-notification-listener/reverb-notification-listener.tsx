import { useEchoPublic } from '@laravel/echo-react';
import { toast } from 'sonner';

import { useReverbNotification } from '@/contexts/reverb-context';

interface ReverbCompletedEvent {
    type: string;
    status: string;
    message: string;
}

interface ReverbProgressEvent {
    type: string;
    current: number;
    total: number;
}

export function ReverbNotificationListener() {
    const { pendingUuids, removeUuid, setProgress } = useReverbNotification();

    return (
        <>
            {Array.from(pendingUuids).map((uuid) => (
                <ReverbChannelListener
                    key={uuid}
                    uuid={uuid}
                    onProgress={(event) => setProgress(uuid, event.current, event.total)}
                    onCompleted={(event) => {
                        if (event.status === 'error') {
                            toast.error(event.message);
                        } else {
                            toast.success(event.message);
                        }
                        removeUuid(uuid);
                    }}
                />
            ))}
        </>
    );
}

interface ReverbChannelListenerProps {
    uuid: string;
    onProgress: (event: ReverbProgressEvent) => void;
    onCompleted: (event: ReverbCompletedEvent) => void;
}

function ReverbChannelListener({ uuid, onProgress, onCompleted }: ReverbChannelListenerProps) {
    // Public channel - server-side uses Illuminate\Broadcasting\Channel (no
    // auth). The default `useEcho` hook subscribes as private which fires a
    // POST /broadcasting/auth that returns 403 for an unregistered channel,
    // so no events are ever delivered to the listener. useEchoPublic skips
    // auth and subscribes directly via pusher:subscribe.
    useEchoPublic<ReverbCompletedEvent>(`reverb.${uuid}`, '.reverb.completed', (e) => {
        onCompleted(e);
    });
    useEchoPublic<ReverbProgressEvent>(`reverb.${uuid}`, '.reverb.progress', (e) => {
        onProgress(e);
    });

    return null;
}
